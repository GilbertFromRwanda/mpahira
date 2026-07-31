<?php
require_once __DIR__ . '/config/database.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$cartId = get_or_create_cart($conn, $userId);

function fetch_cart_rows(mysqli $conn, int $cartId): array
{
    $stmt = mysqli_prepare($conn, '
        SELECT ci.id, ci.quantity, p.id AS product_id, p.name, p.price, p.image,
               parent.name AS parent_name, parent.image AS parent_image,
               grandparent.image AS grandparent_image
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        LEFT JOIN products parent ON parent.id = p.parent_id
        LEFT JOIN products grandparent ON grandparent.id = parent.parent_id
        WHERE ci.cart_id = ?
        ORDER BY ci.id
    ');
    mysqli_stmt_bind_param($stmt, 'i', $cartId);
    mysqli_stmt_execute($stmt);
    $items = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($row = mysqli_fetch_assoc($items)) {
        $row['line_total'] = $row['price'] * $row['quantity'];
        $row['display_name'] = $row['parent_name'] ? $row['parent_name'] . ' — ' . $row['name'] : $row['name'];
        // A Variant (the immediate parent of a Package) has no image of its own, so
        // fall back further to the top-level product's image.
        $row['display_image'] = $row['image'] ?: ($row['parent_image'] ?: $row['grandparent_image']);
        $rows[] = $row;
    }
    return $rows;
}

function render_cart(mysqli $conn, array $rows): string
{
    if (!$rows) {
        return '<p class="text-muted">Your cart is empty. <a href="shop">Browse products</a>.</p>';
    }

    $subtotal = array_sum(array_column($rows, 'line_total'));
    $minOrderTotal = (float) get_setting($conn, 'min_order_total', '0');
    $belowMinimum = $minOrderTotal > 0 && $subtotal < $minOrderTotal;

    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table bg-white align-middle">
            <thead>
                <tr>
                    <th></th>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Subtotal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $item): ?>
                    <tr>
                        <td>
                            <img src="<?= $item['display_image'] ? 'uploads/' . e($item['display_image']) : 'https://placehold.co/60x60?text=No+Img' ?>" style="width:48px;height:48px;object-fit:cover;" loading="lazy">
                        </td>
                        <td><?= e($item['display_name']) ?></td>
                        <td><?= number_format((float) $item['price']) ?></td>
                        <td>
                            <form class="d-flex gap-1 cart-update-form" data-item-id="<?= (int) $item['id'] ?>">
                                <input type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="1" class="form-control form-control-sm" style="width:70px;">
                                <button type="submit" class="btn btn-sm btn-outline-dark">Update</button>
                            </form>
                        </td>
                        <td class="line-total"><?= number_format($item['line_total']) ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger cart-remove-btn" data-item-id="<?= (int) $item['id'] ?>">Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-end">
        <div class="text-end">
            <p class="mb-1">Subtotal: <strong><?= number_format($subtotal) ?> RWF</strong></p>
            <p class="text-muted small">Delivery fee calculated at checkout</p>
            <?php if ($belowMinimum): ?>
                <p class="text-danger small mb-2">Minimum order is <?= number_format($minOrderTotal) ?> RWF. Add <?= number_format($minOrderTotal - $subtotal) ?> RWF more to checkout.</p>
                <button type="button" class="btn btn-dark" disabled>Proceed to Checkout</button>
            <?php else: ?>
                <a href="checkout" class="btn btn-dark">Proceed to Checkout</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);

    if ($action === 'update') {
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $stmt = mysqli_prepare($conn, 'UPDATE cart_items SET quantity = ? WHERE id = ? AND cart_id = ?');
        mysqli_stmt_bind_param($stmt, 'iii', $quantity, $itemId, $cartId);
        mysqli_stmt_execute($stmt);
    } elseif ($action === 'remove') {
        $stmt = mysqli_prepare($conn, 'DELETE FROM cart_items WHERE id = ? AND cart_id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $itemId, $cartId);
        mysqli_stmt_execute($stmt);
    }

    if (is_ajax()) {
        json_response([
            'success' => true,
            'html' => render_cart($conn, fetch_cart_rows($conn, $cartId)),
            'cart_total' => cart_total_amount($conn, $userId),
        ]);
    }

    redirect('cart');
}

$pageTitle = 'Cart';
require_once __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4">Your Cart</h3>

<div id="cartContainer"><?= render_cart($conn, fetch_cart_rows($conn, $cartId)) ?></div>

<script>
document.getElementById('cartContainer').addEventListener('submit', function (e) {
    if (!e.target.classList.contains('cart-update-form')) return;
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    fd.append('action', 'update');
    fd.append('item_id', form.dataset.itemId);
    postAjax(window.location.pathname, fd).then(res => {
        document.getElementById('cartContainer').innerHTML = res.html;
        updateCartBadge(res.cart_total);
        showToast('success', 'Cart updated.');
    }).catch(() => showToast('danger', 'Network error. Please try again.'));
});

document.getElementById('cartContainer').addEventListener('click', function (e) {
    if (!e.target.classList.contains('cart-remove-btn')) return;
    if (!confirm('Remove this item from your cart?')) return;
    const fd = new FormData();
    fd.append('action', 'remove');
    fd.append('item_id', e.target.dataset.itemId);
    postAjax(window.location.pathname, fd).then(res => {
        document.getElementById('cartContainer').innerHTML = res.html;
        updateCartBadge(res.cart_total);
        showToast('success', 'Item removed.');
    }).catch(() => showToast('danger', 'Network error. Please try again.'));
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
