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
$hasLrnColumn = $studentProfileColumns['lrn'];
$importErrorReportRows = $_SESSION['student_import_errors'] ?? [];

function normalizeBirthDate(?string $value): ?string
{
    $clean = trim((string) $value);
    if ($clean === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $clean);
    return $dt && $dt->format('Y-m-d') === $clean ? $clean : null;
}

function parseBoolWord(?string $value): int
{
    $v = strtolower(trim((string) $value));
    return in_array($v, ['1', 'yes', 'y', 'true', 'transferee'], true) ? 1 : 0;
}

function csvCell($value): string
{
    return trim((string) $value);
}

/**
 * Force Excel to treat a cell as plain text.
 * A leading tab tells Excel "this is text", preventing auto-conversion
 * of numbers to scientific notation and dates to serial numbers.
 */
function csvText($value): string
{
    $v = trim((string) $value);
    return $v !== '' ? "\t" . $v : '';
}

/**
 * Format a MySQL datetime/date string in a way Excel will not auto-convert.
 * Output: "29/03/2026 14:30:45"  (unambiguous text — column width safe)
 */
function csvDatetime($value): string
{
    $v = trim((string) $value);
    if ($v === '') {
        return '';
    }
    $ts = strtotime($v);
    return $ts ? date('d/m/Y H:i:s', $ts) : $v;
}

/** Format a date-only value so Excel won't collapse it to a serial number. */
function csvDate($value): string
{
    $v = trim((string) $value);
    if ($v === '') {
        return '';
    }
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}

function generateRoleUsername(PDO $pdo, int $tenantId, string $role): string
{
    $prefix = preg_replace('/[^a-z]/', '', strtolower($role)) ?: 'user';
    do {
        $candidate = $prefix . '_' . date('ymd') . '_' . bin2hex(random_bytes(3));
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND username = ?");
        $check->execute([$tenantId, $candidate]);
    } while ((int) $check->fetchColumn() > 0);
    return $candidate;
}

if (isset($_GET['download_student_template'])) {
    requireVendorSpreadsheet();
    xlsxExport(
        'student_import_template',
        ['first_name','middle_name','last_name','gender','birth_date','nationality',
         'full_address','region','province','city','barangay','zipcode',
         'contact_number','facebook_link','religion','lrn','stay_with','transferee',
         'course','grade_level','section','parent_username','teacher_username','email'],
        [['Juan','Dela','Cruz','male','2010-01-15','Filipino',
          'Blk 1 Lot 2','NCR','Metro Manila','Quezon City','Bagumbayan','1100',
          '09171234567','https://facebook.com/juan','Catholic','123456789012',
          'Mother','no','Junior High School','Grade 7','A','parent1','teacher1','juan@example.com']],
        /* textCols */ [11, 12, 15],  // zipcode, contact_number, lrn
        /* dateCols */ [4]            // birth_date
    );
}

if (isset($_GET['download_import_errors'])) {
    $errRows = $_SESSION['student_import_errors'] ?? [];
    if (!is_array($errRows) || $errRows === []) {
        flash('error', 'No import error report available.');
        redirect(appUrl('users.php'));
    }
    $data = [];
    foreach ($errRows as $r) {
        $data[] = [
            $r['row'] ?? '',
            $r['first_name'] ?? '',
            $r['last_name'] ?? '',
            $r['lrn'] ?? '',
            $r['reason'] ?? '',
        ];
    }
    unset($_SESSION['student_import_errors']);
    requireVendorSpreadsheet();
    xlsxExport('student_import_errors', ['Row','First Name','Last Name','LRN','Reason'], $data, [3]);
}

if (isset($_GET['export_students'])) {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.lrn,
            TRIM(CONCAT(u.last_name, ', ', u.first_name,
                CASE WHEN u.middle_name IS NOT NULL AND u.middle_name <> ''
                     THEN CONCAT(' ', u.middle_name) ELSE '' END)) AS full_name,
            u.email,
            u.phone,
            rc.uid AS rfid_uid,
            u.status,
            u.created_at
        FROM users u
        LEFT JOIN rfid_cards rc ON rc.user_id = u.id AND rc.tenant_id = u.tenant_id
        WHERE u.tenant_id = ? AND u.role = 'student'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$tenantId]);
    $exportRows = [];
    foreach ($stmt->fetchAll() as $row) {
        $exportRows[] = [
            $row['id'],
            csvCell($row['lrn'] ?? ''),
            csvCell($row['full_name'] ?? ''),
            csvCell($row['email'] ?? ''),
            csvCell($row['phone'] ?? ''),
            csvCell($row['rfid_uid'] ?? ''),
            csvCell($row['status'] ?? ''),
            csvCell($row['created_at'] ?? ''),
        ];
    }
    requireVendorSpreadsheet();
    xlsxExport(
        'students_export',
        ['ID', 'LRN', 'Name', 'Email', 'Cellphone #', 'RFID', 'Status', 'Created At'],
        $exportRows,
        /* textCols: LRN=1, Cellphone=4, RFID=5 */ [1, 4, 5]
    );
}

if (isset($_GET['export_view'])) {
    $exportView = strtolower(trim((string) $_GET['export_view']));
    if (!in_array($exportView, ['parent', 'teacher', 'employee'], true)) {
        flash('error', 'Invalid export view.');
        redirect(appUrl('users.php'));
    }
    $stmt = $pdo->prepare("
        SELECT id, first_name, middle_name, last_name, gender, email, phone, username, status, created_at
        FROM users
        WHERE tenant_id = ? AND role = ?
        ORDER BY last_name, first_name
    ");
    $stmt->execute([$tenantId, $exportView]);
    $exportRoleRows = [];
    foreach ($stmt->fetchAll() as $row) {
        $exportRoleRows[] = [
            $row['id'] ?? '',
            csvCell($row['first_name'] ?? ''),
            csvCell($row['middle_name'] ?? ''),
            csvCell($row['last_name'] ?? ''),
            csvCell($row['gender'] ?? ''),
            csvCell($row['email'] ?? ''),
            csvCell($row['phone'] ?? ''),
            csvCell($row['username'] ?? ''),
            csvCell($row['status'] ?? ''),
            csvCell($row['created_at'] ?? ''),
        ];
    }
    requireVendorSpreadsheet();
    xlsxExport(
        $exportView . '_export',
        ['ID','First Name','Middle Name','Last Name','Gender','Email','Phone','Username','Status','Created At'],
        $exportRoleRows,
        /* textCols: Phone=6 */ [6]
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postUsersView = strtolower(trim((string) ($_POST['users_view'] ?? 'student')));
    if (!in_array($postUsersView, ['student', 'parent', 'teacher', 'employee', 'all'], true)) {
        $postUsersView = 'student';
    }
    $postReturnPanel = strtolower(trim((string) ($_POST['return_panel'] ?? '')));
    $allowedPanels = ['student_registration', 'import_students', 'assign_parent', 'assign_teacher', 'create_role', 'import_role'];
    if (!in_array($postReturnPanel, $allowedPanels, true)) {
        $postReturnPanel = '';
    }

    if ($action === 'create') {
        $role = strtolower(trim((string) ($_POST['role'] ?? 'student')));
        if (!in_array($role, ['student', 'parent', 'teacher', 'employee'], true)) {
            $role = 'student';
        }
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = trim((string) ($_POST['middle_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $gender = strtolower(trim((string) ($_POST['gender'] ?? '')));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $courseId = !empty($_POST['course_id']) ? (int) $_POST['course_id'] : null;
        $gradeId = !empty($_POST['grade_level_id']) ? (int) $_POST['grade_level_id'] : null;
        $sectionId = !empty($_POST['section_id']) ? (int) $_POST['section_id'] : null;
        $parentId = !empty($_POST['parent_user_id']) ? (int) $_POST['parent_user_id'] : null;
        $teacherId = (!empty($_POST['teacher_user_id']) && $hasTeacherAssignmentColumn) ? (int) $_POST['teacher_user_id'] : null;
        $birthDate = normalizeBirthDate((string) ($_POST['birth_date'] ?? ''));
        $nationality = trim((string) ($_POST['nationality'] ?? ''));
        $fullAddress = trim((string) ($_POST['full_address'] ?? ''));
        $region = trim((string) ($_POST['region'] ?? ''));
        $province = trim((string) ($_POST['province'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $barangay = trim((string) ($_POST['barangay'] ?? ''));
        $zipcode = trim((string) ($_POST['zipcode'] ?? ''));
        $facebookLink = trim((string) ($_POST['facebook_link'] ?? ''));
        $religion = trim((string) ($_POST['religion'] ?? ''));
        $lrn = trim((string) ($_POST['lrn'] ?? ''));
        $stayWith = trim((string) ($_POST['stay_with'] ?? ''));
        $isTransfereeRaw = (string) ($_POST['is_transferee'] ?? '');
        $isTransferee = $isTransfereeRaw === '1' ? 1 : 0;
        if ($role !== 'student') {
            $parentId = null;
            $teacherId = null;
        }
        $uploadError = null;
        $photoPath = $hasUserPhotoColumn ? saveUploadedUserPhoto('photo_file', $uploadError) : null;
        if ($uploadError) {
            flash('error', $uploadError);
        }
        if (!$hasUserPhotoColumn && isset($_FILES['photo_file']) && (int) ($_FILES['photo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            flash('error', 'Photo upload is disabled until database is updated. Run sql/saas_upgrade.sql.');
        }

        $requiredErrors = [];
        $addRequiredError = static function (bool $condition, string $label) use (&$requiredErrors): void {
            if ($condition) {
                $requiredErrors[] = $label;
            }
        };
        /* Only truly essential fields are required */
        $addRequiredError($firstName === '', 'first name');
        $addRequiredError($lastName === '', 'last name');
        $addRequiredError($gender === '', 'gender');
        /* Email format check only when provided */
        $addRequiredError($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL), 'valid email format');

        if ($role === 'student') {
            $addRequiredError($gradeId === null, 'grade level');
            $addRequiredError($sectionId === null, 'section');
        } else {
            $addRequiredError($phone === '', 'contact number');
            $addRequiredError($email === '', 'email');
            $addRequiredError($password === '', 'password');
            $addRequiredError($confirmPassword === '', 'confirm password');
            $addRequiredError($password !== '' && $confirmPassword !== '' && $password !== $confirmPassword, 'matching passwords');
        }

        if ($requiredErrors !== []) {
            flash('error', 'Please complete all required fields: ' . implode(', ', array_unique($requiredErrors)) . '.');
            $redirectParams = ['users_view' => $postUsersView];
            if ($postReturnPanel !== '') {
                $redirectParams['panel'] = $postReturnPanel;
            }
            redirect(appUrl('users.php', $redirectParams));
        }

        $passwordHash = null;
        if ($role === 'student') {
            $username = generateRoleUsername($pdo, $tenantId, 'student');
            $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        } elseif ($password !== '') {
            if ($username === '') {
                $username = generateRoleUsername($pdo, $tenantId, $role);
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        if (!tenantCanAddUsers($pdo)) {
            flash('error', 'Plan limit reached: cannot add more users for this tenant.');
        } elseif ($firstName !== '' && $lastName !== '' && $username !== '' && $passwordHash !== null) {
            try {
                $insert = [
                    'tenant_id' => $tenantId,
                    'role' => $role,
                    'first_name' => $firstName,
                    'middle_name' => $middleName !== '' ? $middleName : null,
                    'last_name' => $lastName,
                    'gender' => $gender,
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'course_id' => $courseId,
                    'grade_level_id' => $gradeId,
                    'section_id' => $sectionId,
                    'parent_user_id' => $parentId,
                ];
                if ($hasUserPhotoColumn) {
                    $insert['photo_path'] = $photoPath;
                }
                if ($hasTeacherAssignmentColumn) {
                    $insert['teacher_user_id'] = $teacherId;
                }
                if ($hasStudentProfileSupport) {
                    if ($hasLrnColumn && $lrn !== '') {
                        $lrnStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'student' AND lrn = ?");
                        $lrnStmt->execute([$tenantId, $lrn]);
                        if ((int) $lrnStmt->fetchColumn() > 0) {
                            throw new RuntimeException('Duplicate LRN');
                        }
                    }
                    $insert['birth_date'] = $birthDate;
                    $insert['nationality'] = $nationality !== '' ? $nationality : null;
                    $insert['full_address'] = $fullAddress !== '' ? $fullAddress : null;
                    $insert['region'] = $region !== '' ? $region : null;
                    $insert['province'] = $province !== '' ? $province : null;
                    $insert['city'] = $city !== '' ? $city : null;
                    $insert['barangay'] = $barangay !== '' ? $barangay : null;
                    $insert['zipcode'] = $zipcode !== '' ? $zipcode : null;
                    $insert['facebook_link'] = $facebookLink !== '' ? $facebookLink : null;
                    $insert['religion'] = $religion !== '' ? $religion : null;
                    if ($role === 'student') {
                        $insert['lrn'] = $lrn !== '' ? $lrn : null;
                        $insert['stay_with'] = $stayWith !== '' ? $stayWith : null;
                        $insert['is_transferee'] = $isTransferee;
                    }
                }
                $columns = array_keys($insert);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($insert));
                flash('success', 'User created successfully.');
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException && $e->getMessage() === 'Duplicate LRN') {
                    flash('error', 'LRN already exists for this tenant. Please use a unique LRN.');
                } elseif ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                    flash('error', 'Username already exists for this tenant. Please use a different username.');
                } else {
                    flash('error', 'Unable to create user right now. Please try again.');
                }
            }
        }
        if ($role === 'student' && !$hasStudentProfileSupport) {
            flash('error', 'Some student profile fields are disabled until DB is updated. Run sql/saas_upgrade.sql.');
        }
    }

    if ($action === 'delete_all_students') {
        try {
            $delStmt = $pdo->prepare("DELETE FROM users WHERE tenant_id = ? AND role = 'student'");
            $delStmt->execute([$tenantId]);
            $deleted = $delStmt->rowCount();
            flash('success', 'All students deleted (' . $deleted . ' record' . ($deleted !== 1 ? 's' : '') . ' removed).');
        } catch (Throwable $e) {
            flash('error', 'Failed to delete all students: ' . $e->getMessage());
        }
        redirect(appUrl('users.php', ['users_view' => 'student']));
    }

    if ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('error', 'Invalid user selected for deletion.');
        } else {
            try {
                $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
                $targetStmt->execute([$userId, $tenantId]);
                $target = $targetStmt->fetch();
                if (!$target) {
                    flash('error', 'User not found.');
                } elseif ($userId === (int) ($_SESSION['user']['id'] ?? 0)) {
                    flash('error', 'You cannot delete your own account.');
                } elseif ((string) ($target['role'] ?? '') === 'admin') {
                    flash('error', 'Admin accounts cannot be deleted from this page.');
                } else {
                    $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND tenant_id = ?');
                    $deleteStmt->execute([$userId, $tenantId]);
                    flash('success', 'User deleted successfully.');
                }
            } catch (Throwable $e) {
                flash('error', 'Unable to delete this user. It may have related records.');
            }
        }
    }

    if ($action === 'import_students') {
        if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file']) || (int) ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid CSV file for import.');
            redirect(appUrl('users.php', ['users_view' => $postUsersView, 'panel' => $postReturnPanel ?: 'import_students']));
        }
        $tmpName = (string) ($_FILES['import_file']['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpName)) {
            flash('error', 'Invalid uploaded import file.');
            redirect(appUrl('users.php', ['users_view' => $postUsersView, 'panel' => $postReturnPanel ?: 'import_students']));
        }
        $courseMap = [];
        $courseStmt = $pdo->prepare("SELECT id, code, name FROM courses WHERE tenant_id = ?");
        $courseStmt->execute([$tenantId]);
        foreach ($courseStmt->fetchAll() as $c) {
            $courseMap[strtolower((string) ($c['name'] ?? ''))] = (int) $c['id'];
            $courseMap[strtolower((string) ($c['code'] ?? ''))] = (int) $c['id'];
        }
        $gradeMap = [];
        $gradeStmt = $pdo->prepare("SELECT id, name FROM grade_levels WHERE tenant_id = ?");
        $gradeStmt->execute([$tenantId]);
        foreach ($gradeStmt->fetchAll() as $g) {
            $gradeMap[strtolower((string) ($g['name'] ?? ''))] = (int) $g['id'];
        }
        $sectionMap = [];
        $sectionStmt = $pdo->prepare("SELECT id, name FROM sections WHERE tenant_id = ?");
        $sectionStmt->execute([$tenantId]);
        foreach ($sectionStmt->fetchAll() as $s) {
            $sectionMap[strtolower((string) ($s['name'] ?? ''))] = (int) $s['id'];
        }
        $parentMap = [];
        $parentStmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'parent' AND tenant_id = ?");
        $parentStmt->execute([$tenantId]);
        foreach ($parentStmt->fetchAll() as $p) {
            $k = strtolower((string) ($p['username'] ?? ''));
            if ($k !== '') {
                $parentMap[$k] = (int) $p['id'];
            }
        }
        $teacherMap = [];
        $teacherStmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'teacher' AND tenant_id = ?");
        $teacherStmt->execute([$tenantId]);
        foreach ($teacherStmt->fetchAll() as $t) {
            $k = strtolower((string) ($t['username'] ?? ''));
            if ($k !== '') {
                $teacherMap[$k] = (int) $t['id'];
            }
        }

        // Support both .xlsx and .csv imports
        $allImportRows = xlsxImportRows($tmpName);
        $rowNo    = 0;
        $inserted = 0;
        $failed   = 0;
        $errors   = [];
        $errorRows = [];
        $existingLrns = [];
        if ($hasLrnColumn) {
            $existingLrnStmt = $pdo->prepare("SELECT lrn FROM users WHERE tenant_id = ? AND role = 'student' AND lrn IS NOT NULL AND lrn <> ''");
            $existingLrnStmt->execute([$tenantId]);
            foreach ($existingLrnStmt->fetchAll() as $lrnRow) {
                $existingLrns[strtolower(trim((string) ($lrnRow['lrn'] ?? '')))] = true;
            }
        }
        $importLrns = [];

        // Build header→column-index map from the first row so both the
        // import template format AND the export format are accepted.
        $hdrRow = $allImportRows[0] ?? [];
        $colMap = [];
        foreach ($hdrRow as $ci => $hv) {
            $colMap[strtolower(trim((string) $hv))] = $ci;
        }
        // Export format has a combined "name" column; template format has "first_name"
        $isExportFmt = isset($colMap['name']) && !isset($colMap['first_name']);

        foreach ($allImportRows as $row) {
            $rowNo++;
            if ($rowNo === 1) {
                continue; // always skip the header row
            }

            if ($isExportFmt) {
                // ── Export format: ID | LRN | Name | Email | Cellphone # | RFID | Status | Created At ──
                $nameRaw = trim((string) ($row[$colMap['name'] ?? 2] ?? ''));
                // Parse "LASTNAME, FIRSTNAME [MIDDLENAME]"
                if (str_contains($nameRaw, ', ')) {
                    [$lastName, $firstMiddle] = explode(', ', $nameRaw, 2);
                    $lastName    = trim($lastName);
                    $firstMiddle = trim($firstMiddle);
                    $spacePos    = strpos($firstMiddle, ' ');
                    if ($spacePos !== false) {
                        $firstName  = substr($firstMiddle, 0, $spacePos);
                        $middleName = trim(substr($firstMiddle, $spacePos + 1));
                    } else {
                        $firstName  = $firstMiddle;
                        $middleName = '';
                    }
                } else {
                    $firstName  = $nameRaw;
                    $middleName = '';
                    $lastName   = '';
                }
                $lrn   = trim((string) ($row[$colMap['lrn']        ?? 1] ?? ''));
                $email = trim((string) ($row[$colMap['email']       ?? 3] ?? ''));
                $phone = trim((string) ($row[$colMap['cellphone #'] ?? 4] ?? ''));
                // Fields absent in export format – use safe defaults
                $gender = 'other'; $birthDate = null; $nationality = ''; $fullAddress = '';
                $region = ''; $province = ''; $city = ''; $barangay = ''; $zipcode = '';
                $facebook = ''; $religion = ''; $stayWith = ''; $isTransferee = 0;
                $courseId = null; $gradeId = null; $sectionId = null;
                $parentId = null; $teacherId = null;
            } else {
                // ── Template format (positional with header-name fallback) ──
                $firstName  = trim((string) ($row[$colMap['first_name']  ?? 0] ?? ''));
                $middleName = trim((string) ($row[$colMap['middle_name'] ?? 1] ?? ''));
                $lastName   = trim((string) ($row[$colMap['last_name']   ?? 2] ?? ''));
                $gender     = strtolower(trim((string) ($row[$colMap['gender']      ?? 3] ?? 'other')));
                if (!in_array($gender, ['male', 'female', 'other'], true)) {
                    $gender = 'other';
                }
                $birthDate   = normalizeBirthDate((string) ($row[$colMap['birth_date']    ?? 4]  ?? ''));
                $nationality = trim((string) ($row[$colMap['nationality']  ?? 5]  ?? ''));
                $fullAddress = trim((string) ($row[$colMap['full_address'] ?? 6]  ?? ''));
                $region      = trim((string) ($row[$colMap['region']       ?? 7]  ?? ''));
                $province    = trim((string) ($row[$colMap['province']     ?? 8]  ?? ''));
                $city        = trim((string) ($row[$colMap['city']         ?? 9]  ?? ''));
                $barangay    = trim((string) ($row[$colMap['barangay']     ?? 10] ?? ''));
                $zipcode     = trim((string) ($row[$colMap['zipcode']      ?? 11] ?? ''));
                // Template uses "contact_number" header; accept either
                $phoneCol    = $colMap['contact_number'] ?? $colMap['phone'] ?? 12;
                $phone       = trim((string) ($row[$phoneCol] ?? ''));
                $facebook    = trim((string) ($row[$colMap['facebook_link'] ?? 13] ?? ''));
                $religion    = trim((string) ($row[$colMap['religion']      ?? 14] ?? ''));
                $lrn         = trim((string) ($row[$colMap['lrn']           ?? 15] ?? ''));
                $stayWith    = trim((string) ($row[$colMap['stay_with']     ?? 16] ?? ''));
                $transfereeCol = $colMap['transferee'] ?? $colMap['is_transferee'] ?? 17;
                $isTransferee  = parseBoolWord((string) ($row[$transfereeCol] ?? ''));
                $courseId    = $courseMap[strtolower(trim((string) ($row[$colMap['course']            ?? 18] ?? '')))] ?? null;
                $gradeId     = $gradeMap[strtolower(trim((string) ($row[$colMap['grade_level']        ?? 19] ?? '')))] ?? null;
                $sectionId   = $sectionMap[strtolower(trim((string) ($row[$colMap['section']          ?? 20] ?? '')))] ?? null;
                $parentId    = $parentMap[strtolower(trim((string) ($row[$colMap['parent_username']   ?? 21] ?? '')))] ?? null;
                $teacherId   = $teacherMap[strtolower(trim((string) ($row[$colMap['teacher_username'] ?? 22] ?? '')))] ?? null;
                $email       = trim((string) ($row[$colMap['email'] ?? 23] ?? ''));
            }

            if ($firstName === '' || $lastName === '') {
                $failed++;
                $errors[] = 'Row ' . $rowNo . ': first_name and last_name are required.';
                $errorRows[] = ['row' => $rowNo, 'first_name' => $firstName, 'last_name' => $lastName, 'lrn' => '', 'reason' => 'Missing first_name or last_name'];
                continue;
            }
            if (!tenantCanAddUsers($pdo)) {
                $failed++;
                $errors[] = 'Import stopped: plan user limit reached.';
                break;
            }
            if ($hasLrnColumn && $lrn !== '') {
                $lrnKey = strtolower($lrn);
                if (isset($existingLrns[$lrnKey])) {
                    $failed++;
                    $errors[] = 'Row ' . $rowNo . ': duplicate LRN already exists.';
                    $errorRows[] = ['row' => $rowNo, 'first_name' => $firstName, 'last_name' => $lastName, 'lrn' => $lrn, 'reason' => 'Duplicate LRN in existing students'];
                    continue;
                }
                if (isset($importLrns[$lrnKey])) {
                    $failed++;
                    $errors[] = 'Row ' . $rowNo . ': duplicate LRN inside import file.';
                    $errorRows[] = ['row' => $rowNo, 'first_name' => $firstName, 'last_name' => $lastName, 'lrn' => $lrn, 'reason' => 'Duplicate LRN inside CSV'];
                    continue;
                }
            }

            try {
                $insert = [
                    'tenant_id' => $tenantId,
                    'role' => 'student',
                    'first_name' => $firstName,
                    'middle_name' => $middleName !== '' ? $middleName : null,
                    'last_name' => $lastName,
                    'gender' => $gender,
                    'username' => generateRoleUsername($pdo, $tenantId, 'student'),
                    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'course_id' => $courseId,
                    'grade_level_id' => $gradeId,
                    'section_id' => $sectionId,
                    'parent_user_id' => $parentId,
                ];
                if ($hasTeacherAssignmentColumn) {
                    $insert['teacher_user_id'] = $teacherId;
                }
                if ($hasStudentProfileSupport) {
                    $insert['birth_date'] = $birthDate;
                    $insert['nationality'] = $nationality !== '' ? $nationality : null;
                    $insert['full_address'] = $fullAddress !== '' ? $fullAddress : null;
                    $insert['region'] = $region !== '' ? $region : null;
                    $insert['province'] = $province !== '' ? $province : null;
                    $insert['city'] = $city !== '' ? $city : null;
                    $insert['barangay'] = $barangay !== '' ? $barangay : null;
                    $insert['zipcode'] = $zipcode !== '' ? $zipcode : null;
                    $insert['facebook_link'] = $facebook !== '' ? $facebook : null;
                    $insert['religion'] = $religion !== '' ? $religion : null;
                    $insert['lrn'] = $lrn !== '' ? $lrn : null;
                    $insert['stay_with'] = $stayWith !== '' ? $stayWith : null;
                    $insert['is_transferee'] = $isTransferee;
                }
                $columns = array_keys($insert);
                $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($insert));
                $inserted++;
                if ($hasLrnColumn && $lrn !== '') {
                    $existingLrns[strtolower($lrn)] = true;
                    $importLrns[strtolower($lrn)] = true;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Row ' . $rowNo . ': failed to import.';
                $errorRows[] = ['row' => $rowNo, 'first_name' => $firstName, 'last_name' => $lastName, 'lrn' => $lrn, 'reason' => 'Failed to import row'];
            }
        }
        $msg = 'Student import done. Added: ' . $inserted . '. Failed: ' . $failed . '.';
        flash('success', $msg);
        if ($errorRows) {
            $_SESSION['student_import_errors'] = $errorRows;
        } else {
            unset($_SESSION['student_import_errors']);
        }
        if ($errors) {
            flash('error', implode(' ', array_slice($errors, 0, 4)));
        }
        if (!$hasStudentProfileSupport) {
            flash('error', 'Imported with basic fields only. Run sql/saas_upgrade.sql to enable all student profile columns.');
        }
    }

    if ($action === 'import_role_users') {
        $importRole = strtolower(trim((string) ($_POST['import_role'] ?? '')));
        if (!in_array($importRole, ['parent', 'teacher', 'employee'], true)) {
            flash('error', 'Invalid import role.');
            redirect(appUrl('users.php', ['users_view' => $postUsersView, 'panel' => $postReturnPanel ?: 'import_role']));
        }
        if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file']) || (int) ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid CSV file for import.');
            redirect(appUrl('users.php', ['users_view' => $importRole, 'panel' => 'import_role']));
        }
        $tmpName = (string) ($_FILES['import_file']['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpName)) {
            flash('error', 'Invalid uploaded import file.');
            redirect(appUrl('users.php', ['users_view' => $importRole, 'panel' => 'import_role']));
        }
        $allRoleRows = xlsxImportRows($tmpName);
        $rowNo    = 0;
        $inserted = 0;
        $failed   = 0;
        $errors   = [];
        foreach ($allRoleRows as $row) {
            $rowNo++;
            if ($rowNo === 1 && isset($row[0]) && strtolower(trim((string) $row[0])) === 'first_name') {
                continue;
            }
            if (!tenantCanAddUsers($pdo)) {
                $errors[] = 'Import stopped: plan user limit reached.';
                $failed++;
                break;
            }
            $firstName = trim((string) ($row[0] ?? ''));
            $middleName = trim((string) ($row[1] ?? ''));
            $lastName = trim((string) ($row[2] ?? ''));
            $gender = strtolower(trim((string) ($row[3] ?? 'other')));
            $email = trim((string) ($row[4] ?? ''));
            $phone = trim((string) ($row[5] ?? ''));
            $username = trim((string) ($row[6] ?? ''));
            $password = trim((string) ($row[7] ?? ''));
            if (!in_array($gender, ['male', 'female', 'other'], true)) {
                $gender = 'other';
            }
            if ($firstName === '' || $lastName === '') {
                $failed++;
                $errors[] = 'Row ' . $rowNo . ': first_name and last_name are required.';
                continue;
            }
            if ($username === '') {
                $username = generateRoleUsername($pdo, $tenantId, $importRole);
            }
            if ($password === '') {
                $password = bin2hex(random_bytes(6));
            }
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        tenant_id, role, first_name, middle_name, last_name, gender, email, phone, username, password_hash
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $tenantId,
                    $importRole,
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $gender,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
                $inserted++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Row ' . $rowNo . ': failed to import.';
            }
        }
        flash('success', ucfirst($importRole) . ' import done. Added: ' . $inserted . '. Failed: ' . $failed . '.');
        if ($errors) {
            flash('error', implode(' ', array_slice($errors, 0, 4)));
        }
        redirect(appUrl('users.php', ['users_view' => $importRole]));
    }

    if ($action === 'assign_parent') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $parentId = !empty($_POST['parent_user_id']) ? (int) $_POST['parent_user_id'] : null;
        if ($studentId > 0) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET parent_user_id = ?
                WHERE id = ? AND role = 'student' AND tenant_id = ?
            ");
            $stmt->execute([$parentId, $studentId, $tenantId]);
            flash('success', 'Parent assignment updated.');
        }
    }

    if ($action === 'assign_teacher') {
        if (!$hasTeacherAssignmentColumn) {
            flash('error', 'Teacher assignment is disabled until database is updated. Run sql/saas_upgrade.sql.');
        } else {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $teacherId = !empty($_POST['teacher_user_id']) ? (int) $_POST['teacher_user_id'] : null;
            if ($studentId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET teacher_user_id = ?
                    WHERE id = ? AND role = 'student' AND tenant_id = ?
                ");
                $stmt->execute([$teacherId, $studentId, $tenantId]);
                flash('success', 'Teacher assignment updated.');
            }
        }
    }

    $redirectParams = ['users_view' => $postUsersView];
    if ($postReturnPanel !== '') {
        $redirectParams['panel'] = $postReturnPanel;
    }
    redirect(appUrl('users.php', $redirectParams));
}

if (isset($_GET['reset_filters'])) {
    unset($_SESSION['users_filters']);
    redirect(appUrl('users.php'));
}

$savedFilters = $_SESSION['users_filters'] ?? [];
$usersView = array_key_exists('users_view', $_GET) ? strtolower(trim((string) $_GET['users_view'])) : (string) ($savedFilters['users_view'] ?? 'student');
if (!in_array($usersView, ['student', 'parent', 'teacher', 'employee', 'all'], true)) {
    $usersView = 'student';
}
$usersPanel = array_key_exists('panel', $_GET) ? strtolower(trim((string) $_GET['panel'])) : '';
$allowedPanels = ['student_registration', 'import_students', 'assign_parent', 'assign_teacher', 'create_role', 'import_role'];
if (!in_array($usersPanel, $allowedPanels, true)) {
    $usersPanel = '';
}
$roleFilter = array_key_exists('role', $_GET) ? trim((string) $_GET['role']) : (string) ($savedFilters['role'] ?? '');
if ($usersView !== 'all') {
    $roleFilter = $usersView;
}
$searchQuery = array_key_exists('q', $_GET) ? trim((string) $_GET['q']) : (string) ($savedFilters['q'] ?? '');
$perPage = array_key_exists('per_page', $_GET) ? (int) $_GET['per_page'] : (int) ($savedFilters['per_page'] ?? 10);
$sortBy = array_key_exists('sort_by', $_GET) ? trim((string) $_GET['sort_by']) : (string) ($savedFilters['sort_by'] ?? 'created');
$sortDir = strtolower(array_key_exists('sort_dir', $_GET) ? trim((string) $_GET['sort_dir']) : (string) ($savedFilters['sort_dir'] ?? 'desc'));
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$_SESSION['users_filters'] = [
    'users_view' => $usersView,
    'role' => $roleFilter,
    'q' => $searchQuery,
    'per_page' => $perPage,
    'sort_by' => $sortBy,
    'sort_dir' => $sortDir,
];
$viewLabelMap = [
    'student' => 'Students',
    'parent' => 'Parents',
    'teacher' => 'Teachers',
    'employee' => 'Employees',
    'all' => 'All Users',
];
$currentViewLabel = $viewLabelMap[$usersView] ?? 'Users';

$sortMap = [
    'name' => 'u.last_name',
    'role' => 'u.role',
    'status' => 'u.status',
    'created' => 'u.created_at',
];
if (!isset($sortMap[$sortBy])) {
    $sortBy = 'created';
}
$orderSql = $sortMap[$sortBy] . ' ' . strtoupper($sortDir);

$whereSql = 'WHERE u.tenant_id = ?';
$params = [$tenantId];
if ($roleFilter !== '') {
    $whereSql .= ' AND u.role = ?';
    $params[] = $roleFilter;
}
if ($searchQuery !== '') {
    $whereSql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$countStmt->execute($params);
$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$teacherSelectSql = $hasTeacherAssignmentColumn
    ? "t.first_name AS teacher_first, t.last_name AS teacher_last"
    : "NULL AS teacher_first, NULL AS teacher_last";
$teacherJoinSql = $hasTeacherAssignmentColumn
    ? "LEFT JOIN users t ON t.id = u.teacher_user_id AND t.tenant_id = u.tenant_id"
    : "";

$usersStmt = $pdo->prepare("
    SELECT
        u.*,
        p.first_name AS parent_first,
        p.last_name AS parent_last,
        $teacherSelectSql,
        rc.uid AS rfid_uid,
        c.name AS course_name,
        g.name AS grade_name,
        s.name AS section_name
    FROM users u
    LEFT JOIN users p ON p.id = u.parent_user_id AND p.tenant_id = u.tenant_id
    $teacherJoinSql
    LEFT JOIN rfid_cards rc ON rc.user_id = u.id AND rc.tenant_id = u.tenant_id
    LEFT JOIN courses c ON c.id = u.course_id AND c.tenant_id = u.tenant_id
    LEFT JOIN grade_levels g ON g.id = u.grade_level_id AND g.tenant_id = u.tenant_id
    LEFT JOIN sections s ON s.id = u.section_id AND s.tenant_id = u.tenant_id
    $whereSql
    ORDER BY $orderSql
    LIMIT $perPage OFFSET $offset
");
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE role = 'parent' AND tenant_id = ? ORDER BY last_name");
$stmt->execute([$tenantId]);
$parents = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' AND tenant_id = ? ORDER BY last_name");
$stmt->execute([$tenantId]);
$teachers = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT id, first_name, last_name, parent_user_id FROM users WHERE role = 'student' AND tenant_id = ? ORDER BY last_name");
$stmt->execute([$tenantId]);
$students = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT id, name FROM courses WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenantId]);
$courses = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT id, name FROM grade_levels WHERE tenant_id = ? ORDER BY id");
$stmt->execute([$tenantId]);
$grades = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT s.id, s.name, s.grade_level_id, g.name AS grade_name FROM sections s LEFT JOIN grade_levels g ON g.id = s.grade_level_id WHERE s.tenant_id = ? ORDER BY g.id, s.name");
$stmt->execute([$tenantId]);
$sections = $stmt->fetchAll();
$baseParams = ['users_view' => $usersView, 'panel' => $usersPanel, 'role' => $roleFilter, 'q' => $searchQuery, 'per_page' => $perPage, 'sort_by' => $sortBy, 'sort_dir' => $sortDir];

renderHeader($currentViewLabel);
?>

<style>
    .role-profile-form .form-control,
    .role-profile-form .form-select {
        background: var(--surface-2); border-color: var(--border);
        min-height: 42px; font-size: 0.9rem;
    }
    .role-profile-form .input-group-text {
        background: var(--surface-3); border-color: var(--border);
        color: var(--tx-muted); min-width: 42px; justify-content: center;
    }
    .role-profile-form .form-control:focus,
    .role-profile-form .form-select:focus {
        background: var(--surface); border-color: var(--ac-blue);
        box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
    }
    .role-profile-form .section-divider { display: none; }
    .role-profile-form .btn-toggle-password {
        min-width: 44px; border-color: var(--border);
        background: var(--surface-3); color: var(--tx-secondary);
    }
    .role-profile-form .btn-toggle-password:hover { background: var(--surface-2); }
    .profile-form .photo-preview {
        display: flex; align-items: center; gap: 10px; margin-top: 8px;
        padding: 8px 10px; border: 1px solid var(--border);
        border-radius: var(--r-md); background: var(--surface-2);
    }
    .profile-form .photo-preview img {
        width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border);
    }
    .profile-form .photo-preview span { font-size: 0.82rem; color: var(--tx-secondary); word-break: break-all; }
    #user-list .card-header { gap: 10px; }
    #user-list .table td, #user-list .table th { vertical-align: middle; }
    #user-list .col-action { min-width: 182px; width: 182px; }
    #user-list .btn-action-menu {
        background: var(--ac-sky); border-color: var(--ac-sky); color: #fff;
        font-size: 0.64rem; font-weight: 700; text-transform: uppercase; padding: 0.27rem 0.45rem;
    }
    #user-list .btn-action-menu:hover { background: #0284c7; border-color: #0284c7; color: #fff; }
    #user-list .btn-delete-user {
        background: var(--ac-rose); border-color: var(--ac-rose); color: #fff;
        font-size: 0.64rem; font-weight: 700; text-transform: uppercase; padding: 0.27rem 0.45rem;
    }
    #user-list .btn-delete-user:hover { background: #e11d48; border-color: #e11d48; color: #fff; }
    #user-list .users-pagination-bar .pager-icon-btn {
        height: 28px; min-width: 28px; padding: 0.15rem 0.35rem;
        border: 1px solid var(--border); background: var(--surface); color: var(--tx-muted);
        display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none;
    }
    #user-list .users-pagination-bar .pager-icon-btn:hover { background: var(--surface-2); color: var(--tx-primary); }
    #user-list .users-pagination-bar .pager-icon-btn.disabled { opacity: 0.5; pointer-events: none; }
    #user-list .toolbar-btn {
        background: var(--surface-3); border-color: var(--border); color: var(--tx-secondary);
        font-size: 0.75rem; font-weight: 600; padding: 0.35rem 0.7rem;
    }
    #user-list .toolbar-btn:hover { background: var(--surface-2); border-color: #cbd5e1; color: var(--tx-primary); }
    #user-list .search-compact .form-control,
    #user-list .search-compact .form-select,
    #user-list .search-compact .input-group-text { height: 30px; font-size: 0.78rem; }
</style>

<?php if (($usersView === 'student' || $usersView === 'all') && $usersPanel === 'student_registration'): ?>
    <div class="card mb-3" id="add-students">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-badge-fill text-primary"></i>
                <span>Create Student Account</span>
            </div>
            <a href="<?= h(appUrl('users.php', ['users_view' => 'student'])) ?>" class="btn btn-sm btn-light">
                <i class="bi bi-x me-1"></i>Cancel
            </a>
        </div>
        <div class="card-body">
            <?php if (!$hasStudentProfileSupport): ?>
                <div class="alert alert-warning d-flex gap-2 align-items-start">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                    <span>Extended profile fields are partially disabled. Run <code>sql/saas_upgrade.sql</code> in phpMyAdmin to enable them.</span>
                </div>
            <?php endif; ?>
            <form method="post" class="row g-3 role-profile-form profile-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="role" value="student">
                <input type="hidden" name="users_view" value="student">
                <input type="hidden" name="return_panel" value="student_registration">

                <!-- Personal Information -->
                <div class="col-12 section-label"><i class="bi bi-person-fill"></i> Personal Information</div>

                <div class="col-md-4">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input name="first_name" class="form-control" placeholder="e.g. Juan" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input name="middle_name" class="form-control" placeholder="e.g. Dela">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input name="last_name" class="form-control" placeholder="e.g. Cruz" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Birth Date</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar2-week"></i></span>
                        <input name="birth_date" type="date" class="form-control" <?= $studentProfileColumns['birth_date'] ? '' : 'disabled' ?>>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <div class="gender-wrap">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                            <select class="form-select gender-select" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other / Self-describe</option>
                            </select>
                        </div>
                        <input type="text" class="form-control gender-other mt-1 d-none" placeholder="Please specify your gender..." maxlength="60">
                        <input type="hidden" name="gender" class="gender-value" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nationality</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-flag-fill"></i></span>
                        <input name="nationality" class="form-control" placeholder="e.g. Filipino" <?= $studentProfileColumns['nationality'] ? '' : 'disabled' ?>>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="col-12 section-label"><i class="bi bi-envelope-fill"></i> Contact Information</div>

                <div class="col-md-4">
                    <label class="form-label">Mobile / Phone</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone-fill"></i></span>
                        <input name="phone" class="form-control" placeholder="09xxxxxxxxx">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input name="email" type="email" class="form-control" placeholder="student@example.com">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Facebook Link</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-facebook"></i></span>
                        <input name="facebook_link" class="form-control" placeholder="https://facebook.com/..." <?= $studentProfileColumns['facebook_link'] ? '' : 'disabled' ?>>
                    </div>
                </div>

                <!-- Address -->
                <div class="col-12 section-label"><i class="bi bi-geo-alt-fill"></i> Address</div>

                <div class="col-12">
                    <label class="form-label">House No., Street / Subdivision</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-house-door-fill"></i></span>
                        <input name="full_address" class="form-control" placeholder="e.g. Blk 1 Lot 2, Sampaguita St." <?= $studentProfileColumns['full_address'] ? '' : 'disabled' ?>>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Region</label>
                    <?php if ($studentProfileColumns['region']): ?>
                    <select name="region" id="phRegion" class="form-select ph-region-sel">
                        <option value="">— Select Region —</option>
                    </select>
                    <?php else: ?>
                    <input name="region" class="form-control" disabled placeholder="N/A">
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Province</label>
                    <?php if ($studentProfileColumns['province']): ?>
                    <select name="province" id="phProvince" class="form-select ph-province-sel" disabled>
                        <option value="">— Select Province —</option>
                    </select>
                    <?php else: ?>
                    <input name="province" class="form-control" disabled placeholder="N/A">
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">City / Municipality</label>
                    <?php if ($studentProfileColumns['city']): ?>
                    <select name="city" id="phCity" class="form-select ph-city-sel" disabled>
                        <option value="">— Select City —</option>
                    </select>
                    <?php else: ?>
                    <input name="city" class="form-control" disabled placeholder="N/A">
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Barangay</label>
                    <input name="barangay" class="form-control" placeholder="e.g. Bagumbayan" <?= $studentProfileColumns['barangay'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Zipcode</label>
                    <input name="zipcode" class="form-control" placeholder="e.g. 1110" <?= $studentProfileColumns['zipcode'] ? '' : 'disabled' ?>>
                </div>

                <!-- Student Details -->
                <div class="col-12 section-label"><i class="bi bi-card-list"></i> Student Details</div>

                <div class="col-md-3">
                    <label class="form-label">LRN</label>
                    <input name="lrn" class="form-control" placeholder="12-digit LRN" <?= $studentProfileColumns['lrn'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Religion</label>
                    <input name="religion" class="form-control" placeholder="e.g. Catholic" <?= $studentProfileColumns['religion'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Stay With</label>
                    <input name="stay_with" class="form-control" placeholder="e.g. Mother" <?= $studentProfileColumns['stay_with'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Transferee</label>
                    <select name="is_transferee" class="form-select" <?= $studentProfileColumns['is_transferee'] ? '' : 'disabled' ?>>
                        <option value="0">No</option>
                        <option value="1">Yes — Transferee</option>
                    </select>
                </div>

                <!-- Academic / Assignment -->
                <div class="col-12 section-label"><i class="bi bi-mortarboard-fill"></i> Academic &amp; Assignment</div>

                <div class="col-md-4">
                    <label class="form-label">Course / Program <span class="text-muted fw-normal small">(optional)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-journal-bookmark-fill"></i></span>
                        <select name="course_id" class="form-select">
                            <option value="">— None / Not applicable —</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= h((string) $course['id']) ?>"><?= h($course['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-text">Leave blank for Kinder / no program.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-mortarboard"></i></span>
                        <select name="grade_level_id" id="regGradeLevel" class="form-select" required>
                            <option value="">Select grade level</option>
                            <?php foreach ($grades as $grade): ?>
                                <option value="<?= h((string) $grade['id']) ?>"><?= h($grade['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Section <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-grid-3x2-gap-fill"></i></span>
                        <select name="section_id" id="regSection" class="form-select" required>
                            <option value="">— Select grade first —</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= h((string) $section['id']) ?>" data-grade="<?= h((string) $section['grade_level_id']) ?>"><?= h($section['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Assigned Parent</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-hearts"></i></span>
                        <select name="parent_user_id" class="form-select">
                            <option value="">— None / Assign later —</option>
                            <?php foreach ($parents as $parent): ?>
                                <option value="<?= h((string) $parent['id']) ?>">
                                    <?= h($parent['last_name'] . ', ' . $parent['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Assigned Teacher</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-workspace"></i></span>
                        <select name="teacher_user_id" class="form-select" <?= $hasTeacherAssignmentColumn ? '' : 'disabled' ?>>
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= h((string) $teacher['id']) ?>">
                                    <?= h($teacher['last_name'] . ', ' . $teacher['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4 photo-picker">
                    <label class="form-label">Profile Photo <?= $hasUserPhotoColumn ? '<span class="text-danger">*</span>' : '' ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-image-fill"></i></span>
                        <input name="photo_file" type="file" class="form-control photo-input" accept="image/*" <?= $hasUserPhotoColumn ? '' : 'disabled' ?>>
                    </div>
                    <div class="photo-preview d-none mt-1">
                        <img src="<?= h(BASE_URL . '/assets/default-avatar.svg') ?>" alt="Photo preview">
                        <span>No photo selected</span>
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-end border-top pt-3 mt-1">
                    <button class="btn btn-primary px-4">
                        <i class="bi bi-person-plus-fill me-1"></i>Add Student
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (($usersView === 'student' || $usersView === 'all') && $usersPanel === 'import_students'): ?>
    <div class="card mb-3" id="import-export">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Bulk Import Students</span>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" href="<?= h(appUrl('users.php', ['download_student_template' => 1, 'users_view' => $usersView])) ?>">Download CSV Template</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h(appUrl('users.php', ['export_students' => 1, 'users_view' => $usersView])) ?>">Export Students CSV</a>
                <?php if (!empty($importErrorReportRows)): ?>
                    <a class="btn btn-sm btn-outline-danger" href="<?= h(appUrl('users.php', ['download_import_errors' => 1, 'users_view' => $usersView])) ?>">Download Import Errors</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_students">
                <input type="hidden" name="users_view" value="student">
                <input type="hidden" name="return_panel" value="import_students">
                <div class="col-md-9">
                    <label class="form-label">Excel / CSV File <span class="badge bg-success ms-1">XLSX</span></label>
                    <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    <div class="form-text">Upload an <strong>.xlsx</strong> or .csv file. Use the template headers exactly. One row = one student.</div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-success w-100">Import Students</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (in_array($usersView, ['parent', 'teacher', 'employee'], true) && $usersPanel === 'create_role'): ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-vcard-fill text-primary"></i>
                <span>Create <?= h(rtrim($currentViewLabel, 's')) ?> Account</span>
            </div>
            <a href="<?= h(appUrl('users.php', ['users_view' => $usersView])) ?>" class="btn btn-sm btn-light">
                <i class="bi bi-x me-1"></i>Cancel
            </a>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3 role-profile-form profile-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="role" value="<?= h($usersView) ?>">
                <input type="hidden" name="users_view" value="<?= h($usersView) ?>">
                <input type="hidden" name="return_panel" value="create_role">

                <!-- Personal Information -->
                <div class="col-12 section-label"><i class="bi bi-person-fill"></i> Personal Information</div>

                <div class="col-md-4">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input name="first_name" class="form-control" placeholder="e.g. Maria" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input name="middle_name" class="form-control" placeholder="e.g. Santos">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input name="last_name" class="form-control" placeholder="e.g. Reyes" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Birth Date</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar2-week"></i></span>
                        <input name="birth_date" type="date" class="form-control" <?= $studentProfileColumns['birth_date'] ? '' : 'disabled' ?>>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <div class="gender-wrap">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                            <select class="form-select gender-select" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other / Self-describe</option>
                            </select>
                        </div>
                        <input type="text" class="form-control gender-other mt-1 d-none" placeholder="Please specify your gender..." maxlength="60">
                        <input type="hidden" name="gender" class="gender-value" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nationality</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-flag-fill"></i></span>
                        <input name="nationality" class="form-control" placeholder="e.g. Filipino" <?= $studentProfileColumns['nationality'] ? '' : 'disabled' ?>>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="col-12 section-label"><i class="bi bi-envelope-fill"></i> Contact Information</div>

                <div class="col-md-4">
                    <label class="form-label">Mobile / Phone <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone-fill"></i></span>
                        <input name="phone" class="form-control" placeholder="09xxxxxxxxx" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input name="email" type="email" class="form-control" placeholder="staff@example.com" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Facebook Link</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-facebook"></i></span>
                        <input name="facebook_link" class="form-control" placeholder="https://facebook.com/..." <?= $studentProfileColumns['facebook_link'] ? '' : 'disabled' ?>>
                    </div>
                </div>

                <!-- Address -->
                <div class="col-12 section-label"><i class="bi bi-geo-alt-fill"></i> Address</div>

                <div class="col-md-9">
                    <label class="form-label">Full Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-house-door-fill"></i></span>
                        <input name="full_address" class="form-control" placeholder="House No., Street, Subdivision" <?= $studentProfileColumns['full_address'] ? '' : 'disabled' ?>>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Region</label>
                    <input name="region" class="form-control" placeholder="e.g. NCR" <?= $studentProfileColumns['region'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Province</label>
                    <input name="province" class="form-control" placeholder="e.g. Metro Manila" <?= $studentProfileColumns['province'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label">City / Municipality</label>
                    <input name="city" class="form-control" placeholder="e.g. Quezon City" <?= $studentProfileColumns['city'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Barangay</label>
                    <input name="barangay" class="form-control" placeholder="e.g. Bagumbayan" <?= $studentProfileColumns['barangay'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Zipcode</label>
                    <input name="zipcode" class="form-control" placeholder="e.g. 1110" <?= $studentProfileColumns['zipcode'] ? '' : 'disabled' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Religion</label>
                    <input name="religion" class="form-control" placeholder="e.g. Catholic" <?= $studentProfileColumns['religion'] ? '' : 'disabled' ?>>
                </div>

                <!-- Photo & Login -->
                <div class="col-12 section-label"><i class="bi bi-shield-lock-fill"></i> Photo &amp; Login Credentials</div>

                <div class="col-md-6 photo-picker">
                    <label class="form-label">Profile Photo</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-image-fill"></i></span>
                        <input name="photo_file" type="file" class="form-control photo-input" accept="image/*" <?= $hasUserPhotoColumn ? '' : 'disabled' ?>>
                    </div>
                    <div class="photo-preview d-none mt-1">
                        <img src="<?= h(BASE_URL . '/assets/default-avatar.svg') ?>" alt="Photo preview">
                        <span>No photo selected</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input id="rolePasswordField" name="password" type="password" class="form-control" placeholder="Password" required>
                        <button class="btn btn-toggle-password" type="button" data-password-toggle="#rolePasswordField" aria-label="Toggle password">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input id="roleConfirmPasswordField" name="confirm_password" type="password" class="form-control" placeholder="Confirm" required>
                        <button class="btn btn-toggle-password" type="button" data-password-toggle="#roleConfirmPasswordField" aria-label="Toggle confirm password">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-end border-top pt-3 mt-1">
                    <button class="btn btn-primary px-4 btn-register">
                        <i class="bi bi-person-plus-fill me-1"></i>Register <?= h(rtrim($currentViewLabel, 's')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (($usersView === 'student' || $usersView === 'all') && $usersPanel === 'assign_parent'): ?>
    <div class="card mb-3" id="parents">
        <div class="card-header">Parent Assign to Student</div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="assign_parent">
                <input type="hidden" name="users_view" value="student">
                <input type="hidden" name="return_panel" value="assign_parent">
                <div class="col-md-5">
                    <select name="student_id" class="form-select" required>
                        <option value="">Select student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= h((string) $student['id']) ?>">
                                <?= h($student['last_name'] . ', ' . $student['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <select name="parent_user_id" class="form-select">
                        <option value="">No parent</option>
                        <?php foreach ($parents as $parent): ?>
                            <option value="<?= h((string) $parent['id']) ?>">
                                <?= h($parent['last_name'] . ', ' . $parent['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 text-end">
                    <button class="btn btn-outline-primary w-100">Assign</button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php if (($usersView === 'student' || $usersView === 'all') && $usersPanel === 'assign_teacher'): ?>
    <div class="card mb-3" id="teachers">
        <div class="card-header">Teacher Assign to Student</div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="assign_teacher">
                <input type="hidden" name="users_view" value="student">
                <input type="hidden" name="return_panel" value="assign_teacher">
                <div class="col-md-5">
                    <select name="student_id" class="form-select" required>
                        <option value="">Select student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= h((string) $student['id']) ?>">
                                <?= h($student['last_name'] . ', ' . $student['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <select name="teacher_user_id" class="form-select" <?= $hasTeacherAssignmentColumn ? '' : 'disabled data-lock-disabled="1"' ?>>
                        <option value="">No teacher</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= h((string) $teacher['id']) ?>">
                                <?= h($teacher['last_name'] . ', ' . $teacher['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 text-end">
                    <button class="btn btn-outline-primary w-100" <?= $hasTeacherAssignmentColumn ? '' : 'disabled' ?>>Assign</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (in_array($usersView, ['parent', 'teacher', 'employee'], true) && $usersPanel === 'import_role'): ?>
    <div class="card mb-3">
        <div class="card-header">Import <?= h($currentViewLabel) ?></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_role_users">
                <input type="hidden" name="import_role" value="<?= h($usersView) ?>">
                <input type="hidden" name="users_view" value="<?= h($usersView) ?>">
                <input type="hidden" name="return_panel" value="import_role">
                <div class="col-md-9">
                    <label class="form-label">Excel / CSV File <span class="badge bg-success ms-1">XLSX</span></label>
                    <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    <div class="form-text">Upload <strong>.xlsx</strong> or .csv — columns: first_name, middle_name, last_name, gender, email, phone, username, password</div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-success w-100">Import <?= h($currentViewLabel) ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card" id="user-list">
    <div class="card-header d-flex align-items-center gap-3 flex-wrap sticky-filters">
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <span><?= h($currentViewLabel) ?> List</span>
            <span class="badge-soft"><?= h((string) $totalUsers) ?> records</span>
        </div>
        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
            <?php if ($usersView === 'student' || $usersView === 'all'): ?>
                <a class="btn btn-sm toolbar-btn" href="<?= h(appUrl('users.php', ['users_view' => 'student', 'panel' => 'import_students'])) ?>"><i class="bi bi-cloud-upload me-1"></i>Import Students</a>
                <a class="btn btn-sm toolbar-btn" href="<?= h(appUrl('users.php', ['users_view' => 'student', 'export_students' => 1])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Export Students (.xlsx)</a>
            <?php elseif (in_array($usersView, ['parent', 'teacher', 'employee'], true)): ?>
                <a class="btn btn-sm toolbar-btn" href="<?= h(appUrl('users.php', ['users_view' => $usersView, 'panel' => 'import_role'])) ?>"><i class="bi bi-cloud-upload me-1"></i>Import <?= h($currentViewLabel) ?></a>
                <a class="btn btn-sm toolbar-btn" href="<?= h(appUrl('users.php', ['users_view' => $usersView, 'export_view' => $usersView])) ?>"><i class="bi bi-cloud-download me-1"></i>Export <?= h($currentViewLabel) ?></a>
            <?php endif; ?>
            <form method="get" class="d-flex gap-0 search-compact mb-0">
                <input type="hidden" name="users_view" value="<?= h($usersView) ?>">
                <input type="hidden" name="panel" value="<?= h($usersPanel) ?>">
                <input type="hidden" name="per_page" value="<?= h((string) $perPage) ?>">
                <input type="hidden" name="sort_by" value="<?= h($sortBy) ?>">
                <input type="hidden" name="sort_dir" value="<?= h($sortDir) ?>">
                <?php if ($usersView === 'all'): ?>
                    <select name="role" class="form-select form-select-sm rounded-end-0 border-end-0" style="width:110px;">
                        <option value="">All roles</option>
                        <?php foreach (['student', 'teacher', 'employee', 'parent', 'admin'] as $role): ?>
                            <option value="<?= h($role) ?>" <?= selected($roleFilter, $role) ?>><?= h(roleLabel($role)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="role" value="<?= h($roleFilter) ?>">
                <?php endif; ?>
                <div class="input-group input-group-sm" style="width:220px;">
                    <span class="input-group-text <?= $usersView === 'all' ? 'rounded-0' : '' ?>"><i class="bi bi-search"></i></span>
                    <input name="q" value="<?= h($searchQuery) ?>" class="form-control form-control-sm" placeholder="Search <?= h(strtolower($currentViewLabel)) ?>...">
                </div>
            </form>
            <?php if ($usersView === 'student' || $usersView === 'all'): ?>
                <a class="btn btn-sm btn-primary flex-shrink-0" href="<?= h(appUrl('users.php', ['users_view' => 'student', 'panel' => 'student_registration'])) ?>"><i class="bi bi-person-plus me-1"></i>Add Student</a>
                <?php if ($usersView === 'student' && $totalUsers > 0): ?>
                <button type="button" class="btn btn-sm btn-danger flex-shrink-0" data-bs-toggle="modal" data-bs-target="#deleteAllModal">
                    <i class="bi bi-trash3-fill me-1"></i>Delete All
                </button>
                <?php endif; ?>
            <?php elseif (in_array($usersView, ['parent', 'teacher', 'employee'], true)): ?>
                <a class="btn btn-sm btn-primary flex-shrink-0" href="<?= h(appUrl('users.php', ['users_view' => $usersView, 'panel' => 'create_role'])) ?>"><i class="bi bi-person-plus me-1"></i>Add <?= h(rtrim($currentViewLabel, 's')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 users-table" id="userTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Photo</th>
                    <th>
                        <?php $dir = ($sortBy === 'name' && $sortDir === 'asc') ? 'desc' : 'asc'; ?>
                        <a class="sort-link" href="<?= h(appUrl('users.php', $baseParams + ['sort_by' => 'name', 'sort_dir' => $dir])) ?>">
                            Name <span class="sort-indicator"><?= $sortBy === 'name' ? h(strtoupper($sortDir) === 'ASC' ? '▲' : '▼') : '↕' ?></span>
                        </a>
                    </th>
                    <?php if ($usersView === 'student' || $usersView === 'all'): ?>
                        <th>LRN</th>
                        <th>Email</th>
                        <th>Cellphone #</th>
                        <th>RFID</th>
                    <?php elseif (in_array($usersView, ['parent', 'teacher', 'employee'], true)): ?>
                        <th>Email</th>
                        <th>Cellphone #</th>
                        <?php if ($usersView === 'employee'): ?>
                            <th>Role</th>
                        <?php endif; ?>
                    <?php endif; ?>
                    <th class="col-action">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= h((string) $user['id']) ?></td>
                        <td><img src="<?= h(userPhotoUrl(($hasUserPhotoColumn ? ($user['photo_path'] ?? null) : null)) ) ?>" alt="User photo" style="width:36px;height:36px;object-fit:cover;border-radius:50%;border:1px solid #dfe5f3;"></td>
                        <td><?= h($user['last_name'] . ', ' . $user['first_name']) ?></td>
                        <?php if ($usersView === 'student' || $usersView === 'all'): ?>
                            <td><?= h(($user['role'] === 'student' && $studentProfileColumns['lrn']) ? (string) ($user['lrn'] ?? '-') : '-') ?></td>
                            <td><?= h((string) ($user['email'] ?? '-')) ?></td>
                            <td><?= h((string) ($user['phone'] ?? '-')) ?></td>
                            <td><?= h((string) ($user['rfid_uid'] ?? '-')) ?></td>
                        <?php elseif (in_array($usersView, ['parent', 'teacher', 'employee'], true)): ?>
                            <td><?= h((string) ($user['email'] ?? '-')) ?></td>
                            <td><?= h((string) ($user['phone'] ?? '-')) ?></td>
                            <?php if ($usersView === 'employee'): ?>
                                <td><span class="badge text-bg-primary"><?= h(roleLabel((string) $user['role'])) ?></span></td>
                            <?php endif; ?>
                        <?php endif; ?>
                        <td class="col-action">
                            <div class="d-flex gap-1">
                                <a class="btn btn-sm btn-action-menu" href="<?= h(appUrl('user_edit.php', ['id' => $user['id']])) ?>">
                                    <i class="bi bi-list-ul me-1"></i>Action Menu
                                </a>
                                <form method="post" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="users_view" value="<?= h($usersView) ?>">
                                    <input type="hidden" name="return_panel" value="<?= h($usersPanel) ?>">
                                    <input type="hidden" name="user_id" value="<?= h((string) $user['id']) ?>">
                                    <button class="btn btn-sm btn-delete-user" type="submit">
                                        <i class="bi bi-trash3-fill me-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <?php
                    $colspan = 6;
                    if ($usersView === 'student' || $usersView === 'all') {
                        $colspan = 8;
                    } elseif ($usersView === 'employee') {
                        $colspan = 7;
                    }
                    ?>
                    <tr><td colspan="<?= h((string) $colspan) ?>" class="text-center text-muted">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    $startRecord = $totalUsers > 0 ? (($page - 1) * $perPage) + 1 : 0;
    $endRecord = $totalUsers > 0 ? min($totalUsers, $startRecord + count($users) - 1) : 0;
    $prevPage = max(1, $page - 1);
    $nextPage = min($totalPages, $page + 1);
    ?>
    <div class="users-pagination-bar">
        <form method="get" class="page-size-form">
            <input type="hidden" name="users_view" value="<?= h($usersView) ?>">
            <input type="hidden" name="panel" value="<?= h($usersPanel) ?>">
            <input type="hidden" name="role" value="<?= h($roleFilter) ?>">
            <input type="hidden" name="q" value="<?= h($searchQuery) ?>">
            <input type="hidden" name="sort_by" value="<?= h($sortBy) ?>">
            <input type="hidden" name="sort_dir" value="<?= h($sortDir) ?>">
            <span>Records per page:</span>
            <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ([10, 25, 50, 100] as $size): ?>
                    <option value="<?= h((string) $size) ?>" <?= selected($perPage, $size) ?>><?= h((string) $size) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <span><?= h((string) $startRecord) ?>-<?= h((string) $endRecord) ?> of <?= h((string) $totalUsers) ?></span>
        <a class="pager-icon-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(appUrl('users.php', $baseParams + ['page' => 1])) ?>" aria-label="First page"><i class="bi bi-chevron-double-left"></i></a>
        <a class="pager-icon-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(appUrl('users.php', $baseParams + ['page' => $prevPage])) ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
        <a class="pager-icon-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= h(appUrl('users.php', $baseParams + ['page' => $nextPage])) ?>" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
        <a class="pager-icon-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= h(appUrl('users.php', $baseParams + ['page' => $totalPages])) ?>" aria-label="Last page"><i class="bi bi-chevron-double-right"></i></a>
    </div>
</div>

<script>
/* ── Gender "Other / Self-describe" widget ────────────────────────── */
document.querySelectorAll('.gender-wrap').forEach((wrap) => {
    const sel    = wrap.querySelector('.gender-select');
    const other  = wrap.querySelector('.gender-other');
    const hidden = wrap.querySelector('.gender-value');
    if (!sel || !other || !hidden) return;

    function sync() {
        if (sel.value === 'other') {
            other.classList.remove('d-none');
            other.setAttribute('required', '');
            hidden.value = other.value.trim();
        } else {
            other.classList.add('d-none');
            other.removeAttribute('required');
            hidden.value = sel.value;
        }
    }

    sel.addEventListener('change', () => { sync(); if (sel.value === 'other') other.focus(); });
    other.addEventListener('input', () => { hidden.value = other.value.trim(); });

    /* Restore state on page load (e.g. validation re-render) */
    sync();
});

/* ── Password toggle ─────────────────────────────────────────────── */
document.querySelectorAll('[data-password-toggle]').forEach((toggleButton) => {
    toggleButton.addEventListener('click', () => {
        const selector = toggleButton.getAttribute('data-password-toggle') || '';
        const input = document.querySelector(selector);
        if (!input) {
            return;
        }
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        const icon = toggleButton.querySelector('i');
        if (icon) {
            icon.className = show ? 'bi bi-eye' : 'bi bi-eye-slash';
        }
    });
});

document.querySelectorAll('.photo-picker .photo-input').forEach((input) => {
    input.addEventListener('change', () => {
        const wrapper = input.closest('.photo-picker');
        const preview = wrapper ? wrapper.querySelector('.photo-preview') : null;
        const previewImg = preview ? preview.querySelector('img') : null;
        const previewText = preview ? preview.querySelector('span') : null;
        if (!preview || !previewImg || !previewText) {
            return;
        }
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) {
            preview.classList.add('d-none');
            previewText.textContent = 'No photo selected';
            previewImg.src = '<?= h(BASE_URL . '/assets/default-avatar.svg') ?>';
            return;
        }
        preview.classList.remove('d-none');
        previewText.textContent = file.name;
        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = typeof e.target?.result === 'string'
                ? e.target.result
                : '<?= h(BASE_URL . '/assets/default-avatar.svg') ?>';
        };
        reader.readAsDataURL(file);
    });
});

document.querySelectorAll('form.profile-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
            form.classList.add('was-validated');
            return;
        }
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalLabel = submitButton.textContent || 'Save';
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving...';
        }
    });
});

/* ── Grade Level → Section cascade ──────────────────────────────── */
(function () {
    const gradeEl   = document.getElementById('regGradeLevel');
    const sectionEl = document.getElementById('regSection');
    if (!gradeEl || !sectionEl) return;

    const allOptions = Array.from(sectionEl.options).filter(o => o.value !== '');

    function filterSections() {
        const gid = gradeEl.value;
        sectionEl.innerHTML = '';
        const placeholder = new Option(gid ? '— Select section —' : '— Select grade first —', '');
        sectionEl.appendChild(placeholder);
        if (gid) {
            allOptions.forEach(o => {
                if (o.dataset.grade === gid) {
                    sectionEl.appendChild(o.cloneNode(true));
                }
            });
        }
    }

    gradeEl.addEventListener('change', filterSections);
    filterSections();
})();

/* ── Philippine Address cascading dropdowns ─────────────────────── */
(function () {
    const PH = [
        {r:'NCR',n:'NCR – National Capital Region',p:[
            {p:'Metro Manila',c:['Caloocan','Las Piñas','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Parañaque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela']}
        ]},
        {r:'CAR',n:'CAR – Cordillera Administrative Region',p:[
            {p:'Abra',c:['Bangued','Boliney','Bucay','Bucloc','Daguioman','Danglas','Dolores','La Paz','Lacub','Lagangilang','Lagayan','Langiden','Licuan-Baay','Luba','Malibcong','Manabo','Peñarrubia','Pidigan','Pilar','Sallapadan','San Isidro','San Juan','San Quintin','Tayum','Tineg','Tubo','Villaviciosa']},
            {p:'Apayao',c:['Calanasan','Conner','Flora','Kabugao','Luna','Pudtol','Santa Marcela']},
            {p:'Benguet',c:['Atok','Baguio City','Bakun','Bokod','Buguias','Itogon','Kabayan','Kapangan','Kibungan','La Trinidad','Mankayan','Sablan','Tuba','Tublay']},
            {p:'Ifugao',c:['Alfonso Lista','Aguinaldo','Asipulo','Banaue','Hingyon','Hungduan','Kiangan','Lagawe','Lamut','Mayoyao','Tinoc']},
            {p:'Kalinga',c:['Balbalan','Lubuagan','Pasil','Pinukpuk','Rizal','Tabuk City','Tanudan','Tinglayan']},
            {p:'Mountain Province',c:['Barlig','Bauko','Besao','Bontoc','Natonin','Paracelis','Sabangan','Sadanga','Sagada','Tadian']}
        ]},
        {r:'I',n:'Region I – Ilocos Region',p:[
            {p:'Ilocos Norte',c:['Adams','Bacarra','Badoc','Bangui','Banna','Batac City','Burgos','Carasi','Currimao','Dingras','Dumalneg','Laoag City','Marcos','Nueva Era','Pagudpud','Paoay','Pasuquin','Piddig','Pinili','San Nicolas','Sarrat','Solsona','Vintar']},
            {p:'Ilocos Sur',c:['Alilem','Banayoyo','Bantay','Burgos','Cabugao','Candon City','Caoayan','Cervantes','Galimuyod','Gregorio del Pilar','Lidlidda','Magsingal','Nagbukel','Narvacan','Quirino','Salcedo','San Emilio','San Esteban','San Ildefonso','San Juan','San Vicente','Santa','Santa Catalina','Santa Cruz','Santa Lucia','Santa Maria','Santiago','Santo Domingo','Sigay','Sinait','Sugpon','Suyo','Tagudin','Vigan City']},
            {p:'La Union',c:['Agoo','Aringay','Bacnotan','Bagulin','Balaoan','Bangar','Bauang','Burgos','Caba','Luna','Naguilian','Pugo','Rosario','San Fernando City','San Gabriel','San Juan','Santo Tomas','Santol','Sudipen','Tubao']},
            {p:'Pangasinan',c:['Agno','Aguilar','Alaminos City','Alcala','Anda','Asingan','Balungao','Bani','Basista','Bautista','Bayambang','Binalonan','Binmaley','Bugallon','Burgos','Calasiao','Dagupan City','Dasol','Infanta','Labrador','Laoac','Lingayen','Mabini','Malasiqui','Manaoag','Mangaldan','Mangatarem','Mapandan','Natividad','Pozzorubio','Rosales','San Carlos City','San Fabian','San Jacinto','San Manuel','San Nicolas','San Quintin','Santa Barbara','Santa Maria','Santo Tomas','Sison','Sual','Tayug','Umingan','Urbiztondo','Urdaneta City','Villasis']}
        ]},
        {r:'II',n:'Region II – Cagayan Valley',p:[
            {p:'Batanes',c:['Basco','Itbayat','Ivana','Mahatao','Sabtang','Uyugan']},
            {p:'Cagayan',c:['Abulug','Alcala','Allacapan','Amulung','Aparri','Baggao','Ballesteros','Buguey','Calayan','Camalaniugan','Claveria','Enrile','Gattaran','Gonzaga','Iguig','Lal-lo','Lasam','Pamplona','Peñablanca','Piat','Rizal','Sanchez-Mira','Santa Ana','Santa Praxedes','Santa Teresita','Santo Niño','Solana','Tuao','Tuguegarao City']},
            {p:'Isabela',c:['Alicia','Angadanan','Aurora','Benito Soliven','Burgos','Cabagan','Cabatuan','Cauayan City','Cordon','Delfin Albano','Dinapigue','Divilacan','Echague','Gamu','Ilagan City','Jones','Luna','Maconacon','Mallig','Naguilian','Palanan','Quezon','Quirino','Ramon','Reina Mercedes','Roxas','San Agustin','San Guillermo','San Isidro','San Manuel','San Mariano','San Mateo','San Pablo','Santa Maria','Santiago City','Santo Tomas','Tumauini']},
            {p:'Nueva Vizcaya',c:['Alfonso Castañeda','Ambaguio','Aritao','Bagabag','Bambang','Bayombong','Diadi','Dupax del Norte','Dupax del Sur','Kasibu','Kayapa','Quezon','Santa Fe','Solano','Villaverde']},
            {p:'Quirino',c:['Aglipay','Cabarroguis','Diffun','Maddela','Nagtipunan','Saguday']}
        ]},
        {r:'III',n:'Region III – Central Luzon',p:[
            {p:'Aurora',c:['Baler','Casiguran','Dilasag','Dinalungan','Dingalan','Dipaculao','Maria Aurora','San Luis']},
            {p:'Bataan',c:['Abucay','Bagac','Balanga City','Dinalupihan','Hermosa','Limay','Mariveles','Morong','Orani','Orion','Pilar','Samal']},
            {p:'Bulacan',c:['Angat','Balagtas','Baliuag','Bocaue','Bulacan','Bustos','Calumpit','Doña Remedios Trinidad','Guiguinto','Hagonoy','Malolos City','Marilao','Meycauayan City','Norzagaray','Obando','Pandi','Paombong','Plaridel','Pulilan','San Ildefonso','San Jose del Monte City','San Miguel','San Rafael','Santa Maria']},
            {p:'Nueva Ecija',c:['Aliaga','Bongabon','Cabanatuan City','Cabiao','Carranglan','Cuyapo','Gabaldon','Gapan City','General Mamerto Natividad','General Tinio','Guimba','Jaen','Laur','Licab','Llanera','Lupao','Muñoz City','Nampicuan','Palayan City','Pantabangan','Peñaranda','Quezon','Rizal','San Antonio','San Isidro','San Jose City','San Leonardo','Santa Rosa','Santo Domingo','Talavera','Talugtug','Zaragoza']},
            {p:'Pampanga',c:['Angeles City','Apalit','Arayat','Bacolor','Candaba','Floridablanca','Guagua','Lubao','Mabalacat City','Macabebe','Magalang','Masantol','Mexico','Minalin','Porac','San Fernando City','San Luis','San Simon','Santa Ana','Santa Rita','Santo Tomas','Sasmuan']},
            {p:'Tarlac',c:['Anao','Bamban','Camiling','Capas','Concepcion','Gerona','La Paz','Mayantoc','Moncada','Paniqui','Pura','Ramos','San Clemente','San Jose','San Manuel','Santa Ignacia','Tarlac City','Victoria']},
            {p:'Zambales',c:['Botolan','Cabangan','Candelaria','Castillejos','Iba','Masinloc','Olongapo City','Palauig','San Antonio','San Felipe','San Marcelino','San Narciso','Santa Cruz','Subic']}
        ]},
        {r:'IVA',n:'Region IV-A – CALABARZON',p:[
            {p:'Batangas',c:['Agoncillo','Alitagtag','Balayan','Balete','Batangas City','Bauan','Calaca','Calatagan','Cuenca','Ibaan','Laurel','Lemery','Lian','Lipa City','Lobo','Mabini','Malvar','Mataas na Kahoy','Nasugbu','Padre Garcia','Rosario','San Jose','San Juan','San Luis','San Nicolas','San Pascual','Santa Teresita','Santo Tomas','Taal','Talisay','Tanauan City','Taysan','Tingloy','Tuy']},
            {p:'Cavite',c:['Alfonso','Amadeo','Bacoor City','Carmona','Cavite City','Dasmariñas City','General Emilio Aguinaldo','General Mariano Alvarez','General Trias City','Imus City','Indang','Kawit','Magallanes','Maragondon','Mendez','Naic','Noveleta','Rosario','Silang','Tagaytay City','Tanza','Ternate','Trece Martires City']},
            {p:'Laguna',c:['Alaminos','Bay','Biñan City','Cabuyao City','Calauan','Cavinti','Famy','Kalayaan','Liliw','Los Baños','Luisiana','Lumban','Mabitac','Magdalena','Majayjay','Nagcarlan','Paete','Pagsanjan','Pakil','Pangil','Pila','Rizal','San Pablo City','San Pedro City','Santa Cruz','Santa Maria','Santa Rosa City','Siniloan','Victoria']},
            {p:'Quezon',c:['Agdangan','Alabat','Atimonan','Buenavista','Burdeos','Calauag','Candelaria','Catanauan','Dolores','General Luna','General Nakar','Guinayangan','Gumaca','Infanta','Jomalig','Lopez','Lucban','Lucena City','Macalelon','Mauban','Mulanay','Padre Burgos','Pagbilao','Panukulan','Patnanungan','Perez','Pitogo','Plaridel','Polillo','Quezon','Real','Sampaloc','San Andres','San Antonio','San Francisco','San Narciso','Sariaya','Tagkawayan','Tayabas City','Tiaong','Unisan']},
            {p:'Rizal',c:['Angono','Antipolo City','Baras','Binangonan','Cainta','Cardona','Jala-Jala','Morong','Pililla','Rodriguez','San Mateo','Tanay','Taytay','Teresa']}
        ]},
        {r:'IVB',n:'Region IV-B – MIMAROPA',p:[
            {p:'Marinduque',c:['Boac','Buenavista','Gasan','Mogpog','Santa Cruz','Torrijos']},
            {p:'Occidental Mindoro',c:['Abra de Ilog','Calintaan','Looc','Lubang','Magsaysay','Mamburao','Paluan','Rizal','Sablayan','San Jose','Santa Cruz']},
            {p:'Oriental Mindoro',c:['Baco','Bansud','Bongabong','Bulalacao','Calapan City','Gloria','Mansalay','Naujan','Pinamalayan','Pola','Puerto Galera','Roxas','San Teodoro','Socorro','Victoria']},
            {p:'Palawan',c:['Aborlan','Agutaya','Araceli','Balabac','Bataraza',"Brooke's Point",'Busuanga','Cagayancillo','Coron','Culion','Cuyo','Dumaran','El Nido','Kalayaan','Linapacan','Magsaysay','Narra','Puerto Princesa City','Quezon','Rizal','Roxas','San Vicente','Taytay']},
            {p:'Romblon',c:['Alcantara','Banton','Cajidiocan','Calatrava','Concepcion','Corcuera','Ferrol','Looc','Magdiwang','Odiongan','Romblon','San Agustin','San Andres','San Fernando','San Jose','Santa Fe','Santa Maria']}
        ]},
        {r:'V',n:'Region V – Bicol Region',p:[
            {p:'Albay',c:['Bacacay','Camalig','Daraga','Guinobatan','Jovellar','Legazpi City','Libon','Ligao City','Malilipot','Malinao','Manito','Oas','Pio Duran','Polangui','Rapu-Rapu','Santo Domingo','Tabaco City','Tiwi']},
            {p:'Camarines Norte',c:['Basud','Capalonga','Daet','Jose Panganiban','Labo','Mercedes','Paracale','San Lorenzo Ruiz','San Vicente','Santa Elena','Talisay','Vinzons']},
            {p:'Camarines Sur',c:['Baao','Balatan','Bato','Bombon','Buhi','Bula','Cabusao','Calabanga','Camaligan','Canaman','Caramoan','Del Gallego','Gainza','Garchitorena','Goa','Iriga City','Lagonoy','Libmanan','Lupi','Magarao','Milaor','Minalabac','Nabua','Naga City','Ocampo','Pamplona','Pasacao','Pili','Presentacion','Ragay','Sagñay','San Fernando','San Jose','Sipocot','Siruma','Tigaon','Tinambac']},
            {p:'Catanduanes',c:['Bagamanoc','Baras','Bato','Caramoran','Gigmoto','Pandan','Panganiban','San Andres','San Miguel','Viga','Virac']},
            {p:'Masbate',c:['Aroroy','Baleno','Balud','Batuan','Cataingan','Cawayan','Claveria','Dimasalang','Esperanza','Mandaon','Masbate City','Milagros','Mobo','Monreal','Palanas','Placer','San Fernando','San Jacinto','San Pascual','Uson']},
            {p:'Sorsogon',c:['Barcelona','Bulan','Bulusan','Casiguran','Castilla','Donsol','Gubat','Irosin','Juban','Magallanes','Matnog','Pilar','Prieto Diaz','Santa Magdalena','Sorsogon City']}
        ]},
        {r:'VI',n:'Region VI – Western Visayas',p:[
            {p:'Aklan',c:['Altavas','Balete','Banga','Batan','Buruanga','Ibajay','Kalibo','Lezo','Libacao','Madalag','Makato','Malay','Malinao','Nabas','New Washington','Numancia','Tangalan']},
            {p:'Antique',c:['Anini-y','Barbaza','Belison','Bugasong','Caluya','Culasi','Hamtic','Laua-an','Libertad','Pandan','Patnongon','San Jose de Buenavista','San Remigio','Sebaste','Sibalom','Tibiao','Tobias Fornier','Valderrama']},
            {p:'Capiz',c:['Cuartero','Dao','Dumalag','Dumarao','Ivisan','Jamindan','Ma-ayon','Mambusao','Panay','Panitan','Pilar','Pontevedra','President Roxas','Roxas City','Sapi-an','Sigma','Tapaz']},
            {p:'Guimaras',c:['Buenavista','Jordan','Nueva Valencia','San Lorenzo','Sibunag']},
            {p:'Iloilo',c:['Ajuy','Alimodian','Anilao','Badiangan','Balasan','Banate','Barotac Nuevo','Barotac Viejo','Batad','Bingawan','Cabatuan','Calinog','Carles','Concepcion','Dingle','Dueñas','Dumangas','Estancia','Guimbal','Igbaras','Iloilo City','Janiuay','Lambunao','Leganes','Lemery','Leon','Maasin','Miagao','Mina','New Lucena','Oton','Passi City','Pavia','Pototan','San Dionisio','San Enrique','San Joaquin','San Miguel','San Rafael','Santa Barbara','Sara','Tigbauan','Tubungan','Zarraga']},
            {p:'Negros Occidental',c:['Bacolod City','Bago City','Binalbagan','Cadiz City','Calatrava','Candoni','Cauayan','Enrique B. Magalona','Escalante City','Himamaylan City','Hinigaran','Hinoba-an','Ilog','Isabela','Kabankalan City','La Carlota City','La Castellana','Manapla','Moises Padilla','Murcia','Pontevedra','Pulupandan','Sagay City','San Carlos City','San Enrique','Silay City','Sipalay City','Talisay City','Toboso','Valladolid','Victorias City']}
        ]},
        {r:'VII',n:'Region VII – Central Visayas',p:[
            {p:'Bohol',c:['Alburquerque','Alicia','Anda','Antequera','Baclayon','Balilihan','Batuan','Bien Unido','Bilar','Buenavista','Calape','Candijay','Carmen','Catigbian','Clarin','Corella','Cortes','Dagohoy','Danao','Dauis','Dimiao','Duero','Garcia Hernandez','Getafe','Guindulman','Inabanga','Jagna','Lila','Loay','Loboc','Loon','Mabini','Maribojoc','Panglao','Pilar','Sagbayan','San Isidro','San Miguel','Sevilla','Sierra Bullones','Sikatuna','Tagbilaran City','Talibon','Trinidad','Tubigon','Ubay','Valencia']},
            {p:'Cebu',c:['Alcantara','Alcoy','Alegria','Aloguinsan','Argao','Asturias','Badian','Balamban','Bantayan','Barili','Bogo City','Boljoon','Borbon','Carcar City','Carmen','Catmon','Cebu City','Compostela','Consolacion','Cordova','Daanbantayan','Dalaguete','Danao City','Dumanjug','Ginatilan','Lapu-Lapu City','Liloan','Madridejos','Malabuyoc','Mandaue City','Medellin','Minglanilla','Moalboal','Naga City','Oslob','Pilar','Pinamungajan','Poro','Ronda','Samboan','San Fernando','San Francisco','San Remigio','Santa Fe','Santander','Sibonga','Sogod','Tabogon','Tabuelan','Talisay City','Toledo City','Tuburan','Tudela']},
            {p:'Negros Oriental',c:['Amlan','Ayungon','Bacong','Bais City','Basay','Bayawan City','Bindoy','Canlaon City','Dauin','Dumaguete City','Guihulngan City','Jimalalud','La Libertad','Mabinay','Manjuyod','Pamplona','San Jose','Santa Catalina','Siaton','Sibulan','Tanjay City','Tayasan','Valencia','Vallehermoso','Zamboanguita']},
            {p:'Siquijor',c:['Enrique Villanueva','Larena','Lazi','Maria','San Juan','Siquijor']}
        ]},
        {r:'VIII',n:'Region VIII – Eastern Visayas',p:[
            {p:'Biliran',c:['Almeria','Biliran','Cabucgayan','Caibiran','Culaba','Kawayan','Maripipi','Naval']},
            {p:'Eastern Samar',c:['Arteche','Balangiga','Balangkayan','Borongan City','Can-avid','Dolores','General MacArthur','Giporlos','Guiuan','Hernani','Jipapad','Lawaan','Llorente','Maslog','Maydolong','Mercedes','Oras','Quinapondan','Salcedo','San Julian','San Policarpo','Sulat','Taft']},
            {p:'Leyte',c:['Abuyog','Alangalang','Albuera','Babatngon','Barugo','Bato','Baybay City','Burauen','Calubian','Capoocan','Carigara','Dagami','Dulag','Hilongos','Hindang','Inopacan','Isabel','Jaro','Javier','Julita','Kananga','La Paz','Leyte','MacArthur','Mahaplag','Matag-ob','Matalom','Mayorga','Merida','Ormoc City','Palo','Palompon','Pastrana','San Isidro','San Miguel','Santa Fe','Tabango','Tabontabon','Tacloban City','Tanauan','Tolosa','Tunga','Villaba']},
            {p:'Northern Samar',c:['Allen','Biri','Bobon','Capul','Catarman','Catubig','Gamay','Lapinig','Las Navas','Lavezares','Lope de Vega','Mapanas','Mondragon','Pambujan','Rosario','San Antonio','San Isidro','San Jose','San Vicente','Silvino Lobos','Victoria']},
            {p:'Samar',c:['Almagro','Basey','Calbayog City','Calbiga','Catbalogan City','Daram','Gandara','Hinabangan','Jiabong','Marabut','Matuguinao','Motiong','Paranas','Pinabacdao','San Jorge','San Jose de Buan','San Sebastian','Santa Margarita','Santa Rita','Santo Niño','Tarangnan','Villareal','Wright','Zumarraga']},
            {p:'Southern Leyte',c:['Anahawan','Bontoc','Hinunangan','Hinundayan','Libagon','Liloan','Limasawa','Maasin City','Macrohon','Malitbog','Padre Burgos','Pintuyan','Saint Bernard','San Francisco','San Juan','San Ricardo','Sogod','Tomas Oppus']}
        ]},
        {r:'IX',n:'Region IX – Zamboanga Peninsula',p:[
            {p:'Zamboanga del Norte',c:['Baliguian','Dapitan City','Dipolog City','Godod','Gutalac','Jose Dalman','Kalawit','Katipunan','La Libertad','Labason','Leon B. Postigo','Liloy','Manukan','Mutia','Piñan','Polanco','President Manuel A. Roxas','Rizal','Salug','San Miguel','Siayan','Sibuco','Sibutad','Sindangan','Siocon','Sirawai','Tampilisan']},
            {p:'Zamboanga del Sur',c:['Aurora','Bayog','Dimataling','Dinas','Dumalinao','Dumingag','Guipos','Josefina','Kumalarang','Labangan','Lapuyan','Mahayag','Margosatubig','Midsalip','Molave','Pagadian City','Pitogo','Ramon Magsaysay','San Miguel','San Pablo','Tabina','Tambulig','Tigbao','Tukuran','Zamboanga City']},
            {p:'Zamboanga Sibugay',c:['Alicia','Buug','Diplahan','Imelda','Ipil','Kabasalan','Mabuhay','Malangas','Naga','Olutanga','Payao','Roseller Lim','Siay','Talusan','Titay','Tungawan']}
        ]},
        {r:'X',n:'Region X – Northern Mindanao',p:[
            {p:'Bukidnon',c:['Baungon','Cabanglasan','Damulog','Dangcagan','Don Carlos','Impasugong','Kadingilan','Kalilangan','Kibawe','Kitaotao','Lantapan','Libona','Malaybalay City','Malitbog','Manolo Fortich','Maramag','Pangantucan','Quezon','San Fernando','Sumilao','Talakag','Valencia City']},
            {p:'Camiguin',c:['Catarman','Guinsiliban','Mahinog','Mambajao','Sagay']},
            {p:'Lanao del Norte',c:['Bacolod','Baloi','Baroy','Iligan City','Kapatagan','Kauswagan','Kolambugan','Lala','Linamon','Magsaysay','Maigo','Munai','Nunungan','Pantao Ragat','Pantar','Poona Piagapo','Salvador','Sapad','Sultan Naga Dimaporo','Tagoloan','Tangcal','Tubod']},
            {p:'Misamis Occidental',c:['Aloran','Baliangao','Bonifacio','Calamba','Clarin','Concepcion','Don Victoriano Chiongbian','Jimenez','Lopez Jaena','Oroquieta City','Ozamiz City','Panaon','Plaridel','Sapang Dalaga','Sinacaban','Tangub City','Tudela']},
            {p:'Misamis Oriental',c:['Alubijid','Balingasag','Balingoan','Binuangan','Cagayan de Oro City','Claveria','El Salvador City','Gingoog City','Gitagum','Initao','Jasaan','Kinoguitan','Lagonglong','Laguindingan','Libertad','Lugait','Magsaysay','Manticao','Medina','Naawan','Opol','Salay','Sugbongcogon','Tagoloan','Talisayan','Villanueva']}
        ]},
        {r:'XI',n:'Region XI – Davao Region',p:[
            {p:'Davao de Oro',c:['Compostela','Laak','Mabini','Maco','Maragusan','Mawab','Monkayo','Montevista','Nabunturan','New Bataan','Pantukan']},
            {p:'Davao del Norte',c:['Asuncion','Braulio E. Dujali','Carmen','Kapalong','New Corella','Panabo City','Samal City','San Isidro','Santo Tomas','Tagum City','Talaingod']},
            {p:'Davao del Sur',c:['Bansalan','Davao City','Digos City','Hagonoy','Kiblawan','Magsaysay','Malalag','Matanao','Padada','Santa Cruz','Sulop']},
            {p:'Davao Occidental',c:['Don Marcelino','Jose Abad Santos','Malita','Santa Maria','Sarangani']},
            {p:'Davao Oriental',c:['Baganga','Banaybanay','Boston','Caraga','Cateel','Governor Generoso','Lupon','Manay','Mati City','San Isidro','Tarragona']}
        ]},
        {r:'XII',n:'Region XII – SOCCSKSARGEN',p:[
            {p:'Cotabato',c:['Alamada','Aleosan','Antipas','Arakan','Banisilan','Carmen','Kabacan','Kidapawan City','Libungan',"M'lang",'Magpet','Makilala','Matalam','Midsayap','Pigcawayan','Pikit','President Roxas','Tulunan']},
            {p:'Sarangani',c:['Alabel','Glan','Kiamba','Maasim','Maitum','Malapatan','Malungon']},
            {p:'South Cotabato',c:['Banga','General Santos City','Koronadal City','Lake Sebu','Norala','Polomolok','Santo Niño','Surallah',"T'boli",'Tampakan','Tantangan','Tupi']},
            {p:'Sultan Kudarat',c:['Bagumbayan','Columbio','Esperanza','Isulan','Kalamansig','Lambayong','Lebak','Lutayan','Palimbang','President Quirino','Senator Ninoy Aquino','Tacurong City']}
        ]},
        {r:'XIII',n:'Region XIII – Caraga',p:[
            {p:'Agusan del Norte',c:['Buenavista','Butuan City','Cabadbaran City','Carmen','Jabonga','Kitcharao','Las Nieves','Magallanes','Nasipit','Remedios T. Romualdez','Santiago','Tubay']},
            {p:'Agusan del Sur',c:['Bayugan City','Bunawan','Esperanza','La Paz','Loreto','Prosperidad','Rosario','San Francisco','San Luis','Santa Josefa','Sibagat','Talacogon','Trento','Veruela']},
            {p:'Dinagat Islands',c:['Basilisa','Cagdianao','Dinagat','Libjo','Loreto','San Jose','Tubajon']},
            {p:'Surigao del Norte',c:['Alegria','Bacuag','Burgos','Claver','Dapa','Del Carmen','General Luna','Gigaquit','Mainit','Malimono','Pilar','Placer','San Benito','San Francisco','San Isidro','Santa Monica','Sison','Socorro','Surigao City','Tagana-an','Tubod']},
            {p:'Surigao del Sur',c:['Barobo','Bayabas','Bislig City','Cagwait','Cantilan','Carmen','Carrascal','Cortes','Hinatuan','Lanuza','Lianga','Lingig','Madrid','Marihatag','San Agustin','San Miguel','Tagbina','Tago','Tandag City']}
        ]},
        {r:'BARMM',n:'BARMM – Bangsamoro Autonomous Region',p:[
            {p:'Basilan',c:['Akbar','Al-Barka','Hadji Mohammad Ajul','Hadji Muhtamad','Isabela City','Lamitan City','Lantawan','Maluso','Sumisip','Tipo-Tipo','Tuburan','Ungkaya Pukan']},
            {p:'Lanao del Sur',c:['Bacolod-Kalawi','Balabagan','Balindong','Bayang','Binidayan','Buadiposo-Buntong','Bubong','Bumbaran','Butig','Calanogas','Ditsaan-Ramain','Ganassi','Lumba-Bayabao','Lumbaca-Unayan','Lumbatan','Lumbayanague','Madalum','Madamba','Maguing','Malabang','Marantao','Marawi City','Marogong','Masiu','Mulondo','Pagayawan','Piagapo','Picong','Poona Bayabao','Pualas','Saguiaran','Sultan Dumalondong','Sultan Gumander','Tagoloan II','Tamparan','Taraka','Tubaran','Tugaya','Wao']},
            {p:'Maguindanao del Norte',c:['Barira','Buldon','Cotabato City','Datu Blah T. Sinsuat','Datu Odin Sinsuat','Kabuntalan','Matanog','Parang','Sultan Kudarat','Sultan Mastura','Upi']},
            {p:'Maguindanao del Sur',c:['Ampatuan','Buluan','Datu Abdullah Sangki','Datu Anggal Midtimbang','Datu Paglas','Datu Piang','Datu Salibo','Datu Saudi-Ampatuan','Datu Unsay','Gen. Salipada K. Pendatun','Guindulungan','Mamasapano','Mangudadatu','Northern Kabuntalan','Pandag','Raja Buayan','Shariff Aguak','Shariff Saydona Mustapha','South Upi','Sultan sa Barongis','Talayan']},
            {p:'Sulu',c:['Hadji Panglima Tahil','Indanan','Jolo','Kalingalan Caluang','Languyan','Lugus','Luuk','Maimbung','Old Panamao','Omar','Pandami','Panglima Estino','Pangutaran','Parang','Pata','Patikul','Siasi','Talipao','Tapul','Tongkil']},
            {p:'Tawi-Tawi',c:['Balimbing','Bongao','Languyan','Mapun','Panglima Sugala','Sapa-Sapa','Sibutu','Simunul','Sitangkai','South Ubian','Tandubas','Turtle Islands']}
        ]}
    ];

    /* Build region select */
    document.querySelectorAll('.ph-region-sel').forEach(regSel => {
        PH.forEach(reg => {
            regSel.appendChild(new Option(reg.n, reg.n));
        });

        const form     = regSel.closest('form');
        const provSel  = form ? form.querySelector('.ph-province-sel') : null;
        const citySel  = form ? form.querySelector('.ph-city-sel')     : null;
        if (!provSel || !citySel) return;

        function fillProvinces() {
            provSel.innerHTML = '<option value="">— Select Province —</option>';
            citySel.innerHTML = '<option value="">— Select City —</option>';
            provSel.disabled  = true;
            citySel.disabled  = true;
            const reg = PH.find(r => r.n === regSel.value);
            if (!reg) return;
            reg.p.forEach(pr => provSel.appendChild(new Option(pr.p, pr.p)));
            provSel.disabled = false;
        }

        function fillCities() {
            citySel.innerHTML = '<option value="">— Select City / Municipality —</option>';
            citySel.disabled  = true;
            const reg = PH.find(r => r.n === regSel.value);
            if (!reg) return;
            const pr  = reg.p.find(p => p.p === provSel.value);
            if (!pr)  return;
            pr.c.forEach(city => citySel.appendChild(new Option(city, city)));
            citySel.disabled = false;
        }

        regSel.addEventListener('change',  fillProvinces);
        provSel.addEventListener('change', fillCities);
    });
})();
</script>

<?php if ($usersView === 'student'): ?>
<!-- Delete All Students Modal -->
<div class="modal fade" id="deleteAllModal" tabindex="-1" aria-labelledby="deleteAllModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteAllModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete All Students</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger fw-semibold">This will permanently delete <strong>all <?= h((string) $totalUsers) ?> student<?= $totalUsers !== 1 ? 's' : '' ?></strong> and cannot be undone.</p>
                <p class="text-muted small">Their attendance logs and RFID card assignments will also be removed.</p>
                <label for="deleteAllConfirmInput" class="form-label fw-semibold">Type <span class="text-danger font-monospace">DELETE</span> to confirm:</label>
                <input type="text" id="deleteAllConfirmInput" class="form-control" placeholder="DELETE" autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post">
                    <input type="hidden" name="action" value="delete_all_students">
                    <input type="hidden" name="users_view" value="student">
                    <button type="submit" id="deleteAllConfirmBtn" class="btn btn-danger" disabled>
                        <i class="bi bi-trash3-fill me-1"></i>Delete All Students
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('deleteAllConfirmInput');
    const btn   = document.getElementById('deleteAllConfirmBtn');
    if (input && btn) {
        input.addEventListener('input', function () {
            btn.disabled = this.value.trim() !== 'DELETE';
        });
        document.getElementById('deleteAllModal').addEventListener('hidden.bs.modal', function () {
            input.value = '';
            btn.disabled = true;
        });
    }
})();
</script>
<?php endif; ?>

<?php renderFooter(); ?>
