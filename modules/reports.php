<?php
// This page displays sales and harvest reports for admin/staff.
// The logic is written in a beginner-friendly style using simple SQL queries and summaries.
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff', 'farmer');

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$searchValue = $_GET['search'] ?? $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$filterError = '';

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
}

$salesConditions = [];
$salesParameters = [];
if ($from !== '') {
    $salesConditions[] = 'o.created_at >= ?';
    $salesParameters[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $salesConditions[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $salesParameters[] = $to;
}
if ($search !== '') {
    $salesConditions[] = '(o.order_code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR EXISTS (
        SELECT 1
        FROM sales AS sx
        JOIN vegetables AS vx ON vx.id = sx.vegetable_id
        WHERE sx.order_code = o.order_code AND vx.name LIKE ?
    ))';
    $searchTerm = '%' . $search . '%';
    array_push($salesParameters, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$harvestConditions = ['h.archived_at IS NULL'];
$harvestParameters = [];
if ($from !== '') {
    $harvestConditions[] = 'h.harvested_on >= ?';
    $harvestParameters[] = $from;
}
if ($to !== '') {
    $harvestConditions[] = 'h.harvested_on <= ?';
    $harvestParameters[] = $to;
}
if ($search !== '') {
    $harvestConditions[] = 'v.name LIKE ?';
    $harvestParameters[] = '%' . $search . '%';
}

$salesSummary = [
    'revenue' => 0,
    'subtotal' => 0,
    'discounts' => 0,
    'net_sales' => 0,
    'transactions' => 0,
    'quantity' => 0,
    'discount_orders' => 0,
];
$harvestSummary = ['records' => 0];
$harvestQuantitiesByUnit = [];
$dailySales = [];
$harvestTotals = [];
$salesRecords = [];
$harvestRecords = [];
$inventoryGroups = [];
$paymentBreakdown = [];
$discountHistory = [];
$discountedVegetables = [];
$paymentSummary = ['cod' => 0, 'online' => 0, 'pending_payment' => 0, 'paid' => 0];
$reportPeriod = 'All dates';
if ($from !== '' && $to !== '') {
    $reportPeriod = $from . ' to ' . $to;
} elseif ($from !== '') {
    $reportPeriod = 'From ' . $from;
} elseif ($to !== '') {
    $reportPeriod = 'Through ' . $to;
}

if ($filterError === '') {
    $salesFilterSql = $salesConditions ? implode(' AND ', $salesConditions) : '1 = 1';
    $salesWhere = '(' . $salesFilterSql . ") AND o.payment_status = 'paid'";
    $salesSummaryStatement = $db->prepare(
        'SELECT
            COALESCE(SUM(o.final_total), 0) AS revenue,
            COALESCE(SUM(o.subtotal), 0) AS subtotal,
            COALESCE(SUM(o.discount_amount), 0) AS discounts,
            COALESCE(SUM(o.subtotal - o.discount_amount), 0) AS net_sales,
            COUNT(*) AS transactions,
            COALESCE(SUM(o.eligible_quantity), 0) AS quantity,
            COALESCE(SUM(o.discount_amount > 0), 0) AS discount_orders
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         WHERE ' . $salesWhere
    );
    $salesSummaryStatement->execute($salesParameters);
    $salesSummary = $salesSummaryStatement->fetch();

    $dailyStatement = $db->prepare(
        'SELECT DATE(o.created_at) AS sale_day, SUM(o.final_total) AS revenue
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         WHERE ' . $salesWhere . '
         GROUP BY DATE(o.created_at)
         ORDER BY sale_day DESC
         LIMIT 30'
    );
    $dailyStatement->execute($salesParameters);
    $dailySales = array_reverse($dailyStatement->fetchAll());

    $salesRecordsStatement = $db->prepare(
        'SELECT
            s.order_code,
            s.created_at,
            s.quantity,
            s.unit_price,
            s.total,
            o.payment_method,
            o.payment_status,
            v.name AS vegetable,
            v.unit,
            u.name AS buyer
         FROM sales AS s
         JOIN orders AS o ON o.order_code = s.order_code
         JOIN vegetables AS v ON v.id = s.vegetable_id
         JOIN users AS u ON u.id = s.buyer_id
         WHERE ' . $salesFilterSql . '
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 500'
    );
    $salesRecordsStatement->execute($salesParameters);
    $salesRecords = $salesRecordsStatement->fetchAll();

    $paymentBreakdownStatement = $db->prepare(
        'SELECT o.payment_method, o.payment_status, COUNT(*) AS order_count
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         ' . ($salesConditions ? 'WHERE ' . implode(' AND ', $salesConditions) : '') . '
         GROUP BY o.payment_method, o.payment_status
         ORDER BY o.payment_method, o.payment_status'
    );
    $paymentBreakdownStatement->execute($salesParameters);
    $paymentBreakdown = $paymentBreakdownStatement->fetchAll();
    foreach ($paymentBreakdown as $paymentRow) {
        if (isset($paymentSummary[$paymentRow['payment_method']])) {
            $paymentSummary[$paymentRow['payment_method']] += (int) $paymentRow['order_count'];
        }
        if (isset($paymentSummary[$paymentRow['payment_status']])) {
            $paymentSummary[$paymentRow['payment_status']] += (int) $paymentRow['order_count'];
        }
    }

    $discountHistoryStatement = $db->prepare(
        'SELECT
            o.order_code,
            o.created_at,
            o.subtotal,
            o.discount_percentage,
            o.discount_amount,
            o.final_total,
            o.payment_method,
            u.name AS buyer
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         WHERE ' . $salesWhere . ' AND o.discount_amount > 0
         ORDER BY o.created_at DESC
         LIMIT 100'
    );
    $discountHistoryStatement->execute($salesParameters);
    $discountHistory = $discountHistoryStatement->fetchAll();

    $discountedVegetablesStatement = $db->prepare(
        'SELECT
            v.name,
            v.unit,
            COUNT(DISTINCT s.order_code) AS discounted_orders,
            SUM(s.quantity) AS quantity
         FROM sales AS s
         JOIN orders AS o ON o.order_code = s.order_code
         JOIN vegetables AS v ON v.id = s.vegetable_id
         JOIN users AS u ON u.id = s.buyer_id
         WHERE ' . $salesWhere . ' AND o.discount_amount > 0
         GROUP BY v.id, v.name, v.unit
         ORDER BY discounted_orders DESC, quantity DESC, v.name
         LIMIT 10'
    );
    $discountedVegetablesStatement->execute($salesParameters);
    $discountedVegetables = $discountedVegetablesStatement->fetchAll();

    $harvestWhere = implode(' AND ', $harvestConditions);
    $harvestSummaryStatement = $db->prepare(
        'SELECT COUNT(*) AS records
         FROM harvests AS h
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         WHERE ' . $harvestWhere
    );
    $harvestSummaryStatement->execute($harvestParameters);
    $harvestSummary = $harvestSummaryStatement->fetch();

    $harvestQuantityStatement = $db->prepare(
        'SELECT v.unit, SUM(h.quantity) AS quantity
         FROM harvests AS h
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         WHERE ' . $harvestWhere . '
         GROUP BY v.unit
         ORDER BY v.unit'
    );
    $harvestQuantityStatement->execute($harvestParameters);
    $harvestQuantitiesByUnit = $harvestQuantityStatement->fetchAll();

    $harvestTotalsStatement = $db->prepare(
        'SELECT v.name, v.unit, SUM(h.quantity) AS quantity
         FROM harvests AS h
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         WHERE ' . $harvestWhere . '
         GROUP BY v.id, v.name, v.unit
         ORDER BY quantity DESC, v.name'
    );
    $harvestTotalsStatement->execute($harvestParameters);
    $harvestTotals = $harvestTotalsStatement->fetchAll();

    $harvestRecordsStatement = $db->prepare(
        'SELECT
            h.id,
            h.harvested_on,
            h.quantity,
            v.name AS vegetable,
            v.unit
         FROM harvests AS h
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         WHERE ' . $harvestWhere . '
         ORDER BY h.harvested_on DESC, h.id DESC
         LIMIT 500'
    );
    $harvestRecordsStatement->execute($harvestParameters);
    $harvestRecords = $harvestRecordsStatement->fetchAll();

    $inventorySql = 'SELECT v.name, v.unit, i.quantity
                     FROM inventory AS i
                     JOIN vegetables AS v ON v.id = i.vegetable_id
                     WHERE v.active = 1 AND i.quantity > 0';
    $inventoryParameters = [];
    if ($search !== '') {
        $inventorySql .= ' AND v.name LIKE ?';
        $inventoryParameters[] = '%' . $search . '%';
    }
    $inventorySql .= ' ORDER BY v.unit, i.quantity DESC, v.name';
    $inventoryStatement = $db->prepare($inventorySql);
    $inventoryStatement->execute($inventoryParameters);

    foreach ($inventoryStatement->fetchAll() as $item) {
        $inventoryGroups[$item['unit']][] = $item;
    }
}

$pageTitle = 'Reports';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading report-heading">
    <div>
        <p class="module-eyebrow">Farm analytics</p>
        <h1>Reports</h1>
        <p>Review sales, harvest production, and current stock from recorded data.</p>
    </div>
    <button type="button" class="button print-button" data-print>Print report</button>
</section>

<section class="report-print-summary" aria-label="Report details">
    <p class="module-eyebrow">Harvest · Farm analytics</p>
    <h2>Sales and harvest report</h2>
    <p>
        Reporting period:
        <?= h($reportPeriod) ?>
        <?= $search !== '' ? ' · Search: ' . h($search) : '' ?>
    </p>
</section>

<section class="module-stat-grid" aria-label="Report summary">
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">₱</span>
        <span>Paid final totals</span>
        <strong><?= money($salesSummary['revenue']) ?></strong>
        <small>Includes delivery fees, after discounts</small>
    </article>
    <article class="module-stat-card">
        <span>Sales before discount</span>
        <strong><?= money($salesSummary['subtotal']) ?></strong>
        <small>Paid orders matching the filters</small>
    </article>
    <article class="module-stat-card">
        <span>Discounts given</span>
        <strong><?= money($salesSummary['discounts']) ?></strong>
        <small><?= number_format((int) $salesSummary['discount_orders']) ?> paid orders discounted</small>
    </article>
    <article class="module-stat-card">
        <span>Sales after discount</span>
        <strong><?= money($salesSummary['net_sales']) ?></strong>
        <small>Before delivery fees</small>
    </article>
    <article class="module-stat-card">
        <span>COD orders</span>
        <strong><?= number_format($paymentSummary['cod']) ?></strong>
        <small>All matching payment statuses</small>
    </article>
    <article class="module-stat-card">
        <span>Online payment orders</span>
        <strong><?= number_format($paymentSummary['online']) ?></strong>
        <small>All matching payment statuses</small>
    </article>
    <article class="module-stat-card">
        <span>Pending payments</span>
        <strong><?= number_format($paymentSummary['pending_payment']) ?></strong>
        <small>Awaiting cash collection or provider confirmation</small>
    </article>
    <article class="module-stat-card">
        <span>Paid transactions</span>
        <strong><?= number_format($paymentSummary['paid']) ?></strong>
        <small>Confirmed payments</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">⌁</span>
        <span>Vegetables harvested</span>
        <?php if ($harvestQuantitiesByUnit): ?>
            <div class="module-stat-values">
                <?php foreach ($harvestQuantitiesByUnit as $quantityByUnit): ?>
                    <strong>
                        <?= number_format((float) $quantityByUnit['quantity'], 2) ?>
                        <?= h($quantityByUnit['unit']) ?>
                    </strong>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <strong>—</strong>
            <small>No harvest quantity matches these filters.</small>
        <?php endif; ?>
        <small>Grouped by measurement unit</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">▤</span>
        <span>Total transactions</span>
        <strong><?= number_format((int) $salesSummary['transactions']) ?></strong>
        <small>Paid orders matching the filters</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">✓</span>
        <span>Harvest records</span>
        <strong><?= number_format((int) $harvestSummary['records']) ?></strong>
        <small>Active harvest entries</small>
    </article>
</section>

<section class="panel section module-filter-panel report-filters">
    <form method="get" class="module-filter-form">
        <div class="field">
            <label for="from">Start date</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div class="field">
            <label for="to">End date</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div class="field module-search-field">
            <label for="search">Search reports</label>
            <input
                id="search"
                name="search"
                value="<?= h($search) ?>"
                maxlength="100"
                placeholder="Order, buyer, email, or vegetable"
            >
        </div>
        <div class="module-filter-actions">
            <button type="submit">Apply filters</button>
            <a class="button secondary" href="<?= h(app_url('modules/reports.php')) ?>">Clear filters</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<div class="report-content">
    <section class="report-chart-grid" aria-label="Visual reports">
        <article class="panel module-chart-panel">
            <div class="module-table-heading">
                <div>
                    <h2>Daily sales performance</h2>
                    <p class="muted">Most recent 30 paid sale dates matching these filters.</p>
                </div>
            </div>
            <?php if ($dailySales): ?>
                <div class="module-chart-wrap">
                    <canvas
                        class="dashboard-chart"
                        data-chart="line"
                        data-labels="<?= h(json_encode(
                            array_map(
                                static fn (array $row): string => date('M j', strtotime($row['sale_day'])),
                                $dailySales
                            ),
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                        )) ?>"
                        data-values="<?= h(json_encode(
                            array_map(static fn (array $row): float => (float) $row['revenue'], $dailySales),
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                        )) ?>"
                        aria-label="Daily paid sales revenue"
                        role="img"
                    ></canvas>
                </div>
            <?php else: ?>
                <div class="module-empty-state module-empty-state--compact">
                    <span class="module-empty-icon" aria-hidden="true">↗</span>
                    <h3>No sales to chart</h3>
                    <p>Paid sales will appear here when they match the selected dates and search.</p>
                </div>
            <?php endif; ?>
        </article>

        <article class="panel module-chart-panel">
            <div class="module-table-heading">
                <div>
                    <h2>Harvest totals by vegetable</h2>
                    <p class="muted">Recorded quantity, grouped by vegetable.</p>
                </div>
            </div>
            <?php if ($harvestTotals): ?>
                <div class="module-chart-wrap">
                    <canvas
                        class="dashboard-chart"
                        data-chart="bar"
                        data-labels="<?= h(json_encode(
                            array_map(static fn (array $row): string => $row['name'] . ' (' . $row['unit'] . ')', $harvestTotals),
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                        )) ?>"
                        data-values="<?= h(json_encode(
                            array_map(static fn (array $row): float => (float) $row['quantity'], $harvestTotals),
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                        )) ?>"
                        aria-label="Harvest quantity by vegetable"
                        role="img"
                    ></canvas>
                </div>
            <?php else: ?>
                <div class="module-empty-state module-empty-state--compact">
                    <span class="module-empty-icon" aria-hidden="true">⌁</span>
                    <h3>No harvests to chart</h3>
                    <p>Harvest records will appear here when they match the selected filters.</p>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <?php if ($inventoryGroups): ?>
        <section class="panel section module-chart-panel">
            <div class="module-table-heading">
                <div>
                    <h2>Current inventory distribution</h2>
                    <p class="muted">Each chart compares vegetables measured in the same unit.</p>
                </div>
            </div>
            <div class="inventory-distribution-grid">
                <?php foreach ($inventoryGroups as $unit => $items): ?>
                    <?php
                    $inventoryLabels = array_column($items, 'name');
                    $inventoryValues = array_map(static fn (array $item): float => (float) $item['quantity'], $items);
                    ?>
                    <article class="inventory-distribution-card">
                        <h3>Stock measured in <?= h($unit) ?></h3>
                        <div class="inventory-distribution-content">
                            <div class="inventory-chart-wrap">
                                <canvas
                                    class="dashboard-chart"
                                    data-chart="doughnut"
                                    data-labels="<?= h(json_encode($inventoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                                    data-values="<?= h(json_encode($inventoryValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                                    aria-label="Current inventory distribution in <?= h($unit) ?>"
                                    role="img"
                                ></canvas>
                            </div>
                            <ul class="dashboard-chart-legend">
                                <?php foreach ($items as $index => $item): ?>
                                    <li>
                                        <span class="chart-legend-dot chart-color-<?= $index % 7 ?>" aria-hidden="true"></span>
                                        <?= h($item['name']) ?>
                                        <strong><?= number_format((float) $item['quantity'], 2) ?> <?= h($unit) ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel section module-table-panel">
        <div class="module-table-heading">
            <div>
                <h2>Discounted vegetables</h2>
                <p class="muted">Vegetables included in paid orders that received a bulk discount.</p>
            </div>
        </div>
        <?php if (!$discountedVegetables): ?>
            <p class="empty">No discounted paid orders match these filters.</p>
        <?php else: ?>
            <div class="table-wrap module-table-wrap">
                <table class="module-table">
                    <thead>
                        <tr><th>Vegetable</th><th>Discounted orders</th><th>Quantity</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discountedVegetables as $vegetable): ?>
                            <tr>
                                <td><?= h($vegetable['name']) ?></td>
                                <td><?= number_format((int) $vegetable['discounted_orders']) ?></td>
                                <td>
                                    <?= number_format((float) $vegetable['quantity'], 2) ?>
                                    <?= h($vegetable['unit']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel section module-table-panel">
        <div class="module-table-heading">
            <div>
                <h2>Payment method totals</h2>
                <p class="muted">COD and online orders grouped by payment status.</p>
            </div>
        </div>
        <?php if (!$paymentBreakdown): ?>
            <p class="empty">No payment transactions match these filters.</p>
        <?php else: ?>
            <div class="table-wrap module-table-wrap">
                <table class="module-table">
                    <thead>
                        <tr><th>Method</th><th>Payment status</th><th>Orders</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentBreakdown as $paymentRow): ?>
                            <tr>
                                <td><?= h(strtoupper($paymentRow['payment_method'])) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $paymentRow['payment_status']))) ?></td>
                                <td><?= number_format((int) $paymentRow['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel section module-table-panel">
        <div class="module-table-heading">
            <div>
                <h2>Discount usage history</h2>
                <p class="muted">Latest 100 paid orders with applied bulk discounts.</p>
            </div>
        </div>
        <?php if (!$discountHistory): ?>
            <p class="empty">No discounted orders match these filters.</p>
        <?php else: ?>
            <div class="table-wrap module-table-wrap">
                <table class="module-table">
                    <thead>
                        <tr>
                            <th>Order</th><th>Date</th><th>Buyer</th>
                            <th>Subtotal</th><th>Discount</th><th>Final total</th><th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discountHistory as $discountOrder): ?>
                            <tr>
                                <td><?= h($discountOrder['order_code']) ?></td>
                                <td><?= h($discountOrder['created_at']) ?></td>
                                <td><?= h($discountOrder['buyer']) ?></td>
                                <td><?= money($discountOrder['subtotal']) ?></td>
                                <td>
                                    <?= number_format((float) $discountOrder['discount_percentage'], 0) ?>% ·
                                    <?= money($discountOrder['discount_amount']) ?>
                                </td>
                                <td><?= money($discountOrder['final_total']) ?></td>
                                <td><?= h(strtoupper($discountOrder['payment_method'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel section module-table-panel">
        <div class="module-table-heading">
            <div>
                <h2>Sales details</h2>
                <p class="muted">
                    <?= number_format(count($salesRecords)) ?> displayed
                    <?= count($salesRecords) === 1 ? 'sale item' : 'sale items' ?>
                    <?php if (count($salesRecords) === 500): ?> · latest 500 matching records<?php endif; ?>
                    · all payment statuses; summary revenue counts paid orders only
                </p>
            </div>
        </div>
        <?php if (!$salesRecords): ?>
            <div class="module-empty-state module-empty-state--compact">
                <span class="module-empty-icon" aria-hidden="true">▤</span>
                <h3>No sales records found</h3>
                <p>Order items will appear here after a buyer places an order.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap module-table-wrap">
                <table class="module-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Date</th>
                            <th>Buyer</th>
                            <th>Payment status</th>
                            <th>Vegetable</th>
                            <th>Quantity</th>
                            <th>Unit price</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salesRecords as $sale): ?>
                            <tr>
                                <td><?= h($sale['order_code']) ?></td>
                                <td><?= h(date('M j, Y', strtotime($sale['created_at']))) ?></td>
                                <td><?= h($sale['buyer']) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $sale['payment_status']))) ?></td>
                                <td><?= h($sale['vegetable']) ?></td>
                                <td><?= number_format((float) $sale['quantity'], 2) ?> <?= h($sale['unit']) ?></td>
                                <td><?= money($sale['unit_price']) ?></td>
                                <td><?= money($sale['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel section module-table-panel">
        <div class="module-table-heading">
            <div>
                <h2>Harvest details</h2>
                <p class="muted">
                    <?= number_format(count($harvestRecords)) ?> displayed
                    <?= count($harvestRecords) === 1 ? 'harvest record' : 'harvest records' ?>
                    <?php if (count($harvestRecords) === 500): ?> · latest 500 matching records<?php endif; ?>
                </p>
            </div>
        </div>
        <?php if (!$harvestRecords): ?>
            <div class="module-empty-state module-empty-state--compact">
                <span class="module-empty-icon" aria-hidden="true">⌁</span>
                <h3>No harvest records found</h3>
                <p>Record a harvest to include actual production in your reports.</p>
                <a class="button secondary" href="<?= h(app_url('modules/harvests.php')) ?>">View harvests</a>
            </div>
        <?php else: ?>
            <div class="table-wrap module-table-wrap">
                <table class="module-table">
                    <thead>
                        <tr>
                            <th>Harvest ID</th>
                            <th>Harvest date</th>
                            <th>Vegetable</th>
                            <th>Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($harvestRecords as $harvest): ?>
                            <tr>
                                <td>#<?= (int) $harvest['id'] ?></td>
                                <td><?= h(date('M j, Y', strtotime($harvest['harvested_on']))) ?></td>
                                <td><?= h($harvest['vegetable']) ?></td>
                                <td><?= number_format((float) $harvest['quantity'], 2) ?> <?= h($harvest['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
