<?php
require_once __DIR__ . '/config/database.php';

if (is_logged_in()) {
    redirect('index');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_text($_POST['name'] ?? '', 150);
    $phone = normalize_phone($_POST['phone'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($name === '' || $phone === '' || $password === '') {
        $errors[] = 'Name, phone and password are required.';
    }
    if ($name !== '' && mb_strlen($name) < 2) {
        $errors[] = 'Please enter your full name.';
    }
    if ($phone !== '' && !preg_match('/^\+?\d{8,15}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($password !== '' && mb_strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (!$errors) {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE phone = ?');
        mysqli_stmt_bind_param($stmt, 's', $phone);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_get_result($stmt)->fetch_assoc()) {
            $errors[] = 'This phone number is already registered.';
        }
    }

    if (!$errors && $email !== '') {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ?');
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_get_result($stmt)->fetch_assoc()) {
            $errors[] = 'This email address is already registered.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // email is UNIQUE — must be stored as NULL (not '') so multiple
        // no-email signups don't collide on an empty-string duplicate.
        $emailToStore = $email !== '' ? $email : null;
        $stmt = mysqli_prepare($conn, 'INSERT INTO users (name, phone, email, password, role) VALUES (?, ?, ?, ?, "customer")');
        mysqli_stmt_bind_param($stmt, 'ssss', $name, $phone, $emailToStore, $hash);

        if (mysqli_stmt_execute($stmt)) {
            $userId = mysqli_insert_id($conn);
            $_SESSION['user_id'] = $userId;
            $_SESSION['name'] = $name;
            $_SESSION['role'] = 'customer';

            if (is_ajax()) {
                json_response(['success' => true, 'redirect' => 'index']);
            }
            redirect('index');
        }
        $errors[] = 'Registration failed. Please try again.';
    }

    if ($errors && is_ajax()) {
        json_response(['success' => false, 'errors' => $errors]);
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="auth-card">
            <h3 class="mb-1">Create an account</h3>
            <p class="text-muted mb-4">Join Mpahira to start ordering</p>
            <div id="formError">
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
            <form method="post" id="registerForm">
                <div class="mb-3">
                    <label class="form-label">Full name</label>
                    <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email (optional)</label>
                    <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-dark w-100">Register</button>
            </form>
            <p class="mt-3 mb-0">Already have an account? <a href="login">Login</a></p>
        </div>
    </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Registering…');
    postAjax(window.location.pathname, new FormData(form)).then(res => {
        if (res.success) {
            window.location.href = res.redirect;
            return;
        }
        restore();
        document.getElementById('formError').innerHTML = res.errors.map(msg => '<div class="alert alert-danger">' + msg + '</div>').join('');
    }).catch(() => {
        restore();
        document.getElementById('formError').innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
