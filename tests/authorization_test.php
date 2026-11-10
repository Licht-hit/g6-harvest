<?php
require_once __DIR__ . '/../includes/functions.php';

function expect_authorization(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect_authorization(
    dashboard_path_for_role('buyer') === 'buyer/dashboard.php',
    'Buyers should land on their own dashboard.'
);
expect_authorization(
    dashboard_path_for_role('staff') === 'dashboard.php',
    'Staff should land on the operations dashboard.'
);
expect_authorization(
    dashboard_path_for_role('admin') === 'dashboard.php',
    'Admins should land on the operations dashboard.'
);

expect_authorization(
    user_has_role(['role' => 'buyer'], 'buyer'),
    'A buyer should match the buyer role.'
);
expect_authorization(
    !user_has_role(['role' => 'buyer'], 'staff', 'admin'),
    'A buyer must not match staff or admin roles.'
);
expect_authorization(
    user_has_role(['role' => 'staff'], 'staff', 'admin'),
    'Staff should match their permitted operations role.'
);
expect_authorization(
    !user_has_role(['role' => 'admin'], 'staff'),
    'An admin must not be implicitly treated as staff.'
);
expect_authorization(
    !user_has_role([], 'buyer'),
    'A missing user role must not grant access.'
);

$unknownRoleRejected = false;
try {
    dashboard_path_for_role('unknown');
} catch (RuntimeException $exception) {
    $unknownRoleRejected = true;
}
expect_authorization($unknownRoleRejected, 'Unsupported roles must not receive a dashboard route.');

echo "Role routing and authorization checks passed.\n";
