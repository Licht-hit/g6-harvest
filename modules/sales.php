<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$sales = $db->query(
    'SELECT s.*, u.name AS buyer, v.name AS vegetable, v.unit
     FROM sales AS s
     JOIN users AS u ON u.id = s.buyer_id
     JOIN vegetables AS v ON v.id = s.vegetable_id
     ORDER BY s.created_at DESC
     LIMIT 250'
)->fetchAll();

$pageTitle = 'Sales';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Sales</h1>
</div>

<section class="panel section">
    <?php if (!$sales): ?>
        <p class="empty">No sales recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Buyer</th>
                        <th>Produce</th>
                        <th>Quantity</th>
                        <th>Unit price</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <tr>
                            <td><?= h($sale['order_code']) ?></td>
                            <td><?= h($sale['created_at']) ?></td>
                            <td><?= h($sale['buyer']) ?></td>
                            <td><?= h($sale['vegetable']) ?></td>
                            <td>
                                <?= number_format((float) $sale['quantity'], 2) ?>
                                <?= h($sale['unit']) ?>
                            </td>
                            <td><?= money($sale['unit_price']) ?></td>
                            <td><?= money($sale['total']) ?></td>
                            <td><?= h(ucfirst($sale['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
