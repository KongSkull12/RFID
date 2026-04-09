<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['parent']);

$pdo = db();
$tenantId = tenantId();
$parentId = (int) (currentUser()['id'] ?? 0);
$hasUserPhotoColumn = dbColumnExists('users', 'photo_path');
$photoSelect = $hasUserPhotoColumn ? 'u.photo_path' : 'NULL AS photo_path';
$photoGroupBy = $hasUserPhotoColumn ? "u.photo_path,\n        " : '';

$studentsStmt = $pdo->prepare("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        $photoSelect,
        c.name AS course_name,
        g.name AS grade_name,
        s.name AS section_name,
        rc.uid AS rfid_uid,
        MAX(al.scanned_at) AS last_scanned_at,
        SUBSTRING_INDEX(GROUP_CONCAT(al.scan_type ORDER BY al.scanned_at DESC), ',', 1) AS last_scan_type,
        MIN(CASE
            WHEN DATE(al.scanned_at) = CURDATE() AND al.scan_type = 'IN'
            THEN al.scanned_at
            ELSE NULL
        END) AS today_first_in,
        MAX(CASE
            WHEN DATE(al.scanned_at) = CURDATE() AND al.scan_type = 'OUT'
            THEN al.scanned_at
            ELSE NULL
        END) AS today_last_out
    FROM users u
    LEFT JOIN courses c ON c.id = u.course_id AND c.tenant_id = u.tenant_id
    LEFT JOIN grade_levels g ON g.id = u.grade_level_id AND g.tenant_id = u.tenant_id
    LEFT JOIN sections s ON s.id = u.section_id AND s.tenant_id = u.tenant_id
    LEFT JOIN rfid_cards rc ON rc.user_id = u.id AND rc.tenant_id = u.tenant_id
    LEFT JOIN attendance_logs al ON al.user_id = u.id AND al.tenant_id = u.tenant_id
    WHERE u.tenant_id = ? AND u.role = 'student' AND u.parent_user_id = ?
    GROUP BY
        u.id,
        u.first_name,
        u.last_name,
        $photoGroupBy
        c.name,
        g.name,
        s.name,
        rc.uid
    ORDER BY u.last_name, u.first_name
");
$studentsStmt->execute([$tenantId, $parentId]);
$students = $studentsStmt->fetchAll();

renderHeader('My Students');
?>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <span>Assigned Students</span>
            <span class="badge-soft"><?= h((string) count($students)) ?> total</span>
        </div>
        <a class="btn btn-sm btn-outline-dark" href="<?= h(appUrl('logs.php', ['user_type' => 'student'])) ?>">View Full Student Logs</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Academe</th>
                    <th>RFID</th>
                    <th>Latest Scan</th>
                    <th>Today First IN</th>
                    <th>Today Last OUT</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img
                                    src="<?= h(userPhotoUrl($student['photo_path'] ?? null)) ?>"
                                    alt="Student photo"
                                    style="width:40px;height:40px;object-fit:cover;border-radius:50%;border:1px solid #dfe5f3;"
                                >
                                <div>
                                    <div class="fw-semibold"><?= h($student['last_name'] . ', ' . $student['first_name']) ?></div>
                                    <div class="small text-muted">Student</div>
                                </div>
                            </div>
                        </td>
                        <td><?= h(($student['course_name'] ?? '-') . ' / ' . ($student['grade_name'] ?? '-') . ' / ' . ($student['section_name'] ?? '-')) ?></td>
                        <td><?= h($student['rfid_uid'] ?? '-') ?></td>
                        <td>
                            <?php if (!empty($student['last_scanned_at'])): ?>
                                <span class="badge text-bg-light border me-1"><?= h((string) ($student['last_scan_type'] ?? '')) ?></span>
                                <span><?= h((string) $student['last_scanned_at']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">No scan yet</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string) ($student['today_first_in'] ?? '-')) ?></td>
                        <td><?= h((string) ($student['today_last_out'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No students are assigned to your parent account yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
