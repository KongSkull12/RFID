<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

platformLogout();
redirect(BASE_URL . '/platform_login.php');
