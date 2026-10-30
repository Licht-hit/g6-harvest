<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$vegetables = $pdo->query(
    'SELECT
        v.id,
        v.name,
        v.variety,
        v.description,
        v.unit,
        v.price,
        COALESCE(i.quantity, 0) AS stock
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE v.active = 1
     ORDER BY v.name'
)->fetchAll();

$pageTitle = 'Farm shop';
require __DIR__ . '/../includes/header.php';
?>
<section class="shop-hero">
    <div class="shop-hero-copy">
        <p class="shop-eyebrow">Good food, grown close to home</p>
        <h1>Fresh from the farm</h1>
        <p>Shop seasonal vegetables grown with care. Fresh picks, fair prices, and a little goodness in every basket.</p>
    </div>
    <a class="button shop-hero-button" href="<?= h(app_url('buyer/cart.php')) ?>">
        View your cart <span aria-hidden="true">→</span>
        <span class="cart-count"><?= cart_count() ?></span>
    </a>
</section>

<section class="shop-highlights" aria-label="Why shop with us">
    <div><span aria-hidden="true">🌱</span><strong>Freshly harvested</strong><small>Picked with care</small></div>
    <div><span aria-hidden="true">🥕</span><strong>Seasonal produce</strong><small>Good things in season</small></div>
    <div><span aria-hidden="true">🤝</span><strong>Support local farms</strong><small>Good for our community</small></div>
</section>

<div class="shop-section-heading">
    <div>
        <p class="shop-eyebrow">From our fields to your table</p>
        <h2>Shop the harvest</h2>
    </div>
    <p class="muted">Choose your favorites and add them to your basket.</p>
</div>

<?php if (!$vegetables): ?>
    <section class="panel empty">There is no produce in the catalog yet. Please check back soon.</section>
<?php else: ?>
    <div class="card-grid">
    <?php foreach ($vegetables as $vegetable): ?>
        <article class="card product-card">
            <div class="product-image" aria-hidden="true"><span>🥬</span><small>FRESH PICK</small></div>
            <div class="product-card-content">
                <div class="product-card-heading">
                    <h3><?= h($vegetable['name']) ?></h3>
                    <?php if ($vegetable['variety']): ?>
                        <span class="product-variety"><?= h($vegetable['variety']) ?></span>
                    <?php endif; ?>
                </div>

                <p class="product-description"><?= h($vegetable['description'] ?? 'Fresh seasonal produce.') ?></p>

                <div class="product-price">
                    <strong><?= money($vegetable['price']) ?></strong>
                    <span>per <?= h($vegetable['unit']) ?></span>
                </div>
                <p class="product-stock <?= (float) $vegetable['stock'] > 0 ? '' : 'out-of-stock' ?>">
                    <span aria-hidden="true"></span>
                    <?php if ((float) $vegetable['stock'] > 0): ?>
                        <?= number_format((float) $vegetable['stock'], 2) ?> <?= h($vegetable['unit']) ?> available
                    <?php else: ?>
                        Currently out of stock
                    <?php endif; ?>
                </p>
                <a class="product-details-link" href="<?= h(app_url('buyer/product.php?id=' . (int) $vegetable['id'])) ?>">
                    See product details <span aria-hidden="true">→</span>
                </a>
            </div>

            <?php if ((float) $vegetable['stock'] > 0): ?>
                <form class="product-card-form" method="post" action="<?= h(app_url('buyer/cart.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input
                        type="hidden"
                        name="product_id"
                        value="<?= (int) $vegetable['id'] ?>"
                    >

                    <div class="field">
                        <label for="quantity-<?= (int) $vegetable['id'] ?>">Quantity (<?= h($vegetable['unit']) ?>)</label>
                        <input
                            id="quantity-<?= (int) $vegetable['id'] ?>"
                            class="quantity-input"
                            type="number"
                            min="0.01"
                            max="<?= h($vegetable['stock']) ?>"
                            step="0.01"
                            name="quantity"
                            value="1"
                            required
                        >
                    </div>
                    <button type="submit">Add to cart <span aria-hidden="true">+</span></button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
