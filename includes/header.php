<?php
$pageTitle = $pageTitle ?? 'Vegetable System';
$user = current_user();
$notice = take_flash();

// Buyers and farm staff should land on different starting pages.
$homePage = 'dashboard.php';
if ($user && $user['role'] === 'buyer') {
    $homePage = 'buyer/index.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | Vegetable System</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/style.css')) ?>">
    <script src="<?= h(app_url('assets/js/script.js')) ?>" defer></script>
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= h(app_url($homePage)) ?>">Harvest</a>
    <?php if ($user): ?>
        <nav class="main-nav" aria-label="Main navigation">
            <?php if ($user['role'] === 'buyer'): ?>
                <a href="<?= h(app_url('buyer/index.php')) ?>">Shop</a>
                <a href="<?= h(app_url('buyer/orders.php')) ?>">My orders</a>
                <a href="<?= h(app_url('buyer/cart.php')) ?>">Cart (<?= cart_count() ?>)</a>
            <?php else: ?>
                <a href="<?= h(app_url('dashboard.php')) ?>">Dashboard</a>
                <a href="<?= h(app_url('modules/vegetables.php')) ?>">Vegetables</a>
                <a href="<?= h(app_url('modules/plantings.php')) ?>">Plantings</a>
                <a href="<?= h(app_url('modules/harvests.php')) ?>">Harvests</a>
                <a href="<?= h(app_url('modules/inventory.php')) ?>">Inventory</a>
                <a href="<?= h(app_url('modules/sales.php')) ?>">Sales</a>
                <a href="<?= h(app_url('modules/quality_inspection.php')) ?>">Quality</a>
                <a href="<?= h(app_url('modules/reports.php')) ?>">Reports</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="<?= h(app_url('modules/activity_logs.php')) ?>">Activity</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="account">
            <span><?= h($user['name']) ?> · <?= h(ucfirst($user['role'])) ?></span>
            <form method="post" action="<?= h(app_url('auth/logout.php')) ?>">
                <?= csrf_field() ?>
                <button class="link-button" type="submit">Log out</button>
            </form>
        </div>
    <?php else: ?>
        <nav class="main-nav">
            <a href="<?= h(app_url('auth/login.php')) ?>">Log in</a>
            <a href="<?= h(app_url('auth/register.php')) ?>">Register</a>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
    <?php if ($notice): ?>
        <div class="notice <?= h($notice['type']) ?>" role="status">
            <?= h($notice['message']) ?>
        </div>
    <?php endif; ?>
