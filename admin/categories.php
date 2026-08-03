<?php
require_once __DIR__ . '/../config/database.php';
require_permission('categories');

function fetch_categories(mysqli $conn): array
{
    $result = mysqli_query($conn, '
        SELECT c.id, c.name, c.description, COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id
        GROUP BY c.id, c.name, c.description
        ORDER BY c.name
    ');
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function render_category_rows(array $categories): string
{
    if (!$categories) {
        return '<tr><td colspan="3" class="text-center text-muted">No categories yet.</td></tr>';
    }

    ob_start();
    foreach ($categories as $cat): ?>
        <tr data-name="<?= e(strtolower($cat['name'])) ?>">
            <td><?= e($cat['name']) ?></td>
            <td><?= (int) $cat['product_count'] ?></td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-dark category-edit-btn" data-id="<?= (int) $cat['id'] ?>">Edit</button>
                <?php if ((int) $cat['product_count'] === 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger category-delete-btn" data-id="<?= (int) $cat['id'] ?>">Delete</button>
                <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled title="Reassign products first">Delete</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $message = '';
    $type = 'success';

    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $type = 'danger';
            $message = 'Category name is required.';
        } elseif ($action === 'add') {
            $stmt = mysqli_prepare($conn, 'INSERT INTO categories (name, description) VALUES (?, ?)');
            mysqli_stmt_bind_param($stmt, 'ss', $name, $description);
            mysqli_stmt_execute($stmt);
            $message = 'Category added.';
        } else {
            $id = (int) $_POST['id'];
            $stmt = mysqli_prepare($conn, 'UPDATE categories SET name = ?, description = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'ssi', $name, $description, $id);
            mysqli_stmt_execute($stmt);
            $message = 'Category updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = mysqli_prepare($conn, 'DELETE FROM categories WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $message = 'Category deleted.';
    }

    if (is_ajax()) {
        $categories = fetch_categories($conn);
        json_response([
            'success' => $type === 'success',
            'message' => $message,
            'rows' => render_category_rows($categories),
            'categories' => $categories,
        ]);
    }

    flash_set($type, $message);
    redirect('categories');
}

$categories = fetch_categories($conn);

$pageTitle = 'Categories';
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 gap-2">
    <div class="flex-grow-1" style="max-width:320px;">
        <input type="text" id="categorySearch" class="form-control" placeholder="Search categories..." autocomplete="off">
    </div>
    <button type="button" class="btn btn-dark text-nowrap" id="openAddModalBtn" data-bs-toggle="modal" data-bs-target="#categoryModal">+ Add Category</button>
</div>

<div class="table-responsive">
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>Name</th>
            <th>Products</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="categoryRows"><?= render_category_rows($categories) ?></tbody>
</table>
</div>

<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="formTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="categoryForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="formName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="formDescription" class="form-control" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-dark w-100" id="formSubmitBtn">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let categoriesData = <?= json_encode($categories) ?>;

const form = document.getElementById('categoryForm');
const categoryModalEl = document.getElementById('categoryModal');
const categoryModal = new bootstrap.Modal(categoryModalEl);

function resetForm() {
    form.reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('formTitle').textContent = 'Add Category';
    document.getElementById('formSubmitBtn').textContent = 'Add';
}

document.getElementById('openAddModalBtn').addEventListener('click', resetForm);
categoryModalEl.addEventListener('hidden.bs.modal', resetForm);

document.getElementById('categoryRows').addEventListener('click', function (e) {
    const editBtn = e.target.closest('.category-edit-btn');
    const deleteBtn = e.target.closest('.category-delete-btn');

    if (editBtn) {
        const cat = categoriesData.find(c => c.id == editBtn.dataset.id);
        if (!cat) return;
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = cat.id;
        document.getElementById('formName').value = cat.name;
        document.getElementById('formDescription').value = cat.description || '';
        document.getElementById('formTitle').textContent = 'Edit Category';
        document.getElementById('formSubmitBtn').textContent = 'Update';
        categoryModal.show();
    }

    if (deleteBtn) {
        if (!confirm('Delete this category?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', deleteBtn.dataset.id);
        postAjax(window.location.pathname, fd).then(res => {
            document.getElementById('categoryRows').innerHTML = res.rows;
            categoriesData = res.categories;
            showToast(res.success ? 'success' : 'danger', res.message);
            applyCategoryFilter();
        }).catch(() => showToast('danger', 'Network error. Please try again.'));
    }
});

form.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(form, document.getElementById('formAction').value === 'edit' ? 'Updating…' : 'Adding…');
    postAjax(window.location.pathname, new FormData(form)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            document.getElementById('categoryRows').innerHTML = res.rows;
            categoriesData = res.categories;
            applyCategoryFilter();
            categoryModal.hide();
        }
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

const categorySearch = document.getElementById('categorySearch');

function applyCategoryFilter() {
    const q = categorySearch.value.trim().toLowerCase();
    document.querySelectorAll('#categoryRows tr[data-name]').forEach(function (row) {
        row.style.display = row.dataset.name.indexOf(q) === -1 ? 'none' : '';
    });
}

categorySearch.addEventListener('input', applyCategoryFilter);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
