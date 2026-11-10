<?php
$testDatabase = getenv('HARVEST_TEST_DB') ?: '';
if (!preg_match('/^[a-zA-Z0-9_]+_test$/', $testDatabase)) {
    throw new RuntimeException(
        'Set HARVEST_TEST_DB to a dedicated database name ending in _test.'
    );
}

putenv('DB_NAME=' . $testDatabase);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/payments.php';

$actualDatabase = $db->query('SELECT DATABASE()')->fetchColumn();
if ($actualDatabase !== $testDatabase) {
    throw new RuntimeException('The configured database does not match HARVEST_TEST_DB.');
}

function expect_flow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insert_test_order(
    MySQLiDatabase $db,
    string $orderCode,
    int $buyerId,
    string $tokenHash,
    string $paymentStatus,
    string $orderStatus,
    string $sessionId
): void {
    $db->prepare(
        'INSERT INTO orders (
            order_code, buyer_id, checkout_token_hash, checkout_request_hash,
            gateway_checkout_url, subtotal, eligible_quantity, discount_percentage,
            discount_amount, delivery_fee, final_total, delivery_address,
            payment_method, payment_status, order_status, gateway_session_id
        ) VALUES (?, ?, ?, ?, ?, 30, 1, 0, 0, 20, 50, ?, \'online\', ?, ?, ?)'
    )->execute([
        $orderCode,
        $buyerId,
        $tokenHash,
        hash('sha256', $orderCode),
        'https://checkout.paymongo.com/test',
        'Test delivery address',
        $paymentStatus,
        $orderStatus,
        $sessionId,
    ]);
}

$db->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $email = 'harvest-flow-' . $suffix . '@example.invalid';
    $db->prepare(
        "INSERT INTO users (name, email, password, role, email_verified_at)
         VALUES ('Integration Test', ?, 'not-a-login-password', 'buyer', CURRENT_TIMESTAMP)"
    )->execute([$email]);
    $buyerId = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO vegetables (name, category, variety, description, unit, price, active)
         VALUES ('Integration Test Produce', 'Other', NULL, NULL, 'kg', 30, 1)"
    )->execute();
    $vegetableId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO inventory (vegetable_id, quantity) VALUES (?, 5)'
    )->execute([$vegetableId]);

    $firstOrderCode = 'VS-' . strtoupper(bin2hex(random_bytes(5)));
    $firstTokenHash = hash('sha256', random_bytes(32));
    insert_test_order(
        $db,
        $firstOrderCode,
        $buyerId,
        $firstTokenHash,
        'pending_payment',
        'confirmed',
        'cs_test_' . $suffix
    );

    reserve_inventory_stock($db, $vegetableId, 3.0);
    $stock = $db->prepare('SELECT quantity FROM inventory WHERE vegetable_id = ?');
    $stock->execute([$vegetableId]);
    expect_flow((float) $stock->fetchColumn() === 2.0, 'Checkout did not reserve the requested stock.');
    $movement = $db->prepare(
        'SELECT quantity_before, quantity_after, quantity_change, movement_type, reference_code
         FROM inventory_movements
         WHERE vegetable_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $movement->execute([$vegetableId]);
    $reservationMovement = $movement->fetch();
    expect_flow(
        $reservationMovement
            && (float) $reservationMovement['quantity_before'] === 5.0
            && (float) $reservationMovement['quantity_after'] === 2.0
            && (float) $reservationMovement['quantity_change'] === -3.0
            && $reservationMovement['movement_type'] === 'order_reserved',
        'Order stock reservation was not recorded with accurate before and after quantities.'
    );

    $oversellRejected = false;
    try {
        reserve_inventory_stock($db, $vegetableId, 3.0);
    } catch (RuntimeException $exception) {
        $oversellRejected = true;
    }
    $stock->execute([$vegetableId]);
    expect_flow($oversellRejected, 'The stock guard accepted an oversell.');
    expect_flow((float) $stock->fetchColumn() === 2.0, 'A rejected reservation changed inventory.');

    $db->prepare(
        "INSERT INTO sales (
            order_code, buyer_id, vegetable_id, quantity, unit_price, total, status
        ) VALUES (?, ?, ?, 3, 30, 90, 'pending')"
    )->execute([$firstOrderCode, $buyerId, $vegetableId]);

    $duplicateCheckoutRejected = false;
    try {
        insert_test_order(
            $db,
            'VS-' . strtoupper(bin2hex(random_bytes(5))),
            $buyerId,
            $firstTokenHash,
            'pending_payment',
            'confirmed',
            'cs_test_duplicate_' . $suffix
        );
    } catch (mysqli_sql_exception $exception) {
        $duplicateCheckoutRejected = (int) $exception->getCode() === 1062;
        if (!$duplicateCheckoutRejected) {
            throw $exception;
        }
    }
    expect_flow($duplicateCheckoutRejected, 'The checkout token did not prevent duplicate orders.');

    expect_flow(
        paymongo_payment_transition_action('pending_payment', 'paid', 'confirmed') === 'apply',
        'A valid payment was not accepted.'
    );
    $providerEventId = 'evt_test_' . $suffix;
    $history = $db->prepare(
        "INSERT IGNORE INTO payment_history (
            order_code, payment_method, event_type, payment_status, amount,
            payment_reference, provider_event_id, details
        ) VALUES (?, 'online', 'checkout_session.payment.paid', 'paid', 50, ?, ?, ?)"
    );
    $history->execute([
        $firstOrderCode,
        'pay_test_' . $suffix,
        $providerEventId,
        'Payment confirmed by integration test.',
    ]);
    expect_flow($history->rowCount() === 1, 'The first payment event was not recorded.');
    $db->prepare(
        "UPDATE orders SET payment_status = 'paid' WHERE order_code = ?"
    )->execute([$firstOrderCode]);
    $db->prepare(
        "UPDATE sales SET status = 'paid' WHERE order_code = ?"
    )->execute([$firstOrderCode]);
    $history->execute([
        $firstOrderCode,
        'pay_test_' . $suffix,
        $providerEventId,
        'Duplicate event.',
    ]);
    expect_flow($history->rowCount() === 0, 'A repeated provider event was recorded twice.');

    $cancelledOrderCode = 'VS-' . strtoupper(bin2hex(random_bytes(5)));
    insert_test_order(
        $db,
        $cancelledOrderCode,
        $buyerId,
        hash('sha256', random_bytes(32)),
        'failed',
        'cancelled',
        'cs_test_cancelled_' . $suffix
    );
    reserve_inventory_stock($db, $vegetableId, 1.0);
    $stock->execute([$vegetableId]);
    $quantityBeforeRestock = (float) $stock->fetchColumn();
    $db->prepare(
        'UPDATE inventory SET quantity = quantity + 1 WHERE vegetable_id = ?'
    )->execute([$vegetableId]);
    record_inventory_movement(
        $db,
        $vegetableId,
        $quantityBeforeRestock,
        $quantityBeforeRestock + 1.0,
        'order_cancelled',
        'Stock restored after test order cancellation.',
        $cancelledOrderCode,
        $buyerId
    );
    $movement->execute([$vegetableId]);
    $restockMovement = $movement->fetch();
    expect_flow(
        $restockMovement
            && (float) $restockMovement['quantity_before'] === 1.0
            && (float) $restockMovement['quantity_after'] === 2.0
            && (float) $restockMovement['quantity_change'] === 1.0
            && $restockMovement['movement_type'] === 'order_cancelled'
            && $restockMovement['reference_code'] === $cancelledOrderCode,
        'Cancelled order stock restoration was not recorded accurately.'
    );
    $latePaymentAction = paymongo_payment_transition_action('failed', 'paid', 'cancelled');
    expect_flow($latePaymentAction === 'late_payment', 'A delayed payment after cancellation was not flagged.');
    expect_flow(
        paymongo_payment_transition_action('paid', 'paid', 'cancelled') === 'ignore',
        'A duplicate payment callback was not ignored.'
    );
    $stock->execute([$vegetableId]);
    expect_flow((float) $stock->fetchColumn() === 2.0, 'Cancellation did not release its reserved stock.');

    echo "Order flow integration checks passed.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
