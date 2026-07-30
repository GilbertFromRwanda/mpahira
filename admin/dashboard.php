<?php
require_once __DIR__ . '/../config/database.php';
require_admin();

$productCount = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM products'))['c'];
$categoryCount = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM categories'))['c'];
$orderCount = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM orders'))['c'];
$customerCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'customer'"))['c'];

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div class="row g-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="fs-3 fw-bold"><?= (int) $productCount ?></div>
            <div class="text-muted">Products</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="fs-3 fw-bold"><?= (int) $categoryCount ?></div>
            <div class="text-muted">Categories</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="fs-3 fw-bold"><?= (int) $orderCount ?></div>
            <div class="text-muted">Orders</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="fs-3 fw-bold"><?= (int) $customerCount ?></div>
            <div class="text-muted">Customers</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
