<?php

require_once __DIR__ . '/notifications.php';

function pending_order_return_count(MySQLiDatabase $db): int
{
    $statement = $db->query(
        "SELECT COUNT(*) FROM return_requests WHERE status = 'pending'"
    );

    return (int) $statement->fetchColumn();
}

function order_return_is_available(array $order): bool
{
    return ($order['order_status'] ?? null) === 'delivered';
}

function order_returnable_quantity(float $soldQuantity, float $requestedQuantity): float
{
    if (!is_finite($soldQuantity) || !is_finite($requestedQuantity)) {
        return 0.0;
    }

    return max(0.0, round($soldQuantity - $requestedQuantity, 2));
}

function create_order_return_request(
    MySQLiDatabase $db,
    int $buyerId,
    string $orderCode,
    array $requestedItems,
    string $reason,
    string $notes
): int {
    if ($buyerId < 1 || !preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
        throw new RuntimeException('Choose a valid order for your return request.');
    }
    if (!in_array($reason, ['damaged', 'quality_issue'], true)) {
        throw new RuntimeException('Choose a valid return reason.');
    }
    if (strlen($notes) > 2000) {
        throw new RuntimeException('Return notes must be 2,000 characters or fewer.');
    }
    if (!$requestedItems || count($requestedItems) > 50) {
        throw new RuntimeException('Choose at least one order item to return.');
    }

    $db->beginTransaction();
    try {
        $orderStatement = $db->prepare(
            'SELECT order_code, buyer_id, order_status
             FROM orders
             WHERE order_code = ? AND buyer_id = ?
             FOR UPDATE'
        );
        $orderStatement->execute([$orderCode, $buyerId]);
        $order = $orderStatement->fetch();
        if (!$order || !order_return_is_available($order)) {
            throw new RuntimeException(
                'Return requests are available only for delivered orders.'
            );
        }

        $saleStatement = $db->prepare(
            "SELECT s.id, s.quantity,
                    COALESCE(SUM(CASE WHEN rr.status IN ('pending', 'approved', 'completed')
                        THEN rri.quantity ELSE 0 END), 0) AS already_requested
             FROM sales AS s
             LEFT JOIN return_request_items AS rri ON rri.sales_id = s.id
             LEFT JOIN return_requests AS rr ON rr.id = rri.return_request_id
             WHERE s.id = ? AND s.order_code = ? AND s.buyer_id = ?
             GROUP BY s.id, s.quantity"
        );
        $insertRequest = $db->prepare(
            'INSERT INTO return_requests (order_code, buyer_id, reason, notes)
             VALUES (?, ?, ?, ?)'
        );
        $insertItem = $db->prepare(
            'INSERT INTO return_request_items (return_request_id, sales_id, quantity)
             VALUES (?, ?, ?)'
        );
        $items = [];
        foreach ($requestedItems as $saleIdInput => $quantityInput) {
            $saleId = filter_var($saleIdInput, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantityInput, FILTER_VALIDATE_FLOAT);
            if (
                $saleId === false || $saleId < 1
                || $quantity === false || !is_finite((float) $quantity)
                || $quantity <= 0 || $quantity > 99999999.99
                || abs((float) $quantity - round((float) $quantity, 2)) > 0.000000001
            ) {
                throw new RuntimeException('Enter a valid return quantity for each selected item.');
            }

            $saleStatement->execute([$saleId, $orderCode, $buyerId]);
            $sale = $saleStatement->fetch();
            if (!$sale) {
                throw new RuntimeException('A selected item does not belong to this order.');
            }
            $remaining = order_returnable_quantity(
                (float) $sale['quantity'],
                (float) $sale['already_requested']
            );
            if ((float) $quantity > $remaining) {
                throw new RuntimeException('The requested return quantity exceeds the item quantity still eligible.');
            }
            $items[] = ['sales_id' => $saleId, 'quantity' => (float) $quantity];
        }

        $insertRequest->execute([
            $orderCode,
            $buyerId,
            $reason,
            $notes === '' ? null : $notes,
        ]);
        $requestId = (int) $db->lastInsertId();
        foreach ($items as $item) {
            $insertItem->execute([$requestId, $item['sales_id'], $item['quantity']]);
        }

        create_notifications_for_roles(
            $db,
            ['admin', 'staff', 'farmer'],
            'return_request',
            'New return request for order ' . $orderCode . '.',
            'modules/returns.php',
            $orderCode
        );
        log_activity('return_requested', 'order', null, 'Return request submitted for order ' . $orderCode . '.');
        $db->commit();

        return $requestId;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function update_order_return_request(
    MySQLiDatabase $db,
    int $requestId,
    string $nextStatus,
    ?string $staffNotes,
    int $staffId
): void {
    if ($requestId < 1 || $staffId < 1 || !in_array($nextStatus, ['approved', 'rejected', 'completed'], true)) {
        throw new RuntimeException('Choose a valid return request action.');
    }
    if ($nextStatus === 'rejected' && trim((string) $staffNotes) === '') {
        throw new RuntimeException('A reason is required when rejecting a return request.');
    }
    if ($staffNotes !== null && strlen($staffNotes) > 2000) {
        throw new RuntimeException('Staff notes must be 2,000 characters or fewer.');
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'SELECT id, order_code, buyer_id, status
             FROM return_requests WHERE id = ? FOR UPDATE'
        );
        $statement->execute([$requestId]);
        $request = $statement->fetch();
        if (!$request) {
            throw new RuntimeException('That return request could not be found.');
        }
        $allowedStatuses = [
            'pending' => ['approved', 'rejected'],
            'approved' => ['completed'],
        ];
        if (!in_array($nextStatus, $allowedStatuses[$request['status']] ?? [], true)) {
            throw new RuntimeException('That return request status change is not allowed.');
        }

        $update = $db->prepare(
            'UPDATE return_requests
             SET status = ?,
                 staff_notes = COALESCE(?, staff_notes),
                 reviewed_by = ?,
                 reviewed_at = CURRENT_TIMESTAMP,
                 completed_at = CASE WHEN ? = \'completed\' THEN CURRENT_TIMESTAMP ELSE completed_at END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $update->execute([
            $nextStatus,
            $staffNotes === null ? null : trim($staffNotes),
            $staffId,
            $nextStatus,
            $requestId,
        ]);
        create_notification_for_user(
            $db,
            (int) $request['buyer_id'],
            'return_status',
            'Return request for order ' . $request['order_code'] . ' was ' . str_replace('_', ' ', $nextStatus) . '.',
            'buyer/order_details.php?order=' . rawurlencode($request['order_code']),
            $request['order_code']
        );
        log_activity(
            'return_status_updated',
            'order',
            null,
            'Return request #' . $requestId . ' for order ' . $request['order_code']
                . ' was ' . $nextStatus . '.'
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}