<?php

declare(strict_types=1);

/**
 * SIM800 USB gateway (laptop, Pi, etc.): poll pending SMS jobs and acknowledge results.
 * POST JSON only. No session; authenticates with tenant slug + sms_poll_secret.
 *
 * HTTP path is `{BASE_URL}/worker/sms_queue.php` (see `config/config.php`).
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST with JSON body.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON.']);
    exit;
}

$action = strtolower(trim((string) ($data['action'] ?? '')));
$tenantSlug = trim((string) ($data['tenant'] ?? ''));
$secret = trim((string) ($data['secret'] ?? ''));

if ($tenantSlug === '' || $secret === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'tenant and secret are required.']);
    exit;
}

$pdo = db();

if (!smsOutboxTableExists($pdo) || !dbColumnExists('tenants', 'sms_poll_secret')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'SMS schema missing. Import sql/sms_upgrade.sql.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, slug, sms_poll_secret FROM tenants WHERE slug = ? LIMIT 1');
$stmt->execute([$tenantSlug]);
$tenant = $stmt->fetch();
if (!$tenant) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid tenant or secret.']);
    exit;
}

$stored = (string) ($tenant['sms_poll_secret'] ?? '');
if ($stored === '' || !hash_equals($stored, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid tenant or secret.']);
    exit;
}

$tenantId = (int) $tenant['id'];

sms_recover_stale_processing_jobs($pdo, $tenantId);

/** Read-only: verify URL + secret and see backlog without claiming jobs. */
if ($action === 'peek') {
    $cntPending = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE tenant_id = ? AND status = 'pending'");
    $cntPending->execute([$tenantId]);
    $pending = (int) $cntPending->fetchColumn();

    $cntProcessing = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE tenant_id = ? AND status = 'processing'");
    $cntProcessing->execute([$tenantId]);
    $processing = (int) $cntProcessing->fetchColumn();

    $idsStmt = $pdo->prepare("
        SELECT id FROM sms_outbox
        WHERE tenant_id = ? AND status = 'pending'
        ORDER BY id ASC
        LIMIT 10
    ");
    $idsStmt->execute([$tenantId]);
    $ids = array_map('intval', array_column($idsStmt->fetchAll(), 'id'));

    echo json_encode([
        'ok' => true,
        'pending' => $pending,
        'processing' => $processing,
        'pending_job_ids' => $ids,
    ]);
    exit;
}

if ($action === 'poll') {
    $limit = (int) ($data['limit'] ?? 5);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 15) {
        $limit = 15;
    }

    $jobs = [];
    for ($i = 0; $i < $limit; $i++) {
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("
                SELECT id, destination_phone, message_body
                FROM sms_outbox
                WHERE tenant_id = ? AND status = 'pending'
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $sel->execute([$tenantId]);
            $row = $sel->fetch();
            if (!$row) {
                $pdo->commit();
                break;
            }
            if (dbColumnExists('sms_outbox', 'processing_started_at')) {
                $upd = $pdo->prepare("
                    UPDATE sms_outbox
                    SET status = 'processing', processing_started_at = NOW()
                    WHERE id = ? AND tenant_id = ? AND status = 'pending'
                ");
            } else {
                $upd = $pdo->prepare("
                    UPDATE sms_outbox
                    SET status = 'processing'
                    WHERE id = ? AND tenant_id = ? AND status = 'pending'
                ");
            }
            $upd->execute([(int) $row['id'], $tenantId]);
            if ($upd->rowCount() === 0) {
                $pdo->commit();
                continue;
            }
            $pdo->commit();
            $jobs[] = [
                'id' => (int) $row['id'],
                'destination_phone' => (string) $row['destination_phone'],
                'message_body' => (string) $row['message_body'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Poll transaction failed.']);
            exit;
        }
    }

    echo json_encode(['ok' => true, 'jobs' => $jobs]);
    exit;
}

if ($action === 'ack') {
    $jobId = (int) ($data['job_id'] ?? 0);
    $status = strtoupper(trim((string) ($data['status'] ?? '')));
    if ($jobId <= 0 || !in_array($status, ['SENT', 'FAILED'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'job_id and status (sent|failed) required.']);
        exit;
    }

    $newStatus = $status === 'SENT' ? 'sent' : 'failed';
    $err = trim((string) ($data['error'] ?? ''));
    if (mb_strlen($err) > 480) {
        $err = mb_substr($err, 0, 477) . '...';
    }

    $upd = $pdo->prepare("
        UPDATE sms_outbox
        SET
            status = ?,
            processed_at = NOW(),
            error_message = ?
        WHERE id = ? AND tenant_id = ? AND status = 'processing'
    ");
    $upd->execute([
        $newStatus,
        $newStatus === 'failed' ? ($err !== '' ? $err : 'Send failed') : null,
        $jobId,
        $tenantId,
    ]);

    if ($upd->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Job not in processing state or not found.']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Unknown action. Use peek, poll, or ack.']);
