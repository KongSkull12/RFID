<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$gateLabel = trim((string) ($_GET['gate'] ?? 'Gate 1'));
if ($gateLabel === '') {
    $gateLabel = 'Gate 1';
}
$tenant = currentTenant();
$bgUrl = trim((string) ($tenant['background_url'] ?? ''));
$schoolLogoUrl = trim((string) ($tenant['logo_url'] ?? ''));
$companyLogoUrl = trim((string) ($tenant['company_logo_url'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RFID Scanner - <?= h(tenantName()) ?> - <?= h($gateLabel) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            color: #fff;
            background:
                linear-gradient(rgba(8, 22, 34, 0.45), rgba(8, 22, 34, 0.45)),
                <?php if ($bgUrl !== ''): ?>
                url('<?= h($bgUrl) ?>'),
                <?php endif; ?>
                radial-gradient(circle at top left, #2e5e8a 0%, #0f3f58 35%, #0c2f44 100%);
            background-size: cover;
            background-position: center;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .scan-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 1rem 1.25rem;
            position: relative;
        }
        .overlay {
            width: min(1080px, 100%);
            border-radius: 0;
            background: transparent;
            border: 0;
            padding: 1.35rem;
            box-shadow: none;
            backdrop-filter: none;
        }
        .clock {
            position: absolute;
            top: 14px;
            right: 24px;
            font-weight: 700;
            font-size: clamp(1.1rem, 2.2vw, 2.5rem);
            text-shadow: 0 2px 10px rgba(0,0,0,.5);
        }
        .school-name {
            font-size: clamp(2rem, 4.4vw, 4.2rem);
            font-weight: 900;
            line-height: 1.13;
            margin: 0.1rem 0 0.6rem;
            text-transform: uppercase;
            text-shadow: 0 3px 12px rgba(0,0,0,.45);
            letter-spacing: 0.02em;
        }
        .scan-status {
            display: inline-block;
            padding: 0.5rem 1.15rem;
            border-radius: 999px;
            font-size: clamp(1rem, 1.8vw, 1.2rem);
            font-weight: 800;
            letter-spacing: 0.04em;
            background: #1f3dff;
            border: 1px solid #1f3dff;
            margin-bottom: 1rem;
            text-transform: uppercase;
        }
        .status-ok { background: #198754; border-color: #198754; }
        .status-warn { background: #dc3545; border-color: #dc3545; }
        .scan-result {
            display: none;
            width: min(860px, 100%);
            margin: 0 auto 1.1rem;
            text-align: left;
        }
        .scan-result.show { display: block; }
        .scan-result.show .scan-result-card {
            animation: resultEnter .32s cubic-bezier(.2,.8,.2,1);
        }
        .scan-result-card {
            display: flex;
            gap: 1.15rem;
            align-items: stretch;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            color: #0f172a;
            border: 1px solid #dde7f4;
            border-radius: 20px;
            padding: 1.05rem;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.26), 0 2px 0 rgba(255,255,255,.7) inset;
            position: relative;
            overflow: hidden;
        }
        .scan-result-card::after {
            content: "";
            position: absolute;
            left: 16px;
            right: 16px;
            bottom: 8px;
            height: 4px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(56,189,248,.9), rgba(59,130,246,.95), rgba(45,212,191,.9));
            opacity: .85;
            box-shadow: 0 0 16px rgba(59,130,246,.55);
        }
        .scan-left {
            width: 190px;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex-shrink: 0;
        }
        .scan-right {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .scan-type-row {
            margin-bottom: 0.5rem;
        }
        .scan-type-badge {
            display: inline-block;
            background: #0f172a;
            color: #fff;
            border: 1px solid #0f172a;
            padding: 0.38rem 0.78rem;
            border-radius: 10px;
            font-size: 0.98rem;
            font-weight: 800;
            letter-spacing: 0.035em;
        }
        .scan-type-badge.type-in {
            background: #0f4fbe;
            border-color: #0f4fbe;
        }
        .scan-type-badge.type-out {
            background: #0f172a;
            border-color: #0f172a;
        }
        .scan-photo {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 14px;
            border: 1px solid #d7e3f4;
            background: #f8fafc;
        }
        .result-name { font-size: clamp(1.62rem, 2.8vw, 2.35rem); font-weight: 800; line-height: 1.06; letter-spacing: 0.01em; }
        .result-meta { margin-top: .36rem; font-size: 1.05rem; opacity: .9; color: #3b4658; font-weight: 600; }
        .scan-timing {
            margin-top: 0.9rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .pill, .result-role {
            min-width: 140px;
            border-radius: 999px;
            background: #f6f9ff;
            color: #0f172a;
            border: 1px solid #d8e3f1;
            padding: .43rem .8rem;
            font-weight: 800;
            text-align: center;
            font-size: .88rem;
            letter-spacing: 0.01em;
        }
        .result-role {
            background: linear-gradient(180deg, #f4f8ff 0%, #eaf2ff 100%);
        }
        @keyframes resultEnter {
            from {
                opacity: 0;
                transform: translateY(16px) scale(.985);
                filter: blur(1px);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }
        .logo-company {
            position: absolute;
            bottom: 16px;
            right: 16px;
            text-align: right;
            color: rgba(255,255,255,.95);
            font-size: .8rem;
        }
        .logo-company img {
            max-height: 42px;
            max-width: 160px;
            display: block;
            margin-left: auto;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,.4));
        }
        .gate-subtitle {
            font-size: clamp(0.95rem, 1.4vw, 1.2rem);
            font-weight: 800;
            text-shadow: 0 2px 8px rgba(0,0,0,.45);
        }
        #statusText {
            font-size: clamp(1.15rem, 2.1vw, 1.5rem) !important;
            font-weight: 800;
            text-shadow: 0 2px 8px rgba(0,0,0,.45);
        }
        @media (max-width: 768px) {
            .scan-result-card {
                flex-direction: column;
            }
            .scan-left {
                width: 100%;
            align-items: center;
            }
            .scan-photo {
            max-width: 240px;
            height: 220px;
            }
        }
    </style>
</head>
<body>
<div class="scan-wrap">
    <div id="clockNow" class="clock"></div>
    <div class="overlay">
        <p class="mb-1 text-uppercase fw-bold">Welcome</p>
        <?php if ($schoolLogoUrl !== ''): ?>
            <img src="<?= h(userPhotoUrl($schoolLogoUrl)) ?>" alt="School Logo" style="max-height: 95px; margin-bottom: 0.45rem;">
        <?php endif; ?>
        <h1 class="school-name"><?= h(tenantName()) ?></h1>
        <p class="mb-2 gate-subtitle">Scanner location: <strong><?= h($gateLabel) ?></strong></p>

        <div id="scanResult" class="scan-result">
            <div class="scan-result-card">
                <div class="scan-left">
                    <img id="scanPhoto" class="scan-photo" src="<?= h(BASE_URL . '/assets/default-avatar.svg') ?>" alt="Scanned person">
                    <div class="result-role" id="resultRolePill">--</div>
                </div>
                <div class="scan-right">
                    <div class="scan-type-row"><span id="scanTypeBadge" class="scan-type-badge">TIME-IN</span></div>
                    <div id="resultName" class="result-name">Student Name</div>
                    <div id="resultMeta" class="result-meta">Role / Grade / Section</div>
                    <div class="scan-timing">
                        <div class="pill" id="resultTimePill">--:-- --</div>
                        <div class="pill" id="resultDatePill">--</div>
                    </div>
                </div>
            </div>
        </div>
        <div id="statusTag" class="scan-status mt-2">Waiting to scan</div>
        <div id="statusText" class="h5 mb-1">Tap RFID card to proceed.</div>
        <div id="smsHint" class="small mt-2 px-2" style="display:none;max-width:min(720px,100%);margin-left:auto;margin-right:auto;line-height:1.35;"></div>
    </div>
    <?php if ($companyLogoUrl !== ''): ?>
        <div class="logo-company">
            Powered by
            <img src="<?= h(userPhotoUrl($companyLogoUrl)) ?>" alt="Company Logo">
        </div>
    <?php endif; ?>
</div>

<script>
const DEFAULT_AVATAR = <?= json_encode(BASE_URL . '/assets/default-avatar.svg', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const statusTag = document.getElementById('statusTag');
const statusText = document.getElementById('statusText');
const scanResult = document.getElementById('scanResult');
const scanPhoto = document.getElementById('scanPhoto');
const resultName = document.getElementById('resultName');
const resultMeta = document.getElementById('resultMeta');
const scanTypeBadge = document.getElementById('scanTypeBadge');
const resultTimePill = document.getElementById('resultTimePill');
const resultDatePill = document.getElementById('resultDatePill');
const resultRolePill = document.getElementById('resultRolePill');
const clockNow = document.getElementById('clockNow');
const gateLabel = <?= json_encode($gateLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const endpoint = <?= json_encode(appUrl('rfid_scan.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const tenantSlug = <?= json_encode(tenantSlug(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const smsLocalAutoSend = <?= (defined('SMS_LOCAL_AUTO_SEND') && SMS_LOCAL_AUTO_SEND) ? 'true' : 'false' ?>;
const smsHintEl = document.getElementById('smsHint');

function setSmsHint(data) {
    if (!smsHintEl) return;
    smsHintEl.style.display = 'none';
    smsHintEl.textContent = '';
    if (!data || !data.sms) return;
    if (data.sms.queued) {
        const auto = data.sms.local_auto_send === true || (data.sms.local_auto_send === undefined && smsLocalAutoSend);
        smsHintEl.textContent = auto
            ? 'Parent SMS — modem worker ran with this scan (see Admin → Parent SMS if still pending).'
            : 'Parent SMS queued — keep sms_gateway.py running on the PC with the USB modem.';
        smsHintEl.className = 'small mt-2 px-2 text-white';
        smsHintEl.style.display = 'block';
    } else if (data.sms.hint) {
        smsHintEl.textContent = 'SMS not queued: ' + data.sms.hint;
        smsHintEl.className = 'small mt-2 px-2 text-warning';
        smsHintEl.style.display = 'block';
    }
}

let buffer = '';
let clearTimer = null;
let isSubmitting = false;
let lastSubmittedUid = '';
let lastSubmittedAtMs = 0;
const SAME_TAG_INTERVAL_MS = 3000;

function formatClock() {
    const now = new Date();
    clockNow.textContent = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
}
setInterval(formatClock, 1000);
formatClock();

function setStatus(message, state) {
    statusText.textContent = message;
    statusTag.classList.remove('status-ok', 'status-warn');
    if (state === 'ok') statusTag.classList.add('status-ok');
    if (state === 'warn') statusTag.classList.add('status-warn');
}

function showResult(profile, scanType, remarks) {
    scanPhoto.src = profile.photo_url || DEFAULT_AVATAR;
    resultName.textContent = profile.name || 'Unknown User';
    const cleanRole = profile.role || 'User';
    const academe = [profile.course, profile.grade, profile.section].filter(Boolean).join(' / ');
    resultMeta.textContent = cleanRole + (academe ? (' - ' + academe) : '');
    scanTypeBadge.textContent = scanType === 'OUT' ? 'TIME-OUT' : 'TIME-IN';
    scanTypeBadge.classList.remove('type-in', 'type-out');
    scanTypeBadge.classList.add(scanType === 'OUT' ? 'type-out' : 'type-in');
    resultRolePill.textContent = cleanRole + (remarks ? (' - ' + remarks) : '');

    const now = new Date();
    resultTimePill.textContent = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    resultDatePill.textContent = now.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' });

    scanResult.classList.add('show');
}

function hideResult() {
    scanResult.classList.remove('show');
    scanPhoto.src = DEFAULT_AVATAR;
    resultName.textContent = 'Student Name';
    resultMeta.textContent = 'Role / Grade / Section';
    scanTypeBadge.textContent = 'TIME-IN';
    scanTypeBadge.classList.remove('type-out');
    scanTypeBadge.classList.add('type-in');
    resultTimePill.textContent = '--:-- --';
    resultDatePill.textContent = '--';
    resultRolePill.textContent = '--';
}

function resetWaiting() {
    statusTag.textContent = 'Waiting to scan';
    setStatus('Tap RFID card to proceed.', 'idle');
    setSmsHint(null);
    hideResult();
}

async function submitUid(uid) {
    const cleanUid = String(uid || '').trim();
    if (!cleanUid || isSubmitting) return;
    const nowMs = Date.now();
    if (lastSubmittedUid === cleanUid && (nowMs - lastSubmittedAtMs) < SAME_TAG_INTERVAL_MS) {
        const remaining = Math.ceil((SAME_TAG_INTERVAL_MS - (nowMs - lastSubmittedAtMs)) / 1000);
        statusTag.textContent = 'Please wait';
        setStatus('Same tag cooldown: wait ' + remaining + 's before scanning again.', 'warn');
        setTimeout(() => resetWaiting(), 900);
        return;
    }
    lastSubmittedUid = cleanUid;
    lastSubmittedAtMs = nowMs;

    isSubmitting = true;
    statusTag.textContent = 'Scanning...';
    setStatus('Processing card...', 'idle');

    const formData = new FormData();
    formData.append('uid', cleanUid);
    formData.append('device_name', gateLabel);
    formData.append('tenant', tenantSlug);

    try {
        const res = await fetch(endpoint, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.ok) {
            statusTag.textContent = 'Access granted';
            const message = (data.user || 'Unknown') + ' - ' + (data.scan_type || '') + (data.remarks ? (' (' + data.remarks + ')') : '');
            setStatus(message, 'ok');
            setSmsHint(data);
            showResult(data.user_profile || {}, data.scan_type || 'IN', data.remarks || '-');
            setTimeout(() => resetWaiting(), 3800);
        } else {
            statusTag.textContent = 'Access denied';
            setStatus(data.message || 'Scan failed', 'warn');
            setTimeout(() => resetWaiting(), 1500);
        }
    } catch (e) {
        statusTag.textContent = 'Connection error';
        setStatus('Cannot reach scanner endpoint.', 'warn');
        setTimeout(() => resetWaiting(), 1500);
    } finally {
        isSubmitting = false;
    }
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        if (buffer.length > 0) submitUid(buffer);
        buffer = '';
        return;
    }
    if (e.key.length === 1) {
        buffer += e.key;
        if (clearTimer) clearTimeout(clearTimer);
        clearTimer = setTimeout(() => { buffer = ''; }, 1200);
    }
});

hideResult();
</script>
</body>
</html>
