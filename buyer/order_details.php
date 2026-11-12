<?php
// This page shows one buyer order and its payment details.
// It is easy to follow because each query is focused on one order.
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$orderCode = is_string($_GET['order'] ?? null) ? trim($_GET['order']) : '';
if (!preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
    http_response_code(404);
    exit('Order not found.');
}

$orderStatement = $db->prepare(
    'SELECT o.*, u.name AS confirmed_by_name
     FROM orders AS o
     LEFT JOIN users AS u ON u.id = o.payment_confirmed_by
     WHERE o.order_code = ? AND o.buyer_id = ?'
);
$orderStatement->execute([$orderCode, (int) current_user()['id']]);
$order = $orderStatement->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$itemsStatement = $db->prepare(
    'SELECT s.quantity, s.unit_price, s.total, v.name, v.unit
     FROM sales AS s
     JOIN vegetables AS v ON v.id = s.vegetable_id
     WHERE s.order_code = ? AND s.buyer_id = ?
     ORDER BY v.name'
);
$itemsStatement->execute([$orderCode, (int) current_user()['id']]);
$items = $itemsStatement->fetchAll();

$historyStatement = $db->prepare(
    'SELECT ph.*, u.name AS actor_name
     FROM payment_history AS ph
     LEFT JOIN users AS u ON u.id = ph.actor_id
     WHERE ph.order_code = ?
     ORDER BY ph.created_at DESC, ph.id DESC'
);
$historyStatement->execute([$orderCode]);
$paymentHistory = $historyStatement->fetchAll();

$pageTitle = 'Order ' . $orderCode;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="no-print">
        <h1>Order <?= h($orderCode) ?></h1>
        <p class="muted">Placed <?= h($order['created_at']) ?></p>
    </div>
    <div>
        <button type="button" class="button secondary" data-print>Print receipt</button>
        <a href="<?= h(app_url('buyer/orders.php')) ?>">Back to orders</a>
    </div>
</div>

<section class="panel section receipt">
    <h2>Order details</h2>
    <p>
        <strong>Delivery status:</strong>
        <span data-order-status-for="<?= h($orderCode) ?>">
            <?= h(ucwords(str_replace('_', ' ', $order['order_status']))) ?>
        </span>
    </p>
    <p>
        <strong>Payment:</strong>
        <?= h(strtoupper($order['payment_method'])) ?> ·
        <span data-payment-status-for="<?= h($orderCode) ?>">
            <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?>
        </span>
    </p>
    <?php if ($order['order_status'] === 'cancelled' && $order['payment_status'] === 'paid'): ?>
        <p class="notice error" role="status">
            Payment completed after this order was cancelled. Please contact Harvest support to arrange a refund or discuss next steps.
        </p>
    <?php endif; ?>
    <p>
        <strong>Combined eligible quantity:</strong>
        <?= number_format((float) $order['eligible_quantity'], 2) ?> units
    </p>
    <p><strong>Delivery address:</strong> <?= nl2br(h($order['delivery_address'])) ?></p>
    <?php if ($order['payment_reference']): ?>
        <p><strong>Payment reference:</strong> <?= h($order['payment_reference']) ?></p>
    <?php endif; ?>
    <?php if ($order['payment_confirmed_at']): ?>
        <p>
            <strong>Payment collected/confirmed:</strong>
            <?= h($order['payment_confirmed_at']) ?>
            <?php if ($order['confirmed_by_name']): ?>
                by <?= h($order['confirmed_by_name']) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Produce</th>
                    <th>Quantity</th>
                    <th>Unit price</th>
                    <th>Line subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= h($item['name']) ?></td>
                        <td><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                        <td><?= money($item['unit_price']) ?></td>
                        <td><?= money($item['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <dl class="checkout-totals">
        <div><dt>Original vegetable subtotal</dt><dd><?= money($order['subtotal']) ?></dd></div>
        <div>
            <dt>Bulk discount (<?= number_format((float) $order['discount_percentage'], 0) ?>%)</dt>
            <dd>-<?= money($order['discount_amount']) ?></dd>
        </div>
        <div><dt>Delivery fee</dt><dd><?= money($order['delivery_fee']) ?></dd></div>
        <div class="checkout-final-total"><dt>Final total</dt><dd><?= money($order['final_total']) ?></dd></div>
    </dl>

    <section class="payment-history">
        <h2>Payment history</h2>
        <?php if (!$paymentHistory): ?>
            <p class="muted">No payment events have been recorded.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Event</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>Reference / recorded by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $event): ?>
                            <tr>
                                <td><?= h($event['created_at']) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $event['event_type']))) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $event['payment_status']))) ?></td>
                                <td><?= money($event['amount']) ?></td>
                                <td>
                                    <?= h($event['payment_reference'] ?: $event['actor_name'] ?: '—') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
