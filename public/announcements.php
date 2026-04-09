<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin', 'teacher', 'employee', 'parent']);

$pdo = db();
$tenantId = tenantId();
$role = (string) (currentUser()['role'] ?? '');
$isStaff = in_array($role, ['admin', 'teacher', 'employee'], true);
$isParent = $role === 'parent';
$isAdmin = $role === 'admin';
$hasAudience = dbColumnExists('announcements', 'audience');
$hasAnnImage = dbColumnExists('announcements', 'image_path');

if (!$isStaff && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!$isStaff && isset($_GET['toggle'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaff) {
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $smsParents = $isAdmin && isset($_POST['sms_parents']);
    $smsTeachers = $isAdmin && isset($_POST['sms_teachers']);
    $audience = strtolower(trim((string) ($_POST['audience'] ?? 'all')));
    if (!in_array($audience, ['all', 'staff', 'parents'], true)) {
        $audience = 'all';
    }

    if ($title !== '' && $content !== '') {
        $imagePath = null;
        if ($hasAnnImage) {
            $uploadErr = null;
            $imagePath = saveUploadedAnnouncementImage('announcement_image', $uploadErr);
            if ($uploadErr !== null) {
                flash('error', $uploadErr);
                redirect(appUrl('announcements.php'));
            }
        }

        if ($hasAudience && $hasAnnImage) {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (tenant_id, title, content, image_path, is_active, audience, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tenantId, $title, $content, $imagePath, $isActive, $audience, currentUser()['id'] ?? null]);
        } elseif ($hasAudience) {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (tenant_id, title, content, is_active, audience, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tenantId, $title, $content, $isActive, $audience, currentUser()['id'] ?? null]);
        } elseif ($hasAnnImage) {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (tenant_id, title, content, image_path, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tenantId, $title, $content, $imagePath, $isActive, currentUser()['id'] ?? null]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (tenant_id, title, content, is_active, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tenantId, $title, $content, $isActive, currentUser()['id'] ?? null]);
        }

        $msg = 'Announcement published.';
        if ($smsParents || $smsTeachers) {
            $smsResult = queueAnnouncementSmsBroadcast($pdo, $tenantId, $title, $content, $smsParents, $smsTeachers);
            if ($smsResult['count'] > 0) {
                $msg .= ' SMS queued for ' . $smsResult['count'] . ' number(s).';
            } elseif ($smsResult['reason'] !== null) {
                $msg .= ' SMS not sent: ' . $smsResult['reason'];
            }
        }
        flash('success', $msg);
    }

    redirect(appUrl('announcements.php'));
}

if ($isStaff && isset($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $pdo->prepare("UPDATE announcements SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
    flash('success', 'Announcement status updated.');
    redirect(appUrl('announcements.php'));
}

if ($isStaff) {
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE tenant_id = ? ORDER BY created_at DESC");
    $stmt->execute([$tenantId]);
} elseif ($isParent) {
    if ($hasAudience) {
        $stmt = $pdo->prepare("
            SELECT * FROM announcements
            WHERE tenant_id = ?
              AND is_active = 1
              AND audience IN ('all', 'parents')
            ORDER BY created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM announcements
            WHERE tenant_id = ? AND is_active = 1
            ORDER BY created_at DESC
        ");
    }
    $stmt->execute([$tenantId]);
} else {
    $items = [];
    $stmt = null;
}
$items = $stmt ? $stmt->fetchAll() : [];

/** @param array<string, mixed> $row */
function announcementAudienceLabel(array $row): string
{
    $a = (string) ($row['audience'] ?? 'all');
    return match ($a) {
        'staff' => 'Staff only',
        'parents' => 'Parents only',
        default => 'Everyone',
    };
}

$listColspan = 5 + ($hasAudience ? 1 : 0) + ($hasAnnImage ? 1 : 0);

renderHeader('Announcements');
?>

<?php if ($isStaff): ?>
<div class="card mb-3">
    <div class="card-header">Update Announcements</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2">
            <div class="col-md-3">
                <input class="form-control" name="title" placeholder="Announcement title" required>
            </div>
            <div class="col-md-4">
                <textarea class="form-control" name="content" rows="2" placeholder="Message" required></textarea>
            </div>
            <?php if ($hasAnnImage): ?>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-0">Image <span class="fw-normal">(optional)</span></label>
                <input class="form-control" type="file" name="announcement_image" accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="form-text">JPG, PNG, WEBP, or GIF — max 5MB.</div>
            </div>
            <?php endif; ?>
            <?php if ($hasAudience): ?>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-0">Visible to</label>
                <select class="form-select" name="audience" aria-label="Announcement audience">
                    <option value="all">Everyone (staff + parents)</option>
                    <option value="parents">Parents only</option>
                    <option value="staff">Staff only</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="is_active" id="active" checked>
                    <label class="form-check-label" for="active">Active</label>
                </div>
                <?php if ($isAdmin): ?>
                <div class="border rounded p-2 mt-2 small bg-light">
                    <div class="fw-semibold text-muted mb-1">SMS blast</div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sms_parents" id="sms_parents" value="1">
                        <label class="form-check-label" for="sms_parents">Parents</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="sms_teachers" id="sms_teachers" value="1">
                        <label class="form-check-label" for="sms_teachers">Teachers</label>
                    </div>
                    <div class="form-text mt-1">Uses Parent SMS settings &amp; modem. One SMS per phone (duplicates merged).</div>
                </div>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm mt-2">Publish</button>
            </div>
        </form>
        <?php if (!$hasAudience || !$hasAnnImage): ?>
        <p class="text-muted small mb-0 mt-2">
            <?php if (!$hasAudience): ?>
            Run <code>sql/announcements_audience.sql</code> for audience options.
            <?php endif; ?>
            <?php if (!$hasAnnImage): ?>
            <?= !$hasAudience ? ' ' : '' ?>Run <code>sql/announcements_image.sql</code> to attach images.
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isParent): ?>
<div class="card mb-3">
    <div class="card-header">School announcements</div>
    <div class="card-body">
        <?php if (!$items): ?>
            <p class="text-muted mb-0">No announcements for parents right now.</p>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($items as $item): ?>
                <div class="border rounded p-3">
                    <div class="fw-semibold"><?= h($item['title']) ?></div>
                    <div class="small text-muted mb-2"><?= h((string) $item['created_at']) ?></div>
                    <?php
                    $pimg = $hasAnnImage ? announcementImageUrl($item['image_path'] ?? null) : '';
                    if ($pimg !== ''):
                    ?>
                    <div class="mb-2">
                        <a href="<?= h($pimg) ?>" target="_blank" rel="noopener noreferrer">
                            <img src="<?= h($pimg) ?>" alt="" class="img-fluid rounded border" style="max-height:280px;">
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="mb-0" style="white-space:pre-wrap;"><?= h($item['content']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isStaff): ?>
<div class="card">
    <div class="card-header">Announcement List</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
            <tr>
                <th>Title</th>
                <th>Content</th>
                <?php if ($hasAnnImage): ?>
                <th>Image</th>
                <?php endif; ?>
                <?php if ($hasAudience): ?>
                <th>Audience</th>
                <?php endif; ?>
                <th>Date</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= h($item['title']) ?></td>
                    <td><?= h($item['content']) ?></td>
                    <?php if ($hasAnnImage): ?>
                    <td class="text-center">
                        <?php
                        $timg = announcementImageUrl($item['image_path'] ?? null);
                        if ($timg !== ''):
                        ?>
                        <a href="<?= h($timg) ?>" target="_blank" rel="noopener noreferrer" title="View full image">
                            <img src="<?= h($timg) ?>" alt="" class="rounded border" style="height:44px;width:64px;object-fit:cover;">
                        </a>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($hasAudience): ?>
                    <td><?= h(announcementAudienceLabel($item)) ?></td>
                    <?php endif; ?>
                    <td><?= h((string) $item['created_at']) ?></td>
                    <td><?= h(((int) $item['is_active'] === 1) ? 'Active' : 'Inactive') ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= h(appUrl('announcements.php', ['toggle' => $item['id']])) ?>">
                            Toggle
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="<?= h((string) $listColspan) ?>" class="text-center text-muted">No announcements yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
