<?php

declare(strict_types=1);

/**
 * Backward-compatible entry: old URL …/public/api/sms_queue.php → worker script.
 * Prefer /worker/sms_queue.php for new setups.
 */
require_once __DIR__ . '/../worker/sms_queue.php';
