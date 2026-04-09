<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'your_database_name';
const DB_USER = 'your_database_user';
const DB_PASS = 'your_database_password';

const APP_NAME = 'School RFID Attendance';
const BASE_URL = '/public';
const DEFAULT_TENANT_SLUG = 'default-school';
const ALLOW_TENANT_AUTO_CREATE = true;

const AUTO_SCAN_TOGGLE = true;
const LATE_CUTOFF_TIME = '08:00:00';
const MIN_SCAN_INTERVAL_SECONDS = 8;

if (!function_exists('sms_http_host_looks_local_dev')) {
    /**
     * USB-modem auto-worker only makes sense on localhost / private LAN. Public hosting cannot run Python + serial.
     */
    function sms_http_host_looks_local_dev(): bool
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.0.0.1')) {
            return true;
        }
        if (str_starts_with($host, 'localhost:')) {
            return true;
        }
        if (str_contains($host, '.local') || str_contains($host, '.test') || str_contains($host, '.localhost')) {
            return true;
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $host, $m)) {
            $ip = $m[1];
            if ($ip === '127.0.0.1') {
                return true;
            }
            if (str_starts_with($ip, '10.')) {
                return true;
            }
            if (str_starts_with($ip, '192.168.')) {
                return true;
            }
            if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * When true, each queued parent SMS runs sms_gateway.py once (same PC as PHP + USB modem).
 * Requires Python + pyserial; set SMS_LOCAL_QUEUE_URL and COM port to match this machine.
 * Windows: PHP waits for Python in a shutdown handler after the scan response is flushed (async spawns were often killed).
 * If SMS stays pending, set SMS_LOCAL_PYTHON_FULL_PATH to your python.exe (Apache often has no py on PATH).
 * Delete storage/sms_once.lock if a crashed worker left a stale lock.
 * Does NOT work on shared hosting (Hostinger): there is no access to your USB modem from PHP there.
 */
const SMS_LOCAL_AUTO_SEND = false;

/** Must match how you open the gate (include port if any). */
const SMS_LOCAL_QUEUE_URL = '';

const SMS_LOCAL_SERIAL_PORTS = 'COM8';
const SMS_LOCAL_BAUD = '115200';

/**
 * Strongly recommended on Windows when PHP runs under Apache/XAMPP (PATH may not include py).
 * Example: 'C:\\Users\\You\\AppData\\Local\\Programs\\Python\\Python312\\python.exe'
 */
const SMS_LOCAL_PYTHON_FULL_PATH = '';

/** Used only if SMS_LOCAL_PYTHON_FULL_PATH is empty. Windows: e.g. 'py' or 'python' */
const SMS_LOCAL_PYTHON_EXECUTABLE = 'py';
/** Extra argv before the script, e.g. ['-3'] for the Windows py launcher */
const SMS_LOCAL_PYTHON_EXTRA = ['-3'];

/** If non-empty, only these HTTP_HOST values may spawn the worker (lowercase). */
const SMS_LOCAL_ALLOW_HOSTS = [];

/** Optional: same as SMS_SMSC env for the Python worker */
const SMS_LOCAL_SMSC = '';

const SMS_LOCAL_CMGS_NO_PLUS = false;
