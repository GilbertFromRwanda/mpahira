<?php $current = basename($_SERVER['SCRIPT_NAME'], '.php'); ?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $current === 'dashboard' ? 'active' : '' ?>" href="dashboard">Dashboard</a>
    </li>
    <?php if (has_permission('categories')): ?>
    <li class="nav-item">
        <a class="nav-link <?= $current === 'categories' ? 'active' : '' ?>" href="categories">Categories</a>
    </li>
    <?php endif; ?>
    <?php if (has_permission('products')): ?>
    <li class="nav-item">
        <a class="nav-link <?= $current === 'products' ? 'active' : '' ?>" href="products">Products</a>
    </li>
    <?php endif; ?>
    <?php if (has_permission('orders')): ?>
    <li class="nav-item">
        <a class="nav-link <?= in_array($current, ['orders', 'order']) ? 'active' : '' ?>" href="orders">Orders</a>
    </li>
    <?php endif; ?>
    <?php if (is_super_admin()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $current === 'users' ? 'active' : '' ?>" href="users">Users</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $current === 'migrations' ? 'active' : '' ?>" href="migrations">Migrations</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $current === 'settings' ? 'active' : '' ?>" href="settings">Settings</a>
    </li>
    <?php endif; ?>
</ul>
