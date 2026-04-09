<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin']);

$pdo = db();
$tenantId = tenantId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'course') {
        $code = trim((string) ($_POST['code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code !== '' && $name !== '') {
            $pdo->prepare("INSERT IGNORE INTO courses (tenant_id, code, name) VALUES (?, ?, ?)")
                ->execute([$tenantId, $code, $name]);
            flash('success', 'Course added.');
        } else {
            flash('error', 'Course code and name are required.');
        }
    }

    if ($action === 'grade') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            $pdo->prepare("INSERT IGNORE INTO grade_levels (tenant_id, name) VALUES (?, ?)")
                ->execute([$tenantId, $name]);
            flash('success', 'Grade level added.');
        } else {
            flash('error', 'Grade level name is required.');
        }
    }

    if ($action === 'section') {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $gradeId = (int) ($_POST['grade_level_id'] ?? 0);
        if ($name !== '' && $gradeId > 0) {
            $pdo->prepare("INSERT INTO sections (tenant_id, name, grade_level_id) VALUES (?, ?, ?)")
                ->execute([$tenantId, $name, $gradeId]);
            flash('success', 'Section added.');
        } else {
            flash('error', 'Section name and grade level are required.');
        }
    }

    if ($action === 'quick_add_grades') {
        $levels = $_POST['levels'] ?? [];
        if (is_array($levels) && count($levels) > 0) {
            $stmt  = $pdo->prepare("INSERT IGNORE INTO grade_levels (tenant_id, name) VALUES (?, ?)");
            $added = 0;
            foreach ($levels as $lvl) {
                $lvl = trim((string) $lvl);
                if ($lvl !== '') {
                    $stmt->execute([$tenantId, $lvl]);
                    if ($stmt->rowCount() > 0) {
                        $added++;
                    }
                }
            }
            flash('success', $added > 0
                ? "{$added} grade level(s) added."
                : 'All selected levels already exist.');
        } else {
            flash('error', 'Select at least one grade level to add.');
        }
    }

    if ($action === 'delete_course') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE course_id = ? AND tenant_id = ?");
            $check->execute([$id, $tenantId]);
            if ((int) $check->fetchColumn() > 0) {
                flash('error', 'Cannot delete: students are enrolled in this course. Reassign them first.');
            } else {
                $pdo->prepare("DELETE FROM courses WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
                flash('success', 'Course deleted.');
            }
        }
    }

    if ($action === 'delete_grade') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $checkStudents = $pdo->prepare("SELECT COUNT(*) FROM users WHERE grade_level_id = ? AND tenant_id = ?");
            $checkStudents->execute([$id, $tenantId]);
            $checkSections = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE grade_level_id = ? AND tenant_id = ?");
            $checkSections->execute([$id, $tenantId]);
            if ((int) $checkStudents->fetchColumn() > 0) {
                flash('error', 'Cannot delete: students are assigned to this grade level. Reassign them first.');
            } elseif ((int) $checkSections->fetchColumn() > 0) {
                flash('error', 'Cannot delete: sections are linked to this grade level. Delete the sections first.');
            } else {
                $pdo->prepare("DELETE FROM grade_levels WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
                flash('success', 'Grade level deleted.');
            }
        }
    }

    if ($action === 'delete_section') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE section_id = ? AND tenant_id = ?");
            $check->execute([$id, $tenantId]);
            if ((int) $check->fetchColumn() > 0) {
                flash('error', 'Cannot delete: students are assigned to this section. Reassign them first.');
            } else {
                $pdo->prepare("DELETE FROM sections WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
                flash('success', 'Section deleted.');
            }
        }
    }

    redirect(appUrl('academics.php'));
}

/* ── Data queries ─────────────────────────────────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM courses WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenantId]);
$courses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM grade_levels WHERE tenant_id = ? ORDER BY id");
$stmt->execute([$tenantId]);
$grades = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT s.*, g.name AS grade_name
    FROM sections s
    LEFT JOIN grade_levels g ON g.id = s.grade_level_id AND g.tenant_id = s.tenant_id
    WHERE s.tenant_id = ?
    ORDER BY g.id, s.name
");
$stmt->execute([$tenantId]);
$sections = $stmt->fetchAll();

/* Student counts per entity */
$courseStudentCounts = [];
$stmt = $pdo->prepare("SELECT course_id, COUNT(*) AS total FROM users WHERE role = 'student' AND tenant_id = ? AND course_id IS NOT NULL GROUP BY course_id");
$stmt->execute([$tenantId]);
foreach ($stmt->fetchAll() as $row) {
    $courseStudentCounts[(int) $row['course_id']] = (int) $row['total'];
}

$gradeStudentCounts = [];
$stmt = $pdo->prepare("SELECT grade_level_id, COUNT(*) AS total FROM users WHERE role = 'student' AND tenant_id = ? AND grade_level_id IS NOT NULL GROUP BY grade_level_id");
$stmt->execute([$tenantId]);
foreach ($stmt->fetchAll() as $row) {
    $gradeStudentCounts[(int) $row['grade_level_id']] = (int) $row['total'];
}

$sectionStudentCounts = [];
$stmt = $pdo->prepare("SELECT section_id, COUNT(*) AS total FROM users WHERE role = 'student' AND tenant_id = ? AND section_id IS NOT NULL GROUP BY section_id");
$stmt->execute([$tenantId]);
foreach ($stmt->fetchAll() as $row) {
    $sectionStudentCounts[(int) $row['section_id']] = (int) $row['total'];
}

/* Existing grade names (for quick-setup deduplication) */
$existingGradeNames = array_map('strtolower', array_column($grades, 'name'));

renderHeader('Academe');
?>

<style>
    .quick-chip {
        cursor: pointer; user-select: none;
        border: 1.5px solid var(--border); border-radius: var(--r-sm);
        padding: 0.3rem 0.65rem; font-size: 0.75rem; font-weight: 600;
        color: var(--tx-secondary); background: var(--surface);
        transition: all var(--ease); display: inline-flex; align-items: center; gap: 0.3rem;
    }
    .quick-chip:hover { border-color: var(--ac-blue); color: var(--ac-blue); }
    .quick-chip input[type=checkbox]:checked ~ span,
    .quick-chip.checked { border-color: var(--ac-blue); background: var(--ac-blue-light); color: var(--ac-blue-dark); }
    .quick-chip input[type=checkbox] { display: none; }
    .section-group-label {
        font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.06em; color: var(--tx-muted); padding: 0.25rem 0; margin-top: 0.5rem;
    }
    .add-form-section {
        background: var(--surface-2); border-radius: var(--r-md);
        padding: 1rem; margin-bottom: 1rem; border: 1px solid var(--border);
    }
    .form-label-sm { font-size: 0.75rem; font-weight: 600; color: var(--tx-secondary); margin-bottom: 0.25rem; }
    .empty-state { text-align: center; padding: 2rem 1rem; color: var(--tx-muted); font-size: 0.82rem; }
    .empty-state i { font-size: 1.75rem; display: block; margin-bottom: 0.5rem; }
    .header-title { font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; }
</style>

<!-- Quick Grade Level Setup -->
<div class="card acad-card mb-4">
    <div class="card-header">
        <div class="header-title">
            <i class="bi bi-lightning-charge-fill text-warning"></i>
            Quick Grade Level Setup
        </div>
        <span class="text-muted small">Select levels to add in one click</span>
    </div>
    <div class="card-body">
        <form method="post" id="quickGradeForm">
            <input type="hidden" name="action" value="quick_add_grades">

            <div class="section-group-label">Kindergarten</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach (['Kinder 1', 'Kinder 2'] as $lvl): ?>
                    <?php $exists = in_array(strtolower($lvl), $existingGradeNames, true); ?>
                    <label class="quick-chip <?= $exists ? 'opacity-50' : '' ?>" title="<?= $exists ? 'Already added' : 'Click to select' ?>">
                        <input type="checkbox" name="levels[]" value="<?= h($lvl) ?>" <?= $exists ? 'disabled' : '' ?>>
                        <?php if ($exists): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                        <?php endif; ?>
                        <span><?= h($lvl) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="section-group-label">Elementary (Grades 1–6)</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach (['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'] as $lvl): ?>
                    <?php $exists = in_array(strtolower($lvl), $existingGradeNames, true); ?>
                    <label class="quick-chip <?= $exists ? 'opacity-50' : '' ?>" title="<?= $exists ? 'Already added' : 'Click to select' ?>">
                        <input type="checkbox" name="levels[]" value="<?= h($lvl) ?>" <?= $exists ? 'disabled' : '' ?>>
                        <?php if ($exists): ?><i class="bi bi-check-circle-fill text-success"></i><?php endif; ?>
                        <span><?= h($lvl) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="section-group-label">Junior High School (Grades 7–10)</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] as $lvl): ?>
                    <?php $exists = in_array(strtolower($lvl), $existingGradeNames, true); ?>
                    <label class="quick-chip <?= $exists ? 'opacity-50' : '' ?>" title="<?= $exists ? 'Already added' : 'Click to select' ?>">
                        <input type="checkbox" name="levels[]" value="<?= h($lvl) ?>" <?= $exists ? 'disabled' : '' ?>>
                        <?php if ($exists): ?><i class="bi bi-check-circle-fill text-success"></i><?php endif; ?>
                        <span><?= h($lvl) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="section-group-label">Senior High School (Grades 11–12)</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach (['Grade 11', 'Grade 12'] as $lvl): ?>
                    <?php $exists = in_array(strtolower($lvl), $existingGradeNames, true); ?>
                    <label class="quick-chip <?= $exists ? 'opacity-50' : '' ?>" title="<?= $exists ? 'Already added' : 'Click to select' ?>">
                        <input type="checkbox" name="levels[]" value="<?= h($lvl) ?>" <?= $exists ? 'disabled' : '' ?>>
                        <?php if ($exists): ?><i class="bi bi-check-circle-fill text-success"></i><?php endif; ?>
                        <span><?= h($lvl) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>Add Selected Levels
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllBtn">
                    Select All
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="clearAllBtn">
                    Clear All
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Course / Grade / Section management -->
<div class="row g-3">

    <!-- Courses -->
    <div class="col-lg-4">
        <div class="card acad-card h-100">
            <div class="card-header">
                <div class="header-title">
                    <i class="bi bi-journal-bookmark-fill text-primary"></i>Courses / Programs
                </div>
                <span class="count-badge <?= count($courses) > 0 ? 'has-students' : '' ?>">
                    <?= h((string) count($courses)) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="add-form-section">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="course">
                        <div class="col-12">
                            <label class="form-label-sm">Short Code</label>
                            <input class="form-control form-control-sm" name="code" placeholder="e.g. BSIT, JHS, KINDER" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label-sm">Course / Program Name</label>
                            <input class="form-control form-control-sm" name="name" placeholder="e.g. Bachelor of Science in IT" required>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-plus me-1"></i>Add Course
                            </button>
                        </div>
                    </form>
                </div>

                <?php if ($courses): ?>
                <div class="table-responsive">
                    <table class="table table mb-0">
                        <thead>
                            <tr><th>Code</th><th>Name</th><th>Students</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($courses as $course): ?>
                            <?php $cnt = $courseStudentCounts[(int) $course['id']] ?? 0; ?>
                            <tr>
                                <td><code class="small"><?= h($course['code']) ?></code></td>
                                <td><?= h($course['name']) ?></td>
                                <td>
                                    <span class="count-badge <?= $cnt > 0 ? 'has-students' : '' ?>">
                                        <i class="bi bi-people-fill" style="font-size:0.65rem;"></i><?= h((string) $cnt) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete course \'<?= h(addslashes($course['name'])) ?>\'?')">
                                        <input type="hidden" name="action" value="delete_course">
                                        <input type="hidden" name="id" value="<?= h((string) $course['id']) ?>">
                                        <button class="btn btn-sm btn-link text-danger p-0" title="Delete" <?= $cnt > 0 ? 'disabled' : '' ?>>
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-journal-x"></i>No courses yet.<br>Add one above.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Grade Levels -->
    <div class="col-lg-4">
        <div class="card acad-card h-100">
            <div class="card-header">
                <div class="header-title">
                    <i class="bi bi-mortarboard-fill text-success"></i>Grade Levels
                </div>
                <span class="count-badge <?= count($grades) > 0 ? 'has-students' : '' ?>">
                    <?= h((string) count($grades)) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="add-form-section">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="grade">
                        <div class="col-12">
                            <label class="form-label-sm">Grade Level Name</label>
                            <input class="form-control form-control-sm" name="name" placeholder="e.g. Kinder 1, Grade 7" required>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-success btn-sm w-100">
                                <i class="bi bi-plus me-1"></i>Add Grade Level
                            </button>
                        </div>
                    </form>
                </div>

                <?php if ($grades): ?>
                <div class="table-responsive">
                    <table class="table table mb-0">
                        <thead>
                            <tr><th>#</th><th>Name</th><th>Students</th><th>Sections</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php
                        $sectionCountByGrade = [];
                        foreach ($sections as $sec) {
                            $gid = (int) $sec['grade_level_id'];
                            $sectionCountByGrade[$gid] = ($sectionCountByGrade[$gid] ?? 0) + 1;
                        }
                        foreach ($grades as $i => $grade):
                            $cnt    = $gradeStudentCounts[(int) $grade['id']] ?? 0;
                            $secCnt = $sectionCountByGrade[(int) $grade['id']] ?? 0;
                        ?>
                            <tr>
                                <td class="text-muted" style="font-size:0.7rem;"><?= $i + 1 ?></td>
                                <td><?= h($grade['name']) ?></td>
                                <td>
                                    <span class="count-badge <?= $cnt > 0 ? 'has-students' : '' ?>">
                                        <i class="bi bi-people-fill" style="font-size:0.65rem;"></i><?= h((string) $cnt) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="count-badge">
                                        <i class="bi bi-grid-3x2-gap" style="font-size:0.65rem;"></i><?= h((string) $secCnt) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete grade level \'<?= h(addslashes($grade['name'])) ?>\'?')">
                                        <input type="hidden" name="action" value="delete_grade">
                                        <input type="hidden" name="id" value="<?= h((string) $grade['id']) ?>">
                                        <button class="btn btn-sm btn-link text-danger p-0" title="Delete" <?= ($cnt > 0 || $secCnt > 0) ? 'disabled' : '' ?>>
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-list-ol"></i>No grade levels yet.<br>Use Quick Setup above or add one here.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sections -->
    <div class="col-lg-4">
        <div class="card acad-card h-100">
            <div class="card-header">
                <div class="header-title">
                    <i class="bi bi-grid-3x2-gap-fill text-warning"></i>Sections
                </div>
                <span class="count-badge <?= count($sections) > 0 ? 'has-students' : '' ?>">
                    <?= h((string) count($sections)) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="add-form-section">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="section">
                        <div class="col-12">
                            <label class="form-label-sm">Section Name</label>
                            <input class="form-control form-control-sm" name="name" placeholder="e.g. Section A, Sampaguita" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label-sm">Grade Level</label>
                            <select class="form-select form-select-sm" name="grade_level_id" required>
                                <option value="">— Select grade level —</option>
                                <?php foreach ($grades as $grade): ?>
                                    <option value="<?= h((string) $grade['id']) ?>"><?= h($grade['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$grades): ?>
                                <div class="form-text text-danger">Add grade levels first.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-warning btn-sm w-100" <?= !$grades ? 'disabled' : '' ?>>
                                <i class="bi bi-plus me-1"></i>Add Section
                            </button>
                        </div>
                    </form>
                </div>

                <?php if ($sections): ?>
                <div class="table-responsive">
                    <table class="table table mb-0">
                        <thead>
                            <tr><th>Grade</th><th>Section</th><th>Students</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sections as $section): ?>
                            <?php $cnt = $sectionStudentCounts[(int) $section['id']] ?? 0; ?>
                            <tr>
                                <td class="text-muted small"><?= h($section['grade_name'] ?? '—') ?></td>
                                <td><?= h($section['name']) ?></td>
                                <td>
                                    <span class="count-badge <?= $cnt > 0 ? 'has-students' : '' ?>">
                                        <i class="bi bi-people-fill" style="font-size:0.65rem;"></i><?= h((string) $cnt) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete section \'<?= h(addslashes($section['name'])) ?>\'?')">
                                        <input type="hidden" name="action" value="delete_section">
                                        <input type="hidden" name="id" value="<?= h((string) $section['id']) ?>">
                                        <button class="btn btn-sm btn-link text-danger p-0" title="Delete" <?= $cnt > 0 ? 'disabled' : '' ?>>
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-grid-3x2-gap"></i>No sections yet.<br>Add one above.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
/* Quick-chip checkbox toggle */
document.querySelectorAll('.quick-chip').forEach((chip) => {
    const cb = chip.querySelector('input[type=checkbox]');
    if (!cb || cb.disabled) return;
    chip.addEventListener('click', () => {
        cb.checked = !cb.checked;
        chip.classList.toggle('checked', cb.checked);
    });
});

document.getElementById('selectAllBtn')?.addEventListener('click', () => {
    document.querySelectorAll('#quickGradeForm input[type=checkbox]:not(:disabled)').forEach((cb) => {
        cb.checked = true;
        cb.closest('.quick-chip')?.classList.add('checked');
    });
});

document.getElementById('clearAllBtn')?.addEventListener('click', () => {
    document.querySelectorAll('#quickGradeForm input[type=checkbox]:not(:disabled)').forEach((cb) => {
        cb.checked = false;
        cb.closest('.quick-chip')?.classList.remove('checked');
    });
});
</script>

<?php renderFooter(); ?>
