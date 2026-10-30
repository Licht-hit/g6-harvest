<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$orderCode = trim($_GET['order'] ?? '');
if (!preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
    http_response_code(404);
    exit('Order not found.');
}

$statement = $db->prepare(
    'SELECT
        s.order_code,
        s.quantity,
        s.unit_price,
        s.total,
        s.status,
        s.created_at,
        v.name,
        v.unit
     FROM sales AS s
     JOIN vegetables AS v ON v.id = s.vegetable_id
     WHERE s.order_code = ? AND s.buyer_id = ?
     ORDER BY v.name'
);
$statement->execute([$orderCode, (int) current_user()['id']]);
$items = $statement->fetchAll();

if (!$items) {
    http_response_code(404);
    exit('Order not found.');
}

$orderTotal = array_sum(array_column($items, 'total'));
$pageTitle = 'Order ' . $orderCode;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Order <?= h($orderCode) ?></h1>
        <p class="muted">Placed <?= h($items[0]['created_at']) ?></p>
    </div>
    <a href="<?= h(app_url('buyer/orders.php')) ?>">Back to orders</a>
</div>

<section class="panel section">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Produce</th>
                    <th>Quantity</th>
                    <th>Unit price</th>
                    <th>Subtotal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= h($item['name']) ?></td>
                        <td>
                            <?= number_format((float) $item['quantity'], 2) ?>
                            <?= h($item['unit']) ?>
                        </td>
                        <td><?= money($item['unit_price']) ?></td>
                        <td><?= money($item['total']) ?></td>
                        <td><?= h(ucfirst($item['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p><strong>Order total: <?= money($orderTotal) ?></strong></p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
