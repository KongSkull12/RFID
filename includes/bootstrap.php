<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/platform_auth.php';
require_once __DIR__ . '/xlsx_helper.php';

bootstrapTenant();
