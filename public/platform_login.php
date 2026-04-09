<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (platformLoggedIn()) {
    redirect(BASE_URL . '/platform_dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare("
        SELECT id, username, display_name, password_hash, status
        FROM platform_admins
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && $admin['status'] === 'active' && password_verify($password, (string) $admin['password_hash'])) {
        platformLogin($admin);
        redirect(BASE_URL . '/platform_dashboard.php');
    }
    $error = 'Invalid platform credentials.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Admin Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= h(BASE_URL . '/assets/ui.css') ?>" rel="stylesheet">
</head>
<body class="auth-shell">
<div class="auth-wrap">
    <div class="auth-brand">
        <div class="auth-brand-logo auth-brand-logo--platform">
            <i class="bi bi-shield-lock-fill"></i>
        </div>
        <h1 class="auth-brand-title">Platform administration</h1>
        <p class="auth-brand-sub">Manage schools, plans, and subscriptions</p>
    </div>

    <div class="auth-card">
        <div class="card-body">
            <div class="mb-3">
                <div class="auth-card-header-title">Administrator sign in</div>
                <div class="auth-card-header-meta mt-1">Use your platform super-admin account</div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mb-3" data-auto-dismiss="true">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <label class="form-label" for="plat-user">Username</label>
                    <div class="input-group">
                        <span class="input-group-text auth-input-addon"><i class="bi bi-person-fill"></i></span>
                        <input id="plat-user" name="username" class="form-control" required placeholder="Username" autocomplete="username">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="plat-pass">Password</label>
                    <div class="input-group">
                        <span class="input-group-text auth-input-addon"><i class="bi bi-lock-fill"></i></span>
                        <input id="plat-pass" type="password" name="password" class="form-control" required autocomplete="current-password" placeholder="••••••••">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 auth-btn-submit">
                    <i class="bi bi-shield-check me-2"></i>Sign in
                </button>
            </form>

            <div class="text-center mt-4">
                <a class="auth-footer-link" href="<?= h(BASE_URL . '/login.php') ?>">
                    <i class="bi bi-arrow-left me-1"></i>Back to school login
                </a>
            </div>
        </div>
    </div>
</div>
<script src="<?= h(BASE_URL . '/assets/ui.js') ?>"></script>
</body>
</html>
