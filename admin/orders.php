<?php
require_once __DIR__ . '/../config/database.php';
require_permission('orders');

$validStatuses = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'];

function render_order_rows(array $orders): string
{
    if (!$orders) {
        return '<tr><td colspan="7" class="text-center text-muted">No orders found.</td></tr>';
    }

    ob_start();
    foreach ($orders as $order): ?>
        <tr>
            <td>#<?= (int) $order['id'] ?></td>
            <td><?= e($order['customer_name']) ?><br><small class="text-muted"><?= e($order['customer_phone']) ?></small></td>
            <td><?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></td>
            <td><?= number_format((float) $order['total'] + (float) $order['delivery_fee']) ?><?= $order['delivery_fee_type'] === 'negotiable' ? ' + TBD' : '' ?></td>
            <td><?= e($order['payment_method']) ?></td>
            <td><span class="badge bg-info text-dark"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
            <td><a href="order?id=<?= (int) $order['id'] ?>" class="btn btn-sm btn-outline-dark">View</a></td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

$statusFilter = $_GET['status'] ?? '';

$sql = '
    SELECT o.id, o.total, o.delivery_fee, o.delivery_fee_type, o.payment_method, o.status, o.created_at,
           u.name AS customer_name, u.phone AS customer_phone
    FROM orders o
    JOIN users u ON u.id = o.user_id
';
$params = [];
$types = '';

if (in_array($statusFilter, $validStatuses, true)) {
    $sql .= ' WHERE o.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

$sql .= ' ORDER BY o.created_at DESC';

$stmt = mysqli_prepare($conn, $sql);
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$orders = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

if (is_ajax()) {
    json_response(['html' => render_order_rows($orders)]);
}

$pageTitle = 'Orders';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">All Orders</h5>
    <select id="statusFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">All statuses</option>
        <?php foreach ($validStatuses as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>Order #</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="orderRows"><?= render_order_rows($orders) ?></tbody>
</table>

<script>
document.getElementById('statusFilter').addEventListener('change', function () {
    const status = this.value;
    const url = window.location.pathname + (status ? '?status=' + encodeURIComponent(status) : '');
    window.history.pushState({}, '', url);
    getAjax(url).then(res => {
        document.getElementById('orderRows').innerHTML = res.html;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
