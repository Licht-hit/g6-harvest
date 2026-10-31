<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$status = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : '';
$filterError = '';
$validStatuses = ['', 'paid', 'cancelled'];

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
} elseif (!in_array($status, $validStatuses, true)) {
    $filterError = 'Choose a valid transaction status.';
}

$conditions = [];
$parameters = [];
if ($from !== '') {
    $conditions[] = 's.created_at >= ?';
    $parameters[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $conditions[] = 's.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $parameters[] = $to;
}
if ($status !== '') {
    $conditions[] = 's.status = ?';
    $parameters[] = $status;
}
if ($search !== '') {
    $conditions[] = '(s.order_code LIKE ? OR u.name LIKE ? OR v.name LIKE ?)';
    $searchTerm = '%' . $search . '%';
    array_push($parameters, $searchTerm, $searchTerm, $searchTerm);
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$summary = ['revenue' => 0, 'today' => 0, 'transactions' => 0];
$sales = [];
$transactions = [];
$dailySales = [];
$paidQuantitiesByUnit = [];
$matchingLines = 0;

if ($filterError === '') {
    $summaryStatement = $db->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN s.status = \'paid\' THEN s.total ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(
                CASE
                    WHEN s.status = \'paid\' AND DATE(s.created_at) = CURDATE() THEN s.total
                    ELSE 0
                END
            ), 0) AS today,
            COUNT(DISTINCT s.order_code) AS transactions
         FROM sales AS s
         JOIN users AS u ON u.id = s.buyer_id
         JOIN vegetables AS v ON v.id = s.vegetable_id
         ' . $whereSql
    );
    $summaryStatement->execute($parameters);
    $summary = $summaryStatement->fetch();

    $paidQuantityStatement = $db->prepare(
        'SELECT v.unit, SUM(s.quantity) AS quantity
         FROM sales AS s
         JOIN users AS u ON u.id = s.buyer_id
         JOIN vegetables AS v ON v.id = s.vegetable_id
         ' . ($whereSql ? $whereSql . ' AND ' : 'WHERE ')
            . "s.status = 'paid'
         GROUP BY v.unit
         ORDER BY v.unit"
    );
    $paidQuantityStatement->execute($parameters);
    $paidQuantitiesByUnit = $paidQuantityStatement->fetchAll();

    $countStatement = $db->prepare(
        'SELECT COUNT(*)
         FROM sales AS s
         JOIN users AS u ON u.id = s.buyer_id
         JOIN vegetables AS v ON v.id = s.vegetable_id
         ' . $whereSql
    );
    $countStatement->execute($parameters);
    $matchingLines = (int) $countStatement->fetchColumn();

    $statement = $db->prepare(
        'SELECT
            s.id,
            s.order_code,
            s.quantity,
            s.unit_price,
            s.total,
            s.status,
            s.created_at,
            u.name AS buyer,
            v.name AS vegetable,
            v.unit
         FROM sales AS s
         JOIN users AS u ON u.id = s.buyer_id
         JOIN vegetables AS v ON v.id = s.vegetable_id
         ' . $whereSql . '
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 500'
    );
    $statement->execute($parameters);
    $sales = $statement->fetchAll();

    foreach ($sales as $sale) {
        $orderCode = $sale['order_code'];
        if (!isset($transactions[$orderCode])) {
            $transactions[$orderCode] = [
                'order_code' => $orderCode,
                'created_at' => $sale['created_at'],
                'buyer' => $sale['buyer'],
                'status' => $sale['status'],
                'total' => 0.0,
                'quantities' => [],
                'items' => [],
            ];
        }

        $transactions[$orderCode]['total'] += (float) $sale['total'];
        $unit = $sale['unit'];
        $transactions[$orderCode]['quantities'][$unit] =
            ($transactions[$orderCode]['quantities'][$unit] ?? 0) + (float) $sale['quantity'];
        $transactions[$orderCode]['items'][] = $sale;
    }

    $dailyStatement = $db->prepare(
        'SELECT DATE(s.created_at) AS sale_day, SUM(s.total) AS revenue
         FROM sales AS s
         JOIN users AS u ON u.id = s.buyer_id
         JOIN vegetables AS v ON v.id = s.vegetable_id
         ' . ($whereSql ? $whereSql . ' AND ' : 'WHERE ')
            . "s.status = 'paid'
         GROUP BY DATE(s.created_at)
         ORDER BY sale_day"
    );
    $dailyStatement->execute($parameters);
    $dailySales = $dailyStatement->fetchAll();
}

$pageTitle = 'Sales';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Farm performance</p>
        <h1>Sales</h1>
        <p>Review buyer transactions and paid sales from checkout.</p>
    </div>
</section>

<section class="module-stat-grid" aria-label="Sales summary">
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">₱</span>
        <span>Total revenue</span>
        <strong><?= money($summary['revenue']) ?></strong>
        <small>Paid transactions in this view</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">↗</span>
        <span>Sales today</span>
        <strong><?= money($summary['today']) ?></strong>
        <small>Paid today within these filters</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">▤</span>
        <span>Transactions</span>
        <strong><?= number_format((int) $summary['transactions']) ?></strong>
        <small>Matching order records</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">⌁</span>
        <span>Items sold</span>
        <small>Paid quantities, grouped by unit</small>
        <div class="module-stat-values">
            <?php if ($paidQuantitiesByUnit): ?>
                <?php foreach ($paidQuantitiesByUnit as $quantityByUnit): ?>
                    <strong>
                        <?= number_format((float) $quantityByUnit['quantity'], 2) ?>
                        <?= h($quantityByUnit['unit']) ?>
                    </strong>
                <?php endforeach; ?>
            <?php else: ?>
                <strong>—</strong>
                <small>No paid quantities match these filters.</small>
            <?php endif; ?>
        </div>
    </article>
</section>

<section class="panel section module-chart-panel">
    <div class="module-table-heading">
        <div>
            <h2>Sales performance</h2>
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
            <h3>No paid sales to chart</h3>
            <p>Paid revenue will appear here after a buyer completes checkout.</p>
        </div>
    <?php endif; ?>
</section>

<section class="panel section module-filter-panel">
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
            <label for="q">Search sales</label>
            <input
                id="q"
                name="q"
                value="<?= h($search) ?>"
                maxlength="100"
                placeholder="Order, buyer, or vegetable"
            >
        </div>
        <div class="field">
            <label for="status">Payment status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div class="module-filter-actions">
            <button type="submit">Apply filters</button>
            <a class="button secondary" href="<?= h(app_url('modules/sales.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Transactions</h2>
            <p class="muted">
                <?= number_format($matchingLines) ?> matching sale
                <?= $matchingLines === 1 ? 'item' : 'items' ?>
            </p>
        </div>
        <?php if ($matchingLines > 500): ?>
            <span class="module-record-range">Showing the latest 500 sale items</span>
        <?php endif; ?>
    </div>

    <?php if ($filterError !== ''): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">⌕</span>
            <h3>Check the filters</h3>
            <p>Correct the dates or payment status to view matching transactions.</p>
        </div>
    <?php elseif (!$transactions): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">▤</span>
            <h3>No sales found</h3>
            <p>Completed buyer checkouts will appear here. You can also adjust your search filters.</p>
            <?php if ($from !== '' || $to !== '' || $search !== '' || $status !== ''): ?>
                <a class="button secondary" href="<?= h(app_url('modules/sales.php')) ?>">Clear filters</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table sales-table">
                <thead>
                    <tr>
                        <th>Transaction</th>
                        <th>Buyer</th>
                        <th>Purchased vegetables</th>
                        <th>Quantity by unit</th>
                        <th>Total amount</th>
                        <th>Sale date</th>
                        <th>Payment status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><strong><?= h($transaction['order_code']) ?></strong></td>
                            <td><?= h($transaction['buyer']) ?></td>
                            <td>
                                <details class="transaction-details">
                                    <summary>
                                        View <?= count($transaction['items']) ?>
                                        <?= count($transaction['items']) === 1 ? 'item' : 'items' ?>
                                    </summary>
                                    <ul>
                                        <?php foreach ($transaction['items'] as $item): ?>
                                            <li>
                                                <span>
                                                    <?= h($item['vegetable']) ?> ·
                                                    <?= number_format((float) $item['quantity'], 2) ?>
                                                    <?= h($item['unit']) ?>
                                                </span>
                                                <small>
                                                    <?= money($item['unit_price']) ?> each ·
                                                    <?= money($item['total']) ?>
                                                </small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </td>
                            <td>
                                <ul class="transaction-quantity-list">
                                    <?php foreach ($transaction['quantities'] as $unit => $quantity): ?>
                                        <li><?= number_format($quantity, 2) ?> <?= h($unit) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                            <td><strong><?= money($transaction['total']) ?></strong></td>
                            <td><?= h(date('M j, Y g:i a', strtotime($transaction['created_at']))) ?></td>
                            <td>
                                <span class="status-badge status-<?= h($transaction['status']) ?>">
                                    <?= h(ucfirst($transaction['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
