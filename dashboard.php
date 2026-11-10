<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

$isAdmin = current_user()['role'] === 'admin';
$lowStockThreshold = 5.0;
$requestedChartPeriod = $_GET['period'] ?? null;
$chartPeriodDays = $isAdmin
    && is_string($requestedChartPeriod)
    && ctype_digit($requestedChartPeriod)
    && in_array((int) $requestedChartPeriod, [7, 30, 90], true)
    ? (int) $requestedChartPeriod
    : 7;

$vegetableCount = $isAdmin
    ? (int) $db->query('SELECT COUNT(*) FROM vegetables WHERE active = 1')->fetchColumn()
    : 0;
$inventoryCount = (float) $db
    ->query('SELECT COALESCE(SUM(i.quantity), 0) FROM inventory AS i')
    ->fetchColumn();
$salesToday = $isAdmin
    ? (float) $db->query(
        "SELECT COALESCE(SUM(final_total), 0)
         FROM orders
         WHERE payment_status = 'paid'
            AND DATE(created_at) = CURDATE()"
    )
        ->fetchColumn()
    : 0.0;
$openOrderCount = (int) $db
    ->query(
        "SELECT COUNT(*)
         FROM orders
         WHERE order_status NOT IN ('delivered', 'cancelled')"
    )
    ->fetchColumn();
$salesComparison = $isAdmin
    ? $db->query(
    "SELECT
        COALESCE(SUM(CASE
            WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN final_total
            ELSE 0
        END), 0) AS current_period,
        COALESCE(SUM(CASE
            WHEN created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN final_total
            ELSE 0
        END), 0) AS previous_period
     FROM orders
     WHERE payment_status = 'paid'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
    )->fetch()
    : ['current_period' => 0, 'previous_period' => 0];
$currentSevenDaySales = (float) $salesComparison['current_period'];
$previousSevenDaySales = (float) $salesComparison['previous_period'];
$salesTrendPercent = $previousSevenDaySales > 0
    ? (($currentSevenDaySales - $previousSevenDaySales) / $previousSevenDaySales) * 100
    : null;
$harvestCount = (int) $db
    ->query('SELECT COUNT(*) FROM harvests WHERE archived_at IS NULL')
    ->fetchColumn();
$todayHarvestQuantity = (float) $db
    ->query(
        'SELECT COALESCE(SUM(quantity), 0)
         FROM harvests
         WHERE archived_at IS NULL AND harvested_on = CURDATE()'
    )
    ->fetchColumn();

$inventoryRows = $db->query(
    'SELECT v.name, v.unit, COALESCE(i.quantity, 0) AS quantity
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE v.active = 1
     ORDER BY quantity DESC, v.name'
)->fetchAll();
$lowStockItems = array_values(array_filter(
    $inventoryRows,
    static fn (array $item): bool => (float) $item['quantity'] <= $lowStockThreshold
));
$lowStockCount = count($lowStockItems);

$recentInventoryUpdates = $db->query(
    'SELECT v.name, v.unit, i.quantity, i.updated_at
     FROM inventory AS i
     JOIN vegetables AS v ON v.id = i.vegetable_id
     ORDER BY i.updated_at DESC
     LIMIT 6'
)->fetchAll();

$dailySalesRows = $isAdmin
    ? $db->query(
    "SELECT DATE(created_at) AS sale_day, SUM(final_total) AS revenue
     FROM orders
     WHERE payment_status = 'paid'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($chartPeriodDays - 1) . " DAY)
        AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
     GROUP BY DATE(created_at)
     ORDER BY sale_day"
    )->fetchAll()
    : [];
$salesByDay = [];
foreach ($dailySalesRows as $row) {
    $salesByDay[$row['sale_day']] = (float) $row['revenue'];
}

$dailyHarvestRows = $db->query(
    'SELECT harvested_on, SUM(quantity) AS quantity
     FROM harvests
     WHERE archived_at IS NULL
        AND harvested_on >= DATE_SUB(CURDATE(), INTERVAL ' . ($chartPeriodDays - 1) . ' DAY)
        AND harvested_on <= CURDATE()
     GROUP BY harvested_on
     ORDER BY harvested_on'
)->fetchAll();
$harvestsByDay = [];
foreach ($dailyHarvestRows as $row) {
    $harvestsByDay[$row['harvested_on']] = (float) $row['quantity'];
}

$recentSales = $isAdmin
    ? $db->query(
    'SELECT
        o.order_code,
        o.eligible_quantity AS quantity,
        o.final_total AS total,
        o.created_at,
        o.payment_status,
        o.order_status,
        u.name AS buyer,
        (SELECT COUNT(*) FROM sales AS si WHERE si.order_code = o.order_code) AS item_count
     FROM orders AS o
     JOIN users AS u ON u.id = o.buyer_id
     ORDER BY o.created_at DESC
     LIMIT 8'
    )->fetchAll()
    : [];
$recentHarvests = $db->query(
    'SELECT h.harvested_on, h.quantity, v.name AS vegetable, v.unit
     FROM harvests AS h
     JOIN plantings AS p ON p.id = h.planting_id
     JOIN vegetables AS v ON v.id = p.vegetable_id
     WHERE h.archived_at IS NULL
     ORDER BY h.harvested_on DESC, h.id DESC
     LIMIT 6'
)->fetchAll();
$recentActivities = $isAdmin
    ? $db->query(
        'SELECT a.action, a.entity_type, a.details, a.created_at, u.name AS actor
         FROM activity_logs AS a
         LEFT JOIN users AS u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 8'
    )->fetchAll()
    : [];
$topSellingRows = $isAdmin
    ? $db->query(
        "SELECT v.name, v.unit, SUM(s.quantity) AS quantity
         FROM sales AS s
         JOIN orders AS o ON o.order_code = s.order_code
         JOIN vegetables AS v ON v.id = s.vegetable_id
         WHERE o.payment_status = 'paid'
            AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY v.id, v.name, v.unit
         ORDER BY quantity DESC, v.name
         LIMIT 7"
    )->fetchAll()
    : [];

$chartSalesLabels = [];
$chartSalesValues = [];
$chartHarvestLabels = [];
$chartHarvestValues = [];
for ($daysAgo = $chartPeriodDays - 1; $daysAgo >= 0; $daysAgo--) {
    $day = date('Y-m-d', strtotime('-' . $daysAgo . ' days'));
    $label = date('M j', strtotime($day));
    $chartSalesLabels[] = $label;
    $chartSalesValues[] = $salesByDay[$day] ?? 0;
    $chartHarvestLabels[] = $label;
    $chartHarvestValues[] = $harvestsByDay[$day] ?? 0;
}

$inventoryChartRows = array_slice($inventoryRows, 0, 7);
$inventoryDistributionRows = $inventoryRows;
$pageTitle = 'Dashboard';
$pageClass = 'operations-dashboard ' . ($isAdmin ? 'is-admin' : 'is-staff');
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-welcome">
    <div>
        <p class="dashboard-eyebrow"><?= $isAdmin ? 'Farm operations · Admin' : 'Daily farm operations' ?></p>
        <h1>Welcome back, <?= h(current_user()['name']) ?></h1>
        <p><?= $isAdmin
            ? 'Here’s what’s happening across your farm today.'
            : 'Your farm activity and priorities for today.' ?></p>
    </div>
    <div class="dashboard-date">
        <span class="dashboard-date-icon" aria-hidden="true">▦</span>
        <span><?= h(date('l, F j, Y')) ?></span>
    </div>
</section>

<section class="dashboard-stat-grid <?= $isAdmin ? 'dashboard-admin-stats' : 'dashboard-staff-stats' ?>" aria-label="Farm summary">
    <?php if ($isAdmin): ?>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">◉</span>
        <span class="dashboard-stat-label">Active vegetables</span>
        <strong><?= number_format($vegetableCount) ?></strong>
        <small>Available in the catalog</small>
    </article>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">▤</span>
        <span class="dashboard-stat-label">Total inventory</span>
        <strong><?= number_format($inventoryCount, 2) ?></strong>
        <small>Combined quantity across catalog units</small>
    </article>
    <?php else: ?>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">⌁</span>
        <span class="dashboard-stat-label">Today's harvest</span>
        <strong><?= number_format($todayHarvestQuantity, 2) ?></strong>
        <small>Combined quantity; units vary by crop</small>
    </article>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">▤</span>
        <span class="dashboard-stat-label">Available inventory</span>
        <strong><?= number_format($inventoryCount, 2) ?></strong>
        <small>Combined quantity across catalog units</small>
    </article>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">↗</span>
        <span class="dashboard-stat-label">Sales today</span>
        <strong><?= money($salesToday) ?></strong>
        <small>Paid orders today</small>
        <?php if ($salesTrendPercent !== null): ?>
            <small class="dashboard-stat-trend <?= $salesTrendPercent >= 0 ? 'is-positive' : 'is-negative' ?>">
                <?= $salesTrendPercent >= 0 ? '↑' : '↓' ?>
                <?= number_format(abs($salesTrendPercent), 1) ?>% vs previous 7 days
            </small>
        <?php elseif ($currentSevenDaySales > 0): ?>
            <small class="dashboard-stat-trend is-positive">Sales recorded this week</small>
        <?php else: ?>
            <small class="dashboard-stat-trend">No sales in the last 7 days</small>
        <?php endif; ?>
    </article>
    <a
        class="dashboard-stat-card dashboard-stat-card-link"
        href="<?= h(app_url('modules/sales.php')) ?>"
        aria-label="View <?= number_format($openOrderCount) ?> open orders"
    >
        <span class="dashboard-stat-icon" aria-hidden="true">▱</span>
        <span class="dashboard-stat-label">Open orders</span>
        <strong><?= number_format($openOrderCount) ?></strong>
        <small>Need fulfillment</small>
    </a>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">⌁</span>
        <span class="dashboard-stat-label">Total harvests</span>
        <strong><?= number_format($harvestCount) ?></strong>
        <small>Active harvest records</small>
    </article>
    <a
        class="dashboard-stat-card dashboard-stat-card-link dashboard-stat-card-warning"
        href="<?= h(app_url('modules/inventory.php')) ?>"
        aria-label="View <?= number_format($lowStockCount) ?> low stock items"
    >
        <span class="dashboard-stat-icon" aria-hidden="true">!</span>
        <span class="dashboard-stat-label">Low stock items</span>
        <strong><?= number_format($lowStockCount) ?></strong>
        <small>At or below <?= number_format($lowStockThreshold, 2) ?> units</small>
    </a>
    <?php else: ?>
    <a
        class="dashboard-stat-card dashboard-stat-card-link"
        href="<?= h(app_url('modules/sales.php')) ?>"
        aria-label="Review <?= number_format($openOrderCount) ?> open orders"
    >
        <span class="dashboard-stat-icon" aria-hidden="true">▱</span>
        <span class="dashboard-stat-label">Open orders</span>
        <strong><?= number_format($openOrderCount) ?></strong>
        <small>Ready for staff review</small>
    </a>
    <?php endif; ?>
</section>

<section class="dashboard-section dashboard-actions" aria-label="Quick actions">
    <div>
        <h2>Quick actions</h2>
        <p><?= $isAdmin ? 'Shortcuts to your farm management tools.' : 'The tools you need for today’s farm work.' ?></p>
    </div>
    <div class="dashboard-action-links">
        <?php if ($isAdmin): ?>
            <a class="button" href="<?= h(app_url('modules/vegetables.php')) ?>"><span aria-hidden="true">＋</span> Add vegetable</a>
            <a class="button secondary" href="<?= h(app_url('modules/harvests.php')) ?>"><span aria-hidden="true">⌁</span> Record harvest</a>
            <a class="button secondary" href="<?= h(app_url('modules/inventory.php')) ?>"><span aria-hidden="true">▤</span> Manage inventory</a>
            <a class="button secondary" href="<?= h(app_url('modules/sales.php')) ?>"><span aria-hidden="true">↗</span> Review orders</a>
            <a class="button secondary" href="<?= h(app_url('modules/reports.php')) ?>"><span aria-hidden="true">▥</span> Reports</a>
        <?php else: ?>
            <a class="button" href="<?= h(app_url('modules/harvests.php')) ?>"><span aria-hidden="true">⌁</span> Record harvest</a>
            <a class="button secondary" href="<?= h(app_url('modules/inventory.php')) ?>"><span aria-hidden="true">▤</span> Update inventory</a>
            <a class="button secondary" href="<?= h(app_url('modules/vegetables.php')) ?>"><span aria-hidden="true">◉</span> Vegetables</a>
            <a class="button secondary" href="<?= h(app_url('modules/plantings.php')) ?>"><span aria-hidden="true">♧</span> Plantings</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($lowStockItems): ?>
    <section class="dashboard-alert" aria-labelledby="low-stock-title">
        <div class="dashboard-alert-heading">
            <span class="dashboard-alert-icon" aria-hidden="true">!</span>
            <div>
                <h2 id="low-stock-title">Stock needs attention</h2>
                <p><?= count($lowStockItems) ?> active <?= count($lowStockItems) === 1 ? 'item is' : 'items are' ?> at or below <?= number_format($lowStockThreshold, 2) ?> units.</p>
            </div>
        </div>
        <div class="dashboard-alert-items">
            <?php foreach (array_slice($lowStockItems, 0, 5) as $item): ?>
                <a href="<?= h(app_url('modules/inventory.php')) ?>">
                    <span><?= h($item['name']) ?></span>
                    <strong><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></strong>
                </a>
            <?php endforeach; ?>
            <?php if (count($lowStockItems) > 5): ?>
                <a class="dashboard-alert-more" href="<?= h(app_url('modules/inventory.php')) ?>">
                    +<?= count($lowStockItems) - 5 ?> more
                </a>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="dashboard-chart-grid" aria-label="Farm performance charts">
    <?php if ($isAdmin): ?>
    <form class="dashboard-chart-period" method="get" action="<?= h(app_url('dashboard.php')) ?>">
        <label for="chart-period">Chart period</label>
        <select id="chart-period" name="period">
            <?php foreach ([7, 30, 90] as $period): ?>
                <option value="<?= $period ?>" <?= $chartPeriodDays === $period ? 'selected' : '' ?>>
                    Last <?= $period ?> days
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button secondary" type="submit">Update charts</button>
    </form>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <article class="dashboard-chart-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Sales performance</h2>
                <p>Paid revenue · last <?= $chartPeriodDays ?> days</p>
            </div>
            <span class="dashboard-chart-symbol" aria-hidden="true">↗</span>
        </div>
        <?php if (array_sum($chartSalesValues) > 0): ?>
            <div class="dashboard-canvas-wrap">
                <canvas
                    class="dashboard-chart"
                    data-chart="line"
                    data-format="currency"
                    data-labels="<?= h(json_encode($chartSalesLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    data-values="<?= h(json_encode($chartSalesValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    aria-label="Line chart of paid sales revenue over the last <?= $chartPeriodDays ?> days"
                    role="img"
                ></canvas>
            </div>
        <?php else: ?>
            <p class="empty">No paid sales in the last <?= $chartPeriodDays ?> days.</p>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <article class="dashboard-chart-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Inventory overview</h2>
                <p>Available stock by vegetable</p>
            </div>
            <span class="dashboard-chart-symbol" aria-hidden="true">◌</span>
        </div>
        <?php if (array_sum(array_map(static fn (array $item): float => (float) $item['quantity'], $inventoryDistributionRows)) > 0): ?>
            <div class="dashboard-inventory-chart">
                <canvas
                    class="dashboard-chart dashboard-doughnut"
                    data-chart="doughnut"
                    data-labels="<?= h(json_encode(array_column($inventoryDistributionRows, 'name'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    data-values="<?= h(json_encode(array_map(static fn (array $item): float => (float) $item['quantity'], $inventoryDistributionRows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    aria-label="Doughnut chart of inventory by vegetable"
                    role="img"
                ></canvas>
                <ul class="dashboard-chart-legend">
                    <?php foreach ($inventoryDistributionRows as $index => $item): ?>
                        <li><span class="chart-legend-dot chart-color-<?= $index % 7 ?>" aria-hidden="true"></span><?= h($item['name']) ?><strong><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <p class="empty">No inventory quantities to visualize.</p>
        <?php endif; ?>
    </article>

    <article class="dashboard-chart-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Harvest production</h2>
                <p>Recorded quantity · last <?= $chartPeriodDays ?> days</p>
            </div>
            <span class="dashboard-chart-symbol" aria-hidden="true">⌁</span>
        </div>
        <?php if (array_sum($chartHarvestValues) > 0): ?>
            <div class="dashboard-canvas-wrap">
                <canvas
                    class="dashboard-chart"
                    data-chart="bar"
                    data-labels="<?= h(json_encode($chartHarvestLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    data-values="<?= h(json_encode($chartHarvestValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    aria-label="Bar chart of harvest quantity over the last <?= $chartPeriodDays ?> days"
                    role="img"
                ></canvas>
            </div>
        <?php else: ?>
            <p class="empty">No active harvests in the last <?= $chartPeriodDays ?> days.</p>
        <?php endif; ?>
    </article>

    <article class="dashboard-chart-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Stock comparison</h2>
                <p>Top stocked active vegetables</p>
            </div>
            <span class="dashboard-chart-symbol" aria-hidden="true">▥</span>
        </div>
        <?php if ($inventoryChartRows): ?>
            <div class="dashboard-canvas-wrap dashboard-canvas-wrap-tall">
                <canvas
                    class="dashboard-chart"
                    data-chart="horizontal-bar"
                    data-labels="<?= h(json_encode(array_map(
                        static fn (array $item): string => $item['name'] . ' (' . $item['unit'] . ')',
                        $inventoryChartRows
                    ), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    data-values="<?= h(json_encode(array_map(static fn (array $item): float => (float) $item['quantity'], $inventoryChartRows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    aria-label="Horizontal bar chart comparing stock quantities by vegetable"
                    role="img"
                ></canvas>
            </div>
        <?php else: ?>
            <p class="empty">Add vegetables to view stock comparisons.</p>
        <?php endif; ?>
    </article>

    <?php if ($isAdmin): ?>
    <article class="dashboard-chart-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Top-selling vegetables</h2>
                <p>Paid quantities · last 30 days</p>
            </div>
            <span class="dashboard-chart-symbol" aria-hidden="true">↗</span>
        </div>
        <?php if ($topSellingRows): ?>
            <div class="dashboard-canvas-wrap dashboard-canvas-wrap-tall">
                <canvas
                    class="dashboard-chart"
                    data-chart="horizontal-bar"
                    data-labels="<?= h(json_encode(
                        array_map(
                            static fn (array $item): string => $item['name'] . ' (' . $item['unit'] . ')',
                            $topSellingRows
                        ),
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                    )) ?>"
                    data-values="<?= h(json_encode(
                        array_map(static fn (array $item): float => (float) $item['quantity'], $topSellingRows),
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                    )) ?>"
                    aria-label="Horizontal bar chart of top-selling vegetables by paid quantity over the last 30 days"
                    role="img"
                ></canvas>
            </div>
        <?php else: ?>
            <p class="empty">No paid vegetable sales in the last 30 days.</p>
        <?php endif; ?>
    </article>
    <?php endif; ?>
</section>

<section class="dashboard-bottom-grid">
    <?php if ($isAdmin): ?>
    <article class="dashboard-table-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Recent orders</h2>
                <p>Latest order activity</p>
            </div>
            <a href="<?= h(app_url('modules/sales.php')) ?>">Review orders</a>
        </div>
        <?php if (!$recentSales): ?>
            <p class="empty">Sales will appear here after the first order is placed.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Order</th><th>Buyer</th><th>Items</th><th>Total</th><th>Payment / delivery</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td><strong><?= h($sale['order_code']) ?></strong><small class="table-secondary"><?= h(date('M j, g:i a', strtotime($sale['created_at']))) ?></small></td>
                                <td><?= h($sale['buyer']) ?></td>
                                <td><?= number_format((int) $sale['item_count']) ?> product(s)<small class="table-secondary"><?= number_format((float) $sale['quantity'], 2) ?> units</small></td>
                                <td><strong><?= money($sale['total']) ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?= h($sale['payment_status']) ?>">
                                        <?= h(ucwords(str_replace('_', ' ', $sale['payment_status']))) ?>
                                    </span>
                                    <small class="table-secondary">
                                        <span class="status-badge status-<?= h($sale['order_status']) ?>">
                                            <?= h(ucwords(str_replace('_', ' ', $sale['order_status']))) ?>
                                        </span>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php else: ?>
    <article class="dashboard-table-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Recently updated inventory</h2>
                <p>Latest stock updates</p>
            </div>
            <a href="<?= h(app_url('modules/inventory.php')) ?>">View inventory</a>
        </div>
        <?php if (!$recentInventoryUpdates): ?>
            <p class="empty">Inventory updates will appear here.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Vegetable</th><th>Available</th><th>Last updated</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInventoryUpdates as $item): ?>
                            <tr>
                                <td><?= h($item['name']) ?></td>
                                <td><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                                <td><?= h(date('M j, g:i a', strtotime($item['updated_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <article class="dashboard-table-card dashboard-recent-harvests">
        <div class="dashboard-card-heading">
            <div>
                <h2>Recent harvests</h2>
                <p>Latest produce gathered</p>
            </div>
            <a href="<?= h(app_url('modules/harvests.php')) ?>">View all</a>
        </div>
        <?php if (!$recentHarvests): ?>
            <p class="empty">Record your first harvest to see activity here.</p>
        <?php else: ?>
            <ul class="recent-harvest-list">
                <?php foreach ($recentHarvests as $harvest): ?>
                    <li>
                        <span class="recent-harvest-icon" aria-hidden="true">⌁</span>
                        <span class="recent-harvest-copy">
                            <strong><?= h($harvest['vegetable']) ?></strong>
                            <small><?= h(date('M j, Y', strtotime($harvest['harvested_on']))) ?></small>
                        </span>
                        <strong><?= number_format((float) $harvest['quantity'], 2) ?> <small><?= h($harvest['unit']) ?></small></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
</section>
<?php if ($isAdmin): ?>
    <section class="dashboard-table-card dashboard-inventory-updates" aria-labelledby="inventory-updates-title">
        <div class="dashboard-card-heading">
            <div>
                <h2 id="inventory-updates-title">Recent inventory updates</h2>
                <p>Latest recorded stock quantities</p>
            </div>
            <a href="<?= h(app_url('modules/inventory.php')) ?>">View inventory</a>
        </div>
        <?php if (!$recentInventoryUpdates): ?>
            <p class="empty">Inventory updates will appear here.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Vegetable</th><th>Available</th><th>Last updated</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInventoryUpdates as $item): ?>
                            <tr>
                                <td><?= h($item['name']) ?></td>
                                <td><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                                <td><?= h(date('M j, g:i a', strtotime($item['updated_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <section class="dashboard-table-card dashboard-activity-card" aria-labelledby="recent-activity-title">
        <div class="dashboard-card-heading">
            <div>
                <h2 id="recent-activity-title">Recent activity</h2>
                <p>Latest changes made across the farm</p>
            </div>
            <a href="<?= h(app_url('modules/activity_logs.php')) ?>">View activity log</a>
        </div>
        <?php if (!$recentActivities): ?>
            <p class="empty">Activity will appear here as the team works.</p>
        <?php else: ?>
            <ul class="dashboard-activity-list">
                <?php foreach ($recentActivities as $activity): ?>
                    <li>
                        <span class="dashboard-activity-mark" aria-hidden="true">•</span>
                        <span class="dashboard-activity-copy">
                            <strong><?= h(ucwords(str_replace('_', ' ', $activity['action']))) ?></strong>
                            <small>
                                <?= h($activity['details'] ?: ucwords(str_replace('_', ' ', $activity['entity_type']))) ?>
                                · <?= h($activity['actor'] ?? 'Former user') ?>
                            </small>
                        </span>
                        <time datetime="<?= h(date(DATE_ATOM, strtotime($activity['created_at']))) ?>">
                            <?= h(date('M j, g:i a', strtotime($activity['created_at']))) ?>
                        </time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
