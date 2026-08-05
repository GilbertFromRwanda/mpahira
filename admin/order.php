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
$canRevealPrice = has_permission('reveal_price');
$storePriceShown = get_setting($conn, 'show_price', '1') === '1';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_items'])) {
    $success = false;
    $message = "You don't have permission to edit order items.";
    $newTotal = null;

    $stmt = mysqli_prepare($conn, 'SELECT status FROM orders WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $orderStatus = mysqli_stmt_get_result($stmt)->fetch_assoc()['status'] ?? null;

    if (!has_permission('edit_orders')) {
        $message = "You don't have permission to edit order items.";
    } elseif ($orderStatus !== 'pending') {
        $message = 'Only pending orders can be edited.';
    } else {
        $quantities = (array) ($_POST['quantity'] ?? []);
        $prices = (array) ($_POST['price'] ?? []);
        $valid = (bool) $quantities;

        foreach ($quantities as $itemId => $qty) {
            $price = (float) ($prices[$itemId] ?? -1);
            if ((int) $qty < 1 || $price < 0) {
                $valid = false;
                break;
            }
        }

        if (!$valid) {
            $message = 'Quantity must be at least 1 and price cannot be negative.';
        } else {
            foreach ($quantities as $itemId => $qty) {
                $itemId = (int) $itemId;
                $qty = (int) $qty;
                $price = (float) $prices[$itemId];
                $subtotal = $qty * $price;
                $stmt = mysqli_prepare($conn, 'UPDATE order_items SET quantity = ?, price = ?, subtotal = ? WHERE id = ? AND order_id = ?');
                mysqli_stmt_bind_param($stmt, 'iddii', $qty, $price, $subtotal, $itemId, $orderId);
                mysqli_stmt_execute($stmt);
            }
            $newTotal = recalc_order_total($conn, $orderId);
            $success = true;
            $message = 'Order items updated.';
        }
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'total' => $newTotal,
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
    redirect('order?id=' . $orderId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $success = false;
    $message = "You don't have permission to edit order items.";
    $newTotal = null;

    $stmt = mysqli_prepare($conn, 'SELECT status FROM orders WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $orderStatus = mysqli_stmt_get_result($stmt)->fetch_assoc()['status'] ?? null;

    if (!has_permission('edit_orders')) {
        $message = "You don't have permission to edit order items.";
    } elseif ($orderStatus !== 'pending') {
        $message = 'Only pending orders can be edited.';
    } else {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['quantity'] ?? 0);
        $price = (float) ($_POST['price'] ?? -1);

        $stmt = mysqli_prepare($conn, "
            SELECT p.id, p.price FROM products p
            WHERE p.id = ? AND p.status = 'active' AND NOT EXISTS (SELECT 1 FROM products c WHERE c.parent_id = p.id)
        ");
        mysqli_stmt_bind_param($stmt, 'i', $productId);
        mysqli_stmt_execute($stmt);
        $product = mysqli_stmt_get_result($stmt)->fetch_assoc();

        if (!$product) {
            $message = 'Please choose a valid product.';
        } elseif ($qty < 1 || $price < 0) {
            $message = 'Quantity must be at least 1 and price cannot be negative.';
        } else {
            $subtotal = $qty * $price;
            $stmt = mysqli_prepare($conn, 'INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'iiidd', $orderId, $productId, $qty, $price, $subtotal);
            mysqli_stmt_execute($stmt);
            $newTotal = recalc_order_total($conn, $orderId);
            $success = true;
            $message = 'Item added.';
        }
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'total' => $newTotal,
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
    redirect('order?id=' . $orderId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $success = false;
    $message = "You don't have permission to edit order items.";
    $newTotal = null;
    $itemId = (int) ($_POST['item_id'] ?? 0);

    $stmt = mysqli_prepare($conn, 'SELECT status FROM orders WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $orderStatus = mysqli_stmt_get_result($stmt)->fetch_assoc()['status'] ?? null;

    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM order_items WHERE order_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $itemCount = (int) mysqli_stmt_get_result($stmt)->fetch_assoc()['c'];

    if (!has_permission('edit_orders')) {
        $message = "You don't have permission to edit order items.";
    } elseif ($orderStatus !== 'pending') {
        $message = 'Only pending orders can be edited.';
    } elseif ($itemCount <= 1) {
        $message = 'An order must have at least one item.';
    } else {
        $stmt = mysqli_prepare($conn, 'DELETE FROM order_items WHERE id = ? AND order_id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $itemId, $orderId);
        mysqli_stmt_execute($stmt);
        $newTotal = recalc_order_total($conn, $orderId);
        $success = true;
        $message = 'Item removed.';
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'total' => $newTotal,
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['price_shown'])) {
    $priceShown = $_POST['price_shown'] === '1' ? 1 : 0;
    $success = false;
    $message = "You don't have permission to reveal pricing.";

    if (has_permission('reveal_price')) {
        $stmt = mysqli_prepare($conn, 'UPDATE orders SET price_shown = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $priceShown, $orderId);
        mysqli_stmt_execute($stmt);
        $success = true;
        $message = $priceShown ? 'Price revealed to customer.' : 'Price hidden from customer.';

        if ($priceShown) {
            $stmt = mysqli_prepare($conn, 'SELECT user_id FROM orders WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $orderId);
            mysqli_stmt_execute($stmt);
            $customerId = (int) (mysqli_stmt_get_result($stmt)->fetch_assoc()['user_id'] ?? 0);
            if ($customerId) {
                notify_price_revealed($conn, $orderId, $customerId);
            }
        }
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'price_shown' => $priceShown,
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
    SELECT oi.id AS item_id, oi.quantity, oi.price, oi.subtotal, p.name, p.image,
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

$canEditItems = $order['status'] === 'pending' && has_permission('edit_orders');
$purchasableProducts = $canEditItems ? fetch_purchasable_products($conn) : [];

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
        <form id="itemsForm">
        <input type="hidden" name="update_items" value="1">
        <div class="table-responsive">
        <table class="table bg-white align-middle">
            <thead>
                <tr><th></th><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th><?php if ($canEditItems): ?><th></th><?php endif; ?></tr>
            </thead>
            <tbody id="orderItemRows">
                <?php while ($item = mysqli_fetch_assoc($items)): ?>
                    <?php $displayImage = $item['image'] ?: ($item['parent_image'] ?: $item['grandparent_image']); ?>
                    <tr data-item-id="<?= (int) $item['item_id'] ?>">
                        <td><img src="<?= $displayImage ? '../uploads/' . e($displayImage) : 'https://placehold.co/60x60?text=No+Img' ?>" style="width:48px;height:48px;object-fit:cover;" loading="lazy"></td>
                        <td><?= e($item['parent_name'] ? $item['parent_name'] . ' — ' . $item['name'] : $item['name']) ?></td>
                        <td>
                            <?php if ($canEditItems): ?>
                                <input type="number" min="1" name="quantity[<?= (int) $item['item_id'] ?>]" value="<?= (int) $item['quantity'] ?>" class="form-control form-control-sm item-qty-input" style="width:70px;">
                            <?php else: ?>
                                <?= (int) $item['quantity'] ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canEditItems): ?>
                                <input type="number" min="0" step="0.01" name="price[<?= (int) $item['item_id'] ?>]" value="<?= e((string) $item['price']) ?>" class="form-control form-control-sm item-price-input" style="width:90px;">
                            <?php else: ?>
                                <?= number_format((float) $item['price']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="item-subtotal"><?= number_format((float) $item['subtotal']) ?></td>
                        <?php if ($canEditItems): ?>
                            <td><button type="button" class="btn btn-sm btn-outline-danger item-delete-btn" data-id="<?= (int) $item['item_id'] ?>">&times;</button></td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php if ($canEditItems): ?>
            <button type="submit" class="btn btn-dark btn-sm">Save Items</button>
            <div class="form-text">Editing items recalculates the order total. Only available while the order is pending.</div>
        <?php endif; ?>
        </form>

        <?php if ($canEditItems): ?>
            <form id="addItemForm" class="d-flex gap-2 align-items-end flex-wrap mt-3 border-top pt-3">
                <div>
                    <label class="form-label small mb-1">Product</label>
                    <input type="text" id="addItemProductSearch" class="form-control form-control-sm" list="addItemProductOptions" placeholder="Search product…" autocomplete="off" style="min-width:220px;">
                    <datalist id="addItemProductOptions">
                        <?php foreach ($purchasableProducts as $p): ?>
                            <option value="<?= e($p['display_name']) ?>" data-id="<?= (int) $p['id'] ?>" data-price="<?= (float) $p['price'] ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="product_id" id="addItemProduct" value="">
                </div>
                <div>
                    <label class="form-label small mb-1">Qty</label>
                    <input type="number" name="quantity" id="addItemQty" min="1" value="1" class="form-control form-control-sm" style="width:70px;">
                </div>
                <div>
                    <label class="form-label small mb-1">Price</label>
                    <input type="number" name="price" id="addItemPrice" min="0" step="0.01" class="form-control form-control-sm" style="width:90px;">
                </div>
                <input type="hidden" name="add_item" value="1">
                <button type="submit" class="btn btn-outline-dark btn-sm">Add Item</button>
            </form>
        <?php endif; ?>
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
            <?php if ($canRevealPrice && !$storePriceShown && $order['status'] === 'pending'): ?>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="priceShownToggle" <?= $order['price_shown'] ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="priceShownToggle">Show pricing to customer on this order</label>
                    <div class="form-text">Store-wide pricing is hidden. Enable this once you've agreed a price so the customer can see it and upload payment proof.</div>
                </div>
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

document.getElementById('priceShownToggle')?.addEventListener('change', function () {
    const checkbox = this;
    checkbox.disabled = true;
    const fd = new FormData();
    fd.append('price_shown', checkbox.checked ? '1' : '0');
    postAjax(window.location.pathname + window.location.search, fd).then(res => {
        checkbox.disabled = false;
        showToast(res.success ? 'success' : 'danger', res.message);
        if (!res.success) checkbox.checked = !checkbox.checked;
    }).catch(() => {
        checkbox.disabled = false;
        checkbox.checked = !checkbox.checked;
        showToast('danger', 'Network error. Please try again.');
    });
});

document.querySelectorAll('#itemsForm tr[data-item-id]').forEach(function (row) {
    const qtyInput = row.querySelector('.item-qty-input');
    const priceInput = row.querySelector('.item-price-input');
    const subtotalCell = row.querySelector('.item-subtotal');
    if (!qtyInput || !priceInput) return;

    function recalcRow() {
        const qty = Math.max(1, parseInt(qtyInput.value, 10) || 0);
        const price = Math.max(0, parseFloat(priceInput.value) || 0);
        subtotalCell.textContent = Math.round(qty * price).toLocaleString();
    }
    qtyInput.addEventListener('input', recalcRow);
    priceInput.addEventListener('input', recalcRow);
});

document.getElementById('itemsForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Saving…');
    postAjax(window.location.pathname + window.location.search, new FormData(form)).then(res => {
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            window.location.reload();
        } else {
            restore();
        }
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

document.getElementById('orderItemRows')?.addEventListener('click', function (e) {
    const deleteBtn = e.target.closest('.item-delete-btn');
    if (!deleteBtn) return;
    if (!confirm('Remove this item from the order?')) return;

    const fd = new FormData();
    fd.append('delete_item', '1');
    fd.append('item_id', deleteBtn.dataset.id);
    postAjax(window.location.pathname + window.location.search, fd).then(res => {
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) window.location.reload();
    }).catch(() => showToast('danger', 'Network error. Please try again.'));
});

const addItemProductSearch = document.getElementById('addItemProductSearch');
const addItemProductId = document.getElementById('addItemProduct');
const addItemProductOptions = document.getElementById('addItemProductOptions');

addItemProductSearch?.addEventListener('input', function () {
    const match = Array.from(addItemProductOptions.options).find(o => o.value === this.value);
    addItemProductId.value = match ? match.dataset.id : '';
    document.getElementById('addItemPrice').value = match ? match.dataset.price : '';
});

document.getElementById('addItemForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;

    if (!addItemProductId.value) {
        showToast('danger', 'Please pick a product from the list.');
        return;
    }

    const restore = setFormLoading(form, 'Adding…');
    postAjax(window.location.pathname + window.location.search, new FormData(form)).then(res => {
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            window.location.reload();
        } else {
            restore();
        }
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
