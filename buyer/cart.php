<?php
// This page handles the buyer shopping cart.
// The code is kept simple so beginners can follow how cart items are added, edited, and removed.
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $cart = cart_contents();

    if ($action === 'add') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $returnTarget = is_string($_POST['return_to'] ?? null)
            ? $_POST['return_to']
            : 'cart';
        $returnProductId = filter_var($_POST['return_product_id'] ?? null, FILTER_VALIDATE_INT);
        $addRedirectPath = app_url('buyer/cart.php');
        if ($returnTarget === 'shop') {
            $addRedirectPath = app_url('buyer/index.php');
        } elseif ($returnTarget === 'dashboard') {
            $addRedirectPath = app_url('buyer/dashboard.php');
        } elseif ($returnTarget === 'favorites') {
            $addRedirectPath = app_url('buyer/favorites.php');
        } elseif ($returnTarget === 'product') {
            $addRedirectPath = $returnProductId && $returnProductId > 0
                ? app_url('buyer/product.php?id=' . $returnProductId)
                : app_url('buyer/index.php');
        }

        if (!$productId || $productId < 1 || $quantity === false || $quantity <= 0) {
            flash('error', 'Choose a valid product and quantity.');
            redirect($addRedirectPath);
        }

        $statement = $db->prepare(
            'SELECT v.name, i.quantity
             FROM vegetables AS v
             JOIN inventory AS i ON i.vegetable_id = v.id
             WHERE v.id = ? AND v.active = 1'
        );
        $statement->execute([$productId]);
        $product = $statement->fetch();
        $newQuantity = (float) ($cart[$productId] ?? 0) + $quantity;

        if (!$product || $newQuantity > (float) $product['quantity']) {
            flash('error', 'That item is unavailable or the requested amount exceeds stock.');
            redirect($addRedirectPath);
        }
        if (!array_key_exists($productId, $cart) && count($cart) >= 50) {
            flash('error', 'Your cart can contain up to 50 produce items. Remove an item before adding another.');
            redirect(app_url('buyer/cart.php'));
        }
        $cart[$productId] = $newQuantity;
        $_SESSION['cart'] = $cart;
        flash('success', $product['name'] . ' added to your cart.');
        $successRedirectPath = $addRedirectPath
            . (strpos($addRedirectPath, '?') !== false ? '&' : '?')
            . 'cart_added=1';
        redirect($successRedirectPath);
    }

    if ($action === 'update') {
        $error = '';
        $quantities = $_POST['quantities'] ?? [];

        if (!is_array($quantities)) {
            $quantities = [];
            $error = 'Cart quantities were invalid.';
        }
        foreach ($quantities as $id => $rawQuantity) {
            $productId = filter_var($id, FILTER_VALIDATE_INT);
            $quantity = filter_var($rawQuantity, FILTER_VALIDATE_FLOAT);
            if (!$productId || $productId < 1 || $quantity === false || $quantity < 0 || $quantity > 99999999.99) {
                $error = 'One or more quantities were invalid.';
                continue;
            }
            if ($quantity == 0) {
                unset($cart[$productId]);
                continue;
            }

            $statement = $db->prepare(
                'SELECT i.quantity
                 FROM vegetables AS v
                 JOIN inventory AS i ON i.vegetable_id = v.id
                 WHERE v.id = ? AND v.active = 1'
            );
            $statement->execute([$productId]);
            $stock = $statement->fetchColumn();

            if ($stock === false || $quantity > (float) $stock) {
                $error = 'A requested quantity is unavailable. Check current stock and try again.';
                continue;
            }
            $cart[$productId] = $quantity;
        }
        $_SESSION['cart'] = $cart;
        flash($error ? 'error' : 'success', $error ?: 'Cart updated.');
        redirect(app_url('buyer/cart.php'));
    }

    if ($action === 'remove') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId && $productId > 0) {
            unset($cart[$productId]);
            $_SESSION['cart'] = $cart;
            flash('success', 'Item removed from cart.');
        }
        redirect(app_url('buyer/cart.php'));
    }
}

$cart = cart_contents();
$items = [];
$total = 0.0;
$cartNeedsReview = count($cart) > 50;
if ($cart) {
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $statement = $db->prepare(
        "SELECT
            v.id,
            v.name,
            v.unit,
            v.price,
            v.active,
            COALESCE(i.quantity, 0) AS stock
         FROM vegetables AS v
         LEFT JOIN inventory AS i ON i.vegetable_id = v.id
         WHERE v.id IN ({$placeholders})
         ORDER BY v.name"
    );
    $statement->execute($ids);
    $items = $statement->fetchAll();
    if (count($items) !== count($cart)) {
        $cartNeedsReview = true;
    }

    // Calculate each line total so the buyer can see the full cart cost.
    foreach ($items as &$item) {
        $item['cart_quantity'] = (float) $cart[$item['id']];
        $item['line_total'] = (float) $item['price'] * $item['cart_quantity'];
        $item['needs_review'] = !$item['active']
            || $item['cart_quantity'] > (float) $item['stock'];
        if ($item['needs_review']) {
            $cartNeedsReview = true;
        }
        $total += $item['line_total'];
    }
    unset($item);
}
$pageTitle = 'Shopping cart';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <p class="module-eyebrow">Your basket</p>
        <h1>Your cart <span class="cart-heading-count">(<?= number_format(count($cart)) ?>)</span></h1>
    </div>
    <a href="<?= h(app_url('buyer/index.php')) ?>">Continue shopping</a>
</div>

<?php if (!$items): ?>
    <section class="module-empty-state cart-empty-state">
        <span class="module-empty-icon" aria-hidden="true">▱</span>
        <h2>Your cart is empty</h2>
        <p>Find something fresh from the farm and add it to your basket.</p>
        <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Browse produce</a>
    </section>
<?php else: ?>
    <section class="panel section cart-panel">
        <?php if ($cartNeedsReview): ?>
            <div class="notice error cart-stock-notice" role="alert">
                <?php if (count($cart) > 50): ?>
                    Your cart has more than 50 produce items. Remove some items to continue to checkout.
                <?php else: ?>
                    Some quantities exceed current stock or include unavailable produce. Adjust or remove the marked items before checkout.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">

            <div
                class="table-wrap cart-table-wrap"
                role="region"
                aria-label="Cart items; scroll horizontally to view all columns"
                tabindex="0"
            >
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>Produce</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th>Availability</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr class="<?= $item['needs_review'] ? 'cart-row-needs-review' : '' ?>">
                                <td><?= h($item['name']) ?></td>
                                <td>
                                    <?= money($item['price']) ?>
                                    / <?= h($item['unit']) ?>
                                </td>
                                <td>
                                    <input
                                        class="quantity-input"
                                        type="number"
                                        min="0"
                                        max="<?= h($item['stock']) ?>"
                                        step="0.01"
                                        name="quantities[<?= (int) $item['id'] ?>]"
                                        value="<?= h($item['cart_quantity']) ?>"
                                        aria-label="Quantity for <?= h($item['name']) ?>"
                                    >
                                </td>
                                <td><?= money($item['line_total']) ?></td>
                                <td>
                                    <?php if (!$item['active']): ?>
                                        <span class="cart-availability-warning">No longer available</span>
                                    <?php elseif ((float) $item['stock'] <= 0): ?>
                                        <span class="cart-availability-warning">Out of stock</span>
                                    <?php elseif ($item['needs_review']): ?>
                                        <span class="cart-availability-warning">
                                            Only <?= number_format((float) $item['stock'], 2) ?> <?= h($item['unit']) ?> available
                                        </span>
                                    <?php else: ?>
                                        <?= number_format((float) $item['stock'], 2) ?>
                                        <?= h($item['unit']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button
                                        class="button danger"
                                        type="submit"
                                        form="remove-<?= (int) $item['id'] ?>"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="cart-total-row">
                <span>Estimated item subtotal</span>
                <strong><?= money($total) ?></strong>
            </div>
            <p class="muted cart-estimate-note">Final total may include delivery and any eligible checkout discount.</p>
            <button type="submit">Update cart</button>
        </form>

        <?php foreach ($items as $item): ?>
            <form
                id="remove-<?= (int) $item['id'] ?>"
                method="post"
                hidden
            >
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input
                    type="hidden"
                    name="product_id"
                    value="<?= (int) $item['id'] ?>"
                >
            </form>
        <?php endforeach; ?>

        <?php if ($cartNeedsReview): ?>
            <p class="cart-checkout-hint">Update or remove the marked quantities to continue to checkout.</p>
        <?php else: ?>
            <p class="cart-checkout-action">
                <a class="button" href="<?= h(app_url('buyer/checkout.php')) ?>">Proceed to checkout</a>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
