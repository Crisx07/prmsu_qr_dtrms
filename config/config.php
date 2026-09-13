<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

function config_load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $value = str_replace(['\n', '\r'], ["\n", "\r"], $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function config_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false) {
        return (string) $value;
    }

    if (isset($_ENV[$key])) {
        return (string) $_ENV[$key];
    }

    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }

    return $default;
}

function config_bool(string $key, bool $default = false): bool
{
    $value = strtolower(trim(config_value($key, $default ? 'true' : 'false')));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function config_int(string $key, int $default): int
{
    $value = trim(config_value($key, (string) $default));

    return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
}

function config_request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

config_load_env_file(ROOT_PATH . '/.env');

define('APP_NAME', config_value('APP_NAME', 'PRMSU IBA DTRMS'));
define('APP_ENV', config_value('APP_ENV', 'local'));
define('BASE_URL', config_value('BASE_URL', '/prmsu_dtrms_qr_v1.5.4_ar'));
define('APP_PUBLIC_URL', rtrim(trim(config_value('APP_PUBLIC_URL', '')), '/'));
define('UPLOAD_DOCUMENTS', config_value('UPLOAD_DOCUMENTS', ROOT_PATH . '/uploads/documents'));
define('UPLOAD_ROOT', config_value('UPLOAD_ROOT', ROOT_PATH . '/uploads'));
define('UPLOAD_ACCOUNT_REQUESTS', config_value('UPLOAD_ACCOUNT_REQUESTS', UPLOAD_ROOT . '/account_requests'));
define('BACKUP_DIR', config_value('BACKUP_DIR', dirname(dirname(ROOT_PATH)) . '/backups/' . basename(ROOT_PATH)));
define('BACKUP_MYSQLDUMP_PATH', config_value('BACKUP_MYSQLDUMP_PATH', ''));
define('BACKUP_MYSQL_PATH', config_value('BACKUP_MYSQL_PATH', ''));
define('BACKUP_MYSQL_PLUGIN_DIR', config_value('BACKUP_MYSQL_PLUGIN_DIR', ''));
define('BACKUP_RESTORE_TOKEN', config_value('BACKUP_RESTORE_TOKEN', ''));
define('BACKUP_ENCRYPTION_KEY', config_value('BACKUP_ENCRYPTION_KEY', ''));
define('BACKUP_RETENTION_DAYS', config_int('BACKUP_RETENTION_DAYS', 30));
define('APP_TIMEZONE', config_value('APP_TIMEZONE', 'Asia/Manila'));
define('DB_TIMEZONE_OFFSET', config_value('DB_TIMEZONE_OFFSET', '+08:00'));
define('SECURITY_HEADERS_ENABLED', config_bool('SECURITY_HEADERS_ENABLED', true));
define('MIGRATIONS_AUTO_RUN', config_bool('MIGRATIONS_AUTO_RUN', true));

date_default_timezone_set(APP_TIMEZONE);

function config_send_security_headers(): void
{
    if (!SECURITY_HEADERS_ENABLED || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');

    $csp = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "img-src 'self' data: blob:",
        "style-src 'self' 'unsafe-inline'",
        "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' blob: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
        "worker-src 'self' blob: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
        "connect-src 'self' blob: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
        "media-src 'self' blob:",
        "font-src 'self' data:",
        "form-action 'self'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));

    if (config_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

config_send_security_headers();

if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'] ?: '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => config_request_is_https(),
        'httponly' => true,
        'samesite' => config_value('SESSION_SAMESITE', 'Lax'),
    ]);
    session_start();
}

$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

define('DB_HOST', config_value('DB_HOST', '127.0.0.1'));
define('DB_PORT', config_value('DB_PORT', '3306'));
define('DB_NAME', config_value('DB_NAME', 'prmsu_dtrms'));
define('DB_USER', config_value('DB_USER', 'root'));
define('DB_PASS', config_value('DB_PASS', '1234'));

define('APP_KEY', config_value('APP_KEY', 'c5e4335d3c8e54dfd5cfe8c20ccd1f3e6ce74fffa82370adca534a25e0328ef4'));
define('AUTH_OTP_ENABLED', config_bool('AUTH_OTP_ENABLED', true));
define('PASSWORD_MIN_LENGTH', config_int('PASSWORD_MIN_LENGTH', 8));

define('MAIL_HOST', config_value('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT', config_int('MAIL_PORT', 587));
define('MAIL_USERNAME', config_value('MAIL_USERNAME', 'prmsu.dtrms@gmail.com'));
define('MAIL_PASSWORD', config_value('MAIL_PASSWORD', 'buas kgrx nemz kvxu'));
define('MAIL_ENCRYPTION', config_value('MAIL_ENCRYPTION', 'tls'));
define('MAIL_FROM_ADDRESS', config_value('MAIL_FROM_ADDRESS', MAIL_USERNAME));
define('MAIL_FROM_NAME', config_value('MAIL_FROM_NAME', APP_NAME));

define('OTP_CODE_LENGTH', config_int('OTP_CODE_LENGTH', 6));
define('OTP_EXPIRES_MINUTES', config_int('OTP_EXPIRES_MINUTES', 10));
define('OTP_RESEND_COOLDOWN_SECONDS', config_int('OTP_RESEND_COOLDOWN_SECONDS', 120));
define('OTP_MAX_ATTEMPTS', config_int('OTP_MAX_ATTEMPTS', 5));

define('MAX_UPLOAD_BYTES', config_int('MAX_UPLOAD_BYTES', 15 * 1024 * 1024));
define('LOGIN_RATE_LIMIT_ATTEMPTS', config_int('LOGIN_RATE_LIMIT_ATTEMPTS', 5));
define('LOGIN_RATE_LIMIT_WINDOW_SECONDS', config_int('LOGIN_RATE_LIMIT_WINDOW_SECONDS', 900));
define('LOGIN_RATE_LIMIT_LOCK_SECONDS', config_int('LOGIN_RATE_LIMIT_LOCK_SECONDS', 300));

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->exec('SET time_zone = ' . $pdo->quote(DB_TIMEZONE_OFFSET));
    return $pdo;
}
