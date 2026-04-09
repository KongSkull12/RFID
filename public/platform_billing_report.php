<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
requirePlatformLogin();

$pdo = db();
runSubscriptionAutomation($pdo);

$rows = $pdo->query("
    SELECT
        t.id,
        t.slug,
        t.school_name,
        t.status,
        t.plan_name,
        t.max_users,
        t.max_cards,
        t.billing_status,
        t.trial_ends_at,
        t.created_at,
        (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
        (SELECT COUNT(*) FROM rfid_cards c WHERE c.tenant_id = t.id) AS card_count,
        (SELECT COUNT(*) FROM attendance_logs l WHERE l.tenant_id = t.id AND DATE(l.scanned_at) = CURDATE()) AS scans_today,
        (SELECT COUNT(*) FROM attendance_logs l2 WHERE l2.tenant_id = t.id AND l2.scanned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS scans_30_days
    FROM tenants t
    ORDER BY t.created_at DESC
")->fetchAll();

$format = $_GET['format'] ?? 'csv';

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="saas_billing_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, [
        'Tenant ID', 'Slug', 'School Name', 'Status', 'Plan', 'Billing Status', 'Trial Ends At',
        'Max Users', 'Current Users', 'Max Cards', 'Current Cards', 'Scans Today', 'Scans Last 30 Days', 'Created At',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['slug'],
            $row['school_name'],
            $row['status'],
            $row['plan_name'] ?? 'Starter',
            $row['billing_status'] ?? 'trial',
            $row['trial_ends_at'] ?? '',
            $row['max_users'] ?? 0,
            $row['user_count'] ?? 0,
            $row['max_cards'] ?? 0,
            $row['card_count'] ?? 0,
            $row['scans_today'] ?? 0,
            $row['scans_30_days'] ?? 0,
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid p-4">
    <div class="d-flex justify-content-between mb-3">
        <h3>SaaS Billing Report</h3>
        <div class="d-flex gap-2">
            <a class="btn btn-success btn-sm" href="<?= h(BASE_URL . '/platform_billing_report.php?format=csv') ?>">Download CSV</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h(BASE_URL . '/platform_dashboard.php') ?>">Back</a>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>School</th>
                        <th>Plan / Billing</th>
                        <th>Status</th>
                        <th>Users</th>
                        <th>Cards</th>
                        <th>Scans (Today/30d)</th>
                        <th>Trial Ends</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h($row['school_name'] . ' (' . $row['slug'] . ')') ?></td>
                            <td><?= h(($row['plan_name'] ?? 'Starter') . ' / ' . ($row['billing_status'] ?? 'trial')) ?></td>
                            <td><?= h($row['status']) ?></td>
                            <td><?= h((string) ($row['user_count'] ?? 0) . '/' . (string) ($row['max_users'] ?? 0)) ?></td>
                            <td><?= h((string) ($row['card_count'] ?? 0) . '/' . (string) ($row['max_cards'] ?? 0)) ?></td>
                            <td><?= h((string) ($row['scans_today'] ?? 0) . ' / ' . (string) ($row['scans_30_days'] ?? 0)) ?></td>
                            <td><?= h((string) ($row['trial_ends_at'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted">No tenants available.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
