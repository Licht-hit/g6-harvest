<?php
// This file receives the payment provider webhook.
// It checks the webhook signature and updates the payment status only when valid.
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
if (
    !is_string($rawBody)
    || strlen($rawBody) > 1048576
    || !is_string($signature)
    || !paymongo_verify_webhook($rawBody, $signature)
) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

if (
    !is_array($payload)
    || !is_array($payload['data'] ?? null)
    || !is_array($payload['data']['data'] ?? null)
) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook structure']);
    exit;
}

$event = $payload['data'] ?? [];
$eventType = $event['type'] ?? '';
$session = $event['data'] ?? [];
$sessionId = $session['id'] ?? '';
$attributes = $session['attributes'] ?? [];
if (!is_string($eventType) || !is_array($attributes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook structure']);
    exit;
}

if ($eventType === 'payment.refunded') {
    $paymentId = is_string($sessionId) ? $sessionId : '';
    if ($paymentId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Payment reference is missing']);
        exit;
    }

    try {
        $db->beginTransaction();
        $orderStatement = $db->prepare(
            'SELECT * FROM orders
             WHERE payment_reference = ? AND payment_method = \'online\'
               AND gateway_provider = \'paymongo\'
             FOR UPDATE'
        );
        $orderStatement->execute([$paymentId]);
        $order = $orderStatement->fetch();
        if (!$order || $order['payment_status'] !== 'paid') {
            $db->commit();
            http_response_code(200);
            echo json_encode(['received' => true]);
            exit;
        }

        $paymentAmount = (int) ($attributes['amount'] ?? 0);
        $expectedAmount = (int) round((float) $order['final_total'] * 100);
        $currency = $attributes['currency'] ?? '';
        if (
            $currency !== 'PHP'
            || $paymentAmount !== $expectedAmount
            || ($attributes['status'] ?? '') !== 'refunded'
        ) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Refund details are invalid']);
            exit;
        }

        $providerEventId = is_string($event['id'] ?? null)
            ? $event['id']
            : 'payment.refunded:' . $paymentId;
        $history = $db->prepare(
            'INSERT IGNORE INTO payment_history (
                order_code,
                payment_method,
                event_type,
                payment_status,
                amount,
                payment_reference,
                provider_event_id,
                details
            ) VALUES (?, \'online\', \'payment_refunded\', \'refunded\', ?, ?, ?, ?)'
        );
        $history->execute([
            $order['order_code'],
            $order['final_total'],
            $paymentId,
            $providerEventId,
            'Refund confirmed by PayMongo webhook.',
        ]);

        if ($history->rowCount() > 0) {
            $db->prepare(
                "UPDATE orders SET payment_status = 'refunded'
                 WHERE order_code = ? AND payment_status = 'paid'"
            )->execute([$order['order_code']]);
        }

        $db->commit();
        echo json_encode(['received' => true]);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('PayMongo refund webhook processing failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Refund webhook could not be processed']);
    }
    exit;
}

$supportedEvents = [
    'checkout_session.payment.paid' => 'paid',
    'checkout_session.payment.failed' => 'failed',
];

if (!isset($supportedEvents[$eventType]) || !is_string($sessionId) || $sessionId === '') {
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$newPaymentStatus = $supportedEvents[$eventType];
$orderCode = $attributes['reference_number'] ?? '';
$metadataOrderCode = is_array($attributes['metadata'] ?? null)
    ? ($attributes['metadata']['order_code'] ?? '')
    : '';
if (!is_string($orderCode) || $orderCode === '') {
    $orderCode = is_string($metadataOrderCode) ? $metadataOrderCode : '';
}

if (!preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Order reference is missing']);
    exit;
}

try {
    $db->beginTransaction();
    $orderStatement = $db->prepare(
        'SELECT *
         FROM orders
         WHERE order_code = ? AND gateway_provider = \'paymongo\'
         FOR UPDATE'
    );
    $orderStatement->execute([$orderCode]);
    $order = $orderStatement->fetch();

    if (
        !$order
        || $order['payment_method'] !== 'online'
        || $order['gateway_session_id'] !== $sessionId
    ) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Checkout session does not match an online order']);
        exit;
    }

    $transitionAction = paymongo_payment_transition_action(
        $order['payment_status'],
        $newPaymentStatus,
        $order['order_status'],
        !empty($order['gateway_expired_at'])
    );
    if ($transitionAction === 'ignore') {
        $db->commit();
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    $paidPayment = null;
    if ($newPaymentStatus === 'paid') {
        $payments = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $paymentAttributes = is_array($payment['attributes'] ?? null)
                ? $payment['attributes']
                : [];
            if (($paymentAttributes['status'] ?? '') === 'paid') {
                $paidPayment = $payment;
                break;
            }
        }

        $expectedAmount = (int) round((float) $order['final_total'] * 100);
        $paidAttributes = is_array($paidPayment['attributes'] ?? null)
            ? $paidPayment['attributes']
            : [];
        $paidAmount = (int) ($paidAttributes['amount'] ?? -1);
        $currency = $paidAttributes['currency'] ?? '';
        if (!$paidPayment || $paidAmount !== $expectedAmount || $currency !== 'PHP') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Paid amount does not match the order']);
            exit;
        }
    }

    $paymentId = $paidPayment['id'] ?? $sessionId;
    $providerEventId = is_string($event['id'] ?? null)
        ? $event['id']
        : $eventType . ':' . $sessionId . ':' . $paymentId;
    $latePayment = $transitionAction === 'late_payment';
    $historyEventType = $latePayment ? 'manual_review_required' : $eventType;
    $historyPaymentStatus = $latePayment ? $order['payment_status'] : $newPaymentStatus;
    $history = $db->prepare(
        'INSERT IGNORE INTO payment_history (
            order_code,
            payment_method,
            event_type,
            payment_status,
            amount,
            payment_reference,
            provider_event_id,
            details
        ) VALUES (?, \'online\', ?, ?, ?, ?, ?, ?)'
    );
    $history->execute([
        $orderCode,
        $historyEventType,
        $historyPaymentStatus,
        $order['final_total'],
        $paymentId,
        $providerEventId,
        $latePayment
            ? 'Payment completed after the order was cancelled and stock was released. Manual refund or fulfillment review is required.'
            : ($newPaymentStatus === 'paid'
                ? 'Payment confirmed by PayMongo webhook.'
                : 'PayMongo reported that the checkout payment failed.'),
    ]);

    if ($history->rowCount() === 0) {
        $db->commit();
        http_response_code(200);
        echo json_encode(['received' => true, 'duplicate' => true]);
        exit;
    }

    if ($latePayment) {
        $db->prepare(
            'UPDATE orders
             SET payment_reference = ?
             WHERE id = ?'
        )->execute([$paymentId, (int) $order['id']]);
        log_activity(
            'manual_review_required',
            'payment',
            (int) $order['id'],
            'Late PayMongo payment requires manual review: ' . $providerEventId
        );
    } elseif ($newPaymentStatus === 'paid') {
        $updateOrder = $db->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 payment_reference = ?,
                 payment_confirmed_at = CURRENT_TIMESTAMP
             WHERE order_code = ?
               AND payment_status IN ('pending_payment', 'failed')"
        );
        $updateOrder->execute([$paymentId, $orderCode]);
        if ($updateOrder->rowCount() === 1) {
            $updateSales = $db->prepare(
                "UPDATE sales SET status = 'paid' WHERE order_code = ?"
            );
            $updateSales->execute([$orderCode]);
        }
    } elseif ($order['payment_status'] === 'pending_payment') {
        $updateOrder = $db->prepare(
            "UPDATE orders
             SET payment_status = 'failed'
             WHERE order_code = ? AND payment_status = 'pending_payment'"
        );
        $updateOrder->execute([$orderCode]);
    }

    $db->commit();
    echo json_encode(['received' => true]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('PayMongo webhook processing failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Webhook could not be processed']);
}
