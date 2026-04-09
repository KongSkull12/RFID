<?php

declare(strict_types=1);

function currentUser(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function loginUser(array $user): void
{
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
        'role' => (string) $user['role'],
        'tenant_id' => (int) ($user['tenant_id'] ?? 0),
    ];
}

function logoutUser(): void
{
    unset($_SESSION['auth_user']);
}

function requireLogin(array $allowedRoles = []): void
{
    if (!isLoggedIn()) {
        redirect(appUrl('login.php'));
    }

    if (!tenantCanLogin()) {
        logoutUser();
        http_response_code(403);
        echo 'Tenant account is inactive or suspended.';
        exit;
    }

    if ($allowedRoles === []) {
        $user = currentUser();
        if ((int) ($user['tenant_id'] ?? 0) !== tenantId()) {
            logoutUser();
            redirect(appUrl('login.php'));
        }
        return;
    }

    $user = currentUser();
    if ((int) ($user['tenant_id'] ?? 0) !== tenantId()) {
        logoutUser();
        redirect(appUrl('login.php'));
    }

    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}
