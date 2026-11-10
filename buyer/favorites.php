<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$buyerId = (int) current_user()['id'];
$cart = cart_contents();
$returnTargets = [
    'dashboard' => 'buyer/dashboard.php',
    'shop' => 'buyer/index.php',
    'favorites' => 'buyer/favorites.php',
    'home' => 'index.php',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $vegetableId = filter_var($_POST['vegetable_id'] ?? null, FILTER_VALIDATE_INT);
    $returnTarget = is_string($_POST['return_to'] ?? null)
        ? $_POST['return_to']
        : 'favorites';
    $redirectPath = $returnTargets[$returnTarget] ?? $returnTargets['favorites'];

    if (
        !in_array($action, ['save', 'remove'], true)
        || !$vegetableId
        || $vegetableId < 1
    ) {
        flash('error', 'Choose a valid produce item.');
        redirect(app_url($redirectPath));
    }

    $productStatement = $db->prepare('SELECT id FROM vegetables WHERE id = ?');
    $productStatement->execute([$vegetableId]);
    if ($productStatement->fetchColumn() === false) {
        flash('error', 'That produce item is no longer available.');
        redirect(app_url($redirectPath));
    }

    if ($action === 'save') {
        $saveStatement = $db->prepare(
            'INSERT INTO buyer_favorites (buyer_id, vegetable_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE vegetable_id = VALUES(vegetable_id)'
        );
        $saveStatement->execute([$buyerId, $vegetableId]);
        flash('success', 'Produce saved to your favorites.');
    } else {
        $removeStatement = $db->prepare(
            'DELETE FROM buyer_favorites WHERE buyer_id = ? AND vegetable_id = ?'
        );
        $removeStatement->execute([$buyerId, $vegetableId]);
        flash('success', 'Produce removed from your favorites.');
    }

    redirect(app_url($redirectPath));
}

$favoritesStatement = $db->prepare(
    'SELECT
        v.id,
        v.name,
        v.category,
        v.unit,
        v.price,
        v.active,
        COALESCE(i.quantity, 0) AS stock
     FROM buyer_favorites AS f
     JOIN vegetables AS v ON v.id = f.vegetable_id
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE f.buyer_id = ?
     ORDER BY v.active DESC, stock > 0 DESC, v.name'
);
$favoritesStatement->execute([$buyerId]);
$favorites = $favoritesStatement->fetchAll();

$pageTitle = 'Saved favorites';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Your saved produce</p>
        <h1>Favorites</h1>
        <p>Keep the produce you love close at hand.</p>
    </div>
    <a class="button secondary" href="<?= h(app_url('buyer/index.php')) ?>">Browse the shop</a>
</section>

<?php if (!$favorites): ?>
    <section class="module-empty-state favorites-empty">
        <span class="module-empty-icon" aria-hidden="true">♥</span>
        <h2>No favorites saved yet</h2>
        <p>Save produce from the shop or dashboard to find it quickly next time.</p>
        <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Explore available produce</a>
    </section>
<?php else: ?>
    <section class="favorites-grid" aria-label="Your saved produce">
        <?php foreach ($favorites as $vegetable): ?>
            <?php
            $picture = product_picture($vegetable['name']);
            $available = (bool) $vegetable['active'] && (float) $vegetable['stock'] > 0;
            $cartQuantity = is_numeric($cart[$vegetable['id']] ?? null)
                ? (float) $cart[$vegetable['id']]
                : 0.0;
            $remainingStock = max(0, round((float) $vegetable['stock'] - $cartQuantity, 2));
            ?>
            <article class="card favorite-card">
                <div class="favorite-card-image product-image">
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
                </div>
                <div class="favorite-card-content">
                    <div class="product-card-heading">
                        <h2><?= h($vegetable['name']) ?></h2>
                        <strong><?= money($vegetable['price']) ?></strong>
                    </div>
                    <span class="product-category"><?= h($vegetable['category']) ?></span>
                    <?php if ($available && $remainingStock > 0): ?>
                        <p class="product-stock"><?= number_format((float) $vegetable['stock'], 2) ?> <?= h($vegetable['unit']) ?> available</p>
                        <form class="favorite-quick-add" method="post" action="<?= h(app_url('buyer/cart.php')) ?>" data-cart-add>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="return_to" value="favorites">
                            <input type="hidden" name="product_id" value="<?= (int) $vegetable['id'] ?>">
                            <label for="favorite-quantity-<?= (int) $vegetable['id'] ?>">
                                Quantity (<?= h($vegetable['unit']) ?>)
                            </label>
                            <input
                                id="favorite-quantity-<?= (int) $vegetable['id'] ?>"
                                class="quantity-input"
                                type="number"
                                name="quantity"
                                min="0.01"
                                max="<?= h((string) $remainingStock) ?>"
                                step="0.01"
                                value="<?= h((string) min(1.0, $remainingStock)) ?>"
                                required
                            >
                            <button type="submit">Add to cart</button>
                        </form>
                    <?php elseif ($available): ?>
                        <p class="favorite-unavailable" role="status">
                            Available stock is already in your cart.
                            <a href="<?= h(app_url('buyer/cart.php')) ?>">Review cart.</a>
                        </p>
                    <?php else: ?>
                        <p class="favorite-unavailable" role="status">
                            <?= $vegetable['active'] ? 'Currently out of stock.' : 'This produce is no longer available.' ?>
                            <a href="<?= h(app_url('buyer/index.php')) ?>">Browse the shop for alternatives.</a>
                        </p>
                    <?php endif; ?>
                    <form method="post" action="<?= h(app_url('buyer/favorites.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="vegetable_id" value="<?= (int) $vegetable['id'] ?>">
                        <input type="hidden" name="return_to" value="favorites">
                        <button class="button secondary favorite-remove" type="submit">Remove favorite</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>