<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    http_response_code(404);
    exit('Product not found.');
}

$statement = $db->prepare(
    'SELECT
        v.*,
        COALESCE(i.quantity, 0) AS stock,
        (
            SELECT MAX(h.harvested_on)
            FROM harvests AS h
            JOIN plantings AS p ON p.id = h.planting_id
            WHERE p.vegetable_id = v.id
                AND h.archived_at IS NULL
        ) AS latest_harvested_on
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

$picture = product_picture($product['name'], $product['image_path']);
$stock = (float) $product['stock'];
$lowStockThreshold = 5.0;
$stockStatus = $stock <= 0
    ? 'out'
    : ($stock <= $lowStockThreshold ? 'low' : 'in');
$stockStatusLabel = [
    'in' => 'In Stock',
    'low' => 'Low Stock',
    'out' => 'Out of Stock',
][$stockStatus];
$hasVariety = !empty($product['variety']);
$hasDescription = !empty($product['description']);
$hasHarvestDate = !empty($product['latest_harvested_on']);

$pageTitle = $product['name'];
require __DIR__ . '/../includes/header.php';
?>
<nav class="product-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= h(app_url('buyer/index.php')) ?>">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= h(app_url('buyer/index.php')) ?>">Products</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page"><?= h($product['name']) ?></span>
</nav>

<section class="product-detail">
    <div class="product-detail-image">
        <?php if ($picture['url']): ?>
            <img
                src="<?= h($picture['url']) ?>"
                alt="<?= h($product['name']) ?>"
                decoding="async"
            >
        <?php endif; ?>
        <span class="product-detail-image-fallback" aria-hidden="true" <?= $picture['url'] ? 'hidden' : '' ?>>
            <?= h($picture['icon']) ?>
        </span>
        <span class="product-detail-image-tag">Fresh from the farm</span>
    </div>

    <div class="product-detail-content">
        <h1><?= h($product['name']) ?></h1>
        <p class="product-detail-category"><?= h($product['category']) ?></p>

        <?php if ($hasVariety): ?>
            <p class="product-detail-variety"><?= h($product['variety']) ?></p>
        <?php endif; ?>

        <?php if ($hasDescription): ?>
            <p class="product-detail-description"><?= h($product['description']) ?></p>
        <?php endif; ?>

        <div class="product-detail-price">
            <strong><?= money($product['price']) ?></strong>
            <span>/ <?= h($product['unit']) ?></span>
        </div>

        <div class="product-stock-summary">
            <p class="product-availability product-availability--<?= h($stockStatus) ?>">
                <span aria-hidden="true"></span>
                <?= h($stockStatusLabel) ?>
            </p>
            <p class="product-availability-quantity">
                <?= number_format($stock, 2) ?> <?= h($product['unit']) ?> available
            </p>
        </div>

        <?php if ($stock >= 1): ?>
            <form class="product-detail-form" method="post" action="<?= h(app_url('buyer/cart.php')) ?>" data-cart-add>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="return_to" value="product">
                <input type="hidden" name="return_product_id" value="<?= (int) $product['id'] ?>">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                <div class="product-quantity-field">
                    <label for="quantity">Quantity</label>
                    <div class="product-quantity-control" data-quantity-control>
                        <button type="button" class="quantity-stepper" data-quantity-step="-1" aria-label="Decrease quantity">−</button>
                        <input
                            id="quantity"
                            class="quantity-input"
                            type="number"
                            min="1"
                            max="<?= h($product['stock']) ?>"
                            step="0.01"
                            name="quantity"
                            value="1"
                            inputmode="numeric"
                            required
                        >
                        <button type="button" class="quantity-stepper" data-quantity-step="1" aria-label="Increase quantity">+</button>
                    </div>
                    <span class="product-quantity-unit"><?= h($product['unit']) ?></span>
                </div>

                <button class="product-add-to-cart" type="submit">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                        <path d="M3 4h2l2.1 10.1a2 2 0 0 0 2 1.6h8.7a2 2 0 0 0 1.9-1.4L21 8H6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="10" cy="20" r="1.4" fill="currentColor"/>
                        <circle cx="18" cy="20" r="1.4" fill="currentColor"/>
                    </svg>
                    Add to cart
                </button>
            </form>
        <?php elseif ($stock > 0): ?>
            <p class="product-minimum-note">The available stock is below the 1 <?= h($product['unit']) ?> minimum order.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($hasVariety || !empty($product['unit']) || $hasDescription || $hasHarvestDate): ?>
    <section class="product-information" aria-labelledby="product-information-title">
        <div class="product-information-heading">
            <p class="product-detail-eyebrow">Product details</p>
            <h2 id="product-information-title">More about this produce</h2>
        </div>
        <dl class="product-information-list">
            <?php if ($hasVariety): ?>
                <div>
                    <dt>Variety</dt>
                    <dd><?= h($product['variety']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($product['unit'])): ?>
                <div>
                    <dt>Measurement unit</dt>
                    <dd><?= h($product['unit']) ?></dd>
                </div>
            <?php endif; ?>
            <div>
                <dt>Availability</dt>
                <dd>
                    <?= h($stockStatusLabel) ?> ·
                    <?= number_format($stock, 2) ?> <?= h($product['unit']) ?>
                </dd>
            </div>
            <?php if ($hasHarvestDate): ?>
                <div>
                    <dt>Latest harvest recorded</dt>
                    <dd><?= h(date('M j, Y', strtotime($product['latest_harvested_on']))) ?></dd>
                </div>
            <?php endif; ?>
            <?php if ($hasDescription): ?>
                <div class="product-information-description">
                    <dt>Description</dt>
                    <dd><?= h($product['description']) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
