<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_role('buyer');

$buyerId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') !== 'reorder') {
        flash('error', 'Choose a valid dashboard action.');
        redirect(app_url('buyer/dashboard.php'));
    }

    $orderCode = is_string($_POST['order_code'] ?? null) ? trim($_POST['order_code']) : '';
    if ($orderCode === '' || strlen($orderCode) > 24 || !preg_match('/^[A-Za-z0-9-]+$/', $orderCode)) {
        flash('error', 'Choose a valid past order.');
        redirect(app_url('buyer/dashboard.php'));
    }

    $ownedOrder = $db->prepare(
        'SELECT order_status FROM orders WHERE order_code = ? AND buyer_id = ?'
    );
    $ownedOrder->execute([$orderCode, $buyerId]);
    $ownedOrderStatus = $ownedOrder->fetchColumn();
    if ($ownedOrderStatus === false) {
        flash('error', 'That order could not be found in your account.');
        redirect(app_url('buyer/dashboard.php'));
    }
    if (!in_array($ownedOrderStatus, ['delivered', 'cancelled'], true)) {
        flash('error', 'Only delivered or cancelled orders can be reordered.');
        redirect(app_url('buyer/dashboard.php'));
    }

    $orderItemsStatement = $db->prepare(
        'SELECT
            s.vegetable_id,
            v.name,
            SUM(s.quantity) AS quantity,
            v.active,
            COALESCE(i.quantity, 0) AS stock
         FROM sales AS s
         JOIN vegetables AS v ON v.id = s.vegetable_id
         LEFT JOIN inventory AS i ON i.vegetable_id = v.id
         WHERE s.order_code = ? AND s.buyer_id = ?
         GROUP BY s.vegetable_id, v.name, v.active, i.quantity
         ORDER BY v.name'
    );
    $orderItemsStatement->execute([$orderCode, $buyerId]);
    $orderItems = $orderItemsStatement->fetchAll();
    if (!$orderItems) {
        flash('error', 'This order has no produce items to reorder.');
        redirect(app_url('buyer/dashboard.php'));
    }

    try {
        $reorderPlan = merge_reorder_cart($orderItems, cart_contents());
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
        redirect(app_url('buyer/cart.php'));
    }
    if (count($reorderPlan['cart']) > 50) {
        flash('error', 'Your cart can contain up to 50 produce items. Remove some items before reordering.');
        redirect(app_url('buyer/cart.php'));
    }
    $_SESSION['cart'] = $reorderPlan['cart'];

    $message = $reorderPlan['added_items'] > 0
        ? 'Added ' . $reorderPlan['added_items'] . ' produce '
            . ($reorderPlan['added_items'] === 1 ? 'item' : 'items') . ' from order ' . $orderCode . '.'
        : 'No items from order ' . $orderCode . ' could be added.';
    foreach (array_slice($reorderPlan['adjustments'], 0, 4) as $adjustment) {
        if ($adjustment['reason'] === 'unavailable') {
            $message .= ' ' . $adjustment['name'] . ' is no longer available.';
        } elseif ($adjustment['reason'] === 'reduced_stock') {
            $message .= ' ' . $adjustment['name'] . ' quantity was reduced to '
                . number_format($adjustment['added'], 2) . ' due to current stock.';
        } else {
            $message .= ' ' . $adjustment['name'] . ' was not added because your cart already uses its available stock.';
        }
    }
    if (count($reorderPlan['adjustments']) > 4) {
        $message .= ' ' . (count($reorderPlan['adjustments']) - 4)
            . ' more items could not be fully reordered; review your cart.';
    }
    flash($reorderPlan['added_items'] > 0 ? 'success' : 'error', $message);
    redirect(app_url('buyer/cart.php'));
}

$summaryStatement = $db->prepare(
    'SELECT
        COUNT(*) AS order_count,
        COALESCE(SUM(CASE WHEN payment_status = \'paid\' THEN final_total ELSE 0 END), 0) AS total_spent,
        SUM(CASE
            WHEN (payment_status = \'pending_payment\' AND order_status <> \'cancelled\')
                OR (payment_status = \'failed\' AND order_status <> \'cancelled\')
                OR order_status NOT IN (\'delivered\', \'cancelled\')
            THEN 1 ELSE 0
        END) AS attention_orders
     FROM orders
     WHERE buyer_id = ?'
);
$summaryStatement->execute([$buyerId]);
$summary = $summaryStatement->fetch();

$vegetableCount = (int) $db
    ->query('SELECT COUNT(*) FROM vegetables WHERE active = 1')
    ->fetchColumn();

$ordersStatement = $db->prepare(
    'SELECT
        o.order_code,
        o.created_at AS order_date,
        o.final_total AS order_total,
        o.eligible_quantity AS item_count,
        o.payment_status,
        o.order_status
     FROM orders AS o
     WHERE o.buyer_id = ?
     ORDER BY o.created_at DESC
     LIMIT 5'
);
$ordersStatement->execute([$buyerId]);
$recentOrders = $ordersStatement->fetchAll();

$attentionStatement = $db->prepare(
    "SELECT order_code, payment_status, order_status, created_at
     FROM orders
     WHERE buyer_id = ?
        AND (
            (payment_status = 'pending_payment' AND order_status <> 'cancelled')
            OR (payment_status = 'failed' AND order_status <> 'cancelled')
            OR order_status NOT IN ('delivered', 'cancelled')
        )
     ORDER BY created_at DESC
     LIMIT 3"
);
$attentionStatement->execute([$buyerId]);
$attentionOrders = $attentionStatement->fetchAll();

$activeOrderStatement = $db->prepare(
    "SELECT order_code, order_status
     FROM orders
     WHERE buyer_id = ? AND order_status NOT IN ('delivered', 'cancelled')
     ORDER BY created_at DESC
     LIMIT 1"
);
$activeOrderStatement->execute([$buyerId]);
$activeOrder = $activeOrderStatement->fetch() ?: null;

$favoritesStatement = $db->prepare(
    'SELECT v.id, v.name, v.active, v.price, v.unit, COALESCE(i.quantity, 0) AS stock
     FROM buyer_favorites AS f
     JOIN vegetables AS v ON v.id = f.vegetable_id
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE f.buyer_id = ?
     ORDER BY v.active DESC, stock > 0 DESC, v.name
     LIMIT 4'
);
$favoritesStatement->execute([$buyerId]);
$favoriteItems = $favoritesStatement->fetchAll();

$cart = cart_contents();
$cartPreview = [];
$cartSubtotal = 0.0;
$cartNeedsReview = count($cart) > 50;
$cartIds = [];
foreach (array_keys($cart) as $cartId) {
    $validCartId = filter_var($cartId, FILTER_VALIDATE_INT);
    if ($validCartId && $validCartId > 0) {
        $cartIds[] = (int) $validCartId;
    } else {
        $cartNeedsReview = true;
    }
}
$cartIds = array_values(array_unique($cartIds));
if ($cartIds) {
    $cartPlaceholders = implode(',', array_fill(0, count($cartIds), '?'));
    $cartItemsStatement = $db->prepare(
        "SELECT v.id, v.name, v.active, v.price, v.unit, COALESCE(i.quantity, 0) AS stock
         FROM vegetables AS v
         LEFT JOIN inventory AS i ON i.vegetable_id = v.id
         WHERE v.id IN ({$cartPlaceholders})"
    );
    $cartItemsStatement->execute($cartIds);
    foreach ($cartItemsStatement->fetchAll() as $cartItem) {
        $quantity = filter_var($cart[$cartItem['id']] ?? null, FILTER_VALIDATE_FLOAT);
        if (
            $quantity === false
            || !is_finite((float) $quantity)
            || $quantity <= 0
        ) {
            $cartNeedsReview = true;
            continue;
        }

        $cartItem['cart_quantity'] = (float) $quantity;
        $cartPreview[] = $cartItem;
        $cartSubtotal += (float) $cartItem['price'] * (float) $quantity;
        if (!$cartItem['active'] || (float) $quantity > (float) $cartItem['stock']) {
            $cartNeedsReview = true;
        }
    }
    if (count($cartPreview) !== count($cartIds)) {
        $cartNeedsReview = true;
    }
}
$cartSubtotal = round($cartSubtotal, 2);

$pageTitle = 'Buyer dashboard';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Your Harvest account</p>
        <h1>Welcome, <?= h(current_user()['name']) ?></h1>
        <p>Keep track of your orders and find fresh produce from the farm.</p>
    </div>
    <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Browse the shop</a>
</section>

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

<section class="module-stat-grid" aria-label="Your account summary">
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">▤</span>
        <span>Your orders</span>
        <strong><?= number_format((int) $summary['order_count']) ?></strong>
        <small>Orders placed with Harvest</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">₱</span>
        <span>Paid purchases</span>
        <strong><?= money($summary['total_spent']) ?></strong>
        <small>Total of paid order items</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">◉</span>
        <span>Produce available</span>
        <strong><?= number_format($vegetableCount) ?></strong>
        <small>Active vegetables in the shop</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">↗</span>
        <span>Orders needing attention</span>
        <strong><?= number_format((int) $summary['attention_orders']) ?></strong>
        <small>Awaiting payment or delivery updates</small>
    </article>
</section>

<?php if ($attentionOrders): ?>
    <section class="panel section buyer-attention-panel" aria-labelledby="buyer-attention-title">
        <div class="module-table-heading">
            <div>
                <h2 id="buyer-attention-title">Orders to follow up</h2>
                <p class="muted">Check payment or delivery progress for these orders.</p>
            </div>
            <a href="<?= h(app_url('buyer/orders.php')) ?>">All orders</a>
        </div>
        <ul class="buyer-attention-list">
            <?php foreach ($attentionOrders as $order): ?>
                <li>
                    <span>
                        <strong><?= h($order['order_code']) ?></strong>
                        <small>
                            <span data-payment-status-for="<?= h($order['order_code']) ?>">
                                <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?>
                            </span>
                            · <span data-order-status-for="<?= h($order['order_code']) ?>">
                                <?= h(ucwords(str_replace('_', ' ', $order['order_status']))) ?>
                            </span>
                        </small>
                    </span>
                    <a class="button secondary" href="<?= h(app_url(
                        'buyer/order_details.php?order=' . rawurlencode($order['order_code'])
                    )) ?>">View order</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="panel section buyer-cart-summary" aria-labelledby="buyer-cart-title">
    <div class="module-table-heading">
        <div>
            <h2 id="buyer-cart-title">Your cart</h2>
            <p class="muted">
                <?= number_format(count($cart)) ?> <?= count($cart) === 1 ? 'produce item' : 'produce items' ?>
                · Estimated item subtotal <?= money($cartSubtotal) ?>
            </p>
        </div>
        <div class="buyer-cart-actions">
            <a class="button secondary" href="<?= h(app_url('buyer/cart.php')) ?>">Edit cart</a>
            <?php if ($cart && !$cartNeedsReview): ?>
                <a class="button" href="<?= h(app_url('buyer/checkout.php')) ?>">Checkout</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$cart): ?>
        <p class="buyer-cart-empty">Your cart is empty. Browse fresh produce to start an order.</p>
    <?php else: ?>
        <?php if ($cartNeedsReview): ?>
            <p class="notice error" role="alert">
                <?php if (count($cart) > 50): ?>
                    Your cart has more than 50 produce items. Remove some items before checkout.
                <?php else: ?>
                    Some cart quantities exceed current stock or include unavailable items. Review your cart before checkout.
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <ul class="buyer-cart-preview">
            <?php foreach (array_slice($cartPreview, 0, 4) as $item): ?>
                <li>
                    <span><?= h($item['name']) ?> · <?= number_format($item['cart_quantity'], 2) ?> <?= h($item['unit']) ?></span>
                    <?php if (!$item['active'] || $item['cart_quantity'] > (float) $item['stock']): ?>
                        <strong class="buyer-cart-warning">Stock changed</strong>
                    <?php else: ?>
                        <span><?= money((float) $item['price'] * $item['cart_quantity']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (count($cart) > 4): ?>
                <li><a href="<?= h(app_url('buyer/cart.php')) ?>">View all <?= number_format(count($cart)) ?> items</a></li>
            <?php endif; ?>
        </ul>
        <p class="muted buyer-cart-note">Estimate excludes delivery and any checkout discount.</p>
    <?php endif; ?>
</section>

<section class="panel section buyer-favorites-panel" aria-labelledby="buyer-favorites-title">
    <div class="module-table-heading">
        <div>
            <h2 id="buyer-favorites-title">Your favorites</h2>
            <p class="muted">Saved produce, ready when you are.</p>
        </div>
        <a href="<?= h(app_url('buyer/favorites.php')) ?>">Manage favorites</a>
    </div>
    <?php if ($favoriteItems): ?>
        <ul class="buyer-favorite-list">
            <?php foreach ($favoriteItems as $favorite): ?>
                <li>
                    <span>
                        <strong><?= h($favorite['name']) ?></strong>
                        <?php if (!$favorite['active'] || (float) $favorite['stock'] <= 0): ?>
                            <small class="buyer-cart-warning">
                                <?= $favorite['active'] ? 'Currently out of stock' : 'No longer available' ?>
                            </small>
                        <?php else: ?>
                            <small><?= money($favorite['price']) ?> / <?= h($favorite['unit']) ?></small>
                        <?php endif; ?>
                    </span>
                    <?php
                    $favoriteCartQuantity = is_numeric($cart[$favorite['id']] ?? null)
                        ? (float) $cart[$favorite['id']]
                        : 0.0;
                    $favoriteRemainingStock = max(
                        0,
                        round((float) $favorite['stock'] - $favoriteCartQuantity, 2)
                    );
                    ?>
                    <?php if ($favorite['active'] && $favoriteRemainingStock > 0): ?>
                        <form method="post" action="<?= h(app_url('buyer/cart.php')) ?>" data-cart-add>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="return_to" value="dashboard">
                            <input type="hidden" name="product_id" value="<?= (int) $favorite['id'] ?>">
                            <input
                                type="hidden"
                                name="quantity"
                                value="<?= h((string) min(1.0, $favoriteRemainingStock)) ?>"
                            >
                            <button class="button secondary" type="submit">Add 1</button>
                        </form>
                    <?php elseif ($favorite['active'] && (float) $favorite['stock'] > 0): ?>
                        <a class="button secondary" href="<?= h(app_url('buyer/cart.php')) ?>">In cart</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="buyer-cart-empty">Save produce in the shop to find it here next time.</p>
    <?php endif; ?>
</section>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Recent orders</h2>
            <p class="muted">Your latest purchases.</p>
        </div>
        <a href="<?= h(app_url('buyer/orders.php')) ?>">View all orders</a>
    </div>

    <?php if (!$recentOrders): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">▤</span>
            <h3>No orders yet</h3>
            <p>Your purchases will appear here after you place your first order.</p>
            <?php if ($vegetableCount > 0): ?>
                <a class="button" href="<?= h(app_url('buyer/index.php')) ?>">Browse fresh produce</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong><?= h($order['order_code']) ?></strong></td>
                            <td><?= h(date('M j, Y', strtotime($order['order_date']))) ?></td>
                            <td>
                                <?= number_format((float) $order['item_count'], 2) ?> units
                            </td>
                            <td><strong><?= money($order['order_total']) ?></strong></td>
                            <td class="buyer-order-status">
                                <span class="status-badge status-<?= h($order['payment_status']) ?>"
                                      data-payment-status-for="<?= h($order['order_code']) ?>">
                                    <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?>
                                </span>
                                <span class="status-badge status-<?= h($order['order_status']) ?>"
                                      data-order-status-for="<?= h($order['order_code']) ?>">
                                    <?= h(ucwords(str_replace('_', ' ', $order['order_status']))) ?>
                                </span>
                            </td>
                            <td class="buyer-order-actions">
                                <a class="button secondary" href="<?= h(app_url(
                                    'buyer/order_details.php?order=' . rawurlencode($order['order_code'])
                                )) ?>">View order</a>
                                <?php if (in_array($order['order_status'], ['delivered', 'cancelled'], true)): ?>
                                    <form method="post" action="<?= h(app_url('buyer/dashboard.php')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reorder">
                                        <input type="hidden" name="order_code" value="<?= h($order['order_code']) ?>">
                                        <button type="submit">Buy again</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
