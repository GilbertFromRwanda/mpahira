<?php
require_once __DIR__ . '/../config/database.php';
require_permission('orders');

const ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'];

$orderId = (int) ($_GET['id'] ?? 0);

$paymentMethodList = mysqli_fetch_all(mysqli_query($conn, 'SELECT name FROM payment_methods ORDER BY name'), MYSQLI_ASSOC);
$paymentMethodNames = array_column($paymentMethodList, 'name');

$inchargeOptions = fetch_incharge_options($conn);
$inchargeIds = array_map('intval', array_column($inchargeOptions, 'id'));
$canAssignIncharge = has_permission('assign_incharge');

function render_status_history(array $rows): string
{
    if (!$rows) {
        return '<p class="text-muted small mb-0">No status changes yet.</p>';
    }

    ob_start();
    foreach ($rows as $row): ?>
        <div class="border-bottom pb-2 mb-2">
            <div class="d-flex justify-content-between">
                <span class="badge <?= order_status_badge_class($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span>
                <span class="text-muted small"><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></span>
            </div>
            <p class="mb-0 small"><?= e($row['comment']) ?></p>
            <p class="mb-0 text-muted small">&mdash; <?= e($row['admin_name']) ?></p>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    $status = $_POST['status'];
    $comment = trim($_POST['comment'] ?? '');
    $success = false;
    $message = 'Invalid status.';

    if ($status === 'cancelled' && $comment === '') {
        $message = 'Please add a comment explaining why the order is cancelled.';
    } elseif (in_array($status, ORDER_STATUSES, true)) {
        $stmt = mysqli_prepare($conn, 'UPDATE orders SET status = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $status, $orderId);
        mysqli_stmt_execute($stmt);

        $adminId = (int) $_SESSION['user_id'];
        $stmt = mysqli_prepare($conn, 'INSERT INTO order_status_history (order_id, status, comment, created_by) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'issi', $orderId, $status, $comment, $adminId);
        mysqli_stmt_execute($stmt);

        $success = true;
        $message = 'Order status updated.';
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'status' => $status,
            'status_label' => str_replace('_', ' ', $status),
            'history_rows' => $success ? render_status_history(fetch_status_history($conn, $orderId)) : null,
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
    redirect('order?id=' . $orderId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method'])) {
    $paymentMethod = $_POST['payment_method'];
    $success = false;

    if (in_array($paymentMethod, $paymentMethodNames, true)) {
        $stmt = mysqli_prepare($conn, 'UPDATE orders SET payment_method = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $paymentMethod, $orderId);
        mysqli_stmt_execute($stmt);
        $success = true;
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $success ? 'Payment method updated.' : 'Invalid payment method.',
            'payment_method' => $paymentMethod,
        ]);
    }

    flash_set($success ? 'success' : 'danger', $success ? 'Payment method updated.' : 'Invalid payment method.');
    redirect('order?id=' . $orderId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['incharge_id'])) {
    $inchargeId = (int) $_POST['incharge_id'];
    $success = false;
    $message = 'Invalid incharge.';

    if (!has_permission('assign_incharge')) {
        $message = "You don't have permission to assign incharge.";
    } elseif ($inchargeId === 0) {
        $stmt = mysqli_prepare($conn, 'UPDATE orders SET incharge_id = NULL WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $success = true;
        $message = 'Incharge updated.';
    } elseif (in_array($inchargeId, $inchargeIds, true)) {
        $stmt = mysqli_prepare($conn, 'UPDATE orders SET incharge_id = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $inchargeId, $orderId);
        mysqli_stmt_execute($stmt);
        $success = true;
        $message = 'Incharge updated.';
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
    redirect('order?id=' . $orderId);
}

$stmt = mysqli_prepare($conn, '
    SELECT o.*, u.name AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
           a.province, a.district, a.sector, a.cell, a.village, a.address, a.phone AS address_phone,
           i.name AS incharge_name
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN addresses a ON a.id = o.address_id
    LEFT JOIN users i ON i.id = o.incharge_id
    WHERE o.id = ?
');
mysqli_stmt_bind_param($stmt, 'i', $orderId);
mysqli_stmt_execute($stmt);
$order = mysqli_stmt_get_result($stmt)->fetch_assoc();

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/nav.php';
    echo '<p class="text-muted">Order not found.</p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Viewing the order is itself the "read" action — clear any bell notification for it.
$adminId = (int) $_SESSION['user_id'];
$stmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE order_id = ? AND user_id = ?');
mysqli_stmt_bind_param($stmt, 'ii', $orderId, $adminId);
mysqli_stmt_execute($stmt);

$stmt = mysqli_prepare($conn, '
    SELECT oi.quantity, oi.price, oi.subtotal, p.name, p.image,
           parent.name AS parent_name, parent.image AS parent_image,
           grandparent.image AS grandparent_image
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    LEFT JOIN products parent ON parent.id = p.parent_id
    LEFT JOIN products grandparent ON grandparent.id = parent.parent_id
    WHERE oi.order_id = ?
');
mysqli_stmt_bind_param($stmt, 'i', $orderId);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);

$paymentProofIsPdf = $order['payment_proof'] && strtolower(pathinfo($order['payment_proof'], PATHINFO_EXTENSION)) === 'pdf';

$pageTitle = 'Order #' . $orderId;
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Order #<?= (int) $order['id'] ?></h4>
    <a href="orders" class="btn btn-sm btn-outline-secondary">&larr; Back to Orders</a>
</div>

<div class="row g-4">
    <div class="col-md-7">
        <div class="table-responsive">
        <table class="table bg-white align-middle">
            <thead>
                <tr><th></th><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr>
            </thead>
            <tbody>
                <?php while ($item = mysqli_fetch_assoc($items)): ?>
                    <?php $displayImage = $item['image'] ?: ($item['parent_image'] ?: $item['grandparent_image']); ?>
                    <tr>
                        <td><img src="<?= $displayImage ? '../uploads/' . e($displayImage) : 'https://placehold.co/60x60?text=No+Img' ?>" style="width:48px;height:48px;object-fit:cover;" loading="lazy"></td>
                        <td><?= e($item['parent_name'] ? $item['parent_name'] . ' — ' . $item['name'] : $item['name']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= number_format((float) $item['price']) ?></td>
                        <td><?= number_format((float) $item['subtotal']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card p-3 mb-3">
            <h5>Customer</h5>
            <p class="mb-1"><?= e($order['customer_name']) ?></p>
            <p class="mb-1"><?= e($order['customer_phone']) ?></p>
            <p class="mb-0 text-muted"><?= e($order['customer_email'] ?? '') ?></p>
        </div>

        <div class="card p-3 mb-3">
            <h5>Delivery Address</h5>
            <?php if ($order['district']): ?>
                <p class="mb-1"><?= e(implode(', ', array_filter([$order['village'], $order['cell'], $order['sector'], $order['district'], $order['province']]))) ?></p>
                <p class="mb-1"><?= e($order['address']) ?></p>
                <p class="mb-0">Phone: <?= e($order['address_phone']) ?></p>
            <?php else: ?>
                <p class="text-muted mb-0">No address on file.</p>
            <?php endif; ?>
        </div>

        <div class="card p-3 mb-3">
            <h5>Payment</h5>
            <form id="paymentForm" class="d-flex gap-2 mb-3">
                <select name="payment_method" class="form-select">
                    <?php if ($order['payment_method'] !== '' && !in_array($order['payment_method'], $paymentMethodNames, true)): ?>
                        <option value="<?= e($order['payment_method']) ?>" selected><?= e($order['payment_method']) ?></option>
                    <?php endif; ?>
                    <?php foreach ($paymentMethodNames as $m): ?>
                        <option value="<?= e($m) ?>" <?= $order['payment_method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-dark">Update</button>
            </form>
            <?php if ($order['payment_proof']): ?>
                <p class="mb-3"><a href="#" data-bs-toggle="modal" data-bs-target="#paymentProofModal">View uploaded payment proof</a></p>
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= number_format((float) $order['total']) ?></span></div>
            <div class="d-flex justify-content-between"><span>Delivery Fee</span><span><?= $order['delivery_fee_type'] === 'negotiable' ? 'Negotiable' : number_format((float) $order['delivery_fee']) ?></span></div>
            <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?= $order['delivery_fee_type'] === 'negotiable' ? number_format((float) $order['total']) . ' + delivery (TBD)' : number_format((float) $order['total'] + (float) $order['delivery_fee']) . ' RWF' ?></span></div>
        </div>

        <div class="card p-3 mb-3">
            <h5>Incharge</h5>
            <?php if ($canAssignIncharge): ?>
                <form id="inchargeForm" class="d-flex gap-2">
                    <select name="incharge_id" class="form-select">
                        <option value="0"><?= $order['incharge_id'] ? 'Unassign' : 'Unassigned' ?></option>
                        <?php foreach ($inchargeOptions as $opt): ?>
                            <option value="<?= (int) $opt['id'] ?>" <?= (int) $order['incharge_id'] === (int) $opt['id'] ? 'selected' : '' ?>><?= e($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-dark">Update</button>
                </form>
            <?php else: ?>
                <p class="mb-0"><?= $order['incharge_name'] ? e($order['incharge_name']) : '<span class="text-muted">Unassigned</span>' ?></p>
            <?php endif; ?>
        </div>

        <div class="card p-3 mb-3">
            <h5>Status</h5>
            <form id="statusForm">
                <select name="status" id="statusFormSelect" class="form-select mb-2">
                    <?php foreach (ORDER_STATUSES as $s): ?>
                        <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea name="comment" id="statusFormComment" class="form-control mb-2" rows="2" placeholder="Add a comment about this status change (optional)"></textarea>
                <button type="submit" class="btn btn-dark w-100">Update</button>
            </form>
        </div>

        <div class="card p-3">
            <h5>Status History</h5>
            <div id="statusHistory"><?= render_status_history(fetch_status_history($conn, $orderId)) ?></div>
        </div>
    </div>
</div>

<?php if ($order['payment_proof']): ?>
<div class="modal fade" id="paymentProofModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <?php if ($paymentProofIsPdf): ?>
                    <iframe src="../uploads/<?= e($order['payment_proof']) ?>" style="width:100%;height:75vh;border:0;"></iframe>
                <?php else: ?>
                    <img src="../uploads/<?= e($order['payment_proof']) ?>" class="img-fluid" alt="Payment proof">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const statusFormSelect = document.getElementById('statusFormSelect');
const statusFormComment = document.getElementById('statusFormComment');

function syncCommentRequired() {
    const isCancelled = statusFormSelect.value === 'cancelled';
    statusFormComment.required = isCancelled;
    statusFormComment.placeholder = isCancelled
        ? 'Add a comment explaining the cancellation*'
        : 'Add a comment about this status change (optional)';
}
statusFormSelect.addEventListener('change', syncCommentRequired);
syncCommentRequired();

document.getElementById('statusForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Updating…');
    postAjax(window.location.pathname + window.location.search, new FormData(form)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            form.querySelector('textarea[name="comment"]').value = '';
            document.getElementById('statusHistory').innerHTML = res.history_rows;
        }
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

document.getElementById('paymentForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Updating…');
    postAjax(window.location.pathname + window.location.search, new FormData(form)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

document.getElementById('inchargeForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Updating…');
    postAjax(window.location.pathname + window.location.search, new FormData(form)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
