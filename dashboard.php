<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

$lowStockThreshold = 5.0;

$vegetableCount = (int) $db
    ->query('SELECT COUNT(*) FROM vegetables WHERE active = 1')
    ->fetchColumn();
$inventoryCount = (float) $db
    ->query('SELECT COALESCE(SUM(i.quantity), 0) FROM inventory AS i')
    ->fetchColumn();
$salesToday = (float) $db
    ->query(
        "SELECT COALESCE(SUM(final_total), 0)
         FROM orders
         WHERE payment_status = 'paid'
            AND DATE(created_at) = CURDATE()"
    )
    ->fetchColumn();
$harvestCount = (int) $db
    ->query('SELECT COUNT(*) FROM harvests WHERE archived_at IS NULL')
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

$dailySalesRows = $db->query(
    "SELECT DATE(created_at) AS sale_day, SUM(final_total) AS revenue
     FROM orders
     WHERE payment_status = 'paid'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
     GROUP BY DATE(created_at)
     ORDER BY sale_day"
)->fetchAll();
$salesByDay = [];
foreach ($dailySalesRows as $row) {
    $salesByDay[$row['sale_day']] = (float) $row['revenue'];
}

$dailyHarvestRows = $db->query(
    'SELECT harvested_on, SUM(quantity) AS quantity
     FROM harvests
     WHERE archived_at IS NULL
        AND harvested_on >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        AND harvested_on <= CURDATE()
     GROUP BY harvested_on
     ORDER BY harvested_on'
)->fetchAll();
$harvestsByDay = [];
foreach ($dailyHarvestRows as $row) {
    $harvestsByDay[$row['harvested_on']] = (float) $row['quantity'];
}

$recentSales = $db->query(
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
)->fetchAll();
$recentHarvests = $db->query(
    'SELECT h.harvested_on, h.quantity, v.name AS vegetable, v.unit
     FROM harvests AS h
     JOIN plantings AS p ON p.id = h.planting_id
     JOIN vegetables AS v ON v.id = p.vegetable_id
     WHERE h.archived_at IS NULL
     ORDER BY h.harvested_on DESC, h.id DESC
     LIMIT 6'
)->fetchAll();

$chartSalesLabels = [];
$chartSalesValues = [];
$chartHarvestLabels = [];
$chartHarvestValues = [];
for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
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
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-welcome">
    <div>
        <p class="dashboard-eyebrow">Farm operations</p>
        <h1>Welcome back, <?= h(current_user()['name']) ?></h1>
        <p>Here’s what’s happening across your farm today.</p>
    </div>
    <div class="dashboard-date">
        <span class="dashboard-date-icon" aria-hidden="true">▦</span>
        <span><?= h(date('l, F j, Y')) ?></span>
    </div>
</section>

<section class="dashboard-stat-grid" aria-label="Farm summary">
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
        <small>Across all recorded stock</small>
    </article>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">↗</span>
        <span class="dashboard-stat-label">Sales today</span>
        <strong><?= money($salesToday) ?></strong>
        <small>Paid orders today</small>
    </article>
    <article class="dashboard-stat-card">
        <span class="dashboard-stat-icon" aria-hidden="true">⌁</span>
        <span class="dashboard-stat-label">Total harvests</span>
        <strong><?= number_format($harvestCount) ?></strong>
        <small>Active harvest records</small>
    </article>
</section>

<section class="dashboard-section dashboard-actions" aria-label="Quick actions">
    <div>
        <h2>Quick actions</h2>
        <p>Jump into your day-to-day farm tasks.</p>
    </div>
    <div class="dashboard-action-links">
        <a class="button" href="<?= h(app_url('modules/vegetables.php')) ?>"><span aria-hidden="true">＋</span> Add vegetable</a>
        <a class="button secondary" href="<?= h(app_url('modules/harvests.php')) ?>"><span aria-hidden="true">⌁</span> Record harvest</a>
        <a class="button secondary" href="<?= h(app_url('modules/sales.php')) ?>"><span aria-hidden="true">↗</span> Review sales</a>
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
    <article class="dashboard-chart-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Sales performance</h2>
                <p>Paid revenue · last 7 days</p>
            </div>
            <span class="dashboard-chart-symbol" aria-hidden="true">↗</span>
        </div>
        <?php if (array_sum($chartSalesValues) > 0): ?>
            <div class="dashboard-canvas-wrap">
                <canvas
                    class="dashboard-chart"
                    data-chart="line"
                    data-labels="<?= h(json_encode($chartSalesLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    data-values="<?= h(json_encode($chartSalesValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                    aria-label="Line chart of paid sales revenue over the last seven days"
                    role="img"
                ></canvas>
            </div>
        <?php else: ?>
            <p class="empty">No paid sales in the last seven days.</p>
        <?php endif; ?>
    </article>

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
                <p>Recorded quantity · last 7 days</p>
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
                    aria-label="Bar chart of harvest quantity over the last seven days"
                    role="img"
                ></canvas>
            </div>
        <?php else: ?>
            <p class="empty">No active harvests in the last seven days.</p>
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
</section>

<section class="dashboard-bottom-grid">
    <article class="dashboard-table-card">
        <div class="dashboard-card-heading">
            <div>
                <h2>Recent sales</h2>
                <p>Latest order activity</p>
            </div>
            <a href="<?= h(app_url('modules/sales.php')) ?>">View all</a>
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
                                <td><?= h(ucwords(str_replace('_', ' ', $sale['payment_status']))) ?><small class="table-secondary"><?= h(ucwords(str_replace('_', ' ', $sale['order_status']))) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

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
<?php require __DIR__ . '/includes/footer.php'; ?>
