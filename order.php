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

$pageTitle = 'Order #' . $orderId;
$flash = flash_get();
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<h3 class="mb-3">Order #<?= (int) $order['id'] ?></h3>
<p>
    Status: <span class="badge bg-info text-dark"><?= e(str_replace('_', ' ', $order['status'])) ?></span>
    &nbsp;|&nbsp; Placed on <?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?>
</p>

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
                            <td><img src="<?= $displayImage ? 'uploads/' . e($displayImage) : 'https://placehold.co/60x60?text=No+Img' ?>" style="width:48px;height:48px;object-fit:cover;" loading="lazy"></td>
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
                <p class="mb-1"><a href="uploads/<?= e($order['payment_proof']) ?>" target="_blank" rel="noopener">View uploaded payment proof</a></p>
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= number_format((float) $order['total']) ?></span></div>
            <div class="d-flex justify-content-between"><span>Delivery Fee</span><span><?= $order['delivery_fee_type'] === 'negotiable' ? 'Negotiable' : number_format((float) $order['delivery_fee']) ?></span></div>
            <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?= $order['delivery_fee_type'] === 'negotiable' ? number_format((float) $order['total']) . ' + delivery (TBD)' : number_format((float) $order['total'] + (float) $order['delivery_fee']) . ' RWF' ?></span></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
