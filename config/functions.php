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
    'categories'      => 'Categories',
    'products'        => 'Products',
    'orders'          => 'Orders',
    'assign_incharge' => 'Assign Order Incharge',
    'edit_orders'     => 'Edit Pending Order Items',
    'reveal_price'    => 'Reveal Price to Customer',
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

// Turns free-text user search input into a MySQL FULLTEXT boolean-mode query: each
// word becomes a mandatory prefix match (+word*), so multi-word searches AND together
// instead of requiring the exact phrase as a literal substring. Words under 2 chars are
// dropped (InnoDB's default innodb_ft_min_token_size ignores them anyway); returns '' if
// nothing usable is left, so callers can skip the MATCH clause entirely.
function build_fulltext_boolean_query(string $search): string
{
    preg_match_all('/[\p{L}\p{N}]+/u', $search, $matches);
    $tokens = array_filter($matches[0], fn ($t) => mb_strlen($t) >= 2);

    if (!$tokens) {
        return '';
    }

    return implode(' ', array_map(fn ($t) => '+' . $t . '*', $tokens));
}

function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

const PAYMENT_PROOF_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
const PAYMENT_PROOF_MAX_BYTES = 3 * 1024 * 1024;

// Returns [storedPath, error]. storedPath is relative to /uploads.
function handle_payment_proof_upload(array $file): array
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Payment proof upload failed.'];
    }
    if ($file['size'] > PAYMENT_PROOF_MAX_BYTES) {
        return [null, 'Payment proof must be smaller than 3MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PAYMENT_PROOF_EXT, true)) {
        return [null, 'Payment proof must be jpg, png, gif, webp or pdf.'];
    }
    $subdir = date('Y') . '/' . date('m');
    $dir = dirname(__DIR__) . '/uploads/payment_proofs/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [null, 'Could not save payment proof.'];
    }
    $filename = uniqid('proof_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return [null, 'Could not save payment proof.'];
    }
    return ['payment_proofs/' . $subdir . '/' . $filename, null];
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

function notify_order_placed(mysqli $conn, int $orderId, float $amount, string $deliveryFeeType = 'fixed'): void
{
    $amountText = number_format($amount) . ' RWF' . ($deliveryFeeType === 'negotiable' ? ' + delivery (TBD)' : '');
    $message = 'New order #' . $orderId . ' placed — ' . $amountText . '.';

    $admins = mysqli_query($conn, "SELECT id FROM users WHERE role = 'admin'");
    $stmt = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, order_id, message) VALUES (?, ?, ?)');

    while ($admin = mysqli_fetch_assoc($admins)) {
        $adminId = (int) $admin['id'];
        mysqli_stmt_bind_param($stmt, 'iis', $adminId, $orderId, $message);
        mysqli_stmt_execute($stmt);
    }
}

function notify_price_revealed(mysqli $conn, int $orderId, int $customerId): void
{
    $message = 'Pricing has been added to your order #' . $orderId . ' — view it and upload payment proof.';
    $stmt = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, order_id, message) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'iis', $customerId, $orderId, $message);
    mysqli_stmt_execute($stmt);
}

function fetch_status_history(mysqli $conn, int $orderId): array
{
    $stmt = mysqli_prepare($conn, '
        SELECT h.status, h.comment, h.created_at, u.name AS admin_name
        FROM order_status_history h
        JOIN users u ON u.id = h.created_by
        WHERE h.order_id = ?
        ORDER BY h.id DESC
    ');
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

// Admins eligible to be assigned as an order's "incharge": super admins and
// system users explicitly granted the 'orders' module permission.
function fetch_incharge_options(mysqli $conn): array
{
    return mysqli_fetch_all(mysqli_query($conn, "
        SELECT id, name FROM users
        WHERE role = 'admin' AND (is_super = 1 OR id IN (SELECT user_id FROM user_permissions WHERE module = 'orders'))
        ORDER BY name
    "), MYSQLI_ASSOC);
}

function order_status_badge_class(string $status): string
{
    $map = [
        'pending' => 'badge-status-pending',
        'confirmed' => 'badge-status-confirmed',
        'preparing' => 'badge-status-preparing',
        'out_for_delivery' => 'badge-status-out-for-delivery',
        'delivered' => 'badge-status-delivered',
        'cancelled' => 'badge-status-cancelled',
    ];
    return $map[$status] ?? 'bg-secondary';
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

// Recomputes an order's total from its current order_items rows (after a
// quantity/price edit) and persists it. Returns the new total.
function recalc_order_total(mysqli $conn, int $orderId): float
{
    $stmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(subtotal), 0) AS total FROM order_items WHERE order_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $total = (float) mysqli_stmt_get_result($stmt)->fetch_assoc()['total'];

    $stmt = mysqli_prepare($conn, 'UPDATE orders SET total = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'di', $total, $orderId);
    mysqli_stmt_execute($stmt);

    return $total;
}

// Products that can actually be added to an order line: active leaf nodes
// (no variants/packages underneath them), same rule cart/checkout use.
function fetch_purchasable_products(mysqli $conn): array
{
    return mysqli_fetch_all(mysqli_query($conn, "
        SELECT p.id, p.price,
               CONCAT_WS(' — ', grandparent.name, parent.name, p.name) AS display_name
        FROM products p
        LEFT JOIN products parent ON parent.id = p.parent_id
        LEFT JOIN products grandparent ON grandparent.id = parent.parent_id
        WHERE p.status = 'active'
          AND NOT EXISTS (SELECT 1 FROM products c WHERE c.parent_id = p.id)
        ORDER BY display_name
    "), MYSQLI_ASSOC);
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

function cart_item_count(mysqli $conn, int $userId): int
{
    $stmt = mysqli_prepare($conn, '
        SELECT COUNT(*) AS count
        FROM cart_items ci
        JOIN carts c ON c.id = ci.cart_id
        WHERE c.user_id = ?
    ');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    return (int) ($row['count'] ?? 0);
}

function cart_badge_value(mysqli $conn, int $userId): float
{
    if (get_setting($conn, 'show_price', '1') === '1') {
        return cart_total_amount($conn, $userId);
    }

    return cart_item_count($conn, $userId);
}
