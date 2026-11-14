<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/order_returns.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit('Sign in to view notifications.');
}
$userId = (int) $user['id'];
$format = is_string($_GET['format'] ?? null) ? $_GET['format'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $format === 'json') {
    $afterId = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($afterId === false || $afterId < 0) {
        http_response_code(400);
        exit;
    }
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $statement = $db->prepare(
        'SELECT id, type, message, target_path, created_at
         FROM notifications
         WHERE user_id = ? AND id > ?
         ORDER BY id
         LIMIT 50'
    );
    $statement->execute([$userId, $afterId]);
    $notifications = $statement->fetchAll();
    foreach ($notifications as &$notification) {
        $notification['url'] = app_url($notification['target_path']);
    }
    unset($notification);
    $latestId = count($notifications) === 50
        ? (int) $notifications[array_key_last($notifications)]['id']
        : latest_notification_id($db, $userId);
    $pendingReturns = in_array($user['role'], ['admin', 'staff', 'farmer'], true)
        ? pending_order_return_count($db)
        : null;
    echo json_encode([
        'notifications' => $notifications,
        'unread_count' => unread_notification_count($db, $userId),
        'latest_id' => $latestId,
        'pending_return_count' => $pendingReturns,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'mark_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($notificationId && $notificationId > 0) {
            $statement = $db->prepare(
                'UPDATE notifications
                 SET read_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND user_id = ? AND read_at IS NULL'
            );
            $statement->execute([$notificationId, $userId]);
        } else {
            flash('error', 'Choose a valid notification.');
        }
        redirect(app_url('notifications.php'));
    }
    if ($action === 'mark_all_read') {
        $statement = $db->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND read_at IS NULL'
        );
        $statement->execute([$userId]);
        flash('success', 'All notifications marked as read.');
        redirect(app_url('notifications.php'));
    }

    http_response_code(400);
    exit('Choose a valid notification action.');
}

$statement = $db->prepare(
    'SELECT id, type, message, target_path, order_code, read_at, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY id DESC
     LIMIT 100'
);
$statement->execute([$userId]);
$notifications = $statement->fetchAll();
$pageTitle = 'Notifications';
require __DIR__ . '/includes/header.php';
?>
<section class="module-heading">
    <div><p class="module-eyebrow">Account updates</p><h1>Notifications</h1><p>Order and return-request updates for your account.</p></div>
    <?php if (unread_notification_count($db, $userId) > 0): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="button secondary">Mark all as read</button>
        </form>
    <?php endif; ?>
</section>
<section class="panel section">
    <?php if (!$notifications): ?>
        <p class="empty">You do not have any notifications yet.</p>
    <?php else: ?>
        <ul class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <li class="<?= $notification['read_at'] === null ? 'is-unread' : '' ?>">
                    <a href="<?= h(app_url($notification['target_path'])) ?>">
                        <strong><?= h($notification['message']) ?></strong>
                        <small><?= h($notification['created_at']) ?></small>
                    </a>
                    <?php if ($notification['read_at'] === null): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                            <button type="submit" class="button secondary">Mark read</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>