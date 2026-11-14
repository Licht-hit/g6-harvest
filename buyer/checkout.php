<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/notifications.php';
require_role('buyer');

$cart = cart_contents();
$cartItems = [];
$deliveryAddress = null;
$cartIsValid = count($cart) <= 50;
foreach ($cart as $productId => $quantity) {
    $validId = filter_var($productId, FILTER_VALIDATE_INT);
    $quantityIsScalar = is_string($quantity) || is_int($quantity) || is_float($quantity);
    $validQuantity = $quantityIsScalar
        ? filter_var($quantity, FILTER_VALIDATE_FLOAT)
        : false;
    if (
        !$validId
        || $validId < 1
        || $validQuantity === false
        || !is_finite((float) $validQuantity)
        || $validQuantity <= 0
        || $validQuantity > 99999999.99
    ) {
        $cartIsValid = false;
        continue;
    }
    $cartItems[(int) $validId] = (float) $validQuantity;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && is_string($_POST['checkout_token'] ?? null)
    && preg_match('/^[a-f0-9]{64}$/', $_POST['checkout_token'])
) {
    verify_csrf();
    try {
        $deliveryAddress = checkout_delivery_address($_POST);
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
        redirect(app_url('buyer/checkout.php'));
    }

    $replayedTokenHash = checkout_token_hash($_POST['checkout_token']);
    $replayedOrderStatement = $db->prepare(
        'SELECT order_code, payment_method, payment_status, checkout_request_hash, gateway_checkout_url, gateway_provider
         FROM orders
         WHERE buyer_id = ? AND checkout_token_hash = ?'
    );
    $replayedOrderStatement->execute([
        (int) current_user()['id'],
        $replayedTokenHash,
    ]);
    $replayedOrder = $replayedOrderStatement->fetch();
    if ($replayedOrder) {
        $replayedRequestFingerprint = null;
        if ($cartItems && is_string($_POST['payment_method'] ?? null)) {
            $replayedRequestFingerprint = checkout_request_fingerprint(
                $cartItems,
                $deliveryAddress,
                $_POST['payment_method']
            );
        }
        redirect_to_existing_checkout($replayedOrder, $replayedRequestFingerprint);
    }
}

if (!$cart) {
    flash('error', 'Your cart is empty.');
    redirect(app_url('buyer/cart.php'));
}

if (!$cartItems) {
    flash('error', 'Your cart has invalid quantities. Please update your cart and try again.');
    redirect(app_url('buyer/cart.php'));
}

$ids = array_keys($cartItems);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$productQuery = "SELECT
        v.id,
        v.name,
        v.unit,
        v.price,
        v.active,
        COALESCE(i.quantity, 0) AS stock
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     WHERE v.id IN ({$placeholders})
     ORDER BY v.id";

$checkoutToken = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submittedCheckoutToken = $_POST['checkout_token'] ?? '';

    if (!is_string($submittedCheckoutToken) || !preg_match('/^[a-f0-9]{64}$/', $submittedCheckoutToken)) {
        flash('error', 'This checkout form has expired. Reload the page and try again.');
        redirect(app_url('buyer/checkout.php'));
    }

    if ($deliveryAddress === null) {
        try {
            $deliveryAddress = checkout_delivery_address($_POST);
        } catch (RuntimeException $exception) {
            flash('error', $exception->getMessage());
            redirect(app_url('buyer/checkout.php'));
        }
    }
    $paymentMethod = $_POST['payment_method'] ?? '';
    if (!in_array($paymentMethod, ['cod', 'online'], true)) {
        flash('error', 'Choose Cash on Delivery or Online Payment.');
        redirect(app_url('buyer/checkout.php'));
    }
    if ($paymentMethod === 'online' && !xendit_is_configured()) {
        flash('error', xendit_unavailable_message());
        redirect(app_url('buyer/checkout.php'));
    }
    if (!$cartIsValid || count($ids) !== count($cart)) {
        flash('error', 'Your cart contains invalid products or quantities. Please review it.');
        redirect(app_url('buyer/cart.php'));
    }

    $checkoutTokenHash = checkout_token_hash($submittedCheckoutToken);
    $requestFingerprint = checkout_request_fingerprint(
        $cartItems,
        $deliveryAddress,
        $paymentMethod
    );
    $buyerId = (int) current_user()['id'];
    $loadExistingOrder = static function () use ($db, $buyerId, $checkoutTokenHash): ?array {
        $statement = $db->prepare(
            'SELECT order_code, payment_method, payment_status, checkout_request_hash, gateway_checkout_url, gateway_provider
             FROM orders
             WHERE buyer_id = ? AND checkout_token_hash = ?'
        );
        $statement->execute([$buyerId, $checkoutTokenHash]);

        return $statement->fetch() ?: null;
    };

    $existingOrder = $loadExistingOrder();
    if ($existingOrder) {
        redirect_to_existing_checkout($existingOrder, $requestFingerprint);
    }

    try {
        // This is the main checkout flow. It is kept simple and easy to read.
        $db->beginTransaction();

        $lockedProducts = $db->prepare($productQuery . ' FOR UPDATE');
        $lockedProducts->execute($ids);
        $lockedItems = $lockedProducts->fetchAll();

        $stockIssues = checkout_stock_issues($cartItems, $lockedItems);
        if ($stockIssues) {
            throw new RuntimeException(
                'Stock changed for one or more cart items. Review the availability details and update your cart.'
            );
        }

        $productsById = [];
        foreach ($lockedItems as $item) {
            $productsById[(int) $item['id']] = $item;
        }
        $calculationItems = [];
        foreach ($cartItems as $productId => $quantity) {
            $calculationItems[] = [
                'quantity' => $quantity,
                'price' => (float) $productsById[$productId]['price'],
            ];
        }
        $totals = calculate_order_totals($calculationItems);
        if ($totals['final_total'] <= 0) {
            throw new RuntimeException('The order total is invalid. Review your cart and try again.');
        }

        $orderCode = 'VS-' . strtoupper(bin2hex(random_bytes(5)));
        $insertOrder = $db->prepare(
            "INSERT INTO orders (
                order_code,
                buyer_id,
                checkout_token_hash,
                checkout_request_hash,
                subtotal,
                eligible_quantity,
                discount_percentage,
                discount_amount,
                delivery_fee,
                final_total,
                delivery_address,
                payment_method,
                gateway_provider,
                payment_status,
                order_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', 'confirmed')"
        );
        $insertOrder->execute([
            $orderCode,
            $buyerId,
            $checkoutTokenHash,
            $requestFingerprint,
            $totals['subtotal'],
            $totals['eligible_quantity'],
            $totals['discount_percentage'],
            $totals['discount_amount'],
            $totals['delivery_fee'],
            $totals['final_total'],
            $deliveryAddress,
            $paymentMethod,
            $paymentMethod === 'online' ? 'xendit' : null,
        ]);
        $orderId = (int) $db->lastInsertId();

        $insertSale = $db->prepare(
            "INSERT INTO sales (
                order_code,
                buyer_id,
                vegetable_id,
                quantity,
                unit_price,
                total,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        foreach ($cartItems as $productId => $quantity) {
            $product = $productsById[$productId];
            $lineTotal = round((float) $product['price'] * $quantity, 2);
            $insertSale->execute([
                $orderCode,
                $buyerId,
                $productId,
                $quantity,
                $product['price'],
                $lineTotal,
            ]);
            reserve_inventory_stock($db, $productId, $quantity, $orderCode);
        }
        create_notifications_for_roles(
            $db,
            ['admin', 'staff', 'farmer'],
            'new_order',
            'New order ' . $orderCode . ' has been placed.',
            'modules/sales.php',
            $orderCode
        );

        $paymentHistory = $db->prepare(
            'INSERT INTO payment_history (
                order_code,
                payment_method,
                event_type,
                payment_status,
                amount,
                details
            ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $paymentHistory->execute([
            $orderCode,
            $paymentMethod,
            'order_created',
            'pending_payment',
            $totals['final_total'],
            $paymentMethod === 'cod'
                ? 'Cash will be collected at delivery.'
                : 'Waiting for payment through Xendit hosted checkout.',
        ]);

        $checkoutUrl = null;
        if ($paymentMethod === 'online') {
            [$gatewaySessionId, $checkoutUrl] = xendit_checkout_url(
                $orderCode,
                $totals['final_total']
            );
            $saveGatewaySession = $db->prepare(
                'UPDATE orders
                 SET gateway_session_id = ?, gateway_checkout_url = ?
                 WHERE order_code = ?'
            );
            $saveGatewaySession->execute([
                $gatewaySessionId,
                $checkoutUrl,
                $orderCode,
            ]);
            $paymentHistory->execute([
                $orderCode,
                'online',
                'checkout_created',
                'pending_payment',
                $totals['final_total'],
                'Xendit session: ' . $gatewaySessionId,
            ]);
        }

        log_activity('order_placed', 'order', $orderId, 'Order ' . $orderCode . ' placed.');
        $db->commit();
        unset($_SESSION['cart']);

        if ($paymentMethod === 'online') {
            redirect($checkoutUrl);
        }

        flash('success', 'Your order is confirmed. Pay cash when it is delivered.');
        redirect(app_url('buyer/order_details.php?order=' . rawurlencode($orderCode)));
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            $existingOrder = $loadExistingOrder();
            if ($existingOrder) {
                redirect_to_existing_checkout($existingOrder, $requestFingerprint);
            }
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            redirect(app_url('buyer/checkout.php'));
        }
        throw $exception;
    }
}

$statement = $db->prepare($productQuery);
$statement->execute($ids);
$items = $statement->fetchAll();
$byId = [];
foreach ($items as $item) {
    $byId[(int) $item['id']] = $item;
}

$stockIssues = checkout_stock_issues($cartItems, $items);
$previewItems = [];
foreach ($cartItems as $productId => $quantity) {
    if (
        !isset($byId[$productId])
        || empty($byId[$productId]['active'])
        || $quantity > (float) $byId[$productId]['stock']
    ) {
        $cartIsValid = false;
        continue;
    }
    $previewItems[] = [
        'quantity' => $quantity,
        'price' => (float) $byId[$productId]['price'],
    ];
}
$cartIsValid = $cartIsValid && count($byId) === count($cartItems);
$totals = $previewItems ? calculate_order_totals($previewItems) : null;
$onlinePaymentAvailable = xendit_is_configured();

$pageTitle = 'Checkout';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Checkout</h1>
    <a href="<?= h(app_url('buyer/cart.php')) ?>">Edit cart</a>
</div>

<section class="panel section checkout-panel">
    <?php if (!$cartIsValid || !$totals): ?>
        <div class="notice error" role="alert">
            Some items are unavailable or exceed current stock. Update your cart before checkout.
        </div>
        <?php if ($stockIssues): ?>
            <ul class="checkout-stock-issues">
                <?php foreach ($stockIssues as $issue): ?>
                    <li>
                        <strong><?= h($issue['name']) ?>:</strong>
                        <?php if ($issue['reason'] === 'unavailable'): ?>
                            no longer available.
                        <?php else: ?>
                            <?= number_format($issue['available'], 2) ?>
                            <?= h($issue['unit']) ?> available; your cart requests
                            <?= number_format($issue['requested'], 2) ?>
                            <?= h($issue['unit']) ?>.
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="button" href="<?= h(app_url('buyer/cart.php')) ?>">Review cart</a>
    <?php else: ?>
        <div class="checkout-layout">
            <div class="checkout-summary">
                <h2>Order summary</h2>
                <div
                    class="table-wrap checkout-table-wrap"
                    role="region"
                    aria-label="Order items; scroll horizontally to view all columns"
                    tabindex="0"
                >
                    <table class="checkout-table">
                        <thead>
                        <tr>
                            <th>Produce</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Subtotal</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cartItems as $productId => $quantity): ?>
                            <?php $item = $byId[$productId]; ?>
                            <tr>
                                <td><?= h($item['name']) ?></td>
                                <td><?= number_format($quantity, 2) ?> <?= h($item['unit']) ?></td>
                                <td><?= money($item['price']) ?></td>
                                <td><?= money((float) $item['price'] * $quantity) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <dl class="checkout-totals">
                    <div><dt>Vegetable subtotal</dt><dd><?= money($totals['subtotal']) ?></dd></div>
                    <div>
                        <dt>
                            Bulk purchase discount
                            (<?= number_format($totals['discount_percentage'], 0) ?>%)
                        </dt>
                        <dd>-<?= money($totals['discount_amount']) ?></dd>
                    </div>
                    <div><dt>Delivery fee</dt><dd><?= money($totals['delivery_fee']) ?></dd></div>
                    <div class="checkout-final-total">
                        <dt>Final total</dt><dd><?= money($totals['final_total']) ?></dd>
                    </div>
                </dl>
                <p class="muted checkout-quantity">
                    Combined cart quantity:
                    <?= number_format($totals['eligible_quantity'], 2) ?> units
                </p>
                <?php
                $discountProgress = min(
                    BULK_DISCOUNT_MINIMUM_QUANTITY,
                    $totals['eligible_quantity']
                );
                $discountRemaining = max(
                    0,
                    BULK_DISCOUNT_MINIMUM_QUANTITY - $totals['eligible_quantity']
                );
                ?>
                <div class="checkout-discount-progress">
                    <label for="discount-progress">
                        <?php if ($discountRemaining > 0): ?>
                            Add <?= number_format($discountRemaining, 2) ?> more combined units to qualify for <?= number_format(BULK_DISCOUNT_PERCENTAGE, 0) ?>% off.
                        <?php else: ?>
                            Bulk discount unlocked: <?= number_format(BULK_DISCOUNT_PERCENTAGE, 0) ?>% off your produce subtotal.
                        <?php endif; ?>
                    </label>
                    <progress
                        id="discount-progress"
                        max="<?= h((string) BULK_DISCOUNT_MINIMUM_QUANTITY) ?>"
                        value="<?= h((string) $discountProgress) ?>"
                    ><?= h((string) $discountProgress) ?> / <?= h((string) BULK_DISCOUNT_MINIMUM_QUANTITY) ?></progress>
                    <small>
                        Quantity is combined across all produce, even when item units differ. The discount applies to produce subtotal only; delivery is not discounted.
                    </small>
                </div>
                <?php if ($totals['discount_percentage'] > 0): ?>
                    <p class="notice success checkout-discount-notice">
                        Congratulations! You qualify for a 10% bulk purchase discount!
                    </p>
                <?php else: ?>
                    <p class="notice checkout-discount-notice">
                        Purchase 20 or more units/kg to receive 10% OFF!
                    </p>
                <?php endif; ?>
            </div>

            <form class="checkout-form" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="checkout_token" value="<?= h($checkoutToken) ?>">
                <h2>Delivery details</h2>
                <div class="checkout-delivery-fields">
                    <div class="field">
                        <label for="recipient-name">Recipient name</label>
                        <input id="recipient-name" name="recipient_name" type="text"
                            autocomplete="name" required minlength="2" maxlength="100">
                    </div>
                    <div class="field">
                        <label for="contact-number">Contact number</label>
                        <input id="contact-number" name="contact_number" type="tel"
                            inputmode="tel" autocomplete="tel" required minlength="7" maxlength="20"
                            pattern="(?=.*[0-9])[+0-9() .-]{7,20}">
                    </div>
                    <div class="field checkout-delivery-field-wide">
                        <label for="street-address">House number and street</label>
                        <input id="street-address" name="street_address" type="text"
                            autocomplete="address-line1" required minlength="3" maxlength="200"
                            placeholder="Enter the house number and street.">
                    </div>
                    <div class="field">
                        <label for="delivery-barangay">Barangay</label>
                        <input id="delivery-barangay" name="barangay" type="text"
                            required minlength="2" maxlength="100">
                    </div>
                    <div class="field">
                        <label for="delivery-city">City / Municipality</label>
                        <input id="delivery-city" name="city_municipality" type="text"
                            autocomplete="address-level2" required minlength="2" maxlength="100">
                    </div>
                    <div class="field checkout-delivery-field-wide">
                        <label for="delivery-instructions">Delivery instructions</label>
                        <textarea id="delivery-instructions" name="delivery_instructions"
                            maxlength="300"
                            placeholder="Optional: landmark or directions for the rider."></textarea>
                    </div>
                </div>
                <p class="muted checkout-delivery-note">
                    Flat ₱20 delivery fee per order. The order summary shows the fee and final total.
                </p>

                <fieldset class="payment-method-options">
                    <legend>Payment method</legend>
                    <label>
                        <input type="radio" name="payment_method" value="cod" required checked>
                        Cash on Delivery
                        <span class="muted">Pay when your order arrives.</span>
                    </label>
                    <?php if ($onlinePaymentAvailable): ?>
                        <label>
                            <input type="radio" name="payment_method" value="online" required>
                            Online Payment
                            <span class="muted">Pay securely through Xendit using an available payment method.</span>
                        </label>
                    <?php else: ?>
                        <p class="notice">
                            Online payment is unavailable until Xendit credentials, a public HTTPS
                            APP_URL, PHP OpenSSL, and allow_url_fopen are configured.
                        </p>
                    <?php endif; ?>
                </fieldset>

                <button type="submit">Confirm order</button>
            </form>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
