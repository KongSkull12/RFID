<?php

declare(strict_types=1);

/**
 * Python worker must reach Apache on IPv4; "localhost" often resolves to ::1 on Windows while
 * XAMPP listens on 127.0.0.1 only, causing HTTP timeouts and jobs stuck in pending/processing.
 */
function sms_normalize_queue_url_for_worker(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return $url;
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }
    if (strcasecmp((string) $parts['host'], 'localhost') !== 0) {
        return $url;
    }
    $parts['host'] = '127.0.0.1';
    $scheme = $parts['scheme'] . '://';
    $auth = '';
    if (isset($parts['user'])) {
        $auth = $parts['user'];
        if (isset($parts['pass'])) {
            $auth .= ':' . $parts['pass'];
        }
        $auth .= '@';
    }
    $host = $parts['host'];
    if (isset($parts['port'])) {
        $host .= ':' . (int) $parts['port'];
    }
    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $auth . $host . $path . $query . $fragment;
}

/**
 * Jobs left in "processing" (worker crash, API timeout before ack) block clarity; return them to the queue.
 */
function sms_recover_stale_processing_jobs(PDO $pdo, int $tenantId): void
{
    if (!dbColumnExists('sms_outbox', 'processing_started_at')) {
        return;
    }
    $st = $pdo->prepare("
        UPDATE sms_outbox
        SET status = 'pending', processing_started_at = NULL
        WHERE tenant_id = ?
          AND status = 'processing'
          AND processing_started_at IS NOT NULL
          AND processing_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $st->execute([$tenantId]);
}

/**
 * POST a peek to the worker URL from PHP (same checks the USB gateway uses). Helps separate
 * "URL broken / blocked" from "no gateway PC polling".
 *
 * @return bool|null true = HTTP 200 + ok JSON; false = failed; null = could not probe
 */
function sms_probe_worker_endpoint(string $url, string $tenantSlug, string $secret): ?bool
{
    $url = trim($url);
    $tenantSlug = trim($tenantSlug);
    $secret = trim($secret);
    if ($url === '' || $tenantSlug === '' || $secret === '') {
        return null;
    }

    $body = json_encode([
        'action' => 'peek',
        'tenant' => $tenantSlug,
        'secret' => $secret,
    ], JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false || $code !== 200) {
            return false;
        }
        $j = json_decode((string) $out, true);

        return is_array($j) && !empty($j['ok']);
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 6,
        ],
    ]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) {
        return false;
    }
    $j = json_decode((string) $out, true);

    return is_array($j) && !empty($j['ok']);
}

function smsOutboxTableExists(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'sms_outbox'");
        $cached = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function smsTenantColumnsReady(PDO $pdo): bool
{
    return dbColumnExists('tenants', 'sms_enabled')
        && dbColumnExists('tenants', 'sms_template_in')
        && dbColumnExists('tenants', 'sms_poll_secret');
}

/**
 * Normalize cellphone numbers for GSM (Philippines-friendly defaults).
 */
function normalizeSmsDestination(?string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
    if ($digits === '') {
        return null;
    }
    if (str_starts_with($digits, '63')) {
        return '+' . $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '+63' . substr($digits, 1);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        return '+63' . $digits;
    }
    if (str_starts_with($digits, '1') && strlen($digits) === 11) {
        return '+' . $digits;
    }
    return '+' . $digits;
}

function defaultSmsTemplateIn(): string
{
    return '[{school_name}] {student_name} arrived (IN) at {time}. {remarks}';
}

function defaultSmsTemplateOut(): string
{
    return '[{school_name}] {student_name} left (OUT) at {time}. {remarks}';
}

function defaultSmsTemplateStaffIn(): string
{
    return '[{school_name}] {staff_name} ({role_label}) clocked IN at {time}. {remarks}';
}

function defaultSmsTemplateStaffOut(): string
{
    return '[{school_name}] {staff_name} ({role_label}) clocked OUT at {time}. {remarks}';
}

/**
 * @param array<string, string> $vars
 */
function interpolateSmsTemplate(string $template, array $vars): string
{
    $out = $template;
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', $value, $out);
    }
    return $out;
}

/**
 * Human-readable explanation when SMS was not queued (for gate UI / API).
 */
function smsQueueSkipHint(string $reason): string
{
    return match ($reason) {
        'schema_missing' => 'SMS not set up: import sql/sms_upgrade.sql in the database.',
        'not_student' => 'SMS is only queued for students.',
        'no_parent' => 'Assign a parent to this student (Users → Students).',
        'no_parent_phone' => 'Parent account has no valid phone number — add it under Users → Parents.',
        'sms_disabled' => 'Turn on Parent SMS and Save in Admin → Parent SMS.',
        'no_gateway_secret' => 'Open Parent SMS, click Save once to create the gateway secret.',
        'empty_message' => 'Message template produced an empty text.',
        'queue_failed' => 'Could not write to SMS queue (check database).',
        'not_teacher_or_employee' => 'Staff SMS is only for teachers and employees.',
        'no_staff_phone' => 'Add a valid phone number on this teacher/employee account (Users).',
        'staff_sms_disabled' => 'Turn on Teacher & employee scan SMS in Admin → Parent SMS and Save.',
        default => 'SMS was not queued.',
    };
}

/**
 * After a successful student attendance log, queue parent SMS if enabled.
 *
 * @return array{queued: bool, reason: string|null, hint: string|null}
 */
function maybeQueueParentAttendanceSms(
    PDO $pdo,
    int $tenantId,
    int $attendanceLogId,
    string $scanType,
    array $cardRow,
    string $deviceName,
    string $remarks,
    string $scannedAtDisplay
): array {
    $fail = static function (string $reason): array {
        return [
            'queued' => false,
            'reason' => $reason,
            'hint' => smsQueueSkipHint($reason),
        ];
    };

    if (!smsOutboxTableExists($pdo) || !smsTenantColumnsReady($pdo)) {
        return $fail('schema_missing');
    }
    if (($cardRow['role'] ?? '') !== 'student') {
        return $fail('not_student');
    }
    $parentId = isset($cardRow['parent_user_id']) ? (int) $cardRow['parent_user_id'] : 0;
    if ($parentId <= 0) {
        return $fail('no_parent');
    }
    $parentPhone = normalizeSmsDestination($cardRow['parent_phone'] ?? null);
    if ($parentPhone === null) {
        return $fail('no_parent_phone');
    }

    $stmt = $pdo->prepare('
        SELECT sms_template_in, sms_template_out, school_name, sms_poll_secret
        FROM tenants WHERE id = ? LIMIT 1
    ');
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        return $fail('queue_failed');
    }
    if (trim((string) ($tenant['sms_poll_secret'] ?? '')) === '') {
        return $fail('no_gateway_secret');
    }

    $template = $scanType === 'OUT'
        ? (trim((string) ($tenant['sms_template_out'] ?? '')) ?: defaultSmsTemplateOut())
        : (trim((string) ($tenant['sms_template_in'] ?? '')) ?: defaultSmsTemplateIn());

    $studentName = trim(
        ($cardRow['first_name'] ?? '') . ' '
        . (trim((string) ($cardRow['middle_name'] ?? '')) !== ''
            ? trim((string) $cardRow['middle_name']) . ' '
            : '')
        . ($cardRow['last_name'] ?? '')
    );

    $remarksForSms = $remarks;
    if ($scanType === 'IN' && str_starts_with(strtolower(trim($remarks)), 'late by')) {
        $remarksForSms = '';
    }

    $vars = [
        'school_name' => (string) ($tenant['school_name'] ?? ''),
        'student_name' => $studentName,
        'scan_type' => $scanType,
        'time' => $scannedAtDisplay,
        'date' => substr($scannedAtDisplay, 0, 10),
        'remarks' => $remarksForSms,
        'device_name' => $deviceName,
        'parent_name' => trim((string) ($cardRow['parent_first_name'] ?? '') . ' ' . (string) ($cardRow['parent_last_name'] ?? '')),
    ];

    $body = interpolateSmsTemplate($template, $vars);
    $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
    if ($body === '') {
        return $fail('empty_message');
    }
    if (mb_strlen($body) > 670) {
        $body = mb_substr($body, 0, 667) . '...';
    }

    try {
        $ins = $pdo->prepare('
            INSERT INTO sms_outbox (tenant_id, attendance_log_id, destination_phone, message_body, status)
            VALUES (?, ?, ?, ?, \'pending\')
        ');
        $ins->execute([$tenantId, $attendanceLogId, $parentPhone, $body]);
    } catch (Throwable $e) {
        return $fail('queue_failed');
    }

    return ['queued' => true, 'reason' => null, 'hint' => null];
}

/**
 * After a teacher or employee attendance log, queue SMS to their own phone if enabled.
 *
 * @return array{queued: bool, reason: string|null, hint: string|null}
 */
function maybeQueueStaffScanSms(
    PDO $pdo,
    int $tenantId,
    int $attendanceLogId,
    string $scanType,
    array $cardRow,
    string $deviceName,
    string $remarks,
    string $scannedAtDisplay
): array {
    $fail = static function (string $reason): array {
        return [
            'queued' => false,
            'reason' => $reason,
            'hint' => smsQueueSkipHint($reason),
        ];
    };

    $role = (string) ($cardRow['role'] ?? '');
    if (!in_array($role, ['teacher', 'employee'], true)) {
        return $fail('not_teacher_or_employee');
    }

    if (!smsOutboxTableExists($pdo) || !smsTenantColumnsReady($pdo)) {
        return $fail('schema_missing');
    }

    $dest = normalizeSmsDestination($cardRow['user_phone'] ?? null);
    if ($dest === null) {
        return $fail('no_staff_phone');
    }

    $select = 'sms_poll_secret, school_name';
    if (dbColumnExists('tenants', 'sms_template_staff_in')) {
        $select .= ', sms_template_staff_in, sms_template_staff_out';
    }
    $stmt = $pdo->prepare("SELECT $select FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        return $fail('queue_failed');
    }
    if (trim((string) ($tenant['sms_poll_secret'] ?? '')) === '') {
        return $fail('no_gateway_secret');
    }

    $tplIn = defaultSmsTemplateStaffIn();
    $tplOut = defaultSmsTemplateStaffOut();
    if (dbColumnExists('tenants', 'sms_template_staff_in')) {
        $ti = trim((string) ($tenant['sms_template_staff_in'] ?? ''));
        $to = trim((string) ($tenant['sms_template_staff_out'] ?? ''));
        if ($ti !== '') {
            $tplIn = $ti;
        }
        if ($to !== '') {
            $tplOut = $to;
        }
    }

    $template = $scanType === 'OUT' ? $tplOut : $tplIn;

    $staffName = trim(
        ($cardRow['first_name'] ?? '') . ' '
        . (trim((string) ($cardRow['middle_name'] ?? '')) !== ''
            ? trim((string) $cardRow['middle_name']) . ' '
            : '')
        . ($cardRow['last_name'] ?? '')
    );

    $roleLabel = roleLabel($role);

    $vars = [
        'school_name' => (string) ($tenant['school_name'] ?? ''),
        'staff_name' => $staffName,
        'student_name' => $staffName,
        'role_label' => $roleLabel,
        'scan_type' => $scanType,
        'time' => $scannedAtDisplay,
        'date' => substr($scannedAtDisplay, 0, 10),
        'remarks' => $remarks,
        'device_name' => $deviceName,
        'parent_name' => '',
    ];

    $body = interpolateSmsTemplate($template, $vars);
    $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
    if ($body === '') {
        return $fail('empty_message');
    }
    if (mb_strlen($body) > 670) {
        $body = mb_substr($body, 0, 667) . '...';
    }

    try {
        $ins = $pdo->prepare('
            INSERT INTO sms_outbox (tenant_id, attendance_log_id, destination_phone, message_body, status)
            VALUES (?, ?, ?, ?, \'pending\')
        ');
        $ins->execute([$tenantId, $attendanceLogId, $dest, $body]);
    } catch (Throwable $e) {
        return $fail('queue_failed');
    }

    return ['queued' => true, 'reason' => null, 'hint' => null];
}

/**
 * Gateway usable when SMS schema exists and tenant has a poll secret (sending is always-on in app logic).
 */
function tenantCanUseSmsGateway(PDO $pdo, int $tenantId): bool
{
    if (!smsOutboxTableExists($pdo) || !smsTenantColumnsReady($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT sms_poll_secret FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();

    return $row && trim((string) ($row['sms_poll_secret'] ?? '')) !== '';
}

/**
 * Queue one outbox row per distinct phone for an announcement (parents and/or teachers).
 *
 * @return array{count: int, reason: string|null}
 */
function queueAnnouncementSmsBroadcast(
    PDO $pdo,
    int $tenantId,
    string $title,
    string $content,
    bool $sendParents,
    bool $sendTeachers
): array {
    if (!$sendParents && !$sendTeachers) {
        return ['count' => 0, 'reason' => null];
    }
    if (!tenantCanUseSmsGateway($pdo, $tenantId)) {
        return ['count' => 0, 'reason' => 'Turn on Parent SMS and save a gateway secret, or import sms_upgrade.sql.'];
    }

    $stmt = $pdo->prepare('SELECT school_name FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $school = (string) ($stmt->fetchColumn() ?: 'School');

    $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
    $rest = trim(preg_replace('/\s+/', ' ', $content) ?? '');
    $prefix = '[' . $school . '] Announcement: ' . $title . ' — ';
    $body = $prefix . $rest;
    if (mb_strlen($body) > 670) {
        $body = mb_substr($body, 0, 667) . '...';
    }

    $roles = [];
    if ($sendParents) {
        $roles[] = 'parent';
    }
    if ($sendTeachers) {
        $roles[] = 'teacher';
    }
    if ($roles === []) {
        return ['count' => 0, 'reason' => null];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $sql = "
        SELECT phone FROM users
        WHERE tenant_id = ?
          AND role IN ($placeholders)
          AND status = 'active'
          AND phone IS NOT NULL
          AND phone <> ''
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$tenantId], $roles));

    $phones = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $norm = normalizeSmsDestination($row['phone'] ?? null);
        if ($norm !== null) {
            $phones[$norm] = true;
        }
    }

    if ($phones === []) {
        return ['count' => 0, 'reason' => 'No matching parent/teacher phone numbers in Users.'];
    }

    $ins = $pdo->prepare('
        INSERT INTO sms_outbox (tenant_id, attendance_log_id, destination_phone, message_body, status)
        VALUES (?, NULL, ?, ?, \'pending\')
    ');

    try {
        $pdo->beginTransaction();
        foreach (array_keys($phones) as $dest) {
            $ins->execute([$tenantId, $dest, $body]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['count' => 0, 'reason' => 'Could not queue SMS.'];
    }

    $n = count($phones);
    trigger_local_sms_worker_after_queue($tenantId);

    return ['count' => $n, 'reason' => null];
}

/**
 * Resolve py/python to a full path via `where` (same environment as the PHP process).
 *
 * @return array{path: string, prefix: array<int, string>}|null
 */
function sms_windows_resolve_python(string $fullPy, string $exe, array $extra): ?array
{
    if ($fullPy !== '') {
        if (!is_file($fullPy)) {
            return null;
        }

        return ['path' => $fullPy, 'prefix' => $extra];
    }

    $tryNames = [$exe];
    if (strtolower($exe) === 'py') {
        $tryNames[] = 'python';
        $tryNames[] = 'python3';
    }

    foreach ($tryNames as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $out = [];
        $code = 0;
        @exec('where ' . escapeshellarg($name) . ' 2>NUL', $out, $code);
        if ($code !== 0 || $out === [] || $out[0] === '') {
            continue;
        }
        $found = trim((string) $out[0]);
        if ($found === '' || !is_file($found)) {
            continue;
        }
        $base = strtolower(pathinfo($found, PATHINFO_FILENAME));
        $prefix = ($base === 'py') ? $extra : [];

        return ['path' => $found, 'prefix' => $prefix];
    }

    $local = getenv('LOCALAPPDATA') ?: '';
    if ($local !== '') {
        foreach (['Python313', 'Python312', 'Python311', 'Python310', 'Python39'] as $dir) {
            $p = $local . '\\Programs\\Python\\' . $dir . '\\python.exe';
            if (is_file($p)) {
                return ['path' => $p, 'prefix' => []];
            }
        }
    }
    $pf = getenv('ProgramFiles') ?: '';
    if ($pf !== '') {
        foreach (['Python313', 'Python312', 'Python311', 'Python310'] as $dir) {
            $p = $pf . '\\' . $dir . '\\python.exe';
            if (is_file($p)) {
                return ['path' => $p, 'prefix' => []];
            }
        }
    }

    return null;
}

function sms_worker_log_line(string $line): void
{
    $dir = app_storage_dir();
    $path = $dir . DIRECTORY_SEPARATOR . 'sms_worker.log';
    $trim = trim($line);
    if (mb_strlen($trim) > 800) {
        $trim = mb_substr($trim, 0, 797) . '...';
    }
    @file_put_contents($path, date('c') . ' ' . $trim . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Run sms_gateway.py --once-config and wait (Windows). Runs during the request, before the gate JSON is sent.
 */
function sms_run_sync_gateway_once(string $scriptPath, string $tmpJsonPath): void
{
    $fullPy = defined('SMS_LOCAL_PYTHON_FULL_PATH') ? trim((string) SMS_LOCAL_PYTHON_FULL_PATH) : '';
    $exe = defined('SMS_LOCAL_PYTHON_EXECUTABLE') ? SMS_LOCAL_PYTHON_EXECUTABLE : 'python';
    $extra = (defined('SMS_LOCAL_PYTHON_EXTRA') && is_array(SMS_LOCAL_PYTHON_EXTRA)) ? SMS_LOCAL_PYTHON_EXTRA : [];

    $resolved = sms_windows_resolve_python($fullPy, $exe, $extra);
    if ($resolved === null) {
        sms_worker_log_line('SMS worker: could not find Python (set SMS_LOCAL_PYTHON_FULL_PATH in config/config.php).');

        return;
    }

    $argv = array_merge(
        [$resolved['path']],
        $resolved['prefix'],
        [$scriptPath, '--once-config', $tmpJsonPath]
    );
    $argv = array_values(array_filter($argv, static fn ($x) => $x !== '' && $x !== null));

    $cwd = dirname($scriptPath, 2);
    if ($cwd === '' || !is_dir($cwd)) {
        $cwd = null;
    }

    $descriptorspec = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = false;
    if (function_exists('proc_open')) {
        $proc = @proc_open(
            $argv,
            $descriptorspec,
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            $line = '';
            foreach ($argv as $i => $a) {
                $line .= ($i > 0 ? ' ' : '') . escapeshellarg((string) $a);
            }
            $proc = @proc_open($line, $descriptorspec, $pipes, $cwd, null);
        }
    }

    if (is_resource($proc)) {
        $err = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        $code = proc_close($proc);
        if ($code !== 0 || trim($err) !== '') {
            sms_worker_log_line('SMS worker exit=' . (string) $code . ' ' . trim(str_replace(["\r", "\n"], ' ', $err)));
        }

        return;
    }

    $cmdLine = '';
    foreach ($argv as $i => $a) {
        $cmdLine .= ($i > 0 ? ' ' : '') . escapeshellarg((string) $a);
    }
    $out = [];
    $ret = 1;
    if (function_exists('exec')) {
        @exec($cmdLine . ' 2>&1', $out, $ret);
    }
    if ($ret !== 0) {
        sms_worker_log_line('SMS worker exec ret=' . (string) $ret . ' ' . trim(implode(' ', array_map('strval', $out))));
    }
}

/**
 * Start sms_gateway.py without blocking the HTTP request. Python deletes $tmpJsonPath when done.
 *
 * @param list<string|int|float> $argv Full argv including python.exe as [0]
 */
function sms_spawn_windows_gateway_async(array $argv, ?string $cwd): bool
{
    $parts = [];
    foreach ($argv as $a) {
        $parts[] = escapeshellarg((string) $a);
    }
    $inner = implode(' ', $parts);
    $cmd = 'cmd /c start /B "" ' . $inner;

    $descriptorspec = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', 'NUL', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptorspec, $pipes, $cwd ?: null);
    if (is_resource($proc)) {
        proc_close($proc);

        return true;
    }

    return false;
}

/**
 * Fire-and-forget: run sms_gateway.py once on this machine (USB modem must be local to PHP).
 */
function trigger_local_sms_worker_after_queue(int $tenantId): void
{
    if (!defined('SMS_LOCAL_AUTO_SEND') || !SMS_LOCAL_AUTO_SEND) {
        return;
    }
    if (!defined('SMS_LOCAL_QUEUE_URL') || SMS_LOCAL_QUEUE_URL === '') {
        return;
    }
    if (!defined('SMS_LOCAL_SERIAL_PORTS') || SMS_LOCAL_SERIAL_PORTS === '') {
        return;
    }

    /**
     * php -S serves one request at a time. The worker polls this same origin via HTTP,
     * so starting it during rfid_scan deadlocks: the scan never returns until the worker
     * finishes, but the worker cannot run until the scan returns. Queue still works;
     * run `python scripts/sms_gateway.py` in another window, or use Apache/nginx.
     */
    if (PHP_SAPI === 'cli-server') {
        return;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'localhost';
    }
    if (defined('SMS_LOCAL_ALLOW_HOSTS') && is_array(SMS_LOCAL_ALLOW_HOSTS) && SMS_LOCAL_ALLOW_HOSTS !== []) {
        $allowed = array_map('strtolower', SMS_LOCAL_ALLOW_HOSTS);
        if (!in_array($host, $allowed, true)) {
            return;
        }
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT slug, sms_poll_secret FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row || trim((string) ($row['sms_poll_secret'] ?? '')) === '') {
        return;
    }

    $script = realpath(__DIR__ . '/../scripts/sms_gateway.py');
    if ($script === false || !is_readable($script)) {
        return;
    }

    $cfg = [
        'SMS_QUEUE_URL' => sms_normalize_queue_url_for_worker(SMS_LOCAL_QUEUE_URL),
        'SMS_TENANT_SLUG' => (string) ($row['slug'] ?? ''),
        'SMS_POLL_SECRET' => (string) $row['sms_poll_secret'],
        'SMS_SERIAL_PORTS' => SMS_LOCAL_SERIAL_PORTS,
        'SMS_BAUD' => defined('SMS_LOCAL_BAUD') ? (string) SMS_LOCAL_BAUD : '115200',
    ];
    if (defined('SMS_LOCAL_SMSC') && trim((string) SMS_LOCAL_SMSC) !== '') {
        $cfg['SMS_SMSC'] = trim((string) SMS_LOCAL_SMSC);
    }
    if (defined('SMS_LOCAL_CMGS_NO_PLUS') && SMS_LOCAL_CMGS_NO_PLUS) {
        $cfg['SMS_CMGS_NO_PLUS'] = '1';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'rfid_sms_');
    if ($tmp === false) {
        return;
    }
    $tmpJson = $tmp . '.json';
    if (!@rename($tmp, $tmpJson)) {
        @unlink($tmp);

        return;
    }
    file_put_contents($tmpJson, json_encode($cfg, JSON_UNESCAPED_SLASHES));

    $fullPy = defined('SMS_LOCAL_PYTHON_FULL_PATH') ? trim((string) SMS_LOCAL_PYTHON_FULL_PATH) : '';
    $exe = defined('SMS_LOCAL_PYTHON_EXECUTABLE') ? SMS_LOCAL_PYTHON_EXECUTABLE : 'python';
    $extra = (defined('SMS_LOCAL_PYTHON_EXTRA') && is_array(SMS_LOCAL_PYTHON_EXTRA)) ? SMS_LOCAL_PYTHON_EXTRA : [];

    if (PHP_OS_FAMILY === 'Windows') {
        $sync = defined('SMS_LOCAL_SYNC_GATEWAY') && SMS_LOCAL_SYNC_GATEWAY;
        if ($sync) {
            sms_run_sync_gateway_once($script, $tmpJson);
            if (is_file($tmpJson)) {
                @unlink($tmpJson);
            }

            return;
        }

        $resolved = sms_windows_resolve_python($fullPy, $exe, $extra);
        if ($resolved === null) {
            sms_worker_log_line('SMS worker: could not find Python (set SMS_LOCAL_PYTHON_FULL_PATH in config/config.php).');
            if (is_file($tmpJson)) {
                @unlink($tmpJson);
            }

            return;
        }

        $argv = array_merge(
            [$resolved['path']],
            $resolved['prefix'],
            [$script, '--once-config', $tmpJson]
        );
        $argv = array_values(array_filter($argv, static fn ($x) => $x !== '' && $x !== null));
        $cwd = dirname($script, 2);
        $cwd = ($cwd !== '' && is_dir($cwd)) ? $cwd : null;

        if (!sms_spawn_windows_gateway_async($argv, $cwd) && is_file($tmpJson)) {
            @unlink($tmpJson);
            sms_worker_log_line('SMS worker: could not start background process (async spawn failed).');
        }

        return;
    }

    $unixExe = $fullPy !== '' ? $fullPy : $exe;
    $unixParts = $fullPy !== '' ? [$fullPy, $script, '--once-config', $tmpJson] : array_merge([$unixExe], $extra, [$script, '--once-config', $tmpJson]);
    $escaped = array_map('escapeshellarg', $unixParts);
    exec(implode(' ', $escaped) . ' > /dev/null 2>&1 &');
}
