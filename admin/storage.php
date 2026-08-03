<?php
require_once __DIR__ . '/../config/database.php';
require_super();

const PROOFS_DIR = __DIR__ . '/../uploads/payment_proofs';

function format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function scan_proof_folders(): array
{
    $folders = [];
    $years = glob(PROOFS_DIR . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($years as $yearPath) {
        $year = basename($yearPath);
        if (!preg_match('/^\d{4}$/', $year)) {
            continue;
        }
        $months = glob($yearPath . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($months as $monthPath) {
            $month = basename($monthPath);
            if (!preg_match('/^\d{2}$/', $month)) {
                continue;
            }
            $files = array_filter(glob($monthPath . '/*') ?: [], 'is_file');
            $size = array_sum(array_map('filesize', $files));
            $folders[] = [
                'year' => $year,
                'month' => $month,
                'path' => "$year/$month",
                'label' => date('n/Y', strtotime("$year-$month-01")),
                'count' => count($files),
                'size' => $size,
            ];
        }
    }
    usort($folders, fn ($a, $b) => strcmp($a['path'], $b['path']));
    return $folders;
}

function delete_dir_recursive(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? delete_dir_recursive($path) : unlink($path);
    }
    return rmdir($dir);
}

function render_storage_rows(): string
{
    $folders = scan_proof_folders();
    if (!$folders) {
        return '<tr><td colspan="4" class="text-center text-muted">No payment proof folders yet.</td></tr>';
    }

    ob_start();
    foreach ($folders as $f): ?>
        <tr data-path="<?= e($f['path']) ?>">
            <td><?= e($f['label']) ?></td>
            <td><?= (int) $f['count'] ?> file<?= $f['count'] === 1 ? '' : 's' ?></td>
            <td><?= e(format_bytes($f['size'])) ?></td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger storage-delete-btn" data-path="<?= e($f['path']) ?>" data-label="<?= e($f['label']) ?>">Delete</button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $path = $_POST['path'] ?? '';
    $success = false;
    $message = 'Invalid folder.';

    // Path must be exactly YYYY/MM — guards against traversal outside PROOFS_DIR.
    if (preg_match('#^\d{4}/\d{2}$#', $path)) {
        $dir = PROOFS_DIR . '/' . $path;
        if (is_dir($dir)) {
            delete_dir_recursive($dir);
            [$year, $month] = explode('/', $path);
            $prefix = "payment_proofs/$year/$month/";
            $stmt = mysqli_prepare($conn, "UPDATE orders SET payment_proof = NULL WHERE payment_proof LIKE CONCAT(?, '%')");
            mysqli_stmt_bind_param($stmt, 's', $prefix);
            mysqli_stmt_execute($stmt);
            $success = true;
            $message = 'Folder deleted and freed disk space.';
        } else {
            $message = 'Folder not found.';
        }
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'rows' => render_storage_rows(),
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
    redirect('storage');
}

$pageTitle = 'Storage';
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<p class="text-muted small">Uploaded payment proofs are stored by month under <code>uploads/payment_proofs/</code>. Deleting an older folder frees disk space but permanently removes those files &mdash; any order from that month will lose its "View uploaded payment proof" link.</p>

<div class="table-responsive">
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>Month</th>
            <th>Files</th>
            <th>Size</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="storageRows"><?= render_storage_rows() ?></tbody>
</table>
</div>

<script>
document.getElementById('storageRows').addEventListener('click', function (e) {
    const btn = e.target.closest('.storage-delete-btn');
    if (!btn) return;

    if (!confirm('Permanently delete all payment proofs from ' + btn.dataset.label + '? This cannot be undone.')) return;

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Deleting…';

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('path', btn.dataset.path);

    postAjax(window.location.pathname, fd).then(res => {
        document.getElementById('storageRows').innerHTML = res.rows;
        showToast(res.success ? 'success' : 'danger', res.message);
    }).catch(() => {
        btn.disabled = false;
        btn.textContent = originalText;
        showToast('danger', 'Network error. Please try again.');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
