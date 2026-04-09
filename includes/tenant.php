<?php

declare(strict_types=1);

function tenantTableExists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $stmt = $pdo->query("SHOW TABLES LIKE 'tenants'");
    $exists = (bool) $stmt->fetchColumn();
    return $exists;
}

function sanitizeTenantSlug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';
    return $slug;
}

function requestedTenantSlug(): string
{
    $fromGet = sanitizeTenantSlug((string) ($_GET['tenant'] ?? ''));
    if ($fromGet !== '') {
        return $fromGet;
    }

    $fromPost = sanitizeTenantSlug((string) ($_POST['tenant'] ?? ''));
    if ($fromPost !== '') {
        return $fromPost;
    }

    $fromSession = sanitizeTenantSlug((string) ($_SESSION['tenant']['slug'] ?? ''));
    if ($fromSession !== '') {
        return $fromSession;
    }

    return DEFAULT_TENANT_SLUG;
}

function bootstrapTenant(): void
{
    $pdo = db();

    if (!tenantTableExists($pdo)) {
        $_SESSION['tenant'] = [
            'id' => 1,
            'slug' => DEFAULT_TENANT_SLUG,
            'name' => APP_NAME,
            'logo_url' => null,
            'company_logo_url' => null,
            'background_url' => null,
            'status' => 'active',
        ];
        return;
    }

    runSubscriptionAutomation($pdo);

    $slug = requestedTenantSlug();

    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $tenant = $stmt->fetch();

    if (!$tenant && ALLOW_TENANT_AUTO_CREATE && $slug !== '') {
        $schoolName = ucwords(str_replace('-', ' ', $slug));
        $insert = $pdo->prepare("
            INSERT INTO tenants (slug, school_name, status)
            VALUES (?, ?, 'active')
        ");
        $insert->execute([$slug, $schoolName]);
        $stmt->execute([$slug]);
        $tenant = $stmt->fetch();
    }

    if (!$tenant) {
        $tenant = $pdo->query("SELECT * FROM tenants ORDER BY id ASC LIMIT 1")->fetch();
    }

    if (!$tenant) {
        $insert = $pdo->prepare("
            INSERT INTO tenants (slug, school_name, status)
            VALUES (?, ?, 'active')
        ");
        $insert->execute([DEFAULT_TENANT_SLUG, APP_NAME]);
        $stmt->execute([DEFAULT_TENANT_SLUG]);
        $tenant = $stmt->fetch();
    }

    $_SESSION['tenant'] = [
        'id' => (int) $tenant['id'],
        'slug' => (string) $tenant['slug'],
        'name' => (string) $tenant['school_name'],
        'logo_url' => $tenant['logo_url'] ?? null,
        'company_logo_url' => $tenant['company_logo_url'] ?? null,
        'background_url' => $tenant['background_url'] ?? null,
        'status' => (string) ($tenant['status'] ?? 'active'),
        'plan_name' => (string) ($tenant['plan_name'] ?? 'Starter'),
        'max_users' => (int) ($tenant['max_users'] ?? 100),
        'max_cards' => (int) ($tenant['max_cards'] ?? 300),
        'billing_status' => (string) ($tenant['billing_status'] ?? 'trial'),
        'trial_ends_at' => (string) ($tenant['trial_ends_at'] ?? ''),
    ];
}

function currentTenant(): array
{
    return $_SESSION['tenant'] ?? [
        'id' => 1,
        'slug' => DEFAULT_TENANT_SLUG,
        'name' => APP_NAME,
        'logo_url' => null,
        'company_logo_url' => null,
        'background_url' => null,
        'status' => 'active',
        'plan_name' => 'Starter',
        'max_users' => 100,
        'max_cards' => 300,
        'billing_status' => 'trial',
        'trial_ends_at' => '',
    ];
}

function tenantId(): int
{
    return (int) (currentTenant()['id'] ?? 1);
}

function tenantSlug(): string
{
    return (string) (currentTenant()['slug'] ?? DEFAULT_TENANT_SLUG);
}

function tenantName(): string
{
    return (string) (currentTenant()['name'] ?? APP_NAME);
}

function tenantIsActive(): bool
{
    return (currentTenant()['status'] ?? 'active') === 'active';
}

function tenantBillingStatus(): string
{
    return (string) (currentTenant()['billing_status'] ?? 'trial');
}

function tenantCanLogin(): bool
{
    if (!tenantIsActive()) {
        return false;
    }
    return tenantBillingStatus() !== 'suspended';
}

function tenantCanAddUsers(PDO $pdo): bool
{
    $limit = (int) (currentTenant()['max_users'] ?? 100);
    if ($limit <= 0) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ?");
    $stmt->execute([tenantId()]);
    return (int) $stmt->fetchColumn() < $limit;
}

function tenantCanAddCards(PDO $pdo): bool
{
    $limit = (int) (currentTenant()['max_cards'] ?? 300);
    if ($limit <= 0) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rfid_cards WHERE tenant_id = ?");
    $stmt->execute([tenantId()]);
    return (int) $stmt->fetchColumn() < $limit;
}

function runSubscriptionAutomation(PDO $pdo): int
{
    if (!tenantTableExists($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare("
        UPDATE tenants
        SET
            billing_status = 'suspended',
            status = 'inactive'
        WHERE
            status = 'active'
            AND billing_status = 'trial'
            AND trial_ends_at IS NOT NULL
            AND trial_ends_at < CURDATE()
    ");
    $stmt->execute();
    return $stmt->rowCount();
}
