<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $cart = cart_contents();

    if ($action === 'add') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);

        if (!$productId || $productId < 1 || $quantity === false || $quantity <= 0) {
            flash('error', 'Choose a valid product and quantity.');
            redirect(app_url('buyer/index.php'));
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
            redirect(app_url('buyer/index.php'));
        }
        $cart[$productId] = $newQuantity;
        $_SESSION['cart'] = $cart;
        flash('success', $product['name'] . ' added to your cart.');
        redirect(app_url('buyer/cart.php'));
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

    // Calculate each line total so the buyer can see the full cart cost.
    foreach ($items as &$item) {
        $item['cart_quantity'] = (float) $cart[$item['id']];
        $item['line_total'] = (float) $item['price'] * $item['cart_quantity'];
        $total += $item['line_total'];
    }
    unset($item);
}
$pageTitle = 'Shopping cart';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Your cart</h1>
    <a href="<?= h(app_url('buyer/index.php')) ?>">Continue shopping</a>
</div>

<?php if (!$items): ?>
    <section class="panel empty">
        Your cart is empty.
        <a href="<?= h(app_url('buyer/index.php')) ?>">Browse produce</a>.
    </section>
<?php else: ?>
    <section class="panel section">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">

            <div class="table-wrap">
                <table>
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
                            <tr>
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
                                    <?php if ($item['active']): ?>
                                        <?= number_format((float) $item['stock'], 2) ?>
                                        <?= h($item['unit']) ?>
                                    <?php else: ?>
                                        Unavailable
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

            <p><strong>Cart total: <?= money($total) ?></strong></p>
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

        <p>
            <a class="button" href="<?= h(app_url('buyer/checkout.php')) ?>">
                Proceed to checkout
            </a>
        </p>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
