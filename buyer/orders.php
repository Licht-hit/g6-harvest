<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$statement = $db->prepare(
    'SELECT
        order_code,
        MIN(created_at) AS created_at,
        SUM(total) AS total,
        SUM(quantity) AS item_quantity,
        COUNT(*) AS line_count
     FROM sales
     WHERE buyer_id = ?
     GROUP BY order_code
     ORDER BY created_at DESC'
);
$statement->execute([(int) current_user()['id']]);
$orders = $statement->fetchAll();

$pageTitle = 'My orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>My orders</h1>
        <p class="muted">Your purchase history.</p>
    </div>
    <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Browse shop</a>
</div>

<section class="panel section">
    <?php if (!$orders): ?>
        <p class="empty">You have not placed any orders yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
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
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= h($order['order_code']) ?></td>
                            <td><?= h($order['created_at']) ?></td>
                            <td>
                                <?= (int) $order['line_count'] ?> product(s),
                                <?= number_format((float) $order['item_quantity'], 2) ?> units
                            </td>
                            <td><?= money($order['total']) ?></td>
                            <td>
                                <a href="<?= h(app_url(
                                    'buyer/order_details.php?order='
                                    . rawurlencode($order['order_code'])
                                )) ?>">
                                    View details
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
