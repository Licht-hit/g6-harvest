<?php

const BULK_DISCOUNT_MINIMUM_QUANTITY = 20.0;
const BULK_DISCOUNT_PERCENTAGE = 10.0;
const ORDER_DELIVERY_FEE = 20.00;

function checkout_token_hash(string $token): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('This checkout form has expired. Reload the page and try again.');
    }

    return hash('sha256', $token);
}

function checkout_request_fingerprint(
    array $cartItems,
    string $deliveryAddress,
    string $paymentMethod
): string {
    ksort($cartItems, SORT_NUMERIC);
    $items = [];
    foreach ($cartItems as $productId => $quantity) {
        $items[] = [(int) $productId, (float) $quantity];
    }

    return hash('sha256', json_encode([
        'items' => $items,
        'delivery_address' => $deliveryAddress,
        'payment_method' => $paymentMethod,
    ], JSON_THROW_ON_ERROR));
}

function reserve_inventory_stock(MySQLiDatabase $db, int $vegetableId, float $quantity): void
{
    if ($vegetableId < 1 || !is_finite($quantity) || $quantity <= 0) {
        throw new RuntimeException('An inventory reservation quantity is invalid.');
    }

    $statement = $db->prepare(
        'UPDATE inventory
         SET quantity = quantity - ?
         WHERE vegetable_id = ? AND quantity >= ?'
    );
    $statement->execute([$quantity, $vegetableId, $quantity]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Stock changed during checkout. Please try again.');
    }
}

function redirect_to_existing_checkout(array $order, ?string $requestFingerprint = null): void
{
    if (
        $requestFingerprint !== null
        && (
            !is_string($order['checkout_request_hash'] ?? null)
            || !hash_equals($order['checkout_request_hash'], $requestFingerprint)
        )
    ) {
        flash('error', 'This checkout form was already used for a different order. Reload checkout to continue.');
        redirect(app_url('buyer/checkout.php'));
    }

    $checkoutUrl = $order['gateway_checkout_url'] ?? '';
    $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : false;
    $checkoutScheme = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_SCHEME) : false;
    if (
        $order['payment_method'] === 'online'
        && $order['payment_status'] === 'pending_payment'
        && $checkoutScheme === 'https'
        && $checkoutHost === 'checkout.paymongo.com'
    ) {
        redirect($checkoutUrl);
    }

    redirect(app_url(
        'buyer/order_details.php?order=' . rawurlencode($order['order_code'])
    ));
}

function calculate_order_totals(array $items): array
{
    $subtotal = 0.0;
    $eligibleQuantity = 0.0;

    foreach ($items as $item) {
        $quantity = (float) ($item['quantity'] ?? 0);
        $price = (float) ($item['price'] ?? 0);

        if (!is_finite($quantity) || !is_finite($price) || $quantity <= 0 || $price < 0) {
            throw new RuntimeException('One or more order quantities or prices are invalid.');
        }

        $eligibleQuantity += $quantity;
        $subtotal += round($price * $quantity, 2);
    }

    $subtotal = round($subtotal, 2);
    $discountPercentage = $eligibleQuantity >= BULK_DISCOUNT_MINIMUM_QUANTITY
        ? BULK_DISCOUNT_PERCENTAGE
        : 0.0;
    $discountAmount = round($subtotal * $discountPercentage / 100, 2);
    $discountedSubtotal = max(0, round($subtotal - $discountAmount, 2));
    $finalTotal = round($discountedSubtotal + ORDER_DELIVERY_FEE, 2);

    return [
        'subtotal' => $subtotal,
        'eligible_quantity' => round($eligibleQuantity, 2),
        'discount_percentage' => $discountPercentage,
        'discount_amount' => $discountAmount,
        'delivery_fee' => ORDER_DELIVERY_FEE,
        'final_total' => $finalTotal,
    ];
}
