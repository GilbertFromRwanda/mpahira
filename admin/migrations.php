<?php
require_once __DIR__ . '/../config/database.php';
require_super();

const MIGRATIONS_DIR = __DIR__ . '/../database';


function list_migration_files(): array
{
    $files = glob(MIGRATIONS_DIR . '/migration_*.sql') ?: [];
    $files = array_map('basename', $files);
    sort($files);
    return $files;
}

function fetch_applied_migrations(mysqli $conn): array
{
    $result = mysqli_query($conn, 'SELECT filename, status, log, applied_at FROM migrations');
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[$row['filename']] = $row;
    }
    return $rows;
}

function split_sql_statements(string $sql): array
{
    $statements = array_map('trim', explode(';', $sql));
    return array_values(array_filter($statements, fn ($s) => $s !== '' && $s[0] !== '#'));
}

function run_migration_file(mysqli $conn, string $filename): array
{
    $path = MIGRATIONS_DIR . '/' . $filename;
    $sql = file_get_contents($path);
    $statements = split_sql_statements($sql);

    $logLines = [];
    $allOk = true;

    foreach ($statements as $statement) {
        $preview = preg_replace('/\s+/', ' ', $statement);
        $preview = mb_strlen($preview) > 120 ? mb_substr($preview, 0, 120) . '…' : $preview;

        if (mysqli_query($conn, $statement)) {
            $logLines[] = "OK: {$preview}";
        } else {
            $allOk = false;
            $logLines[] = "ERROR: {$preview} -- " . mysqli_error($conn);
        }
    }

    if (!$statements) {
        $logLines[] = 'No executable statements found in file.';
        $allOk = false;
    }

    $status = $allOk ? 'success' : 'error';
    $log = implode("\n", $logLines);

    $stmt = mysqli_prepare($conn, '
        INSERT INTO migrations (filename, status, log) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), log = VALUES(log), applied_at = CURRENT_TIMESTAMP
    ');
    mysqli_stmt_bind_param($stmt, 'sss', $filename, $status, $log);
    mysqli_stmt_execute($stmt);

    return ['status' => $status, 'log' => $log];
}

function render_migration_rows(mysqli $conn): string
{
    $files = list_migration_files();
    $applied = fetch_applied_migrations($conn);

    if (!$files) {
        return '<tr><td colspan="4" class="text-center text-muted">No migration files found in /database.</td></tr>';
    }

    ob_start();
    foreach ($files as $file):
        $info = $applied[$file] ?? null;
    ?>
        <tr data-filename="<?= e($file) ?>">
            <td><code><?= e($file) ?></code></td>
            <td>
                <?php if (!$info): ?>
                    <span class="badge bg-secondary">Not run</span>
                <?php elseif ($info['status'] === 'success'): ?>
                    <span class="badge bg-success">Applied</span>
                <?php else: ?>
                    <span class="badge bg-danger">Ran with errors</span>
                <?php endif; ?>
            </td>
            <td class="text-muted small"><?= $info ? e(date('M j, Y g:i A', strtotime($info['applied_at']))) : '—' ?></td>
            <td class="text-end">
                <?php if ($info): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary migration-log-btn" data-log="<?= e($info['log']) ?>">View log</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-dark migration-run-btn" data-filename="<?= e($file) ?>"><?= $info ? 'Re-run' : 'Run' ?></button>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $filename = $_POST['filename'] ?? '';
    $success = false;
    $message = 'Invalid migration file.';
    $log = '';

    if (in_array($filename, list_migration_files(), true)) {
        $result = run_migration_file($conn, $filename);
        $success = $result['status'] === 'success';
        $log = $result['log'];
        $message = $success ? "{$filename} ran successfully." : "{$filename} ran with errors — check the log.";
    }

    if (is_ajax()) {
        json_response([
            'success' => $success,
            'message' => $message,
            'log' => $log,
            'rows' => render_migration_rows($conn),
        ]);
    }

    flash_set($success ? 'success' : 'danger', $message);
    redirect('migrations');
}

$pageTitle = 'Migrations';
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<p class="text-muted small">Runs the <code>.sql</code> files in <code>/database</code> against this database, statement by statement. Some statements (e.g. adding a column that already exists) may safely error on a re-run &mdash; check the log for details.</p>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>File</th>
            <th>Status</th>
            <th>Last run</th>
            <th></th>
        </tr>
    </thead>
    <tbody id="migrationRows"><?= render_migration_rows($conn) ?></tbody>
</table>

<div class="modal fade" id="logModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Migration log</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="logContent" class="small mb-0" style="white-space: pre-wrap;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
const logModalEl = document.getElementById('logModal');
const logModal = new bootstrap.Modal(logModalEl);

document.getElementById('migrationRows').addEventListener('click', function (e) {
    const runBtn = e.target.closest('.migration-run-btn');
    const logBtn = e.target.closest('.migration-log-btn');

    if (logBtn) {
        document.getElementById('logContent').textContent = logBtn.dataset.log || '(empty)';
        logModal.show();
    }

    if (runBtn) {
        if (!confirm('Run ' + runBtn.dataset.filename + ' against the database?')) return;
        const originalText = runBtn.textContent;
        runBtn.disabled = true;
        runBtn.textContent = 'Running…';

        const fd = new FormData();
        fd.append('action', 'run');
        fd.append('filename', runBtn.dataset.filename);

        postAjax(window.location.pathname, fd).then(res => {
            document.getElementById('migrationRows').innerHTML = res.rows;
            showToast(res.success ? 'success' : 'danger', res.message);
        }).catch(() => {
            runBtn.disabled = false;
            runBtn.textContent = originalText;
            showToast('danger', 'Network error. Please try again.');
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
