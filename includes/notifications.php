<?php

function create_notification_for_user(
    MySQLiDatabase $db,
    int $userId,
    string $type,
    string $message,
    string $targetPath,
    ?string $orderCode = null
): void {
    if (
        $userId < 1
        || !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type)
        || trim($message) === ''
        || strlen($message) > 255
        || $targetPath === ''
        || str_starts_with($targetPath, '/')
        || str_contains($targetPath, '://')
    ) {
        throw new RuntimeException('Notification details are invalid.');
    }

    $statement = $db->prepare(
        'INSERT INTO notifications (user_id, type, message, target_path, order_code)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$userId, $type, $message, $targetPath, $orderCode]);
}

function create_notifications_for_roles(
    MySQLiDatabase $db,
    array $roles,
    string $type,
    string $message,
    string $targetPath,
    ?string $orderCode = null
): void {
    $roles = array_values(array_intersect(['admin', 'staff', 'farmer'], $roles));
    if (!$roles) {
        return;
    }
    if (
        !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type)
        || trim($message) === ''
        || strlen($message) > 255
        || $targetPath === ''
        || str_starts_with($targetPath, '/')
        || str_contains($targetPath, '://')
    ) {
        throw new RuntimeException('Notification details are invalid.');
    }

    $rolePlaceholders = implode(', ', array_fill(0, count($roles), '?'));
    $statement = $db->prepare(
        'INSERT INTO notifications (user_id, type, message, target_path, order_code)
         SELECT id, ?, ?, ?, ? FROM users WHERE role IN (' . $rolePlaceholders . ')'
    );
    $statement->execute(array_merge([$type, $message, $targetPath, $orderCode], $roles));
}

function unread_notification_count(MySQLiDatabase $db, int $userId): int
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
    );
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
}

function latest_notification_id(MySQLiDatabase $db, int $userId): int
{
    $statement = $db->prepare(
        'SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?'
    );
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
}
