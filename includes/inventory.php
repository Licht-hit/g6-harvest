<?php

function record_inventory_movement(
    MySQLiDatabase $db,
    int $vegetableId,
    float $quantityBefore,
    float $quantityAfter,
    string $movementType,
    string $reason,
    ?string $referenceCode = null,
    ?int $actorId = null
): void {
    if (
        $vegetableId < 1
        || !is_finite($quantityBefore)
        || !is_finite($quantityAfter)
        || $quantityBefore < 0
        || $quantityAfter < 0
        || $quantityBefore > 99999999.99
        || $quantityAfter > 99999999.99
        || abs($quantityBefore - round($quantityBefore, 2)) > 0.000000001
        || abs($quantityAfter - round($quantityAfter, 2)) > 0.000000001
        || !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $movementType)
        || trim($reason) === ''
        || strlen($reason) > 255
        || ($referenceCode !== null && strlen($referenceCode) > 64)
    ) {
        throw new RuntimeException('Inventory movement details are invalid.');
    }

    $vegetableStatement = $db->prepare(
        'SELECT name, unit FROM vegetables WHERE id = ?'
    );
    $vegetableStatement->execute([$vegetableId]);
    $vegetable = $vegetableStatement->fetch();
    if (!$vegetable) {
        throw new RuntimeException('Cannot record a movement for a missing vegetable.');
    }

    if ($actorId === null && isset($_SESSION['user']['id'])) {
        $actorId = (int) $_SESSION['user']['id'];
    }

    $movementStatement = $db->prepare(
        'INSERT INTO inventory_movements (
            vegetable_id,
            vegetable_name,
            unit,
            movement_type,
            quantity_before,
            quantity_after,
            quantity_change,
            reason,
            reference_code,
            actor_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $movementStatement->execute([
        $vegetableId,
        $vegetable['name'],
        $vegetable['unit'],
        $movementType,
        $quantityBefore,
        $quantityAfter,
        round($quantityAfter - $quantityBefore, 2),
        $reason,
        $referenceCode,
        $actorId,
    ]);
}
