<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
requirePlatformLogin();

$pdo = db();
$notice = flash('success');
$automationCount = 0;

if (isset($_GET['reset_filters'])) {
    unset($_SESSION['platform_tenant_filters']);
    redirect(BASE_URL . '/platform_dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_tenant') {
        $slug          = sanitizeTenantSlug((string) ($_POST['slug'] ?? ''));
        $schoolName    = trim((string) ($_POST['school_name'] ?? ''));
        $planName      = trim((string) ($_POST['plan_name'] ?? 'Starter'));
        $maxUsers      = max(1, (int) ($_POST['max_users'] ?? 100));
        $maxCards      = max(1, (int) ($_POST['max_cards'] ?? 300));
        $billingStatus = (string) ($_POST['billing_status'] ?? 'trial');
        $trialEndsAt   = trim((string) ($_POST['trial_ends_at'] ?? ''));
        $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');

        if ($slug !== '' && $schoolName !== '' && $adminUsername !== '' && $adminPassword !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO tenants (
                    slug, school_name, status,
                    plan_name, max_users, max_cards, billing_status, trial_ends_at
                ) VALUES (?, ?, 'active', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $slug, $schoolName, $planName, $maxUsers, $maxCards,
                $billingStatus, $trialEndsAt !== '' ? $trialEndsAt : null,
            ]);
            $tenantId = (int) $pdo->lastInsertId();

            $uStmt = $pdo->prepare("
                INSERT INTO users (
                    tenant_id, role, first_name, last_name, gender,
                    username, password_hash, status
                ) VALUES (?, 'admin', 'Tenant', 'Admin', 'other', ?, ?, 'active')
            ");
            $uStmt->execute([$tenantId, $adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT)]);

            flash(
                'success',
                'Tenant created. School admin login: username “' . $adminUsername . '” with the password you set. URL: '
                . BASE_URL . '/login.php?tenant=' . $slug
            );
        } else {
            flash('error', 'All fields (slug, school name, admin username & password) are required.');
        }
    }

    if ($action === 'update_tenant') {
        $tenantId      = (int) ($_POST['tenant_id'] ?? 0);
        $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $planName      = trim((string) ($_POST['plan_name'] ?? 'Starter'));
        $maxUsers      = max(1, (int) ($_POST['max_users'] ?? 100));
        $maxCards      = max(1, (int) ($_POST['max_cards'] ?? 300));
        $billingStatus = (string) ($_POST['billing_status'] ?? 'trial');
        $trialEndsAt   = trim((string) ($_POST['trial_ends_at'] ?? ''));
        $schoolName    = trim((string) ($_POST['school_name'] ?? ''));
        $newPw         = (string) ($_POST['admin_new_password'] ?? '');
        $newPw2        = (string) ($_POST['admin_new_password_confirm'] ?? '');
        $wantPwChange  = ($newPw !== '' || $newPw2 !== '');

        if ($wantPwChange && ($newPw !== $newPw2 || strlen($newPw) < 8)) {
            flash('error', 'Admin password: fields must match and be at least 8 characters.');
            redirect(BASE_URL . '/platform_dashboard.php');
        }

        if ($tenantId > 0 && $schoolName !== '') {
            $stmt = $pdo->prepare("
                UPDATE tenants
                SET school_name = ?, status = ?, plan_name = ?, max_users = ?, max_cards = ?, billing_status = ?, trial_ends_at = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $schoolName, $status, $planName, $maxUsers, $maxCards,
                $billingStatus, $trialEndsAt !== '' ? $trialEndsAt : null, $tenantId,
            ]);
            $msg = 'Tenant updated successfully.';
            if ($wantPwChange) {
                $admStmt = $pdo->prepare("
                    SELECT id FROM users
                    WHERE tenant_id = ? AND role = 'admin'
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $admStmt->execute([$tenantId]);
                $admId = (int) $admStmt->fetchColumn();
                if ($admId > 0) {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($newPw, PASSWORD_DEFAULT), $admId]);
                    $msg .= ' School admin password was updated.';
                } else {
                    $msg .= ' No admin user found — password not changed.';
                }
            }
            flash('success', $msg);
        }
    }

    if ($action === 'generate_tenant_admin_password') {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $admStmt = $pdo->prepare("
                SELECT u.id, u.username
                FROM users u
                WHERE u.tenant_id = ? AND u.role = 'admin'
                ORDER BY u.id ASC
                LIMIT 1
            ");
            $admStmt->execute([$tenantId]);
            $admin = $admStmt->fetch();
            $tStmt = $pdo->prepare('SELECT slug, school_name FROM tenants WHERE id = ? LIMIT 1');
            $tStmt->execute([$tenantId]);
            $trow = $tStmt->fetch();
            if ($admin && $trow) {
                $plain = substr(bin2hex(random_bytes(8)), 0, 16);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($plain, PASSWORD_DEFAULT), (int) $admin['id']]);
                flash(
                    'success',
                    'One-time admin password for “' . $trow['school_name'] . '” — user '
                    . $admin['username'] . ': ' . $plain
                    . ' — Copy now; it will not be shown again.'
                );
            } else {
                flash('error', 'No admin user found for that tenant.');
            }
        }
        redirect(BASE_URL . '/platform_dashboard.php');
    }

    if ($action === 'run_automation') {
        $affected = runSubscriptionAutomation($pdo);
        flash('success', 'Automation ran. Suspended ' . $affected . ' tenant(s).');
    }

    redirect(BASE_URL . '/platform_dashboard.php');
}

$automationCount = runSubscriptionAutomation($pdo);

$savedFilters  = $_SESSION['platform_tenant_filters'] ?? [];
$search        = array_key_exists('q', $_GET)       ? trim((string) $_GET['q'])       : (string) ($savedFilters['q'] ?? '');
$statusFilter  = array_key_exists('status', $_GET)  ? trim((string) $_GET['status'])  : (string) ($savedFilters['status'] ?? '');
$billingFilter = array_key_exists('billing', $_GET) ? trim((string) $_GET['billing']) : (string) ($savedFilters['billing'] ?? '');
$perPage       = array_key_exists('per_page', $_GET) ? (int) $_GET['per_page'] : (int) ($savedFilters['per_page'] ?? 10);
$sortBy        = array_key_exists('sort_by', $_GET)  ? trim((string) $_GET['sort_by'])  : (string) ($savedFilters['sort_by'] ?? 'created');
$sortDir       = strtolower(array_key_exists('sort_dir', $_GET) ? trim((string) $_GET['sort_dir']) : (string) ($savedFilters['sort_dir'] ?? 'desc'));

if (!in_array($perPage, [10, 25, 50, 100], true)) { $perPage = 10; }
if (!in_array($sortDir, ['asc', 'desc'], true))    { $sortDir = 'desc'; }
$page = max(1, (int) ($_GET['page'] ?? 1));

$_SESSION['platform_tenant_filters'] = compact('search', 'statusFilter', 'billingFilter', 'perPage', 'sortBy', 'sortDir');

$sortMap = [
    'school'  => 't.school_name',
    'slug'    => 't.slug',
    'billing' => 't.billing_status',
    'status'  => 't.status',
    'users'   => 'user_count',
    'cards'   => 'card_count',
    'scans'   => 'scans_today',
    'created' => 't.created_at',
];
if (!isset($sortMap[$sortBy])) { $sortBy = 'created'; }
$orderSql = $sortMap[$sortBy] . ' ' . strtoupper($sortDir);

$where = []; $params = [];
if ($search !== '') {
    $where[] = '(t.school_name LIKE ? OR t.slug LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 't.status = ?'; $params[] = $statusFilter;
}
if (in_array($billingFilter, ['trial', 'paid', 'past_due', 'suspended'], true)) {
    $where[] = 't.billing_status = ?'; $params[] = $billingFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tenants t $whereSql");
$countStmt->execute($params);
$totalTenants = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalTenants / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$rowsStmt = $pdo->prepare("
    SELECT
        t.*,
        (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
        (SELECT COUNT(*) FROM rfid_cards c WHERE c.tenant_id = t.id) AS card_count,
        (SELECT COUNT(*) FROM attendance_logs l WHERE l.tenant_id = t.id AND DATE(l.scanned_at) = CURDATE()) AS scans_today,
        (SELECT u.username FROM users u WHERE u.tenant_id = t.id AND u.role = 'admin' ORDER BY u.id ASC LIMIT 1) AS admin_username
    FROM tenants t
    $whereSql
    ORDER BY $orderSql
    LIMIT $perPage OFFSET $offset
");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$stats = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_total,
      SUM(CASE WHEN billing_status = 'paid' THEN 1 ELSE 0 END) AS paid_total,
      SUM(CASE WHEN billing_status = 'suspended' THEN 1 ELSE 0 END) AS suspended_total
    FROM tenants
")->fetch();
$activeTenants    = (int) ($stats['active_total'] ?? 0);
$paidTenants      = (int) ($stats['paid_total'] ?? 0);
$suspendedTenants = (int) ($stats['suspended_total'] ?? 0);

$baseParams = ['q' => $search, 'status' => $statusFilter, 'billing' => $billingFilter, 'per_page' => $perPage, 'sort_by' => $sortBy, 'sort_dir' => $sortDir];

function sortLink(string $col, string $label, string $current, string $dir, array $base): string {
    $newDir = ($current === $col && $dir === 'asc') ? 'desc' : 'asc';
    $icon   = $current === $col ? ($dir === 'asc' ? '▲' : '▼') : '↕';
    $url    = h(BASE_URL . '/platform_dashboard.php?' . http_build_query($base + ['sort_by' => $col, 'sort_dir' => $newDir]));
    $active = $current === $col ? 'style="color:#6366f1;font-weight:700;"' : '';
    return "<a href=\"$url\" class=\"sort-link\" $active>$label <span class=\"text-muted small\">$icon</span></a>";
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= h(BASE_URL . '/assets/ui.css') ?>" rel="stylesheet">
    <style>
    /* ── Platform Dashboard ────────────────────────────────────── */
    body { background: var(--app-bg); font-family: 'Inter', system-ui, sans-serif; }

    /* Topbar */
    .pd-topbar {
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
        color: #fff;
        padding: 0 28px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 12px rgba(30,27,75,0.4);
    }
    .pd-topbar .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 700;
        font-size: 1.05rem;
        letter-spacing: -0.02em;
    }
    .pd-topbar .brand .brand-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: rgba(255,255,255,0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }
    .pd-topbar .brand span { color: #c7d2fe; font-size: 0.78rem; font-weight: 400; }

    /* Content wrapper */
    .pd-content { padding: 28px; max-width: 1400px; margin: 0 auto; }

    /* Stat cards */
    .stat-card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(15,23,42,0.07);
        overflow: hidden;
        transition: transform 0.18s, box-shadow 0.18s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(15,23,42,0.12); }
    .stat-card .card-body { padding: 20px 22px; }
    .stat-card .si {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        flex-shrink: 0;
    }
    .stat-card .stat-val { font-size: 2rem; font-weight: 800; line-height: 1; color: #0f172a; }
    .stat-card .stat-lbl { font-size: 0.78rem; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

    /* Section cards */
    .pd-card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(15,23,42,0.07);
        overflow: hidden;
    }
    .pd-card .pd-card-head {
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        border-bottom: 1px solid #e5e7eb;
        padding: 14px 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .pd-card .pd-card-head .ch-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }
    .pd-card .pd-card-head h6 { margin: 0; font-weight: 700; font-size: 0.92rem; color: #0f172a; }
    .pd-card .pd-card-head .ch-sub { margin: 0; font-size: 0.75rem; color: #64748b; }

    /* Form section label */
    .form-section-lbl {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #94a3b8;
        padding-bottom: 6px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 12px;
        margin-top: 4px;
    }

    /* Badges */
    .billing-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: capitalize;
        letter-spacing: 0.03em;
    }
    .billing-trial     { background: #fef9c3; color: #854d0e; }
    .billing-paid      { background: #dcfce7; color: #166534; }
    .billing-past_due  { background: #fee2e2; color: #991b1b; }
    .billing-suspended { background: #f1f5f9; color: #475569; }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 600;
    }
    .status-pill .dot { width: 7px; height: 7px; border-radius: 50%; }
    .status-active   { background: #dcfce7; color: #166534; }
    .status-active .dot   { background: #16a34a; }
    .status-inactive { background: #f1f5f9; color: #64748b; }
    .status-inactive .dot { background: #94a3b8; }

    /* Table */
    .pd-table thead th {
        background: #f8fafc;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        border-bottom: 1.5px solid #e5e7eb;
        padding: 10px 14px;
        white-space: nowrap;
    }
    .pd-table tbody td {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .pd-table tbody tr:last-child td { border-bottom: 0; }
    .pd-table tbody tr:hover > td { background: #fafaff; }

    .sort-link { color: inherit; text-decoration: none; }
    .sort-link:hover { color: #6366f1; }

    /* Slug chip */
    .slug-chip {
        display: inline-block;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 2px 8px;
        font-family: monospace;
        font-size: 0.78rem;
        color: #475569;
    }

    /* Edit row */
    .edit-row td { background: #f8faff !important; border-top: 2px solid #e0e7ff !important; }
    .edit-row .edit-inner { padding: 16px; }

    /* Pagination */
    .page-link { border-radius: 8px !important; font-size: 0.82rem; border-color: #e5e7eb; color: #475569; }
    .page-item.active .page-link { background: #6366f1; border-color: #6366f1; }
    .page-item:not(.disabled) .page-link:hover { background: #e0e7ff; color: #4338ca; border-color: #c7d2fe; }

    /* Auto pill */
    .auto-card { border: 0; border-radius: 14px; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); box-shadow: 0 4px 16px rgba(245,158,11,0.15); }
    </style>
</head>
<body>

<!-- ── Topbar ───────────────────────────────────────────────────── -->
<div class="pd-topbar">
    <div class="brand">
        <div class="brand-icon"><i class="bi bi-server"></i></div>
        <div>
            <div>SaaS Platform</div>
            <span>Super Admin Console</span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= h(BASE_URL . '/platform_billing_report.php') ?>" class="btn btn-sm" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.25);">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Billing Report
        </a>
        <a href="<?= h(BASE_URL . '/platform_logout.php') ?>" class="btn btn-sm btn-danger">
            <i class="bi bi-box-arrow-right me-1"></i>Logout
        </a>
    </div>
</div>

<div class="pd-content">

    <?php if ($notice): ?>
        <div class="alert alert-success alert-dismissible d-flex align-items-center gap-2 mb-3" role="alert">
            <i class="bi bi-check-circle-fill"></i>
            <div><?= h($notice) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php $err = flash('error'); if ($err): ?>
        <div class="alert alert-danger alert-dismissible d-flex align-items-center gap-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= h($err) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($automationCount > 0): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-lightning-charge-fill"></i>
            Auto-suspension applied to <strong><?= h((string) $automationCount) ?></strong> tenant(s) this session.
        </div>
    <?php endif; ?>

    <!-- ── Stat Cards ─────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="si" style="background:rgba(99,102,241,0.12);color:#6366f1;"><i class="bi bi-buildings-fill"></i></div>
                    <div>
                        <div class="stat-val"><?= h((string) ($stats['total'] ?? 0)) ?></div>
                        <div class="stat-lbl">Total Tenants</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="si" style="background:rgba(16,185,129,0.12);color:#059669;"><i class="bi bi-check-circle-fill"></i></div>
                    <div>
                        <div class="stat-val"><?= h((string) $activeTenants) ?></div>
                        <div class="stat-lbl">Active Tenants</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="si" style="background:rgba(59,130,246,0.12);color:#2563eb;"><i class="bi bi-credit-card-fill"></i></div>
                    <div>
                        <div class="stat-val"><?= h((string) $paidTenants) ?></div>
                        <div class="stat-lbl">Paid Tenants</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="si" style="background:rgba(239,68,68,0.12);color:#dc2626;"><i class="bi bi-slash-circle-fill"></i></div>
                    <div>
                        <div class="stat-val"><?= h((string) $suspendedTenants) ?></div>
                        <div class="stat-lbl">Suspended</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Subscription Automation ──────────────────────────── -->
    <div class="card auto-card mb-4">
        <div class="card-body d-flex align-items-center justify-content-between gap-3 py-3 px-4">
            <div class="d-flex align-items-center gap-3">
                <div class="si" style="background:rgba(245,158,11,0.18);color:#d97706;width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                    <i class="bi bi-lightning-charge-fill"></i>
                </div>
                <div>
                    <div class="fw-bold" style="color:#92400e;">Subscription Automation</div>
                    <div class="small" style="color:#b45309;">Suspends active trial tenants when <code>trial_ends_at</code> is past today.</div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="run_automation">
                <button class="btn btn-warning btn-sm fw-semibold px-3">
                    <i class="bi bi-play-circle me-1"></i>Run Now
                </button>
            </form>
        </div>
    </div>

    <!-- ── Create Tenant ─────────────────────────────────────── -->
    <div class="card pd-card mb-4">
        <div class="pd-card-head">
            <div class="ch-icon" style="background:rgba(99,102,241,0.12);color:#6366f1;"><i class="bi bi-plus-circle-fill"></i></div>
            <div>
                <h6>Create New Tenant</h6>
                <p class="ch-sub">Provision a new school with an admin account</p>
            </div>
        </div>
        <div class="card-body py-4 px-4">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="create_tenant">

                <div class="col-12">
                    <div class="form-section-lbl"><i class="bi bi-building me-1"></i>Tenant Identity</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Tenant Slug <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                        <input name="slug" class="form-control" placeholder="e.g. my-school" required>
                    </div>
                    <div class="form-text">Unique URL identifier — lowercase, no spaces.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">School Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                        <input name="school_name" class="form-control" placeholder="e.g. A.O. Floirendo NHS" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Plan Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-award"></i></span>
                        <input name="plan_name" class="form-control" value="Starter" placeholder="Starter">
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-section-lbl"><i class="bi bi-sliders me-1"></i>Limits &amp; Billing</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">Max Users</label>
                    <input type="number" min="1" name="max_users" class="form-control" value="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">Max RFID Cards</label>
                    <input type="number" min="1" name="max_cards" class="form-control" value="300">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Billing Status</label>
                    <select name="billing_status" class="form-select">
                        <option value="trial">Trial</option>
                        <option value="paid">Paid</option>
                        <option value="past_due">Past Due</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Trial Ends At</label>
                    <input type="date" name="trial_ends_at" class="form-control">
                </div>

                <div class="col-12">
                    <div class="form-section-lbl"><i class="bi bi-person-badge me-1"></i>Admin Account Credentials</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Admin Username <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input name="admin_username" class="form-control" placeholder="admin-username" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Admin Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input name="admin_password" type="password" class="form-control" placeholder="Password" required>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button class="btn btn-primary w-100">
                        <i class="bi bi-plus-circle me-2"></i>Create Tenant
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Tenants Table ─────────────────────────────────────── -->
    <div class="card pd-card">
        <div class="pd-card-head flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 me-auto">
                <div class="ch-icon" style="background:rgba(99,102,241,0.12);color:#6366f1;"><i class="bi bi-table"></i></div>
                <div>
                    <h6 class="mb-0">Tenants
                        <span class="badge rounded-pill ms-1" style="background:#e0e7ff;color:#4338ca;font-size:0.72rem;"><?= h((string) $totalTenants) ?></span>
                        <?php if ($search !== '' || $statusFilter !== '' || $billingFilter !== ''): ?>
                        <span class="badge rounded-pill ms-1 bg-warning text-dark" style="font-size:0.7rem;">filtered</span>
                        <?php endif; ?>
                    </h6>
                </div>
            </div>
            <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
                <input name="q" value="<?= h($search) ?>" class="form-control form-control-sm" placeholder="Search school / slug…" style="width:180px;">
                <select name="status" class="form-select form-select-sm" style="width:130px;">
                    <option value="">All status</option>
                    <option value="active"   <?= selected($statusFilter, 'active') ?>>Active</option>
                    <option value="inactive" <?= selected($statusFilter, 'inactive') ?>>Inactive</option>
                </select>
                <select name="billing" class="form-select form-select-sm" style="width:130px;">
                    <option value="">All billing</option>
                    <?php foreach (['trial' => 'Trial', 'paid' => 'Paid', 'past_due' => 'Past Due', 'suspended' => 'Suspended'] as $bv => $bl): ?>
                        <option value="<?= h($bv) ?>" <?= selected($billingFilter, $bv) ?>><?= h($bl) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="per_page" class="form-select form-select-sm" style="width:100px;">
                    <?php foreach ([10, 25, 50, 100] as $sz): ?>
                        <option value="<?= $sz ?>" <?= selected($perPage, $sz) ?>><?= $sz ?>/page</option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="sort_by"  value="<?= h($sortBy) ?>">
                <input type="hidden" name="sort_dir" value="<?= h($sortDir) ?>">
                <button class="btn btn-sm btn-primary px-3"><i class="bi bi-search me-1"></i>Apply</button>
                <a href="<?= h(BASE_URL . '/platform_dashboard.php?reset_filters=1') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table pd-table align-middle mb-0">
                    <thead>
                    <tr>
                        <th><?= sortLink('school', 'Tenant', $sortBy, $sortDir, $baseParams) ?></th>
                        <th><?= sortLink('slug', 'Slug', $sortBy, $sortDir, $baseParams) ?></th>
                        <th>Plan</th>
                        <th><?= sortLink('billing', 'Billing', $sortBy, $sortDir, $baseParams) ?></th>
                        <th><?= sortLink('status', 'Status', $sortBy, $sortDir, $baseParams) ?></th>
                        <th><?= sortLink('users', 'Users', $sortBy, $sortDir, $baseParams) ?></th>
                        <th><?= sortLink('cards', 'Cards', $sortBy, $sortDir, $baseParams) ?></th>
                        <th><?= sortLink('scans', 'Scans Today', $sortBy, $sortDir, $baseParams) ?></th>
                        <th>Login</th>
                        <th class="text-center">Edit</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="bi bi-buildings fs-2 d-block mb-2 opacity-25"></i>
                                No tenants found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row):
                        $billing = $row['billing_status'] ?? 'trial';
                        $rstatus = $row['status'] ?? 'active';
                        $billingLabel = ['trial' => 'Trial', 'paid' => 'Paid', 'past_due' => 'Past Due', 'suspended' => 'Suspended'][$billing] ?? ucfirst($billing);
                    ?>
                    <tr class="tenant-row">
                        <td>
                            <div class="fw-semibold text-dark"><?= h($row['school_name']) ?></div>
                            <div class="text-muted small">ID #<?= h((string) $row['id']) ?></div>
                        </td>
                        <td><span class="slug-chip"><?= h($row['slug']) ?></span></td>
                        <td class="small"><?= h($row['plan_name'] ?? 'Starter') ?></td>
                        <td><span class="billing-badge billing-<?= h($billing) ?>"><?= h($billingLabel) ?></span></td>
                        <td>
                            <span class="status-pill status-<?= h($rstatus) ?>">
                                <span class="dot"></span><?= ucfirst(h($rstatus)) ?>
                            </span>
                        </td>
                        <td>
                            <span class="fw-semibold"><?= h((string) $row['user_count']) ?></span>
                            <span class="text-muted small"> / <?= h((string) ($row['max_users'] ?? '—')) ?></span>
                        </td>
                        <td>
                            <span class="fw-semibold"><?= h((string) $row['card_count']) ?></span>
                            <span class="text-muted small"> / <?= h((string) ($row['max_cards'] ?? '—')) ?></span>
                        </td>
                        <td>
                            <?php $s = (int) $row['scans_today']; ?>
                            <span class="fw-semibold <?= $s > 0 ? 'text-success' : 'text-muted' ?>"><?= h((string) $s) ?></span>
                        </td>
                        <td>
                            <a target="_blank" href="<?= h(BASE_URL . '/login.php?tenant=' . urlencode($row['slug'])) ?>"
                               class="btn btn-sm btn-outline-primary py-0 px-2">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 toggle-edit-btn"
                                    data-target="edit-row-<?= h((string) $row['id']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                    </tr>
                    <!-- Edit row (hidden by default) -->
                    <tr class="edit-row d-none" id="edit-row-<?= h((string) $row['id']) ?>">
                        <td colspan="10">
                            <div class="edit-inner">
                                <form id="gen-pw-<?= h((string) $row['id']) ?>" method="post" class="d-none" aria-hidden="true">
                                    <input type="hidden" name="action" value="generate_tenant_admin_password">
                                    <input type="hidden" name="tenant_id" value="<?= h((string) $row['id']) ?>">
                                </form>
                                <form method="post" class="row g-3">
                                    <input type="hidden" name="action"    value="update_tenant">
                                    <input type="hidden" name="tenant_id" value="<?= h((string) $row['id']) ?>">

                                    <div class="col-12">
                                        <div class="form-section-lbl"><i class="bi bi-building me-1"></i>Identity</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold small">School Name</label>
                                        <input class="form-control form-control-sm" name="school_name" value="<?= h($row['school_name']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small">Plan Name</label>
                                        <input class="form-control form-control-sm" name="plan_name" value="<?= h($row['plan_name'] ?? 'Starter') ?>">
                                    </div>

                                    <div class="col-12">
                                        <div class="form-section-lbl"><i class="bi bi-person-badge me-1"></i>School admin login</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold small">Admin username</label>
                                        <?php if (!empty($row['admin_username'])): ?>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                                <input type="text" class="form-control font-monospace" readonly value="<?= h((string) $row['admin_username']) ?>">
                                                <button type="button" class="btn btn-outline-secondary" title="Copy username"
                                                        onclick="navigator.clipboard.writeText(this.closest('.input-group').querySelector('input').value)">
                                                    <i class="bi bi-clipboard"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-warning py-2 px-3 small mb-0">No user with role <code>admin</code> found for this tenant.</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold small">Password</label>
                                        <div class="form-control form-control-sm bg-light text-muted d-flex align-items-center" style="min-height:31px;">
                                            Stored as a secure hash — the current password cannot be shown.
                                        </div>
                                        <div class="small text-muted mt-1">
                                            Set a new password below, or use <strong>Generate new password</strong> to create one and show it once at the top of the page.
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold small">New admin password</label>
                                        <input type="password" class="form-control form-control-sm" name="admin_new_password" autocomplete="new-password" placeholder="Leave blank to keep current" minlength="8">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold small">Confirm new password</label>
                                        <input type="password" class="form-control form-control-sm" name="admin_new_password_confirm" autocomplete="new-password" placeholder="Repeat if changing" minlength="8">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <?php if (!empty($row['admin_username'])): ?>
                                            <button type="submit" form="gen-pw-<?= h((string) $row['id']) ?>" class="btn btn-sm btn-outline-primary w-100"
                                                    onclick="return confirm('This will replace the school admin password and show the new one once in the green banner at the top. Continue?');">
                                                <i class="bi bi-key me-1"></i>Generate new password
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-section-lbl"><i class="bi bi-sliders me-1"></i>Limits &amp; Billing</div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold small">Max Users</label>
                                        <input type="number" min="1" class="form-control form-control-sm" name="max_users" value="<?= h((string) ($row['max_users'] ?? 100)) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold small">Max Cards</label>
                                        <input type="number" min="1" class="form-control form-control-sm" name="max_cards" value="<?= h((string) ($row['max_cards'] ?? 300)) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small">Billing Status</label>
                                        <select name="billing_status" class="form-select form-select-sm">
                                            <?php foreach (['trial' => 'Trial', 'paid' => 'Paid', 'past_due' => 'Past Due', 'suspended' => 'Suspended'] as $bv => $bl): ?>
                                                <option value="<?= h($bv) ?>" <?= selected($row['billing_status'] ?? 'trial', $bv) ?>><?= h($bl) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold small">Status</label>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="active"   <?= selected($row['status'], 'active') ?>>Active</option>
                                            <option value="inactive" <?= selected($row['status'], 'inactive') ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small">Trial Ends At</label>
                                        <input type="date" class="form-control form-control-sm" name="trial_ends_at" value="<?= h((string) ($row['trial_ends_at'] ?? '')) ?>">
                                    </div>

                                    <div class="col-12 d-flex justify-content-end gap-2 pt-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary toggle-edit-btn" data-target="edit-row-<?= h((string) $row['id']) ?>">
                                            Cancel
                                        </button>
                                        <button type="submit" class="btn btn-sm btn-primary px-4">
                                            <i class="bi bi-check2 me-1"></i>Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1):
            $prevPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
        ?>
        <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center px-4 py-2">
            <span class="text-muted small">
                Page <?= h((string) $page) ?> of <?= h((string) $totalPages) ?>
                &nbsp;·&nbsp; <?= h((string) $totalTenants) ?> total
            </span>
            <ul class="pagination pagination-sm mb-0 gap-1">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= h(BASE_URL . '/platform_dashboard.php?' . http_build_query($baseParams + ['page' => $prevPage])) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                for ($p = $start; $p <= $end; $p++):
                ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= h(BASE_URL . '/platform_dashboard.php?' . http_build_query($baseParams + ['page' => $p])) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= h(BASE_URL . '/platform_dashboard.php?' . http_build_query($baseParams + ['page' => $nextPage])) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /pd-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(BASE_URL . '/assets/ui.js') ?>"></script>
<script>
/* Toggle edit row */
document.querySelectorAll('.toggle-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const targetId = btn.dataset.target;
        const row = document.getElementById(targetId);
        if (!row) return;
        const isHidden = row.classList.contains('d-none');
        /* Close all open edit rows first */
        document.querySelectorAll('.edit-row').forEach(r => r.classList.add('d-none'));
        if (isHidden) {
            row.classList.remove('d-none');
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });
});
</script>
</body>
</html>
