<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST only.']);
    exit;
}

$uid = trim((string) ($_POST['uid'] ?? ''));
$deviceName = trim((string) ($_POST['device_name'] ?? 'RFID Device'));
$requestedScanType = strtoupper(trim((string) ($_POST['scan_type'] ?? '')));
$scanType = $requestedScanType;

if ($uid === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'uid is required']);
    exit;
}

$pdo = db();
$tenantId = tenantId();
$hasUserPhotoColumn = dbColumnExists('users', 'photo_path');

$photoSelect = $hasUserPhotoColumn ? 'u.photo_path,' : "NULL AS photo_path,";

$stmt = $pdo->prepare("
    SELECT
        rc.uid,
        rc.user_id,
        u.first_name,
        u.last_name,
        u.middle_name,
        u.status,
        u.role,
        u.parent_user_id,
        parent.phone AS parent_phone,
        parent.first_name AS parent_first_name,
        parent.last_name AS parent_last_name,
        u.phone AS user_phone,
        $photoSelect
        c.name AS course_name,
        g.name AS grade_name,
        s.name AS section_name
    FROM rfid_cards rc
    LEFT JOIN users u ON u.id = rc.user_id AND u.tenant_id = rc.tenant_id
    LEFT JOIN users parent ON parent.id = u.parent_user_id AND parent.tenant_id = u.tenant_id
    LEFT JOIN courses c ON c.id = u.course_id AND c.tenant_id = u.tenant_id
    LEFT JOIN grade_levels g ON g.id = u.grade_level_id AND g.tenant_id = u.tenant_id
    LEFT JOIN sections s ON s.id = u.section_id AND s.tenant_id = u.tenant_id
    WHERE rc.uid = ? AND rc.tenant_id = ?
");
$stmt->execute([$uid, $tenantId]);
$card = $stmt->fetch();

if (!$card) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'RFID not registered']);
    exit;
}

if (!$card['user_id']) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'RFID not assigned']);
    exit;
}

if (($card['status'] ?? 'inactive') !== 'active') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'User inactive']);
    exit;
}

if (!in_array($scanType, ['IN', 'OUT'], true)) {
    if (AUTO_SCAN_TOGGLE) {
        $latestStmt = $pdo->prepare("
            SELECT scan_type
            FROM attendance_logs
            WHERE user_id = ? AND tenant_id = ? AND DATE(scanned_at) = CURDATE()
            ORDER BY scanned_at DESC
            LIMIT 1
        ");
        $latestStmt->execute([(int) $card['user_id'], $tenantId]);
        $latestType = $latestStmt->fetchColumn();
        $scanType = ($latestType === 'IN') ? 'OUT' : 'IN';
    } else {
        $scanType = 'IN';
    }
}

$remarks = 'Scan accepted';
if ($scanType === 'IN' && ($card['role'] ?? '') === 'student') {
    $cutoffTs = strtotime(date('Y-m-d') . ' ' . LATE_CUTOFF_TIME);
    $nowTs = time();
    if ($nowTs > $cutoffTs) {
        $lateMinutes = (int) floor(($nowTs - $cutoffTs) / 60);
        $remarks = 'Late by ' . $lateMinutes . ' minute(s)';
    } else {
        $remarks = 'On time';
    }
}

$scannedAtSql = app_now_sql();
$logStmt = $pdo->prepare("
    INSERT INTO attendance_logs (tenant_id, user_id, rfid_uid, scan_type, device_name, remarks, scanned_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$logStmt->execute([
    $tenantId,
    (int) $card['user_id'],
    $uid,
    $scanType,
    $deviceName,
    $remarks,
    $scannedAtSql,
]);

$attendanceLogId = (int) $pdo->lastInsertId();
$scannedAtDisplay = $scannedAtSql;
$smsQueue = ['queued' => false, 'reason' => null, 'hint' => null];
if ($attendanceLogId > 0) {
    $smsQueue = maybeQueueParentAttendanceSms(
        $pdo,
        $tenantId,
        $attendanceLogId,
        $scanType,
        $card,
        $deviceName,
        $remarks,
        $scannedAtDisplay
    );
    if (($smsQueue['queued'] ?? false) === false
        && in_array((string) ($card['role'] ?? ''), ['teacher', 'employee'], true)
    ) {
        $smsQueue = maybeQueueStaffScanSms(
            $pdo,
            $tenantId,
            $attendanceLogId,
            $scanType,
            $card,
            $deviceName,
            $remarks,
            $scannedAtDisplay
        );
    }
}

$p = [
    'ok' => true,
    'message' => 'Attendance logged',
    'user' => trim($card['first_name'] . ' ' . $card['last_name']),
    'scan_type' => $scanType,
    'remarks' => $remarks,
    'time' => $scannedAtDisplay,
    'user_profile' => [
        'name' => trim($card['first_name'] . ' ' . ($card['middle_name'] ? ($card['middle_name'] . ' ') : '') . $card['last_name']),
        'role' => roleLabel((string) ($card['role'] ?? '')),
        'course' => $card['course_name'] ?? null,
        'grade' => $card['grade_name'] ?? null,
        'section' => $card['section_name'] ?? null,
        'photo_url' => userPhotoUrl($card['photo_path'] ?? null),
    ],
    'sms' => array_merge($smsQueue, [
        'local_auto_send' => defined('SMS_LOCAL_AUTO_SEND') && SMS_LOCAL_AUTO_SEND,
    ]),
];

echo json_encode($p);

while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
}

if (($smsQueue['queued'] ?? false) === true) {
    trigger_local_sms_worker_after_queue($tenantId);
}
