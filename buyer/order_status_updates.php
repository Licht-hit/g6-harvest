<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$statement = $db->prepare(
    'SELECT order_code, payment_status, order_status, delivered_at
     FROM orders
     WHERE buyer_id = ?
     ORDER BY created_at DESC'
);
$statement->execute([(int) current_user()['id']]);

echo json_encode(
    ['orders' => $statement->fetchAll()],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
