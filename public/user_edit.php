<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin']);

$pdo = db();
$tenantId = tenantId();
$hasUserPhotoColumn = dbColumnExists('users', 'photo_path');
$hasTeacherAssignmentColumn = dbColumnExists('users', 'teacher_user_id');
$studentProfileColumns = [
    'birth_date' => dbColumnExists('users', 'birth_date'),
    'nationality' => dbColumnExists('users', 'nationality'),
    'full_address' => dbColumnExists('users', 'full_address'),
    'region' => dbColumnExists('users', 'region'),
    'province' => dbColumnExists('users', 'province'),
    'city' => dbColumnExists('users', 'city'),
    'barangay' => dbColumnExists('users', 'barangay'),
    'zipcode' => dbColumnExists('users', 'zipcode'),
    'facebook_link' => dbColumnExists('users', 'facebook_link'),
    'religion' => dbColumnExists('users', 'religion'),
    'lrn' => dbColumnExists('users', 'lrn'),
    'stay_with' => dbColumnExists('users', 'stay_with'),
    'is_transferee' => dbColumnExists('users', 'is_transferee'),
];
$hasStudentProfileSupport = !in_array(false, $studentProfileColumns, true);
$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($userId <= 0) {
    redirect(appUrl('users.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $roleStmt->execute([$userId, $tenantId]);
        $editingRole = (string) ($roleStmt->fetchColumn() ?: '');
        $isStudent = $editingRole === 'student';
        $uploadError = null;
        $photoPath = $hasUserPhotoColumn ? saveUploadedUserPhoto('photo_file', $uploadError) : null;
        if ($uploadError) {
            flash('error', $uploadError);
        }
        if (!$hasUserPhotoColumn && isset($_FILES['photo_file']) && (int) ($_FILES['photo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            flash('error', 'Photo upload is disabled until database is updated. Run sql/saas_upgrade.sql.');
        }

        $sql = "
            UPDATE users
            SET first_name = ?, last_name = ?, middle_name = ?, email = ?, phone = ?, status = ?
        ";
        $params = [
            trim((string) ($_POST['first_name'] ?? '')),
            trim((string) ($_POST['last_name'] ?? '')),
            trim((string) ($_POST['middle_name'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            trim((string) ($_POST['phone'] ?? '')),
            $_POST['status'] ?? 'active',
        ];
        if ($hasUserPhotoColumn && $photoPath !== null) {
            $sql .= ", photo_path = ?";
            $params[] = $photoPath;
        }
        if ($isStudent) {
            $sql .= ", parent_user_id = ?";
            $params[] = !empty($_POST['parent_user_id']) ? (int) $_POST['parent_user_id'] : null;
            if ($hasTeacherAssignmentColumn) {
                $sql .= ", teacher_user_id = ?";
                $params[] = !empty($_POST['teacher_user_id']) ? (int) $_POST['teacher_user_id'] : null;
            }
            if ($hasStudentProfileSupport) {
                $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
                $birthDate = $birthDate !== '' ? $birthDate : null;
                $sql .= ", birth_date = ?, nationality = ?, full_address = ?, region = ?, province = ?, city = ?, barangay = ?, zipcode = ?, facebook_link = ?, religion = ?, lrn = ?, stay_with = ?, is_transferee = ?";
                array_push(
                    $params,
                    $birthDate,
                    trim((string) ($_POST['nationality'] ?? '')) ?: null,
                    trim((string) ($_POST['full_address'] ?? '')) ?: null,
                    trim((string) ($_POST['region'] ?? '')) ?: null,
                    trim((string) ($_POST['province'] ?? '')) ?: null,
                    trim((string) ($_POST['city'] ?? '')) ?: null,
                    trim((string) ($_POST['barangay'] ?? '')) ?: null,
                    trim((string) ($_POST['zipcode'] ?? '')) ?: null,
                    trim((string) ($_POST['facebook_link'] ?? '')) ?: null,
                    trim((string) ($_POST['religion'] ?? '')) ?: null,
                    trim((string) ($_POST['lrn'] ?? '')) ?: null,
                    trim((string) ($_POST['stay_with'] ?? '')) ?: null,
                    isset($_POST['is_transferee']) ? 1 : 0
                );
            }
        }
        $sql .= " WHERE id = ? AND tenant_id = ?";
        $params[] = $userId;
        $params[] = $tenantId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        flash('success', 'User information updated.');
    }

    if ($action === 'update_credentials') {
        $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $roleStmt->execute([$userId, $tenantId]);
        $editingRole = (string) ($roleStmt->fetchColumn() ?: '');
        if ($editingRole === 'student') {
            flash('error', 'Student accounts do not use login credentials.');
            redirect(appUrl('user_edit.php', ['id' => $userId]));
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        if ($username !== '') {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, password_hash = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $userId, $tenantId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$username, $userId, $tenantId]);
                }
                flash('success', 'Credentials updated.');
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    flash('error', 'Username already exists for this tenant. Please use a different username.');
                } else {
                    flash('error', 'Unable to update credentials right now. Please try again.');
                }
            }
        }
    }

    redirect(appUrl('user_edit.php', ['id' => $userId]));
}

$stmt = $pdo->prepare("
    SELECT u.*, rc.uid AS rfid_uid
    FROM users u
    LEFT JOIN rfid_cards rc ON rc.user_id = u.id AND rc.tenant_id = u.tenant_id
    WHERE u.id = ? AND u.tenant_id = ?
");
$stmt->execute([$userId, $tenantId]);
$user = $stmt->fetch();

if (!$user) {
    redirect(appUrl('users.php'));
}

$parentsStmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE role = 'parent' AND tenant_id = ? ORDER BY last_name, first_name");
$parentsStmt->execute([$tenantId]);
$parents = $parentsStmt->fetchAll();
$teachersStmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' AND tenant_id = ? ORDER BY last_name, first_name");
$teachersStmt->execute([$tenantId]);
$teachers = $teachersStmt->fetchAll();

renderHeader('Manage User');
?>

<style>
    .edit-section-label {
        font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--tx-muted);
        margin-bottom: 0.6rem; margin-top: 0.25rem;
        display: flex; align-items: center; gap: 0.4rem;
    }
    .edit-section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .user-profile-header {
        display: flex; align-items: center; gap: 1rem;
        padding: 1rem 1.25rem;
        background: linear-gradient(135deg, var(--surface-2), #eef2ff);
        border-bottom: 1px solid var(--border);
    }
    .user-profile-header img {
        width: 68px; height: 68px; object-fit: cover; border-radius: 50%;
        border: 3px solid #fff; box-shadow: var(--shadow-md); flex-shrink: 0;
    }
    .user-profile-header .user-meta h6 { font-size: 1rem; font-weight: 700; color: var(--tx-primary); margin-bottom: 0.2rem; }
    .role-badge {
        display: inline-block; padding: 0.2rem 0.55rem; border-radius: var(--r-pill);
        font-size: 0.72rem; font-weight: 700; text-transform: capitalize; letter-spacing: 0.03em;
    }
    .role-badge.student  { background: var(--ac-blue-light);   color: var(--ac-blue-dark); }
    .role-badge.teacher  { background: var(--ac-green-light);  color: #166534; }
    .role-badge.parent   { background: var(--ac-amber-light);  color: #92400e; }
    .role-badge.employee { background: var(--ac-purple-light); color: #5b21b6; }
    .role-badge.admin    { background: var(--ac-rose-light);   color: #9f1239; }
    .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
    .status-dot.active   { background: var(--ac-green); }
    .status-dot.inactive { background: var(--ac-rose); }
    .rfid-badge {
        background: var(--ac-green-light); border: 1px solid #bbf7d0;
        border-radius: var(--r-md); padding: 0.75rem 1rem;
        display: flex; align-items: center; gap: 0.75rem;
    }
    .rfid-badge.unassigned { background: var(--surface-2); border-color: var(--border); }
    .rfid-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .rfid-badge .rfid-icon { background: #dcfce7; color: #16a34a; }
    .rfid-badge.unassigned .rfid-icon { background: var(--surface-3); color: var(--tx-muted); }
</style>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= h(appUrl('users.php')) ?>" class="btn btn-sm btn-light">
        <i class="bi bi-arrow-left me-1"></i>Back to Users
    </a>
    <span class="text-muted small">/ <?= h($user['last_name'] . ', ' . $user['first_name']) ?></span>
</div>

<div class="row g-3">
    <!-- Left: Update Information -->
    <div class="col-lg-7">
        <div class="card">
            <div class="user-profile-header">
                <img src="<?= h(userPhotoUrl($hasUserPhotoColumn ? ($user['photo_path'] ?? null) : null)) ?>" alt="Profile photo">
                <div class="user-meta">
                    <h6><?= h($user['first_name'] . ' ' . $user['last_name']) ?></h6>
                    <span class="role-badge <?= h($user['role']) ?>"><?= h(roleLabel($user['role'])) ?></span>
                    <span class="ms-1 small text-muted">
                        <span class="status-dot <?= h($user['status'] ?? 'inactive') ?>"></span><?= h(ucfirst($user['status'] ?? 'inactive')) ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="id" value="<?= h((string) $userId) ?>">
                    <input type="hidden" name="action" value="update_info">

                    <!-- Profile Photo -->
                    <?php if ($hasUserPhotoColumn): ?>
                    <div class="col-12">
                        <div class="edit-section-label"><i class="bi bi-camera"></i> Profile Photo</div>
                        <input type="file" name="photo_file" class="form-control form-control-sm" accept="image/*">
                        <div class="form-text">JPG, PNG, WEBP or GIF — max 5 MB</div>
                    </div>
                    <?php endif; ?>

                    <!-- Personal Information -->
                    <div class="col-12">
                        <div class="edit-section-label"><i class="bi bi-person"></i> Personal Information</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input class="form-control" name="first_name" value="<?= h($user['first_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input class="form-control" name="last_name" value="<?= h($user['last_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Middle Name</label>
                        <input class="form-control" name="middle_name" value="<?= h($user['middle_name'] ?? '') ?>" placeholder="Optional">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" <?= selected($user['status'], 'active') ?>>Active</option>
                            <option value="inactive" <?= selected($user['status'], 'inactive') ?>>Inactive</option>
                        </select>
                    </div>
                    <?php if ($user['role'] === 'student' && $studentProfileColumns['birth_date']): ?>
                    <div class="col-md-3">
                        <label class="form-label">Birth Date</label>
                        <input type="date" name="birth_date" class="form-control" value="<?= h((string) ($user['birth_date'] ?? '')) ?>">
                    </div>
                    <?php endif; ?>

                    <!-- Contact -->
                    <div class="col-12">
                        <div class="edit-section-label"><i class="bi bi-envelope"></i> Contact</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= h($user['email'] ?? '') ?>" placeholder="email@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone / Mobile</label>
                        <input class="form-control" name="phone" value="<?= h($user['phone'] ?? '') ?>" placeholder="09xxxxxxxxx">
                    </div>

                    <?php if ($user['role'] === 'student'): ?>

                    <!-- Student Extended Profile -->
                    <?php if ($studentProfileColumns['nationality']): ?>
                    <div class="col-md-6">
                        <label class="form-label">Nationality</label>
                        <input name="nationality" class="form-control" value="<?= h((string) ($user['nationality'] ?? '')) ?>" placeholder="e.g. Filipino">
                    </div>
                    <?php endif; ?>
                    <?php if ($studentProfileColumns['religion']): ?>
                    <div class="col-md-6">
                        <label class="form-label">Religion</label>
                        <input name="religion" class="form-control" value="<?= h((string) ($user['religion'] ?? '')) ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($studentProfileColumns['lrn']): ?>
                    <div class="col-md-6">
                        <label class="form-label">LRN</label>
                        <input name="lrn" class="form-control" value="<?= h((string) ($user['lrn'] ?? '')) ?>" placeholder="12-digit LRN">
                    </div>
                    <?php endif; ?>
                    <?php if ($studentProfileColumns['stay_with']): ?>
                    <div class="col-md-6">
                        <label class="form-label">Stay With</label>
                        <input name="stay_with" class="form-control" value="<?= h((string) ($user['stay_with'] ?? '')) ?>" placeholder="e.g. Mother">
                    </div>
                    <?php endif; ?>
                    <?php if ($studentProfileColumns['facebook_link']): ?>
                    <div class="col-md-12">
                        <label class="form-label">Facebook Link</label>
                        <input name="facebook_link" class="form-control" value="<?= h((string) ($user['facebook_link'] ?? '')) ?>" placeholder="https://facebook.com/...">
                    </div>
                    <?php endif; ?>

                    <!-- Address -->
                    <?php if ($studentProfileColumns['full_address']): ?>
                    <div class="col-12">
                        <div class="edit-section-label"><i class="bi bi-geo-alt"></i> Address</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Full Address</label>
                        <input name="full_address" class="form-control" value="<?= h((string) ($user['full_address'] ?? '')) ?>" placeholder="House No., Street, Subdivision">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Region</label>
                        <input name="region" class="form-control" value="<?= h((string) ($user['region'] ?? '')) ?>" <?= $studentProfileColumns['region'] ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Province</label>
                        <input name="province" class="form-control" value="<?= h((string) ($user['province'] ?? '')) ?>" <?= $studentProfileColumns['province'] ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">City / Municipality</label>
                        <input name="city" class="form-control" value="<?= h((string) ($user['city'] ?? '')) ?>" <?= $studentProfileColumns['city'] ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Barangay</label>
                        <input name="barangay" class="form-control" value="<?= h((string) ($user['barangay'] ?? '')) ?>" <?= $studentProfileColumns['barangay'] ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Zipcode</label>
                        <input name="zipcode" class="form-control" value="<?= h((string) ($user['zipcode'] ?? '')) ?>" <?= $studentProfileColumns['zipcode'] ? '' : 'disabled' ?>>
                    </div>
                    <?php endif; ?>

                    <!-- Academic / Assignment -->
                    <div class="col-12">
                        <div class="edit-section-label"><i class="bi bi-mortarboard"></i> Academic &amp; Assignment</div>
                    </div>
                    <?php if ($studentProfileColumns['is_transferee']): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_transferee" id="is_transferee_edit" value="1" <?= !empty($user['is_transferee']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="is_transferee_edit">Transferee Student</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <label class="form-label">Assigned Parent</label>
                        <select name="parent_user_id" class="form-select">
                            <option value="">— No parent assigned —</option>
                            <?php foreach ($parents as $parent): ?>
                                <option value="<?= h((string) $parent['id']) ?>" <?= selected((string) ($user['parent_user_id'] ?? ''), (string) $parent['id']) ?>>
                                    <?= h($parent['last_name'] . ', ' . $parent['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Assigned Teacher</label>
                        <select name="teacher_user_id" class="form-select" <?= $hasTeacherAssignmentColumn ? '' : 'disabled' ?>>
                            <option value="">— No teacher assigned —</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= h((string) $teacher['id']) ?>" <?= selected((string) ($user['teacher_user_id'] ?? ''), (string) $teacher['id']) ?>>
                                    <?= h($teacher['last_name'] . ', ' . $teacher['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php endif; /* end student-only fields */ ?>

                    <div class="col-12 d-flex justify-content-end border-top pt-3 mt-1">
                        <button class="btn btn-primary px-4">
                            <i class="bi bi-floppy me-1"></i>Save Information
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Credentials + RFID -->
    <div class="col-lg-5 d-flex flex-column gap-3">

        <?php if ($user['role'] !== 'student'): ?>
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-key-fill text-warning"></i> Login Credentials
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="id" value="<?= h((string) $userId) ?>">
                    <input type="hidden" name="action" value="update_credentials">
                    <div class="col-12">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                            <input class="form-control" name="username" value="<?= h($user['username']) ?>" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">New Password <span class="text-muted fw-normal">(leave blank to keep current)</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" id="cred_password" class="form-control" name="password" placeholder="New password">
                            <button type="button" class="btn btn-outline-secondary" data-password-toggle="#cred_password" title="Show/hide">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-outline-primary px-4">
                            <i class="bi bi-floppy me-1"></i>Save Credentials
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-shield-lock text-info"></i> Student Access
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start gap-2 p-2 rounded" style="background:#f0f9ff;border:1px solid #bae6fd;">
                    <i class="bi bi-info-circle-fill text-info mt-1 flex-shrink-0"></i>
                    <span class="small text-secondary">Students do not log in to the system. Login credentials are not used for student accounts.</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-broadcast-pin text-primary"></i> RFID Card
            </div>
            <div class="card-body">
                <?php if (!empty($user['rfid_uid'])): ?>
                <div class="rfid-badge">
                    <div class="rfid-icon"><i class="bi bi-credit-card-2-front-fill"></i></div>
                    <div>
                        <div class="small text-muted mb-1">Assigned UID</div>
                        <code class="fs-6 fw-bold text-dark"><?= h($user['rfid_uid']) ?></code>
                    </div>
                    <span class="ms-auto badge bg-success-subtle text-success rounded-pill small">Active</span>
                </div>
                <?php else: ?>
                <div class="rfid-badge unassigned">
                    <div class="rfid-icon"><i class="bi bi-credit-card"></i></div>
                    <div>
                        <div class="small text-muted">No card assigned</div>
                        <a class="small" href="<?= h(appUrl('rfid.php')) ?>">Go to RFID Manager →</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const input = document.querySelector(btn.getAttribute('data-password-toggle') || '');
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        const icon = btn.querySelector('i');
        if (icon) icon.className = show ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
});
</script>

<?php renderFooter(); ?>
