<?php
require_once __DIR__ . '/../config/database.php';
require_permission('products');

const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;
const UPLOAD_DIR = __DIR__ . '/../uploads/';

function handle_image_upload(array $file): array
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Image upload failed.'];
    }
    if ($file['size'] > MAX_IMAGE_BYTES) {
        return [null, 'Image must be smaller than 2MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_IMAGE_EXT, true)) {
        return [null, 'Image must be jpg, png, gif or webp.'];
    }
    $filename = uniqid('product_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        return [null, 'Could not save uploaded image.'];
    }
    return [$filename, null];
}

// 0 = standalone product, 1 = variant (child of a product), 2 = package (child of a variant).
function get_depth(mysqli $conn, int $id): ?int
{
    $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT parent_id FROM products WHERE id = ' . $id));
    if (!$row) {
        return null;
    }
    if ($row['parent_id'] === null) {
        return 0;
    }
    $parentRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT parent_id FROM products WHERE id = ' . (int) $row['parent_id']));
    if (!$parentRow || $parentRow['parent_id'] === null) {
        return 1;
    }
    return 2;
}

function collect_descendant_ids(mysqli $conn, int $id): array
{
    $ids = [];
    $result = mysqli_query($conn, 'SELECT id FROM products WHERE parent_id = ' . $id);
    while ($row = mysqli_fetch_assoc($result)) {
        $childId = (int) $row['id'];
        $ids[] = $childId;
        $ids = array_merge($ids, collect_descendant_ids($conn, $childId));
    }
    return $ids;
}

// Walks parent_id up from any product/variant/package id to its top-level ancestor.
function get_top_level_id(mysqli $conn, int $id): int
{
    while (true) {
        $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT parent_id FROM products WHERE id = ' . $id));
        if (!$row || $row['parent_id'] === null) {
            return $id;
        }
        $id = (int) $row['parent_id'];
    }
}

// Recomputes and persists the has_variants/display_price/search_blob cache shop.php reads
// for one top-level product id, using the same aggregation shop.php used to run live.
function recompute_product_cache(mysqli $conn, int $id): void
{
    $stmt = mysqli_prepare($conn, '
        SELECT
            EXISTS (SELECT 1 FROM products v WHERE v.parent_id = p.id AND v.status = "active") AS has_variants,
            COALESCE(
                NULLIF(
                    LEAST(
                        COALESCE(
                            (SELECT MIN(v.price) FROM products v
                             WHERE v.parent_id = p.id AND v.status = "active"
                               AND NOT EXISTS (SELECT 1 FROM products g WHERE g.parent_id = v.id AND g.status = "active")),
                            999999999.99
                        ),
                        COALESCE(
                            (SELECT MIN(g.price) FROM products v
                             JOIN products g ON g.parent_id = v.id
                             WHERE v.parent_id = p.id AND v.status = "active" AND g.status = "active"),
                            999999999.99
                        )
                    ),
                    999999999.99
                ),
                p.price
            ) AS display_price,
            CONCAT_WS(" ",
                p.name,
                (SELECT GROUP_CONCAT(v.name SEPARATOR " ") FROM products v WHERE v.parent_id = p.id),
                (SELECT GROUP_CONCAT(g.name SEPARATOR " ") FROM products v JOIN products g ON g.parent_id = v.id WHERE v.parent_id = p.id),
                (SELECT GROUP_CONCAT(pm.meta_value SEPARATOR " ") FROM product_meta pm WHERE pm.product_id = p.id)
            ) AS search_blob
        FROM products p
        WHERE p.id = ?
    ');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) {
        return;
    }

    $hasVariants = (int) $row['has_variants'];
    $displayPrice = (float) $row['display_price'];
    $searchBlob = (string) $row['search_blob'];
    $update = mysqli_prepare($conn, 'UPDATE products SET has_variants = ?, display_price = ?, search_blob = ? WHERE id = ?');
    mysqli_stmt_bind_param($update, 'idsi', $hasVariants, $displayPrice, $searchBlob, $id);
    mysqli_stmt_execute($update);
}

function fetch_products(mysqli $conn): array
{
    $result = mysqli_query($conn, '
        SELECT p.id, p.name, p.description, p.price, p.image, p.status, p.category_id, p.parent_id,
               c.name AS category_name,
               parent.name AS parent_name, parent.image AS parent_image,
               grandparent.image AS grandparent_image
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN products parent ON parent.id = p.parent_id
        LEFT JOIN products grandparent ON grandparent.id = parent.parent_id
        ORDER BY p.created_at DESC
    ');
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $metaResult = mysqli_query($conn, 'SELECT id, product_id, meta_key, meta_value FROM product_meta ORDER BY id');
    $metaByProduct = [];
    while ($row = mysqli_fetch_assoc($metaResult)) {
        $metaByProduct[(int) $row['product_id']][] = $row;
    }

    $byId = [];
    foreach ($rows as $row) {
        $row['meta'] = $metaByProduct[(int) $row['id']] ?? [];
        $byId[(int) $row['id']] = $row;
    }

    $childrenByParent = [];
    foreach ($byId as $row) {
        if ($row['parent_id']) {
            $childrenByParent[(int) $row['parent_id']][] = $row;
        }
    }
    foreach ($childrenByParent as &$group) {
        usort($group, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    }
    unset($group);

    $ordered = [];
    $addWithChildren = function (array $row, int $depth) use (&$addWithChildren, &$ordered, &$childrenByParent) {
        $row['depth'] = $depth;
        $row['has_children'] = isset($childrenByParent[(int) $row['id']]);
        $ordered[] = $row;
        foreach ($childrenByParent[(int) $row['id']] ?? [] as $child) {
            $addWithChildren($child, $depth + 1);
        }
    };
    foreach ($byId as $row) {
        if (!$row['parent_id']) {
            $addWithChildren($row, 0);
        }
    }

    return $ordered;
}

function render_product_rows(array $products): string
{
    if (!$products) {
        return '<tr><td colspan="6" class="text-center text-muted">No products yet.</td></tr>';
    }

    ob_start();
    foreach ($products as $product):
        $depth = $product['depth'];
        $displayImage = $product['image'] ?: ($product['parent_image'] ?: ($product['grandparent_image'] ?? null));
        $searchBlob = strtolower(trim(
            $product['name'] . ' ' . ($product['parent_name'] ?? '') . ' ' .
            implode(' ', array_column($product['meta'], 'meta_value'))
        )); ?>
        <tr data-search="<?= e($searchBlob) ?>" data-category-id="<?= (int) ($product['category_id'] ?? 0) ?>" data-id="<?= (int) $product['id'] ?>" data-parent-row="<?= (int) ($product['parent_id'] ?? 0) ?>" class="<?= $depth > 0 ? 'table-light' : '' ?>">
            <td>
                <span style="display:inline-block;padding-left:<?= $depth * 24 ?>px;">
                    <img src="<?= $displayImage ? '../uploads/' . e($displayImage) : 'https://placehold.co/60x60?text=No+Img' ?>" style="width:48px;height:48px;object-fit:cover;" loading="lazy">
                </span>
            </td>
            <td>
                <span style="display:inline-block;padding-left:<?= $depth * 24 ?>px;">
                    <?php if ($depth > 0): ?><span class="text-muted">&#8627;</span> <?php endif; ?>
                    <?php if ($product['has_children']): ?>
                        <button type="button" class="btn btn-sm btn-link p-0 me-1 row-toggle-btn" data-expanded="0" data-id="<?= (int) $product['id'] ?>">&#9656;</button>
                    <?php endif; ?>
                    <?= e($product['name']) ?>
                </span>
            </td>
            <td><?= e($product['category_name'] ?? '—') ?></td>
            <td><?= $product['has_children'] ? '—' : number_format((float) $product['price']) ?></td>
            <td><span class="badge bg-<?= $product['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e($product['status']) ?></span></td>
            <td class="text-end">
                <?php if ($depth === 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary product-add-variant-btn" data-id="<?= (int) $product['id'] ?>" data-name="<?= e($product['name']) ?>">+ Variant</button>
                <?php elseif ($depth === 1): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary product-add-package-btn" data-id="<?= (int) $product['id'] ?>" data-name="<?= e($product['name']) ?>">+ Package</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-dark product-edit-btn" data-id="<?= (int) $product['id'] ?>" data-depth="<?= $depth ?>">Edit</button>
                <button type="button" class="btn btn-sm btn-outline-danger product-delete-btn" data-id="<?= (int) $product['id'] ?>">Delete</button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

function parse_meta_rows(array $post): array
{
    $entries = $post['meta_text'] ?? [];
    $rows = [];
    foreach ($entries as $text) {
        $text = trim($text);
        if ($text === '') {
            continue;
        }
        $key = 'tag';
        $value = $text;
        if (strpos($text, ':') !== false) {
            [$candidateKey, $candidateValue] = explode(':', $text, 2);
            $candidateKey = trim($candidateKey);
            $candidateValue = trim($candidateValue);
            if ($candidateKey !== '' && $candidateValue !== '') {
                $key = $candidateKey;
                $value = $candidateValue;
            }
        }
        $rows[] = ['key' => $key, 'value' => $value];
    }
    return $rows;
}

function save_meta(mysqli $conn, int $productId, array $rows): void
{
    mysqli_query($conn, 'DELETE FROM product_meta WHERE product_id = ' . $productId);
    if (!$rows) {
        return;
    }
    $stmt = mysqli_prepare($conn, 'INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        mysqli_stmt_bind_param($stmt, 'iss', $productId, $row['key'], $row['value']);
        mysqli_stmt_execute($stmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $message = '';
    $type = 'success';

    if ($action === 'add' || $action === 'edit') {
        // A standalone top-level product: full fields, never has a parent.
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
        $description = trim($_POST['description'] ?? '');
        $price = (float) ($_POST['price'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $id = $action === 'edit' ? (int) $_POST['id'] : null;
        $metaRows = parse_meta_rows($_POST);

        [$uploadedImage, $uploadError] = handle_image_upload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

        if ($name === '' || $price < 0) {
            $type = 'danger';
            $message = 'Name and price are required and must be valid.';
        } elseif ($uploadError) {
            $type = 'danger';
            $message = $uploadError;
        } elseif ($action === 'add') {
            $stmt = mysqli_prepare($conn, 'INSERT INTO products (category_id, name, description, price, image, status) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'issdss', $categoryId, $name, $description, $price, $uploadedImage, $status);
            mysqli_stmt_execute($stmt);
            $newId = (int) mysqli_insert_id($conn);
            save_meta($conn, $newId, $metaRows);
            recompute_product_cache($conn, $newId);
            $message = 'Product added.';
        } else {
            if ($uploadedImage) {
                $old = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT image FROM products WHERE id = ' . $id));
                if (!empty($old['image']) && is_file(UPLOAD_DIR . $old['image'])) {
                    unlink(UPLOAD_DIR . $old['image']);
                }
                $stmt = mysqli_prepare($conn, 'UPDATE products SET category_id=?, name=?, description=?, price=?, image=?, status=? WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'issdssi', $categoryId, $name, $description, $price, $uploadedImage, $status, $id);
            } else {
                $stmt = mysqli_prepare($conn, 'UPDATE products SET category_id=?, name=?, description=?, price=?, status=? WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'issdsi', $categoryId, $name, $description, $price, $status, $id);
            }
            mysqli_stmt_execute($stmt);
            save_meta($conn, $id, $metaRows);
            recompute_product_cache($conn, $id);
            $message = 'Product updated.';
        }
    } elseif ($action === 'add_variant' || $action === 'edit_variant') {
        // A variant: just a name, grouping packages under a top-level product.
        $name = trim($_POST['name'] ?? '');
        [$uploadedImage, $uploadError] = handle_image_upload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

        if ($name === '') {
            $type = 'danger';
            $message = 'Name is required.';
        } elseif ($uploadError) {
            $type = 'danger';
            $message = $uploadError;
        } elseif ($action === 'add_variant') {
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            if (get_depth($conn, $parentId) !== 0) {
                $type = 'danger';
                $message = 'Invalid parent product.';
            } else {
                $parentRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT category_id FROM products WHERE id = ' . $parentId));
                $categoryId = $parentRow['category_id'] !== null ? (int) $parentRow['category_id'] : null;
                $stmt = mysqli_prepare($conn, 'INSERT INTO products (category_id, parent_id, name, price, image, status) VALUES (?, ?, ?, 0, ?, "active")');
                mysqli_stmt_bind_param($stmt, 'iiss', $categoryId, $parentId, $name, $uploadedImage);
                mysqli_stmt_execute($stmt);
                recompute_product_cache($conn, $parentId);
                $message = 'Variant added.';
            }
        } else {
            $id = (int) $_POST['id'];
            if (get_depth($conn, $id) !== 1) {
                $type = 'danger';
                $message = 'Invalid variant.';
            } else {
                if ($uploadedImage) {
                    $old = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT image FROM products WHERE id = ' . $id));
                    if (!empty($old['image']) && is_file(UPLOAD_DIR . $old['image'])) {
                        unlink(UPLOAD_DIR . $old['image']);
                    }
                    $stmt = mysqli_prepare($conn, 'UPDATE products SET name = ?, image = ? WHERE id = ?');
                    mysqli_stmt_bind_param($stmt, 'ssi', $name, $uploadedImage, $id);
                } else {
                    $stmt = mysqli_prepare($conn, 'UPDATE products SET name = ? WHERE id = ?');
                    mysqli_stmt_bind_param($stmt, 'si', $name, $id);
                }
                mysqli_stmt_execute($stmt);
                recompute_product_cache($conn, get_top_level_id($conn, $id));
                $message = 'Variant updated.';
            }
        }
    } elseif ($action === 'add_package' || $action === 'edit_package') {
        // A package: the actual purchasable item, with its own name and price.
        $name = trim($_POST['name'] ?? '');
        $price = (float) ($_POST['price'] ?? 0);
        [$uploadedImage, $uploadError] = handle_image_upload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

        if ($name === '' || $price < 0) {
            $type = 'danger';
            $message = 'Name and price are required and must be valid.';
        } elseif ($uploadError) {
            $type = 'danger';
            $message = $uploadError;
        } elseif ($action === 'add_package') {
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            if (get_depth($conn, $parentId) !== 1) {
                $type = 'danger';
                $message = 'Invalid variant.';
            } else {
                $parentRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT category_id FROM products WHERE id = ' . $parentId));
                $categoryId = $parentRow['category_id'] !== null ? (int) $parentRow['category_id'] : null;
                $stmt = mysqli_prepare($conn, 'INSERT INTO products (category_id, parent_id, name, price, image, status) VALUES (?, ?, ?, ?, ?, "active")');
                mysqli_stmt_bind_param($stmt, 'iisds', $categoryId, $parentId, $name, $price, $uploadedImage);
                mysqli_stmt_execute($stmt);
                recompute_product_cache($conn, get_top_level_id($conn, $parentId));
                $message = 'Package added.';
            }
        } else {
            $id = (int) $_POST['id'];
            // Depth 1 is included so a legacy leaf variant (created before Variants and
            // Packages were separate concepts) can still have its price edited here.
            if (!in_array(get_depth($conn, $id), [1, 2], true)) {
                $type = 'danger';
                $message = 'Invalid package.';
            } else {
                if ($uploadedImage) {
                    $old = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT image FROM products WHERE id = ' . $id));
                    if (!empty($old['image']) && is_file(UPLOAD_DIR . $old['image'])) {
                        unlink(UPLOAD_DIR . $old['image']);
                    }
                    $stmt = mysqli_prepare($conn, 'UPDATE products SET name = ?, price = ?, image = ? WHERE id = ?');
                    mysqli_stmt_bind_param($stmt, 'sdsi', $name, $price, $uploadedImage, $id);
                } else {
                    $stmt = mysqli_prepare($conn, 'UPDATE products SET name = ?, price = ? WHERE id = ?');
                    mysqli_stmt_bind_param($stmt, 'sdi', $name, $price, $id);
                }
                mysqli_stmt_execute($stmt);
                recompute_product_cache($conn, get_top_level_id($conn, $id));
                $message = 'Package updated.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $idList = implode(',', array_merge([$id], collect_descendant_ids($conn, $id)));
        $inUse = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM order_items WHERE product_id IN (' . $idList . ')'));

        if ((int) $inUse['c'] > 0) {
            $type = 'danger';
            $message = 'Cannot delete: this item (or one of its variants/packages) has order history. Set it to inactive instead.';
        } else {
            $parentRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT parent_id FROM products WHERE id = ' . $id));
            $affectedTopId = ($parentRow && $parentRow['parent_id'] !== null)
                ? get_top_level_id($conn, (int) $parentRow['parent_id'])
                : null;

            $imagesResult = mysqli_query($conn, 'SELECT image FROM products WHERE id IN (' . $idList . ')');
            while ($row = mysqli_fetch_assoc($imagesResult)) {
                if (!empty($row['image']) && is_file(UPLOAD_DIR . $row['image'])) {
                    unlink(UPLOAD_DIR . $row['image']);
                }
            }
            $stmt = mysqli_prepare($conn, 'DELETE FROM products WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            if ($affectedTopId !== null) {
                recompute_product_cache($conn, $affectedTopId);
            }
            $message = 'Deleted.';
        }
    }

    if (is_ajax()) {
        $products = fetch_products($conn);
        json_response([
            'success' => $type === 'success',
            'message' => $message,
            'rows' => render_product_rows($products),
            'products' => $products,
        ]);
    }

    flash_set($type, $message);
    redirect('products');
}

$categories = mysqli_query($conn, 'SELECT id, name FROM categories ORDER BY name');
$categoryOptions = [];
while ($row = mysqli_fetch_assoc($categories)) {
    $categoryOptions[] = $row;
}

$products = fetch_products($conn);

$pageTitle = 'Products';
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div class="d-flex gap-2">
        <input type="text" id="productSearch" class="form-control" placeholder="Search products..." autocomplete="off" style="max-width:220px;">
        <select id="productCategoryFilter" class="form-select" style="max-width:200px;">
            <option value="">All categories</option>
            <?php foreach ($categoryOptions as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="button" class="btn btn-dark text-nowrap" id="openAddModalBtn" data-bs-toggle="modal" data-bs-target="#productModal">+ Add Product</button>
</div>

<div class="table-responsive">
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th></th>
            <th>Name</th>
            <th>Category</th>
            <th>Price</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="productRows"><?= render_product_rows($products) ?></tbody>
</table>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="formTitle">Add Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="productForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="formName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="formCategoryId" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($categoryOptions as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="formDescription" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Search Metadata <small class="text-muted fw-normal">(optional keywords for search — e.g. "Origin: Rwanda" or just a keyword)</small></label>
                        <div id="metaRows"></div>
                        <button type="button" class="btn btn-sm btn-outline-dark" id="addMetaRowBtn">+ Add Keyword</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Price (RWF)</label>
                        <input type="number" step="0.01" min="0" name="price" id="formPrice" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="formStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="formImageLabel">Image</label>
                        <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                        <img id="formImagePreview" class="mt-2 d-none" style="height:80px;object-fit:cover;">
                    </div>
                    <button type="submit" class="btn btn-dark w-100" id="formSubmitBtn">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="variantModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="variantFormTitle">Add Variant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="variantForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="variantFormAction" value="add_variant">
                    <input type="hidden" name="id" id="variantFormId" value="">
                    <input type="hidden" name="parent_id" id="variantFormParentId" value="">
                    <div class="mb-3">
                        <label class="form-label">Name <small class="text-muted fw-normal">(e.g. Red)</small></label>
                        <input type="text" name="name" id="variantFormName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="variantFormImageLabel">Image</label>
                        <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                        <img id="variantFormImagePreview" class="mt-2 d-none" style="height:80px;object-fit:cover;">
                    </div>
                    <button type="submit" class="btn btn-dark w-100" id="variantFormSubmitBtn">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="packageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="packageFormTitle">Add Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="packageForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="packageFormAction" value="add_package">
                    <input type="hidden" name="id" id="packageFormId" value="">
                    <input type="hidden" name="parent_id" id="packageFormParentId" value="">
                    <div class="mb-3">
                        <label class="form-label">Name <small class="text-muted fw-normal">(e.g. 1kg)</small></label>
                        <input type="text" name="name" id="packageFormName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Price (RWF)</label>
                        <input type="number" step="0.01" min="0" name="price" id="packageFormPrice" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="packageFormImageLabel">Image</label>
                        <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                        <img id="packageFormImagePreview" class="mt-2 d-none" style="height:80px;object-fit:cover;">
                    </div>
                    <button type="submit" class="btn btn-dark w-100" id="packageFormSubmitBtn">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let productsData = <?= json_encode($products) ?>;

const form = document.getElementById('productForm');
const productModalEl = document.getElementById('productModal');
const productModal = new bootstrap.Modal(productModalEl);

const variantForm = document.getElementById('variantForm');
const variantModalEl = document.getElementById('variantModal');
const variantModal = new bootstrap.Modal(variantModalEl);

const packageForm = document.getElementById('packageForm');
const packageModalEl = document.getElementById('packageModal');
const packageModal = new bootstrap.Modal(packageModalEl);

function addMetaRow(m = {}) {
    const wrap = document.createElement('div');
    wrap.className = 'row g-1 mb-1 align-items-center meta-row';
    const prefill = (m.meta_key && m.meta_key !== 'tag') ? `${m.meta_key}: ${m.meta_value}` : (m.meta_value || '');
    wrap.innerHTML = `
        <div class="col-10"><input type="text" name="meta_text[]" class="form-control form-control-sm" placeholder="e.g. Origin: Rwanda, or just a keyword" value="${escapeHtml(prefill)}"></div>
        <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger remove-row-btn">&times;</button></div>
    `;
    document.getElementById('metaRows').appendChild(wrap);
}

document.getElementById('metaRows').addEventListener('click', function (e) {
    const removeBtn = e.target.closest('.remove-row-btn');
    if (removeBtn) removeBtn.closest('.meta-row').remove();
});
document.getElementById('addMetaRowBtn').addEventListener('click', () => addMetaRow());

function resetForm() {
    form.reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('formTitle').textContent = 'Add Product';
    document.getElementById('formSubmitBtn').textContent = 'Add';
    document.getElementById('formImageLabel').textContent = 'Image';
    document.getElementById('formImagePreview').classList.add('d-none');
    document.getElementById('metaRows').innerHTML = '';
}

function resetVariantForm() {
    variantForm.reset();
    document.getElementById('variantFormAction').value = 'add_variant';
    document.getElementById('variantFormId').value = '';
    document.getElementById('variantFormParentId').value = '';
    document.getElementById('variantFormTitle').textContent = 'Add Variant';
    document.getElementById('variantFormSubmitBtn').textContent = 'Add';
    document.getElementById('variantFormImageLabel').textContent = 'Image';
    document.getElementById('variantFormImagePreview').classList.add('d-none');
}

function resetPackageForm() {
    packageForm.reset();
    document.getElementById('packageFormAction').value = 'add_package';
    document.getElementById('packageFormId').value = '';
    document.getElementById('packageFormParentId').value = '';
    document.getElementById('packageFormTitle').textContent = 'Add Package';
    document.getElementById('packageFormSubmitBtn').textContent = 'Add';
    document.getElementById('packageFormImageLabel').textContent = 'Image';
    document.getElementById('packageFormImagePreview').classList.add('d-none');
}

document.getElementById('openAddModalBtn').addEventListener('click', resetForm);
productModalEl.addEventListener('hidden.bs.modal', resetForm);
variantModalEl.addEventListener('hidden.bs.modal', resetVariantForm);
packageModalEl.addEventListener('hidden.bs.modal', resetPackageForm);

document.getElementById('productRows').addEventListener('click', function (e) {
    const toggleBtn = e.target.closest('.row-toggle-btn');
    const editBtn = e.target.closest('.product-edit-btn');
    const deleteBtn = e.target.closest('.product-delete-btn');
    const addVariantBtn = e.target.closest('.product-add-variant-btn');
    const addPackageBtn = e.target.closest('.product-add-package-btn');

    if (toggleBtn) {
        const expanded = toggleBtn.dataset.expanded === '1';
        toggleBtn.dataset.expanded = expanded ? '0' : '1';
        toggleBtn.innerHTML = expanded ? '&#9656;' : '&#9662;';
        applyProductFilter();
        return;
    }

    if (addVariantBtn) {
        resetVariantForm();
        document.getElementById('variantFormParentId').value = addVariantBtn.dataset.id;
        document.getElementById('variantFormTitle').textContent = 'Add Variant of ' + addVariantBtn.dataset.name;
        variantModal.show();
    }

    if (addPackageBtn) {
        resetPackageForm();
        document.getElementById('packageFormParentId').value = addPackageBtn.dataset.id;
        document.getElementById('packageFormTitle').textContent = 'Add Package of ' + addPackageBtn.dataset.name;
        packageModal.show();
    }

    if (editBtn) {
        const p = productsData.find(x => x.id == editBtn.dataset.id);
        if (!p) return;
        const depth = Number(editBtn.dataset.depth);

        if (depth === 0) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = p.id;
            document.getElementById('formName').value = p.name;
            document.getElementById('formCategoryId').value = p.category_id || '';
            document.getElementById('formDescription').value = p.description || '';
            document.getElementById('formPrice').value = p.price;
            document.getElementById('formStatus').value = p.status;
            document.getElementById('formTitle').textContent = 'Edit Product';
            document.getElementById('formSubmitBtn').textContent = 'Update';
            document.getElementById('formImageLabel').textContent = 'Image (leave blank to keep current)';

            document.getElementById('metaRows').innerHTML = '';
            (p.meta || []).forEach(addMetaRow);

            const preview = document.getElementById('formImagePreview');
            if (p.image) {
                preview.src = '../uploads/' + p.image;
                preview.classList.remove('d-none');
            } else {
                preview.classList.add('d-none');
            }
            productModal.show();
        } else if (depth === 1 && p.has_children) {
            document.getElementById('variantFormAction').value = 'edit_variant';
            document.getElementById('variantFormId').value = p.id;
            document.getElementById('variantFormName').value = p.name;
            document.getElementById('variantFormTitle').textContent = 'Edit Variant';
            document.getElementById('variantFormSubmitBtn').textContent = 'Update';
            document.getElementById('variantFormImageLabel').textContent = 'Image (leave blank to keep current)';

            const variantPreview = document.getElementById('variantFormImagePreview');
            if (p.image) {
                variantPreview.src = '../uploads/' + p.image;
                variantPreview.classList.remove('d-none');
            } else {
                variantPreview.classList.add('d-none');
            }
            variantModal.show();
        } else {
            document.getElementById('packageFormAction').value = 'edit_package';
            document.getElementById('packageFormId').value = p.id;
            document.getElementById('packageFormName').value = p.name;
            document.getElementById('packageFormPrice').value = p.price;
            document.getElementById('packageFormTitle').textContent = 'Edit Package';
            document.getElementById('packageFormSubmitBtn').textContent = 'Update';
            document.getElementById('packageFormImageLabel').textContent = 'Image (leave blank to keep current)';

            const packagePreview = document.getElementById('packageFormImagePreview');
            if (p.image) {
                packagePreview.src = '../uploads/' + p.image;
                packagePreview.classList.remove('d-none');
            } else {
                packagePreview.classList.add('d-none');
            }
            packageModal.show();
        }
    }

    if (deleteBtn) {
        if (!confirm('Delete this item?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', deleteBtn.dataset.id);
        postAjax(window.location.pathname, fd).then(res => {
            document.getElementById('productRows').innerHTML = res.rows;
            productsData = res.products;
            showToast(res.success ? 'success' : 'danger', res.message);
            applyProductFilter();
        }).catch(() => showToast('danger', 'Network error. Please try again.'));
    }
});

function handleFormResponse(res) {
    showToast(res.success ? 'success' : 'danger', res.message);
    if (res.success) {
        document.getElementById('productRows').innerHTML = res.rows;
        productsData = res.products;
        applyProductFilter();
    }
    return res.success;
}

form.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(form, document.getElementById('formAction').value === 'edit' ? 'Updating…' : 'Adding…');
    postAjax(window.location.pathname, new FormData(form)).then(res => {
        restore();
        if (handleFormResponse(res)) productModal.hide();
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

variantForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(variantForm, document.getElementById('variantFormAction').value === 'edit_variant' ? 'Updating…' : 'Adding…');
    postAjax(window.location.pathname, new FormData(variantForm)).then(res => {
        restore();
        if (handleFormResponse(res)) variantModal.hide();
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

packageForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(packageForm, document.getElementById('packageFormAction').value === 'edit_package' ? 'Updating…' : 'Adding…');
    postAjax(window.location.pathname, new FormData(packageForm)).then(res => {
        restore();
        if (handleFormResponse(res)) packageModal.hide();
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

const productSearch = document.getElementById('productSearch');
const productCategoryFilter = document.getElementById('productCategoryFilter');

// With no search/category filter active, rows are shown or hidden based on
// whether every ancestor up to the top-level product has been expanded.
function isExpandedByCollapse(row) {
    const parentId = row.dataset.parentRow;
    if (!parentId || parentId === '0') return true;
    const parentRow = document.querySelector('#productRows tr[data-id="' + parentId + '"]');
    if (!parentRow) return true;
    const toggle = parentRow.querySelector('.row-toggle-btn');
    const parentExpanded = !toggle || toggle.dataset.expanded === '1';
    return parentExpanded && isExpandedByCollapse(parentRow);
}

function applyProductFilter() {
    const q = productSearch.value.trim().toLowerCase();
    const categoryId = productCategoryFilter.value;
    const filtering = q !== '' || categoryId !== '';

    document.querySelectorAll('#productRows tr[data-search]').forEach(function (row) {
        if (filtering) {
            const matchesSearch = row.dataset.search.indexOf(q) !== -1;
            const matchesCategory = !categoryId || row.dataset.categoryId === categoryId;
            row.style.display = matchesSearch && matchesCategory ? '' : 'none';
        } else {
            row.style.display = isExpandedByCollapse(row) ? '' : 'none';
        }
    });
}

productSearch.addEventListener('input', applyProductFilter);
productCategoryFilter.addEventListener('change', applyProductFilter);
applyProductFilter();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
