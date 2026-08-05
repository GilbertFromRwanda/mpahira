<?php
require_once __DIR__ . '/config/database.php';
require_login();

$userId  = (int) $_SESSION['user_id'];
$perPage = 10;

function render_order_rows(array $orders, bool $showPrice): string
{
    if (!$orders) {
        return '<tr><td colspan="6" class="text-center text-muted">You have no orders yet. <a href="shop">Start shopping</a>.</td></tr>';
    }

    ob_start();
    foreach ($orders as $order):
        $rowShowPrice = $showPrice || $order['status'] !== 'pending';
        ?>
        <tr>
            <td>#<?= (int) $order['id'] ?></td>
            <td><?= e(date('M j, Y', strtotime($order['created_at']))) ?></td>
            <td>
                <?php if ($rowShowPrice): ?>
                    <?= number_format((float) $order['total'] + (float) $order['delivery_fee']) ?> RWF<?= $order['delivery_fee_type'] === 'negotiable' ? ' + TBD' : '' ?>
                <?php else: ?>
                    <span class="text-muted">Pending confirmation</span>
                <?php endif; ?>
            </td>
            <td><?= e($order['payment_method']) ?></td>
            <td><span class="badge <?= order_status_badge_class($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
            <td><a href="order?id=<?= (int) $order['id'] ?>" class="btn btn-sm btn-outline-dark">View</a></td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

function render_orders_pagination(int $page, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    ob_start(); ?>
    <nav aria-label="Orders pagination">
        <ul class="pagination">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="orders?page=<?= $page - 1 ?>" data-page="<?= $page - 1 ?>">&laquo; Prev</a>
            </li>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="orders?page=<?= $p ?>" data-page="<?= $p ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="orders?page=<?= $page + 1 ?>" data-page="<?= $page + 1 ?>">Next &raquo;</a>
            </li>
        </ul>
    </nav>
    <?php return ob_get_clean();
}

$countStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM orders WHERE user_id = ?');
mysqli_stmt_bind_param($countStmt, 'i', $userId);
mysqli_stmt_execute($countStmt);
$total      = (int) (mysqli_stmt_get_result($countStmt)->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

$page   = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = mysqli_prepare($conn, '
    SELECT id, total, delivery_fee, delivery_fee_type, payment_method, status, created_at
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
');
mysqli_stmt_bind_param($stmt, 'iii', $userId, $perPage, $offset);
mysqli_stmt_execute($stmt);
$orders = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
$showPrice = get_setting($conn, 'show_price', '1') === '1';

if (is_ajax()) {
    json_response([
        'html'       => render_order_rows($orders, $showPrice),
        'pagination' => render_orders_pagination($page, $totalPages),
    ]);
}

$pageTitle = 'My Orders';
require_once __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4">My Orders</h3>

<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Date</th>
                <th>Total</th>
                <th>Payment</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="orderRows"><?= render_order_rows($orders, $showPrice) ?></tbody>
    </table>
</div>

<div id="orderPagination"><?= render_orders_pagination($page, $totalPages) ?></div>

<script>
document.getElementById('orderPagination').addEventListener('click', function (e) {
    const link = e.target.closest('a.page-link');
    if (!link || link.closest('.page-item').classList.contains('disabled')) return;
    e.preventDefault();

    const url = 'orders?page=' + link.dataset.page;
    window.history.pushState({}, '', url);
    getAjax(url).then(res => {
        document.getElementById('orderRows').innerHTML = res.html;
        document.getElementById('orderPagination').innerHTML = res.pagination;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
