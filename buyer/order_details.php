<?php
// This page shows one buyer order and its payment details.
// It is easy to follow because each query is focused on one order.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_tracking.php';
require_once __DIR__ . '/../includes/order_returns.php';
require_role('buyer');

$orderCode = is_string($_GET['order'] ?? null) ? trim($_GET['order']) : '';
if (!preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
    http_response_code(404);
    exit('Order not found.');
}

$orderStatement = $db->prepare(
    'SELECT o.*, u.name AS confirmed_by_name
     FROM orders AS o
     LEFT JOIN users AS u ON u.id = o.payment_confirmed_by
     WHERE o.order_code = ? AND o.buyer_id = ?'
);
$orderStatement->execute([$orderCode, (int) current_user()['id']]);
$order = $orderStatement->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action !== 'request_return') {
        http_response_code(400);
        exit('Choose a valid order action.');
    }

    $rawRequestedItems = is_array($_POST['return_quantity'] ?? null)
        ? $_POST['return_quantity']
        : [];
    $requestedItems = [];
    foreach ($rawRequestedItems as $saleId => $quantity) {
        if (!is_string($quantity) && !is_int($quantity) && !is_float($quantity)) {
            continue;
        }
        $validatedQuantity = filter_var($quantity, FILTER_VALIDATE_FLOAT);
        if ($validatedQuantity === false || $validatedQuantity <= 0) {
            continue;
        }
        $requestedItems[$saleId] = $validatedQuantity;
    }

    try {
        create_order_return_request(
            $db,
            (int) current_user()['id'],
            $orderCode,
            $requestedItems,
            is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '',
            is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : ''
        );
        flash('success', 'Your return request was sent for staff review.');
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    redirect(app_url('buyer/order_details.php?order=' . rawurlencode($orderCode)));
}

$itemsStatement = $db->prepare(
    "SELECT s.id, s.quantity, s.unit_price, s.total, v.name, v.unit,
            COALESCE(SUM(CASE WHEN rr.status IN ('pending', 'approved', 'completed')
                THEN rri.quantity ELSE 0 END), 0) AS already_requested
     FROM sales AS s
     JOIN vegetables AS v ON v.id = s.vegetable_id
     LEFT JOIN return_request_items AS rri ON rri.sales_id = s.id
     LEFT JOIN return_requests AS rr ON rr.id = rri.return_request_id
     WHERE s.order_code = ? AND s.buyer_id = ?
     GROUP BY s.id, s.quantity, s.unit_price, s.total, v.name, v.unit
     ORDER BY v.name"
);
$itemsStatement->execute([$orderCode, (int) current_user()['id']]);
$items = $itemsStatement->fetchAll();

$historyStatement = $db->prepare(
    'SELECT ph.*, u.name AS actor_name
     FROM payment_history AS ph
     LEFT JOIN users AS u ON u.id = ph.actor_id
     WHERE ph.order_code = ?
     ORDER BY ph.created_at DESC, ph.id DESC'
);
$historyStatement->execute([$orderCode]);
$paymentHistory = $historyStatement->fetchAll();

$returnStatement = $db->prepare(
    'SELECT id, reason, notes, status, staff_notes, requested_at, reviewed_at, completed_at
     FROM return_requests
     WHERE order_code = ? AND buyer_id = ?
     ORDER BY requested_at DESC, id DESC'
);
$returnStatement->execute([$orderCode, (int) current_user()['id']]);
$returnRequests = $returnStatement->fetchAll();
$returnItemStatement = $db->prepare(
    'SELECT rri.return_request_id, rri.quantity, v.name, v.unit
     FROM return_request_items AS rri
     JOIN sales AS s ON s.id = rri.sales_id
     JOIN vegetables AS v ON v.id = s.vegetable_id
     JOIN return_requests AS rr ON rr.id = rri.return_request_id
     WHERE rr.order_code = ? AND rr.buyer_id = ?
     ORDER BY v.name'
);
$returnItemStatement->execute([$orderCode, (int) current_user()['id']]);
$returnItemsByRequest = [];
foreach ($returnItemStatement->fetchAll() as $returnItem) {
    $returnItemsByRequest[(int) $returnItem['return_request_id']][] = $returnItem;
}

$pageTitle = 'Order ' . $orderCode;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="no-print">
        <h1>Order <?= h($orderCode) ?></h1>
        <p class="muted">Placed <?= h($order['created_at']) ?></p>
    </div>
    <div class="buyer-order-detail-actions">
        <button type="button" class="button secondary" data-print>Print receipt</button>
        <?php if (in_array($order['order_status'], ['delivered', 'cancelled'], true)): ?>
            <form method="post" action="<?= h(app_url('buyer/dashboard.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="order_code" value="<?= h($orderCode) ?>">
                <button class="button secondary" type="submit">Order again</button>
            </form>
        <?php endif; ?>
        <a class="button secondary" href="<?= h(app_url('buyer/orders.php')) ?>">Back to orders</a>
    </div>
</div>

<section class="panel section receipt">
    <h2>Order details</h2>
    <p>
        <strong>Delivery status:</strong>
        <span data-order-status-for="<?= h($orderCode) ?>">
            <?= h(ucwords(str_replace('_', ' ', $order['order_status']))) ?>
        </span>
    </p>
    <?= order_tracking_html($order) ?>
    <p>
        <strong>Payment:</strong>
        <?= h(strtoupper($order['payment_method'])) ?> ·
        <span data-payment-status-for="<?= h($orderCode) ?>">
            <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?>
        </span>
    </p>
    <?php if ($order['order_status'] === 'cancelled' && $order['payment_status'] === 'paid'): ?>
        <p class="notice error" role="status">
            Payment completed after this order was cancelled. Please contact Harvest support to arrange a refund or discuss next steps.
        </p>
    <?php endif; ?>
    <p>
        <strong>Combined eligible quantity:</strong>
        <?= number_format((float) $order['eligible_quantity'], 2) ?> units
    </p>
    <p><strong>Delivery address:</strong> <?= nl2br(h($order['delivery_address'])) ?></p>
    <?php if ($order['payment_reference']): ?>
        <p><strong>Payment reference:</strong> <?= h($order['payment_reference']) ?></p>
    <?php endif; ?>
    <?php if ($order['payment_confirmed_at']): ?>
        <p>
            <strong>Payment collected/confirmed:</strong>
            <?= h($order['payment_confirmed_at']) ?>
            <?php if ($order['confirmed_by_name']): ?>
                by <?= h($order['confirmed_by_name']) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Produce</th>
                    <th>Quantity</th>
                    <th>Unit price</th>
                    <th>Line subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= h($item['name']) ?></td>
                        <td><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                        <td><?= money($item['unit_price']) ?></td>
                        <td><?= money($item['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <dl class="checkout-totals">
        <div><dt>Original vegetable subtotal</dt><dd><?= money($order['subtotal']) ?></dd></div>
        <div>
            <dt>Bulk discount (<?= number_format((float) $order['discount_percentage'], 0) ?>%)</dt>
            <dd>-<?= money($order['discount_amount']) ?></dd>
        </div>
        <div><dt>Delivery fee</dt><dd><?= money($order['delivery_fee']) ?></dd></div>
        <div class="checkout-final-total"><dt>Final total</dt><dd><?= money($order['final_total']) ?></dd></div>
    </dl>

    <section class="section">
        <h2>Return requests</h2>
        <?php if (order_return_is_available($order)): ?>
            <p class="muted">Report damaged or quality-issue items after delivery. Staff will review your request.</p>
            <form method="post" class="return-request-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_return">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Produce</th><th>Purchased</th><th>Still eligible</th><th>Return quantity</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php $remainingReturnable = order_returnable_quantity((float) $item['quantity'], (float) $item['already_requested']); ?>
                                <tr>
                                    <td><?= h($item['name']) ?></td>
                                    <td><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                                    <td><?= number_format($remainingReturnable, 2) ?> <?= h($item['unit']) ?></td>
                                    <td>
                                        <input type="number" name="return_quantity[<?= (int) $item['id'] ?>]"
                                            min="0" max="<?= h((string) $remainingReturnable) ?>" step="0.01" value="0"
                                            <?= $remainingReturnable <= 0 ? 'disabled' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="field">
                    <label for="return-reason">Reason</label>
                    <select id="return-reason" name="reason" required>
                        <option value="">Choose a reason</option>
                        <option value="damaged">Damaged</option>
                        <option value="quality_issue">Quality issue</option>
                    </select>
                </div>
                <div class="field">
                    <label for="return-notes">Details (optional)</label>
                    <textarea id="return-notes" name="notes" maxlength="2000"></textarea>
                </div>
                <button type="submit">Request return</button>
            </form>
        <?php endif; ?>
        <?php if (!$returnRequests): ?>
            <p class="muted">No return requests for this order.</p>
        <?php else: ?>
            <div class="return-request-history">
                <?php foreach ($returnRequests as $request): ?>
                    <article class="panel">
                        <h3><?= h(ucwords(str_replace('_', ' ', $request['status']))) ?> · <?= h(ucwords(str_replace('_', ' ', $request['reason']))) ?></h3>
                        <p class="muted">Requested <?= h($request['requested_at']) ?></p>
                        <?php if ($request['notes']): ?><p><?= nl2br(h($request['notes'])) ?></p><?php endif; ?>
                        <ul>
                            <?php foreach ($returnItemsByRequest[(int) $request['id']] ?? [] as $returnItem): ?>
                                <li><?= h($returnItem['name']) ?>: <?= number_format((float) $returnItem['quantity'], 2) ?> <?= h($returnItem['unit']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($request['staff_notes']): ?><p><strong>Staff note:</strong> <?= nl2br(h($request['staff_notes'])) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="payment-history">
        <h2>Payment history</h2>
        <?php if (!$paymentHistory): ?>
            <p class="muted">No payment events have been recorded.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Event</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>Reference / recorded by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $event): ?>
                            <tr>
                                <td><?= h($event['created_at']) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $event['event_type']))) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', $event['payment_status']))) ?></td>
                                <td><?= money($event['amount']) ?></td>
                                <td>
                                    <?= h($event['payment_reference'] ?: $event['actor_name'] ?: '—') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
