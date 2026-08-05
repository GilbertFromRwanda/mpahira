<?php
require_once __DIR__ . '/config/database.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, '
    SELECT o.*, a.province, a.district, a.sector, a.cell, a.village, a.address, a.phone
    FROM orders o
    LEFT JOIN addresses a ON a.id = o.address_id
    WHERE o.id = ? AND o.user_id = ?
');
mysqli_stmt_bind_param($stmt, 'ii', $orderId, $userId);
mysqli_stmt_execute($stmt);
$order = mysqli_stmt_get_result($stmt)->fetch_assoc();

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require_once __DIR__ . '/includes/header.php';
    echo '<p class="text-muted">Order not found.</p>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_items'])) {
    $success = false;
    $message = 'Only pending orders can be edited.';
    $newTotal = null;

    if ($order['status'] === 'pending') {
        $quantities = (array) ($_POST['quantity'] ?? []);
        $valid = (bool) $quantities;

        foreach ($quantities as $qty) {
            if ((int) $qty < 1) {
                $valid = false;
                break;
            }
        }

        if (!$valid) {
            $message = 'Quantity must be at least 1.';
        } else {
            foreach ($quantities as $itemId => $qty) {
                $itemId = (int) $itemId;
                $qty = (int) $qty;
                $stmt = mysqli_prepare($conn, 'UPDATE order_items SET quantity = ?, subtotal = ? * price WHERE id = ? AND order_id = ?');
                mysqli_stmt_bind_param($stmt, 'iiii', $qty, $qty, $itemId, $orderId);
                mysqli_stmt_execute($stmt);
            }
            $newTotal = recalc_order_total($conn, $orderId);
            $success = true;
            $message = 'Order updated.';
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
    $message = 'Only pending orders can be edited.';
    $newTotal = null;

    if ($order['status'] === 'pending') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = max(1, (int) ($_POST['quantity'] ?? 1));

        $stmt = mysqli_prepare($conn, "
            SELECT p.id, p.price FROM products p
            WHERE p.id = ? AND p.status = 'active' AND NOT EXISTS (SELECT 1 FROM products c WHERE c.parent_id = p.id)
        ");
        mysqli_stmt_bind_param($stmt, 'i', $productId);
        mysqli_stmt_execute($stmt);
        $product = mysqli_stmt_get_result($stmt)->fetch_assoc();

        if (!$product) {
            $message = 'Please choose a valid product.';
        } else {
            $price = (float) $product['price'];
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
    $message = 'Only pending orders can be edited.';
    $newTotal = null;
    $itemId = (int) ($_POST['item_id'] ?? 0);

    if ($order['status'] === 'pending') {
        $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM order_items WHERE order_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $itemCount = (int) mysqli_stmt_get_result($stmt)->fetch_assoc()['c'];

        if ($itemCount <= 1) {
            $message = 'An order must have at least one item.';
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM order_items WHERE id = ? AND order_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $itemId, $orderId);
            mysqli_stmt_execute($stmt);
            $newTotal = recalc_order_total($conn, $orderId);
            $success = true;
            $message = 'Item removed.';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_payment_proof'])) {
    $success = false;
    $message = 'Payment proof cannot be uploaded for this order right now.';

    $showPriceNow = get_setting($conn, 'show_price', '1') === '1' || $order['status'] !== 'pending' || (bool) $order['price_shown'];

    $paymentMethodRow = null;
    if ($order['payment_method'] !== '') {
        $stmt = mysqli_prepare($conn, 'SELECT requires_proof FROM payment_methods WHERE name = ?');
        mysqli_stmt_bind_param($stmt, 's', $order['payment_method']);
        mysqli_stmt_execute($stmt);
        $paymentMethodRow = mysqli_stmt_get_result($stmt)->fetch_assoc();
    }
    $canUploadNow = $showPriceNow && $order['status'] === 'pending' && !$order['payment_proof']
        && $paymentMethodRow && (int) $paymentMethodRow['requires_proof'] === 1;

    if ($canUploadNow) {
        [$paymentProof, $proofError] = handle_payment_proof_upload($_FILES['payment_proof'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if ($proofError) {
            $message = $proofError;
        } elseif (!$paymentProof) {
            $message = 'Please choose a file to upload.';
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE orders SET payment_proof = ? WHERE id = ? AND user_id = ?');
            mysqli_stmt_bind_param($stmt, 'sii', $paymentProof, $orderId, $userId);
            mysqli_stmt_execute($stmt);
            $success = true;
            $message = 'Payment proof uploaded.';
        }
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

// Viewing the order is itself the "read" action — clear any bell notification for it.
$stmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE order_id = ? AND user_id = ?');
mysqli_stmt_bind_param($stmt, 'ii', $orderId, $userId);
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

$canEditQty = $order['status'] === 'pending';
$purchasableProducts = $canEditQty ? fetch_purchasable_products($conn) : [];
$showPrice = get_setting($conn, 'show_price', '1') === '1' || !$canEditQty || (bool) $order['price_shown'];

$paymentProofIsPdf = $order['payment_proof'] && strtolower(pathinfo($order['payment_proof'], PATHINFO_EXTENSION)) === 'pdf';

$paymentMethodRow = null;
if ($order['payment_method'] !== '') {
    $stmt = mysqli_prepare($conn, 'SELECT requires_proof, instructions FROM payment_methods WHERE name = ?');
    mysqli_stmt_bind_param($stmt, 's', $order['payment_method']);
    mysqli_stmt_execute($stmt);
    $paymentMethodRow = mysqli_stmt_get_result($stmt)->fetch_assoc();
}
$needsPaymentProof = $showPrice && $canEditQty && !$order['payment_proof']
    && $paymentMethodRow && (int) $paymentMethodRow['requires_proof'] === 1;

function render_customer_status_history(array $rows): string
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
            <?php if ($row['comment'] !== ''): ?>
                <p class="mb-0 small"><?= e($row['comment']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

$pageTitle = 'Order #' . $orderId;
$flash = flash_get();
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<h3 class="mb-3">Order #<?= (int) $order['id'] ?></h3>
<p>
    Status: <span class="badge <?= order_status_badge_class($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span>
    &nbsp;|&nbsp; Placed on <?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?>
</p>

<div class="row g-4">
    <div class="col-md-7">
        <form id="itemsForm">
        <input type="hidden" name="update_items" value="1">
        <div class="table-responsive">
            <table class="table bg-white align-middle">
                <thead>
                    <tr><th></th><th>Product</th><th>Qty</th><?php if ($showPrice): ?><th>Price</th><th>Subtotal</th><?php endif; ?><?php if ($canEditQty): ?><th></th><?php endif; ?></tr>
                </thead>
                <tbody id="orderItemRows">
                    <?php while ($item = mysqli_fetch_assoc($items)): ?>
                        <?php $displayImage = $item['image'] ?: ($item['parent_image'] ?: $item['grandparent_image']); ?>
                        <tr data-item-id="<?= (int) $item['item_id'] ?>" <?= $showPrice ? 'data-price="' . (float) $item['price'] . '"' : '' ?>>
                            <td><img src="<?= $displayImage ? 'uploads/' . e($displayImage) : 'https://placehold.co/60x60?text=No+Img' ?>" style="width:48px;height:48px;object-fit:cover;" loading="lazy"></td>
                            <td><?= e($item['parent_name'] ? $item['parent_name'] . ' — ' . $item['name'] : $item['name']) ?></td>
                            <td>
                                <?php if ($canEditQty): ?>
                                    <input type="number" min="1" name="quantity[<?= (int) $item['item_id'] ?>]" value="<?= (int) $item['quantity'] ?>" class="form-control form-control-sm item-qty-input" style="width:70px;">
                                <?php else: ?>
                                    <?= (int) $item['quantity'] ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($showPrice): ?>
                                <td><?= number_format((float) $item['price']) ?></td>
                                <td class="item-subtotal"><?= number_format((float) $item['subtotal']) ?></td>
                            <?php endif; ?>
                            <?php if ($canEditQty): ?>
                                <td><button type="button" class="btn btn-sm btn-outline-danger item-delete-btn" data-id="<?= (int) $item['item_id'] ?>">&times;</button></td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEditQty): ?>
            <button type="submit" class="btn btn-dark btn-sm">Save Changes</button>
            <div class="form-text">You can adjust quantities while your order is pending.</div>
        <?php endif; ?>
        </form>

        <?php if ($canEditQty): ?>
            <form id="addItemForm" class="d-flex gap-2 align-items-end flex-wrap mt-3 border-top pt-3">
                <div>
                    <label class="form-label small mb-1">Add a product</label>
                    <input type="text" id="addItemProductSearch" class="form-control form-control-sm" list="addItemProductOptions" placeholder="Search product…" autocomplete="off" style="min-width:220px;">
                    <datalist id="addItemProductOptions">
                        <?php foreach ($purchasableProducts as $p): ?>
                            <option value="<?= e($p['display_name']) ?><?= $showPrice ? ' — ' . number_format((float) $p['price']) . ' RWF' : '' ?>" data-id="<?= (int) $p['id'] ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="product_id" id="addItemProduct" value="">
                </div>
                <div>
                    <label class="form-label small mb-1">Qty</label>
                    <input type="number" name="quantity" id="addItemQty" min="1" value="1" class="form-control form-control-sm" style="width:70px;">
                </div>
                <input type="hidden" name="add_item" value="1">
                <button type="submit" class="btn btn-outline-dark btn-sm">Add Item</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="col-md-5">
        <div class="card p-3 mb-3">
            <h5>Delivery Address</h5>
            <?php if ($order['district']): ?>
                <p class="mb-1"><?= e(implode(', ', array_filter([$order['village'], $order['cell'], $order['sector'], $order['district'], $order['province']]))) ?></p>
                <p class="mb-1"><?= e($order['address']) ?></p>
                <p class="mb-0">Phone: <?= e($order['phone']) ?></p>
            <?php else: ?>
                <p class="text-muted mb-0">No address on file.</p>
            <?php endif; ?>
        </div>
        <div class="card p-3">
            <h5>Payment</h5>
            <p class="mb-1">Method: <?= e($order['payment_method']) ?></p>
            <?php if ($order['payment_proof']): ?>
                <p class="mb-1"><a href="#" data-bs-toggle="modal" data-bs-target="#paymentProofModal">View uploaded payment proof</a></p>
            <?php elseif ($needsPaymentProof): ?>
                <form id="paymentProofForm" class="mb-3" enctype="multipart/form-data">
                    <input type="hidden" name="upload_payment_proof" value="1">
                    <label class="form-label small mb-1">Upload payment proof</label>
                    <input type="file" name="payment_proof" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" required>
                    <div class="form-text">Max 3MB — jpg, png, gif, webp or pdf.</div>
                    <button type="submit" class="btn btn-dark btn-sm mt-2">Upload</button>
                </form>
            <?php endif; ?>
            <?php if ($showPrice): ?>
                <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= number_format((float) $order['total']) ?></span></div>
                <div class="d-flex justify-content-between"><span>Delivery Fee</span><span><?= $order['delivery_fee_type'] === 'negotiable' ? 'Negotiable' : number_format((float) $order['delivery_fee']) ?></span></div>
                <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?= $order['delivery_fee_type'] === 'negotiable' ? number_format((float) $order['total']) . ' + delivery (TBD)' : number_format((float) $order['total'] + (float) $order['delivery_fee']) . ' RWF' ?></span></div>
            <?php else: ?>
                <p class="text-muted small mb-0">Pricing will be confirmed with you directly.</p>
            <?php endif; ?>
        </div>

        <div class="card p-3 mt-3">
            <h5>Status History</h5>
            <?= render_customer_status_history(fetch_status_history($conn, $orderId)) ?>
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
                    <iframe src="uploads/<?= e($order['payment_proof']) ?>" style="width:100%;height:75vh;border:0;"></iframe>
                <?php else: ?>
                    <img src="uploads/<?= e($order['payment_proof']) ?>" class="img-fluid" alt="Payment proof">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canEditQty): ?>
<script>
document.querySelectorAll('#itemsForm tr[data-item-id]').forEach(function (row) {
    const qtyInput = row.querySelector('.item-qty-input');
    const subtotalCell = row.querySelector('.item-subtotal');
    if (!qtyInput || !subtotalCell) return;
    const price = parseFloat(row.dataset.price) || 0;

    qtyInput.addEventListener('input', function () {
        const qty = Math.max(1, parseInt(qtyInput.value, 10) || 0);
        subtotalCell.textContent = Math.round(qty * price).toLocaleString();
    });
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

document.getElementById('orderItemRows')?.addEventListener('click', function (e) {
    const deleteBtn = e.target.closest('.item-delete-btn');
    if (!deleteBtn) return;
    if (!confirm('Remove this item from your order?')) return;

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

document.getElementById('paymentProofForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Uploading…');
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
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
