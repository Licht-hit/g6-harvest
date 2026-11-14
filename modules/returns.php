<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_returns.php';
require_role('admin', 'staff', 'farmer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $nextStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $staffNotes = is_string($_POST['staff_notes'] ?? null) ? trim($_POST['staff_notes']) : null;
    if ($action !== 'update_return' || $requestId === false || $requestId < 1) {
        flash('error', 'Choose a valid return request action.');
        redirect(app_url('modules/returns.php'));
    }

    try {
        update_order_return_request(
            $db,
            $requestId,
            $nextStatus,
            $staffNotes,
            (int) current_user()['id']
        );
        flash('success', 'Return request updated.');
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    redirect(app_url('modules/returns.php'));
}

$orderFilter = is_string($_GET['order'] ?? null) ? trim($_GET['order']) : '';
$filterError = $orderFilter !== '' && !preg_match('/^VS-[A-F0-9]{10}$/', $orderFilter)
    ? 'Choose a valid order.'
    : '';
$requestSql = 'SELECT rr.*, buyer.name AS buyer_name, buyer.email AS buyer_email
     FROM return_requests AS rr
     JOIN users AS buyer ON buyer.id = rr.buyer_id';
if ($orderFilter !== '' && $filterError === '') {
    $requestSql .= ' WHERE rr.order_code = ?';
}
$requestSql .= ' ORDER BY rr.requested_at DESC, rr.id DESC LIMIT 500';
$requestStatement = $db->prepare($requestSql);
if ($orderFilter !== '' && $filterError === '') {
    $requestStatement->execute([$orderFilter]);
} else {
    $requestStatement->execute();
}
$requests = $filterError === '' ? $requestStatement->fetchAll() : [];
$itemsByRequest = [];
if ($requests) {
    $requestIds = array_map(static fn (array $request): int => (int) $request['id'], $requests);
    $requestPlaceholders = implode(',', array_fill(0, count($requestIds), '?'));
    $itemStatement = $db->prepare(
        "SELECT rri.return_request_id, v.name, v.unit, rri.quantity
         FROM return_request_items AS rri
         JOIN sales AS s ON s.id = rri.sales_id
         JOIN vegetables AS v ON v.id = s.vegetable_id
         WHERE rri.return_request_id IN ({$requestPlaceholders})
         ORDER BY v.name"
    );
    $itemStatement->execute($requestIds);
    foreach ($itemStatement->fetchAll() as $item) {
        $itemsByRequest[(int) $item['return_request_id']][] = $item;
    }
}

$pageTitle = 'Return requests';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div><p class="module-eyebrow">Customer service</p><h1>Return requests</h1><p>Review damaged or quality-issue reports and update their status.</p>
        <?php if ($orderFilter !== ''): ?><p class="muted">Order <?= h($orderFilter) ?> · <a href="<?= h(app_url('modules/returns.php')) ?>">Show all requests</a></p><?php endif; ?>
    </div>
</section>
<section class="panel section">
    <?php if ($filterError !== ''): ?>
        <div class="notice error" role="alert"><?= h($filterError) ?></div>
    <?php elseif (!$requests): ?>
        <p class="empty">No return requests.</p>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Order / requested</th>
                        <th>Buyer</th>
                        <th>Items</th>
                        <th>Reason</th>
                        <th>Status / staff notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $requestItems = $itemsByRequest[(int) $request['id']] ?? [];
                        $itemSummary = [];
                        foreach ($requestItems as $requestItem) {
                            $itemSummary[] = $requestItem['name'] . ' × '
                                . number_format((float) $requestItem['quantity'], 2)
                                . ' ' . $requestItem['unit'];
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= h($request['order_code']) ?></strong>
                                <small class="table-secondary"><?= h(date('M j, g:i A', strtotime($request['requested_at']))) ?></small>
                            </td>
                            <td>
                                <?= h($request['buyer_name']) ?>
                                <small class="table-secondary"><?= h($request['buyer_email']) ?></small>
                            </td>
                            <td><?= h(implode(', ', $itemSummary) ?: '—') ?></td>
                            <td>
                                <?= h($request['reason'] === 'damaged' ? 'Damaged item' : 'Quality issue') ?>
                                <?php if ($request['notes']): ?>
                                    <small class="table-secondary"><?= h($request['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= h($request['status']) ?>">
                                    <?= h(ucwords($request['status'])) ?>
                                </span>
                                <?php if ($request['staff_notes']): ?>
                                    <small class="table-secondary"><?= h($request['staff_notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="return-review-actions">
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <form method="post" class="return-review-action"
                                            data-confirm="Approve this request for manual handling? No refund or inventory changes will happen automatically.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_return">
                                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                            <input type="hidden" name="status" value="approved">
                                            <button type="submit">Approve</button>
                                        </form>
                                        <form method="post" class="return-review-action" data-return-review-prompt="reject">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_return">
                                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                            <input type="hidden" name="status" value="rejected">
                                            <input type="hidden" name="staff_notes" value="">
                                            <button type="submit" class="button danger">Reject</button>
                                        </form>
                                    <?php elseif ($request['status'] === 'approved'): ?>
                                        <form method="post" class="return-review-action"
                                            data-confirm="Confirm that any refund or payment adjustment and appropriate inventory update were handled manually.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_return">
                                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit">Mark complete</button>
                                        </form>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>