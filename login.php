<?php
require_once __DIR__ . '/config/database.php';

if (is_logged_in()) {
    redirect('shop');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $isEmail = str_contains($identifier, '@');
    $lookup = $isEmail ? mb_strtolower($identifier) : normalize_phone($identifier);

    $stmt = mysqli_prepare($conn, 'SELECT id, name, password, role, is_super FROM users WHERE ' . ($isEmail ? 'email' : 'phone') . ' = ?');
    mysqli_stmt_bind_param($stmt, 's', $lookup);
    mysqli_stmt_execute($stmt);
    $user = mysqli_stmt_get_result($stmt)->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['is_super'] = (bool) $user['is_super'];
        $redirectUrl = $user['role'] === 'admin' ? 'admin/dashboard' : 'shop';

        if (is_ajax()) {
            json_response(['success' => true, 'redirect' => $redirectUrl]);
        }
        redirect($redirectUrl);
    }

    $error = 'Invalid phone/email or password.';
    if (is_ajax()) {
        json_response(['success' => false, 'message' => $error]);
    }
} else {
    $error = '';
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="auth-card">
            <h3 class="mb-1">Welcome back</h3>
            <p class="text-muted mb-4">Log in to your Mpahira account</p>
            <div id="formError">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
            </div>
            <form method="post" id="loginForm">
                <div class="mb-3">
                    <label class="form-label">Phone or Email</label>
                    <input type="text" name="identifier" class="form-control" value="<?= e($_POST['identifier'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-dark w-100">Login</button>
            </form>
            <p class="mt-3 mb-0">No account yet? <a href="register">Register</a></p>
        </div>
    </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Logging in…');
    postAjax(window.location.pathname, new FormData(form)).then(res => {
        if (res.success) {
            window.location.href = res.redirect;
            return;
        }
        restore();
        document.getElementById('formError').innerHTML = '<div class="alert alert-danger">' + res.message + '</div>';
    }).catch(() => {
        restore();
        document.getElementById('formError').innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
