<?php
require_once __DIR__ . '/../config/database.php';
require_admin();

const DASHBOARD_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'];

if (is_ajax()) {
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-$i days"))] = 0;
    }
    $dailyResult = mysqli_query($conn, '
        SELECT DATE(created_at) AS d, COUNT(*) AS c
        FROM orders
        WHERE created_at >= CURDATE() - INTERVAL 13 DAY
        GROUP BY DATE(created_at)
    ');
    while ($row = mysqli_fetch_assoc($dailyResult)) {
        if (isset($days[$row['d']])) {
            $days[$row['d']] = (int) $row['c'];
        }
    }

    $statusCounts = array_fill_keys(DASHBOARD_ORDER_STATUSES, 0);
    $statusResult = mysqli_query($conn, 'SELECT status, COUNT(*) AS c FROM orders GROUP BY status');
    while ($row = mysqli_fetch_assoc($statusResult)) {
        if (isset($statusCounts[$row['status']])) {
            $statusCounts[$row['status']] = (int) $row['c'];
        }
    }

    json_response([
        'products' => (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM products'))['c'],
        'categories' => (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM categories'))['c'],
        'orders' => (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM orders'))['c'],
        'customers' => (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'customer'"))['c'],
        'daily_labels' => array_map(fn ($d) => date('M j', strtotime($d)), array_keys($days)),
        'daily_counts' => array_values($days),
        'status_labels' => array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), array_keys($statusCounts)),
        'status_counts' => array_values($statusCounts),
    ]);
}

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<?php
function render_dashboard_card(string $id, string $label, ?string $href): void
{
    $inner = '<div class="card text-center p-3' . ($href ? ' dashboard-card' : '') . '">'
        . '<div class="fs-3 fw-bold" id="' . e($id) . '">&hellip;</div>'
        . '<div class="text-muted">' . e($label) . '</div>'
        . '</div>';
    if ($href) {
        echo '<a href="' . e($href) . '" class="text-decoration-none text-reset d-block">' . $inner . '</a>';
    } else {
        echo $inner;
    }
}
?>

<div class="row g-4">
    <div class="col-6 col-md-3">
        <?php render_dashboard_card('dashProductCount', 'Products', has_permission('products') ? 'products' : null) ?>
    </div>
    <div class="col-6 col-md-3">
        <?php render_dashboard_card('dashCategoryCount', 'Categories', has_permission('categories') ? 'categories' : null) ?>
    </div>
    <div class="col-6 col-md-3">
        <?php render_dashboard_card('dashOrderCount', 'Orders', has_permission('orders') ? 'orders' : null) ?>
    </div>
    <div class="col-6 col-md-3">
        <?php render_dashboard_card('dashCustomerCount', 'Customers', is_super_admin() ? 'users?role=customer' : null) ?>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-7">
        <div class="card p-3">
            <h6 class="text-muted mb-3">Orders &mdash; Last 14 Days</h6>
            <canvas id="dailyOrdersChart" height="220" role="img" aria-label="Line chart of order count per day for the last 14 days"></canvas>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-3">
            <h6 class="text-muted mb-3">Orders by Status</h6>
            <canvas id="statusChart" height="220" role="img" aria-label="Bar chart of order count per status"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"></script>
<script>
getAjax(window.location.pathname).then(function (res) {
    document.getElementById('dashProductCount').textContent = res.products;
    document.getElementById('dashCategoryCount').textContent = res.categories;
    document.getElementById('dashOrderCount').textContent = res.orders;
    document.getElementById('dashCustomerCount').textContent = res.customers;

    var rootStyle = getComputedStyle(document.documentElement);
    var brand = rootStyle.getPropertyValue('--color-primary').trim();
    var brandLight = rootStyle.getPropertyValue('--color-primary-light').trim();
    var gridColor = rootStyle.getPropertyValue('--color-border').trim();
    var mutedColor = rootStyle.getPropertyValue('--color-text-muted').trim();

    Chart.defaults.font.family = "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif";

    new Chart(document.getElementById('dailyOrdersChart'), {
        type: 'line',
        data: {
            labels: res.daily_labels,
            datasets: [{
                data: res.daily_counts,
                borderColor: brand,
                backgroundColor: brandLight,
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: brand,
                pointHoverRadius: 5
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: mutedColor } },
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: mutedColor, precision: 0 } }
            }
        }
    });

    new Chart(document.getElementById('statusChart'), {
        type: 'bar',
        data: {
            labels: res.status_labels,
            datasets: [{
                data: res.status_counts,
                backgroundColor: brand,
                borderRadius: 4,
                maxBarThickness: 22
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: mutedColor, precision: 0 } },
                y: { grid: { display: false }, ticks: { color: mutedColor } }
            }
        }
    });
}).catch(function () {});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
