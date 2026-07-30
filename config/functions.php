<?php

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function is_admin(): bool
{
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'admin';
}

function relative_base(): string
{
    return strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false ? '../' : '';
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . relative_base() . 'login');
        exit;
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: ' . relative_base() . 'login');
        exit;
    }
}

// Modules a system user can be granted individual access to. Anything not
// listed here (settings, users, migrations) is super-admin only.
const PERMISSION_MODULES = [
    'categories' => 'Categories',
    'products'   => 'Products',
    'orders'     => 'Orders',
];

function is_super_admin(): bool
{
    return is_admin() && !empty($_SESSION['is_super']);
}

function has_permission(string $module): bool
{
    if (is_super_admin()) {
        return true;
    }
    if (!is_admin()) {
        return false;
    }

    static $cache = null;
    if ($cache === null) {
        global $conn;
        $cache = [];
        $userId = (int) $_SESSION['user_id'];
        $stmt = mysqli_prepare($conn, 'SELECT module FROM user_permissions WHERE user_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $cache[$row['module']] = true;
        }
    }

    return $cache[$module] ?? false;
}

// Gates a specific admin module (e.g. 'products') — accessible to super
// admins and to system users explicitly granted that module.
function require_permission(string $module): void
{
    require_admin();
    if (!has_permission($module)) {
        flash_set('danger', "You don't have permission to access that section.");
        redirect('dashboard');
    }
}

// Gates admin sections reserved for super admins only (settings, users, migrations).
function require_super(): void
{
    require_admin();
    if (!is_super_admin()) {
        flash_set('danger', "You don't have permission to access that section.");
        redirect('dashboard');
    }
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Strips everything but digits and a leading + from user-supplied phone input,
// so the same number typed with spaces/dashes always matches on lookup.
function normalize_phone(string $phone): string
{
    $phone = trim($phone);
    $plus = str_starts_with($phone, '+') ? '+' : '';
    return $plus . preg_replace('/\D/', '', $phone);
}

// Collapses whitespace and strips any HTML tags from free-text user input
// before it's stored, independent of the output-side e() escaping.
function clean_text(string $value, int $maxLength): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_substr($value, 0, $maxLength);
}

function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function get_or_create_cart(mysqli $conn, int $userId): int
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM carts WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    if ($row) {
        return (int) $row['id'];
    }

    $stmt = mysqli_prepare($conn, 'INSERT INTO carts (user_id) VALUES (?)');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);

    return (int) mysqli_insert_id($conn);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function notify_order_placed(mysqli $conn, int $orderId): void
{
    $message = 'New order #' . $orderId . ' placed.';

    $admins = mysqli_query($conn, "SELECT id FROM users WHERE role = 'admin'");
    $stmt = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, order_id, message) VALUES (?, ?, ?)');

    while ($admin = mysqli_fetch_assoc($admins)) {
        $adminId = (int) $admin['id'];
        mysqli_stmt_bind_param($stmt, 'iis', $adminId, $orderId, $message);
        mysqli_stmt_execute($stmt);
    }
}

function get_setting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = mysqli_prepare($conn, 'SELECT `value` FROM settings WHERE `key` = ?');
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();

    return $row ? $row['value'] : $default;
}

function set_setting(mysqli $conn, string $key, string $value): void
{
    $stmt = mysqli_prepare($conn, '
        INSERT INTO settings (`key`, `value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
    mysqli_stmt_execute($stmt);
}

function cart_total_amount(mysqli $conn, int $userId): float
{
    $stmt = mysqli_prepare($conn, '
        SELECT COALESCE(SUM(ci.quantity * p.price), 0) AS total
        FROM cart_items ci
        JOIN carts c ON c.id = ci.cart_id
        JOIN products p ON p.id = ci.product_id
        WHERE c.user_id = ?
    ');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    return (float) ($row['total'] ?? 0);
}
