<?php

require_once __DIR__ . '/inventory.php';

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

function checkout_stock_issues(array $cartItems, array $products): array
{
    $productsById = [];
    foreach ($products as $product) {
        $productsById[(int) ($product['id'] ?? 0)] = $product;
    }

    $issues = [];
    foreach ($cartItems as $productId => $requestedQuantity) {
        $productId = (int) $productId;
        $product = $productsById[$productId] ?? null;
        $availableStock = $product
            ? (float) max(0, (float) ($product['stock'] ?? 0))
            : 0.0;

        if (!$product || empty($product['active'])) {
            $issues[] = [
                'name' => $product && is_string($product['name'] ?? null)
                    ? $product['name']
                    : 'Unavailable produce',
                'unit' => $product && is_string($product['unit'] ?? null)
                    ? $product['unit']
                    : '',
                'requested' => (float) $requestedQuantity,
                'available' => $availableStock,
                'reason' => 'unavailable',
            ];
        } elseif ((float) $requestedQuantity > $availableStock) {
            $issues[] = [
                'name' => is_string($product['name'] ?? null)
                    ? $product['name']
                    : 'Produce item',
                'unit' => is_string($product['unit'] ?? null)
                    ? $product['unit']
                    : '',
                'requested' => (float) $requestedQuantity,
                'available' => $availableStock,
                'reason' => 'insufficient_stock',
            ];
        }
    }

    return $issues;
}

function reserve_inventory_stock(
    MySQLiDatabase $db,
    int $vegetableId,
    float $quantity,
    ?string $orderCode = null
): void
{
    if ($vegetableId < 1 || !is_finite($quantity) || $quantity <= 0) {
        throw new RuntimeException('An inventory reservation quantity is invalid.');
    }

    $currentStockStatement = $db->prepare(
        'SELECT quantity FROM inventory WHERE vegetable_id = ? FOR UPDATE'
    );
    $currentStockStatement->execute([$vegetableId]);
    $quantityBefore = $currentStockStatement->fetchColumn();
    if ($quantityBefore === false) {
        throw new RuntimeException('Stock changed during checkout. Please try again.');
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

    $quantityBefore = (float) $quantityBefore;
    record_inventory_movement(
        $db,
        $vegetableId,
        $quantityBefore,
        round($quantityBefore - $quantity, 2),
        'order_reserved',
        $orderCode
            ? 'Stock reserved for order ' . $orderCode . '.'
            : 'Stock reserved for a customer order.',
        $orderCode
    );
}

function merge_reorder_cart(array $orderItems, array $cart): array
{
    $addedItems = 0;
    $adjustments = [];

    foreach ($orderItems as $item) {
        $vegetableId = filter_var($item['vegetable_id'] ?? null, FILTER_VALIDATE_INT);
        $requestedQuantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($item['stock'] ?? null, FILTER_VALIDATE_FLOAT);
        $name = is_string($item['name'] ?? null) ? $item['name'] : 'Produce item';
        $active = !empty($item['active']);

        if (
            !$vegetableId
            || $vegetableId < 1
            || $requestedQuantity === false
            || !is_finite((float) $requestedQuantity)
            || $requestedQuantity <= 0
            || $stock === false
            || !is_finite((float) $stock)
            || $stock < 0
        ) {
            throw new RuntimeException('An item in this past order has invalid quantity or stock data.');
        }

        if (!$active || $stock <= 0) {
            $adjustments[] = [
                'name' => $name,
                'requested' => (float) $requestedQuantity,
                'added' => 0.0,
                'reason' => 'unavailable',
            ];
            continue;
        }

        $existingQuantity = 0.0;
        if (array_key_exists($vegetableId, $cart)) {
            $existingValue = filter_var($cart[$vegetableId], FILTER_VALIDATE_FLOAT);
            if (
                $existingValue === false
                || !is_finite((float) $existingValue)
                || $existingValue < 0
            ) {
                throw new RuntimeException('Your current cart has an invalid quantity. Review it before reordering.');
            }
            $existingQuantity = (float) $existingValue;
        }

        $remainingStock = max(0, round((float) $stock - $existingQuantity, 2));
        $quantityToAdd = min((float) $requestedQuantity, $remainingStock);
        if ($quantityToAdd > 0) {
            $cart[$vegetableId] = round($existingQuantity + $quantityToAdd, 2);
            $addedItems++;
        }

        if ($quantityToAdd < (float) $requestedQuantity) {
            $adjustments[] = [
                'name' => $name,
                'requested' => (float) $requestedQuantity,
                'added' => $quantityToAdd,
                'reason' => $remainingStock <= 0 ? 'cart_limit' : 'reduced_stock',
            ];
        }
    }

    return [
        'cart' => $cart,
        'added_items' => $addedItems,
        'adjustments' => $adjustments,
    ];
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
