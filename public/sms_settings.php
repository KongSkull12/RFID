<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin']);

$pdo = db();
$tid = tenantId();
$schemaReady = smsOutboxTableExists($pdo) && smsTenantColumnsReady($pdo);

$hasStaffSmsCols = dbColumnExists('tenants', 'sms_notify_staff_scans');
$tenantSelect = 'id, slug, school_name, sms_enabled, sms_template_in, sms_template_out, sms_poll_secret';
if ($hasStaffSmsCols) {
    $tenantSelect .= ', sms_notify_staff_scans, sms_template_staff_in, sms_template_staff_out';
}
$stmt = $pdo->prepare("SELECT $tenantSelect FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tid]);
$row = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    $regen = isset($_POST['regenerate_poll_secret']) && $_POST['regenerate_poll_secret'] === '1';
    $enabled = 1;
    $staffNotify = 1;
    $tplIn = trim((string) ($_POST['sms_template_in'] ?? ''));
    $tplOut = trim((string) ($_POST['sms_template_out'] ?? ''));

    $newSecret = (string) ($row['sms_poll_secret'] ?? '');
    if ($regen || $newSecret === '') {
        $newSecret = bin2hex(random_bytes(24));
    }

    if ($hasStaffSmsCols) {
        $staffTplIn = trim((string) ($_POST['sms_template_staff_in'] ?? ''));
        $staffTplOut = trim((string) ($_POST['sms_template_staff_out'] ?? ''));
        $upd = $pdo->prepare('
            UPDATE tenants
            SET sms_enabled = ?, sms_template_in = ?, sms_template_out = ?, sms_poll_secret = ?,
                sms_notify_staff_scans = ?, sms_template_staff_in = ?, sms_template_staff_out = ?
            WHERE id = ?
        ');
        $upd->execute([
            $enabled,
            $tplIn !== '' ? $tplIn : null,
            $tplOut !== '' ? $tplOut : null,
            $newSecret,
            $staffNotify,
            $staffTplIn !== '' ? $staffTplIn : null,
            $staffTplOut !== '' ? $staffTplOut : null,
            $tid,
        ]);
    } else {
        $upd = $pdo->prepare('
            UPDATE tenants
            SET sms_enabled = ?, sms_template_in = ?, sms_template_out = ?, sms_poll_secret = ?
            WHERE id = ?
        ');
        $upd->execute([
            $enabled,
            $tplIn !== '' ? $tplIn : null,
            $tplOut !== '' ? $tplOut : null,
            $newSecret,
            $tid,
        ]);
    }
    flash('success', $regen ? 'SMS settings saved. A new gateway secret was generated — update the gateway script environment on your PC.' : 'SMS settings saved.');
    redirect(appUrl('sms_settings.php'));
}

renderHeader('Parent SMS');
?>

<style>
.sms-page .slug-display {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    background: var(--surface-3); border: 1.5px solid var(--border);
    border-radius: var(--r-sm); padding: 10px 14px;
    font-family: ui-monospace, 'Cascadia Code', 'Consolas', monospace;
    font-size: 0.82rem; color: var(--tx-secondary); min-height: 44px; word-break: break-all;
}
.sms-page .slug-display code { background: transparent; color: inherit; padding: 0; font-size: inherit; }
.sms-page .settings-save-bar {
    background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--r-md);
    padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    flex-wrap: wrap; box-shadow: var(--shadow-sm);
}
.sms-page .sc-icon {
    width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
</style>

<div class="sms-page">
<div class="page-header mb-4">
    <div class="page-header-left">
        <div class="page-header-icon"><i class="bi bi-chat-dots-fill"></i></div>
        <div>
            <h5>Parent SMS</h5>
            <p>Parent and staff scan SMS are always on when a gateway secret exists — edit templates below; USB modem worker sends the queue.</p>
        </div>
    </div>
    <?php if ($schemaReady && $row): ?>
    <div>
        <span class="badge rounded-pill text-bg-success d-inline-flex align-items-center gap-1 px-3 py-2">
            <i class="bi bi-broadcast"></i> Scan SMS on
        </span>
    </div>
    <?php endif; ?>
</div>

<?php if (!$schemaReady): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-0" role="alert">
    <i class="bi bi-database-exclamation mt-1 flex-shrink-0"></i>
    <div>
        <strong>Database update required.</strong>
        Import <code>sql/sms_upgrade.sql</code> in phpMyAdmin (or your MySQL client), then reload this page.
    </div>
</div>
<?php else: ?>

<?php if ($schemaReady && !$hasStaffSmsCols): ?>
<div class="alert alert-info d-flex align-items-start gap-2 small mb-3" role="alert">
    <i class="bi bi-person-badge mt-1 flex-shrink-0"></i>
    <div>
        Import <code>sql/sms_staff_scans.sql</code> once to edit <strong>custom staff IN/OUT</strong> message templates (staff SMS already works using defaults).
    </div>
</div>
<?php endif; ?>

<?php
$suggestedSmsQueueUrl = publicSmsWorkerQueueUrl();

$smsLocalQueueCfg = (defined('SMS_LOCAL_QUEUE_URL') && is_string(SMS_LOCAL_QUEUE_URL)) ? trim(SMS_LOCAL_QUEUE_URL) : '';
$normalizeSmsQueueUrl = static function (string $u): string {
    $u = trim(strtolower($u));
    $u = str_replace('localhost', '127.0.0.1', $u);

    return rtrim($u, '/');
};
$smsQueueUrlMismatch = defined('SMS_LOCAL_AUTO_SEND') && SMS_LOCAL_AUTO_SEND
    && $smsLocalQueueCfg !== ''
    && $normalizeSmsQueueUrl($smsLocalQueueCfg) !== $normalizeSmsQueueUrl($suggestedSmsQueueUrl);

$counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
$cntStmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM sms_outbox WHERE tenant_id = ? GROUP BY status');
$cntStmt->execute([$tid]);
foreach ($cntStmt->fetchAll() as $cr) {
    $counts[(string) $cr['status']] = (int) $cr['c'];
}

$oldestPendingAt = null;
if (($counts['pending'] ?? 0) > 0) {
    $opStmt = $pdo->prepare("SELECT MIN(created_at) AS t FROM sms_outbox WHERE tenant_id = ? AND status = 'pending'");
    $opStmt->execute([$tid]);
    $oldestPendingAt = $opStmt->fetchColumn();
}
$oldestPendingTs = $oldestPendingAt ? strtotime((string) $oldestPendingAt) : false;
/** Pending rows sitting with no worker activity for at least this long (gateway polls slower). */
$pendingStuckSeconds = 120;
$pendingOldEnough = $oldestPendingTs !== false && ($oldestPendingTs + $pendingStuckSeconds) < time();

$smsWorkerProbe = null;
if ($schemaReady && ($counts['pending'] ?? 0) > 0 && $row && trim((string) ($row['sms_poll_secret'] ?? '')) !== '') {
    $smsWorkerProbe = sms_probe_worker_endpoint(
        $suggestedSmsQueueUrl,
        (string) ($row['slug'] ?? ''),
        (string) $row['sms_poll_secret']
    );
}

$hostLooksLocal = function_exists('sms_http_host_looks_local_dev') && sms_http_host_looks_local_dev();

$recentStmt = $pdo->prepare('
    SELECT id, destination_phone, message_body, status, created_at, error_message, processed_at
    FROM sms_outbox
    WHERE tenant_id = ?
    ORDER BY id DESC
    LIMIT 20
');
$recentStmt->execute([$tid]);
$recentRows = $recentStmt->fetchAll();

$pendingOnlyStuck = ($counts['pending'] ?? 0) > 0
    && ($counts['processing'] ?? 0) === 0
    && $pendingOldEnough;
?>

<?php if (!$hostLooksLocal && defined('SMS_LOCAL_AUTO_SEND') && !SMS_LOCAL_AUTO_SEND): ?>
<div class="alert alert-info d-flex align-items-start gap-2 small mb-3" role="alert">
    <i class="bi bi-cloud-check mt-1 flex-shrink-0"></i>
    <div>
        <strong>Hosted site mode.</strong>
        PHP will not auto-start <code>sms_gateway.py</code> on this server (USB modems are not attached to shared hosting).
        Keep <code>sms_gateway.py</code> running on a <strong>Windows PC or Raspberry Pi</strong> with your SIM800/USB modem, and set
        <code>SMS_QUEUE_URL</code> to the HTTPS worker URL below (must match this site). Use <code>SMS_TENANT_SLUG</code> and <code>SMS_POLL_SECRET</code> from the gateway card.
    </div>
</div>
<?php endif; ?>

<?php if ($smsQueueUrlMismatch): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 small mb-3" role="alert">
    <i class="bi bi-link-45deg mt-1 flex-shrink-0"></i>
    <div>
        <strong>Queue URL may not match this site.</strong>
        This page is at <code class="user-select-all"><?= h($suggestedSmsQueueUrl) ?></code>
        but <code>config/config.php</code> has <code>SMS_LOCAL_QUEUE_URL</code> =
        <code class="user-select-all"><?= h($smsLocalQueueCfg) ?></code>.
        Align host/port or SMS may stay <strong>pending</strong>.
    </div>
</div>
<?php endif; ?>

<?php if ($pendingOnlyStuck): ?>
<div class="alert alert-danger border-danger d-flex align-items-start gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-octagon-fill mt-1 flex-shrink-0"></i>
    <div class="flex-grow-1">
        <strong>Queue is not draining.</strong>
        <?= (int) $counts['pending'] ?> message(s) have stayed <strong>pending</strong> for more than <?= (int) $pendingStuckSeconds ?> seconds with no worker picking them up.
        <?php if ($smsWorkerProbe === true): ?>
            <span class="d-block mt-2 small"><i class="bi bi-check-circle text-success me-1"></i>This server <strong>can reach</strong> the worker URL — the missing piece is almost always a PC/Raspberry Pi that is <strong>not</strong> running <code>sms_gateway.py</code> (or it uses the wrong <code>SMS_QUEUE_URL</code> / secret).</span>
        <?php elseif ($smsWorkerProbe === false): ?>
            <span class="d-block mt-2 small"><i class="bi bi-x-circle text-warning me-1"></i>PHP could <strong>not</strong> confirm the worker URL (firewall, SSL, or wrong path). Fix the URL to match this site, then re-test.</span>
        <?php endif; ?>
        <?php if (!$hostLooksLocal): ?>
            <p class="small mb-0 mt-2">On cloud hosting, the gateway must run <strong>off the server</strong> (office PC with USB modem). Set environment on that machine: <code>SMS_QUEUE_URL=<?= h($suggestedSmsQueueUrl) ?></code> (same string as below).</p>
        <?php else: ?>
            <p class="small mb-0 mt-2">On XAMPP, run <code>python scripts\sms_gateway.py</code> on the PC that has the modem, or use <code>scripts\run_sms_gateway.example.bat</code> after setting COM port and URL.</p>
        <?php endif; ?>
        <hr class="my-2 opacity-50">
        <div class="small text-muted mb-1">Worker endpoint (copy into <code>SMS_QUEUE_URL</code> on the gateway machine):</div>
        <div class="slug-display mb-2"><code class="user-select-all"><?= h($suggestedSmsQueueUrl) ?></code></div>
        <p class="small mb-0">Use <code>SMS_POLL_SECRET</code> and tenant slug from <strong>Gateway API</strong> on this page, plus <code>SMS_SERIAL_PORTS</code> for your modem.</p>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" id="smsSettingsForm">
            <div class="card mb-3">
                <div class="card-header gap-2">
                    <div class="sc-icon" style="background:var(--ac-sky-light);color:var(--ac-sky);">
                        <i class="bi bi-chat-text-fill"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:0.9rem;">Message templates</div>
                        <div style="font-size:0.75rem;color:var(--tx-muted);">IN and OUT scans use different text; leave blank for defaults</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small" for="sms_template_in">Arrival (IN)</label>
                        <textarea class="form-control font-monospace" name="sms_template_in" id="sms_template_in" rows="3"
                            placeholder="<?= h(defaultSmsTemplateIn()) ?>"><?= h((string) ($row['sms_template_in'] ?? '')) ?></textarea>
                        <div class="form-text">Empty = default arrival message.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold small" for="sms_template_out">Dismissal (OUT)</label>
                        <textarea class="form-control font-monospace" name="sms_template_out" id="sms_template_out" rows="3"
                            placeholder="<?= h(defaultSmsTemplateOut()) ?>"><?= h((string) ($row['sms_template_out'] ?? '')) ?></textarea>
                        <div class="form-text">Empty = default dismissal message.</div>
                    </div>
                </div>
            </div>

            <?php if ($hasStaffSmsCols): ?>
            <div class="card mb-3">
                <div class="card-header gap-2">
                    <div class="sc-icon" style="background:var(--ac-sky-light);color:var(--ac-sky);">
                        <i class="bi bi-briefcase-fill"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:0.9rem;">Teachers &amp; employees</div>
                        <div style="font-size:0.75rem;color:var(--tx-muted);">SMS to their own phone on file when they scan at the gate</div>
                    </div>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Teachers and employees receive SMS on their own phone when they scan; add a phone under Users → Teachers / Employees.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small" for="sms_template_staff_in">Staff arrival (IN)</label>
                        <textarea class="form-control font-monospace" name="sms_template_staff_in" id="sms_template_staff_in" rows="3"
                            placeholder="<?= h(defaultSmsTemplateStaffIn()) ?>"><?= h((string) ($row['sms_template_staff_in'] ?? '')) ?></textarea>
                        <div class="form-text">Empty = default staff IN message.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold small" for="sms_template_staff_out">Staff dismissal (OUT)</label>
                        <textarea class="form-control font-monospace" name="sms_template_staff_out" id="sms_template_staff_out" rows="3"
                            placeholder="<?= h(defaultSmsTemplateStaffOut()) ?>"><?= h((string) ($row['sms_template_staff_out'] ?? '')) ?></textarea>
                        <div class="form-text">Empty = default staff OUT message.</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-3 border-primary border-opacity-25">
                <div class="card-header gap-2">
                    <div class="sc-icon" style="background:var(--ac-purple-light);color:var(--ac-purple);">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:0.9rem;">Gateway API</div>
                        <div style="font-size:0.75rem;color:var(--tx-muted);">Paste these into <code>sms_gateway.py</code> / your <code>.bat</code> on the PC with the USB modem</div>
                    </div>
                </div>
                <div class="card-body">
                    <label class="form-label fw-semibold small">Queue endpoint</label>
                    <p class="small text-muted mb-2">Must include <code>http://</code> or <code>https://</code> and match where you open this admin (e.g. if you use <code>localhost</code>, the gateway must too).</p>
                    <div class="d-flex flex-wrap align-items-stretch gap-2 mb-3">
                        <div class="slug-display flex-grow-1 min-w-0">
                            <code class="user-select-all"><?= h($suggestedSmsQueueUrl) ?></code>
                        </div>
                        <button type="button" class="btn btn-outline-secondary flex-shrink-0 sms-copy-btn" data-copy="<?= h($suggestedSmsQueueUrl) ?>" title="Copy URL">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Tenant slug</label>
                            <div class="slug-display"><span class="user-select-all"><?= h((string) ($row['slug'] ?? '')) ?></span></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Poll secret</label>
                            <div class="d-flex flex-wrap align-items-stretch gap-2">
                                <div class="slug-display flex-grow-1 min-w-0">
                                    <span class="user-select-all font-monospace small"><?= h((string) ($row['sms_poll_secret'] ?? '')) ?></span>
                                </div>
                                <button type="button" class="btn btn-outline-secondary flex-shrink-0 sms-copy-btn" data-copy="<?= h((string) ($row['sms_poll_secret'] ?? '')) ?>" title="Copy secret">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="border rounded p-3 mt-3 bg-light bg-opacity-50">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="regenerate_poll_secret" id="regen" value="1">
                            <label class="form-check-label small" for="regen">
                                <span class="text-warning fw-semibold">Regenerate poll secret</span>
                                — update <code>SMS_POLL_SECRET</code> everywhere you run the gateway.
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-save-bar mb-4">
                <p class="mb-0 text-muted small"><i class="bi bi-info-circle me-1"></i>Save after changing templates or regenerating the gateway secret.</p>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check2-circle me-2"></i>Save settings
                </button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header gap-2">
                <div class="sc-icon" style="background:var(--ac-amber-light);color:var(--ac-amber);">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="flex-grow-1">
                    <div style="font-weight:700;font-size:0.9rem;">Queue overview</div>
                    <div style="font-size:0.75rem;color:var(--tx-muted);">Last 20 jobs · same database as gate scans</div>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="stat-card sa-amber mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value" style="font-size:1.35rem;"><?= (int) ($counts['pending'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card sa-sky mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon"><i class="bi bi-arrow-repeat"></i></div>
                                <div>
                                    <div class="stat-label">Processing</div>
                                    <div class="stat-value" style="font-size:1.35rem;"><?= (int) ($counts['processing'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card sa-green mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                                <div>
                                    <div class="stat-label">Sent</div>
                                    <div class="stat-value" style="font-size:1.35rem;"><?= (int) ($counts['sent'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card sa-rose mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon"><i class="bi bi-x-octagon-fill"></i></div>
                                <div>
                                    <div class="stat-label">Failed</div>
                                    <div class="stat-value" style="font-size:1.35rem;"><?= (int) ($counts['failed'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive rounded border" style="max-height: 320px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="sticky-top" style="z-index:2;">
                        <tr>
                            <th class="small">ID</th>
                            <th class="small">To</th>
                            <th class="small">Status</th>
                            <th class="small">Created</th>
                            <th class="small">Note</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentRows): ?>
                            <tr><td colspan="5" class="text-muted small py-4 text-center">No messages yet — scan a student with a parent phone to queue one.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRows as $r): ?>
                            <tr>
                                <td class="small"><?= (int) $r['id'] ?></td>
                                <td class="font-monospace small text-break" style="max-width:7rem;"><?= h((string) $r['destination_phone']) ?></td>
                                <td>
                                    <span class="badge rounded-pill bg-<?= $r['status'] === 'sent' ? 'success' : ($r['status'] === 'failed' ? 'danger' : ($r['status'] === 'processing' ? 'warning text-dark' : 'secondary')) ?>">
                                        <?= h((string) $r['status']) ?>
                                    </span>
                                </td>
                                <td class="small text-nowrap"><?= h((string) $r['created_at']) ?></td>
                                <td class="small text-break"><?= $r['status'] === 'failed' ? h((string) ($r['error_message'] ?? '')) : h(mb_substr((string) $r['message_body'], 0, 80)) ?><?= mb_strlen((string) $r['message_body']) > 80 ? '…' : '' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="accordion mb-3" id="smsHelpAccordion">
            <div class="accordion-item border rounded overflow-hidden">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#smsHelpFlow" aria-expanded="false">
                        <i class="bi bi-info-circle me-2 text-primary"></i><span class="fw-semibold">How parent SMS works</span>
                    </button>
                </h2>
                <div id="smsHelpFlow" class="accordion-collapse collapse" data-bs-parent="#smsHelpAccordion">
                    <div class="accordion-body small text-muted">
                        When a student scans at the gate, the server queues one SMS to the linked parent’s phone.
                        Teachers and employees receive SMS on their own phone when their user record includes a valid phone number.
                        A USB modem worker (e.g. <code>scripts/sms_gateway.py</code>) sends queued messages from the machine connected to the SIM module.
                    </div>
                </div>
            </div>
            <div class="accordion-item border rounded overflow-hidden mt-2">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#smsHelpTrouble" aria-expanded="false">
                        <i class="bi bi-wrench-adjustable me-2 text-secondary"></i><span class="fw-semibold">Troubleshooting checklist</span>
                    </button>
                </h2>
                <div id="smsHelpTrouble" class="accordion-collapse collapse" data-bs-parent="#smsHelpAccordion">
                    <div class="accordion-body small">
                        <ol class="mb-0 ps-3">
                            <li class="mb-2"><strong>Gate message</strong> — After a scan, the gate shows whether SMS was queued or why not.</li>
                            <li class="mb-2"><strong>Same server</strong> — Queue URL must match the PHP site where the gate posts (localhost vs production).</li>
                            <li class="mb-2"><strong>Gateway</strong> — If messages stay pending, keep <code>sms_gateway.py</code> running on the modem PC and check queue credentials.</li>
                            <li class="mb-2"><strong>Credentials</strong> — Use the same <code>SMS_TENANT_SLUG</code> and <code>SMS_POLL_SECRET</code> as on this page.</li>
                            <li class="mb-0"><strong>Failed status</strong> — Modem errors in the table; check COM port, baud (try 9600), SIM PIN, signal.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <a class="btn btn-outline-dark btn-sm w-100" href="<?= h(appUrl('gate.php')) ?>">
            <i class="bi bi-door-open me-1"></i>Open gate scanner
        </a>
    </div>
</div>

<?php endif; ?>
</div>

<script>
(function () {
  document.querySelectorAll('.sms-copy-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const text = btn.getAttribute('data-copy') || '';
      const originalHtml = btn.innerHTML.trim();
      const done = '<i class="bi bi-check2"></i> Copied';
      try {
        await navigator.clipboard.writeText(text);
        btn.innerHTML = done;
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-secondary');
        setTimeout(() => {
          btn.innerHTML = originalHtml;
          btn.classList.remove('btn-success');
          btn.classList.add('btn-outline-secondary');
        }, 2000);
      } catch (e) {
        btn.innerHTML = '<i class="bi bi-x-lg"></i> Failed';
        setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
      }
    });
  });
})();
</script>

<?php renderFooter(); ?>
