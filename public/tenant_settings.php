<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin']);

$pdo = db();
$tenant = currentTenant();
$hasCompanyLogoColumn = dbColumnExists('tenants', 'company_logo_url');
$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'change_password') {
    $currentPw = (string) ($_POST['current_password'] ?? '');
    $newPw     = (string) ($_POST['new_password'] ?? '');
    $newPw2    = (string) ($_POST['new_password_confirm'] ?? '');
    $uid       = (int) ($me['id'] ?? 0);

    if ($uid <= 0) {
        flash('error', 'Session error. Please sign in again.');
    } elseif ($newPw !== $newPw2 || strlen($newPw) < 8) {
        flash('error', 'New password and confirmation must match and be at least 8 characters.');
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$uid, tenantId()]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($currentPw, (string) $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
                ->execute([password_hash($newPw, PASSWORD_DEFAULT), $uid, tenantId()]);
            flash('success', 'Your password was updated successfully.');
        }
    }
    redirect(appUrl('tenant_settings.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolName = trim((string) ($_POST['school_name'] ?? ''));
    $logoUrl = trim((string) ($_POST['logo_url'] ?? ''));
    $companyLogoUrl = trim((string) ($_POST['company_logo_url'] ?? ''));
    $bgUrl = trim((string) ($_POST['background_url'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $schoolLogoErr = null;
    $companyLogoErr = null;
    $backgroundErr = null;
    $schoolLogoUpload = saveUploadedBrandAsset('school_logo_file', $schoolLogoErr);
    $companyLogoUpload = saveUploadedBrandAsset('company_logo_file', $companyLogoErr);
    $backgroundUpload = saveUploadedBrandAsset('background_file', $backgroundErr);

    if ($schoolLogoErr || $companyLogoErr || $backgroundErr) {
        $errors = array_filter([$schoolLogoErr, $companyLogoErr, $backgroundErr]);
        flash('error', implode(' ', $errors));
    }

    if ($schoolName !== '') {
        $newSchoolLogo = $schoolLogoUpload ?? ($logoUrl !== '' ? $logoUrl : null);
        $newCompanyLogo = $companyLogoUpload ?? ($companyLogoUrl !== '' ? $companyLogoUrl : null);
        $newBackground = $backgroundUpload ?? ($bgUrl !== '' ? $bgUrl : null);
        if ($hasCompanyLogoColumn) {
            $stmt = $pdo->prepare("
                UPDATE tenants
                SET school_name = ?, logo_url = ?, company_logo_url = ?, background_url = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $schoolName,
                $newSchoolLogo,
                $newCompanyLogo,
                $newBackground,
                $status,
                (int) $tenant['id'],
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE tenants
                SET school_name = ?, logo_url = ?, background_url = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $schoolName,
                $newSchoolLogo,
                $newBackground,
                $status,
                (int) $tenant['id'],
            ]);
            flash('error', 'Company logo column is missing. Import sql/saas_upgrade.sql to enable it.');
        }
        bootstrapTenant();
        flash('success', 'School settings saved successfully.');
    }

    redirect(appUrl('tenant_settings.php'));
}

renderHeader('School Settings');
?>

<style>
.slug-display {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface-3); border: 1.5px solid var(--border);
    border-radius: var(--r-sm); padding: 9px 13px;
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 0.85rem; color: var(--tx-secondary); min-height: 42px;
}
.asset-card {
    border: 1.5px solid var(--border); border-radius: var(--r-md);
    overflow: hidden; height: 100%; display: flex; flex-direction: column;
    transition: border-color var(--ease), box-shadow var(--ease);
    background: var(--surface);
}
.asset-card:hover { border-color: #bfdbfe; box-shadow: 0 0 0 3px rgba(59,130,246,0.08); }
.asset-card .asset-preview {
    background: var(--surface-3); min-height: 130px;
    display: flex; align-items: center; justify-content: center;
    border-bottom: 1px solid var(--border); position: relative; overflow: hidden;
}
.asset-card .asset-preview img.preview-img { max-width: 100%; max-height: 110px; object-fit: contain; border-radius: 6px; }
.asset-card .asset-preview.bg-type img.preview-img { width: 100%; height: 110px; object-fit: cover; border-radius: 0; max-height: none; }
.asset-card .asset-preview .no-img { display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--tx-muted); }
.asset-card .asset-preview .no-img i { font-size: 2rem; }
.asset-card .asset-preview .no-img span { font-size: 0.75rem; }
.asset-card .asset-body { padding: 14px 16px; flex: 1; display: flex; flex-direction: column; gap: 10px; }
.asset-card .asset-title { font-weight: 700; font-size: 0.83rem; color: var(--tx-primary); display: flex; align-items: center; gap: 7px; }
.asset-card .asset-title .asset-badge {
    font-size: 0.7rem; font-weight: 500; color: var(--tx-muted);
    background: var(--surface-3); border-radius: 20px; padding: 1px 8px; border: 1px solid var(--border);
}
.asset-field-group label.field-lbl {
    font-size: 0.72rem; font-weight: 700; color: var(--tx-muted);
    text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: block;
}
.plan-stat-box {
    background: var(--surface-2); border: 1.5px solid var(--border);
    border-radius: var(--r-md); padding: 18px 14px; text-align: center;
}
.plan-stat-box .psb-val { font-size: 1.45rem; font-weight: 800; line-height: 1; margin-bottom: 5px; color: var(--tx-primary); }
.plan-stat-box .psb-lbl { font-size: 0.72rem; color: var(--tx-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
.settings-save-bar {
    background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--r-md);
    padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;
    box-shadow: var(--shadow-sm);
}
.sc-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
</style>

<!-- Page header -->
<div class="page-header mb-4">
    <div class="page-header-left">
        <div class="page-header-icon"><i class="bi bi-gear-wide-connected"></i></div>
        <div>
            <h5>School Settings</h5>
            <p>Manage your school's branding, identity, and account information</p>
        </div>
    </div>
</div>

<?php if (!$hasCompanyLogoColumn): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
    <div>
        <strong>Database update needed.</strong> Company logo is disabled because the <code>company_logo_url</code> column is missing.
        Import <code>sql/saas_upgrade.sql</code> in phpMyAdmin to enable it.
    </div>
</div>
<?php endif; ?>

    <!-- ── Administrator password (separate form — not nested in school settings upload form) ── -->
    <div class="card mb-3">
        <div class="card-header gap-2">
            <div class="sc-icon" style="background:rgba(245,158,11,0.15);color:#d97706;">
                <i class="bi bi-key-fill"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:0.9rem;">Administrator password</div>
                <div style="font-size:0.75rem;color:var(--tx-muted);">Change the password for your own admin account (does not affect other users)</div>
            </div>
        </div>
        <div class="card-body py-4">
            <form method="post" class="row g-3 align-items-end mb-0" autocomplete="off">
                <input type="hidden" name="form" value="change_password">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold small">Signed in as</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" class="form-control bg-light" readonly value="<?= h((string) ($me['username'] ?? '')) ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold small" for="pw-current">Current password</label>
                    <input id="pw-current" name="current_password" type="password" class="form-control" required autocomplete="current-password" minlength="1">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold small" for="pw-new">New password</label>
                    <input id="pw-new" name="new_password" type="password" class="form-control" required autocomplete="new-password" minlength="8" placeholder="At least 8 characters">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold small" for="pw-new2">Confirm new password</label>
                    <input id="pw-new2" name="new_password_confirm" type="password" class="form-control" required autocomplete="new-password" minlength="8">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-shield-lock me-2"></i>Update password
                    </button>
                </div>
            </form>
        </div>
    </div>

<form method="post" enctype="multipart/form-data">

    <!-- ── School Identity ──────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header gap-2">
            <div class="sc-icon" style="background:var(--ac-blue-light);color:var(--ac-blue);">
                <i class="bi bi-building-fill"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:0.9rem;">School Identity</div>
                <div style="font-size:0.75rem;color:var(--tx-muted);">Your school's name, unique slug, and operational status</div>
            </div>
        </div>
        <div class="card-body py-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">School Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                        <input name="school_name" class="form-control" value="<?= h((string) $tenant['name']) ?>" placeholder="e.g. A.O. Floirendo National High School" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">
                        Tenant Slug
                        <span class="text-muted fw-normal">(read-only)</span>
                    </label>
                    <div class="slug-display">
                        <i class="bi bi-link-45deg"></i>
                        <span><?= h((string) $tenant['slug']) ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Tenant Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= selected($tenant['status'] ?? 'active', 'active') ?>>Active</option>
                        <option value="inactive" <?= selected($tenant['status'] ?? 'active', 'inactive') ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Branding Assets ──────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header gap-2">
            <div class="sc-icon" style="background:var(--ac-purple-light);color:var(--ac-purple);">
                <i class="bi bi-palette-fill"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:0.9rem;">Branding Assets</div>
                <div style="font-size:0.75rem;color:var(--tx-muted);">Upload logos and gate background — supports JPG, PNG, WEBP (max 8 MB)</div>
            </div>
        </div>
        <div class="card-body py-4">
            <div class="row g-3">

                <!-- School Logo -->
                <div class="col-md-4">
                    <div class="asset-card">
                        <div class="asset-preview" id="previewWrapSchool">
                            <?php $hasSchoolLogo = !empty($tenant['logo_url']); ?>
                            <div class="no-img <?= $hasSchoolLogo ? 'd-none' : '' ?>" id="noImgSchool">
                                <i class="bi bi-shield-fill-check"></i>
                                <span>No logo uploaded</span>
                            </div>
                            <img src="<?= h(userPhotoUrl($hasSchoolLogo ? $tenant['logo_url'] : null)) ?>"
                                 alt="School logo"
                                 id="prevSchoolLogo"
                                 class="preview-img <?= $hasSchoolLogo ? '' : 'd-none' ?>">
                        </div>
                        <div class="asset-body">
                            <div class="asset-title">
                                <i class="bi bi-shield-fill text-primary"></i>
                                School Logo
                                <span class="asset-badge">optional</span>
                            </div>
                            <div class="asset-field-group">
                                <label class="field-lbl">Upload file</label>
                                <input name="school_logo_file" type="file" class="form-control form-control-sm"
                                       accept="image/*"
                                       data-preview-img="prevSchoolLogo"
                                       data-preview-no="noImgSchool">
                            </div>
                            <div class="asset-field-group">
                                <label class="field-lbl">Or paste URL</label>
                                <input name="logo_url" class="form-control form-control-sm"
                                       value="<?= h((string) ($tenant['logo_url'] ?? '')) ?>"
                                       placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Company / Partner Logo -->
                <div class="col-md-4">
                    <div class="asset-card <?= $hasCompanyLogoColumn ? '' : 'opacity-50' ?>">
                        <div class="asset-preview">
                            <?php $hasCompanyLogo = !empty($tenant['company_logo_url']); ?>
                            <div class="no-img <?= $hasCompanyLogo ? 'd-none' : '' ?>" id="noImgCompany">
                                <i class="bi bi-briefcase-fill"></i>
                                <span>No logo uploaded</span>
                            </div>
                            <img src="<?= h(userPhotoUrl($hasCompanyLogo ? ($tenant['company_logo_url'] ?? null) : null)) ?>"
                                 alt="Company logo"
                                 id="prevCompanyLogo"
                                 class="preview-img <?= $hasCompanyLogo ? '' : 'd-none' ?>">
                        </div>
                        <div class="asset-body">
                            <div class="asset-title">
                                <i class="bi bi-briefcase-fill text-success"></i>
                                Company / Partner Logo
                                <span class="asset-badge">optional</span>
                            </div>
                            <?php if (!$hasCompanyLogoColumn): ?>
                            <p class="text-muted small mb-0"><i class="bi bi-lock-fill me-1"></i>DB update required to enable</p>
                            <?php else: ?>
                            <div class="asset-field-group">
                                <label class="field-lbl">Upload file</label>
                                <input name="company_logo_file" type="file" class="form-control form-control-sm"
                                       accept="image/*"
                                       data-preview-img="prevCompanyLogo"
                                       data-preview-no="noImgCompany">
                            </div>
                            <div class="asset-field-group">
                                <label class="field-lbl">Or paste URL</label>
                                <input name="company_logo_url" class="form-control form-control-sm"
                                       value="<?= h((string) ($tenant['company_logo_url'] ?? '')) ?>"
                                       placeholder="https://...">
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Gate Background -->
                <div class="col-md-4">
                    <div class="asset-card">
                        <div class="asset-preview bg-type" id="previewWrapBg">
                            <?php $hasBg = !empty($tenant['background_url']); ?>
                            <div class="no-img <?= $hasBg ? 'd-none' : '' ?>" id="noImgBg">
                                <i class="bi bi-image-fill"></i>
                                <span>No background uploaded</span>
                            </div>
                            <img src="<?= h(userPhotoUrl($hasBg ? $tenant['background_url'] : null)) ?>"
                                 alt="Gate background"
                                 id="prevBackground"
                                 class="preview-img <?= $hasBg ? '' : 'd-none' ?>">
                        </div>
                        <div class="asset-body">
                            <div class="asset-title">
                                <i class="bi bi-card-image text-warning"></i>
                                Gate Background
                                <span class="asset-badge">optional</span>
                            </div>
                            <div class="asset-field-group">
                                <label class="field-lbl">Upload file</label>
                                <input name="background_file" type="file" class="form-control form-control-sm"
                                       accept="image/*"
                                       data-preview-img="prevBackground"
                                       data-preview-no="noImgBg">
                            </div>
                            <div class="asset-field-group">
                                <label class="field-lbl">Or paste URL</label>
                                <input name="background_url" class="form-control form-control-sm"
                                       value="<?= h((string) ($tenant['background_url'] ?? '')) ?>"
                                       placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Plan & Account Limits (read-only) ───────────────────── -->
    <?php
    $billing     = strtolower((string) ($tenant['billing_status'] ?? 'trial'));
    $billingColor = match ($billing) { 'active' => 'success', 'trial' => 'warning', default => 'danger' };
    ?>
    <div class="card mb-3">
        <div class="card-header gap-2">
            <div class="sc-icon" style="background:var(--ac-teal-light);color:var(--ac-teal);">
                <i class="bi bi-bar-chart-fill"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:0.9rem;">Plan &amp; Account Limits</div>
                <div style="font-size:0.75rem;color:var(--tx-muted);">Your current subscription — contact the platform admin to upgrade</div>
            </div>
        </div>
        <div class="card-body py-4">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="plan-stat-box">
                        <div class="psb-val text-primary"><?= h((string) ($tenant['plan_name'] ?? 'Starter')) ?></div>
                        <div class="psb-lbl">Current Plan</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="plan-stat-box">
                        <div class="psb-val text-success"><?= h((string) ($tenant['max_users'] ?? 100)) ?></div>
                        <div class="psb-lbl">Max Users</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="plan-stat-box">
                        <div class="psb-val" style="color:#6366f1;"><?= h((string) ($tenant['max_cards'] ?? 300)) ?></div>
                        <div class="psb-lbl">Max RFID Cards</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="plan-stat-box">
                        <div class="psb-val text-<?= $billingColor ?>"><?= ucfirst(h($billing)) ?></div>
                        <div class="psb-lbl">Billing Status</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Save bar ─────────────────────────────────────────────── -->
    <div class="settings-save-bar">
        <p class="mb-0 text-muted small"><i class="bi bi-info-circle me-1"></i>Changes to branding take effect immediately after saving.</p>
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-check2-circle me-2"></i>Save Settings
        </button>
    </div>

</form>

<script>
/* Live preview for brand asset file inputs */
document.querySelectorAll('input[type="file"][data-preview-img]').forEach(input => {
    input.addEventListener('change', () => {
        const imgEl   = document.getElementById(input.dataset.previewImg);
        const noImgEl = input.dataset.previewNo ? document.getElementById(input.dataset.previewNo) : null;
        const file    = input.files && input.files[0] ? input.files[0] : null;
        if (!imgEl || !file) return;
        const reader = new FileReader();
        reader.onload = e => {
            imgEl.src = e.target.result;
            imgEl.classList.remove('d-none');
            if (noImgEl) noImgEl.classList.add('d-none');
        };
        reader.readAsDataURL(file);
    });
});
</script>

<?php renderFooter(); ?>
