<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$statement = $db->prepare(
    'SELECT
        order_code,
        created_at,
        final_total,
        eligible_quantity,
        payment_method,
        payment_status,
        order_status
     FROM orders
     WHERE buyer_id = ?
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
        <p class="muted">Your purchase, delivery, and payment history.</p>
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
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Delivery</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= h($order['order_code']) ?></td>
                            <td><?= h($order['created_at']) ?></td>
                            <td><?= number_format((float) $order['eligible_quantity'], 2) ?></td>
                            <td><?= money($order['final_total']) ?></td>
                            <td>
                                <?= h(strtoupper($order['payment_method'])) ?> ·
                                <span data-payment-status-for="<?= h($order['order_code']) ?>">
                                    <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?>
                                </span>
                            </td>
                            <td data-order-status-for="<?= h($order['order_code']) ?>">
                                <?= h(ucwords(str_replace('_', ' ', $order['order_status']))) ?>
                            </td>
                            <td>
                                <a href="<?= h(app_url(
                                    'buyer/order_details.php?order=' . rawurlencode($order['order_code'])
                                )) ?>">View details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
