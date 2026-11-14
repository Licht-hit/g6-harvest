<?php

function sort_fifo_batches(array $batches): array
{
    usort($batches, static function (array $first, array $second): int {
        $firstOpening = ($first['source_type'] ?? '') === 'opening_balance';
        $secondOpening = ($second['source_type'] ?? '') === 'opening_balance';
        if ($firstOpening !== $secondOpening) {
            return $firstOpening ? -1 : 1;
        }

        $dateOrder = strcmp((string) ($first['received_on'] ?? ''), (string) ($second['received_on'] ?? ''));
        if ($dateOrder !== 0) {
            return $dateOrder;
        }

        return (int) ($first['id'] ?? 0) <=> (int) ($second['id'] ?? 0);
    });

    return $batches;
}

function plan_fifo_allocation(array $batches, float $quantity): array
{
    if (!is_finite($quantity) || $quantity <= 0) {
        throw new RuntimeException('An inventory reservation quantity is invalid.');
    }

    $allocations = [];
    $remaining = round($quantity, 2);
    foreach (sort_fifo_batches($batches) as $batch) {
        $available = round((float) ($batch['quantity_remaining'] ?? 0), 2);
        $batchId = filter_var($batch['id'] ?? null, FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1 || !is_finite($available) || $available <= 0) {
            continue;
        }

        $allocated = min($remaining, $available);
        if ($allocated > 0) {
            $allocations[] = ['inventory_lot_id' => $batchId, 'quantity' => $allocated];
            $remaining = round($remaining - $allocated, 2);
        }
        if ($remaining <= 0) {
            break;
        }
    }

    if ($remaining > 0) {
        throw new RuntimeException('Stock changed during checkout. Please try again.');
    }

    return $allocations;
}

function fifo_add_inventory_lot(
    MySQLiDatabase $db,
    int $vegetableId,
    float $quantity,
    string $sourceType,
    ?string $receivedOn = null,
    ?int $harvestId = null
): void {
    if (
        $vegetableId < 1
        || !is_finite($quantity)
        || $quantity <= 0
        || abs($quantity - round($quantity, 2)) > 0.000000001
        || !in_array($sourceType, ['harvest', 'manual_addition', 'cancellation'], true)
        || ($sourceType === 'harvest') !== ($harvestId !== null)
    ) {
        throw new RuntimeException('Inventory batch details are invalid.');
    }

    $receivedOn = $receivedOn ?: date('Y-m-d');
    if (!is_valid_date($receivedOn)) {
        throw new RuntimeException('The inventory batch date is invalid.');
    }

    $statement = $db->prepare(
        'INSERT INTO inventory_lots (
            vegetable_id, source_type, harvest_id, received_on, quantity_received, quantity_remaining
        ) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$vegetableId, $sourceType, $harvestId, $receivedOn, $quantity, $quantity]);
}

function fifo_adjust_harvest_lot(
    MySQLiDatabase $db,
    int $vegetableId,
    int $harvestId,
    float $difference,
    string $receivedOn
): bool {
    $statement = $db->prepare(
        'SELECT id, quantity_received, quantity_remaining
         FROM inventory_lots
         WHERE harvest_id = ? AND vegetable_id = ? AND source_type = \'harvest\'
         FOR UPDATE'
    );
    $statement->execute([$harvestId, $vegetableId]);
    $lot = $statement->fetch();
    if (!$lot) {
        if ($difference > 0) {
            fifo_add_inventory_lot($db, $vegetableId, $difference, 'harvest', $receivedOn, $harvestId);
            return true;
        }
        if ($difference < 0) {
            fifo_remove_available_stock($db, $vegetableId, abs($difference));
        }
        return true;
    }

    if (!is_valid_date($receivedOn) || !is_finite($difference)) {
        throw new RuntimeException('Harvest batch details are invalid.');
    }
    if ($difference < 0 && (float) $lot['quantity_remaining'] < abs($difference)) {
        throw new RuntimeException('Stock is too low to reduce this harvest quantity.');
    }

    $update = $db->prepare(
        'UPDATE inventory_lots
         SET quantity_received = quantity_received + ?,
             quantity_remaining = quantity_remaining + ?,
             received_on = ?
         WHERE id = ?'
    );
    $update->execute([$difference, $difference, $receivedOn, (int) $lot['id']]);

    return true;
}

function fifo_remove_available_stock(
    MySQLiDatabase $db,
    int $vegetableId,
    float $quantity,
    ?int $harvestId = null,
    bool $reduceReceived = false
): void {
    if ($vegetableId < 1 || !is_finite($quantity) || $quantity < 0) {
        throw new RuntimeException('The inventory adjustment quantity is invalid.');
    }
    if ($quantity === 0.0) {
        return;
    }

    $sql = 'SELECT id, quantity_remaining
            FROM inventory_lots
            WHERE vegetable_id = ? AND quantity_remaining > 0';
    $parameters = [$vegetableId];
    if ($harvestId !== null) {
        $sql .= ' AND harvest_id = ? AND source_type = \'harvest\'';
        $parameters[] = $harvestId;
    }
    $sql .= ' ORDER BY received_on DESC, id DESC FOR UPDATE';

    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $lots = $statement->fetchAll();
    if ($harvestId !== null && !$lots) {
        $existingHarvestLot = $db->prepare(
            'SELECT id FROM inventory_lots
             WHERE vegetable_id = ? AND harvest_id = ? AND source_type = \'harvest\'
             LIMIT 1 FOR UPDATE'
        );
        $existingHarvestLot->execute([$vegetableId, $harvestId]);
        if ($existingHarvestLot->fetch()) {
            throw new RuntimeException('Stock is too low to reduce this harvest quantity.');
        }

        $fallback = $db->prepare(
            'SELECT id, quantity_remaining
             FROM inventory_lots
             WHERE vegetable_id = ? AND quantity_remaining > 0
             ORDER BY received_on DESC, id DESC
             FOR UPDATE'
        );
        $fallback->execute([$vegetableId]);
        $lots = $fallback->fetchAll();
    }

    $remaining = round($quantity, 2);
    foreach ($lots as $lot) {
        $lotAvailable = round((float) $lot['quantity_remaining'], 2);
        $removed = min($remaining, $lotAvailable);
        if ($removed <= 0) {
            continue;
        }

        $updateSql = $reduceReceived
            ? 'UPDATE inventory_lots
               SET quantity_received = quantity_received - ?,
                   quantity_remaining = quantity_remaining - ?
               WHERE id = ? AND quantity_remaining >= ? AND quantity_received >= ?'
            : 'UPDATE inventory_lots
               SET quantity_remaining = quantity_remaining - ?
               WHERE id = ? AND quantity_remaining >= ?';
        $update = $db->prepare($updateSql);
        $update->execute($reduceReceived
            ? [$removed, $removed, (int) $lot['id'], $removed, $removed]
            : [$removed, (int) $lot['id'], $removed]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Inventory changed. Reload the page and try again.');
        }

        $remaining = round($remaining - $removed, 2);
        if ($remaining <= 0) {
            break;
        }
    }

    if ($remaining > 0) {
        throw new RuntimeException('There is not enough available stock in the inventory batches.');
    }
}

function fifo_reconcile_manual_adjustment(
    MySQLiDatabase $db,
    int $vegetableId,
    float $quantityBefore,
    float $quantityAfter
): void {
    $difference = round($quantityAfter - $quantityBefore, 2);
    if ($difference > 0) {
        fifo_add_inventory_lot($db, $vegetableId, $difference, 'manual_addition');
    } elseif ($difference < 0) {
        fifo_remove_available_stock($db, $vegetableId, abs($difference));
    }
}

function fifo_reserve_order_stock(
    MySQLiDatabase $db,
    int $vegetableId,
    float $quantity,
    string $orderCode
): void {
    $statement = $db->prepare(
        'SELECT id, source_type, received_on, quantity_remaining
         FROM inventory_lots
         WHERE vegetable_id = ? AND quantity_remaining > 0
         ORDER BY received_on, id
         FOR UPDATE'
    );
    $statement->execute([$vegetableId]);
    $allocations = plan_fifo_allocation($statement->fetchAll(), $quantity);
    $reduce = $db->prepare(
        'UPDATE inventory_lots SET quantity_remaining = quantity_remaining - ?
         WHERE id = ? AND quantity_remaining >= ?'
    );
    $record = $db->prepare(
        'INSERT INTO inventory_lot_allocations (
            order_code, vegetable_id, inventory_lot_id, quantity
        ) VALUES (?, ?, ?, ?)'
    );

    foreach ($allocations as $allocation) {
        $allocated = $allocation['quantity'];
        $lotId = $allocation['inventory_lot_id'];
        $reduce->execute([$allocated, $lotId, $allocated]);
        if ($reduce->rowCount() !== 1) {
            throw new RuntimeException('Stock changed during checkout. Please try again.');
        }
        $record->execute([$orderCode, $vegetableId, $lotId, $allocated]);
    }
}

function fifo_release_order_stock(MySQLiDatabase $db, string $orderCode): array
{
    $statement = $db->prepare(
        'SELECT id, vegetable_id, inventory_lot_id, quantity
         FROM inventory_lot_allocations
         WHERE order_code = ? AND released_at IS NULL
         ORDER BY id
         FOR UPDATE'
    );
    $statement->execute([$orderCode]);
    $allocations = $statement->fetchAll();
    $releasedByVegetable = [];
    $restoreLot = $db->prepare(
        'UPDATE inventory_lots
         SET quantity_remaining = quantity_remaining + ?
         WHERE id = ? AND quantity_remaining + ? <= quantity_received'
    );
    $markReleased = $db->prepare(
        'UPDATE inventory_lot_allocations SET released_at = CURRENT_TIMESTAMP WHERE id = ?'
    );

    foreach ($allocations as $allocation) {
        $quantity = (float) $allocation['quantity'];
        $restoreLot->execute([
            $quantity,
            (int) $allocation['inventory_lot_id'],
            $quantity,
        ]);
        if ($restoreLot->rowCount() !== 1) {
            throw new RuntimeException('Could not restore the order’s inventory batch.');
        }
        $markReleased->execute([(int) $allocation['id']]);
        $vegetableId = (int) $allocation['vegetable_id'];
        $releasedByVegetable[$vegetableId] = round(
            ($releasedByVegetable[$vegetableId] ?? 0) + $quantity,
            2
        );
    }

    return $releasedByVegetable;
}