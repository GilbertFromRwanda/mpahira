<?php
require_once __DIR__ . '/../config/database.php';
require_login();

header('Content-Type: application/json');

$userId = (int) $_SESSION['user_id'];

// ── Mark as read: the admin dismissed/acted on a notification — delete it now. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE id = ? AND user_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
    mysqli_stmt_execute($stmt);
    echo json_encode(['success' => true]);
    exit;
}

// ── Total unread count, for the topnav bell badge. ──────────────────────────
if (($_GET['action'] ?? '') === 'count') {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    echo json_encode(['count' => (int) ($row['c'] ?? 0)]);
    exit;
}

// ── Full list, for the bell's dropdown panel (most recent first). ───────────
if (($_GET['action'] ?? '') === 'list') {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    echo json_encode($rows);
    exit;
}

// Long-poll: hold the request open, checking every second, so the admin gets
// notified the moment an order is placed instead of on a fixed refresh
// interval. A row is handed out once (delivered_at gets stamped so the loop
// below doesn't keep re-returning it every second) but is only ever deleted
// once the admin explicitly marks it read.
$deadline = time() + 25;
session_write_close();

while (true) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM notifications WHERE user_id = ? AND delivered_at IS NULL ORDER BY id ASC');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    if ($rows) {
        $ids = implode(',', array_column($rows, 'id'));
        $now = date('Y-m-d H:i:s');
        mysqli_query($conn, "UPDATE notifications SET delivered_at = '$now' WHERE id IN ($ids)");
        echo json_encode($rows);
        exit;
    }

    if (time() >= $deadline) {
        echo json_encode([]);
        exit;
    }
    usleep(1000000);
}
