<?php
require_once __DIR__ . '/../includes/orders.php';

function expect_reorder(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$plan = merge_reorder_cart(
    [
        ['vegetable_id' => 1, 'name' => 'Tomato', 'quantity' => 4, 'stock' => 5, 'active' => 1],
        ['vegetable_id' => 2, 'name' => 'Lettuce', 'quantity' => 2, 'stock' => 1, 'active' => 1],
        ['vegetable_id' => 3, 'name' => 'Spinach', 'quantity' => 1, 'stock' => 0, 'active' => 1],
        ['vegetable_id' => 4, 'name' => 'Kale', 'quantity' => 1, 'stock' => 10, 'active' => 0],
    ],
    [1 => 2.5]
);

expect_reorder($plan['cart'][1] === 5.0, 'Reorder should add only the remaining available stock.');
expect_reorder($plan['cart'][2] === 1.0, 'Reorder should cap an item at current stock.');
expect_reorder(!isset($plan['cart'][3]) && !isset($plan['cart'][4]), 'Unavailable produce must not be added.');
expect_reorder($plan['added_items'] === 2, 'Reorder should count only items added to the cart.');
expect_reorder(count($plan['adjustments']) === 4, 'Reorder should report stock reductions and unavailable produce.');
expect_reorder($plan['adjustments'][0]['reason'] === 'reduced_stock', 'Reduced stock should be identified.');
expect_reorder($plan['adjustments'][1]['reason'] === 'reduced_stock', 'Stock-limited produce should be identified.');
expect_reorder($plan['adjustments'][2]['reason'] === 'unavailable', 'Out-of-stock produce should be identified.');
expect_reorder($plan['adjustments'][3]['reason'] === 'unavailable', 'Inactive produce should be identified.');

$fullCartPlan = merge_reorder_cart(
    [['vegetable_id' => 2, 'name' => 'Lettuce', 'quantity' => 1, 'stock' => 1, 'active' => 1]],
    [2 => 1.0]
);
expect_reorder($fullCartPlan['added_items'] === 0, 'Reorder must not overfill existing cart stock.');
expect_reorder($fullCartPlan['adjustments'][0]['reason'] === 'cart_limit', 'Existing cart stock should be reported.');

echo "Cart reorder quantity tests passed.\n";