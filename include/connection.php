<?php
declare(strict_types=1);

// --- one-time bootstrap guard ---
if (defined('BUGO_CONNECTION_BOOTSTRAPPED')) return;
define('BUGO_CONNECTION_BOOTSTRAPPED', true);

// --- block direct access ---
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// --- PHP runtime timezone ---
date_default_timezone_set('Asia/Manila');

// --- dotenv ---
require_once __DIR__ . '/../vendor/autoload.php';
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

// --- envs ---
$dbHost     = $_ENV['DB_HOST']      ?? 'localhost';
$dbPort     = (int)($_ENV['DB_PORT'] ?? 3306);
$dbName     = $_ENV['DB_NAME']      ?? '';
$dbUser     = $_ENV['DB_USER']      ?? '';
$dbPass     = $_ENV['DB_PASS']      ?? '';
$dbCharset  = $_ENV['DB_CHARSET']   ?? 'utf8mb4';
$dbTimeZone = $_ENV['DB_TIME_ZONE'] ?? '+08:00'; // Asia/Manila

// --- mysqli strict (throw exceptions instead of warnings) ---
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

global $mysqli;

/**
 * Returns a live mysqli connection and enforces the session time_zone.
 */
if (!function_exists('db_connection')) {
    function db_connection(): mysqli {
        global $mysqli, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $dbCharset, $dbTimeZone;

        $needsNew = true;
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            // Safe liveness check under STRICT mode
            try {
                $mysqli->query('SELECT 1');
                $needsNew = false;
            } catch (Throwable $e) {
                $needsNew = true;
            }
            
        }

        if ($needsNew) {
            $mysqli = mysqli_init();
            if (!$mysqli) {
                throw new RuntimeException('MySQLi initialization failed.');
            }
            $mysqli->options(MYSQLI_OPT_LOCAL_INFILE, 0);
            $mysqli->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
            $mysqli->set_charset($dbCharset);
        }

        // Enforce PH time for THIS session so NOW()/CURRENT_TIMESTAMP read/write in PH time
        try {
            $tz = addslashes($dbTimeZone);
            $mysqli->query("SET time_zone = '{$tz}'");
        } catch (Throwable $e) {
            // Optional: log the failure without breaking the request
            // error_log('Failed to set session time_zone: ' . $e->getMessage());
        }

        return $mysqli;
    }
}

// --- initialize first connection (also sets session time zone) ---
$mysqli = db_connection();
