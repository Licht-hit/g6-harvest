<?php
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/order_returns.php';

$pageTitle = $pageTitle ?? 'Vegetable System';
$pageClass = $pageClass ?? '';
$user = current_user();
$styleVersion = filemtime(__DIR__ . '/../assets/css/style.css');
$scriptVersion = filemtime(__DIR__ . '/../assets/js/script.js');
$buyerLanguageScriptPath = __DIR__ . '/../assets/js/buyer-language.js';
$buyerLanguageScriptVersion = is_file($buyerLanguageScriptPath)
    ? filemtime($buyerLanguageScriptPath)
    : null;
$uiCssPath = __DIR__ . '/../assets/vendor/ui.css';
$uiScriptPath = __DIR__ . '/../assets/vendor/ui.js';
$uiVersion = is_file($uiCssPath) && is_file($uiScriptPath)
    ? max(filemtime($uiCssPath), filemtime($uiScriptPath))
    : null;
$pageClassNames = preg_split('/\s+/', trim($pageClass)) ?: [];
$isPublicHome = in_array('public-home-page', $pageClassNames, true);
$isOperationsUser = $user && in_array($user['role'], ['admin', 'staff', 'farmer'], true);
$unreadNotifications = $user ? unread_notification_count($db, (int) $user['id']) : 0;
$latestNotificationId = $user ? latest_notification_id($db, (int) $user['id']) : 0;
$pendingReturnCount = $isOperationsUser ? pending_order_return_count($db) : 0;
$notice = take_flash();

// Send guests, buyers, and farm staff to the appropriate starting page.
$homePage = 'index.php';
if ($user) {
    $homePage = dashboard_path_for_role($user['role']);
}
$currentPage = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');
$managementLinks = [
    ['label' => 'Dashboard', 'path' => 'dashboard.php', 'icon' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>'],
    ['label' => 'Vegetables', 'path' => 'modules/vegetables.php', 'icon' => '<path d="M20 4c-7 0-13 3-13 9a5 5 0 0 0 5 5c6 0 8-7 8-14Z"/><path d="M4 21c3-5 7-8 12-11"/>'],
    ['label' => 'Plantings', 'path' => 'modules/plantings.php', 'icon' => '<path d="M12 22v-8"/><path d="M12 14c-4 0-7-2-7-6 4 0 7 2 7 6Z"/><path d="M12 11c0-4 3-7 7-7 0 4-3 7-7 7Z"/>'],
    ['label' => 'Harvests', 'path' => 'modules/harvests.php', 'icon' => '<path d="M12 22V8"/><path d="M12 13 7 9"/><path d="m12 17 6-5"/><path d="M7 9c-2-2-2-4-1-6 3 1 4 3 4 6"/><path d="M18 12c2-2 2-4 1-6-3 1-4 3-4 6"/>'],
    ['label' => 'Inventory', 'path' => 'modules/inventory.php', 'icon' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 8 9 5 9-5"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>'],
    ['label' => 'Sales', 'path' => 'modules/sales.php', 'icon' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 7H6"/>'],
    ['label' => 'Returns', 'path' => 'modules/returns.php', 'icon' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>', 'badge_count' => $pendingReturnCount],
    ['label' => 'Quality', 'path' => 'modules/quality_inspection.php', 'icon' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/>'],
    ['label' => 'Reports', 'path' => 'modules/reports.php', 'icon' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>'],
];
if ($user && $user['role'] === 'admin') {
    $managementLinks[] = ['label' => 'Activity', 'path' => 'modules/activity_logs.php', 'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | Harvest</title>
    <?php if (!empty($pageDescription)): ?>
        <meta name="description" content="<?= h($pageDescription) ?>">
    <?php endif; ?>
    <?php if ($isPublicHome && $uiVersion !== null): ?>
        <link rel="stylesheet" href="<?= h(app_url('assets/vendor/ui.css?v=' . $uiVersion)) ?>">
        <script src="<?= h(app_url('assets/vendor/ui.js?v=' . $uiVersion)) ?>" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/style.css?v=' . $styleVersion)) ?>">
    <script src="<?= h(app_url('assets/js/script.js?v=' . $scriptVersion)) ?>" defer></script>
    <?php if ($user && $user['role'] === 'buyer' && $buyerLanguageScriptVersion !== null): ?>
        <script src="<?= h(app_url('assets/js/buyer-language.js?v=' . $buyerLanguageScriptVersion)) ?>" defer></script>
    <?php endif; ?>
</head>
<body
    class="<?= h(trim($pageClass . ($isOperationsUser ? ' operations-layout' : ''))) ?>"
    <?= $user && $user['role'] === 'buyer'
        ? 'data-order-status-url="' . h(app_url('buyer/order_status_updates.php')) . '"'
        : '' ?>
    <?= $user
        ? 'data-notifications-url="' . h(app_url('notifications.php?format=json')) . '" data-notifications-latest="' . $latestNotificationId . '" data-pending-return-count="' . $pendingReturnCount . '"'
        : '' ?>
>
<header class="site-header<?= $isOperationsUser ? ' operations-sidebar' : '' ?><?= $isPublicHome ? ' public-site-header' : '' ?>">
    <a class="brand" href="<?= h(app_url($isPublicHome ? 'index.php' : $homePage)) ?>">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 32 32" fill="none">
                <path d="M25.8 5.2C16 5.5 8.3 8.8 8.3 17.1c0 3.2 2.2 5.4 5.1 5.4 8.8 0 11.6-8.8 12.4-17.3Z" fill="currentColor"/>
                <path d="M5.2 27c4.6-7.5 9.2-11 16.3-15.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </span>
        <span>Harvest<span class="brand-period">.</span></span>
    </a>
    <?php if ($isPublicHome): ?>
        <button
            class="nav-toggle public-nav-toggle"
            type="button"
            aria-label="Open navigation"
            aria-expanded="false"
            aria-controls="primary-navigation"
            data-nav-toggle
        >
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav public-main-nav" id="primary-navigation" aria-label="Public navigation">
            <a href="#home" aria-current="page">Home</a>
            <a href="#about">About</a>
            <a href="#featured">Produce</a>
            <a href="#why-harvest">Why Harvest</a>
            <a href="#farming-process">Our process</a>
            <a href="#contact">Contact</a>
        </nav>
        <div class="public-header-actions">
            <?php if ($user): ?>
                <a class="public-header-login" href="<?= h(app_url($user['role'] === 'buyer' ? 'buyer/dashboard.php' : 'dashboard.php')) ?>">My dashboard</a>
                <a class="public-header-login" href="<?= h(app_url('notifications.php')) ?>">
                    Notifications <span class="nav-count" data-notification-badge data-unread-count="<?= $unreadNotifications ?>" <?= $unreadNotifications === 0 ? 'hidden' : '' ?>><?= $unreadNotifications ?></span>
                </a>
                <?php if ($user['role'] === 'buyer'): ?>
                    <a class="public-header-register" href="<?= h(app_url('buyer/favorites.php')) ?>">My favorites</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="public-header-login" href="<?= h(app_url('auth/login.php')) ?>">Log in</a>
                <a class="public-header-register" href="<?= h(app_url('auth/register.php')) ?>">Create account</a>
            <?php endif; ?>
        </div>
    <?php elseif ($user): ?>
        <button
            class="nav-toggle"
            type="button"
            aria-label="<?= $isOperationsUser ? 'Collapse navigation' : 'Open navigation' ?>"
            aria-expanded="<?= $isOperationsUser ? 'true' : 'false' ?>"
            aria-controls="primary-navigation"
            data-nav-toggle
        >
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav" id="primary-navigation" aria-label="<?= $isOperationsUser ? 'Farm operations' : 'Main navigation' ?>">
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
                <button
                    class="buyer-language-toggle"
                    type="button"
                    data-buyer-language-toggle
                    aria-label="Switch to Filipino"
                >Filipino</button>
            <?php else: ?>
                <?php foreach ($managementLinks as $link): ?>
                    <?php
                    $isActive = $currentPage === basename($link['path']);
                    $badgeCount = (int) ($link['badge_count'] ?? 0);
                    ?>
                    <a
                        class="<?= $isActive ? 'active' : '' ?>"
                        href="<?= h(app_url($link['path'])) ?>"
                        title="<?= h($link['label']) ?>"
                        <?= $badgeCount > 0 ? 'aria-label="' . h($link['label'] . ', ' . $badgeCount . ' pending') . '"' : '' ?>
                        <?= $isActive ? 'aria-current="page"' : '' ?>
                    >
                        <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $link['icon'] ?></svg></span>
                        <span class="nav-link-label"><?= h($link['label']) ?></span>
                        <?php if ($link['path'] === 'modules/returns.php'): ?>
                            <span class="nav-return-count" data-pending-return-badge <?= $badgeCount === 0 ? 'hidden' : '' ?>><?= $badgeCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
            <a class="<?= $currentPage === 'notifications.php' ? 'active' : '' ?>" href="<?= h(app_url('notifications.php')) ?>" <?= $currentPage === 'notifications.php' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">♧</span>
                <span class="nav-link-label">Notifications</span>
                <span class="nav-count" data-notification-badge data-unread-count="<?= $unreadNotifications ?>" <?= $unreadNotifications === 0 ? 'hidden' : '' ?>><?= $unreadNotifications ?></span>
            </a>
        </nav>
        <details class="account-menu">
            <summary aria-label="Account menu for <?= h($user['name']) ?>">
                <span class="account-avatar" aria-hidden="true"><?= h(strtoupper(substr($user['name'], 0, 1))) ?></span>
                <span class="account-copy">
                    <strong><?= h($user['name']) ?></strong>
                    <small><?= h($user['role'] === 'farmer' ? 'Farmer' : ucfirst($user['role'])) ?></small>
                </span>
                <span class="account-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="account-dropdown">
                <div class="account-dropdown-user">
                    <strong><?= h($user['name']) ?></strong>
                    <small><?= h($user['role'] === 'farmer' ? 'Farmer' : ucfirst($user['role'])) ?></small>
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
