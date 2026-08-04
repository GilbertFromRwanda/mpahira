<?php
require_once __DIR__ . '/config/database.php';

const PRODUCTS_PER_PAGE = 12;

// Substitutes bound params back into a prepared-statement SQL template for debug display —
// not for re-execution. Values are quoted/escaped per bind type, matching mysqli_stmt_bind_param's type string.
function interpolate_query(string $sql, string $types, array $params, mysqli $conn): string
{
    $i = 0;
    return preg_replace_callback('/\?/', function () use (&$i, $types, $params, $conn) {
        $value = $params[$i];
        $type = $types[$i];
        $i++;

        if ($type === 'i') {
            return (string) (int) $value;
        }
        if ($type === 'd') {
            return (string) (float) $value;
        }
        return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
    }, $sql);
}

function fetch_cart_product_ids(mysqli $conn): array
{
    if (!is_logged_in()) {
        return [];
    }

    $userId = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, '
        SELECT ci.product_id
        FROM cart_items ci
        JOIN carts c ON c.id = ci.cart_id
        WHERE c.user_id = ?
    ');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $ids[(int) $row['product_id']] = true;
    }
    return $ids;
}

function render_product_grid(array $products, array $cartProductIds = []): string
{
    if (!$products) {
        return '<p class="text-muted">No products found.</p>';
    }

    ob_start();
    foreach ($products as $product):
        $hasVariants = (bool) $product['has_variants'];
        $inCart = !$hasVariants && isset($cartProductIds[(int) $product['id']]); ?>
        <div class="card product-card">
            <div class="product-image-wrap">
                <img src="<?= $product['image'] ? 'uploads/' . e($product['image']) : 'https://placehold.co/300x220?text=No+Image' ?>" class="card-img-top product-view-trigger" data-id="<?= (int) $product['id'] ?>" alt="<?= e($product['name']) ?>" loading="lazy" role="button">
                <span class="in-cart-badge <?= $inCart ? '' : 'd-none' ?>">&check; In Cart</span>
            </div>
            <div class="card-body d-flex flex-column">
                <h6 class="card-title product-view-trigger" data-id="<?= (int) $product['id'] ?>" role="button"><?= e($product['name']) ?></h6>
                <p class="fw-bold mb-2"><?= $hasVariants ? 'From ' : '' ?><?= number_format((float) $product['display_price']) ?> RWF</p>
                <?php if ($hasVariants): ?>
                    <button type="button" class="btn btn-dark btn-sm add-to-cart-btn" data-id="<?= (int) $product['id'] ?>" data-has-variants="1" title="Choose an option">Choose option</button>
                <?php else: ?>
                    <div class="d-flex gap-1 cart-controls">
                        <input type="number" class="form-control form-control-sm qty-input flex-grow-1" value="1" min="1">
                        <button type="button" class="btn btn-dark btn-sm add-to-cart-btn <?= $inCart ? 'added' : '' ?>" data-id="<?= (int) $product['id'] ?>" data-has-variants="0" title="Add to cart"><?= $inCart ? '✓' : '+' ?></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

$categoryId = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * PRODUCTS_PER_PAGE;

$sql = "
    SELECT p.id, p.name, p.price, p.image, p.has_variants, p.display_price
    FROM products p
    WHERE p.status = 'active' AND p.parent_id IS NULL
";
$params = [];
$types = '';

if ($categoryId > 0) {
    $sql .= ' AND p.category_id = ?';
    $types .= 'i';
    $params[] = $categoryId;
}

$booleanSearch = build_fulltext_boolean_query($search);
if ($booleanSearch !== '') {
    $sql .= ' AND MATCH (p.search_blob) AGAINST (? IN BOOLEAN MODE)';
    $types .= 's';
    $params[] = $booleanSearch;
}

$sql .= ' ORDER BY p.created_at DESC LIMIT ? OFFSET ?';
$types .= 'ii';
$params[] = PRODUCTS_PER_PAGE + 1;
$params[] = $offset;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$products = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$hasMore = count($products) > PRODUCTS_PER_PAGE;
if ($hasMore) {
    array_pop($products);
}

$cartProductIds = fetch_cart_product_ids($conn);
// 'sql' => interpolate_query($sql, $types, $params, $conn)

if (is_ajax()) {
    json_response(['html' => render_product_grid($products, $cartProductIds), 'has_more' => $hasMore, 
    ]);
}

$categories = mysqli_query($conn, 'SELECT id, name FROM categories ORDER BY name');

$pageTitle = 'Shop';
require_once __DIR__ . '/includes/header.php';
?>

<div class="product-grid" id="productGrid"><?= render_product_grid($products, $cartProductIds) ?></div>

<div id="scrollSentinel" class="text-center text-muted py-4 <?= $hasMore ? '' : 'd-none' ?>">Loading more…</div>

<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productModalTitle">Loading…</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="productModalBody" class="text-center text-muted py-5">Loading…</div>
            </div>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let hasMore = <?= json_encode($hasMore) ?>;
let loading = false;

function buildFilterParams() {
    return new URLSearchParams(new FormData(document.getElementById('headerSearchForm')));
}

function loadPage(page, append) {
    if (loading) return;
    loading = true;

    const sentinel = document.getElementById('scrollSentinel');
    sentinel.classList.remove('d-none');
    sentinel.textContent = 'Loading more…';

    const params = buildFilterParams();
    params.set('page', page);

    getAjax(window.location.pathname + '?' + params.toString()).then(res => {
        const grid = document.getElementById('productGrid');
        if (append) {
            grid.insertAdjacentHTML('beforeend', res.html);
        } else {
            grid.innerHTML = res.html;
        }
        currentPage = page;
        hasMore = res.has_more;
        sentinel.classList.toggle('d-none', !hasMore);
        loading = false;
    }).catch(() => {
        loading = false;
        sentinel.classList.add('d-none');
    });
}

function applyFilters() {
    const params = buildFilterParams();
    window.history.pushState({}, '', window.location.pathname + '?' + params.toString());
    loadPage(1, false);
}

let searchTimer;
document.getElementById('headerSearchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});
document.getElementById('headerCategorySelect').addEventListener('change', applyFilters);
document.getElementById('headerSearchForm').addEventListener('submit', function (e) {
    e.preventDefault();
    clearTimeout(searchTimer);
    applyFilters();
});

new IntersectionObserver(function (entries) {
    if (entries[0].isIntersecting && hasMore && !loading) {
        loadPage(currentPage + 1, true);
    }
}, { rootMargin: '300px' }).observe(document.getElementById('scrollSentinel'));

const productModalEl = document.getElementById('productModal');
const productModal = new bootstrap.Modal(productModalEl);

function renderProductModal(p) {
    document.getElementById('productModalTitle').textContent = p.name;

    const imageUrl = p.image ? 'uploads/' + p.image : 'https://placehold.co/400x300?text=No+Image';
    const hasChildren = p.children && p.children.length > 0;

    let priceHtml, actionHtml;
    if (hasChildren) {
        priceHtml = `<p class="fs-4 fw-bold" id="modalDetailPrice">${Number(p.price).toLocaleString()} RWF</p>`;
        actionHtml = `
            <div class="mb-3" id="modalVariantPickers"></div>
            <div id="modalVariantCartArea"></div>`;
    } else {
        priceHtml = `<p class="fs-4 fw-bold">${Number(p.price).toLocaleString()} RWF</p>`;
        actionHtml = `<button type="button" id="modalAddToCartBtn" class="btn btn-dark ${p.in_cart ? 'added' : ''}">${p.in_cart ? '✓ In Cart' : 'Add to Cart'}</button>`;
    }

    document.getElementById('productModalBody').innerHTML = `
        <div class="row g-4">
            <div class="col-md-5">
                <img id="modalDetailImage" src="${imageUrl}" class="img-fluid rounded" alt="${escapeHtml(p.name)}" loading="lazy">
            </div>
            <div class="col-md-7">
                ${priceHtml}
                <p>${escapeHtml(p.description).replace(/\n/g, '<br>')}</p>
                ${actionHtml}
            </div>
        </div>`;

    if (hasChildren) {
        setupModalVariantPicker(p);
    } else {
        setupModalAddToCart(p.id);
    }
}

function setupModalVariantPicker(p) {
    const pickersEl = document.getElementById('modalVariantPickers');
    const area = document.getElementById('modalVariantCartArea');
    const priceEl = document.getElementById('modalDetailPrice');
    const imageEl = document.getElementById('modalDetailImage');

    // Same two-level logic as the product page: if a child itself has children,
    // it's a Type with Packages underneath; otherwise each child is directly purchasable.
    const isTwoLevel = p.children.some(c => c.children && c.children.length > 0);

    let pickersHtml = `
        <label class="form-label">Type</label>
        <select id="modalTypeSelect" class="form-select ${isTwoLevel ? 'mb-2' : ''}">
            ${p.children.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('')}
        </select>`;
    if (isTwoLevel) {
        pickersHtml += `
            <label class="form-label">Package</label>
            <select id="modalPackageSelect" class="form-select"></select>`;
    }
    pickersEl.innerHTML = pickersHtml;

    const typeSelect = document.getElementById('modalTypeSelect');
    const packageSelect = document.getElementById('modalPackageSelect');

    function getSelectedType() {
        return p.children.find(c => c.id == typeSelect.value);
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

    function render() {
        const v = getSelectedVariant();
        if (!v) {
            const t = getSelectedType();
            const image = (t && t.image) || p.image;
            imageEl.src = image ? 'uploads/' + image : 'https://placehold.co/400x300?text=No+Image';
            area.innerHTML = '<p class="text-danger fw-bold">No packages available for this option.</p>';
            return;
        }
        priceEl.textContent = Number(v.price || p.price).toLocaleString() + ' RWF';
        const t = getSelectedType();
        const image = v.image || (t && t.image) || p.image;
        imageEl.src = image ? 'uploads/' + image : 'https://placehold.co/400x300?text=No+Image';

        area.innerHTML = `
            <div class="d-flex gap-2 align-items-center">
                <input type="number" id="modalVariantQty" value="1" min="1" class="form-control" style="width:100px;">
                <button type="button" id="modalVariantAddBtn" class="btn btn-dark ${v.in_cart ? 'added' : ''}">${v.in_cart ? '✓ In Cart' : 'Add to Cart'}</button>
            </div>`;

        document.getElementById('modalVariantAddBtn').addEventListener('click', function () {
            const btn = this;
            const wasAlreadyInCart = btn.classList.contains('added');
            const qty = Math.max(1, parseInt(document.getElementById('modalVariantQty').value, 10) || 1);
            btn.disabled = true;
            btn.textContent = '…';

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
                    setTimeout(() => productModal.hide(), 600);
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
            render();
        });
        packageSelect.addEventListener('change', render);
        populatePackageSelect();
    } else {
        typeSelect.addEventListener('change', render);
    }
    render();
}

function setupModalAddToCart(productId) {
    document.getElementById('modalAddToCartBtn').addEventListener('click', function () {
        const btn = this;
        const wasAlreadyInCart = btn.classList.contains('added');
        btn.disabled = true;
        btn.textContent = '…';

        const fd = new FormData();
        fd.append('add_to_cart', '1');
        fd.append('quantity', '1');

        postAjax('product?id=' + productId, fd).then(res => {
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

            const gridBtn = document.querySelector('.add-to-cart-btn[data-id="' + productId + '"]');
            if (gridBtn) {
                gridBtn.classList.add('added');
                gridBtn.textContent = '✓';
                const badge = gridBtn.closest('.product-card').querySelector('.in-cart-badge');
                if (badge) badge.classList.remove('d-none');
            }

            setTimeout(function () {
                productModal.hide();
            }, 600);
        }).catch(() => {
            btn.disabled = false;
            btn.textContent = wasAlreadyInCart ? '✓ In Cart' : 'Add to Cart';
            showToast('danger', 'Network error. Please try again.');
        });
    });
}

document.getElementById('productGrid').addEventListener('click', function (e) {
    const viewTrigger = e.target.closest('.product-view-trigger');
    const variantPickerBtn = e.target.closest('.add-to-cart-btn[data-has-variants="1"]');

    if (viewTrigger || variantPickerBtn) {
        const id = viewTrigger ? viewTrigger.dataset.id : variantPickerBtn.dataset.id;
        document.getElementById('productModalTitle').textContent = 'Loading…';
        document.getElementById('productModalBody').innerHTML = '<div class="text-center text-muted py-5">Loading…</div>';
        productModal.show();

        getAjax('product?id=' + id).then(res => {
            if (res.success) {
                renderProductModal(res.product);
            } else {
                document.getElementById('productModalBody').innerHTML = '<p class="text-muted">' + res.message + '</p>';
            }
        }).catch(() => {
            document.getElementById('productModalBody').innerHTML = '<p class="text-danger">Failed to load product.</p>';
        });
        return;
    }

    const cartBtn = e.target.closest('.add-to-cart-btn[data-has-variants="0"]');
    if (cartBtn) {
        const card = cartBtn.closest('.product-card');
        const qtyInput = card.querySelector('.qty-input');
        const quantity = Math.max(1, parseInt(qtyInput.value, 10) || 1);
        const wasAlreadyInCart = cartBtn.classList.contains('added');

        cartBtn.disabled = true;
        cartBtn.textContent = '…';

        const fd = new FormData();
        fd.append('add_to_cart', '1');
        fd.append('quantity', quantity);

        postAjax('product?id=' + cartBtn.dataset.id, fd).then(res => {
            cartBtn.disabled = false;

            if (res.login_required) {
                cartBtn.textContent = wasAlreadyInCart ? '✓' : '+';
                window.location.href = res.redirect;
                return;
            }
            showToast('success', res.message);
            updateCartBadge(res.cart_total);
            qtyInput.value = 1;

            cartBtn.textContent = '✓';
            cartBtn.classList.add('added');
            card.querySelector('.in-cart-badge').classList.remove('d-none');

            card.classList.add('just-added');
            setTimeout(function () {
                card.classList.remove('just-added');
            }, 700);
        }).catch(() => {
            cartBtn.disabled = false;
            cartBtn.textContent = wasAlreadyInCart ? '✓' : '+';
            showToast('danger', 'Network error. Please try again.');
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
