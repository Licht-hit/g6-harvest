<?php

const BULK_DISCOUNT_MINIMUM_QUANTITY = 20.0;
const BULK_DISCOUNT_PERCENTAGE = 10.0;
const ORDER_DELIVERY_FEE = 20.00;

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
