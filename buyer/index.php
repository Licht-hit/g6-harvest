<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$vegetables = $db->query(
    'SELECT
        v.id,
        v.name,
        v.category,
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
    <p class="muted" data-shop-count aria-live="polite">
        <?= count($vegetables) ?> <?= count($vegetables) === 1 ? 'produce item' : 'produce items' ?>
    </p>
</div>

<?php if (!$vegetables): ?>
    <section class="panel empty">There is no produce in the catalog yet. Please check back soon.</section>
<?php else: ?>
    <section class="shop-discovery" aria-label="Find produce">
        <label class="shop-search">
            <span class="shop-search-icon" aria-hidden="true">⌕</span>
            <span class="sr-only">Search produce</span>
            <input
                type="search"
                placeholder="Search vegetables..."
                autocomplete="off"
                data-shop-search
            >
        </label>
        <label class="shop-filter">
            <span>Category</span>
            <select data-shop-category>
                <option value="all">All categories</option>
                <?php foreach (vegetable_categories() as $category): ?>
                    <option value="<?= h($category) ?>"><?= h($category) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="shop-filter">
            <span>Availability</span>
            <select data-shop-availability>
                <option value="all">All produce</option>
                <option value="available">Available now</option>
                <option value="out">Out of stock</option>
            </select>
        </label>
        <label class="shop-filter">
            <span>Sort by</span>
            <select data-shop-sort>
                <option value="featured">Featured</option>
                <option value="name">Name: A to Z</option>
                <option value="price-low">Price: Low to high</option>
                <option value="price-high">Price: High to low</option>
            </select>
        </label>
    </section>
    <div class="card-grid shop-product-grid">
    <?php foreach ($vegetables as $vegetable): ?>
        <?php
        $picture = product_picture($vegetable['name']);
        $searchText = implode(' ', array_filter([
            $vegetable['name'],
            $vegetable['category'],
            $vegetable['variety'],
            $vegetable['description'],
        ]));
        ?>
        <article
            class="card product-card"
            data-shop-product
            data-search="<?= h($searchText) ?>"
            data-name="<?= h($vegetable['name']) ?>"
            data-category="<?= h($vegetable['category']) ?>"
            data-price="<?= h((string) $vegetable['price']) ?>"
            data-stock="<?= h((string) $vegetable['stock']) ?>"
        >
            <div class="product-image">
                <?php if ($picture['url']): ?>
                    <img
                        src="<?= h($picture['url']) ?>"
                        alt="<?= h($vegetable['name']) ?>"
                        loading="lazy"
                        decoding="async"
                    >
                <?php endif; ?>
                <span class="product-image-fallback" aria-hidden="true" <?= $picture['url'] ? 'hidden' : '' ?>>
                    <?= h($picture['icon']) ?>
                </span>
                <span class="product-image-label">FRESH PICK</span>
            </div>
            <div class="product-card-content">
                <div class="product-card-heading">
                    <h3><?= h($vegetable['name']) ?></h3>
                    <?php if ($vegetable['variety']): ?>
                        <span class="product-variety"><?= h($vegetable['variety']) ?></span>
                    <?php endif; ?>
                </div>
                <span class="product-category"><?= h($vegetable['category']) ?></span>

                <p class="product-description">
                    <?= h($vegetable['description'] ?: 'Fresh seasonal produce, picked with care.') ?>
                </p>

                <div class="product-card-meta">
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
                </div>
                <a class="product-details-link" href="<?= h(app_url('buyer/product.php?id=' . (int) $vegetable['id'])) ?>">
                    View details <span aria-hidden="true">→</span>
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
    <p class="shop-no-results empty" data-shop-no-results role="status" hidden>
        No produce matches those filters. Try a different search or availability setting.
    </p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
