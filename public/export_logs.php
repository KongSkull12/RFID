<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin(['admin', 'teacher', 'employee', 'parent']);

$pdo = db();
$tenantId = tenantId();
$auth = currentUser();
$viewerRole = (string) ($auth['role'] ?? '');
$viewerId = (int) ($auth['id'] ?? 0);
$hasTeacherAssignmentColumn = dbColumnExists('users', 'teacher_user_id');

$format = $_GET['format'] ?? 'csv';
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$gradeId = trim((string) ($_GET['grade_level_id'] ?? ''));
$sectionId = trim((string) ($_GET['section_id'] ?? ''));
$userType = trim((string) ($_GET['user_type'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

if ($viewerRole === 'parent') {
    $userType = 'student';
    $gradeId = '';
    $sectionId = '';
} elseif ($viewerRole === 'teacher') {
    $userType = 'student';
}

$where = ['al.tenant_id = ?'];
$params = [$tenantId];

if ($dateFrom !== '') {
    $where[] = 'DATE(al.scanned_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(al.scanned_at) <= ?';
    $params[] = $dateTo;
}
if ($gradeId !== '') {
    $where[] = 'u.grade_level_id = ?';
    $params[] = $gradeId;
}
if ($sectionId !== '') {
    $where[] = 'u.section_id = ?';
    $params[] = $sectionId;
}
if ($userType !== '') {
    $where[] = 'u.role = ?';
    $params[] = $userType;
}
if ($search !== '') {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($viewerRole === 'parent') {
    $where[] = 'al.user_id IS NOT NULL';
    $where[] = "u.role = 'student'";
    $where[] = 'u.parent_user_id = ?';
    $params[] = $viewerId;
} elseif ($viewerRole === 'teacher') {
    $where[] = 'al.user_id IS NOT NULL';
    $where[] = "u.role = 'student'";
    if ($hasTeacherAssignmentColumn) {
        $where[] = 'u.teacher_user_id = ?';
        $params[] = $viewerId;
    } else {
        $where[] = '1 = 0';
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT
        al.scanned_at,
        u.first_name,
        u.last_name,
        u.role,
        g.name AS grade_name,
        s.name AS section_name,
        al.rfid_uid,
        al.scan_type,
        al.device_name
    FROM attendance_logs al
    LEFT JOIN users u ON u.id = al.user_id AND u.tenant_id = al.tenant_id
    LEFT JOIN grade_levels g ON g.id = u.grade_level_id AND g.tenant_id = al.tenant_id
    LEFT JOIN sections s ON s.id = u.section_id AND s.tenant_id = al.tenant_id
    $whereSql
    ORDER BY al.scanned_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($format === 'csv') {
    requireVendorSpreadsheet();

    $exportLogRows = [];
    foreach ($rows as $row) {
        $ts       = $row['scanned_at'] ?? '';
        $tsParsed = $ts ? strtotime($ts) : false;
        $exportLogRows[] = [
            $tsParsed ? date('d/m/Y', $tsParsed) : ($ts ?: '-'),
            $tsParsed ? date('h:i:s A', $tsParsed) : '-',
            trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')),
            ucfirst((string) ($row['role'] ?? 'Unknown')),
            $row['grade_name'] ?? '-',
            $row['section_name'] ?? '-',
            trim((string) ($row['rfid_uid'] ?? '')),
            $row['scan_type'] ?? '-',
            $row['device_name'] ?? '-',
        ];
    }
    xlsxExport(
        'attendance_logs',
        ['Date', 'Time', 'Name', 'Role', 'Grade', 'Section', 'RFID UID', 'Scan Type', 'Device'],
        $exportLogRows,
        /* textCols: RFID UID=6 */ [6]
    );
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance Logs Print</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        h2 { margin-bottom: 6px; }
        .meta { margin-bottom: 12px; color: #555; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<button class="no-print" onclick="window.print()">Print / Save as PDF</button>
<h2>Attendance Logs</h2>
<div class="meta">Generated: <?= h(date('Y-m-d H:i:s')) ?></div>
<table>
    <thead>
    <tr>
        <th>Date Time</th>
        <th>Name</th>
        <th>Role</th>
        <th>Grade</th>
        <th>Section</th>
        <th>RFID UID</th>
        <th>Type</th>
        <th>Device</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row):
        $ts = $row['scanned_at'] ?? '';
        $tsParsed = $ts ? strtotime($ts) : false;
        $dateDisp = $tsParsed ? date('M j, Y', $tsParsed) : ($ts ?: '-');
        $timeDisp = $tsParsed ? date('h:i:s A', $tsParsed) : '';
    ?>
        <tr>
            <td><?= h($dateDisp) ?><?= $timeDisp ? '<br><small style="color:#666;">' . h($timeDisp) . '</small>' : '' ?></td>
            <td><?= h(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? ''))) ?></td>
            <td><?= h(ucfirst((string) ($row['role'] ?? 'Unknown'))) ?></td>
            <td><?= h($row['grade_name'] ?? '-') ?></td>
            <td><?= h($row['section_name'] ?? '-') ?></td>
            <td><?= h($row['rfid_uid']) ?></td>
            <td><?= h($row['scan_type']) ?></td>
            <td><?= h($row['device_name'] ?? '-') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="8">No logs found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>
