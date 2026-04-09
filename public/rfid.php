<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
requireLogin(['admin']);

$pdo = db();
$tenantId = tenantId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_rfid') {
        $uid = trim((string) ($_POST['uid'] ?? ''));
        $label = trim((string) ($_POST['label_name'] ?? ''));
        if (!tenantCanAddCards($pdo)) {
            flash('error', 'Plan limit reached: cannot add more RFID cards for this tenant.');
        } elseif ($uid !== '') {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO rfid_cards (tenant_id, uid, label_name, created_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$tenantId, $uid, $label ?: null, app_now_sql()]);
            flash('success', 'RFID added to list.');
        }
    }

    if ($action === 'assign_rfid') {
        $cardId = (int) ($_POST['card_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($cardId > 0 && $userId > 0) {
            $pdo->prepare("UPDATE rfid_cards SET user_id = ?, is_assigned = 1 WHERE id = ? AND tenant_id = ?")
                ->execute([$userId, $cardId, $tenantId]);
            flash('success', 'RFID assigned successfully.');
        }
    }

    if ($action === 'remove_assignment') {
        $cardId = (int) ($_POST['card_id'] ?? 0);
        if ($cardId > 0) {
            $pdo->prepare("UPDATE rfid_cards SET user_id = NULL, is_assigned = 0 WHERE id = ? AND tenant_id = ?")
                ->execute([$cardId, $tenantId]);
            flash('success', 'RFID assignment removed.');
        }
    }

    redirect(appUrl('rfid.php'));
}

$stmt = $pdo->prepare("
    SELECT rc.*, u.first_name, u.last_name, u.role
    FROM rfid_cards rc
    LEFT JOIN users u ON u.id = rc.user_id AND u.tenant_id = rc.tenant_id
    WHERE rc.tenant_id = ?
    ORDER BY rc.created_at DESC
");
$stmt->execute([$tenantId]);
$cards = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, role
    FROM users
    WHERE role IN ('student', 'teacher', 'employee') AND tenant_id = ?
    ORDER BY last_name, first_name
");
$stmt->execute([$tenantId]);
$users = $stmt->fetchAll();

renderHeader('RFID');
?>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Add List of RFID</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_rfid">
                    <div class="col-12">
                        <input class="form-control" name="uid" placeholder="RFID UID (e.g. A1B2C3D4)" required>
                    </div>
                    <div class="col-12">
                        <input class="form-control" name="label_name" placeholder="Card label (optional)">
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary">Add RFID</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">Assign RFID to User</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="assign_rfid">
                    <div class="col-md-6">
                        <select name="card_id" class="form-select" required>
                            <option value="">Select unassigned card</option>
                            <?php foreach ($cards as $card): ?>
                                <?php if ((int) $card['is_assigned'] === 0): ?>
                                    <option value="<?= h((string) $card['id']) ?>">
                                        <?= h($card['uid'] . ($card['label_name'] ? ' - ' . $card['label_name'] : '')) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <select name="user_id" class="form-select" required>
                            <option value="">Select user</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= h((string) $user['id']) ?>">
                                    <?= h($user['last_name'] . ', ' . $user['first_name'] . ' (' . roleLabel($user['role']) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-outline-primary">Assign RFID</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">RFID List</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                <tr>
                    <th>UID</th>
                    <th>Label</th>
                    <th>Assigned User</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cards as $card): ?>
                    <tr>
                        <td><?= h($card['uid']) ?></td>
                        <td><?= h($card['label_name'] ?: '-') ?></td>
                        <td><?= h(isset($card['last_name']) ? $card['last_name'] . ', ' . $card['first_name'] : '-') ?></td>
                        <td><?= h($card['role'] ? roleLabel($card['role']) : '-') ?></td>
                        <td><?= h(((int) $card['is_assigned'] === 1) ? 'Assigned' : 'Unassigned') ?></td>
                        <td>
                            <?php if ((int) $card['is_assigned'] === 1): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="remove_assignment">
                                    <input type="hidden" name="card_id" value="<?= h((string) $card['id']) ?>">
                                    <button class="btn btn-sm btn-danger">Unassign</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$cards): ?>
                    <tr><td colspan="6" class="text-center text-muted">No RFID cards yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
