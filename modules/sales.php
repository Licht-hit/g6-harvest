<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_role('admin', 'staff');

$validPaymentStatuses = ['', 'pending_payment', 'paid', 'failed', 'refunded'];
$orderStatusLabels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready_for_delivery' => 'Ready for Delivery',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
];
$nextOrderStatuses = [
    'pending' => ['confirmed'],
    'confirmed' => ['preparing'],
    'preparing' => ['ready_for_delivery'],
    'ready_for_delivery' => ['out_for_delivery'],
    'out_for_delivery' => ['delivered'],
    'delivered' => [],
    'cancelled' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $orderCode = is_string($_POST['order_code'] ?? null) ? trim($_POST['order_code']) : '';
    if (!preg_match('/^VS-[A-F0-9]{10}$/', $orderCode)) {
        flash('error', 'Choose a valid order.');
        redirect(app_url('modules/sales.php'));
    }

    try {
        $db->beginTransaction();
        $orderStatement = $db->prepare(
            'SELECT * FROM orders WHERE order_code = ? FOR UPDATE'
        );
        $orderStatement->execute([$orderCode]);
        $order = $orderStatement->fetch();
        if (!$order) {
            throw new RuntimeException('That order could not be found.');
        }

        if ($action === 'update_order_status') {
            $nextStatus = $_POST['order_status'] ?? '';
            $allowedNextStatuses = $nextOrderStatuses[$order['order_status']] ?? [];
            $canCancel = $order['payment_status'] !== 'paid'
                && $order['payment_status'] !== 'refunded'
                && $order['order_status'] !== 'delivered'
                && (
                    $order['payment_method'] !== 'online'
                    || (
                        !empty($order['gateway_session_id'])
                        && paymongo_is_configured()
                    )
                );

            if ($canCancel && $order['order_status'] !== 'cancelled') {
                $allowedNextStatuses[] = 'cancelled';
            }
            if (!in_array($nextStatus, $allowedNextStatuses, true)) {
                throw new RuntimeException('That order status change is not allowed.');
            }

            if ($nextStatus === 'cancelled' && $order['payment_method'] === 'online') {
                paymongo_expire_checkout_session($order['gateway_session_id']);
                $expireHistory = $db->prepare(
                    "INSERT INTO payment_history (
                        order_code,
                        payment_method,
                        event_type,
                        payment_status,
                        amount,
                        payment_reference,
                        actor_id,
                        details
                    ) VALUES (?, 'online', 'checkout_session_expired', 'failed', ?, ?, ?, ?)"
                );
                $expireHistory->execute([
                    $orderCode,
                    $order['final_total'],
                    $order['gateway_session_id'],
                    (int) current_user()['id'],
                    'PayMongo confirmed the pending checkout session was expired before cancellation.',
                ]);
                $db->prepare(
                    "UPDATE orders
                     SET payment_status = 'failed'
                     WHERE order_code = ? AND payment_status = 'pending_payment'"
                )->execute([$orderCode]);
            }

            $updateOrder = $db->prepare(
                'UPDATE orders SET order_status = ? WHERE order_code = ?'
            );
            $updateOrder->execute([$nextStatus, $orderCode]);
            if ($nextStatus === 'cancelled') {
                $itemStatement = $db->prepare(
                    'SELECT vegetable_id, quantity FROM sales WHERE order_code = ?'
                );
                $itemStatement->execute([$orderCode]);
                $restock = $db->prepare(
                    'UPDATE inventory SET quantity = quantity + ? WHERE vegetable_id = ?'
                );
                foreach ($itemStatement->fetchAll() as $item) {
                    $restock->execute([$item['quantity'], $item['vegetable_id']]);
                    if ($restock->rowCount() !== 1) {
                        throw new RuntimeException('Could not restore the cancelled order stock.');
                    }
                }
                $db->prepare(
                    "UPDATE sales SET status = 'cancelled' WHERE order_code = ?"
                )->execute([$orderCode]);
            }

            log_activity(
                'order_status_updated',
                'order',
                (int) $order['id'],
                'Order ' . $orderCode . ' changed to ' . $nextStatus . '.'
            );
            $db->commit();
            flash('success', 'Order status updated.');
        } elseif ($action === 'confirm_cod_collection') {
            if (
                $order['payment_method'] !== 'cod'
                || $order['payment_status'] !== 'pending_payment'
                || !in_array($order['order_status'], ['out_for_delivery', 'delivered'], true)
            ) {
                throw new RuntimeException(
                    'COD can be confirmed only for an out-for-delivery or delivered order with pending payment.'
                );
            }

            $reference = 'COD-' . $orderCode;
            $updatePayment = $db->prepare(
                "UPDATE orders
                 SET payment_status = 'paid',
                     payment_reference = ?,
                     payment_confirmed_by = ?,
                     payment_confirmed_at = CURRENT_TIMESTAMP
                 WHERE order_code = ? AND payment_status = 'pending_payment'"
            );
            $updatePayment->execute([$reference, (int) current_user()['id'], $orderCode]);
            if ($updatePayment->rowCount() !== 1) {
                throw new RuntimeException('This payment has already been updated.');
            }
            $db->prepare(
                "UPDATE sales SET status = 'paid' WHERE order_code = ?"
            )->execute([$orderCode]);
            $history = $db->prepare(
                "INSERT INTO payment_history (
                    order_code,
                    payment_method,
                    event_type,
                    payment_status,
                    amount,
                    payment_reference,
                    actor_id,
                    details
                ) VALUES (?, 'cod', 'cash_collected', 'paid', ?, ?, ?, ?)"
            );
            $history->execute([
                $orderCode,
                $order['final_total'],
                $reference,
                (int) current_user()['id'],
                'Cash collection confirmed by authorized staff.',
            ]);
            log_activity(
                'cod_payment_collected',
                'order',
                (int) $order['id'],
                'COD collection confirmed for order ' . $orderCode . '.'
            );
            $db->commit();
            flash('success', 'Cash collection recorded as paid.');
        } else {
            throw new RuntimeException('Choose a valid order action.');
        }
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
        } else {
            throw $exception;
        }
    }

    redirect(app_url('modules/sales.php'));
}

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$paymentStatus = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : '';
$filterError = '';

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
} elseif (!in_array($paymentStatus, $validPaymentStatuses, true)) {
    $filterError = 'Choose a valid payment status.';
}

$conditions = [];
$parameters = [];
if ($from !== '') {
    $conditions[] = 'o.created_at >= ?';
    $parameters[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $conditions[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $parameters[] = $to;
}
if ($paymentStatus !== '') {
    $conditions[] = 'o.payment_status = ?';
    $parameters[] = $paymentStatus;
}
if ($search !== '') {
    $conditions[] = '(o.order_code LIKE ? OR u.name LIKE ? OR EXISTS (
        SELECT 1
        FROM sales AS sx
        JOIN vegetables AS vx ON vx.id = sx.vegetable_id
        WHERE sx.order_code = o.order_code AND vx.name LIKE ?
    ))';
    $searchTerm = '%' . $search . '%';
    array_push($parameters, $searchTerm, $searchTerm, $searchTerm);
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$summary = [
    'revenue' => 0,
    'today' => 0,
    'transactions' => 0,
    'subtotal' => 0,
    'discounts' => 0,
];
$orders = [];
$itemsByOrder = [];
$historyByOrder = [];
$dailySales = [];
$matchingOrders = 0;

if ($filterError === '') {
    $summaryStatement = $db->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN o.payment_status = \'paid\' THEN o.final_total ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(
                CASE WHEN o.payment_status = \'paid\' AND DATE(o.created_at) = CURDATE()
                    THEN o.final_total ELSE 0 END
            ), 0) AS today,
            COUNT(*) AS transactions,
            COALESCE(SUM(o.subtotal), 0) AS subtotal,
            COALESCE(SUM(o.discount_amount), 0) AS discounts
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         ' . $whereSql
    );
    $summaryStatement->execute($parameters);
    $summary = $summaryStatement->fetch();

    $countStatement = $db->prepare(
        'SELECT COUNT(*)
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         ' . $whereSql
    );
    $countStatement->execute($parameters);
    $matchingOrders = (int) $countStatement->fetchColumn();

    $orderStatement = $db->prepare(
        'SELECT o.*, u.name AS buyer
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         ' . $whereSql . '
         ORDER BY o.created_at DESC
         LIMIT 500'
    );
    $orderStatement->execute($parameters);
    $orders = $orderStatement->fetchAll();

    if ($orders) {
        $orderCodes = array_column($orders, 'order_code');
        $orderPlaceholders = implode(',', array_fill(0, count($orderCodes), '?'));
        $itemsStatement = $db->prepare(
            "SELECT s.order_code, s.quantity, s.unit_price, s.total, v.name AS vegetable, v.unit
             FROM sales AS s
             JOIN vegetables AS v ON v.id = s.vegetable_id
             WHERE s.order_code IN ({$orderPlaceholders})
             ORDER BY v.name"
        );
        $itemsStatement->execute($orderCodes);
        foreach ($itemsStatement->fetchAll() as $item) {
            $itemsByOrder[$item['order_code']][] = $item;
        }

        $historyStatement = $db->prepare(
            "SELECT ph.*, u.name AS actor_name
             FROM payment_history AS ph
             LEFT JOIN users AS u ON u.id = ph.actor_id
             WHERE ph.order_code IN ({$orderPlaceholders})
             ORDER BY ph.created_at DESC"
        );
        $historyStatement->execute($orderCodes);
        foreach ($historyStatement->fetchAll() as $event) {
            $historyByOrder[$event['order_code']][] = $event;
        }
    }

    $dailyStatement = $db->prepare(
        'SELECT DATE(o.created_at) AS sale_day, SUM(o.final_total) AS revenue
         FROM orders AS o
         JOIN users AS u ON u.id = o.buyer_id
         ' . ($whereSql ? $whereSql . ' AND ' : 'WHERE ')
            . "o.payment_status = 'paid'
         GROUP BY DATE(o.created_at)
         ORDER BY sale_day"
    );
    $dailyStatement->execute($parameters);
    $dailySales = $dailyStatement->fetchAll();
}

$pageTitle = 'Sales and orders';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Farm performance</p>
        <h1>Sales and orders</h1>
        <p>Review order totals, payment status, delivery progress, and payment history.</p>
    </div>
</section>

<section class="module-stat-grid" aria-label="Sales summary">
    <article class="module-stat-card">
        <span>Total paid revenue</span>
        <strong><?= money($summary['revenue']) ?></strong>
        <small>Final order totals with paid status</small>
    </article>
    <article class="module-stat-card">
        <span>Paid today</span>
        <strong><?= money($summary['today']) ?></strong>
        <small>Paid orders today within these filters</small>
    </article>
    <article class="module-stat-card">
        <span>Orders</span>
        <strong><?= number_format((int) $summary['transactions']) ?></strong>
        <small><?= money($summary['subtotal']) ?> before discounts</small>
    </article>
    <article class="module-stat-card">
        <span>Discounts given</span>
        <strong><?= money($summary['discounts']) ?></strong>
        <small>Bulk discounts on matching orders</small>
    </article>
</section>

<section class="panel section module-filter-panel">
    <form method="get" class="module-filter-form">
        <div class="field">
            <label for="from">Start date</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div class="field">
            <label for="to">End date</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div class="field module-search-field">
            <label for="q">Search orders</label>
            <input id="q" name="q" value="<?= h($search) ?>" maxlength="100">
        </div>
        <div class="field">
            <label for="status">Payment status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (array_slice($validPaymentStatuses, 1) as $statusOption): ?>
                    <option value="<?= h($statusOption) ?>" <?= $paymentStatus === $statusOption ? 'selected' : '' ?>>
                        <?= h(ucwords(str_replace('_', ' ', $statusOption))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="module-filter-actions">
            <button type="submit">Apply filters</button>
            <a class="button secondary" href="<?= h(app_url('modules/sales.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<section class="panel section module-chart-panel">
    <div class="module-table-heading">
        <div>
            <h2>Paid sales performance</h2>
            <p class="muted">Paid final totals by order date.</p>
        </div>
    </div>
    <?php if ($dailySales): ?>
        <div class="module-chart-wrap">
            <canvas
                class="dashboard-chart"
                data-chart="line"
                data-labels="<?= h(json_encode(
                    array_map(
                        static fn (array $row): string => date('M j', strtotime($row['sale_day'])),
                        $dailySales
                    ),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                )) ?>"
                data-values="<?= h(json_encode(
                    array_map(static fn (array $row): float => (float) $row['revenue'], $dailySales),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                )) ?>"
                aria-label="Daily paid sales revenue"
                role="img"
            ></canvas>
        </div>
    <?php else: ?>
        <p class="muted">Paid revenue will appear here after payment is confirmed.</p>
    <?php endif; ?>
</section>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Orders</h2>
            <p class="muted"><?= number_format($matchingOrders) ?> matching order(s)</p>
        </div>
        <?php if ($matchingOrders > 500): ?>
            <span class="module-record-range">Showing the latest 500 orders</span>
        <?php endif; ?>
    </div>

    <?php if ($filterError !== ''): ?>
        <div class="notice error">Correct the filters to view orders.</div>
    <?php elseif (!$orders): ?>
        <p class="empty">No orders match these filters.</p>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table sales-table">
                <thead>
                    <tr>
                        <th>Order / buyer</th>
                        <th>Items</th>
                        <th>Subtotal / discount / total</th>
                        <th>Payment</th>
                        <th>Delivery</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $orderItems = $itemsByOrder[$order['order_code']] ?? [];
                        $canCancel = $order['payment_status'] !== 'paid'
                            && $order['payment_status'] !== 'refunded'
                            && $order['order_status'] !== 'delivered'
                            && (
                                $order['payment_method'] !== 'online'
                                || (
                                    !empty($order['gateway_session_id'])
                                    && paymongo_is_configured()
                                )
                            );
                        $availableNextStatuses = $nextOrderStatuses[$order['order_status']] ?? [];
                        if ($canCancel && $order['order_status'] !== 'cancelled') {
                            $availableNextStatuses[] = 'cancelled';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= h($order['order_code']) ?></strong><br>
                                <?= h($order['buyer']) ?><br>
                                <small><?= h($order['created_at']) ?></small>
                            </td>
                            <td>
                                <details class="transaction-details">
                                    <summary><?= count($orderItems) ?> item(s)</summary>
                                    <ul>
                                        <?php foreach ($orderItems as $item): ?>
                                            <li>
                                                <?= h($item['vegetable']) ?> ·
                                                <?= number_format((float) $item['quantity'], 2) ?>
                                                <?= h($item['unit']) ?> · <?= money($item['total']) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </td>
                            <td>
                                <?= money($order['subtotal']) ?><br>
                                <small>
                                    <?= number_format((float) $order['eligible_quantity'], 2) ?> units ·
                                    <?= number_format((float) $order['discount_percentage'], 0) ?>% discount:
                                    -<?= money($order['discount_amount']) ?>
                                </small><br>
                                <strong><?= money($order['final_total']) ?></strong>
                            </td>
                            <td>
                                <?= h(strtoupper($order['payment_method'])) ?><br>
                                <span class="status-badge status-<?= h($order['payment_status']) ?>">
                                    <?= h(ucwords(str_replace('_', ' ', $order['payment_status']))) ?>
                                </span><br>
                                <?php if ($order['payment_reference']): ?>
                                    <small>Ref: <?= h($order['payment_reference']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($historyByOrder[$order['order_code']])): ?>
                                    <details class="transaction-details">
                                        <summary>Payment history</summary>
                                        <ul>
                                            <?php foreach ($historyByOrder[$order['order_code']] as $event): ?>
                                                <li>
                                                    <?= h($event['created_at']) ?> ·
                                                    <?= h(ucwords(str_replace('_', ' ', $event['event_type']))) ?> ·
                                                    <?= h(ucwords(str_replace('_', ' ', $event['payment_status']))) ?>
                                                    <?php if ($event['actor_name']): ?>
                                                        · <?= h($event['actor_name']) ?>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= h($order['order_status']) ?>">
                                    <?= h($orderStatusLabels[$order['order_status']] ?? $order['order_status']) ?>
                                </span><br>
                                <small><?= h($order['delivery_address']) ?></small>
                            </td>
                            <td>
                                <?php if ($availableNextStatuses): ?>
                                    <form method="post" class="order-action-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_order_status">
                                        <input type="hidden" name="order_code" value="<?= h($order['order_code']) ?>">
                                        <label class="sr-only" for="status-<?= h($order['order_code']) ?>">
                                            Update order status
                                        </label>
                                        <select id="status-<?= h($order['order_code']) ?>" name="order_status" required>
                                            <option value="">Update delivery status</option>
                                            <?php foreach ($availableNextStatuses as $nextStatus): ?>
                                                <option value="<?= h($nextStatus) ?>">
                                                    <?= h($orderStatusLabels[$nextStatus]) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit">Save</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (
                                    $order['payment_method'] === 'cod'
                                    && $order['payment_status'] === 'pending_payment'
                                    && in_array($order['order_status'], ['out_for_delivery', 'delivered'], true)
                                ): ?>
                                    <form method="post" class="order-action-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="confirm_cod_collection">
                                        <input type="hidden" name="order_code" value="<?= h($order['order_code']) ?>">
                                        <button type="submit">Confirm cash received</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
