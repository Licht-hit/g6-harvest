<?php
$pageTitle = $pageTitle ?? 'Vegetable System';
$pageClass = $pageClass ?? '';
$user = current_user();
$notice = take_flash();

// Buyers and farm staff should land on different starting pages.
$homePage = 'dashboard.php';
if ($user && $user['role'] === 'buyer') {
    $homePage = 'buyer/dashboard.php';
}
$currentPage = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');
$managementLinks = [
    ['label' => 'Dashboard', 'path' => 'dashboard.php', 'icon' => '▦'],
    ['label' => 'Vegetables', 'path' => 'modules/vegetables.php', 'icon' => '◉'],
    ['label' => 'Plantings', 'path' => 'modules/plantings.php', 'icon' => '♧'],
    ['label' => 'Harvests', 'path' => 'modules/harvests.php', 'icon' => '⌁'],
    ['label' => 'Inventory', 'path' => 'modules/inventory.php', 'icon' => '▤'],
    ['label' => 'Sales', 'path' => 'modules/sales.php', 'icon' => '↗'],
    ['label' => 'Quality', 'path' => 'modules/quality_inspection.php', 'icon' => '✓'],
    ['label' => 'Reports', 'path' => 'modules/reports.php', 'icon' => '▥'],
];
if ($user && $user['role'] === 'admin') {
    $managementLinks[] = ['label' => 'Activity', 'path' => 'modules/activity_logs.php', 'icon' => '◷'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | Harvest</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/style.css')) ?>">
    <script src="<?= h(app_url('assets/js/script.js')) ?>" defer></script>
</head>
<body class="<?= h($pageClass) ?>">
<header class="site-header">
    <a class="brand" href="<?= h(app_url($homePage)) ?>">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 32 32" fill="none">
                <path d="M25.8 5.2C16 5.5 8.3 8.8 8.3 17.1c0 3.2 2.2 5.4 5.1 5.4 8.8 0 11.6-8.8 12.4-17.3Z" fill="currentColor"/>
                <path d="M5.2 27c4.6-7.5 9.2-11 16.3-15.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </span>
        <span>Harvest<span class="brand-period">.</span></span>
    </a>
    <?php if ($user): ?>
        <button
            class="nav-toggle"
            type="button"
            aria-label="Open navigation"
            aria-expanded="false"
            aria-controls="primary-navigation"
            data-nav-toggle
        >
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav" id="primary-navigation" aria-label="Main navigation">
            <?php if ($user['role'] === 'buyer'): ?>
                <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= h(app_url('buyer/dashboard.php')) ?>" <?= $currentPage === 'dashboard.php' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">▦</span> Dashboard
                </a>
                <a class="<?= in_array($currentPage, ['index.php', 'product.php'], true) ? 'active' : '' ?>" href="<?= h(app_url('buyer/index.php')) ?>" <?= in_array($currentPage, ['index.php', 'product.php'], true) ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">⌂</span> Shop
                </a>
                <a class="<?= $currentPage === 'favorites.php' ? 'active' : '' ?>" href="<?= h(app_url('buyer/favorites.php')) ?>" <?= $currentPage === 'favorites.php' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">♡</span> Favorites
                </a>
                <a class="<?= in_array($currentPage, ['orders.php', 'order_details.php'], true) ? 'active' : '' ?>" href="<?= h(app_url('buyer/orders.php')) ?>" <?= in_array($currentPage, ['orders.php', 'order_details.php'], true) ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">▤</span> My orders
                </a>
                <a class="<?= $currentPage === 'cart.php' ? 'active' : '' ?>" href="<?= h(app_url('buyer/cart.php')) ?>" <?= $currentPage === 'cart.php' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">▱</span> Cart <span class="nav-count"><?= cart_count() ?></span>
                </a>
            <?php else: ?>
                <?php foreach ($managementLinks as $link): ?>
                    <?php $isActive = $currentPage === basename($link['path']); ?>
                    <a class="<?= $isActive ? 'active' : '' ?>" href="<?= h(app_url($link['path'])) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><?= h($link['icon']) ?></span>
                        <?= h($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
        <details class="account-menu">
            <summary>
                <span class="account-avatar" aria-hidden="true"><?= h(strtoupper(substr($user['name'], 0, 1))) ?></span>
                <span class="account-copy">
                    <strong><?= h($user['name']) ?></strong>
                    <small><?= h(ucfirst($user['role'])) ?></small>
                </span>
                <span class="account-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="account-dropdown">
                <div class="account-dropdown-user">
                    <strong><?= h($user['name']) ?></strong>
                    <small><?= h(ucfirst($user['role'])) ?></small>
                    <small class="account-dropdown-email"><?= h($user['email']) ?></small>
                </div>
                <form method="post" action="<?= h(app_url('auth/logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button class="account-logout" type="submit"><span aria-hidden="true">↪</span> Log out</button>
                </form>
            </div>
        </details>
    <?php else: ?>
        <nav class="guest-nav" aria-label="Account navigation">
            <a href="<?= h(app_url('auth/login.php')) ?>">Log in</a>
            <a class="guest-register" href="<?= h(app_url('auth/register.php')) ?>">Create account</a>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
    <?php if ($notice): ?>
        <div class="notice <?= h($notice['type']) ?>" role="<?= $notice['type'] === 'error' ? 'alert' : 'status' ?>">
            <?= h($notice['message']) ?>
        </div>
    <?php endif; ?>
