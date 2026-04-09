<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$tenant = isset($_GET['tenant']) ? ('?tenant=' . urlencode((string) $_GET['tenant'])) : '';
header('Location: ' . BASE_URL . '/login.php' . $tenant);
exit;
