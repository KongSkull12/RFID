<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (isLoggedIn()) {
    $u = currentUser();
    $dest = (($u['role'] ?? '') === 'parent') ? 'parent_students.php' : 'index.php';
    redirect(appUrl($dest));
}

$error = null;
$tenant = currentTenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bootstrapTenant();
    $tenant = currentTenant();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!tenantCanLogin()) {
        $error = 'This tenant is inactive or suspended. Contact platform administrator.';
    } else {

        $stmt = db()->prepare("
            SELECT id, tenant_id, username, role, first_name, last_name, password_hash, status
            FROM users
            WHERE username = ? AND tenant_id = ? AND role <> 'student'
            LIMIT 1
        ");
        $stmt->execute([$username, tenantId()]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active' && password_verify($password, (string) $user['password_hash'])) {
            loginUser($user);
            $dest = ((string) ($user['role'] ?? '')) === 'parent' ? 'parent_students.php' : 'index.php';
            redirect(appUrl($dest));
        }

        if ($user && ($user['status'] ?? '') !== 'active') {
            $error = 'This account is inactive. Ask your administrator to reactivate it.';
        } elseif ($user) {
            $error = 'Wrong password for this username and school.';
        } else {
            $error = 'No account with that username for school code “' . tenantSlug() . '”. Check the school code and username (copy them from the platform dashboard if needed).';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login – <?= h(tenantName()) ?></title>
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
        <div class="auth-brand-logo">
            <?php
            $initials = '';
            foreach (explode(' ', tenantName()) as $w) {
                $initials .= strtoupper(mb_substr($w, 0, 1));
                if (strlen($initials) >= 2) {
                    break;
                }
            }
            echo h($initials ?: 'S');
            ?>
        </div>
        <h1 class="auth-brand-title"><?= h(tenantName()) ?></h1>
        <p class="auth-brand-sub">Sign in to the attendance dashboard</p>
    </div>

    <div class="auth-card">
        <div class="card-body">
            <div class="auth-card-header">
                <div>
                    <div class="auth-card-header-title">Welcome back</div>
                    <div class="auth-card-header-meta">
                        <i class="bi bi-building me-1"></i><?= h($tenant['slug'] ?? '') ?>
                        <span class="ms-2 tb-plan-badge"><?= h((string)($tenant['plan_name'] ?? 'Starter')) ?></span>
                    </div>
                </div>
                <a class="btn btn-sm btn-outline-primary flex-shrink-0 auth-platform-pill" href="<?= h(BASE_URL . '/platform_login.php') ?>" title="Platform super-admin sign-in">
                    <i class="bi bi-shield-lock-fill me-1"></i>Platform
                </a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mb-3" data-auto-dismiss="true">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <label class="form-label" for="login-tenant">School code (tenant)</label>
                    <div class="input-group">
                        <span class="input-group-text auth-input-addon"><i class="bi bi-building"></i></span>
                        <input id="login-tenant" name="tenant" class="form-control" value="<?= h($tenant['slug'] ?? '') ?>" required placeholder="e.g. default-school" autocomplete="organization">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="login-user">Username</label>
                    <div class="input-group">
                        <span class="input-group-text auth-input-addon"><i class="bi bi-person-fill"></i></span>
                        <input id="login-user" name="username" class="form-control" required placeholder="Username" autocomplete="username">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="login-pass">Password</label>
                    <div class="input-group">
                        <span class="input-group-text auth-input-addon"><i class="bi bi-lock-fill"></i></span>
                        <input id="login-pass" type="password" name="password" class="form-control" required autocomplete="current-password" placeholder="••••••••">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 auth-btn-submit">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
                </button>
            </form>

            <div class="text-center mt-4 auth-platform-foot">
                <a class="btn btn-outline-primary btn-sm px-3" href="<?= h(BASE_URL . '/platform_login.php') ?>">
                    <i class="bi bi-shield-lock me-1"></i>Platform admin login
                </a>
            </div>
            <p class="text-center auth-footer-hint mt-2 mb-0">
                Tip: bookmark <span class="kbd">?tenant=your-school</span> for faster access
            </p>
        </div>
    </div>
</div>
<script src="<?= h(BASE_URL . '/assets/ui.js') ?>"></script>
</body>
</html>
