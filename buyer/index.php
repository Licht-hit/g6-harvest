<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$shopCart = cart_contents();

$favoriteStatement = $db->prepare(
    'SELECT vegetable_id FROM buyer_favorites WHERE buyer_id = ?'
);
$favoriteStatement->execute([(int) current_user()['id']]);
$favoriteIds = array_fill_keys(
    array_map('intval', array_column($favoriteStatement->fetchAll(), 'vegetable_id')),
    true
);

$vegetables = $db->query(
    'SELECT
        v.id,
        v.name,
        v.category,
        v.image_path,
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

$activeOrderStatement = $db->prepare(
    "SELECT order_code, order_status
     FROM orders
     WHERE buyer_id = ? AND order_status NOT IN ('delivered', 'cancelled')
     ORDER BY created_at DESC
     LIMIT 1"
);
$activeOrderStatement->execute([(int) current_user()['id']]);
$activeOrder = $activeOrderStatement->fetch() ?: null;

$pageTitle = 'Farm shop';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($activeOrder): ?>
    <section class="panel buyer-active-order" data-active-order-for="<?= h($activeOrder['order_code']) ?>">
        <div>
            <h2>Your order in progress</h2>
            <p>Order <?= h($activeOrder['order_code']) ?> · Progress updates automatically.</p>
        </div>
        <div class="buyer-active-order-actions">
            <span class="status-badge status-<?= h($activeOrder['order_status']) ?>"
                  data-order-status-for="<?= h($activeOrder['order_code']) ?>">
                <?= h(ucwords(str_replace('_', ' ', $activeOrder['order_status']))) ?>
            </span>
            <a class="button secondary" href="<?= h(app_url(
                'buyer/order_details.php?order=' . rawurlencode($activeOrder['order_code'])
            )) ?>">Track order</a>
        </div>
    </section>
<?php endif; ?>
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
                <option value="stock-high">Stock: high to low</option>
            </select>
        </label>
        <button class="button secondary shop-reset" type="button" data-shop-reset>Reset filters</button>
    </section>
    <div class="card-grid shop-product-grid">
    <?php foreach ($vegetables as $vegetable): ?>
        <?php
        $cartQuantity = is_numeric($shopCart[$vegetable['id']] ?? null)
            ? (float) $shopCart[$vegetable['id']]
            : 0.0;
        $remainingStock = max(0, round((float) $vegetable['stock'] - $cartQuantity, 2));
        $picture = product_picture($vegetable['name'], $vegetable['image_path']);
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
                <form class="favorite-image-form" method="post" action="<?= h(app_url('buyer/favorites.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= isset($favoriteIds[(int) $vegetable['id']]) ? 'remove' : 'save' ?>">
                    <input type="hidden" name="vegetable_id" value="<?= (int) $vegetable['id'] ?>">
                    <input type="hidden" name="return_to" value="shop">
                    <button
                        class="favorite-toggle"
                        type="submit"
                        aria-label="<?= isset($favoriteIds[(int) $vegetable['id']]) ? 'Remove ' . h($vegetable['name']) . ' from favorites' : 'Save ' . h($vegetable['name']) . ' to favorites' ?>"
                        aria-pressed="<?= isset($favoriteIds[(int) $vegetable['id']]) ? 'true' : 'false' ?>"
                    ><?= isset($favoriteIds[(int) $vegetable['id']]) ? '♥' : '♡' ?></button>
                </form>
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

            <?php if ($remainingStock > 0): ?>
                <form class="product-card-form" method="post" action="<?= h(app_url('buyer/cart.php')) ?>" data-cart-add>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="return_to" value="shop">
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
                            max="<?= h((string) $remainingStock) ?>"
                            step="0.01"
                            name="quantity"
                            value="<?= h((string) min(1.0, $remainingStock)) ?>"
                            required
                        >
                    </div>
                    <button type="submit">Add to cart <span aria-hidden="true">+</span></button>
                </form>
            <?php elseif ((float) $vegetable['stock'] > 0): ?>
                <p class="product-already-in-cart">
                    Available stock is already in your cart.
                    <a href="<?= h(app_url('buyer/cart.php')) ?>">Review cart</a>
                </p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    </div>
    <p class="shop-no-results empty" data-shop-no-results role="status" hidden>
        No produce matches those filters. Try a different search or availability setting.
    </p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
