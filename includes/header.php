<?php
global $conn;

$cartTotal = is_logged_in() ? cart_badge_value($conn, (int) $_SESSION['user_id']) : 0;
$base = relative_base();
$isAdminPage = strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false;
$current = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - Mpahira' : 'Mpahira' ?></title>
    <link rel="icon" type="image/png" href="<?= $base ?>assets/images/mpahira_logo.png">
    <link rel="manifest" href="<?= $base ?>manifest.json">
    <link rel="apple-touch-icon" href="<?= $base ?>assets/images/apple-touch-icon.png">
    <meta name="theme-color" content="#2f9e44">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Mpahira">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= $base ?>assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $base ?>assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
    <?php if (is_logged_in()): ?>
        <script>
            window.__ADMIN_NOTIFY_BASE = <?= json_encode($isAdminPage ? '' : $base . 'admin/') ?>;
            window.__NOTIFY_ORDER_BASE = <?= json_encode(is_admin() ? ($isAdminPage ? '' : $base . 'admin/') : $base) ?>;
            window.__SITE_BASE = <?= json_encode($base) ?>;
        </script>
        <script src="<?= $base ?>assets/js/admin-notify.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-notify.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container">
        <a class="navbar-brand" href="<?= $base ?>shop">
            <img src="<?= $base ?>assets/images/mpahira_logo.png" alt="Mpahira" class="navbar-logo">
        </a>
        <?php if (is_logged_in()): ?>
            <a href="<?= $base ?>cart" class="header-cart-mobile d-lg-none" title="Cart">
                &#128722;
                <span class="cart-badge badge bg-primary rounded-pill <?= $cartTotal > 0 ? '' : 'd-none' ?>"><?= number_format($cartTotal) ?></span>
            </a>
        <?php endif; ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <?php if (!$isAdminPage && $current === 'shop'): ?>
            <form class="d-flex gap-2 header-search-form" role="search" action="<?= $base ?>shop" method="get" id="headerSearchForm">
                <select name="category" id="headerCategorySelect" class="form-select d-none d-lg-block" style="max-width:180px;">
                    <option value="0">All categories</option>
                    <?php if (isset($categories)): while ($cat = mysqli_fetch_assoc($categories)): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= (isset($categoryId) && $categoryId === (int) $cat['id']) ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endwhile; endif; ?>
                </select>
                <input type="search" name="q" id="headerSearchInput" class="form-control" placeholder="Search products..." value="<?= isset($search) ? e($search) : '' ?>" autocomplete="off">
            </form>
        <?php endif; ?>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item"><a class="nav-link <?= !$isAdminPage && $current === 'shop' ? 'active' : '' ?>" href="<?= $base ?>shop">Shop</a></li>
                <?php if (is_logged_in()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $current === 'cart' ? 'active' : '' ?>" href="<?= $base ?>cart">Cart <span class="cart-badge badge bg-primary rounded-pill <?= $cartTotal > 0 ? '' : 'd-none' ?>"><?= number_format($cartTotal) ?></span></a>
                    </li>
                    <li class="nav-item"><a class="nav-link <?= !$isAdminPage && in_array($current, ['orders', 'order'], true) ? 'active' : '' ?>" href="<?= $base ?>orders">My Orders</a></li>
                    <li class="nav-item nav-notif-wrap" id="navNotifWrap">
                        <button type="button" class="nav-link nav-notif" id="navNotifBell" title="Notifications">
                            &#128276;
                            <span class="nav-notif-badge" id="navNotifBadge" style="display:none;">0</span>
                        </button>
                        <div class="nav-notif-panel" id="navNotifPanel">
                            <div class="nav-notif-panel-hdr">Notifications</div>
                            <div class="nav-notif-list" id="navNotifList">
                                <div class="nav-notif-empty">No notifications</div>
                            </div>
                        </div>
                    </li>
                    <?php if (is_admin()): ?>
                        <li class="nav-item"><a class="nav-link <?= $isAdminPage ? 'active' : '' ?>" href="<?= $base ?>admin/dashboard">Admin</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="<?= $base ?>logout">Logout (<?= e($_SESSION['name']) ?>)</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link <?= $current === 'login' ? 'active' : '' ?>" href="<?= $base ?>login">Login</a></li>
                    <li class="nav-item"><a class="nav-link <?= $current === 'register' ? 'active' : '' ?>" href="<?= $base ?>register">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="container py-4">
