<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    http_response_code(404);
    exit('Product not found.');
}

$statement = $db->prepare(
    'SELECT v.*, COALESCE(i.quantity, 0) AS stock
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE v.id = ? AND v.active = 1'
);
$statement->execute([$productId]);
$product = $statement->fetch();

if (!$product) {
    http_response_code(404);
    exit('Product not found.');
}

$pageTitle = $product['name'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="<?= h(app_url('buyer/index.php')) ?>">← Back to shop</a></p>

<section class="panel">
    <div class="product-image" aria-hidden="true">✿</div>
    <h1><?= h($product['name']) ?></h1>

    <?php if ($product['variety']): ?>
        <p class="muted"><?= h($product['variety']) ?></p>
    <?php endif; ?>

    <p><?= h($product['description'] ?? 'Fresh seasonal produce.') ?></p>
    <p>
        <strong><?= money($product['price']) ?></strong>
        / <?= h($product['unit']) ?>
    </p>
    <p>
        <?= number_format((float) $product['stock'], 2) ?>
        <?= h($product['unit']) ?> available
    </p>

    <?php if ((float) $product['stock'] > 0): ?>
        <form method="post" action="<?= h(app_url('buyer/cart.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

            <div class="field">
                <label for="quantity">Quantity (<?= h($product['unit']) ?>)</label>
                <input
                    id="quantity"
                    class="quantity-input"
                    type="number"
                    min="0.01"
                    max="<?= h($product['stock']) ?>"
                    step="0.01"
                    name="quantity"
                    value="1"
                    required
                >
            </div>

            <button type="submit">Add to cart</button>
        </form>
    <?php else: ?>
        <p class="muted">Currently out of stock.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
