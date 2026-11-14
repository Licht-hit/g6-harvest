<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

function xendit_webhook_respond(int $status, array $response): void
{
    http_response_code($status);
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    xendit_webhook_respond(405, ['error' => 'Method not allowed']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!is_string($contentType) || !str_contains(strtolower($contentType), 'application/json')) {
    xendit_webhook_respond(415, ['error' => 'Expected a JSON webhook payload']);
}

$configuration = paymongo_config();
$callbackToken = $configuration['xendit_webhook_token'];
if ($callbackToken === '') {
    error_log('Xendit webhook configuration is missing.');
    xendit_webhook_respond(503, ['error' => 'Payment webhook is not configured']);
}
if (!xendit_verify_callback_token($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? null, $callbackToken)) {
    xendit_webhook_respond(401, ['error' => 'Invalid webhook token']);
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength > 1048576) {
    xendit_webhook_respond(413, ['error' => 'Webhook payload is too large']);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 1048576) {
    xendit_webhook_respond(413, ['error' => 'Webhook payload is too large']);
}

try {
    $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    xendit_webhook_respond(400, ['error' => 'Invalid JSON payload']);
}

if (!is_array($event)) {
    xendit_webhook_respond(400, ['error' => 'Invalid webhook structure']);
}

$values = xendit_webhook_event_values($event);
$supportedEvents = [
    'payment_session.completed',
    'payment_session.expired',
    'refund.succeeded',
    'refund.failed',
];
if (!in_array($values['event_type'], $supportedEvents, true)) {
    xendit_webhook_respond(200, ['received' => true]);
}

if (
    !is_string($values['event_id'])
    || $values['event_id'] === ''
    || strlen($values['event_id']) > 160
    || !is_string($values['session_id'])
    || $values['session_id'] === ''
    || strlen($values['session_id']) > 120
) {
    xendit_webhook_respond(400, ['error' => 'Payment event is missing valid identifiers']);
}

if (in_array($values['event_type'], ['payment_session.completed', 'payment_session.expired'], true)) {
    if (
        !is_string($values['order_code'])
        || !preg_match('/^VS-[A-F0-9]{10}$/', $values['order_code'])
        || $values['currency'] !== 'PHP'
    ) {
        xendit_webhook_respond(400, ['error' => 'Payment session details are invalid']);
    }

    $expectedStatus = $values['event_type'] === 'payment_session.completed' ? 'COMPLETED' : 'EXPIRED';
    if ($values['status'] !== $expectedStatus) {
        xendit_webhook_respond(400, ['error' => 'Payment session status is invalid']);
    }
    if (
        $values['event_type'] === 'payment_session.completed'
        && ($values['amount_cents'] === null || $values['payment_id'] === null)
    ) {
        xendit_webhook_respond(400, ['error' => 'Completed payment details are missing']);
    }
} elseif (
    $values['currency'] !== 'PHP'
    || $values['amount_cents'] === null
    || $values['payment_id'] === null
) {
    xendit_webhook_respond(400, ['error' => 'Refund details are invalid']);
}

try {
    $db->beginTransaction();
    if (str_starts_with($values['event_type'], 'refund.')) {
        $orderStatement = $db->prepare(
            'SELECT * FROM orders
             WHERE payment_method = \'online\'
               AND gateway_provider = \'xendit\'
               AND payment_reference = ?
             FOR UPDATE'
        );
        $orderStatement->execute([$values['session_id']]);
    } else {
        $orderStatement = $db->prepare(
            'SELECT * FROM orders
             WHERE order_code = ?
               AND payment_method = \'online\'
               AND gateway_provider = \'xendit\'
               AND gateway_session_id = ?
             FOR UPDATE'
        );
        $orderStatement->execute([$values['order_code'], $values['session_id']]);
    }
    $order = $orderStatement->fetch();
    if (!$order) {
        $db->rollBack();
        xendit_webhook_respond(400, ['error' => 'Payment event does not match an Xendit order']);
    }

    $duplicateStatement = $db->prepare(
        'SELECT id FROM payment_history WHERE provider_event_id = ? LIMIT 1'
    );
    $duplicateStatement->execute([$values['event_id']]);
    if ($duplicateStatement->fetchColumn()) {
        $db->commit();
        xendit_webhook_respond(200, ['received' => true, 'duplicate' => true]);
    }

    $expectedAmountCents = (int) round((float) $order['final_total'] * 100);
    $amountMatches = $values['amount_cents'] === null
        || $values['amount_cents'] === $expectedAmountCents;
    $action = xendit_payment_event_action(
        $values['event_type'],
        $order['payment_status'],
        $order['order_status'],
        $amountMatches,
        !empty($order['gateway_expired_at'])
    );
    $nextPaymentStatus = match ($action) {
        'paid' => 'paid',
        'failed' => 'failed',
        'refunded' => 'refunded',
        default => $order['payment_status'],
    };

    $details = match ($action) {
        'manual_review' => !$amountMatches
            ? 'Xendit event amount does not match the order total. Review the payment manually.'
            : (!empty($order['gateway_expired_at'])
                ? 'Xendit event arrived after checkout expiry. Review payment and fulfillment manually.'
            : ($order['order_status'] === 'cancelled'
                ? 'Xendit event arrived after order cancellation. Review payment and fulfillment manually.'
                : 'Xendit event conflicts with the current payment status. Review it manually.')),
        'paid' => 'Payment confirmed by Xendit webhook.',
        'failed' => 'Xendit reported that the checkout session expired.',
        'refunded' => 'Refund confirmed by Xendit webhook.',
        'record_only' => 'Xendit reported that the refund failed.',
        default => 'Xendit event recorded.',
    };
    $historyEventType = $action === 'manual_review'
        ? 'manual_review_required'
        : $values['event_type'];
    $historyAmount = $values['amount_cents'] === null
        ? (float) $order['final_total']
        : $values['amount_cents'] / 100;
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
        $order['order_code'],
        $historyEventType,
        $nextPaymentStatus,
        $historyAmount,
        $values['payment_id'],
        $values['event_id'],
        $details,
    ]);

    if ($history->rowCount() === 0) {
        $db->commit();
        xendit_webhook_respond(200, ['received' => true, 'duplicate' => true]);
    }

    if ($action === 'manual_review') {
        if ($values['event_type'] === 'payment_session.expired') {
            $db->prepare(
                'UPDATE orders
                 SET gateway_expired_at = COALESCE(gateway_expired_at, CURRENT_TIMESTAMP)
                 WHERE id = ?'
            )->execute([(int) $order['id']]);
        }
        if ($values['event_type'] === 'payment_session.completed' && $values['payment_id'] !== null) {
            $db->prepare(
                'UPDATE orders
                 SET payment_reference = CASE
                     WHEN payment_status IN (\'paid\', \'refunded\') THEN payment_reference
                     ELSE ?
                 END
                 WHERE id = ?'
            )->execute([$values['payment_id'], (int) $order['id']]);
        }
        log_activity(
            'manual_review_required',
            'payment',
            (int) $order['id'],
            'Xendit event requires manual review: ' . $values['event_id']
        );
    } elseif ($action === 'paid') {
        $db->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 payment_reference = ?,
                 payment_confirmed_at = COALESCE(payment_confirmed_at, CURRENT_TIMESTAMP)
             WHERE id = ?"
        )->execute([$values['payment_id'], (int) $order['id']]);
        $db->prepare(
            "UPDATE sales SET status = 'paid' WHERE order_code = ?"
        )->execute([$order['order_code']]);
    } elseif ($action === 'failed' || $action === 'refunded') {
        $db->prepare(
            "UPDATE orders
             SET payment_status = ?,
                 gateway_expired_at = CASE
                     WHEN ? = 'failed' THEN COALESCE(gateway_expired_at, CURRENT_TIMESTAMP)
                     ELSE gateway_expired_at
                 END
             WHERE id = ?"
        )->execute([$nextPaymentStatus, $nextPaymentStatus, (int) $order['id']]);
    }

    $db->commit();
    xendit_webhook_respond(200, ['received' => true]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Xendit webhook processing failed: ' . $exception->getMessage());
    xendit_webhook_respond(500, ['error' => 'Webhook could not be processed']);
}