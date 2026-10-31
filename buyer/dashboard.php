<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$buyerId = (int) current_user()['id'];

$summaryStatement = $db->prepare(
    'SELECT
        COUNT(*) AS order_count,
        COALESCE(SUM(CASE WHEN payment_status = \'paid\' THEN final_total ELSE 0 END), 0) AS total_spent
     FROM orders
     WHERE buyer_id = ?'
);
$summaryStatement->execute([$buyerId]);
$summary = $summaryStatement->fetch();

$vegetableCount = (int) $db
    ->query('SELECT COUNT(*) FROM vegetables WHERE active = 1')
    ->fetchColumn();

$ordersStatement = $db->prepare(
    'SELECT
        o.order_code,
        o.created_at AS order_date,
        o.final_total AS order_total,
        o.eligible_quantity AS item_count,
        o.payment_status,
        o.order_status
     FROM orders AS o
     WHERE o.buyer_id = ?
     ORDER BY o.created_at DESC
     LIMIT 5'
);
$ordersStatement->execute([$buyerId]);
$recentOrders = $ordersStatement->fetchAll();

$pageTitle = 'Buyer dashboard';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Your Harvest account</p>
        <h1>Welcome, <?= h(current_user()['name']) ?></h1>
        <p>Keep track of your orders and find fresh produce from the farm.</p>
    </div>
    <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Browse the shop</a>
</section>

<section class="module-stat-grid" aria-label="Your account summary">
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">▤</span>
        <span>Your orders</span>
        <strong><?= number_format((int) $summary['order_count']) ?></strong>
        <small>Orders placed with Harvest</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">₱</span>
        <span>Paid purchases</span>
        <strong><?= money($summary['total_spent']) ?></strong>
        <small>Total of paid order items</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">◉</span>
        <span>Produce available</span>
        <strong><?= number_format($vegetableCount) ?></strong>
        <small>Active vegetables in the shop</small>
    </article>
</section>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Recent orders</h2>
            <p class="muted">Your latest purchases.</p>
        </div>
        <a href="<?= h(app_url('buyer/orders.php')) ?>">View all orders</a>
    </div>

    <?php if (!$recentOrders): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">▤</span>
            <h3>No orders yet</h3>
            <p>Your purchases will appear here after you place your first order.</p>
            <?php if ($vegetableCount > 0): ?>
                <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Browse fresh produce</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong><?= h($order['order_code']) ?></strong></td>
                            <td><?= h(date('M j, Y', strtotime($order['order_date']))) ?></td>
                            <td>
                                <?= number_format((float) $order['item_count'], 2) ?> units
                            </td>
                            <td><strong><?= money($order['order_total']) ?></strong></td>
                            <td>
                                <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?><br>
                                <?= h(ucwords(str_replace('_', ' ', $order['order_status']))) ?><br>
                                <a href="<?= h(app_url(
                                    'buyer/order_details.php?order=' . rawurlencode($order['order_code'])
                                )) ?>">
                                    View order
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
