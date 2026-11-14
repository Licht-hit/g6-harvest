<?php
require_once __DIR__ . '/../includes/fifo.php';

$sorted = sort_fifo_batches([
    ['id' => 3, 'source_type' => 'harvest', 'received_on' => '2025-02-01'],
    ['id' => 2, 'source_type' => 'opening_balance', 'received_on' => '2026-01-01'],
    ['id' => 1, 'source_type' => 'harvest', 'received_on' => '2025-01-01'],
]);
if (array_column($sorted, 'id') !== [2, 1, 3]) {
    throw new RuntimeException('FIFO batches were not sorted by opening balance and receipt date.');
}

$allocations = plan_fifo_allocation([
    ['id' => 7, 'source_type' => 'harvest', 'received_on' => '2025-02-01', 'quantity_remaining' => 3],
    ['id' => 4, 'source_type' => 'harvest', 'received_on' => '2025-01-01', 'quantity_remaining' => 2],
], 4);
if ($allocations !== [
    ['inventory_lot_id' => 4, 'quantity' => 2.0],
    ['inventory_lot_id' => 7, 'quantity' => 2.0],
]) {
    throw new RuntimeException('FIFO allocation did not consume the oldest lot first.');
}

foreach ([0.0, -1.0, INF, NAN] as $quantity) {
    try {
        plan_fifo_allocation([], $quantity);
    } catch (RuntimeException $exception) {
        continue;
    }
    throw new RuntimeException('FIFO allocation accepted an invalid quantity.');
}

try {
    plan_fifo_allocation([
        ['id' => 1, 'source_type' => 'harvest', 'received_on' => '2025-01-01', 'quantity_remaining' => 1],
    ], 2);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Stock changed during checkout. Please try again.') {
        throw $exception;
    }
    exit;
}
throw new RuntimeException('FIFO allocation did not reject insufficient stock.');