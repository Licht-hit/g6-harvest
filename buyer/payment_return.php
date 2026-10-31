<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$orderCode = is_string($_GET['order'] ?? null) ? trim($_GET['order']) : '';
if (!preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
    http_response_code(404);
    exit('Order not found.');
}

$statement = $db->prepare(
    'SELECT order_code, payment_status
     FROM orders
     WHERE order_code = ? AND buyer_id = ? AND payment_method = \'online\''
);
$statement->execute([$orderCode, (int) current_user()['id']]);
$order = $statement->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$result = $_GET['result'] ?? '';
$pageTitle = 'Online payment';
require __DIR__ . '/../includes/header.php';
?>
<section class="panel section">
    <h1>Online payment status</h1>
    <?php if ($order['payment_status'] === 'paid'): ?>
        <p class="notice success">Your payment has been verified. Thank you!</p>
    <?php elseif ($result === 'cancel'): ?>
        <p class="notice">
            Checkout was cancelled or not completed. Your payment remains pending until
            PayMongo confirms its status.
        </p>
    <?php else: ?>
        <p class="notice">
            Your payment is awaiting confirmation from PayMongo. This page does not mark
            an order as paid; a verified provider webhook does that.
        </p>
    <?php endif; ?>
    <a class="button" href="<?= h(app_url(
        'buyer/order_details.php?order=' . rawurlencode($orderCode)
    )) ?>">View order</a>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
