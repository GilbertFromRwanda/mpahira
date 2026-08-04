<?php
require_once __DIR__ . '/config/database.php';

function fetch_children(mysqli $conn, int $parentId): array
{
    $result = mysqli_query($conn, "
        SELECT id, name, price, image
        FROM products
        WHERE parent_id = $parentId AND status = 'active'
        ORDER BY name
    ");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = mysqli_prepare($conn, "
    SELECT p.id, p.name, p.description, p.price, p.image, p.parent_id, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.id = ? AND p.status = 'active'
");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$product = mysqli_stmt_get_result($stmt)->fetch_assoc();

if ($product) {
    // A node with children is a grouping (a product with Variants, or a Variant with
    // Packages); a node with no children is directly purchasable, whatever level it's at.
    $product['children'] = fetch_children($conn, (int) $product['id']);
    foreach ($product['children'] as &$child) {
        $child['children'] = fetch_children($conn, (int) $child['id']);
    }
    unset($child);

    $metaResult = mysqli_query($conn, 'SELECT meta_key, meta_value FROM product_meta WHERE product_id = ' . (int) $product['id']);
    $product['meta'] = mysqli_fetch_all($metaResult, MYSQLI_ASSOC);
}

$inCart = false;
if ($product && is_logged_in()) {
    $checkIds = [(int) $product['id']];
    foreach ($product['children'] as $child) {
        $checkIds[] = (int) $child['id'];
        foreach ($child['children'] as $grandchild) {
            $checkIds[] = (int) $grandchild['id'];
        }
    }
    $idList = implode(',', $checkIds);
    $stmt = mysqli_prepare($conn, "
        SELECT ci.product_id FROM cart_items ci
        JOIN carts c ON c.id = ci.cart_id
        WHERE c.user_id = ? AND ci.product_id IN ($idList)
    ");
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $inCartIds = [];
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $inCartIds[(int) $row['product_id']] = true;
    }
    $inCart = isset($inCartIds[(int) $product['id']]);
    foreach ($product['children'] as &$child) {
        $child['in_cart'] = isset($inCartIds[(int) $child['id']]);
        foreach ($child['children'] as &$grandchild) {
            $grandchild['in_cart'] = isset($inCartIds[(int) $grandchild['id']]);
        }
        unset($grandchild);
    }
    unset($child);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_ajax()) {
    if (!$product) {
        json_response(['success' => false, 'message' => 'Product not found.']);
    }
    $product['in_cart'] = $inCart;
    json_response(['success' => true, 'product' => $product]);
}

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require_once __DIR__ . '/includes/header.php';
    echo '<p class="text-muted">Product not found.</p>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    if (!is_logged_in()) {
        if (is_ajax()) {
            json_response(['success' => false, 'login_required' => true, 'redirect' => 'login']);
        }
        require_login();
    }

    if ($product['children']) {
        $message = 'Please choose an option before adding to cart.';
        if (is_ajax()) {
            json_response(['success' => false, 'message' => $message]);
        }
        flash_set('danger', $message);
        redirect('product?id=' . $id);
    }

    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $cartId = get_or_create_cart($conn, (int) $_SESSION['user_id']);

    $stmt = mysqli_prepare($conn, 'SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $cartId, $id);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_stmt_get_result($stmt)->fetch_assoc();

    if ($existing) {
        $newQuantity = (int) $existing['quantity'] + $quantity;
        $stmt = mysqli_prepare($conn, 'UPDATE cart_items SET quantity = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $newQuantity, $existing['id']);
        mysqli_stmt_execute($stmt);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iii', $cartId, $id, $quantity);
        mysqli_stmt_execute($stmt);
    }

    $message = 'Added to cart.';

    if (is_ajax()) {
        json_response([
            'success' => true,
            'message' => $message,
            'cart_total' => cart_total_amount($conn, (int) $_SESSION['user_id']),
        ]);
    }

    flash_set('success', $message);
    redirect('product?id=' . $id);
}

$pageTitle = $product['name'];
$flash = flash_get();
require_once __DIR__ . '/includes/header.php';
?>

<div id="formError">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <img id="detailImage" src="<?= $product['image'] ? 'uploads/' . e($product['image']) : 'https://placehold.co/400x300?text=No+Image' ?>" class="img-fluid rounded" loading="lazy">
    </div>
    <div class="col-md-7">
        <h3><?= e($product['name']) ?></h3>
        <p class="text-muted"><?= e($product['category_name'] ?? '') ?></p>

        <?php if ($product['meta']): ?>
            <ul class="list-unstyled small text-muted mb-2">
                <?php foreach ($product['meta'] as $m): ?>
                    <li><?php if ($m['meta_key'] !== 'tag'): ?><strong><?= e($m['meta_key']) ?>:</strong> <?php endif; ?><?= e($m['meta_value']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="fs-4 fw-bold" id="detailPrice"><?= number_format((float) $product['price']) ?> RWF</p>
        <p><?= nl2br(e($product['description'])) ?></p>

        <?php if ($product['children']): ?>
            <div id="variantPickers" class="mb-3"></div>
            <div id="variantCartArea"></div>
        <?php else: ?>
            <form method="post" class="d-flex gap-2 align-items-center" id="addToCartForm">
                <input type="hidden" name="add_to_cart" value="1">
                <input type="number" name="quantity" value="1" min="1" class="form-control" style="width:100px;">
                <button type="submit" class="btn btn-dark <?= $inCart ? 'added' : '' ?>" id="addToCartSubmitBtn"><?= $inCart ? '✓ In Cart' : 'Add to Cart' ?></button>
            </form>
        <?php endif; ?>

        <a href="shop" class="btn btn-link mt-3 ps-0">&larr; Back to shop</a>
    </div>
</div>

<?php if ($product['children']): ?>
<script>
const productChildren = <?= json_encode($product['children']) ?>;
const basePrice = <?= (float) $product['price'] ?>;
const parentImage = <?= json_encode($product['image']) ?>;
const variantCartArea = document.getElementById('variantCartArea');
const detailPrice = document.getElementById('detailPrice');
const detailImage = document.getElementById('detailImage');

// If any child itself has children, this is a two-level pick: Type (the child's
// own name) then Package (that child's children, each with its own price) — the
// package is what actually gets added to cart. If no child has children, this is
// the flat one-level case: each child is directly purchasable.
const isTwoLevel = productChildren.some(c => c.children && c.children.length > 0);

const pickersEl = document.getElementById('variantPickers');
let pickersHtml = `
    <label class="form-label">Type</label>
    <select id="typeSelect" class="form-select ${isTwoLevel ? 'mb-2' : ''}">
        ${productChildren.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('')}
    </select>`;
if (isTwoLevel) {
    pickersHtml += `
        <label class="form-label">Package</label>
        <select id="packageSelect" class="form-select"></select>`;
}
pickersEl.innerHTML = pickersHtml;

const typeSelect = document.getElementById('typeSelect');
const packageSelect = document.getElementById('packageSelect');

function getSelectedType() {
    return productChildren.find(c => c.id == typeSelect.value);
}

function populatePackageSelect() {
    const t = getSelectedType();
    const packages = (t && t.children) || [];
    packageSelect.innerHTML = packages.map(pkg => `<option value="${pkg.id}">${escapeHtml(pkg.name)}</option>`).join('');
}

function getSelectedVariant() {
    if (!isTwoLevel) {
        return getSelectedType();
    }
    const t = getSelectedType();
    if (!t || !t.children || !t.children.length) {
        return null;
    }
    return t.children.find(pkg => pkg.id == packageSelect.value);
}

function renderVariantCartArea() {
    const v = getSelectedVariant();
    if (!v) {
        const t = getSelectedType();
        const image = (t && t.image) || parentImage;
        detailImage.src = image ? 'uploads/' + image : 'https://placehold.co/400x300?text=No+Image';
        variantCartArea.innerHTML = '<p class="text-danger fw-bold">No packages available for this option.</p>';
        return;
    }
    detailPrice.textContent = Number(v.price || basePrice).toLocaleString() + ' RWF';
    const t = getSelectedType();
    const image = v.image || (t && t.image) || parentImage;
    detailImage.src = image ? 'uploads/' + image : 'https://placehold.co/400x300?text=No+Image';

    variantCartArea.innerHTML = `
        <div class="d-flex gap-2 align-items-center">
            <input type="number" id="variantQty" value="1" min="1" class="form-control" style="width:100px;">
            <button type="button" id="variantAddBtn" class="btn btn-dark ${v.in_cart ? 'added' : ''}">${v.in_cart ? '✓ In Cart' : 'Add to Cart'}</button>
        </div>
    `;

    document.getElementById('variantAddBtn').addEventListener('click', function () {
        const btn = this;
        const wasAlreadyInCart = btn.classList.contains('added');
        const qty = Math.max(1, parseInt(document.getElementById('variantQty').value, 10) || 1);
        btn.disabled = true;
        btn.textContent = 'Adding…';

        const fd = new FormData();
        fd.append('add_to_cart', '1');
        fd.append('quantity', qty);

        postAjax('product?id=' + v.id, fd).then(res => {
            btn.disabled = false;

            if (res.login_required) {
                btn.textContent = wasAlreadyInCart ? '✓ In Cart' : 'Add to Cart';
                window.location.href = res.redirect;
                return;
            }
            showToast(res.success ? 'success' : 'danger', res.message);
            if (res.success) {
                updateCartBadge(res.cart_total);
                btn.textContent = '✓ In Cart';
                btn.classList.add('added');
                v.in_cart = true;
            } else {
                btn.textContent = wasAlreadyInCart ? '✓ In Cart' : 'Add to Cart';
            }
        }).catch(() => {
            btn.disabled = false;
            btn.textContent = wasAlreadyInCart ? '✓ In Cart' : 'Add to Cart';
            showToast('danger', 'Network error. Please try again.');
        });
    });
}

if (isTwoLevel) {
    typeSelect.addEventListener('change', function () {
        populatePackageSelect();
        renderVariantCartArea();
    });
    packageSelect.addEventListener('change', renderVariantCartArea);
    populatePackageSelect();
} else {
    typeSelect.addEventListener('change', renderVariantCartArea);
}
renderVariantCartArea();
</script>
<?php else: ?>
<script>
document.getElementById('addToCartForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const btn = document.getElementById('addToCartSubmitBtn');
    const wasAlreadyInCart = btn.classList.contains('added');
    const restore = setFormLoading(form, 'Adding…');
    postAjax(window.location.pathname + window.location.search, new FormData(form)).then(res => {
        btn.disabled = false;

        if (res.login_required) {
            btn.textContent = wasAlreadyInCart ? '✓ In Cart' : 'Add to Cart';
            window.location.href = res.redirect;
            return;
        }
        showToast('success', res.message);
        updateCartBadge(res.cart_total);
        btn.textContent = '✓ In Cart';
        btn.classList.add('added');
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
