<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

// Load the summary values shown in the dashboard cards.
$vegetableCount = (int) $db
    ->query('SELECT COUNT(*) FROM vegetables WHERE active = 1')
    ->fetchColumn();
$inventoryCount = (int) $db
    ->query('SELECT COALESCE(SUM(quantity), 0) FROM inventory')
    ->fetchColumn();
$salesToday = (int) $db
    ->query(
        'SELECT COALESCE(SUM(total), 0)
         FROM sales
         WHERE status = "paid"
            AND DATE(created_at) = CURDATE()'
    )
    ->fetchColumn();

$recentSales = $db->query(
    'SELECT
        s.order_code,
        s.quantity,
        s.total,
        s.created_at,
        v.name AS vegetable,
        u.name AS buyer
     FROM sales AS s
     JOIN vegetables AS v ON v.id = s.vegetable_id
     JOIN users AS u ON u.id = s.buyer_id
     ORDER BY s.created_at DESC
     LIMIT 8'
)->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Farm dashboard</h1>
        <p class="muted">A quick overview of produce and recent sales.</p>
    </div>
</div>

<div class="stats">
    <div class="card">
        <span class="muted">Active vegetables</span>
        <strong class="stat-value"><?= $vegetableCount ?></strong>
    </div>
    <div class="card">
        <span class="muted">Inventory on hand</span>
        <strong class="stat-value"><?= number_format($inventoryCount, 2) ?></strong>
    </div>
    <div class="card">
        <span class="muted">Sales today</span>
        <strong class="stat-value"><?= money($salesToday) ?></strong>
    </div>
</div>

<section class="panel section">
    <h2>Recent sales</h2>

    <?php if (!$recentSales): ?>
        <p class="empty">No sales recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Buyer</th>
                        <th>Produce</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td><?= h($sale['order_code']) ?></td>
                            <td><?= h($sale['buyer']) ?></td>
                            <td><?= h($sale['vegetable']) ?></td>
                            <td><?= number_format((int) $sale['quantity'], 2) ?></td>
                            <td><?= money($sale['total']) ?></td>
                            <td><?= h($sale['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
