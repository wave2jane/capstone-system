<?php
// CCDI Student Evaluation Portal - configuration & shared helpers.
// Edit the DB_* values (or set them as environment variables) before first use.

declare(strict_types=1);

define('DB_HOST', getenv('CCDI_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('CCDI_DB_NAME') ?: 'ccdi_eval');
define('DB_USER', getenv('CCDI_DB_USER') ?: 'root');
define('DB_PASS', getenv('CCDI_DB_PASS') ?: '');

// Student ID format: letter prefix, 4-digit year, then a number. e.g. C-2024-00123
// Accepts any valid year (2000 .. next year) so IDs are not hardcoded.
define('SID_PATTERN', '/^[A-Z]{1,3}-(\d{4})-(\d{3,6})$/');
define('SID_EXAMPLE', 'C-2024-00123');

const COURSES = ['BSIT', 'BSCS', 'BSOA', 'BSIS'];
const BLOCKS  = ['A', 'B', 'C', 'D', 'E', 'F'];

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('CCDISESS');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function valid_sid(string $sid): bool
{
    if (!preg_match(SID_PATTERN, $sid, $m)) {
        return false;
    }
    $year = (int)$m[1];
    return $year >= 2000 && $year <= (int)date('Y') + 1;
}
