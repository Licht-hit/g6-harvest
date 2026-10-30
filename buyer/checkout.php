<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

$cart = cart_contents();
if (!$cart) {
    flash('error', 'Your cart is empty.');
    redirect(app_url('buyer/cart.php'));
}

$ids = array_map('intval', array_keys($cart));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$statement = $db->prepare(
    "SELECT
        v.id,
        v.name,
        v.unit,
        v.price,
        i.quantity AS stock
     FROM vegetables AS v
     JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE v.active = 1 AND v.id IN ({$placeholders})
     ORDER BY v.id
     FOR UPDATE"
);
$statement->execute($ids);
$items = $statement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $db->beginTransaction();

        // Lock and reload stock so another shopper cannot buy the same stock.
        $statement->execute($ids);
        $lockedItems = $statement->fetchAll();

        if (count($lockedItems) !== count($cart)) {
            throw new RuntimeException('One or more products are no longer available. Review your cart.');
        }

        $byId = [];
        foreach ($lockedItems as $item) {
            $byId[(int) $item['id']] = $item;
        }

        foreach ($cart as $productId => $quantity) {
            $itemId = (int) $productId;
            $itemIsMissing = !isset($byId[$itemId]);
            $quantityIsInvalid = (float) $quantity <= 0;
            $quantityExceedsStock = !$itemIsMissing
                && (float) $quantity > (float) $byId[$itemId]['stock'];

            if ($itemIsMissing || $quantityIsInvalid || $quantityExceedsStock) {
                throw new RuntimeException('Stock changed for one or more cart items. Please review your cart.');
            }
        }

        $orderCode = 'VS-' . strtoupper(bin2hex(random_bytes(5)));
        $insert = $db->prepare(
            "INSERT INTO sales (
                order_code,
                buyer_id,
                vegetable_id,
                quantity,
                unit_price,
                total,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, 'paid')"
        );
        $update = $db->prepare('UPDATE inventory SET quantity = quantity - ? WHERE vegetable_id = ? AND quantity >= ?');

        foreach ($cart as $productId => $quantity) {
            $product = $byId[(int) $productId];
            $lineTotal = round((float) $product['price'] * (float) $quantity, 2);

            $insert->execute([
                $orderCode,
                (int) current_user()['id'],
                (int) $productId,
                $quantity,
                $product['price'],
                $lineTotal,
            ]);
            $update->execute([$quantity, (int) $productId, $quantity]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Stock changed during checkout. Please try again.');
            }
        }

        log_activity('order_placed', 'order', null, 'Order ' . $orderCode . ' placed.');

        // Save all order items together, or roll them all back if any step fails.
        $db->commit();
        unset($_SESSION['cart']);
        flash('success', 'Your order has been placed.');
        redirect(app_url('buyer/order_details.php?order=' . rawurlencode($orderCode)));
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            redirect(app_url('buyer/cart.php'));
        }
        throw $exception;
    }
}

$byId = [];
foreach ($items as $item) {
    $byId[(int) $item['id']] = $item;
}

$total = 0.0;
$valid = count($byId) === count($cart);

foreach ($cart as $productId => $quantity) {
    $itemId = (int) $productId;
    $itemIsMissing = !isset($byId[$itemId]);
    $quantityExceedsStock = !$itemIsMissing
        && (float) $quantity > (float) $byId[$itemId]['stock'];

    if ($itemIsMissing || $quantityExceedsStock) {
        $valid = false;
        continue;
    }

    $total += (float) $byId[$itemId]['price'] * (float) $quantity;
}

$pageTitle = 'Checkout';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Checkout</h1>
    <a href="<?= h(app_url('buyer/cart.php')) ?>">Edit cart</a>
</div>

<section class="panel section">
    <?php if (!$valid): ?>
        <div class="notice error">
            Some items are unavailable or exceed current stock.
            Update your cart before checkout.
        </div>
        <a class="button" href="<?= h(app_url('buyer/cart.php')) ?>">Review cart</a>
    <?php else: ?>
        <h2>Order summary</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Produce</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart as $productId => $quantity): ?>
                        <?php $item = $byId[(int) $productId]; ?>
                        <tr>
                            <td><?= h($item['name']) ?></td>
                            <td>
                                <?= number_format((float) $quantity, 2) ?>
                                <?= h($item['unit']) ?>
                            </td>
                            <td><?= money($item['price']) ?></td>
                            <td>
                                <?= money((float) $item['price'] * (float) $quantity) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p><strong>Total: <?= money($total) ?></strong></p>
        <p class="muted">
            Demo checkout records the order as paid; no payment is processed.
        </p>

        <form method="post">
            <?= csrf_field() ?>
            <button type="submit">Place order</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
