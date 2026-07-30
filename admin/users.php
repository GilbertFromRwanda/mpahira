<?php
require_once __DIR__ . '/../config/database.php';
require_super();

const USERS_PER_PAGE = 20;

function fetch_users(mysqli $conn, string $search, string $role, int $page, int $perPage): array
{
    $where = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($role === 'admin' || $role === 'customer') {
        $where[] = 'role = ?';
        $types .= 's';
        $params[] = $role;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM users $whereSql");
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $total = (int) mysqli_stmt_get_result($stmt)->fetch_assoc()['c'];

    $offset = ($page - 1) * $perPage;
    $stmt = mysqli_prepare($conn, "
        SELECT id, name, phone, email, role, is_super, created_at
        FROM users
        $whereSql
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    return ['rows' => $rows, 'total' => $total];
}

// Modules already granted to each of the given (non-super) system users, keyed by user_id.
function fetch_permissions_map(mysqli $conn, array $userIds): array
{
    $map = [];
    if (!$userIds) {
        return $map;
    }
    $ids = implode(',', array_map('intval', $userIds));
    $result = mysqli_query($conn, "SELECT user_id, module FROM user_permissions WHERE user_id IN ($ids)");
    while ($row = mysqli_fetch_assoc($result)) {
        $map[(int) $row['user_id']][] = $row['module'];
    }
    return $map;
}

function render_user_rows(array $users, int $currentUserId, array $permissionsMap): string
{
    if (!$users) {
        return '<tr><td colspan="5" class="text-center text-muted">No users found.</td></tr>';
    }

    ob_start();
    foreach ($users as $u):
        $id = (int) $u['id'];
        $isSuper = (bool) $u['is_super']; ?>
        <tr>
            <td><?= e($u['name']) ?></td>
            <td><?= e($u['phone']) ?></td>
            <td><?= e($u['email'] ?? '') ?></td>
            <td>
                <?php if ($isSuper): ?>
                    <span class="badge bg-dark">Super Admin</span>
                <?php elseif ($u['role'] === 'admin'): ?>
                    <span class="badge bg-success">System User</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Customer</span>
                <?php endif; ?>
            </td>
            <td class="text-end">
                <?php if ($id === $currentUserId): ?>
                    <span class="text-muted small">This is you</span>
                <?php elseif ($isSuper): ?>
                    <span class="text-muted small">&mdash;</span>
                <?php elseif ($u['role'] === 'admin'): ?>
                    <button type="button" class="btn btn-sm btn-outline-dark permissions-btn" data-id="<?= $id ?>" data-name="<?= e($u['name']) ?>" data-modules="<?= e(json_encode($permissionsMap[$id] ?? [])) ?>">Permissions</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary role-toggle-btn" data-id="<?= $id ?>" data-role="customer">Make Customer</button>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-dark role-toggle-btn" data-id="<?= $id ?>" data-role="admin">Make System User</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

function render_pagination(int $page, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $windowStart = max(1, $page - 2);
    $windowEnd = min($totalPages, $page + 2);

    ob_start(); ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page - 1 ?>">&laquo;</a>
            </li>
            <?php if ($windowStart > 1): ?>
                <li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>
                <?php if ($windowStart > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="#" data-page="<?= $p ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($windowEnd < $totalPages): ?>
                <?php if ($windowEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="#" data-page="<?= $totalPages ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page + 1 ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php
    return ob_get_clean();
}

$currentUserId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $message = '';
    $type = 'success';

    if ($action === 'set_role') {
        $id = (int) ($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'customer';

        if ($id === $currentUserId) {
            $type = 'danger';
            $message = "You can't change your own role.";
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE users SET role = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'si', $role, $id);
            mysqli_stmt_execute($stmt);

            if ($role === 'customer') {
                $stmt = mysqli_prepare($conn, 'DELETE FROM user_permissions WHERE user_id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $id);
                mysqli_stmt_execute($stmt);
            }

            $message = $role === 'admin'
                ? 'User promoted to system user. Use "Permissions" to grant them access to specific sections.'
                : 'User reverted to a normal customer account.';
        }
    } elseif ($action === 'set_permissions') {
        $id = (int) ($_POST['id'] ?? 0);
        $modules = array_values(array_intersect((array) ($_POST['modules'] ?? []), array_keys(PERMISSION_MODULES)));

        $check = mysqli_prepare($conn, 'SELECT role, is_super FROM users WHERE id = ?');
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $target = mysqli_stmt_get_result($check)->fetch_assoc();

        if ($id === $currentUserId || !$target || $target['role'] !== 'admin' || (int) $target['is_super'] === 1) {
            $type = 'danger';
            $message = 'Cannot set permissions for that user.';
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM user_permissions WHERE user_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);

            if ($modules) {
                $stmt = mysqli_prepare($conn, 'INSERT INTO user_permissions (user_id, module) VALUES (?, ?)');
                foreach ($modules as $module) {
                    mysqli_stmt_bind_param($stmt, 'is', $id, $module);
                    mysqli_stmt_execute($stmt);
                }
            }
            $message = 'Permissions updated.';
        }
    }

    if (is_ajax()) {
        json_response(['success' => $type === 'success', 'message' => $message]);
    }

    flash_set($type, $message);
    redirect('users');
}

$search = trim($_GET['q'] ?? '');
$role = $_GET['role'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = fetch_users($conn, $search, $role, $page, USERS_PER_PAGE);
$totalPages = max(1, (int) ceil($result['total'] / USERS_PER_PAGE));
$permissionsMap = fetch_permissions_map($conn, array_column($result['rows'], 'id'));

if (is_ajax()) {
    json_response([
        'rows' => render_user_rows($result['rows'], $currentUserId, $permissionsMap),
        'pagination' => render_pagination($page, $totalPages),
        'total' => $result['total'],
    ]);
}

$pageTitle = 'Users';
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<p class="text-muted small">A <strong>System User</strong> only sees the admin sections you explicitly grant via <strong>Permissions</strong>. A <strong>Super Admin</strong> has full, unrestricted access, including managing other users. A <strong>Customer</strong> can only shop and manage their own orders.</p>

<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div class="d-flex gap-2">
        <input type="text" id="userSearch" class="form-control" placeholder="Search users..." autocomplete="off" style="max-width:240px;" value="<?= e($search) ?>">
        <select id="userRoleFilter" class="form-select" style="max-width:160px;">
            <option value="">All roles</option>
            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>System User</option>
            <option value="customer" <?= $role === 'customer' ? 'selected' : '' ?>>Customer</option>
        </select>
    </div>
    <div class="text-muted small" id="userCount"><?= (int) $result['total'] ?> user<?= $result['total'] === 1 ? '' : 's' ?></div>
</div>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Role</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="userRows"><?= render_user_rows($result['rows'], $currentUserId, $permissionsMap) ?></tbody>
</table>

<div id="userPagination" class="d-flex justify-content-center"><?= render_pagination($page, $totalPages) ?></div>

<!-- Permissions modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Permissions for <span id="permissionsUserName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="permissionsForm">
                    <input type="hidden" name="action" value="set_permissions">
                    <input type="hidden" name="id" id="permissionsUserId" value="">
                    <?php foreach (PERMISSION_MODULES as $key => $label): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="modules[]" value="<?= e($key) ?>" id="perm_<?= e($key) ?>">
                            <label class="form-check-label" for="perm_<?= e($key) ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-dark w-100 mt-2">Save Permissions</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let currentPage = <?= (int) $page ?>;
let loading = false;

function loadUsers(page) {
    if (loading) return;
    loading = true;

    const params = new URLSearchParams();
    if (document.getElementById('userSearch').value.trim()) params.set('q', document.getElementById('userSearch').value.trim());
    if (document.getElementById('userRoleFilter').value) params.set('role', document.getElementById('userRoleFilter').value);
    params.set('page', page);

    getAjax(window.location.pathname + '?' + params.toString()).then(res => {
        document.getElementById('userRows').innerHTML = res.rows;
        document.getElementById('userPagination').innerHTML = res.pagination;
        document.getElementById('userCount').textContent = res.total + (res.total === 1 ? ' user' : ' users');
        currentPage = page;
        window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
        loading = false;
    }).catch(() => {
        loading = false;
        showToast('danger', 'Network error. Please try again.');
    });
}

let searchTimer;
document.getElementById('userSearch').addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadUsers(1), 350);
});
document.getElementById('userRoleFilter').addEventListener('change', () => loadUsers(1));

document.getElementById('userPagination').addEventListener('click', function (e) {
    const link = e.target.closest('.page-link[data-page]');
    if (!link || link.closest('.page-item.disabled')) return;
    e.preventDefault();
    const page = parseInt(link.dataset.page, 10);
    if (page && page !== currentPage) loadUsers(page);
});

const permissionsModalEl = document.getElementById('permissionsModal');
const permissionsModal = new bootstrap.Modal(permissionsModalEl);
const permissionsForm = document.getElementById('permissionsForm');

document.getElementById('userRows').addEventListener('click', function (e) {
    const roleBtn = e.target.closest('.role-toggle-btn');
    const permBtn = e.target.closest('.permissions-btn');

    if (roleBtn) {
        const makingAdmin = roleBtn.dataset.role === 'admin';
        if (!confirm(makingAdmin ? 'Make this user a system user? You can then grant them section access via Permissions.' : 'Revert this user to a normal customer account? Their permissions will be cleared.')) return;

        const fd = new FormData();
        fd.append('action', 'set_role');
        fd.append('id', roleBtn.dataset.id);
        fd.append('role', roleBtn.dataset.role);
        postAjax(window.location.pathname, fd).then(res => {
            showToast(res.success ? 'success' : 'danger', res.message);
            if (res.success) loadUsers(currentPage);
        }).catch(() => showToast('danger', 'Network error. Please try again.'));
        return;
    }

    if (permBtn) {
        document.getElementById('permissionsUserName').textContent = permBtn.dataset.name;
        document.getElementById('permissionsUserId').value = permBtn.dataset.id;
        const granted = JSON.parse(permBtn.dataset.modules || '[]');
        permissionsForm.querySelectorAll('input[name="modules[]"]').forEach(cb => {
            cb.checked = granted.includes(cb.value);
        });
        permissionsModal.show();
    }
});

permissionsForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(permissionsForm, 'Saving…');
    postAjax(window.location.pathname, new FormData(permissionsForm)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            permissionsModal.hide();
            loadUsers(currentPage);
        }
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
