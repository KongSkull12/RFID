<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin', 'teacher', 'employee', 'parent']);

$pdo    = db();
$tenantId   = tenantId();
$auth       = currentUser();
$viewerRole = (string) ($auth['role'] ?? '');
$viewerId   = (int) ($auth['id'] ?? 0);
$hasTeacherAssignmentColumn = dbColumnExists('users', 'teacher_user_id');

if (isset($_GET['reset_filters'])) {
    unset($_SESSION['logs_filters']);
    redirect(appUrl('logs.php'));
}

$savedFilters = $_SESSION['logs_filters'] ?? [];
$preset    = array_key_exists('preset', $_GET)          ? trim((string) $_GET['preset'])          : (string) ($savedFilters['preset'] ?? '');
$dateFrom  = array_key_exists('date_from', $_GET)       ? trim((string) $_GET['date_from'])       : (string) ($savedFilters['date_from'] ?? '');
$dateTo    = array_key_exists('date_to', $_GET)         ? trim((string) $_GET['date_to'])         : (string) ($savedFilters['date_to'] ?? '');
$gradeId   = array_key_exists('grade_level_id', $_GET)  ? trim((string) $_GET['grade_level_id'])  : (string) ($savedFilters['grade_level_id'] ?? '');
$sectionId = array_key_exists('section_id', $_GET)      ? trim((string) $_GET['section_id'])      : (string) ($savedFilters['section_id'] ?? '');
$userType  = array_key_exists('user_type', $_GET)       ? trim((string) $_GET['user_type'])       : (string) ($savedFilters['user_type'] ?? '');
$search    = array_key_exists('search', $_GET)          ? trim((string) $_GET['search'])          : (string) ($savedFilters['search'] ?? '');
$perPage   = array_key_exists('per_page', $_GET)        ? (int) $_GET['per_page']                 : (int) ($savedFilters['per_page'] ?? 25);
$sortBy    = array_key_exists('sort_by', $_GET)         ? trim((string) $_GET['sort_by'])         : (string) ($savedFilters['sort_by'] ?? 'datetime');
$sortDir   = strtolower(array_key_exists('sort_dir', $_GET) ? trim((string) $_GET['sort_dir'])    : (string) ($savedFilters['sort_dir'] ?? 'desc'));

if (!in_array($perPage, [10, 25, 50, 100], true)) { $perPage = 25; }
if (!in_array($sortDir, ['asc', 'desc'], true))    { $sortDir = 'desc'; }
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($preset === 'today') { $dateFrom = date('Y-m-d'); $dateTo = date('Y-m-d'); }
if ($preset === 'week')  { $dateFrom = date('Y-m-d', strtotime('-6 days')); $dateTo = date('Y-m-d'); }
if ($preset === 'month') { $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d'); }

if ($viewerRole === 'parent') {
    $gradeId = '';
    $sectionId = '';
}

$_SESSION['logs_filters'] = compact('preset','dateFrom','dateTo','gradeId','sectionId','userType','search','perPage','sortBy','sortDir');

$sortMap = [
    'datetime' => 'al.scanned_at',
    'name'     => 'u.last_name',
    'role'     => 'u.role',
    'grade'    => 'g.name',
    'rfid'     => 'al.rfid_uid',
    'type'     => 'al.scan_type',
    'device'   => 'al.device_name',
];
if (!isset($sortMap[$sortBy])) { $sortBy = 'datetime'; }
$orderSql = $sortMap[$sortBy] . ' ' . strtoupper($sortDir);

$where  = ['al.tenant_id = ?'];
$params = [$tenantId];

if ($dateFrom !== '') { $where[] = 'DATE(al.scanned_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'DATE(al.scanned_at) <= ?'; $params[] = $dateTo;   }
if ($gradeId   !== '') { $where[] = 'u.grade_level_id = ?';  $params[] = $gradeId;   }
if ($sectionId !== '') { $where[] = 'u.section_id = ?';      $params[] = $sectionId; }
if ($userType  !== '') { $where[] = 'u.role = ?';             $params[] = $userType;  }
if ($search    !== '') {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($viewerRole === 'parent') {
    $where[] = 'al.user_id IS NOT NULL';
    $where[] = "u.role = 'student'";
    $where[] = 'u.parent_user_id = ?';
    $params[] = $viewerId;
    $userType = 'student';
} elseif ($viewerRole === 'teacher') {
    $where[] = 'al.user_id IS NOT NULL';
    $where[] = "u.role = 'student'";
    if ($hasTeacherAssignmentColumn) {
        $where[] = 'u.teacher_user_id = ?';
        $params[] = $viewerId;
    } else {
        $where[] = '1 = 0';
        flash('error', 'Teacher assignment unavailable — run sql/saas_upgrade.sql first.');
    }
    $userType = 'student';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_logs al
    LEFT JOIN users u ON u.id = al.user_id AND u.tenant_id = al.tenant_id
    $whereSql
");
$countStmt->execute($params);
$logCount   = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($logCount / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$logsStmt = $pdo->prepare("
    SELECT
        al.*,
        u.first_name, u.last_name, u.role,
        g.name AS grade_name,
        s.name AS section_name
    FROM attendance_logs al
    LEFT JOIN users u ON u.id = al.user_id AND u.tenant_id = al.tenant_id
    LEFT JOIN grade_levels g ON g.id = u.grade_level_id AND g.tenant_id = al.tenant_id
    LEFT JOIN sections    s ON s.id = u.section_id AND s.tenant_id = al.tenant_id
    $whereSql
    ORDER BY $orderSql
    LIMIT $perPage OFFSET $offset
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

if ($viewerRole !== 'parent') {
    $stmt = $pdo->prepare("SELECT id, name FROM grade_levels WHERE tenant_id = ? ORDER BY id");
    $stmt->execute([$tenantId]);
    $grades = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name FROM sections WHERE tenant_id = ? ORDER BY name");
    $stmt->execute([$tenantId]);
    $sections = $stmt->fetchAll();
} else {
    $grades = [];
    $sections = [];
}

$baseParams = compact('preset','dateFrom','dateTo','gradeId','sectionId','userType','search','perPage','sortBy','sortDir');
$exportParams = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'grade_level_id' => $gradeId, 'section_id' => $sectionId, 'user_type' => $userType, 'search' => $search];

$pageTitle = in_array($viewerRole, ['teacher', 'parent'], true) ? 'My Assigned Student Logs' : 'User Logs Report';
renderHeader($pageTitle);

/* Helpers */
function scanTypeBadge(string $type): string {
    $t = strtolower(trim($type));
    if (str_contains($t, 'in'))  return '<span class="scan-badge scan-in"><i class="bi bi-box-arrow-in-right me-1"></i>' . h($type) . '</span>';
    if (str_contains($t, 'out')) return '<span class="scan-badge scan-out"><i class="bi bi-box-arrow-right me-1"></i>' . h($type) . '</span>';
    return '<span class="scan-badge scan-other">' . h($type) . '</span>';
}
function roleBadge(string $role): string {
    $map = [
        'student'  => ['#dbeafe','#1d4ed8','bi-mortarboard-fill'],
        'teacher'  => ['#d1fae5','#065f46','bi-person-workspace'],
        'employee' => ['#ede9fe','#5b21b6','bi-briefcase-fill'],
        'parent'   => ['#ffedd5','#9a3412','bi-person-hearts'],
    ];
    [$bg, $color, $icon] = $map[$role] ?? ['#f1f5f9','#475569','bi-person-fill'];
    $label = h(roleLabel($role));
    return "<span class=\"role-chip\" style=\"background:$bg;color:$color;\"><i class=\"bi $icon me-1\"></i>$label</span>";
}
?>

<style>
.preset-chip {
    display: inline-block; padding: 0.3rem 0.85rem; border-radius: var(--r-pill);
    font-size: 0.78rem; font-weight: 600; cursor: pointer;
    text-decoration: none; transition: all var(--ease);
    border: 1.5px solid var(--border); background: var(--surface); color: var(--tx-secondary);
}
.preset-chip:hover, .preset-chip.active {
    background: var(--ac-blue); border-color: var(--ac-blue); color: #fff;
}
.filter-lbl {
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: var(--tx-muted); margin-bottom: 0.25rem;
}
.active-filter-tag {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--ac-blue-light); color: var(--ac-blue);
    border-radius: var(--r-pill); padding: 0.18rem 0.65rem;
    font-size: 0.72rem; font-weight: 600;
}
.rfid-chip {
    font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.76rem;
    background: var(--surface-3); border: 1px solid var(--border);
    border-radius: 6px; padding: 2px 8px; color: var(--tx-secondary);
}
.dt-date { font-weight: 600; color: var(--tx-primary); font-size: 0.84rem; }
.dt-time { color: var(--tx-muted); font-size: 0.76rem; }
.empty-logs { padding: 60px 20px; text-align: center; }
.empty-logs i { font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: 12px; }
.empty-logs p { color: var(--tx-muted); margin: 0; font-size: 0.875rem; }
.logs-pagination { padding: 0.85rem 1.25rem; border-top: 1px solid var(--border); background: var(--surface-2); }
.page-link { border-radius: 7px !important; font-size: 0.8rem; border-color: var(--border); color: var(--tx-secondary); }
.page-item.active .page-link { background: var(--ac-blue); border-color: var(--ac-blue); color: #fff; }
.page-item:not(.disabled) .page-link:hover { background: var(--ac-blue-light); color: var(--ac-blue); border-color: #bfdbfe; }
</style>

<!-- Page header -->
<div class="page-header mb-4">
    <div class="page-header-left">
        <div class="page-header-icon"><i class="bi bi-clipboard2-pulse-fill"></i></div>
        <div>
            <h5><?= h($pageTitle) ?></h5>
            <p>Filter, search, and export attendance scan records</p>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-success btn-sm"
           href="<?= h(appUrl('export_logs.php', ['format' => 'csv'] + $exportParams)) ?>">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export XLSX
        </a>
        <a class="btn btn-danger btn-sm"
           href="<?= h(appUrl('export_logs.php', ['format' => 'print'] + $exportParams)) ?>" target="_blank">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </a>
    </div>
</div>

<!-- ── Filter Card ─────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-funnel-fill" style="color:var(--ac-blue);"></i>
            <span>Filters</span>
            <?php if ($logCount > 0): ?>
            <span class="badge-soft"><?= h((string) $logCount) ?> records</span>
            <?php endif; ?>
        </div>
        <!-- Active filter summary -->
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
            <span class="active-filter-tag">
                <i class="bi bi-calendar3"></i>
                <?= h($dateFrom ?: '…') ?> → <?= h($dateTo ?: '…') ?>
            </span>
            <?php endif; ?>
            <?php if ($search !== ''): ?>
            <span class="active-filter-tag"><i class="bi bi-search"></i> "<?= h($search) ?>"</span>
            <?php endif; ?>
            <?php if ($userType !== ''): ?>
            <span class="active-filter-tag"><i class="bi bi-person"></i> <?= h(roleLabel($userType)) ?></span>
            <?php endif; ?>
            <?php if ($viewerRole !== 'parent' && ($gradeId !== '' || $sectionId !== '')): ?>
            <span class="active-filter-tag"><i class="bi bi-mortarboard"></i> Grade/Section filtered</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-3">

            <!-- Quick presets -->
            <div class="col-12">
                <div class="filter-lbl">Quick Range</div>
                <div class="preset-chips">
                    <a href="<?= h(appUrl('logs.php', $baseParams + ['preset' => 'today',  'page' => 1])) ?>"
                       class="preset-chip <?= $preset === 'today' ? 'active' : '' ?>">Today</a>
                    <a href="<?= h(appUrl('logs.php', $baseParams + ['preset' => 'week',   'page' => 1])) ?>"
                       class="preset-chip <?= $preset === 'week' ? 'active' : '' ?>">Last 7 Days</a>
                    <a href="<?= h(appUrl('logs.php', $baseParams + ['preset' => 'month',  'page' => 1])) ?>"
                       class="preset-chip <?= $preset === 'month' ? 'active' : '' ?>">This Month</a>
                </div>
            </div>

            <!-- Date range -->
            <div class="col-md-2">
                <div class="filter-lbl">Date From</div>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <div class="filter-lbl">Date To</div>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?= h($dateTo) ?>">
            </div>

            <?php if ($viewerRole !== 'parent'): ?>
            <!-- Grade -->
            <div class="col-md-2">
                <div class="filter-lbl">Grade Level</div>
                <select name="grade_level_id" class="form-select form-select-sm">
                    <option value="">All grades</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= h((string) $g['id']) ?>" <?= selected($gradeId, $g['id']) ?>><?= h($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Section -->
            <div class="col-md-2">
                <div class="filter-lbl">Section</div>
                <select name="section_id" class="form-select form-select-sm">
                    <option value="">All sections</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= h((string) $sec['id']) ?>" <?= selected($sectionId, $sec['id']) ?>><?= h($sec['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- User type -->
            <?php if (!in_array($viewerRole, ['teacher', 'parent'], true)): ?>
            <div class="col-md-2">
                <div class="filter-lbl">User Type</div>
                <select name="user_type" class="form-select form-select-sm">
                    <option value="">All types</option>
                    <?php foreach (['student', 'teacher', 'employee', 'parent'] as $r): ?>
                        <option value="<?= h($r) ?>" <?= selected($userType, $r) ?>><?= h(roleLabel($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="user_type" value="student">
            <?php endif; ?>

            <!-- Search -->
            <div class="col-md-3">
                <div class="filter-lbl">Name Search</div>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input class="form-control" name="search" placeholder="First or last name…" value="<?= h($search) ?>">
                </div>
            </div>

            <!-- Per page -->
            <div class="col-md-1">
                <div class="filter-lbl">Per Page</div>
                <select name="per_page" class="form-select form-select-sm">
                    <?php foreach ([10, 25, 50, 100] as $sz): ?>
                        <option value="<?= $sz ?>" <?= selected($perPage, $sz) ?>><?= $sz ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" name="sort_by"  value="<?= h($sortBy) ?>">
            <input type="hidden" name="sort_dir" value="<?= h($sortDir) ?>">
            <input type="hidden" name="preset"   value="<?= h($preset) ?>">

            <!-- Buttons -->
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-funnel me-1"></i>Apply Filters
                </button>
                <a href="<?= h(appUrl('logs.php', ['reset_filters' => 1])) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ── Logs Table ──────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2" style="color:var(--ac-blue);"></i>
        <span>Attendance Logs</span>
        <span class="badge-soft ms-2"><?= h((string) $logCount) ?> records</span>
        <?php if ($totalPages > 1): ?>
        <span class="text-muted small ms-2">— page <?= h((string) $page) ?> of <?= h((string) $totalPages) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                <tr>
                    <?php
                    $cols = [
                        'datetime' => 'Date / Time',
                        'name'     => 'Name',
                        'role'     => 'Role',
                        'grade'    => 'Grade / Section',
                        'rfid'     => 'RFID',
                        'type'     => 'Scan Type',
                        'device'   => 'Device',
                    ];
                    foreach ($cols as $col => $label):
                        $newDir = ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc';
                        $icon   = $sortBy === $col ? ($sortDir === 'asc' ? ' ▲' : ' ▼') : ' ↕';
                        $active = $sortBy === $col ? 'style="color:#93c5fd;"' : '';
                    ?>
                    <th>
                        <a class="sort-link" <?= $active ?>
                           href="<?= h(appUrl('logs.php', $baseParams + ['sort_by' => $col, 'sort_dir' => $newDir, 'page' => 1])) ?>">
                            <?= h($label) ?><span class="text-muted" style="font-size:0.7rem;"><?= $icon ?></span>
                        </a>
                    </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-logs">
                                <i class="bi bi-clipboard2-x"></i>
                                <p>No attendance logs match your current filters.</p>
                                <a href="<?= h(appUrl('logs.php', ['reset_filters' => 1])) ?>" class="btn btn-sm btn-outline-primary mt-2">Clear Filters</a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($logs as $log):
                    $ts       = $log['scanned_at'] ?? '';
                    $dateDisp = $ts ? date('M j, Y', strtotime($ts)) : '—';
                    $timeDisp = $ts ? date('h:i:s A', strtotime($ts)) : '—';
                    $fullName = trim(($log['last_name'] ?? '') . ', ' . ($log['first_name'] ?? ''));
                    $grade    = $log['grade_name']   ?? '';
                    $section  = $log['section_name'] ?? '';
                    $gradeSec = ($grade || $section) ? trim($grade . ($section ? ' · ' . $section : '')) : '—';
                ?>
                <tr>
                    <td>
                        <div class="dt-date"><?= h($dateDisp) ?></div>
                        <div class="dt-time"><?= h($timeDisp) ?></div>
                    </td>
                    <td class="fw-semibold text-dark"><?= $fullName ? h($fullName) : '<span class="text-muted">Unknown</span>' ?></td>
                    <td><?= $log['role'] ? roleBadge($log['role']) : '<span class="text-muted small">—</span>' ?></td>
                    <td class="small"><?= h($gradeSec) ?></td>
                    <td><span class="rfid-chip"><?= h($log['rfid_uid'] ?? '—') ?></span></td>
                    <td><?= scanTypeBadge((string) ($log['scan_type'] ?? '')) ?></td>
                    <td class="text-muted small"><?= h($log['device_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1):
        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);
        $pStart   = max(1, $page - 2);
        $pEnd     = min($totalPages, $page + 2);
    ?>
    <div class="logs-pagination d-flex justify-content-between align-items-center">
        <span class="text-muted small">
            Showing <?= h((string) (($page - 1) * $perPage + 1)) ?>–<?= h((string) min($page * $perPage, $logCount)) ?> of <?= h((string) $logCount) ?> records
        </span>
        <ul class="pagination pagination-sm mb-0 gap-1">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h(appUrl('logs.php', $baseParams + ['page' => $prevPage])) ?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php if ($pStart > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= h(appUrl('logs.php', $baseParams + ['page' => 1])) ?>">1</a></li>
                <?php if ($pStart > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $pStart; $p <= $pEnd; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= h(appUrl('logs.php', $baseParams + ['page' => $p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($pEnd < $totalPages): ?>
                <?php if ($pEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= h(appUrl('logs.php', $baseParams + ['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h(appUrl('logs.php', $baseParams + ['page' => $nextPage])) ?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>
