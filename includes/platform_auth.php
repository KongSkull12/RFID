<?php

declare(strict_types=1);

function platformAdmin(): ?array
{
    return $_SESSION['platform_admin'] ?? null;
}

function platformLoggedIn(): bool
{
    return platformAdmin() !== null;
}

function platformLogin(array $admin): void
{
    $_SESSION['platform_admin'] = [
        'id' => (int) $admin['id'],
        'username' => (string) $admin['username'],
        'name' => (string) ($admin['display_name'] ?? $admin['username']),
    ];
}

function platformLogout(): void
{
    unset($_SESSION['platform_admin']);
}

function requirePlatformLogin(): void
{
    if (!platformLoggedIn()) {
        redirect(BASE_URL . '/platform_login.php');
    }
}
