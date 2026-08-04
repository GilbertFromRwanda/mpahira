<?php
require_once __DIR__ . '/../config/database.php';
require_permission('orders');

$validStatuses = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'];

function render_order_rows(array $orders): string
{
    if (!$orders) {
        return '<tr><td colspan="8" class="text-center text-muted">No orders found.</td></tr>';
    }

    ob_start();
    foreach ($orders as $order): ?>
        <tr>
            <td>#<?= (int) $order['id'] ?></td>
            <td><?= e($order['customer_name']) ?><br><small class="text-muted"><?= e($order['customer_phone']) ?></small></td>
            <td><?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></td>
            <td><?= number_format((float) $order['total'] + (float) $order['delivery_fee']) ?><?= $order['delivery_fee_type'] === 'negotiable' ? ' + TBD' : '' ?></td>
            <td><?= e($order['payment_method']) ?></td>
            <td><span class="badge <?= order_status_badge_class($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
            <td><?= $order['incharge_name'] ? e($order['incharge_name']) : '<span class="text-muted">&mdash;</span>' ?></td>
            <td><a href="order?id=<?= (int) $order['id'] ?>" class="btn btn-sm btn-outline-dark">View</a></td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

$paymentMethods = mysqli_fetch_all(mysqli_query($conn, 'SELECT name FROM payment_methods ORDER BY name'), MYSQLI_ASSOC);
$inchargeOptions = fetch_incharge_options($conn);

$statusFilter = $_GET['status'] ?? '';
$paymentFilter = $_GET['payment_method'] ?? '';
$inchargeFilter = (int) ($_GET['incharge_id'] ?? 0);
$customerSearch = trim($_GET['q'] ?? '');
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$validDate = fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

$sql = '
    SELECT o.id, o.total, o.delivery_fee, o.delivery_fee_type, o.payment_method, o.status, o.created_at,
           u.name AS customer_name, u.phone AS customer_phone, i.name AS incharge_name
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN users i ON i.id = o.incharge_id
';
$conditions = [];
$params = [];
$types = '';

if (in_array($statusFilter, $validStatuses, true)) {
    $conditions[] = 'o.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($paymentFilter !== '') {
    $conditions[] = 'o.payment_method = ?';
    $types .= 's';
    $params[] = $paymentFilter;
}

if ($inchargeFilter !== 0 && in_array($inchargeFilter, array_map('intval', array_column($inchargeOptions, 'id')), true)) {
    $conditions[] = 'o.incharge_id = ?';
    $types .= 'i';
    $params[] = $inchargeFilter;
}

if ($customerSearch !== '') {
    $booleanSearch = build_fulltext_boolean_query($customerSearch);
    if ($booleanSearch !== '') {
        $conditions[] = 'MATCH (u.name) AGAINST (? IN BOOLEAN MODE)';
        $types .= 's';
        $params[] = $booleanSearch;
    }
}

if ($startDate !== '' && $validDate($startDate)) {
    $conditions[] = 'o.created_at >= ?';
    $types .= 's';
    $params[] = $startDate;
}

if ($endDate !== '' && $validDate($endDate)) {
    $conditions[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $endDate;
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
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

<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <h5 class="mb-0">All Orders</h5>
    <div class="d-flex gap-2 flex-wrap">
        <input type="text" id="customerSearch" class="form-control form-control-sm" style="width:auto;" placeholder="Search customer..." value="<?= e($customerSearch) ?>">
        <input type="date" id="startDateFilter" class="form-control form-control-sm" style="width:auto;" value="<?= e($startDate) ?>">
        <input type="date" id="endDateFilter" class="form-control form-control-sm" style="width:auto;" value="<?= e($endDate) ?>">
        <select id="paymentFilter" class="form-select form-select-sm" style="width:auto;">
            <option value="">All payment methods</option>
            <?php foreach ($paymentMethods as $m): ?>
                <option value="<?= e($m['name']) ?>" <?= $paymentFilter === $m['name'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusFilter" class="form-select form-select-sm" style="width:auto;">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="inchargeFilter" class="form-select form-select-sm" style="width:auto;">
            <option value="">All incharges</option>
            <?php foreach ($inchargeOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>" <?= $inchargeFilter === (int) $opt['id'] ? 'selected' : '' ?>><?= e($opt['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="table-responsive">
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>Order #</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Incharge</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="orderRows"><?= render_order_rows($orders) ?></tbody>
</table>
</div>

<script>
const filterFields = {
    status: document.getElementById('statusFilter'),
    payment_method: document.getElementById('paymentFilter'),
    incharge_id: document.getElementById('inchargeFilter'),
    q: document.getElementById('customerSearch'),
    start_date: document.getElementById('startDateFilter'),
    end_date: document.getElementById('endDateFilter'),
};

function applyFilters() {
    const params = new URLSearchParams();
    for (const [key, el] of Object.entries(filterFields)) {
        if (el.value) params.set(key, el.value);
    }
    const query = params.toString();
    const url = window.location.pathname + (query ? '?' + query : '');
    window.history.pushState({}, '', url);
    getAjax(url).then(res => {
        document.getElementById('orderRows').innerHTML = res.html;
    });
}

let filterDebounce;
for (const [key, el] of Object.entries(filterFields)) {
    if (el.tagName === 'SELECT') {
        el.addEventListener('change', applyFilters);
    } else {
        el.addEventListener('input', function () {
            clearTimeout(filterDebounce);
            filterDebounce = setTimeout(applyFilters, 350);
        });
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
