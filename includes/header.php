<?php
global $conn;

$cartTotal = is_logged_in() ? cart_total_amount($conn, (int) $_SESSION['user_id']) : 0;
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= $base ?>assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $base ?>assets/js/app.js"></script>
    <?php if (is_admin()): ?>
        <script>window.__ADMIN_NOTIFY_BASE = <?= json_encode($isAdminPage ? '' : $base . 'admin/') ?>;</script>
        <script src="<?= $base ?>assets/js/admin-notify.js" defer></script>
    <?php endif; ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= $base ?>index">Mpahira</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item"><a class="nav-link <?= !$isAdminPage && $current === 'index' ? 'active' : '' ?>" href="<?= $base ?>index">Shop</a></li>
                <?php if (is_logged_in()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $current === 'cart' ? 'active' : '' ?>" href="<?= $base ?>cart">Cart <span id="cartBadge" class="badge bg-primary rounded-pill <?= $cartTotal > 0 ? '' : 'd-none' ?>"><?= number_format($cartTotal) ?></span></a>
                    </li>
                    <li class="nav-item"><a class="nav-link <?= !$isAdminPage && in_array($current, ['orders', 'order'], true) ? 'active' : '' ?>" href="<?= $base ?>orders">My Orders</a></li>
                    <?php if (is_admin()): ?>
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
