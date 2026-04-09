<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin', 'teacher', 'employee', 'parent']);

$authEarly = currentUser();
if (($authEarly['role'] ?? '') === 'parent') {
    redirect(appUrl('parent_students.php'));
}

$pdo = db();
$tenantId = tenantId();
$auth = currentUser();
$viewerRole = (string) ($auth['role'] ?? '');
$viewerId = (int) ($auth['id'] ?? 0);
$hasTeacherAssignmentColumn = dbColumnExists('users', 'teacher_user_id');

$studentAccessWhere = "role = 'student' AND tenant_id = ?";
$studentAccessParams = [$tenantId];
if ($viewerRole === 'parent') {
    $studentAccessWhere .= ' AND parent_user_id = ?';
    $studentAccessParams[] = $viewerId;
} elseif ($viewerRole === 'teacher') {
    if ($hasTeacherAssignmentColumn) {
        $studentAccessWhere .= ' AND teacher_user_id = ?';
        $studentAccessParams[] = $viewerId;
    } else {
        $studentAccessWhere .= ' AND 1 = 0';
        flash('error', 'Teacher assignment is unavailable until database is updated. Run sql/saas_upgrade.sql.');
    }
}

$logJoinScope = '';
$logWhereScope = 'al.tenant_id = ?';
$logParams = [$tenantId];
if ($viewerRole === 'parent') {
    $logJoinScope = 'INNER JOIN users su ON su.id = al.user_id';
    $logWhereScope .= " AND su.role = 'student' AND su.parent_user_id = ?";
    $logParams[] = $viewerId;
} elseif ($viewerRole === 'teacher') {
    if ($hasTeacherAssignmentColumn) {
        $logJoinScope = 'INNER JOIN users su ON su.id = al.user_id';
        $logWhereScope .= " AND su.role = 'student' AND su.teacher_user_id = ?";
        $logParams[] = $viewerId;
    } else {
        $logWhereScope .= ' AND 1 = 0';
    }
}

$stmt = $pdo->prepare("
    SELECT gender, COUNT(*) AS total
    FROM users
    WHERE $studentAccessWhere
    GROUP BY gender
");
$stmt->execute($studentAccessParams);
$studentByGender = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT gender, COUNT(*) AS total
    FROM users
    WHERE role IN ('teacher', 'employee') AND tenant_id = ?
    GROUP BY gender
");
$stmt->execute([$tenantId]);
$staffByGender = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'parent' AND tenant_id = ?");
$stmt->execute([$tenantId]);
$parentCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $studentAccessWhere");
$stmt->execute($studentAccessParams);
$studentCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $studentAccessWhere AND status = 'active'");
$stmt->execute($studentAccessParams);
$activeStudentCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND tenant_id = ?");
$stmt->execute([$tenantId]);
$teacherCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'employee' AND tenant_id = ?");
$stmt->execute([$tenantId]);
$employeeCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT DATE(al.scanned_at) AS day, COUNT(*) AS total
    FROM attendance_logs al
    $logJoinScope
    WHERE al.scanned_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      AND $logWhereScope
    GROUP BY DATE(al.scanned_at)
    ORDER BY DATE(al.scanned_at) ASC
");
$stmt->execute($logParams);
$graphRows = $stmt->fetchAll();

$graphLabels = [];
$graphTotals = [];
foreach ($graphRows as $row) {
    $graphLabels[] = $row['day'];
    $graphTotals[] = (int) $row['total'];
}

$genderMap = ['male' => 0, 'female' => 0, 'other' => 0];
foreach ($studentByGender as $row) {
    $genderMap[$row['gender']] = (int) $row['total'];
}

$stmt = $pdo->prepare("
    SELECT COALESCE(u.role, 'unknown') AS role_name, COUNT(*) AS total
    FROM attendance_logs al
    LEFT JOIN users u ON u.id = al.user_id
    $logJoinScope
    WHERE al.scanned_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND $logWhereScope
    GROUP BY COALESCE(u.role, 'unknown')
    ORDER BY total DESC
");
$stmt->execute($logParams);
$roleScanRows = $stmt->fetchAll();
$roleLabels = [];
$roleTotals = [];
foreach ($roleScanRows as $row) {
    $roleLabels[] = strtoupper((string) $row['role_name']);
    $roleTotals[] = (int) $row['total'];
}

renderHeader('Dashboard');

$staffMap = ['male' => 0, 'female' => 0, 'other' => 0];
foreach ($staffByGender as $row) {
    $staffMap[$row['gender']] = (int) $row['total'];
}
?>

<?php
/* compute max scans for the mini bar widths */
$maxScans = max(array_column($graphRows, 'total') ?: [1]);
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card sa-blue">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Students</div>
                    <div class="stat-value"><?= h((string) $studentCount) ?></div>
                    <div class="stat-sub"><?= h((string) $activeStudentCount) ?> active</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card sa-green">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
                <div>
                    <div class="stat-label">Active</div>
                    <div class="stat-value"><?= h((string) $activeStudentCount) ?></div>
                    <div class="stat-sub">of <?= h((string) $studentCount) ?> total</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card sa-amber">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-person-hearts"></i></div>
                <div>
                    <div class="stat-label">Parents</div>
                    <div class="stat-value"><?= h((string) $parentCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card sa-purple">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                <div>
                    <div class="stat-label">Teachers</div>
                    <div class="stat-value"><?= h((string) $teacherCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card sa-rose">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-briefcase-fill"></i></div>
                <div>
                    <div class="stat-label">Employees</div>
                    <div class="stat-value"><?= h((string) $employeeCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card sa-sky">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-gender-ambiguous"></i></div>
                <div>
                    <div class="stat-label">Staff M/F</div>
                    <div class="stat-value" style="font-size:1.2rem;"><?= h($staffMap['male'] . ' / ' . $staffMap['female']) ?></div>
                    <div class="stat-sub"><?= h((string) $staffMap['other']) ?> other</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="card-heading-title">Student gender</div>
                    <div class="card-heading-muted"><?= h((string) $studentCount) ?> students in scope</div>
                </div>
                <i class="bi bi-pie-chart-fill text-muted fs-5 opacity-75"></i>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:220px;">
                <canvas id="studentGenderChart" style="max-height:210px;max-width:210px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="card-heading-title">Scans by role</div>
                    <div class="card-heading-muted">Last 30 days</div>
                </div>
                <i class="bi bi-bar-chart-fill text-muted fs-5 opacity-75"></i>
            </div>
            <div class="card-body" style="min-height:220px;">
                <canvas id="roleScanChart" style="max-height:200px;"></canvas>
                <?php if (!$roleScanRows): ?>
                    <p class="text-center text-muted small mt-4">No scan data in the last 30 days.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Trend -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <div class="card-heading-title">Attendance trend</div>
            <div class="card-heading-muted">Daily scans — last 7 days</div>
        </div>
        <i class="bi bi-graph-up-arrow text-muted fs-5 opacity-75"></i>
    </div>
    <div class="card-body">
        <?php if ($graphRows): ?>
        <canvas id="attendanceChart" style="max-height:160px;" class="mb-3"></canvas>
        <?php endif; ?>
        <div class="table-responsive" style="max-height:none;">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Scans</th>
                        <th style="width:40%;">Activity</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($graphRows as $row): ?>
                    <?php $barPct = $maxScans > 0 ? round((int)$row['total'] / $maxScans * 100) : 0; ?>
                    <tr>
                        <td style="font-weight:600;color:var(--tx-primary);"><?= h(date('D, M j', strtotime($row['day']))) ?></td>
                        <td><span class="fw-bold"><?= h((string) $row['total']) ?></span></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="height:7px;border-radius:999px;background:var(--ac-blue);width:<?= $barPct ?>%;min-width:4px;flex-shrink:0;"></div>
                                <span class="text-muted" style="font-size:0.72rem;"><?= $barPct ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$graphRows): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No attendance data in the last 7 days.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#64748b';

const attendanceLabels = <?= json_encode($graphLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const attendanceData   = <?= json_encode($graphTotals) ?>;
const genderData       = <?= json_encode([$genderMap['male'], $genderMap['female'], $genderMap['other']]) ?>;
const roleLabels       = <?= json_encode($roleLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const roleData         = <?= json_encode($roleTotals) ?>;

/* Attendance line chart */
if (attendanceLabels.length > 0) {
    new Chart(document.getElementById('attendanceChart'), {
        type: 'line',
        data: {
            labels: attendanceLabels,
            datasets: [{
                label: 'Scans',
                data: attendanceData,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.08)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                fill: true,
                tension: 0.38
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y + ' scans' } }
            },
            scales: {
                x: { grid: { display: false }, border: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 }, grid: { color: '#f1f5f9' }, border: { display: false } }
            }
        }
    });
}

/* Gender doughnut */
new Chart(document.getElementById('studentGenderChart'), {
    type: 'doughnut',
    data: {
        labels: ['Male', 'Female', 'Other'],
        datasets: [{
            data: genderData,
            backgroundColor: ['#3b82f6', '#ec4899', '#f59e0b'],
            borderWidth: 3,
            borderColor: '#fff',
            hoverOffset: 6
        }]
    },
    options: {
        cutout: '65%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10, font: { size: 12 } }
            }
        }
    }
});

/* Role scan bar chart */
if (roleLabels.length > 0) {
    const roleColors = ['#3b82f6','#22c55e','#f59e0b','#8b5cf6','#f43f5e','#14b8a6'];
    new Chart(document.getElementById('roleScanChart'), {
        type: 'bar',
        data: {
            labels: roleLabels,
            datasets: [{
                label: 'Scans',
                data: roleData,
                backgroundColor: roleLabels.map((_, i) => roleColors[i % roleColors.length]),
                borderRadius: 8,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y + ' scans' } }
            },
            scales: {
                x: { grid: { display: false }, border: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' }, border: { display: false } }
            }
        }
    });
}
</script>

<?php renderFooter(); ?>
