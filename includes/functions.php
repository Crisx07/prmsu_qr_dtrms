<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

// Core utilities

function password_min_length(): int
{
    return max(8, (int) PASSWORD_MIN_LENGTH);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function render_collapsible_text(?string $value, int $limit = 160, bool $preserveLines = false): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '-';
    }

    $limit = max(40, $limit);
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $fullText = $preserveLines ? nl2br(e($text)) : e($text);

    if ($length <= $limit) {
        return $fullText;
    }

    $previewSource = (string) preg_replace('/\s+/', ' ', $text);
    $preview = function_exists('mb_substr')
        ? mb_substr($previewSource, 0, $limit, 'UTF-8')
        : substr($previewSource, 0, $limit);
    $preview = rtrim($preview, " \t\n\r\0\x0B.,;:") . '...';

    $fullClass = 'collapsible-text-full' . ($preserveLines ? ' preserve-lines' : '');

    return '<details class="collapsible-text">'
        . '<summary><span class="collapsible-text-preview">' . e($preview) . '</span>'
        . '<span class="collapsible-text-toggle more">Show more</span>'
        . '<span class="collapsible-text-toggle less">Show less</span></summary>'
        . '<div class="' . $fullClass . '">' . $fullText . '</div>'
        . '</details>';
}

function redirect(string $path): never
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function current_path(): string
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = (string) parse_url($requestUri, PHP_URL_PATH);

    return $path !== '' ? $path : '/';
}

function redirect_back(string $fallback): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $path = (string) parse_url($referer, PHP_URL_PATH);
        if ($path !== '' && str_starts_with($path, BASE_URL . '/')) {
            header('Location: ' . $referer);
            exit;
        }
    }

    redirect($fallback);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function deactivated_account_message(): string
{
    return 'Your account has been deactivated. Please contact the administrator.';
}

function auth_logout_reason_deactivated(): string
{
    return 'deactivated';
}

function set_auth_logout_reason(string $reason): void
{
    $_SESSION['auth_logout_reason'] = $reason;
}

function consume_auth_logout_reason(): ?string
{
    if (!isset($_SESSION['auth_logout_reason'])) {
        return null;
    }

    $reason = $_SESSION['auth_logout_reason'];
    unset($_SESSION['auth_logout_reason']);

    return is_string($reason) ? $reason : null;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function require_post(string $fallback = '/dashboard.php'): void
{
    if (!is_post()) {
        set_flash('error', 'Invalid request method.');
        redirect($fallback);
    }
}

function require_csrf(string $fallback = '/dashboard.php'): void
{
    require_post($fallback);

    $submitted = $_POST['_csrf'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        set_flash('error', 'Your session token is invalid or has expired. Please try again.');
        redirect($fallback);
    }
}

function old(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

function input_string(mixed $value, string $default = ''): string
{
    return is_string($value) ? trim($value) : $default;
}

function input_int(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
        return (int) $value;
    }

    return $default;
}

function input_date(mixed $value, string $default = ''): string
{
    return input_string($value, $default);
}

function date_range_filter_sql(string $column, int $year, int $month, string $prefix, array &$params): string
{
    if ($year > 0 && $month >= 1 && $month <= 12) {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $params[$prefix . '_from'] = $start;
        $params[$prefix . '_to'] = $end;

        return $column . ' >= :' . $prefix . '_from AND ' . $column . ' < :' . $prefix . '_to';
    }

    if ($year > 0) {
        $params[$prefix . '_from'] = sprintf('%04d-01-01', $year);
        $params[$prefix . '_to'] = sprintf('%04d-01-01', $year + 1);

        return $column . ' >= :' . $prefix . '_from AND ' . $column . ' < :' . $prefix . '_to';
    }

    if ($month >= 1 && $month <= 12) {
        $params[$prefix . '_month'] = $month;

        return 'MONTH(' . $column . ') = :' . $prefix . '_month';
    }

    return '';
}

function pagination_page_from_request(): int
{
    return max(1, input_int($_GET['page'] ?? 1, 1));
}

function pagination_per_page_from_request(int $default = 10): int
{
    $perPage = input_int($_GET['per_page'] ?? $default, $default);
    $allowed = [10, 25, 50, 100];

    return in_array($perPage, $allowed, true) ? $perPage : $default;
}

function pagination_total_pages(int $total, int $perPage): int
{
    return max(1, (int) ceil($total / max(1, $perPage)));
}

function pagination_offset(int $page, int $perPage): int
{
    return max(0, ($page - 1) * $perPage);
}

function pagination_url(int $page, int $perPage): string
{
    $path = current_path();
    $relativePath = $path;
    if (BASE_URL !== '' && str_starts_with($path, BASE_URL . '/')) {
        $relativePath = substr($path, strlen(BASE_URL));
    }
    if ($relativePath === '' || $relativePath[0] !== '/') {
        $relativePath = '/' . $relativePath;
    }

    $params = $_GET;
    unset($params['export']);
    $params['page'] = $page;
    $params['per_page'] = $perPage;

    return BASE_URL . $relativePath . '?' . http_build_query($params);
}

function render_pagination_controls(int $total, int $page, int $perPage): string
{
    if ($total <= 0) {
        return '';
    }

    $totalPages = pagination_total_pages($total, $perPage);
    $page = max(1, min($page, $totalPages));
    $from = pagination_offset($page, $perPage) + 1;
    $to = min($total, $page * $perPage);
    $html = '<div class="pagination no-print">';
    $html .= '<div class="pagination-summary">Showing ' . e((string) $from) . '-' . e((string) $to) . ' of ' . e((string) $total) . '</div>';
    $html .= '<div class="pagination-actions">';

    if ($page > 1) {
        $html .= '<a class="btn btn-sm btn-secondary" href="' . e(pagination_url($page - 1, $perPage)) . '">Previous</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-secondary disabled">Previous</span>';
    }

    $html .= '<span class="pagination-page">Page ' . e((string) $page) . ' of ' . e((string) $totalPages) . '</span>';

    if ($page < $totalPages) {
        $html .= '<a class="btn btn-sm btn-secondary" href="' . e(pagination_url($page + 1, $perPage)) . '">Next</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-secondary disabled">Next</span>';
    }

    $html .= '</div>';
    $html .= '<form method="get" class="pagination-size">';
    foreach ($_GET as $key => $value) {
        if (in_array($key, ['page', 'per_page', 'export'], true) || is_array($value)) {
            continue;
        }
        $html .= '<input type="hidden" name="' . e((string) $key) . '" value="' . e((string) $value) . '">';
    }
    $html .= '<input type="hidden" name="page" value="1">';
    $html .= '<label for="per_page">Rows</label>';
    $html .= '<select name="per_page" id="per_page" onchange="this.form.submit()">';
    foreach ([10, 25, 50, 100] as $option) {
        $selected = $option === $perPage ? ' selected' : '';
        $html .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
    }
    $html .= '</select>';
    $html .= '</form>';
    $html .= '</div>';

    return $html;
}

function build_like_search_clause(string $term, array $columns, string $placeholderPrefix = 'q'): array
{
    $term = trim($term);
    if ($term === '') {
        return [
            'sql' => '',
            'params' => [],
        ];
    }

    $safePrefix = preg_replace('/[^\w]+/', '_', $placeholderPrefix) ?? 'q';
    $conditions = [];
    $params = [];

    foreach ($columns as $index => $column) {
        $expression = trim((string) $column);
        if ($expression === '') {
            continue;
        }

        $placeholder = $safePrefix . '_' . $index;
        $conditions[] = $expression . ' LIKE :' . $placeholder;
        $params[$placeholder] = '%' . $term . '%';
    }

    if ($conditions === []) {
        return [
            'sql' => '',
            'params' => [],
        ];
    }

    return [
        'sql' => '(' . implode(' OR ', $conditions) . ')',
        'params' => $params,
    ];
}

function fulltext_boolean_query(string $term): string
{
    $tokens = preg_split('/\s+/', trim($term)) ?: [];
    $parts = [];

    foreach ($tokens as $token) {
        $clean = preg_replace('/[^\pL\pN_\-]+/u', '', $token) ?? '';
        $clean = trim($clean, '-_');
        if ($clean === '') {
            continue;
        }

        $parts[] = '+' . $clean . '*';
    }

    return implode(' ', $parts);
}

function document_fulltext_columns(string $alias = 'd'): array
{
    return [
        $alias . '.tracking_no',
        $alias . '.document_number',
        $alias . '.document_name',
        $alias . '.document_person_name',
        $alias . '.subject',
        $alias . '.description',
        $alias . '.ocr_raw_text',
        $alias . '.archive_note',
    ];
}

function build_document_search_clause(string $term, array $extraLikeColumns = [], string $placeholderPrefix = 'document_q', string $alias = 'd'): array
{
    $like = build_like_search_clause($term, array_merge(document_fulltext_columns($alias), $extraLikeColumns), $placeholderPrefix);
    $booleanQuery = fulltext_boolean_query($term);

    if ($booleanQuery === '') {
        return $like;
    }

    try {
        if (!db_index_exists_raw(db(), 'documents', 'idx_documents_fulltext_search')) {
            return $like;
        }
    } catch (Throwable $e) {
        return $like;
    }

    $safePrefix = preg_replace('/[^\w]+/', '_', $placeholderPrefix) ?? 'document_q';
    $fulltextPlaceholder = $safePrefix . '_fulltext';
    $fulltextSql = 'MATCH(' . implode(', ', document_fulltext_columns($alias)) . ') AGAINST (:' . $fulltextPlaceholder . ' IN BOOLEAN MODE)';
    $params = $like['params'];
    $params[$fulltextPlaceholder] = $booleanQuery;

    return [
        'sql' => $like['sql'] !== '' ? '(' . $fulltextSql . ' OR ' . $like['sql'] . ')' : $fulltextSql,
        'params' => $params,
    ];
}

function run_in_transaction(callable $callback): mixed
{
    $pdo = db();
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }

    try {
        $result = $callback($pdo);
        if ($started) {
            $pdo->commit();
        }

        return $result;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function is_duplicate_key_error(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

    return $driverCode === 1062 || (string) $e->getCode() === '23000';
}

function document_number_match_key(string $documentNumber): string
{
    $key = strtolower(trim($documentNumber));
    return preg_replace('/\s+/', '', $key) ?? '';
}

function document_number_exists(string $documentNumber, ?int $excludeId = null): bool
{
    $needle = document_number_match_key($documentNumber);
    if ($needle === '') {
        return false;
    }

    $pdo = db();
    $sql = 'SELECT id, document_number
            FROM documents
            WHERE document_number IS NOT NULL
              AND TRIM(document_number) <> \'\'';
    $params = [];

    if (db_column_exists_raw($pdo, 'documents', 'deleted_at')) {
        $sql .= ' AND deleted_at IS NULL';
    }
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        if (document_number_match_key((string) ($row['document_number'] ?? '')) === $needle) {
            return true;
        }
    }

    return false;
}

// Session and authorization

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function has_role(string $role): bool
{
    $user = current_user();

    return isset($user['role']) && $user['role'] === $role;
}

function has_any_role(array $roles): bool
{
    $user = current_user();

    return $user !== null && in_array($user['role'], $roles, true);
}

function role_options(): array
{
    return [
        'admin' => 'System Administrator',
        'issuing_authority' => 'Issuing Authority',
        'office_staff' => 'Office User',
        'records_officer' => 'Records Officer',
    ];
}

function document_module_roles(): array
{
    return ['issuing_authority', 'office_staff', 'records_officer'];
}

function can_access_document_module(): bool
{
    return has_any_role(document_module_roles());
}

function can_issue_documents(): bool
{
    return has_role('issuing_authority');
}

function can_review_ocr_results(): bool
{
    return has_role('records_officer');
}

function can_archive_intake_documents(): bool
{
    return has_role('records_officer');
}

function can_manage_system_settings(): bool
{
    return has_role('admin');
}

function can_manage_all_documents(): bool
{
    return has_role('records_officer');
}

function can_view_reports(): bool
{
    return has_any_role(document_module_roles());
}

function can_access_recycle_bin(): bool
{
    return false;
}

function current_office_id(): ?int
{
    $user = current_user();

    return isset($user['office_id']) ? (int) $user['office_id'] : null;
}

function protected_admin_unit_name(): string
{
    return 'Records Office - Iba Campus';
}

function protected_admin_unit_code(): string
{
    return 'RECORDS';
}

function legacy_admin_unit_names(): array
{
    return ['System Administration', 'Records Office - Iba Campus'];
}

function legacy_admin_unit_codes(): array
{
    return ['SYSADM', 'RECORDS'];
}

function is_protected_admin_unit(?array $office): bool
{
    if (!is_array($office)) {
        return false;
    }

    $name = strtolower(trim((string) ($office['name'] ?? '')));
    $code = strtoupper(trim((string) ($office['code'] ?? '')));
    $legacyNames = array_map(
        static fn (string $value): string => strtolower(trim($value)),
        legacy_admin_unit_names()
    );

    return $name === strtolower(protected_admin_unit_name())
        || in_array($name, $legacyNames, true)
        || $code === protected_admin_unit_code()
        || in_array($code, legacy_admin_unit_codes(), true);
}

function role_label(string $role): string
{
    $roles = role_options();

    return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function require_roles(array $roles, string $fallback = '/dashboard.php'): void
{
    if (!has_any_role($roles)) {
        set_flash('error', 'You are not authorized to access that page.');
        redirect($fallback);
    }
}

function auth_otp_enabled(): bool
{
    return AUTH_OTP_ENABLED === true;
}

function app_timezone(): DateTimeZone
{
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone(APP_TIMEZONE);

    return $timezone;
}

function otp_code_length(): int
{
    return max(4, (int) OTP_CODE_LENGTH);
}

function otp_expires_minutes(): int
{
    return max(1, (int) OTP_EXPIRES_MINUTES);
}

function otp_resend_cooldown_seconds(): int
{
    return max(0, (int) OTP_RESEND_COOLDOWN_SECONDS);
}

function otp_max_attempts(): int
{
    return max(1, (int) OTP_MAX_ATTEMPTS);
}

function login_rate_limit_attempts(): int
{
    return max(1, (int) LOGIN_RATE_LIMIT_ATTEMPTS);
}

function login_rate_limit_window_seconds(): int
{
    return max(60, (int) LOGIN_RATE_LIMIT_WINDOW_SECONDS);
}

function login_rate_limit_lock_seconds(): int
{
    return max(60, (int) LOGIN_RATE_LIMIT_LOCK_SECONDS);
}

function app_key_is_configured(): bool
{
    $key = trim((string) APP_KEY);

    return $key !== '' && $key !== 'change-me-to-a-random-secret-key';
}

function mail_from_name(): string
{
    $name = trim((string) MAIL_FROM_NAME);

    return $name !== '' ? $name : APP_NAME;
}

function smtp_is_configured(): bool
{
    return trim((string) MAIL_HOST) !== ''
        && (int) MAIL_PORT > 0
        && trim((string) MAIL_USERNAME) !== ''
        && trim((string) MAIL_PASSWORD) !== ''
        && trim((string) MAIL_FROM_ADDRESS) !== '';
}

function mail_is_configured(): bool
{
    return app_key_is_configured() && smtp_is_configured();
}

function require_smtp_ready(): void
{
    if (!smtp_is_configured()) {
        throw new RuntimeException('Email delivery is not configured yet. Update the SMTP settings in the environment configuration.');
    }
}

function require_mail_ready(): void
{
    if (!auth_otp_enabled()) {
        throw new RuntimeException('Account recovery email verification is not enabled.');
    }

    if (!app_key_is_configured()) {
        throw new RuntimeException('Account recovery email verification requires a unique APP_KEY value.');
    }

    require_smtp_ready();
}

function masked_email(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return $email;
    }

    [$localPart, $domainPart] = explode('@', $email, 2);
    $localLength = strlen($localPart);
    $domainSegments = explode('.', $domainPart);
    $domainName = $domainSegments[0] ?? '';
    $domainSuffix = count($domainSegments) > 1 ? '.' . implode('.', array_slice($domainSegments, 1)) : '';

    $maskedLocal = $localLength <= 2
        ? substr($localPart, 0, 1) . str_repeat('*', max(1, $localLength - 1))
        : substr($localPart, 0, 1) . str_repeat('*', max(1, $localLength - 2)) . substr($localPart, -1);

    $domainLength = strlen($domainName);
    if ($domainLength <= 2) {
        $maskedDomain = substr($domainName, 0, 1) . str_repeat('*', max(1, $domainLength - 1));
    } else {
        $maskedDomain = substr($domainName, 0, 1) . str_repeat('*', max(1, $domainLength - 2)) . substr($domainName, -1);
    }

    return $maskedLocal . '@' . $maskedDomain . $domainSuffix;
}

function clear_pending_login_otp(): void
{
    unset($_SESSION['pending_login_otp']);
}

function pending_recovery_otp(): ?array
{
    $payload = $_SESSION['pending_recovery_otp'] ?? null;

    return is_array($payload) ? $payload : null;
}

function clear_pending_recovery_otp(): void
{
    unset($_SESSION['pending_recovery_otp']);
}

function set_pending_recovery_otp(string $email, ?int $userId = null, ?int $challengeId = null, bool $deliverable = false, ?string $recoveryIdentity = null): void
{
    clear_pending_login_otp();
    clear_verified_recovery_reset();

    $_SESSION['pending_recovery_otp'] = [
        'user_id' => $userId,
        'email' => $email,
        'masked_email' => masked_email($email),
        'challenge_id' => $challengeId ?? 0,
        'deliverable' => $deliverable,
        'recovery_identity' => $recoveryIdentity ?? strtolower($email),
    ];
}

function verified_recovery_reset(): ?array
{
    $payload = $_SESSION['verified_recovery_reset'] ?? null;

    return is_array($payload) ? $payload : null;
}

function clear_verified_recovery_reset(): void
{
    unset($_SESSION['verified_recovery_reset']);
}

function set_verified_recovery_reset(array $user, array $challenge): void
{
    clear_pending_login_otp();
    clear_pending_recovery_otp();

    $_SESSION['verified_recovery_reset'] = [
        'user_id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'challenge_id' => (int) $challenge['id'],
        'verified_at' => date('Y-m-d H:i:s'),
    ];
}

function session_user_payload(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'user_uid' => (string) ($user['user_uid'] ?? ''),
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'office_id' => $user['office_id'] !== null ? (int) $user['office_id'] : null,
        'office_name' => $user['office_name'] ?? '',
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
    ];
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    clear_pending_login_otp();
    clear_pending_recovery_otp();
    clear_verified_recovery_reset();
    $_SESSION['user'] = session_user_payload($user);
}

function logout_user(): void
{
    session_unset();
    session_destroy();
    session_start();
}

// Database schema and migration compatibility

function db_table_exists_raw(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table'
    );
    $stmt->execute(['table' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function db_column_exists_raw(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function users_office_name_column_exists(?PDO $pdo = null): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $exists = db_column_exists_raw($pdo ?? db(), 'users', 'office_name');
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function db_index_exists_raw(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index_name'
    );
    $stmt->execute([
        'table' => $table,
        'index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function normalize_user_uid(string $userUid): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($userUid)) ?? '');
}

function user_uid_year_prefix(?int $year = null): string
{
    $yearValue = $year ?? (int) date('Y');
    return substr((string) $yearValue, -2) . '-1-1-';
}

function format_user_uid(int $sequence, ?int $year = null): string
{
    return user_uid_year_prefix($year) . str_pad((string) max(0, $sequence), 4, '0', STR_PAD_LEFT);
}

function next_user_uid(?PDO $pdo = null, ?int $year = null): string
{
    $pdo ??= db();
    $prefix = user_uid_year_prefix($year);
    $stmt = $pdo->prepare(
        'SELECT MAX(CAST(SUBSTRING(user_uid, :suffix_start) AS UNSIGNED))
         FROM users
         WHERE user_uid LIKE :prefix'
    );
    $stmt->execute([
        'suffix_start' => strlen($prefix) + 1,
        'prefix' => $prefix . '%',
    ]);
    $maxSequence = $stmt->fetchColumn();
    $nextSequence = $maxSequence !== null ? ((int) $maxSequence + 1) : 0;

    return format_user_uid($nextSequence, $year);
}

function backfill_user_uids(PDO $pdo): void
{
    if (!db_column_exists_raw($pdo, 'users', 'user_uid')) {
        return;
    }

    $stmt = $pdo->query(
        'SELECT id, created_at
         FROM users
         WHERE user_uid IS NULL OR TRIM(user_uid) = \'\'
         ORDER BY created_at ASC, id ASC'
    );
    $users = $stmt->fetchAll();
    $update = $pdo->prepare('UPDATE users SET user_uid = :user_uid WHERE id = :id');

    foreach ($users as $user) {
        $update->execute([
            'user_uid' => next_user_uid($pdo),
            'id' => (int) $user['id'],
        ]);
    }
}

function db_column_metadata(PDO $pdo, string $table, string $column): ?array
{
    $stmt = $pdo->prepare(
        'SELECT data_type, column_type, is_nullable
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column
         LIMIT 1'
    );
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);
    $metadata = $stmt->fetch();

    return is_array($metadata) ? array_change_key_case($metadata, CASE_LOWER) : null;
}

function normalize_legacy_text_column_nullable(PDO $pdo, string $table, string $column): void
{
    $metadata = db_column_metadata($pdo, $table, $column);
    if ($metadata === null || strtoupper((string) $metadata['is_nullable']) === 'YES') {
        return;
    }

    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);
    $columnType = (string) $metadata['column_type'];

    if ($columnType === '') {
        return;
    }

    $pdo->exec(
        sprintf(
            'ALTER TABLE `%s` MODIFY `%s` %s NULL DEFAULT NULL',
            $safeTable,
            $safeColumn,
            $columnType
        )
    );
}

function recent_database_backup_exists(): bool
{
    $backupDir = rtrim(str_replace('\\', '/', (string) BACKUP_DIR), '/');
    if ($backupDir === '' || !is_dir($backupDir)) {
        return false;
    }

    $cutoff = time() - (24 * 60 * 60);
    $iterator = new DirectoryIterator($backupDir);
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $filename = $file->getFilename();
        if (preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $filename) !== 1) {
            continue;
        }

        if ($file->getMTime() >= $cutoff && $file->getSize() > 0) {
            return true;
        }
    }

    return false;
}

function legacy_text_column_has_values(PDO $pdo, string $table, string $column): bool
{
    if (!db_column_exists_raw($pdo, $table, $column)) {
        return false;
    }

    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);
    $sql = sprintf(
        'SELECT 1 FROM `%s` WHERE `%s` IS NOT NULL AND TRIM(`%s`) <> "" LIMIT 1',
        $safeTable,
        $safeColumn,
        $safeColumn
    );

    return (bool) $pdo->query($sql)->fetchColumn();
}

function drop_legacy_text_column_if_empty(PDO $pdo, string $table, string $column): void
{
    if (!db_column_exists_raw($pdo, $table, $column) || legacy_text_column_has_values($pdo, $table, $column)) {
        return;
    }

    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);
    $pdo->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $safeTable, $safeColumn));
}

function cleanup_empty_legacy_text_columns(PDO $pdo): void
{
    if (!recent_database_backup_exists()) {
        return;
    }

    foreach (
        [
            ['documents', 'category'],
            ['documents', 'origin_office'],
            ['documents', 'current_office'],
            ['document_routes', 'from_office'],
            ['document_routes', 'to_office'],
        ] as [$table, $column]
    ) {
        drop_legacy_text_column_if_empty($pdo, $table, $column);
    }
}

function base_required_tables(): array
{
    return ['users', 'documents', 'document_routes'];
}

function required_tables(): array
{
    return [
        'users',
        'documents',
        'document_routes',
        'offices',
        'document_categories',
        'document_number_sequences',
        'audit_logs',
        'email_otps',
        'auth_rate_limits',
        'document_scan_logs',
        'document_timeline_events',
        'document_validation_checklists',
        'schema_migrations',
        'account_requests',
    ];
}

function ensure_schema_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
}

function schema_migration_definitions(): array
{
    return [
        '20260716_000001_hardening_search_filters' => static function (PDO $pdo): void {
            ensure_schema_migrations_table($pdo);

            if (db_table_exists_raw($pdo, 'document_scan_logs')
                && !db_column_exists_raw($pdo, 'document_scan_logs', 'physical_handler_name')) {
                $pdo->exec('ALTER TABLE document_scan_logs ADD COLUMN physical_handler_name VARCHAR(150) NULL AFTER remarks');
            }

            if (db_table_exists_raw($pdo, 'documents')
                && !db_index_exists_raw($pdo, 'documents', 'idx_documents_fulltext_search')) {
                $pdo->exec(
                    'ALTER TABLE documents
                     ADD FULLTEXT INDEX idx_documents_fulltext_search (
                        tracking_no,
                        document_number,
                        document_name,
                        document_person_name,
                        subject,
                        description,
                        ocr_raw_text,
                        archive_note
                     )'
                );
            }
        },
        '20260721_000001_office_contacts' => static function (PDO $pdo): void {
            if (db_table_exists_raw($pdo, 'offices')
                && !db_column_exists_raw($pdo, 'offices', 'trunk_line')) {
                $pdo->exec('ALTER TABLE offices ADD COLUMN trunk_line VARCHAR(50) NULL AFTER code');
            }

            if (db_table_exists_raw($pdo, 'offices')
                && !db_column_exists_raw($pdo, 'offices', 'local_number')) {
                $pdo->exec('ALTER TABLE offices ADD COLUMN local_number VARCHAR(50) NULL AFTER trunk_line');
            }
        },
        '20260817_000001_account_requests' => static function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS account_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  office_id INT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by INT UNSIGNED NULL,
  rejection_reason VARCHAR(500) NULL,
  approved_user_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_account_requests_status (status),
  KEY idx_account_requests_email (email),
  KEY idx_account_requests_office_id (office_id),
  KEY idx_account_requests_reviewed_by (reviewed_by),
  KEY idx_account_requests_approved_user (approved_user_id),
  CONSTRAINT fk_account_requests_office FOREIGN KEY (office_id) REFERENCES offices (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_account_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_account_requests_approved_user FOREIGN KEY (approved_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
            );
        },
        '20260902_000001_account_id_front_back' => static function (PDO $pdo): void {
            if (db_table_exists_raw($pdo, 'account_requests')) {
                $columns = [
                    'id_front_path' => 'VARCHAR(255) NULL',
                    'id_front_original_name' => 'VARCHAR(255) NULL',
                    'id_front_mime' => 'VARCHAR(150) NULL',
                    'id_front_size' => 'BIGINT UNSIGNED NULL',
                    'id_back_path' => 'VARCHAR(255) NULL',
                    'id_back_original_name' => 'VARCHAR(255) NULL',
                    'id_back_mime' => 'VARCHAR(150) NULL',
                    'id_back_size' => 'BIGINT UNSIGNED NULL',
                ];

                foreach ($columns as $column => $definition) {
                    if (!db_column_exists_raw($pdo, 'account_requests', $column)) {
                        $pdo->exec('ALTER TABLE account_requests ADD COLUMN ' . $column . ' ' . $definition);
                    }
                }
            }
        },
        '20260823_000001_account_id_photo_legacy_archive' => static function (PDO $pdo): void {
            if (db_table_exists_raw($pdo, 'account_requests')) {
                if (!db_column_exists_raw($pdo, 'account_requests', 'id_photo_path')) {
                    $pdo->exec('ALTER TABLE account_requests ADD COLUMN id_photo_path VARCHAR(255) NULL AFTER office_id');
                }
                if (!db_column_exists_raw($pdo, 'account_requests', 'id_photo_original_name')) {
                    $pdo->exec('ALTER TABLE account_requests ADD COLUMN id_photo_original_name VARCHAR(255) NULL AFTER id_photo_path');
                }
                if (!db_column_exists_raw($pdo, 'account_requests', 'id_photo_mime')) {
                    $pdo->exec('ALTER TABLE account_requests ADD COLUMN id_photo_mime VARCHAR(150) NULL AFTER id_photo_original_name');
                }
                if (!db_column_exists_raw($pdo, 'account_requests', 'id_photo_size')) {
                    $pdo->exec('ALTER TABLE account_requests ADD COLUMN id_photo_size BIGINT UNSIGNED NULL AFTER id_photo_mime');
                }
            }
            if (db_table_exists_raw($pdo, 'documents') && !db_column_exists_raw($pdo, 'documents', 'document_creator')) {
                $pdo->exec('ALTER TABLE documents ADD COLUMN document_creator VARCHAR(180) NULL AFTER document_name');
            }
        },
    ];
}

function applied_schema_migrations(PDO $pdo): array
{
    if (!db_table_exists_raw($pdo, 'schema_migrations')) {
        return [];
    }

    $rows = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = [];
    foreach ($rows as $migration) {
        $applied[(string) $migration] = true;
    }

    return $applied;
}

function pending_schema_migrations(?PDO $pdo = null): array
{
    try {
        $pdo ??= db();
        $applied = applied_schema_migrations($pdo);
        $pending = [];
        foreach (schema_migration_definitions() as $migration => $_callback) {
            if (!isset($applied[$migration])) {
                $pending[] = $migration;
            }
        }

        return $pending;
    } catch (Throwable $e) {
        return array_keys(schema_migration_definitions());
    }
}

function run_pending_schema_migrations(PDO $pdo): array
{
    ensure_schema_migrations_table($pdo);
    $applied = applied_schema_migrations($pdo);
    $ran = [];

    foreach (schema_migration_definitions() as $migration => $callback) {
        if (isset($applied[$migration])) {
            continue;
        }

        run_in_transaction(static function () use ($pdo, $migration, $callback): void {
            $callback($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO schema_migrations (migration, applied_at)
                 VALUES (:migration, NOW())'
            );
            $stmt->execute(['migration' => $migration]);
        });
        $ran[] = $migration;
    }

    return $ran;
}

function office_code_candidate(string $name): string
{
    $clean = preg_replace('/[^A-Z0-9 ]+/', ' ', strtoupper($name));
    $clean = preg_replace('/\s+/', ' ', trim((string) $clean));

    if ($clean === '') {
        return 'OFFICE';
    }

    $words = preg_split('/\s+/', $clean) ?: [];
    $letters = '';
    foreach ($words as $word) {
        if ($word !== '') {
            $letters .= $word[0];
        }
    }

    $candidate = strlen($letters) >= 3 ? $letters : str_replace(' ', '', $clean);
    $candidate = substr($candidate, 0, 10);

    return $candidate !== '' ? $candidate : 'OFFICE';
}

function generate_unique_office_code(PDO $pdo, string $name): string
{
    $existingCodes = $pdo->query('SELECT code FROM offices')->fetchAll(PDO::FETCH_COLUMN);
    $used = [];
    foreach ($existingCodes as $code) {
        $used[(string) $code] = true;
    }

    $base = office_code_candidate($name);
    $code = $base;
    $counter = 1;

    while (isset($used[$code])) {
        $suffix = (string) $counter;
        $code = substr($base, 0, max(1, 10 - strlen($suffix))) . $suffix;
        $counter++;
    }

    return $code;
}

function ensure_system_office(PDO $pdo): int
{
    $targetCode = protected_admin_unit_code();
    $targetName = protected_admin_unit_name();

    $stmt = $pdo->prepare('SELECT id FROM offices WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $targetCode]);
    $officeId = $stmt->fetchColumn();

    if ($officeId) {
        $pdo->prepare('UPDATE offices SET name = :name, is_active = 1 WHERE id = :id')->execute([
            'name' => $targetName,
            'id' => $officeId,
        ]);

        return (int) $officeId;
    }

    $stmt = $pdo->prepare('SELECT id FROM offices WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $targetName]);
    $officeId = $stmt->fetchColumn();

    if ($officeId) {
        $pdo->prepare('UPDATE offices SET code = :code, is_active = 1 WHERE id = :id')->execute([
            'code' => $targetCode,
            'id' => $officeId,
        ]);

        return (int) $officeId;
    }

    foreach (legacy_admin_unit_codes() as $legacyCode) {
        $stmt = $pdo->prepare('SELECT id FROM offices WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $legacyCode]);
        $officeId = $stmt->fetchColumn();

        if ($officeId) {
            $pdo->prepare('UPDATE offices SET name = :name, code = :code, is_active = 1 WHERE id = :id')->execute([
                'name' => $targetName,
                'code' => $targetCode,
                'id' => $officeId,
            ]);

            return (int) $officeId;
        }
    }

    foreach (legacy_admin_unit_names() as $legacyName) {
        $stmt = $pdo->prepare('SELECT id FROM offices WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $legacyName]);
        $officeId = $stmt->fetchColumn();

        if ($officeId) {
            $pdo->prepare('UPDATE offices SET name = :name, code = :code, is_active = 1 WHERE id = :id')->execute([
                'name' => $targetName,
                'code' => $targetCode,
                'id' => $officeId,
            ]);

            return (int) $officeId;
        }
    }

    $pdo->prepare(
        'INSERT INTO offices (name, code, is_active, created_at)
         VALUES (:name, :code, 1, NOW())'
    )->execute([
        'name' => $targetName,
        'code' => $targetCode,
    ]);

    return (int) $pdo->lastInsertId();
}

function office_name_for_id(int $officeId, ?PDO $pdo = null): ?string
{
    if ($officeId <= 0) {
        return null;
    }

    $stmt = ($pdo ?? db())->prepare('SELECT name FROM offices WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $officeId]);
    $name = $stmt->fetchColumn();

    return is_string($name) && $name !== '' ? $name : null;
}

function office_contact_label(?string $trunkLine, ?string $localNumber): string
{
    $trunkLine = trim((string) $trunkLine);
    $localNumber = trim((string) $localNumber);
    $parts = [];

    if ($trunkLine !== '') {
        $parts[] = 'Trunk: ' . $trunkLine;
    }
    if ($localNumber !== '') {
        $parts[] = 'Local: ' . $localNumber;
    }

    return $parts ? implode(' | ', $parts) : '-';
}

function sync_legacy_user_office_names(PDO $pdo): void
{
    if (!users_office_name_column_exists($pdo)) {
        return;
    }

    $pdo->exec(
        'UPDATE users u
         LEFT JOIN offices o ON o.id = u.office_id
         SET u.office_name = o.name
         WHERE u.office_id IS NOT NULL
           AND o.name IS NOT NULL
           AND (
                u.office_name IS NULL
                OR TRIM(u.office_name) = ""
                OR LOWER(TRIM(u.office_name)) <> LOWER(TRIM(o.name))
           )'
    );
}

function sync_legacy_office_name_references(PDO $pdo, int $officeId, string $oldName, string $newName): void
{
    if ($officeId <= 0) {
        return;
    }

    $oldName = trim($oldName);
    $newName = trim($newName);
    if ($newName === '') {
        return;
    }

    if (db_column_exists_raw($pdo, 'users', 'office_name')) {
        $pdo->prepare(
            'UPDATE users
             SET office_name = :new_name
             WHERE office_id = :office_id'
        )->execute([
            'new_name' => $newName,
            'office_id' => $officeId,
        ]);
    }

    $legacyColumns = [
        ['documents', 'origin_office', 'origin_office_id'],
        ['documents', 'current_office', 'current_office_id'],
        ['document_routes', 'from_office', 'from_office_id'],
        ['document_routes', 'to_office', 'to_office_id'],
    ];

    foreach ($legacyColumns as [$table, $legacyColumn, $officeIdColumn]) {
        if (!db_column_exists_raw($pdo, $table, $legacyColumn) || !db_column_exists_raw($pdo, $table, $officeIdColumn)) {
            continue;
        }

        $safeTable = str_replace('`', '``', $table);
        $safeLegacyColumn = str_replace('`', '``', $legacyColumn);
        $safeOfficeIdColumn = str_replace('`', '``', $officeIdColumn);
        $sql = sprintf(
            'UPDATE `%s`
             SET `%s` = :new_name
             WHERE `%s` = :office_id',
            $safeTable,
            $safeLegacyColumn,
            $safeOfficeIdColumn
        );
        $params = [
            'new_name' => $newName,
            'office_id' => $officeId,
        ];

        if ($oldName !== '') {
            $sql .= sprintf(' OR LOWER(TRIM(`%s`)) = LOWER(TRIM(:old_name))', $safeLegacyColumn);
            $params['old_name'] = $oldName;
        }

        $pdo->prepare($sql)->execute($params);
    }
}

function sync_offices_from_legacy_data(PDO $pdo): void
{
    $names = [protected_admin_unit_name()];

    if (db_column_exists_raw($pdo, 'users', 'office_name')) {
        $names = array_merge(
            $names,
            $pdo->query("SELECT DISTINCT TRIM(office_name) FROM users WHERE office_name IS NOT NULL AND TRIM(office_name) <> ''")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    if (db_column_exists_raw($pdo, 'documents', 'origin_office')) {
        $names = array_merge(
            $names,
            $pdo->query("SELECT DISTINCT TRIM(origin_office) FROM documents WHERE origin_office IS NOT NULL AND TRIM(origin_office) <> ''")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    if (db_column_exists_raw($pdo, 'documents', 'current_office')) {
        $names = array_merge(
            $names,
            $pdo->query("SELECT DISTINCT TRIM(current_office) FROM documents WHERE current_office IS NOT NULL AND TRIM(current_office) <> ''")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    if (db_column_exists_raw($pdo, 'document_routes', 'from_office')) {
        $names = array_merge(
            $names,
            $pdo->query("SELECT DISTINCT TRIM(from_office) FROM document_routes WHERE from_office IS NOT NULL AND TRIM(from_office) <> ''")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    if (db_column_exists_raw($pdo, 'document_routes', 'to_office')) {
        $names = array_merge(
            $names,
            $pdo->query("SELECT DISTINCT TRIM(to_office) FROM document_routes WHERE to_office IS NOT NULL AND TRIM(to_office) <> ''")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    $names = array_values(array_unique(array_filter(array_map(static fn ($name) => trim((string) $name), $names))));

    $existingNames = $pdo->query('SELECT name FROM offices')->fetchAll(PDO::FETCH_COLUMN);
    $lookup = [];
    foreach ($existingNames as $name) {
        $lookup[strtolower(trim((string) $name))] = true;
    }

    $insert = $pdo->prepare(
        'INSERT INTO offices (name, code, is_active, created_at)
         VALUES (:name, :code, 1, NOW())'
    );

    foreach ($names as $name) {
        $key = strtolower($name);
        if (isset($lookup[$key])) {
            continue;
        }

        $insert->execute([
            'name' => $name,
            'code' => generate_unique_office_code($pdo, $name),
        ]);
        $lookup[$key] = true;
    }
}

function sync_categories_from_legacy_data(PDO $pdo): void
{
    $defaults = ['Request', 'Travel Order', 'Office Order', 'Memorandum'];
    $names = $defaults;

    if (db_column_exists_raw($pdo, 'documents', 'category')) {
        $names = array_merge(
            $names,
            $pdo->query("SELECT DISTINCT TRIM(category) FROM documents WHERE category IS NOT NULL AND TRIM(category) <> ''")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    $names = array_values(array_unique(array_filter(array_map(static fn ($name) => trim((string) $name), $names))));
    $existingNames = $pdo->query('SELECT name FROM document_categories')->fetchAll(PDO::FETCH_COLUMN);
    $lookup = [];
    foreach ($existingNames as $name) {
        $lookup[strtolower(trim((string) $name))] = true;
    }

    $insert = $pdo->prepare(
        'INSERT INTO document_categories (name, is_active, created_at)
         VALUES (:name, 1, NOW())'
    );

    foreach ($names as $name) {
        $key = strtolower($name);
        if (isset($lookup[$key])) {
            continue;
        }

        $insert->execute(['name' => $name]);
        $lookup[$key] = true;
    }
}

function ensure_database_schema(PDO $pdo): void
{
    static $hasRun = false;

    if ($hasRun) {
        return;
    }

    $hasRun = true;

    foreach (base_required_tables() as $table) {
        if (!db_table_exists_raw($pdo, $table)) {
            return;
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS offices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            code VARCHAR(20) NOT NULL UNIQUE,
            trunk_line VARCHAR(50) NULL,
            local_number VARCHAR(50) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    if (!db_column_exists_raw($pdo, 'offices', 'trunk_line')) {
        $pdo->exec('ALTER TABLE offices ADD COLUMN trunk_line VARCHAR(50) NULL AFTER code');
    }
    if (!db_column_exists_raw($pdo, 'offices', 'local_number')) {
        $pdo->exec('ALTER TABLE offices ADD COLUMN local_number VARCHAR(50) NULL AFTER trunk_line');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS document_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS document_number_sequences (
            `year` SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
            last_number INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_entity (entity_type, entity_id),
            INDEX idx_audit_user (user_id),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_otps (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            email VARCHAR(150) NOT NULL,
            purpose ENUM("login", "recovery") NOT NULL,
            code_hash CHAR(64) NOT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            requested_ip VARCHAR(45) NULL,
            INDEX idx_email_otps_user_purpose (user_id, purpose),
            INDEX idx_email_otps_expires_at (expires_at),
            CONSTRAINT fk_email_otps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS auth_rate_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(40) NOT NULL,
            identity_hash CHAR(64) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_auth_rate_scope_identity (scope, identity_hash),
            INDEX idx_auth_rate_locked_until (locked_until)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS document_scan_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            route_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            office_id INT UNSIGNED NULL,
            action ENUM("scan", "validate", "route", "receive", "flag_mismatch", "return_to_records", "resolve_hold", "complete", "archive", "location_update", "restore_archive") NOT NULL DEFAULT "scan",
            result ENUM("valid", "invalid", "mismatch", "unauthorized") NOT NULL DEFAULT "valid",
            remarks TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scan_document (document_id),
            INDEX idx_scan_route (route_id),
            INDEX idx_scan_user (user_id),
            INDEX idx_scan_office (office_id),
            CONSTRAINT fk_scan_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_scan_route FOREIGN KEY (route_id) REFERENCES document_routes(id) ON DELETE SET NULL,
            CONSTRAINT fk_scan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_scan_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS document_timeline_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            route_id INT UNSIGNED NULL,
            actor_user_id INT UNSIGNED NULL,
            actor_office_id INT UNSIGNED NULL,
            counterparty_office_id INT UNSIGNED NULL,
            event_type ENUM(
                "registered",
                "draft_created",
                "submitted",
                "released",
                "rejected",
                "intake_validated",
                "forwarded",
                "office_forwarded",
                "received",
                "returned_to_records",
                "remarked",
                "hold_placed",
                "hold_resolved",
                "completed",
                "archived",
                "file_validated",
                "ocr_verified",
                "ocr_rejected",
                "location_updated",
                "archive_restored"
            ) NOT NULL,
            stage_after VARCHAR(30) NOT NULL,
            remarks TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_timeline_document (document_id),
            INDEX idx_timeline_route (route_id),
            INDEX idx_timeline_user (actor_user_id),
            INDEX idx_timeline_office (actor_office_id),
            CONSTRAINT fk_timeline_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_timeline_route FOREIGN KEY (route_id) REFERENCES document_routes(id) ON DELETE SET NULL,
            CONSTRAINT fk_timeline_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_timeline_actor_office FOREIGN KEY (actor_office_id) REFERENCES offices(id) ON DELETE SET NULL,
            CONSTRAINT fk_timeline_counterparty_office FOREIGN KEY (counterparty_office_id) REFERENCES offices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS document_validation_checklists (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            route_id INT UNSIGNED NULL,
            office_id INT UNSIGNED NULL,
            validated_by INT UNSIGNED NULL,
            context ENUM("records_intake", "office_receipt", "records_return") NOT NULL,
            qr_match TINYINT(1) NOT NULL DEFAULT 0,
            tracking_number_match TINYINT(1) NOT NULL DEFAULT 0,
            subject_match TINYINT(1) NOT NULL DEFAULT 0,
            source_office_match TINYINT(1) NOT NULL DEFAULT 0,
            destination_office_match TINYINT(1) NOT NULL DEFAULT 0,
            page_count_match TINYINT(1) NOT NULL DEFAULT 0,
            signatures_match TINYINT(1) NOT NULL DEFAULT 0,
            remarks TEXT NULL,
            result ENUM("pass", "fail") NOT NULL DEFAULT "pass",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_checklist_document (document_id),
            INDEX idx_checklist_route (route_id),
            INDEX idx_checklist_context (context),
            INDEX idx_checklist_result (result),
            CONSTRAINT fk_checklist_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_checklist_route FOREIGN KEY (route_id) REFERENCES document_routes(id) ON DELETE SET NULL,
            CONSTRAINT fk_checklist_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL,
            CONSTRAINT fk_checklist_user FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );

    $scanActionMeta = db_column_metadata($pdo, 'document_scan_logs', 'action');
    $scanActionType = (string) ($scanActionMeta['column_type'] ?? '');
    if ($scanActionMeta !== null
        && (
            !str_contains($scanActionType, "'return_to_records'")
            || !str_contains($scanActionType, "'location_update'")
            || !str_contains($scanActionType, "'restore_archive'")
        )) {
        $pdo->exec(
            "ALTER TABLE document_scan_logs MODIFY action ENUM(
                'scan',
                'validate',
                'route',
                'receive',
                'flag_mismatch',
                'return_to_records',
                'resolve_hold',
                'complete',
                'archive',
                'location_update',
                'restore_archive'
            ) NOT NULL DEFAULT 'scan'"
        );
    }

    $roleMeta = db_column_metadata($pdo, 'users', 'role');
    if ($roleMeta !== null && $roleMeta['data_type'] !== 'varchar') {
        $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'office_staff'");
    }

    if (!db_column_exists_raw($pdo, 'users', 'office_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN office_id INT UNSIGNED NULL AFTER role');
    }
    if (!db_column_exists_raw($pdo, 'users', 'user_uid')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN user_uid VARCHAR(20) NULL AFTER id');
    }
    if (!db_column_exists_raw($pdo, 'users', 'must_change_password')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }
    if (!db_index_exists_raw($pdo, 'users', 'idx_users_office_id')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_office_id (office_id)');
    }
    backfill_user_uids($pdo);
    if (!db_index_exists_raw($pdo, 'users', 'idx_users_user_uid')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE INDEX idx_users_user_uid (user_uid)');
    }

    $statusMeta = db_column_metadata($pdo, 'documents', 'status');
    if ($statusMeta !== null && !str_contains((string) $statusMeta['column_type'], "'Under Action'")) {
        $pdo->exec(
            "ALTER TABLE documents MODIFY status ENUM(
                'Draft',
                'Submitted',
                'Rejected',
                'Pending',
                'Pending Receipt',
                'In Progress',
                'Completed',
                'Archived',
                'Logged',
                'Validated',
                'Forwarded',
                'Under Action',
                'Returned to Records',
                'On Hold'
            ) NOT NULL DEFAULT 'Pending'"
        );
    }

    if (!db_column_exists_raw($pdo, 'documents', 'category_id')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN category_id INT UNSIGNED NULL AFTER description');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'document_number')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN document_number VARCHAR(80) NULL AFTER tracking_no');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'document_name')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN document_name VARCHAR(180) NULL AFTER document_number');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'document_person_name')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN document_person_name VARCHAR(180) NULL AFTER document_name');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'origin_office_id')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN origin_office_id INT UNSIGNED NULL AFTER category_id');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'current_office_id')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN current_office_id INT UNSIGNED NULL AFTER origin_office_id');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'attachment_original_name')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN attachment_original_name VARCHAR(255) NULL AFTER attachment_path');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'attachment_mime')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN attachment_mime VARCHAR(150) NULL AFTER attachment_original_name');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'attachment_size')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN attachment_size BIGINT UNSIGNED NULL AFTER attachment_mime');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'qr_token')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN qr_token CHAR(64) NULL AFTER tracking_no');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'file_sha256')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN file_sha256 CHAR(64) NULL AFTER attachment_size');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'page_count')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN page_count INT UNSIGNED NULL AFTER file_sha256');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'qr_issued_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN qr_issued_at DATETIME NULL AFTER page_count');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'validated_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN validated_at DATETIME NULL AFTER qr_issued_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'validated_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN validated_by INT UNSIGNED NULL AFTER validated_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'ocr_raw_text')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN ocr_raw_text MEDIUMTEXT NULL AFTER validated_by');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'ocr_confidence')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN ocr_confidence DECIMAL(5,2) NULL AFTER ocr_raw_text');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'ocr_status')) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN ocr_status VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER ocr_confidence");
    }
    if (!db_column_exists_raw($pdo, 'documents', 'ocr_reviewed_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN ocr_reviewed_by INT UNSIGNED NULL AFTER ocr_status');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'ocr_reviewed_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN ocr_reviewed_at DATETIME NULL AFTER ocr_reviewed_by');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'ocr_review_note')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN ocr_review_note TEXT NULL AFTER ocr_reviewed_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'released_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN released_at DATETIME NULL AFTER ocr_review_note');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'released_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN released_by INT UNSIGNED NULL AFTER released_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'submitted_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN submitted_at DATETIME NULL AFTER released_by');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'submitted_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN submitted_by INT UNSIGNED NULL AFTER submitted_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'reviewed_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN reviewed_at DATETIME NULL AFTER submitted_by');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'reviewed_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reviewed_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'review_note')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN review_note TEXT NULL AFTER reviewed_by');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'archived_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'deleted_at')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN deleted_at DATETIME NULL AFTER updated_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'deleted_by')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at');
    }
    if (!db_column_exists_raw($pdo, 'documents', 'deleted_reason')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN deleted_reason TEXT NULL AFTER deleted_by');
    }
    $pdo->exec(
        'UPDATE documents
         SET deleted_at = NULL,
             deleted_by = NULL,
             deleted_reason = NULL
         WHERE deleted_at IS NOT NULL
            OR deleted_by IS NOT NULL
            OR deleted_reason IS NOT NULL'
    );
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_current_office_id')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_current_office_id (current_office_id)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_category_id')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_category_id (category_id)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_received_date')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_received_date (received_date)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_status')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_status (status)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_tracking_no')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_tracking_no (tracking_no)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_document_number')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_document_number (document_number)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_person_name')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_person_name (document_person_name)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_deleted_at')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_deleted_at (deleted_at)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_ocr_status')) {
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_ocr_status (ocr_status)');
    }
    if (!db_index_exists_raw($pdo, 'documents', 'idx_documents_qr_token')) {
        $pdo->exec('ALTER TABLE documents ADD UNIQUE INDEX idx_documents_qr_token (qr_token)');
    }

    if (!db_column_exists_raw($pdo, 'document_routes', 'from_office_id')) {
        $pdo->exec('ALTER TABLE document_routes ADD COLUMN from_office_id INT UNSIGNED NULL AFTER document_id');
    }
    if (!db_column_exists_raw($pdo, 'document_routes', 'to_office_id')) {
        $pdo->exec('ALTER TABLE document_routes ADD COLUMN to_office_id INT UNSIGNED NULL AFTER from_office_id');
    }
    if (!db_column_exists_raw($pdo, 'document_routes', 'route_status')) {
        $pdo->exec("ALTER TABLE document_routes ADD COLUMN route_status ENUM('pending_receipt', 'received', 'cancelled') NOT NULL DEFAULT 'received' AFTER remarks");
    }
    $routeStatusMeta = db_column_metadata($pdo, 'document_routes', 'route_status');
    if ($routeStatusMeta !== null && !str_contains((string) $routeStatusMeta['column_type'], "'cancelled'")) {
        $pdo->exec("ALTER TABLE document_routes MODIFY route_status ENUM('pending_receipt', 'received', 'cancelled') NOT NULL DEFAULT 'received'");
    }
    if (!db_column_exists_raw($pdo, 'document_routes', 'handoff_type')) {
        $pdo->exec("ALTER TABLE document_routes ADD COLUMN handoff_type ENUM('records_to_office', 'office_to_records', 'office_to_office') NULL AFTER route_status");
    }
    $handoffMeta = db_column_metadata($pdo, 'document_routes', 'handoff_type');
    if ($handoffMeta !== null && !str_contains((string) $handoffMeta['column_type'], "'office_to_office'")) {
        $pdo->exec("ALTER TABLE document_routes MODIFY handoff_type ENUM('records_to_office', 'office_to_records', 'office_to_office') NULL");
    }
    if (!db_column_exists_raw($pdo, 'document_routes', 'received_at')) {
        $pdo->exec('ALTER TABLE document_routes ADD COLUMN received_at DATETIME NULL AFTER routed_at');
    }
    if (!db_column_exists_raw($pdo, 'document_routes', 'received_by')) {
        $pdo->exec('ALTER TABLE document_routes ADD COLUMN received_by INT UNSIGNED NULL AFTER received_at');
    }
    if (!db_index_exists_raw($pdo, 'document_routes', 'idx_document_routes_status')) {
        $pdo->exec('ALTER TABLE document_routes ADD INDEX idx_document_routes_status (route_status)');
    }
    if (!db_index_exists_raw($pdo, 'document_routes', 'idx_document_routes_handoff_type')) {
        $pdo->exec('ALTER TABLE document_routes ADD INDEX idx_document_routes_handoff_type (handoff_type)');
    }

    sync_offices_from_legacy_data($pdo);
    sync_categories_from_legacy_data($pdo);
    $systemOfficeId = ensure_system_office($pdo);

    $pdo->exec(
        'UPDATE users u
         LEFT JOIN offices o ON o.id = u.office_id
         SET u.office_id = NULL
         WHERE u.office_id IS NOT NULL
           AND o.id IS NULL'
    );
    $pdo->exec(
        'UPDATE documents d
         LEFT JOIN offices o ON o.id = d.origin_office_id
         SET d.origin_office_id = NULL
         WHERE d.origin_office_id IS NOT NULL
           AND o.id IS NULL'
    );
    $pdo->exec(
        'UPDATE documents d
         LEFT JOIN offices o ON o.id = d.current_office_id
         SET d.current_office_id = NULL
         WHERE d.current_office_id IS NOT NULL
           AND o.id IS NULL'
    );
    $pdo->exec(
        'UPDATE document_routes dr
         LEFT JOIN offices o ON o.id = dr.from_office_id
         SET dr.from_office_id = NULL
         WHERE dr.from_office_id IS NOT NULL
           AND o.id IS NULL'
    );
    $pdo->exec(
        'UPDATE document_routes dr
         LEFT JOIN offices o ON o.id = dr.to_office_id
         SET dr.to_office_id = NULL
         WHERE dr.to_office_id IS NOT NULL
           AND o.id IS NULL'
    );

    if (db_column_exists_raw($pdo, 'users', 'office_name')) {
        $pdo->exec(
            'UPDATE users u
             LEFT JOIN offices o ON LOWER(TRIM(o.name)) = LOWER(TRIM(u.office_name))
             SET u.office_id = o.id
             WHERE u.office_id IS NULL
               AND u.office_name IS NOT NULL
               AND TRIM(u.office_name) <> ""'
        );
    }
    $pdo->prepare('UPDATE users SET office_id = :office_id WHERE office_id IS NULL')->execute([
        'office_id' => $systemOfficeId,
    ]);
    $pdo->exec(
        "UPDATE users
         SET role = CASE
             WHEN role = 'admin' THEN 'admin'
             WHEN role = 'issuing_authority' THEN 'issuing_authority'
             WHEN role = 'records_officer' THEN 'records_officer'
             ELSE 'office_staff'
         END"
    );
    $pdo->exec('UPDATE users SET must_change_password = 0 WHERE must_change_password IS NULL');
    sync_legacy_user_office_names($pdo);

    if (db_column_exists_raw($pdo, 'documents', 'category')) {
        $pdo->exec(
            'UPDATE documents d
             LEFT JOIN document_categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(d.category))
             SET d.category_id = c.id
             WHERE d.category_id IS NULL
               AND d.category IS NOT NULL
               AND TRIM(d.category) <> ""'
        );
    }
    $pdo->exec(
        'UPDATE documents d
         LEFT JOIN document_categories c ON c.name = "General"
         SET d.category_id = c.id
         WHERE d.category_id IS NULL'
    );

    if (db_column_exists_raw($pdo, 'documents', 'origin_office')) {
        $pdo->exec(
            'UPDATE documents d
             LEFT JOIN offices o ON LOWER(TRIM(o.name)) = LOWER(TRIM(d.origin_office))
             SET d.origin_office_id = o.id
             WHERE d.origin_office_id IS NULL
               AND d.origin_office IS NOT NULL
               AND TRIM(d.origin_office) <> ""'
        );
    }
    if (db_column_exists_raw($pdo, 'documents', 'current_office')) {
        $pdo->exec(
            'UPDATE documents d
             LEFT JOIN offices o ON LOWER(TRIM(o.name)) = LOWER(TRIM(d.current_office))
             SET d.current_office_id = o.id
             WHERE d.current_office_id IS NULL
               AND d.current_office IS NOT NULL
               AND TRIM(d.current_office) <> ""'
        );
    }
    $pdo->prepare('UPDATE documents SET origin_office_id = :office_id WHERE origin_office_id IS NULL')->execute([
        'office_id' => $systemOfficeId,
    ]);
    $pdo->prepare('UPDATE documents SET current_office_id = :office_id WHERE current_office_id IS NULL')->execute([
        'office_id' => $systemOfficeId,
    ]);
    if (db_column_exists_raw($pdo, 'documents', 'attachment_path')) {
        $pdo->exec(
            'UPDATE documents
             SET attachment_original_name = attachment_path
             WHERE attachment_path IS NOT NULL
               AND attachment_path <> ""
               AND (attachment_original_name IS NULL OR attachment_original_name = "")'
        );
    }
    $pdo->exec(
        'UPDATE documents
         SET document_name = subject
         WHERE (document_name IS NULL OR TRIM(document_name) = "")
           AND subject IS NOT NULL
           AND TRIM(subject) <> ""'
    );
    $pdo->exec(
        "UPDATE documents
         SET ocr_status = 'manual'
         WHERE ocr_status IS NULL OR TRIM(ocr_status) = ''"
    );
    $pdo->exec(
        'UPDATE documents
         SET released_at = COALESCE(released_at, qr_issued_at, created_at),
             released_by = COALESCE(released_by, created_by)
         WHERE status IN ("Under Action", "Completed")
           AND (released_at IS NULL OR released_by IS NULL)'
    );
    $pdo->exec(
        "UPDATE documents
         SET ocr_status = 'verified',
             ocr_reviewed_by = COALESCE(ocr_reviewed_by, released_by, created_by),
             ocr_reviewed_at = COALESCE(ocr_reviewed_at, released_at, created_at, NOW()),
             ocr_review_note = COALESCE(NULLIF(TRIM(ocr_review_note), ''), 'Verified during document creation.')
         WHERE ocr_status = 'pending_review'"
    );

    backfill_document_qr_tokens($pdo);
    backfill_document_file_hashes($pdo);

    if (db_column_exists_raw($pdo, 'document_routes', 'from_office')) {
        $pdo->exec(
            'UPDATE document_routes dr
             LEFT JOIN offices o ON LOWER(TRIM(o.name)) = LOWER(TRIM(dr.from_office))
             SET dr.from_office_id = o.id
             WHERE dr.from_office_id IS NULL
               AND dr.from_office IS NOT NULL
               AND TRIM(dr.from_office) <> ""'
        );
    }
    if (db_column_exists_raw($pdo, 'document_routes', 'to_office')) {
        $pdo->exec(
            'UPDATE document_routes dr
             LEFT JOIN offices o ON LOWER(TRIM(o.name)) = LOWER(TRIM(dr.to_office))
             SET dr.to_office_id = o.id
             WHERE dr.to_office_id IS NULL
               AND dr.to_office IS NOT NULL
               AND TRIM(dr.to_office) <> ""'
        );
    }
    $pdo->prepare('UPDATE document_routes SET from_office_id = :office_id WHERE from_office_id IS NULL')->execute([
        'office_id' => $systemOfficeId,
    ]);
    $pdo->prepare('UPDATE document_routes SET to_office_id = :office_id WHERE to_office_id IS NULL')->execute([
        'office_id' => $systemOfficeId,
    ]);
    $pdo->prepare(
        "UPDATE document_routes
         SET handoff_type = CASE
             WHEN to_office_id = :records_office_id THEN 'office_to_records'
             ELSE 'records_to_office'
         END
         WHERE handoff_type IS NULL"
    )->execute([
        'records_office_id' => $systemOfficeId,
    ]);
    $pdo->exec(
        "UPDATE document_routes
         SET route_status = 'received',
             received_at = COALESCE(received_at, routed_at),
             received_by = COALESCE(received_by, routed_by)
         WHERE route_status = 'received'
           AND received_at IS NULL"
    );
    $pdo->exec(
        "UPDATE documents
         SET status = CASE
             WHEN status = 'Draft' THEN 'Draft'
             WHEN status = 'Submitted' THEN 'Submitted'
             WHEN status = 'Rejected' THEN 'Rejected'
             WHEN status = 'Completed' THEN 'Completed'
             WHEN status = 'Archived' THEN 'Archived'
             ELSE 'Under Action'
         END
         WHERE status NOT IN ('Draft', 'Submitted', 'Rejected', 'Under Action', 'Completed', 'Archived')"
    );
    $simplifiedStatusMeta = db_column_metadata($pdo, 'documents', 'status');
    $simplifiedStatusType = (string) ($simplifiedStatusMeta['column_type'] ?? '');
    if ($simplifiedStatusMeta !== null
        && (
            !str_contains($simplifiedStatusType, "'Under Action'")
            || !str_contains($simplifiedStatusType, "'Draft'")
            || !str_contains($simplifiedStatusType, "'Submitted'")
            || !str_contains($simplifiedStatusType, "'Rejected'")
            || !str_contains($simplifiedStatusType, "'Completed'")
            || !str_contains($simplifiedStatusType, "'Archived'")
            || str_contains($simplifiedStatusType, "'Logged'")
            || str_contains($simplifiedStatusType, "'Forwarded'")
            || str_contains($simplifiedStatusType, "'On Hold'")
        )) {
        $pdo->exec(
            "ALTER TABLE documents MODIFY status ENUM(
                'Draft',
                'Submitted',
                'Rejected',
                'Under Action',
                'Completed',
                'Archived'
            ) NOT NULL DEFAULT 'Draft'"
        );
    }

    $timelineEventMeta = db_column_metadata($pdo, 'document_timeline_events', 'event_type');
    $timelineEventType = (string) ($timelineEventMeta['column_type'] ?? '');
    if ($timelineEventMeta !== null
        && (
            !str_contains($timelineEventType, "'released'")
            || !str_contains($timelineEventType, "'draft_created'")
            || !str_contains($timelineEventType, "'submitted'")
            || !str_contains($timelineEventType, "'rejected'")
            || !str_contains($timelineEventType, "'office_forwarded'")
            || !str_contains($timelineEventType, "'remarked'")
            || !str_contains($timelineEventType, "'ocr_verified'")
            || !str_contains($timelineEventType, "'ocr_rejected'")
            || !str_contains($timelineEventType, "'location_updated'")
            || !str_contains($timelineEventType, "'archive_restored'")
        )) {
        $pdo->exec(
            "ALTER TABLE document_timeline_events MODIFY event_type ENUM(
                'registered',
                'draft_created',
                'submitted',
                'released',
                'rejected',
                'intake_validated',
                'forwarded',
                'office_forwarded',
                'received',
                'returned_to_records',
                'remarked',
                'hold_placed',
                'hold_resolved',
                'completed',
                'archived',
                'file_validated',
                'ocr_verified',
                'ocr_rejected',
                'location_updated',
                'archive_restored'
            ) NOT NULL"
        );
    }

    backfill_document_timeline_events($pdo);
    run_pending_schema_migrations($pdo);

    foreach (
        [
            ['users', 'office_name'],
            ['documents', 'category'],
            ['documents', 'origin_office'],
            ['documents', 'current_office'],
            ['document_routes', 'from_office'],
            ['document_routes', 'to_office'],
        ] as [$table, $column]
    ) {
        normalize_legacy_text_column_nullable($pdo, $table, $column);
    }

    cleanup_empty_legacy_text_columns($pdo);
}

function database_status(): array
{
    static $status = null;

    if (is_array($status)) {
        return $status;
    }

    $status = [
        'connected' => false,
        'ready' => false,
        'missing_tables' => [],
        'pending_migrations' => [],
        'migrations_auto_run' => MIGRATIONS_AUTO_RUN,
        'error' => null,
    ];

    try {
        $pdo = db();
        $status['connected'] = true;

        if (MIGRATIONS_AUTO_RUN) {
            ensure_database_schema($pdo);
        }

        $placeholders = implode(', ', array_fill(0, count(required_tables()), '?'));
        $stmt = $pdo->prepare(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)"
        );
        $stmt->execute(required_tables());

        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $status['missing_tables'] = array_values(array_diff(required_tables(), $existingTables));
        $status['pending_migrations'] = pending_schema_migrations($pdo);
        $status['migrations_auto_run'] = MIGRATIONS_AUTO_RUN;
        $status['ready'] = $status['missing_tables'] === [];
    } catch (Throwable $e) {
        $status['error'] = $e->getMessage();
    }

    return $status;
}

function table_exists(string $table): bool
{
    try {
        $pdo = db();
        if (MIGRATIONS_AUTO_RUN) {
            ensure_database_schema($pdo);
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function system_is_ready(): bool
{
    return database_status()['ready'];
}

function database_unavailable_message(?array $dbStatus = null): string
{
    $dbStatus ??= database_status();

    if (!($dbStatus['connected'] ?? false)) {
        $message = 'Database connection failed. Verify the database settings in config/config.php.';
    } elseif (!empty($dbStatus['missing_tables'])) {
        $message = 'Database schema is incomplete. Missing required tables: ' . implode(', ', $dbStatus['missing_tables']) . '.';
    } else {
        $message = 'Database readiness check failed.';
    }

    if (!empty($dbStatus['error'])) {
        $message .= ' Current error: ' . (string) $dbStatus['error'];
    }

    return $message;
}

// User and metadata lookups

function users_count(): int
{
    $dbStatus = database_status();

    try {
        if (!$dbStatus['ready']) {
            return 0;
        }

        return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function load_user_by_id(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, o.name AS office_name
         FROM users u
         LEFT JOIN offices o ON o.id = u.office_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function load_user_by_email(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, o.name AS office_name
         FROM users u
         LEFT JOIN offices o ON o.id = u.office_id
         WHERE u.email = :email
           AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function load_user_by_email_any_status(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, o.name AS office_name
         FROM users u
         LEFT JOIN offices o ON o.id = u.office_id
         WHERE u.email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function load_user_by_uid_any_status(string $userUid): ?array
{
    $normalized = normalize_user_uid($userUid);
    if ($normalized === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.*, o.name AS office_name
         FROM users u
         LEFT JOIN offices o ON o.id = u.office_id
         WHERE UPPER(REPLACE(u.user_uid, \' \', \'\')) = :user_uid
         LIMIT 1'
    );
    $stmt->execute(['user_uid' => $normalized]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function load_email_otp_by_id(int $id): ?array
{
    if ($id <= 0 || !table_exists('email_otps')) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM email_otps WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $otp = $stmt->fetch();

    return is_array($otp) ? $otp : null;
}

function cleanup_email_otps(): void
{
    if (!table_exists('email_otps')) {
        return;
    }

    db()->exec(
        'DELETE FROM email_otps
         WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
            OR (expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY))'
    );
}

function auth_rate_limit_identity(string $identity): string
{
    $identity = strtolower(trim($identity));
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = app_key_is_configured() ? (string) APP_KEY : 'local-rate-limit-key';

    return hash_hmac('sha256', $identity . '|' . $ipAddress, $key);
}

function auth_rate_limit_remaining_seconds(string $scope, string $identity): int
{
    if (!table_exists('auth_rate_limits')) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT locked_until
         FROM auth_rate_limits
         WHERE scope = :scope
           AND identity_hash = :identity_hash
         LIMIT 1'
    );
    $stmt->execute([
        'scope' => $scope,
        'identity_hash' => auth_rate_limit_identity($identity),
    ]);
    $lockedUntil = (string) $stmt->fetchColumn();
    if ($lockedUntil === '') {
        return 0;
    }

    try {
        $lockedUntilDate = new DateTime($lockedUntil, app_timezone());
    } catch (Exception $e) {
        return 0;
    }

    $remaining = $lockedUntilDate->getTimestamp() - time();

    return max(0, $remaining);
}

function auth_rate_limit_record_failure(string $scope, string $identity): void
{
    if (!table_exists('auth_rate_limits')) {
        return;
    }

    $identityHash = auth_rate_limit_identity($identity);
    $stmt = db()->prepare(
        'SELECT attempts, last_attempt_at
         FROM auth_rate_limits
         WHERE scope = :scope
           AND identity_hash = :identity_hash
         LIMIT 1'
    );
    $stmt->execute([
        'scope' => $scope,
        'identity_hash' => $identityHash,
    ]);
    $record = $stmt->fetch();

    $attempts = 1;
    if (is_array($record)) {
        $lastAttempt = null;
        try {
            $lastAttempt = new DateTime((string) $record['last_attempt_at'], app_timezone());
        } catch (Exception $e) {
            $lastAttempt = null;
        }

        if ($lastAttempt instanceof DateTime && $lastAttempt->getTimestamp() >= time() - login_rate_limit_window_seconds()) {
            $attempts = ((int) $record['attempts']) + 1;
        }
    }

    $lockedUntilSql = $attempts >= login_rate_limit_attempts()
        ? 'DATE_ADD(NOW(), INTERVAL ' . login_rate_limit_lock_seconds() . ' SECOND)'
        : 'NULL';

    $stmt = db()->prepare(
        'INSERT INTO auth_rate_limits (scope, identity_hash, attempts, locked_until, last_attempt_at, created_at, updated_at)
         VALUES (:scope, :identity_hash, :attempts, ' . $lockedUntilSql . ', NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             attempts = VALUES(attempts),
             locked_until = ' . $lockedUntilSql . ',
             last_attempt_at = NOW(),
             updated_at = NOW()'
    );
    $stmt->execute([
        'scope' => $scope,
        'identity_hash' => $identityHash,
        'attempts' => $attempts,
    ]);
}

function auth_rate_limit_clear(string $scope, string $identity): void
{
    if (!table_exists('auth_rate_limits')) {
        return;
    }

    $stmt = db()->prepare(
        'DELETE FROM auth_rate_limits
         WHERE scope = :scope
           AND identity_hash = :identity_hash'
    );
    $stmt->execute([
        'scope' => $scope,
        'identity_hash' => auth_rate_limit_identity($identity),
    ]);
}

function auth_rate_limit_message(int $seconds): string
{
    $minutes = max(1, (int) ceil($seconds / 60));

    return 'Too many attempts. Please try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

function invalidate_existing_otps(int $userId, string $purpose): void
{
    if ($userId <= 0 || !table_exists('email_otps')) {
        return;
    }

    $stmt = db()->prepare(
        'UPDATE email_otps
         SET consumed_at = COALESCE(consumed_at, NOW())
         WHERE user_id = :user_id
           AND purpose = :purpose
           AND consumed_at IS NULL'
    );
    $stmt->execute([
        'user_id' => $userId,
        'purpose' => $purpose,
    ]);
}

function generate_email_otp_code(): string
{
    $code = '';
    for ($i = 0; $i < otp_code_length(); $i++) {
        $code .= (string) random_int(0, 9);
    }

    return $code;
}

function hash_email_otp_code(string $code): string
{
    return hash_hmac('sha256', $code, (string) APP_KEY);
}

function create_email_otp(array $user, string $purpose): array
{
    require_mail_ready();

    if ($purpose !== 'recovery') {
        throw new InvalidArgumentException('Unsupported OTP purpose.');
    }

    if (!table_exists('email_otps')) {
        throw new RuntimeException('The OTP storage table is not available.');
    }

    cleanup_email_otps();
    invalidate_existing_otps((int) $user['id'], $purpose);

    $code = generate_email_otp_code();
    $stmt = db()->prepare(
        'INSERT INTO email_otps (user_id, email, purpose, code_hash, attempt_count, expires_at, consumed_at, created_at, requested_ip)
         VALUES (:user_id, :email, :purpose, :code_hash, 0, DATE_ADD(NOW(), INTERVAL :expires MINUTE), NULL, NOW(), :requested_ip)'
    );
    $stmt->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':email', (string) $user['email'], PDO::PARAM_STR);
    $stmt->bindValue(':purpose', $purpose, PDO::PARAM_STR);
    $stmt->bindValue(':code_hash', hash_email_otp_code($code), PDO::PARAM_STR);
    $stmt->bindValue(':expires', otp_expires_minutes(), PDO::PARAM_INT);
    $stmt->bindValue(':requested_ip', $_SERVER['REMOTE_ADDR'] ?? null, PDO::PARAM_STR);
    $stmt->execute();

    $otp = load_email_otp_by_id((int) db()->lastInsertId());
    if ($otp === null) {
        throw new RuntimeException('Unable to create the email verification record.');
    }

    $otp['plain_code'] = $code;

    return $otp;
}

function build_mailer(): PHPMailer
{
    require_smtp_ready();

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) MAIL_HOST;
    $mailer->Port = (int) MAIL_PORT;
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) MAIL_USERNAME;
    $mailer->Password = (string) MAIL_PASSWORD;
    $mailer->CharSet = 'UTF-8';
    $mailer->isHTML(true);

    $encryption = trim((string) MAIL_ENCRYPTION);
    if ($encryption !== '') {
        $mailer->SMTPSecure = $encryption;
    }

    $mailer->setFrom((string) MAIL_FROM_ADDRESS, mail_from_name());

    return $mailer;
}

function email_otp_copy(string $purpose): array
{
    if ($purpose !== 'recovery') {
        throw new InvalidArgumentException('Unsupported OTP purpose.');
    }

    return [
        'subject' => APP_NAME . ' Password Reset Code',
        'heading' => 'Your password reset code',
        'summary' => 'Use this code to continue resetting your password.',
    ];
}

function send_email_otp(array $user, string $purpose, string $code): void
{
    $copy = email_otp_copy($purpose);
    $mailer = build_mailer();
    $mailer->addAddress((string) $user['email'], (string) ($user['full_name'] ?? ''));
    $mailer->Subject = $copy['subject'];

    $expiresLabel = otp_expires_minutes() . ' minute' . (otp_expires_minutes() === 1 ? '' : 's');
    $safeName = e((string) ($user['full_name'] ?? 'User'));
    $safeApp = e(APP_NAME);
    $safeHeading = e($copy['heading']);
    $safeSummary = e($copy['summary']);
    $safeCode = e($code);

    $mailer->Body = <<<HTML
<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #243447;">
    <p>Hello {$safeName},</p>
    <p>{$safeSummary}</p>
    <p style="margin: 24px 0;">
        <span style="display: inline-block; padding: 14px 20px; font-size: 28px; font-weight: 700; letter-spacing: 0.35em; background: #f3f7ff; border: 1px solid #d7e3f8; border-radius: 12px; color: #123a7a;">{$safeCode}</span>
    </p>
    <p>This code expires in {$expiresLabel}.</p>
    <p>If you did not request this code, you can ignore this message.</p>
    <p style="margin-top: 24px;">{$safeHeading}<br>{$safeApp}</p>
</div>
HTML;

    $mailer->AltBody = implode(PHP_EOL, [
        'Hello ' . (string) ($user['full_name'] ?? 'User') . ',',
        '',
        $copy['summary'],
        'Code: ' . $code,
        'This code expires in ' . $expiresLabel . '.',
        'If you did not request this code, you can ignore this message.',
        '',
        APP_NAME,
    ]);

    try {
        $mailer->send();
    } catch (MailException $e) {
        throw new RuntimeException('Unable to send the verification code email. Please check the SMTP settings and try again.');
    }
}

function send_temporary_password_email(array $user, string $temporaryPassword, string $context): void
{
    $mailer = build_mailer();
    $mailer->addAddress((string) $user['email'], (string) ($user['full_name'] ?? ''));

    $isReset = $context === 'reset';
    if (!in_array($context, ['created', 'reset'], true)) {
        throw new InvalidArgumentException('Unsupported temporary password email context.');
    }

    $mailer->Subject = APP_NAME . ($isReset ? ' Temporary Password Reset' : ' Account Created');

    $loginUrl = app_public_base_url() . '/auth/login.php';
    $safeName = e((string) ($user['full_name'] ?? 'User'));
    $safeApp = e(APP_NAME);
    $safeUserUid = e((string) ($user['user_uid'] ?? ''));
    $safeTemporaryPassword = e($temporaryPassword);
    $safeLoginUrl = e($loginUrl);
    $safeIntro = $isReset
        ? 'Your temporary password has been reset by the administrator.'
        : 'Your ' . $safeApp . ' account has been created.';

    $mailer->Body = <<<HTML
<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #243447;">
    <p>Hello {$safeName},</p>
    <p>{$safeIntro}</p>
    <p style="margin: 20px 0;">
        <span style="display: inline-block; padding: 12px 16px; font-size: 18px; font-weight: 700; background: #f3f7ff; border: 1px solid #d7e3f8; border-radius: 8px; color: #123a7a;">User ID: {$safeUserUid}</span>
    </p>
    <p style="margin: 20px 0;">
        <span style="display: inline-block; padding: 12px 16px; font-size: 18px; font-weight: 700; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; color: #7c2d12;">Temporary password: {$safeTemporaryPassword}</span>
    </p>
    <p>Use this temporary password when you sign in. You will be asked to change it before continuing.</p>
    <p><a href="{$safeLoginUrl}" style="color: #1557b0;">Sign in to {$safeApp}</a></p>
    <p>If you were not expecting this message, please contact the administrator.</p>
    <p style="margin-top: 24px;">{$safeApp}</p>
</div>
HTML;

    $mailer->AltBody = implode(PHP_EOL, [
        'Hello ' . (string) ($user['full_name'] ?? 'User') . ',',
        '',
        $isReset
            ? 'Your temporary password has been reset by the administrator.'
            : 'Your ' . APP_NAME . ' account has been created.',
        'User ID: ' . (string) ($user['user_uid'] ?? ''),
        'Temporary password: ' . $temporaryPassword,
        '',
        'Use this temporary password when you sign in. You will be asked to change it before continuing.',
        'Login: ' . $loginUrl,
        '',
        'If you were not expecting this message, please contact the administrator.',
        '',
        APP_NAME,
    ]);

    try {
        $mailer->send();
    } catch (MailException $e) {
        throw new RuntimeException('Unable to send the temporary password email. Please check the SMTP settings and try again.');
    }
}

function send_account_created_email(array $user, string $temporaryPassword): void
{
    send_temporary_password_email($user, $temporaryPassword, 'created');
}

function send_account_request_rejected_email(array $request, string $reason): void
{
    $mailer = build_mailer();
    $email = strtolower(trim((string) ($request['email'] ?? '')));
    $name = (string) ($request['full_name'] ?? 'User');

    if ($email === '') {
        throw new InvalidArgumentException('The account request does not contain a valid email address.');
    }

    $mailer->addAddress($email, $name);
    $mailer->Subject = APP_NAME . ' Account Request Rejected';

    $safeName = e($name);
    $safeApp = e(APP_NAME);
    $safeReason = nl2br(e($reason));

    $mailer->Body = <<<HTML
<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #243447;">
    <p>Hello {$safeName},</p>
    <p>Your request for an account in <strong>{$safeApp}</strong> has been rejected by the administrator.</p>
    <p style="margin: 20px 0; padding: 14px 16px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px;">
        <strong>Reason:</strong><br>{$safeReason}
    </p>
    <p>If you believe this was rejected by mistake, please contact the system administrator or your office.</p>
    <p style="margin-top: 24px;">{$safeApp}</p>
</div>
HTML;

    $mailer->AltBody = implode(PHP_EOL, [
        'Hello ' . $name . ',',
        '',
        'Your request for an account in ' . APP_NAME . ' has been rejected by the administrator.',
        '',
        'Reason: ' . $reason,
        '',
        'If you believe this was rejected by mistake, please contact the system administrator or your office.',
        '',
        APP_NAME,
    ]);

    try {
        $mailer->send();
    } catch (MailException $e) {
        throw new RuntimeException('Unable to send the account rejection email. Please check the SMTP settings and try again.');
    }
}

function send_temporary_password_reset_email(array $user, string $temporaryPassword): void
{
    send_temporary_password_email($user, $temporaryPassword, 'reset');
}

function verify_email_otp(array $challenge, string $code): array
{
    $freshChallenge = load_email_otp_by_id((int) ($challenge['id'] ?? 0));
    if ($freshChallenge === null) {
        return [
            'ok' => false,
            'message' => 'That verification code is invalid or has expired. Request a new code and try again.',
        ];
    }

    if (($freshChallenge['consumed_at'] ?? null) !== null) {
        return [
            'ok' => false,
            'message' => 'That verification code has already been used. Request a new code and try again.',
        ];
    }

    if ((int) ($freshChallenge['attempt_count'] ?? 0) >= otp_max_attempts()) {
        return [
            'ok' => false,
            'message' => 'That verification code has reached the maximum number of attempts. Request a new code and try again.',
        ];
    }

    try {
        $expiresAt = new DateTime((string) $freshChallenge['expires_at'], app_timezone());
    } catch (Exception $e) {
        $expiresAt = null;
    }

    if (!$expiresAt instanceof DateTime || $expiresAt < new DateTime('now', app_timezone())) {
        return [
            'ok' => false,
            'message' => 'That verification code has expired. Request a new code and try again.',
        ];
    }

    $normalizedCode = preg_replace('/\D+/', '', $code) ?? '';
    if (!hash_equals((string) $freshChallenge['code_hash'], hash_email_otp_code($normalizedCode))) {
        $stmt = db()->prepare('UPDATE email_otps SET attempt_count = attempt_count + 1 WHERE id = :id');
        $stmt->execute(['id' => (int) $freshChallenge['id']]);

        $updatedChallenge = load_email_otp_by_id((int) $freshChallenge['id']);
        if ((int) ($updatedChallenge['attempt_count'] ?? 0) >= otp_max_attempts()) {
            return [
                'ok' => false,
                'message' => 'That verification code has reached the maximum number of attempts. Request a new code and try again.',
            ];
        }

        return [
            'ok' => false,
            'message' => 'The verification code you entered is incorrect.',
        ];
    }

    return [
        'ok' => true,
        'challenge' => $freshChallenge,
    ];
}

function consume_email_otp(int $id): void
{
    if ($id <= 0 || !table_exists('email_otps')) {
        return;
    }

    $stmt = db()->prepare(
        'UPDATE email_otps
         SET consumed_at = COALESCE(consumed_at, NOW())
         WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
}

function email_otp_resend_remaining(array $challenge): int
{
    try {
        $createdAt = new DateTime((string) ($challenge['created_at'] ?? ''), app_timezone());
    } catch (Exception $e) {
        return 0;
    }

    $currentTime = new DateTime('now', app_timezone());
    $remaining = otp_resend_cooldown_seconds() - ($currentTime->getTimestamp() - $createdAt->getTimestamp());

    return max(0, $remaining);
}

function refresh_current_user(): void
{
    $user = current_user();
    if ($user === null) {
        return;
    }

    $freshUser = load_user_by_id((int) $user['id']);
    if ($freshUser === null || (int) $freshUser['is_active'] !== 1) {
        unset($_SESSION['user']);
        if ($freshUser !== null && (int) $freshUser['is_active'] !== 1) {
            set_auth_logout_reason(auth_logout_reason_deactivated());
        }
        return;
    }

    $_SESSION['user'] = session_user_payload($freshUser);
}

function load_offices(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM offices';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';

    return db()->query($sql)->fetchAll();
}

function load_categories(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM document_categories';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';

    return db()->query($sql)->fetchAll();
}

function archive_intake_category_names(): array
{
    return records_office_archive_category_names();
}

function records_office_archive_category_names(): array
{
    return ['Travel Order', 'Office Order', 'Memorandum'];
}

function records_office_archive_category_name_matches(string $categoryName): bool
{
    $categoryName = strtolower(trim($categoryName));
    if ($categoryName === '') {
        return false;
    }

    $allowed = array_map(
        static fn (string $name): string => strtolower(trim($name)),
        records_office_archive_category_names()
    );

    return in_array($categoryName, $allowed, true);
}

function records_office_archive_category_condition(string $documentAlias = 'd', string $paramPrefix = 'records_archive_category_', bool $negate = false): array
{
    $names = records_office_archive_category_names();
    $placeholders = [];
    $params = [];

    foreach ($names as $index => $name) {
        $key = $paramPrefix . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = strtolower($name);
    }

    $categorySubquery = 'SELECT id FROM document_categories WHERE LOWER(name) IN (' . implode(', ', $placeholders) . ')';
    $sql = $documentAlias . '.category_id IN (' . $categorySubquery . ')';
    if ($negate) {
        $sql = '(' . $documentAlias . '.category_id IS NULL OR ' . $documentAlias . '.category_id NOT IN (' . $categorySubquery . '))';
    }

    return ['sql' => $sql, 'params' => $params];
}

function document_archive_access_condition(string $documentAlias = 'd', string $paramPrefix = 'archive_scope_'): array
{
    if (can_finalize_document_workflow()) {
        return records_office_archive_category_condition($documentAlias, $paramPrefix . 'records_category_');
    }

    if (!can_access_document_module()) {
        return ['sql' => '1 = 0', 'params' => []];
    }

    $conditions = [];
    $params = [];
    $officeId = current_office_id();
    $user = current_user();

    if (has_any_role(['office_staff', 'issuing_authority']) && $officeId !== null) {
        $conditions[] = $documentAlias . '.origin_office_id = :' . $paramPrefix . 'origin_office_id';
        $params[$paramPrefix . 'origin_office_id'] = $officeId;
    }

    if (has_role('issuing_authority') && is_array($user)) {
        $conditions[] = $documentAlias . '.created_by = :' . $paramPrefix . 'creator_user_id';
        $params[$paramPrefix . 'creator_user_id'] = (int) $user['id'];
    }

    if ($conditions === []) {
        return ['sql' => '1 = 0', 'params' => []];
    }

    return [
        'sql' => '(' . implode(' OR ', $conditions) . ')',
        'params' => $params,
    ];
}

function document_report_scope_condition(string $documentAlias = 'd', string $paramPrefix = 'report_scope_'): array
{
    if (has_role('records_officer')) {
        return records_office_archive_category_condition($documentAlias, $paramPrefix . 'records_category_');
    }

    if (!has_any_role(['office_staff', 'issuing_authority'])) {
        return ['sql' => '1 = 0', 'params' => []];
    }

    $user = current_user();
    $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    if ($userId <= 0) {
        return ['sql' => '1 = 0', 'params' => []];
    }

    $conditions = [
        $documentAlias . '.created_by = :' . $paramPrefix . 'creator_user_id',
    ];
    $params = [
        $paramPrefix . 'creator_user_id' => $userId,
    ];

    $officeId = current_office_id();
    if (has_role('issuing_authority') && $officeId !== null) {
        $conditions[] = $documentAlias . '.created_by IN (SELECT id FROM users WHERE office_id = :' . $paramPrefix . 'creator_office_id)';
        $params[$paramPrefix . 'creator_office_id'] = $officeId;
    }

    return [
        'sql' => '(' . implode(' OR ', $conditions) . ')',
        'params' => $params,
    ];
}

function document_is_records_office_archive_category(array $document): bool
{
    $categoryName = trim((string) ($document['category_name'] ?? ''));
    if ($categoryName === '' && isset($document['category_id'])) {
        $category = load_category_by_id((int) $document['category_id']);
        $categoryName = is_array($category) ? trim((string) ($category['name'] ?? '')) : '';
    }

    return records_office_archive_category_name_matches($categoryName);
}

function document_is_records_office_archive_lock_target(array $document): bool
{
    if (!in_array((string) ($document['status'] ?? ''), ['Completed', 'Archived'], true)) {
        return false;
    }

    return document_is_records_office_archive_category($document);
}

function document_is_locked_records_archive_for_current_user(array $document): bool
{
    return has_role('records_officer') && document_is_records_office_archive_lock_target($document);
}

function current_user_can_archive_as_source_office(array $document): bool
{
    if (!has_any_role(['office_staff', 'issuing_authority'])) {
        return false;
    }

    if (document_is_records_office_archive_category($document)) {
        return false;
    }

    $officeId = current_office_id();

    return $officeId !== null && (int) ($document['origin_office_id'] ?? 0) === $officeId;
}

function current_user_can_view_archived_source_document(array $document): bool
{
    if (!has_any_role(['office_staff', 'issuing_authority'])) {
        return false;
    }

    if ((string) ($document['status'] ?? '') !== 'Archived') {
        return false;
    }

    $officeId = current_office_id();

    return $officeId !== null && (int) ($document['origin_office_id'] ?? 0) === $officeId;
}

function load_archive_intake_categories(): array
{
    $names = archive_intake_category_names();
    $placeholders = [];
    $params = [];
    foreach ($names as $index => $name) {
        $key = 'name_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = strtolower($name);
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM document_categories
         WHERE is_active = 1
           AND LOWER(name) IN (' . implode(', ', $placeholders) . ')
         ORDER BY FIELD(name, "Travel Order", "Office Order", "Memorandum"), name ASC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function archive_intake_category_exists(int $categoryId): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $categories = load_archive_intake_categories();
    foreach ($categories as $category) {
        if ((int) $category['id'] === $categoryId) {
            return true;
        }
    }

    return false;
}

function load_office_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM offices WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $office = $stmt->fetch();

    return is_array($office) ? $office : null;
}

function load_category_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM document_categories WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $category = $stmt->fetch();

    return is_array($category) ? $category : null;
}

function office_exists(int $officeId, bool $activeOnly = true): bool
{
    if ($officeId <= 0) {
        return false;
    }

    $sql = 'SELECT COUNT(*) FROM offices WHERE id = :id';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $officeId]);

    return (int) $stmt->fetchColumn() > 0;
}

function category_exists(int $categoryId, bool $activeOnly = true): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $sql = 'SELECT COUNT(*) FROM document_categories WHERE id = :id';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $categoryId]);

    return (int) $stmt->fetchColumn() > 0;
}

function office_lookup(): array
{
    $rows = db()->query('SELECT id, name FROM offices')->fetchAll();
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[(int) $row['id']] = $row['name'];
    }

    return $lookup;
}

function category_lookup(): array
{
    $rows = db()->query('SELECT id, name FROM document_categories')->fetchAll();
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[(int) $row['id']] = $row['name'];
    }

    return $lookup;
}

function document_status_badge(string $status): string
{
    $map = [
        'Draft' => 'badge muted',
        'Submitted' => 'badge warning',
        'Rejected' => 'badge danger',
        'Under Action' => 'badge info',
        'Completed' => 'badge success',
        'Archived' => 'badge dark',
    ];

    return $map[$status] ?? 'badge';
}

function document_status_filter_options(): array
{
    return ['Draft', 'Submitted', 'Rejected', 'Under Action', 'Completed', 'Archived'];
}

function document_index_status_filter_options(): array
{
    if (has_role('records_officer')) {
        return ['Completed'];
    }

    return array_values(array_filter(
        document_status_filter_options(),
        static fn (string $option): bool => $option !== 'Archived'
    ));
}

function document_report_status_filter_options(): array
{
    if (has_role('records_officer')) {
        return ['Completed', 'Archived'];
    }

    if (has_any_role(['office_staff', 'issuing_authority'])) {
        return document_status_filter_options();
    }

    return [];
}

function editable_document_status_options(): array
{
    return document_status_filter_options();
}

function document_unreleased_statuses(): array
{
    return ['Draft', 'Submitted', 'Rejected'];
}

function document_official_statuses(): array
{
    return ['Under Action', 'Completed', 'Archived'];
}

function document_is_unreleased(array $document): bool
{
    return in_array((string) ($document['status'] ?? ''), document_unreleased_statuses(), true);
}

function document_is_official(array $document): bool
{
    return in_array((string) ($document['status'] ?? ''), document_official_statuses(), true);
}

function document_can_have_qr(array $document): bool
{
    return document_is_official($document);
}

function document_can_print_qr(array $document): bool
{
    return document_can_have_qr($document) && trim((string) ($document['qr_token'] ?? '')) !== '';
}

function ocr_status_label(?string $status): string
{
    return match ((string) $status) {
        'pending_review' => 'Pending Review',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'manual' => 'Manual Entry',
        default => 'Manual Entry',
    };
}

function ocr_status_badge(?string $status): string
{
    return match ((string) $status) {
        'pending_review' => 'badge warning',
        'verified' => 'badge success',
        'rejected' => 'badge danger',
        default => 'badge muted',
    };
}

function route_status_label(string $status): string
{
    return match ($status) {
        'pending_receipt' => 'Pending Receipt',
        'cancelled' => 'Cancelled',
        default => 'Received',
    };
}

function route_is_pending(?array $route): bool
{
    return is_array($route) && ($route['route_status'] ?? '') === 'pending_receipt';
}

// File and display helpers

function normalize_filename(string $filename): string
{
    $filename = trim(basename($filename));
    $filename = preg_replace('/[^\w.\- ]+/', '_', $filename) ?? $filename;

    return $filename !== '' ? $filename : 'document';
}

function max_upload_bytes(): int
{
    return max(1024, (int) MAX_UPLOAD_BYTES);
}

function format_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $index = 0;

    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return rtrim(rtrim(number_format($size, 2), '0'), '.') . ' ' . $units[$index];
}

function upload_extension_mime_map(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-word', 'application/x-cfb', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
}

function file_signature_matches(string $path, string $ext): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $bytes = fread($handle, 8);
    fclose($handle);
    if (!is_string($bytes)) {
        return false;
    }

    $hex = bin2hex($bytes);

    return match ($ext) {
        'pdf' => str_starts_with($bytes, '%PDF-'),
        'jpg', 'jpeg' => str_starts_with($hex, 'ffd8ff'),
        'png' => str_starts_with($hex, '89504e470d0a1a0a'),
        'doc' => str_starts_with($hex, 'd0cf11e0a1b11ae1'),
        'docx' => str_starts_with($bytes, 'PK') && docx_structure_matches($path),
        default => false,
    };
}

function docx_structure_matches(string $path): bool
{
    if (!class_exists(ZipArchive::class)) {
        return true;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }

    $matches = $zip->locateName('[Content_Types].xml') !== false
        && $zip->locateName('word/document.xml') !== false;
    $zip->close();

    return $matches;
}

function upload_file(array $file, string $targetDir): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $original = normalize_filename((string) ($file['name'] ?? 'document'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedMimeMap = upload_extension_mime_map();

    if (!isset($allowedMimeMap[$ext])) {
        throw new RuntimeException('Invalid file type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Uploaded file is empty.');
    }
    if ($size > max_upload_bytes()) {
        throw new RuntimeException('Uploaded file is too large. Maximum size is ' . format_bytes(max_upload_bytes()) . '.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Uploaded file could not be verified.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName) ?: 'application/octet-stream';
    if (!in_array($mime, $allowedMimeMap[$ext], true)) {
        throw new RuntimeException('Uploaded file content does not match the selected file type.');
    }
    if (!file_signature_matches($tmpName, $ext)) {
        throw new RuntimeException('Uploaded file signature could not be verified.');
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $storedName = uniqid('file_', true) . '.' . $ext;
    $targetPath = rtrim($targetDir, '/') . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Failed to upload file.');
    }

    return [
        'path' => $storedName,
        'original_name' => $original,
        'mime' => $mime,
        'size' => (int) filesize($targetPath),
    ];
}

function document_file_path(?string $filename): ?string
{
    if ($filename === null || $filename === '') {
        return null;
    }

    return base_path('uploads/documents/' . basename($filename));
}

function document_attachment_mime(array $document): string
{
    $mime = strtolower(trim((string) ($document['attachment_mime'] ?? '')));
    if ($mime !== '') {
        return explode(';', $mime)[0];
    }

    $filename = (string) (($document['attachment_original_name'] ?? '') ?: ($document['attachment_path'] ?? ''));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return match ($extension) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        default => 'application/octet-stream',
    };
}

function document_attachment_is_browser_viewable(array $document): bool
{
    return in_array(document_attachment_mime($document), ['application/pdf', 'image/jpeg', 'image/png'], true);
}

function cleanup_uploaded_file(?array $upload): void
{
    $path = is_array($upload) ? document_file_path((string) ($upload['path'] ?? '')) : null;
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

function csv_safe_cell(mixed $value): string
{
    $cell = (string) $value;
    $trimmed = ltrim($cell);
    if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
        return "'" . $cell;
    }

    return $cell;
}

function csv_write_row($handle, array $row): void
{
    fputcsv($handle, array_map('csv_safe_cell', $row));
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '-';
    }

    try {
        return (new DateTime($value, app_timezone()))->format('M d, Y h:i A');
    } catch (Exception $e) {
        return $value;
    }
}

function date_string_end_exclusive(string $value): string
{
    try {
        return (new DateTime($value, app_timezone()))->modify('+1 day')->format('Y-m-d');
    } catch (Exception $e) {
        return $value;
    }
}

function base_path(string $path = ''): string
{
    return ROOT_PATH . ($path ? '/' . ltrim($path, '/') : '');
}

function app_logo_url(): string
{
    return BASE_URL . '/assets/images/prmsu-logo.jpg';
}

function app_brand_logos(): array
{
    return [
        [
            'src' => app_logo_url(),
            'alt' => 'President Ramon Magsaysay State University logo',
        ],
    ];
}

function nav_is_active(array $paths): bool
{
    $current = str_replace('\\', '/', current_path());

    foreach ($paths as $path) {
        $candidate = str_replace('\\', '/', BASE_URL . $path);
        if (str_ends_with($path, '/')) {
            if (str_starts_with($current, $candidate)) {
                return true;
            }
        } elseif ($current === $candidate) {
            return true;
        }
    }

    return false;
}

// Document access and workflow helpers

function document_access_condition(string $alias = 'd', string $paramPrefix = 'scope_'): array
{
    if (can_manage_all_documents()) {
        return ['sql' => " AND {$alias}.status NOT IN ('Draft', 'Submitted', 'Rejected')", 'params' => []];
    }

    if (!can_access_document_module()) {
        return ['sql' => ' AND 1 = 0', 'params' => []];
    }

    $officeId = current_office_id();
    $user = current_user();
    if (has_role('issuing_authority') && is_array($user)) {
        $params = [
            "{$paramPrefix}user_id" => (int) $user['id'],
        ];

        if ($officeId !== null) {
            $params["{$paramPrefix}review_office_id"] = $officeId;
            $params["{$paramPrefix}current_office_id"] = $officeId;
            $params["{$paramPrefix}source_office_id"] = $officeId;
            $sourceCategoryScope = records_office_archive_category_condition($alias, "{$paramPrefix}source_category_", true);
            $params = array_merge($params, $sourceCategoryScope['params']);

            return [
                'sql' => " AND ({$alias}.created_by = :{$paramPrefix}user_id OR ({$alias}.status = 'Submitted' AND {$alias}.origin_office_id = :{$paramPrefix}review_office_id) OR ({$alias}.status NOT IN ('Draft', 'Submitted', 'Rejected') AND ({$alias}.current_office_id = :{$paramPrefix}current_office_id OR ({$alias}.origin_office_id = :{$paramPrefix}source_office_id AND {$sourceCategoryScope['sql']}))))",
                'params' => $params,
            ];
        }

        return [
            'sql' => " AND {$alias}.created_by = :{$paramPrefix}user_id",
            'params' => $params,
        ];
    }

    if ($officeId === null) {
        return ['sql' => ' AND 1 = 0', 'params' => []];
    }

    $sourceCategoryScope = records_office_archive_category_condition($alias, "{$paramPrefix}source_category_", true);
    $params = [
        "{$paramPrefix}user_id" => (int) ($user['id'] ?? 0),
        "{$paramPrefix}creator_official_user_id" => (int) ($user['id'] ?? 0),
        "{$paramPrefix}office_id" => $officeId,
        "{$paramPrefix}source_office_id" => $officeId,
    ];
    $params = array_merge($params, $sourceCategoryScope['params']);

    return [
        'sql' => " AND (({$alias}.status IN ('Draft', 'Submitted', 'Rejected') AND {$alias}.created_by = :{$paramPrefix}user_id) OR ({$alias}.status IN ('Under Action', 'Completed') AND {$alias}.created_by = :{$paramPrefix}creator_official_user_id) OR ({$alias}.status NOT IN ('Draft', 'Submitted', 'Rejected') AND ({$alias}.current_office_id = :{$paramPrefix}office_id OR ({$alias}.origin_office_id = :{$paramPrefix}source_office_id AND {$sourceCategoryScope['sql']}))))",
        'params' => $params,
    ];
}

function recycle_bin_access_condition(string $alias = 'd', string $paramPrefix = 'recycle_'): array
{
    return ['sql' => ' AND 1 = 0', 'params' => []];
}

function can_access_document(array $document): bool
{
    $userId = (int) (current_user()['id'] ?? 0);
    $creatorId = (int) ($document['created_by'] ?? 0);
    $officeId = current_office_id();

    if (document_is_unreleased($document)) {
        if ($userId > 0 && $creatorId > 0 && $userId === $creatorId) {
            return true;
        }

        return has_role('issuing_authority')
            && (string) ($document['status'] ?? '') === 'Submitted'
            && $officeId !== null
            && (int) ($document['origin_office_id'] ?? 0) === $officeId;
    }

    if (can_manage_all_documents()) {
        return true;
    }

    if (!can_access_document_module()) {
        return false;
    }

    if (can_edit_document_creator_official_metadata($document)) {
        return true;
    }

    if (has_role('issuing_authority')) {
        if ($userId > 0 && (int) ($document['created_by'] ?? 0) === $userId) {
            return true;
        }
    }

    if ($officeId === null) {
        return false;
    }

    if (isset($document['current_office_id']) && (int) $document['current_office_id'] === $officeId) {
        return true;
    }

    if (current_user_can_view_archived_source_document($document)) {
        return true;
    }

    if (current_user_can_archive_as_source_office($document)) {
        return true;
    }

    return false;
}

function can_access_document_attachment(array $document): bool
{
    if (empty($document['attachment_path'])) {
        return false;
    }

    if (document_is_official($document)) {
        return can_access_document($document);
    }

    if (!document_is_unreleased($document)) {
        return false;
    }

    return current_user_created_document($document) || can_review_document_release($document);
}

function can_view_document_private_logs(array $document): bool
{
    if (document_is_locked_records_archive_for_current_user($document)) {
        return false;
    }

    $userId = (int) (current_user()['id'] ?? 0);
    $creatorId = (int) ($document['created_by'] ?? 0);

    if ($userId > 0 && $creatorId > 0 && $userId === $creatorId) {
        return true;
    }

    if (can_manage_all_documents()) {
        return !document_is_unreleased($document);
    }

    return has_role('issuing_authority')
        && current_office_id() !== null
        && (int) ($document['origin_office_id'] ?? 0) === (int) current_office_id();
}

function can_access_recycle_bin_document(array $document): bool
{
    return false;
}

function require_document_or_redirect(
    int $id,
    string $fallback = '/documents/index.php',
    string $notFoundMessage = 'Document not found.',
    bool $includeDeleted = false
): array {
    $document = fetch_document($id, $includeDeleted);

    if ($document === null) {
        set_flash('error', $notFoundMessage);
        redirect($fallback);
    }

    return $document;
}

function require_accessible_document_or_redirect(
    int $id,
    string $fallback = '/documents/index.php',
    string $notFoundMessage = 'Document not found.',
    string $deniedMessage = 'You are not allowed to access that document.',
    bool $includeDeleted = false
): array {
    $document = require_document_or_redirect($id, $fallback, $notFoundMessage, $includeDeleted);

    if (!can_access_document($document)) {
        set_flash('error', $deniedMessage);
        redirect($fallback);
    }

    return $document;
}

// Audit log helpers

function audit_log(string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    try {
        if (!table_exists('audit_logs')) {
            return;
        }

        $userId = current_user()['id'] ?? null;
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId !== null ? (int) $userId : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
    }
}

function audit_log_filter_options(): array
{
    $defaults = [
        'actions' => [],
        'entity_types' => [],
        'users' => [],
    ];

    try {
        if (!table_exists('audit_logs')) {
            return $defaults;
        }

        return [
            'actions' => db()->query(
                'SELECT DISTINCT action
                 FROM audit_logs
                 WHERE action IS NOT NULL AND action <> ""
                 ORDER BY action ASC'
            )->fetchAll(PDO::FETCH_COLUMN),
            'entity_types' => db()->query(
                'SELECT DISTINCT entity_type
                 FROM audit_logs
                 WHERE entity_type IS NOT NULL AND entity_type <> ""
                 ORDER BY entity_type ASC'
            )->fetchAll(PDO::FETCH_COLUMN),
            'users' => db()->query(
                'SELECT DISTINCT a.user_id, u.full_name, u.email
                 FROM audit_logs a
                 INNER JOIN users u ON u.id = a.user_id
                 WHERE a.user_id IS NOT NULL
                 ORDER BY u.full_name ASC, u.email ASC'
            )->fetchAll(),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

function audit_log_value_to_string(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    if (!is_array($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : '[complex value]';
    }

    if ($value === []) {
        return '[]';
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    if ($isList) {
        return implode(', ', array_map(static fn ($item) => audit_log_value_to_string($item), $value));
    }

    $pairs = [];
    foreach ($value as $key => $item) {
        $pairs[] = (string) $key . '=' . audit_log_value_to_string($item);
    }

    return implode(', ', $pairs);
}

function audit_log_label(string $value): string
{
    return ucwords(str_replace('_', ' ', trim($value)));
}

function format_audit_log_details(?string $details): string
{
    $raw = trim((string) $details);
    if ($raw === '') {
        return 'No details';
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $raw;
    }

    if (!is_array($decoded)) {
        return audit_log_value_to_string($decoded);
    }

    if ($decoded === []) {
        return 'No details';
    }

    $lines = [];
    foreach ($decoded as $key => $value) {
        $label = is_string($key)
            ? audit_log_label($key)
            : 'Item ' . ((int) $key + 1);
        $lines[] = $label . ': ' . audit_log_value_to_string($value);
    }

    return $lines ? implode(PHP_EOL, $lines) : 'No details';
}

function generate_document_qr_token(): string
{
    return bin2hex(random_bytes(32));
}

function document_verification_code(?string $token): string
{
    $token = strtolower(trim((string) $token));
    if ($token === '') {
        return '-';
    }

    return strtoupper(substr($token, -8));
}

function app_public_base_url(): string
{
    if (defined('APP_PUBLIC_URL') && APP_PUBLIC_URL !== '') {
        return APP_PUBLIC_URL;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return BASE_URL;
    }

    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = ($https !== '' && strtolower((string) $https) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host . BASE_URL;
}

function app_public_url_is_https(): bool
{
    return defined('APP_PUBLIC_URL')
        && APP_PUBLIC_URL !== ''
        && str_starts_with(strtolower(APP_PUBLIC_URL), 'https://');
}

function production_public_url_is_ready(): bool
{
    return strtolower((string) APP_ENV) !== 'production' || app_public_url_is_https();
}

function document_qr_url(array $document): string
{
    $token = (string) ($document['qr_token'] ?? '');

    return app_public_base_url() . '/documents/scan.php?t=' . rawurlencode($token);
}

function extract_qr_token(string $raw): string
{
    $value = trim(html_entity_decode(rawurldecode($raw), ENT_QUOTES, 'UTF-8'));
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
        return strtolower($value);
    }

    $parts = parse_url($value);
    if (is_array($parts) && isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        if (isset($query['t']) && is_string($query['t']) && preg_match('/^[a-f0-9]{64}$/i', $query['t']) === 1) {
            return strtolower($query['t']);
        }
    }

    if (preg_match('/(?:^|[?&])t=([a-f0-9]{64})(?:&|$)/i', $value, $matches) === 1) {
        return strtolower($matches[1]);
    }

    return '';
}

function document_qr_token_matches(array $document, string $raw): bool
{
    $expectedToken = extract_qr_token((string) ($document['qr_token'] ?? ''));
    $submittedToken = extract_qr_token($raw);

    return $expectedToken !== ''
        && $submittedToken !== ''
        && hash_equals($expectedToken, $submittedToken);
}

function ensure_document_qr_token(int $documentId): string
{
    if ($documentId <= 0) {
        return '';
    }

    $stmt = db()->prepare('SELECT status, qr_token FROM documents WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $documentId]);
    $row = $stmt->fetch();
    if (!is_array($row) || !in_array((string) ($row['status'] ?? ''), document_official_statuses(), true)) {
        return '';
    }

    $existing = (string) ($row['qr_token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/i', $existing) === 1) {
        return strtolower($existing);
    }

    return '';
}

function backfill_document_qr_tokens(PDO $pdo): void
{
    if (!db_column_exists_raw($pdo, 'documents', 'qr_token')) {
        return;
    }

    $rows = $pdo->query(
        "SELECT id
         FROM documents
         WHERE status IN ('Under Action', 'Completed', 'Archived')
           AND (qr_token IS NULL OR qr_token = '')"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) {
        return;
    }

    $stmt = $pdo->prepare('UPDATE documents SET qr_token = :token, qr_issued_at = COALESCE(qr_issued_at, created_at, NOW()) WHERE id = :id');
    foreach ($rows as $id) {
        $stmt->execute([
            'token' => generate_document_qr_token(),
            'id' => (int) $id,
        ]);
    }
}

function backfill_document_file_hashes(PDO $pdo): void
{
    if (!db_column_exists_raw($pdo, 'documents', 'file_sha256') || !db_column_exists_raw($pdo, 'documents', 'attachment_path')) {
        return;
    }

    $stmt = $pdo->query(
        'SELECT id, attachment_path
         FROM documents
         WHERE attachment_path IS NOT NULL
           AND attachment_path <> ""
           AND (file_sha256 IS NULL OR file_sha256 = "")'
    );
    $update = $pdo->prepare('UPDATE documents SET file_sha256 = :hash WHERE id = :id');
    foreach ($stmt->fetchAll() as $row) {
        $path = document_file_path((string) $row['attachment_path']);
        if ($path !== null && is_file($path)) {
            $hash = hash_file('sha256', $path);
            if (is_string($hash) && $hash !== '') {
                $update->execute([
                    'hash' => $hash,
                    'id' => (int) $row['id'],
                ]);
            }
        }
    }
}

function fetch_document_by_qr_token(string $token): ?array
{
    $token = extract_qr_token($token);
    if ($token === '') {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT id
         FROM documents
         WHERE qr_token = :token
           AND deleted_at IS NULL
           AND status IN ('Under Action', 'Completed', 'Archived')
         LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    $id = (int) $stmt->fetchColumn();

    return $id > 0 ? fetch_document($id) : null;
}

function log_document_scan_event(
    int $documentId,
    ?int $routeId,
    string $action = 'scan',
    string $result = 'valid',
    string $remarks = '',
    string $physicalHandlerName = ''
): void {
    try {
        if (!table_exists('document_scan_logs')) {
            return;
        }

        $hasPhysicalHandler = db_column_exists_raw(db(), 'document_scan_logs', 'physical_handler_name');
        $columns = 'document_id, route_id, user_id, office_id, action, result, remarks';
        $values = ':document_id, :route_id, :user_id, :office_id, :action, :result, :remarks';
        $params = [
            'document_id' => $documentId,
            'route_id' => $routeId,
            'user_id' => current_user()['id'] ?? null,
            'office_id' => current_office_id(),
            'action' => $action,
            'result' => $result,
            'remarks' => $remarks !== '' ? $remarks : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];

        if ($hasPhysicalHandler) {
            $columns .= ', physical_handler_name';
            $values .= ', :physical_handler_name';
            $params['physical_handler_name'] = $physicalHandlerName !== '' ? substr($physicalHandlerName, 0, 150) : null;
        }

        $stmt = db()->prepare(
            'INSERT INTO document_scan_logs (' . $columns . ', ip_address, user_agent, created_at)
             VALUES (' . $values . ', :ip_address, :user_agent, NOW())'
        );
        $stmt->execute($params);
    } catch (Throwable $e) {
    }
}

function document_scan_logs(int $documentId, int $limit = 20): array
{
    if ($documentId <= 0 || !table_exists('document_scan_logs')) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        'SELECT dsl.*,
                u.full_name AS user_name,
                o.name AS office_name,
                o.trunk_line AS office_trunk_line,
                o.local_number AS office_local_number
         FROM document_scan_logs dsl
         LEFT JOIN users u ON u.id = dsl.user_id
         LEFT JOIN offices o ON o.id = dsl.office_id
         WHERE dsl.document_id = :document_id
         ORDER BY dsl.created_at DESC, dsl.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute(['document_id' => $documentId]);

    return $stmt->fetchAll();
}

function scan_result_badge(string $result): string
{
    return match ($result) {
        'valid' => 'badge success',
        'mismatch', 'unauthorized' => 'badge danger',
        'invalid' => 'badge warning',
        default => 'badge',
    };
}

function fetch_document(int $id, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT d.*,
                   o_from.name AS origin_office_name,
                   o_from.trunk_line AS origin_office_trunk_line,
                   o_from.local_number AS origin_office_local_number,
                   o_current.name AS current_office_name,
                   o_current.trunk_line AS current_office_trunk_line,
                   o_current.local_number AS current_office_local_number,
                   c.name AS category_name,
                   creator.full_name AS created_by_name,
                   archiver.full_name AS archived_by_name,
                   deleter.full_name AS deleted_by_name,
                   validator.full_name AS validated_by_name,
                   releaser.full_name AS released_by_name,
                   ocr_reviewer.full_name AS ocr_reviewed_by_name
            FROM documents d
            LEFT JOIN offices o_from ON o_from.id = d.origin_office_id
            LEFT JOIN offices o_current ON o_current.id = d.current_office_id
            LEFT JOIN document_categories c ON c.id = d.category_id
            LEFT JOIN users creator ON creator.id = d.created_by
            LEFT JOIN users archiver ON archiver.id = d.archived_by
            LEFT JOIN users deleter ON deleter.id = d.deleted_by
            LEFT JOIN users validator ON validator.id = d.validated_by
            LEFT JOIN users releaser ON releaser.id = d.released_by
            LEFT JOIN users ocr_reviewer ON ocr_reviewer.id = d.ocr_reviewed_by
            WHERE d.id = :id';

    if (!$includeDeleted) {
        $sql .= ' AND d.deleted_at IS NULL';
    }

    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $id]);
    $document = $stmt->fetch();

    return is_array($document) ? $document : null;
}

function latest_route_for_document(int $documentId): ?array
{
    $stmt = db()->prepare(
        'SELECT dr.*,
                from_office.name AS from_office_name,
                to_office.name AS to_office_name,
                routed_user.full_name AS routed_by_name,
                received_user.full_name AS received_by_name
         FROM document_routes dr
         LEFT JOIN offices from_office ON from_office.id = dr.from_office_id
         LEFT JOIN offices to_office ON to_office.id = dr.to_office_id
         LEFT JOIN users routed_user ON routed_user.id = dr.routed_by
         LEFT JOIN users received_user ON received_user.id = dr.received_by
         WHERE dr.document_id = :document_id
         ORDER BY dr.routed_at DESC, dr.id DESC
         LIMIT 1'
    );
    $stmt->execute(['document_id' => $documentId]);
    $route = $stmt->fetch();

    return is_array($route) ? $route : null;
}

function generate_tracking_number(?int $officeId = null): string
{
    $year = date('Y');
    $prefix = $year . '-IBA-';
    $stmt = db()->prepare(
        'SELECT MAX(CAST(SUBSTRING_INDEX(tracking_no, "-", -1) AS UNSIGNED))
         FROM documents
         WHERE tracking_no LIKE :prefix'
    );
    $stmt->execute(['prefix' => $prefix . '%']);
    $next = ((int) $stmt->fetchColumn()) + 1;

    return sprintf('%s%05d', $prefix, $next);
}

function official_document_number_year(): int
{
    return (int) (new DateTimeImmutable('now', app_timezone()))->format('Y');
}

function format_official_document_number(int $sequenceNumber, int $year): string
{
    return sprintf('No. %02d s. %04d.', $sequenceNumber, $year);
}

function highest_existing_official_document_sequence(PDO $pdo, int $year): int
{
    $stmt = $pdo->prepare(
        'SELECT document_number
         FROM documents
         WHERE document_number IS NOT NULL
           AND TRIM(document_number) <> \'\'
           AND document_number LIKE :year_pattern'
    );
    $stmt->execute(['year_pattern' => '%' . $year . '%']);

    $highest = 0;
    $pattern = '/^No\.\s*(\d+)\s*s\.\s*' . preg_quote((string) $year, '/') . '\.$/i';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $documentNumber) {
        if (preg_match($pattern, trim((string) $documentNumber), $matches) !== 1) {
            continue;
        }

        $highest = max($highest, (int) $matches[1]);
    }

    return $highest;
}

function generate_official_document_number(PDO $pdo, ?int $excludeDocumentId = null): string
{
    $year = official_document_number_year();

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $seedNumber = highest_existing_official_document_sequence($pdo, $year);

        $seedStmt = $pdo->prepare(
            'INSERT INTO document_number_sequences (`year`, last_number, created_at, updated_at)
             VALUES (:year, :last_number, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 last_number = GREATEST(last_number, VALUES(last_number)),
                 updated_at = NOW()'
        );
        $seedStmt->execute([
            'year' => $year,
            'last_number' => $seedNumber,
        ]);

        $lockStmt = $pdo->prepare(
            'SELECT last_number
             FROM document_number_sequences
             WHERE `year` = :year
             FOR UPDATE'
        );
        $lockStmt->execute(['year' => $year]);
        $lastNumber = max((int) $lockStmt->fetchColumn(), $seedNumber);
        $nextNumber = $lastNumber + 1;
        $documentNumber = format_official_document_number($nextNumber, $year);

        $updateStmt = $pdo->prepare(
            'UPDATE document_number_sequences
             SET last_number = :last_number,
                 updated_at = NOW()
             WHERE `year` = :year'
        );
        $updateStmt->execute([
            'last_number' => $nextNumber,
            'year' => $year,
        ]);

        if (!document_number_exists($documentNumber, $excludeDocumentId)) {
            return $documentNumber;
        }
    }

    throw new RuntimeException('Unable to generate a unique official document number.');
}

function records_office_id(): int
{
    static $officeId = 0;

    if ($officeId > 0) {
        return $officeId;
    }

    $officeId = ensure_system_office(db());

    return $officeId;
}

function is_records_office_id(?int $officeId): bool
{
    return $officeId !== null && (int) $officeId === records_office_id();
}

function records_officer_custody_gate_message(): string
{
    return 'Scan the QR code or paste the full QR URL and confirm custody before accessing this record.';
}

function records_officer_custody_context_ttl_seconds(): int
{
    return 900;
}

function document_requires_records_officer_custody_gate(array $document): bool
{
    return has_role('records_officer')
        && (string) ($document['status'] ?? '') === 'Completed'
        && document_is_records_office_archive_category($document);
}

function document_custody_context_key(array $document): string
{
    $documentId = (int) ($document['id'] ?? 0);
    $token = strtolower(trim((string) ($document['qr_token'] ?? '')));
    if ($documentId <= 0 || $token === '') {
        return '';
    }

    return $documentId . ':' . hash('sha256', $token);
}

function document_custody_contexts(): array
{
    $contexts = $_SESSION['records_officer_custody_contexts'] ?? [];
    if (!is_array($contexts)) {
        return [];
    }

    $now = time();
    foreach ($contexts as $key => $expiresAt) {
        if (!is_string($key) || (int) $expiresAt < $now) {
            unset($contexts[$key]);
        }
    }

    $_SESSION['records_officer_custody_contexts'] = $contexts;

    return $contexts;
}

function remember_document_custody_verification(array $document): void
{
    $key = document_custody_context_key($document);
    if ($key === '') {
        return;
    }

    $contexts = document_custody_contexts();
    $contexts[$key] = time() + records_officer_custody_context_ttl_seconds();
    $_SESSION['records_officer_custody_contexts'] = $contexts;
}

function document_custody_is_verified(array $document): bool
{
    $key = document_custody_context_key($document);
    if ($key === '') {
        return false;
    }

    $contexts = document_custody_contexts();

    return isset($contexts[$key]) && (int) $contexts[$key] >= time();
}

function document_records_officer_custody_access_allowed(array $document): bool
{
    return !document_requires_records_officer_custody_gate($document) || document_custody_is_verified($document);
}

function current_user_is_records_office(): bool
{
    return can_finalize_document_workflow();
}

function can_register_documents(): bool
{
    return has_any_role(['issuing_authority', 'office_staff']);
}

function can_edit_document_metadata(): bool
{
    return has_role('records_officer');
}

function current_user_created_document(array $document): bool
{
    $userId = (int) (current_user()['id'] ?? 0);
    $creatorId = (int) ($document['created_by'] ?? 0);

    return $userId > 0 && $creatorId > 0 && $userId === $creatorId;
}

function can_edit_document_draft(array $document): bool
{
    return has_any_role(['office_staff', 'issuing_authority'])
        && current_user_created_document($document)
        && in_array((string) ($document['status'] ?? ''), ['Draft', 'Rejected'], true);
}

function can_edit_document_creator_official_metadata(array $document): bool
{
    return has_any_role(['office_staff', 'issuing_authority'])
        && current_user_created_document($document)
        && in_array((string) ($document['status'] ?? ''), ['Under Action', 'Completed'], true);
}

function can_edit_document_record(array $document): bool
{
    if (document_is_locked_records_archive_for_current_user($document)) {
        return false;
    }

    if (can_edit_document_metadata() && (string) ($document['status'] ?? '') !== 'Archived' && !document_is_unreleased($document)) {
        return true;
    }

    return can_edit_document_draft($document) || can_edit_document_creator_official_metadata($document);
}

function can_review_document_release(array $document): bool
{
    return has_role('issuing_authority')
        && (string) ($document['status'] ?? '') === 'Submitted'
        && current_office_id() !== null
        && (int) ($document['origin_office_id'] ?? 0) === (int) current_office_id();
}

function can_finalize_document_workflow(): bool
{
    return has_role('records_officer');
}

function workflow_destination_offices(): array
{
    return load_offices();
}

function document_is_in_records_custody(array $document): bool
{
    return is_records_office_id(isset($document['current_office_id']) ? (int) $document['current_office_id'] : null);
}

function route_handoff_label(?string $handoffType): string
{
    return match ((string) $handoffType) {
        'records_to_office' => 'Records to Office',
        'office_to_records' => 'Office to Records',
        'office_to_office' => 'Office to Office',
        default => 'Workflow Route',
    };
}

function office_forward_destination_offices(): array
{
    return location_update_offices();
}

function location_update_offices(): array
{
    if (can_finalize_document_workflow()) {
        return load_offices();
    }

    $officeId = current_office_id();
    if ($officeId === null) {
        return [];
    }

    $office = load_office_by_id($officeId);
    if ($office === null || (int) ($office['is_active'] ?? 0) !== 1) {
        return [];
    }

    return [$office];
}

function can_update_document_location(array $document): bool
{
    if (!can_access_document_module()) {
        return false;
    }

    if ((string) ($document['status'] ?? '') === 'Archived' || document_is_unreleased($document)) {
        return false;
    }

    if (can_finalize_document_workflow()) {
        return document_is_records_office_archive_category($document);
    }

    return current_office_id() !== null || can_finalize_document_workflow();
}

function can_complete_document_processing(array $document): bool
{
    if ((string) ($document['status'] ?? '') !== 'Under Action') {
        return false;
    }

    if (can_finalize_document_workflow()) {
        return true;
    }

    $officeId = current_office_id();
    return $officeId !== null
        && (int) ($document['current_office_id'] ?? 0) === (int) $officeId;
}

function document_can_validate_intake(array $document, ?array $latestRoute): bool
{
    return false;
}

function document_can_forward_from_records(array $document, ?array $latestRoute): bool
{
    return false;
}

function document_can_acknowledge_office_receipt(array $document, ?array $latestRoute): bool
{
    return false;
}

function document_can_update_office_work(array $document, ?array $latestRoute): bool
{
    return can_complete_document_processing($document);
}

function document_can_forward_from_office(array $document, ?array $latestRoute): bool
{
    return false;
}

function document_can_return_to_records(array $document, ?array $latestRoute): bool
{
    return false;
}

function document_can_acknowledge_records_return(array $document, ?array $latestRoute): bool
{
    return false;
}

function document_can_resolve_hold(array $document): bool
{
    return false;
}

function document_can_complete(array $document, ?array $latestRoute): bool
{
    return can_complete_document_processing($document);
}

function document_can_archive(array $document, ?array $latestRoute): bool
{
    if ((string) ($document['status'] ?? '') !== 'Completed') {
        return false;
    }

    if (can_finalize_document_workflow()) {
        return document_is_records_office_archive_category($document);
    }

    return current_user_can_archive_as_source_office($document);
}

function document_can_restore_archive(array $document): bool
{
    if ((string) ($document['status'] ?? '') !== 'Archived') {
        return false;
    }

    if (can_finalize_document_workflow()) {
        return document_is_records_office_archive_category($document);
    }

    return current_user_can_archive_as_source_office($document);
}

function document_custody_summary(array $document, ?array $latestRoute): string
{
    $currentOffice = (string) ($document['current_office_name'] ?? 'Unassigned office');

    return 'Current physical location: ' . $currentOffice . '.';
}

function document_next_action_hint(array $document, ?array $latestRoute): string
{
    $status = (string) ($document['status'] ?? '');

    if ($status === 'Draft') {
        return 'Complete the draft details and submit it for issuing authority approval.';
    }

    if ($status === 'Submitted') {
        return 'This document is waiting for issuing authority review.';
    }

    if ($status === 'Rejected') {
        return 'Review the rejection remarks, edit the draft, and submit it again when ready.';
    }

    if (document_can_complete($document, $latestRoute)) {
        return 'Update the location whenever the physical copy moves, then mark processing completed when the final action is done.';
    }

    if (document_can_archive($document, $latestRoute)) {
        return document_is_records_office_archive_category($document)
            ? 'This completed document is ready for Records Office archiving.'
            : 'This completed document is ready for source office archiving.';
    }

    if ($status === 'Completed') {
        return document_is_records_office_archive_category($document)
            ? 'This completed document should be archived by the Records Office.'
            : 'This completed document should be archived by its source office.';
    }

    if ($status === 'Archived') {
        return 'This document is closed and stored in the digital archive.';
    }

    return 'Review the location history and document timeline below for the latest movement.';
}

function validation_checklist_fields(): array
{
    return [
        'qr_match' => 'QR token / code matches the official record',
        'tracking_number_match' => 'Tracking number matches',
        'subject_match' => 'Subject matches',
        'source_office_match' => 'Source office matches',
        'destination_office_match' => 'Destination office matches',
        'page_count_match' => 'Page count matches',
        'signatures_match' => 'Signatures / stamps match',
    ];
}

function validation_checklist_defaults(): array
{
    $defaults = [];
    foreach (validation_checklist_fields() as $field => $label) {
        $defaults[$field] = false;
    }

    return $defaults;
}

function normalize_validation_checklist(array $payload): array
{
    $normalized = validation_checklist_defaults();

    foreach (array_keys($normalized) as $field) {
        $normalized[$field] = !empty($payload[$field]);
    }

    return $normalized;
}

function validation_checklist_passes(array $payload): bool
{
    foreach (array_keys(validation_checklist_fields()) as $field) {
        if (empty($payload[$field])) {
            return false;
        }
    }

    return true;
}

function validation_checklist_context_label(string $context): string
{
    return match ($context) {
        'records_intake' => 'Records Intake',
        'office_receipt' => 'Office Receipt',
        'records_return' => 'Records Return',
        default => ucwords(str_replace('_', ' ', $context)),
    };
}

function validation_checklist_result_badge(string $result): string
{
    return $result === 'pass' ? 'badge success' : 'badge danger';
}

function validation_checklist_summary(array $row): string
{
    if ((string) ($row['result'] ?? 'pass') === 'pass') {
        return 'All required validation checks passed.';
    }

    $missing = [];
    foreach (validation_checklist_fields() as $field => $label) {
        if ((int) ($row[$field] ?? 0) !== 1) {
            $missing[] = $label;
        }
    }

    if ($missing === []) {
        return 'Validation failed. See remarks for details.';
    }

    return 'Failed checks: ' . implode('; ', $missing);
}

function create_document_validation_checklist(
    int $documentId,
    ?int $routeId,
    string $context,
    array $checklist,
    string $remarks,
    string $result
): int {
    $checklist = normalize_validation_checklist($checklist);
    $stmt = db()->prepare(
        'INSERT INTO document_validation_checklists (
            document_id, route_id, office_id, validated_by, context,
            qr_match, tracking_number_match, subject_match, source_office_match,
            destination_office_match, page_count_match, signatures_match,
            remarks, result, created_at
         ) VALUES (
            :document_id, :route_id, :office_id, :validated_by, :context,
            :qr_match, :tracking_number_match, :subject_match, :source_office_match,
            :destination_office_match, :page_count_match, :signatures_match,
            :remarks, :result, NOW()
         )'
    );
    $stmt->execute([
        'document_id' => $documentId,
        'route_id' => $routeId,
        'office_id' => current_office_id(),
        'validated_by' => current_user()['id'] ?? null,
        'context' => $context,
        'qr_match' => $checklist['qr_match'] ? 1 : 0,
        'tracking_number_match' => $checklist['tracking_number_match'] ? 1 : 0,
        'subject_match' => $checklist['subject_match'] ? 1 : 0,
        'source_office_match' => $checklist['source_office_match'] ? 1 : 0,
        'destination_office_match' => $checklist['destination_office_match'] ? 1 : 0,
        'page_count_match' => $checklist['page_count_match'] ? 1 : 0,
        'signatures_match' => $checklist['signatures_match'] ? 1 : 0,
        'remarks' => $remarks !== '' ? $remarks : null,
        'result' => $result,
    ]);

    return (int) db()->lastInsertId();
}

function document_validation_checklists(int $documentId, int $limit = 50): array
{
    if ($documentId <= 0 || !table_exists('document_validation_checklists')) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare(
        'SELECT dvc.*,
                u.full_name AS validator_name,
                o.name AS office_name
         FROM document_validation_checklists dvc
         LEFT JOIN users u ON u.id = dvc.validated_by
         LEFT JOIN offices o ON o.id = dvc.office_id
         WHERE dvc.document_id = :document_id
         ORDER BY dvc.created_at DESC, dvc.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute(['document_id' => $documentId]);

    return $stmt->fetchAll();
}

function document_timeline_event_label(string $eventType): string
{
    return match ($eventType) {
        'registered' => 'Registered',
        'draft_created' => 'Draft Created',
        'submitted' => 'Submitted for Approval',
        'released' => 'Released',
        'rejected' => 'Rejected',
        'intake_validated' => 'Intake Validated',
        'forwarded' => 'Forwarded',
        'office_forwarded' => 'Office Forwarded',
        'received' => 'Received',
        'returned_to_records' => 'Returned to Records',
        'remarked' => 'Office Remark',
        'hold_placed' => 'Hold Placed',
        'hold_resolved' => 'Hold Resolved',
        'completed' => 'Completed',
        'archived' => 'Archived',
        'file_validated' => 'File Validated',
        'ocr_verified' => 'OCR Verified',
        'ocr_rejected' => 'OCR Rejected',
        'location_updated' => 'Location Updated',
        'archive_restored' => 'Archive Restored',
        default => ucwords(str_replace('_', ' ', $eventType)),
    };
}

function document_timeline_event_badge(string $eventType): string
{
    return match ($eventType) {
        'registered', 'draft_created', 'submitted' => 'badge warning',
        'intake_validated', 'received', 'completed', 'archived', 'file_validated', 'ocr_verified' => 'badge success',
        'released', 'forwarded', 'office_forwarded', 'returned_to_records', 'remarked', 'location_updated', 'archive_restored' => 'badge info',
        'hold_placed', 'rejected', 'ocr_rejected' => 'badge danger',
        'hold_resolved' => 'badge warning',
        default => 'badge',
    };
}

function create_document_timeline_event(
    int $documentId,
    string $eventType,
    string $stageAfter,
    ?int $routeId = null,
    string $remarks = '',
    ?int $counterpartyOfficeId = null,
    ?string $createdAt = null,
    ?int $actorUserId = null,
    ?int $actorOfficeId = null
): void {
    if (!table_exists('document_timeline_events')) {
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO document_timeline_events (
            document_id, route_id, actor_user_id, actor_office_id, counterparty_office_id,
            event_type, stage_after, remarks, created_at
         ) VALUES (
            :document_id, :route_id, :actor_user_id, :actor_office_id, :counterparty_office_id,
            :event_type, :stage_after, :remarks, :created_at
         )'
    );
    $stmt->execute([
        'document_id' => $documentId,
        'route_id' => $routeId,
        'actor_user_id' => $actorUserId ?? (current_user()['id'] ?? null),
        'actor_office_id' => $actorOfficeId ?? current_office_id(),
        'counterparty_office_id' => $counterpartyOfficeId,
        'event_type' => $eventType,
        'stage_after' => $stageAfter,
        'remarks' => $remarks !== '' ? $remarks : null,
        'created_at' => $createdAt ?? date('Y-m-d H:i:s'),
    ]);
}

function document_timeline_events(int $documentId, int $limit = 100): array
{
    if ($documentId <= 0 || !table_exists('document_timeline_events')) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare(
        'SELECT dte.*,
                u.full_name AS actor_name,
                actor_office.name AS actor_office_name,
                actor_office.trunk_line AS actor_office_trunk_line,
                actor_office.local_number AS actor_office_local_number,
                counterparty_office.name AS counterparty_office_name,
                counterparty_office.trunk_line AS counterparty_office_trunk_line,
                counterparty_office.local_number AS counterparty_office_local_number
         FROM document_timeline_events dte
         LEFT JOIN users u ON u.id = dte.actor_user_id
         LEFT JOIN offices actor_office ON actor_office.id = dte.actor_office_id
         LEFT JOIN offices counterparty_office ON counterparty_office.id = dte.counterparty_office_id
         WHERE dte.document_id = :document_id
         ORDER BY dte.created_at ASC, dte.id ASC
         LIMIT ' . $limit
    );
    $stmt->execute(['document_id' => $documentId]);

    return $stmt->fetchAll();
}

function append_route_note(?string $existing, string $note): ?string
{
    $existing = trim((string) $existing);
    $note = trim($note);

    if ($note === '') {
        return $existing !== '' ? $existing : null;
    }

    if ($existing === '') {
        return $note;
    }

    return $existing . PHP_EOL . $note;
}

function create_document_route(int $documentId, int $fromOfficeId, int $toOfficeId, string $handoffType, string $remarks = ''): int
{
    $stmt = db()->prepare(
        'INSERT INTO document_routes (
            document_id, from_office_id, to_office_id, remarks, route_status, handoff_type, routed_by, routed_at
         ) VALUES (
            :document_id, :from_office_id, :to_office_id, :remarks, :route_status, :handoff_type, :routed_by, NOW()
         )'
    );
    $stmt->execute([
        'document_id' => $documentId,
        'from_office_id' => $fromOfficeId,
        'to_office_id' => $toOfficeId,
        'remarks' => $remarks !== '' ? $remarks : null,
        'route_status' => 'pending_receipt',
        'handoff_type' => $handoffType,
        'routed_by' => current_user()['id'] ?? null,
    ]);

    return (int) db()->lastInsertId();
}

function cancel_document_route(int $routeId, string $remarks = ''): void
{
    if ($routeId <= 0) {
        return;
    }

    if ($remarks === '') {
        $stmt = db()->prepare(
            'UPDATE document_routes
             SET route_status = :route_status
             WHERE id = :id'
        );
        $stmt->execute([
            'route_status' => 'cancelled',
            'id' => $routeId,
        ]);

        return;
    }

    $stmt = db()->prepare(
        'UPDATE document_routes
         SET route_status = :route_status,
             remarks = CASE
                 WHEN remarks IS NULL OR TRIM(remarks) = "" THEN :remarks
                 ELSE CONCAT(remarks, CHAR(10), :remarks)
             END
         WHERE id = :id'
    );
    $stmt->execute([
        'route_status' => 'cancelled',
        'remarks' => $remarks,
        'id' => $routeId,
    ]);
}

function acknowledge_document_route(int $routeId): void
{
    $stmt = db()->prepare(
        'UPDATE document_routes
         SET route_status = :route_status,
             received_at = NOW(),
             received_by = :received_by
         WHERE id = :id'
    );
    $stmt->execute([
        'route_status' => 'received',
        'received_by' => current_user()['id'] ?? null,
        'id' => $routeId,
    ]);
}

function set_document_workflow_state(
    int $documentId,
    string $status,
    ?int $currentOfficeId = null,
    bool $markValidated = false
): void {
    $sql = 'UPDATE documents SET status = :status, updated_at = NOW()';
    $params = [
        'status' => $status,
        'id' => $documentId,
    ];

    if ($currentOfficeId !== null) {
        $sql .= ', current_office_id = :current_office_id';
        $params['current_office_id'] = $currentOfficeId;
    }

    if ($markValidated) {
        $sql .= ', validated_at = NOW(), validated_by = :validated_by';
        $params['validated_by'] = current_user()['id'] ?? null;
    }

    $sql .= ' WHERE id = :id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

function perform_submit_document_for_review(int $documentId, string $remarks = ''): void
{
    run_in_transaction(static function () use ($documentId, $remarks): void {
        $document = fetch_document($documentId);
        if ($document === null) {
            throw new RuntimeException('Document not found.');
        }

        if (!in_array((string) ($document['status'] ?? ''), ['Draft', 'Rejected'], true)) {
            throw new RuntimeException('Only draft or rejected documents can be submitted for approval.');
        }

        $stmt = db()->prepare(
            'UPDATE documents
             SET status = :status,
                 submitted_at = NOW(),
                 submitted_by = :submitted_by,
                 reviewed_at = NULL,
                 reviewed_by = NULL,
                 review_note = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'Submitted',
            'submitted_by' => current_user()['id'] ?? null,
            'id' => $documentId,
        ]);

        create_document_timeline_event($documentId, 'submitted', 'Submitted', null, $remarks !== '' ? $remarks : 'Document submitted for issuing authority approval.');
        audit_log('submit_document_for_review', 'document', $documentId, [
            'submitted_by' => current_user()['id'] ?? null,
        ]);
    });
}

function perform_release_document(int $documentId, ?string $documentNumber = null, bool $completedOnRelease = false, string $reviewNote = ''): ?string
{
    return run_in_transaction(static function () use ($documentId, $documentNumber, $completedOnRelease, $reviewNote): ?string {
        $pdo = db();

        $document = fetch_document($documentId);
        if ($document === null) {
            throw new RuntimeException('Document not found.');
        }

        if (!in_array((string) ($document['status'] ?? ''), ['Draft', 'Submitted', 'Rejected'], true)) {
            throw new RuntimeException('Only unreleased documents can be officially released.');
        }

        $documentNumber = $documentNumber !== null ? trim($documentNumber) : '';
        $documentNumber = $documentNumber !== '' ? $documentNumber : null;
        if ($documentNumber !== null && document_number_exists($documentNumber, $documentId)) {
            throw new RuntimeException('A document with this document number already exists.');
        }

        $token = generate_document_qr_token();
        $status = $completedOnRelease ? 'Completed' : 'Under Action';
        $currentOfficeId = isset($document['origin_office_id']) ? (int) $document['origin_office_id'] : current_office_id();

        $stmt = $pdo->prepare(
            'UPDATE documents
             SET document_number = :document_number,
                 qr_token = :qr_token,
                 qr_issued_at = NOW(),
                 released_at = NOW(),
                 released_by = :released_by,
                 reviewed_at = NOW(),
                 reviewed_by = :reviewed_by,
                 review_note = :review_note,
                 status = :status,
                 current_office_id = :current_office_id,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'document_number' => $documentNumber,
            'qr_token' => $token,
            'released_by' => current_user()['id'] ?? null,
            'reviewed_by' => current_user()['id'] ?? null,
            'review_note' => $reviewNote !== '' ? $reviewNote : null,
            'status' => $status,
            'current_office_id' => $currentOfficeId,
            'id' => $documentId,
        ]);

        create_document_timeline_event(
            $documentId,
            'released',
            $status,
            null,
            $reviewNote !== '' ? $reviewNote : 'Document approved and officially released.',
            $currentOfficeId
        );

        if ($completedOnRelease) {
            create_document_timeline_event(
                $documentId,
                'completed',
                'Completed',
                null,
                'Document was already signed/completed at release.',
                $currentOfficeId
            );
        }

        audit_log('release_document', 'document', $documentId, [
            'document_number' => $documentNumber,
            'status' => $status,
            'released_by' => current_user()['id'] ?? null,
        ]);

        return $documentNumber;
    });
}

function perform_reject_document_release(int $documentId, string $reviewNote): void
{
    $reviewNote = trim($reviewNote);
    if ($reviewNote === '') {
        throw new InvalidArgumentException('Review remarks are required when rejecting a document.');
    }

    run_in_transaction(static function () use ($documentId, $reviewNote): void {
        $document = fetch_document($documentId);
        if ($document === null) {
            throw new RuntimeException('Document not found.');
        }

        if ((string) ($document['status'] ?? '') !== 'Submitted') {
            throw new RuntimeException('Only submitted documents can be rejected.');
        }

        $stmt = db()->prepare(
            'UPDATE documents
             SET status = :status,
                 reviewed_at = NOW(),
                 reviewed_by = :reviewed_by,
                 review_note = :review_note,
                 qr_token = NULL,
                 qr_issued_at = NULL,
                 released_at = NULL,
                 released_by = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'Rejected',
            'reviewed_by' => current_user()['id'] ?? null,
            'review_note' => $reviewNote,
            'id' => $documentId,
        ]);

        create_document_timeline_event($documentId, 'rejected', 'Rejected', null, $reviewNote);
        audit_log('reject_document_release', 'document', $documentId, [
            'reviewed_by' => current_user()['id'] ?? null,
            'review_note' => $reviewNote,
        ]);
    });
}

function perform_document_location_update(int $documentId, int $officeId, string $remarks, string $physicalHandlerName = ''): void
{
    run_in_transaction(static function () use ($documentId, $officeId, $remarks, $physicalHandlerName): void {
        $document = fetch_document($documentId);
        if ($document === null) {
            throw new RuntimeException('Document not found.');
        }

        $previousOfficeId = isset($document['current_office_id']) ? (int) $document['current_office_id'] : null;
        $previousOffice = (string) ($document['current_office_name'] ?? 'Unassigned office');
        $newOffice = load_office_by_id($officeId);
        $newOfficeName = is_array($newOffice) ? (string) $newOffice['name'] : 'Selected office';
        $status = (string) ($document['status'] ?? 'Under Action');
        $handlerNote = $physicalHandlerName !== '' ? ' Handled by ' . $physicalHandlerName . '.' : '';
        $message = trim(sprintf(
            'Location changed from %s to %s.%s',
            $previousOffice,
            $newOfficeName,
            ($remarks !== '' ? ' ' . $remarks : '') . $handlerNote
        ));

        $stmt = db()->prepare(
            'UPDATE documents
             SET current_office_id = :current_office_id,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'current_office_id' => $officeId,
            'id' => $documentId,
        ]);

        create_document_timeline_event($documentId, 'location_updated', $status, null, $message, $officeId);
        log_document_scan_event($documentId, null, 'location_update', 'valid', $message, $physicalHandlerName);
        audit_log('update_document_location', 'document', $documentId, [
            'from_office_id' => $previousOfficeId,
            'to_office_id' => $officeId,
            'remarks' => $remarks,
            'physical_handler_name' => $physicalHandlerName,
        ]);
    });
}

function perform_records_officer_custody_confirmation(int $documentId, string $remarks, string $physicalHandlerName = ''): void
{
    run_in_transaction(static function () use ($documentId, $remarks, $physicalHandlerName): void {
        $document = fetch_document($documentId);
        if ($document === null) {
            throw new RuntimeException('Document not found.');
        }

        $status = (string) ($document['status'] ?? 'Completed');
        $message = trim('Records Office confirmed physical custody.'
            . ($remarks !== '' ? ' ' . $remarks : '')
            . ($physicalHandlerName !== '' ? ' Verified by ' . $physicalHandlerName . '.' : ''));

        db()->prepare('UPDATE documents SET updated_at = NOW() WHERE id = :id')->execute([
            'id' => $documentId,
        ]);

        create_document_timeline_event($documentId, 'location_updated', $status, null, $message, records_office_id());
        log_document_scan_event($documentId, null, 'location_update', 'valid', $message, $physicalHandlerName);
        audit_log('confirm_records_custody', 'document', $documentId, [
            'office_id' => records_office_id(),
            'remarks' => $remarks,
            'physical_handler_name' => $physicalHandlerName,
        ]);
    });
}

function perform_records_intake_validation(int $documentId, array $checklist, string $remarks = ''): bool
{
    $passed = validation_checklist_passes($checklist);
    $route = latest_route_for_document($documentId);
    $routeId = isset($route['id']) ? (int) $route['id'] : null;

    create_document_validation_checklist(
        $documentId,
        $routeId,
        'records_intake',
        $checklist,
        $remarks,
        $passed ? 'pass' : 'fail'
    );

    if ($passed) {
        set_document_workflow_state($documentId, 'Validated', records_office_id(), true);
        create_document_timeline_event($documentId, 'intake_validated', 'Validated', $routeId, $remarks);
        log_document_scan_event($documentId, $routeId, 'validate', 'valid', $remarks !== '' ? $remarks : 'Records Office validated the intake checklist.');
        audit_log('validate_intake', 'document', $documentId, ['route_id' => $routeId]);

        return true;
    }

    set_document_workflow_state($documentId, 'On Hold');
    create_document_timeline_event($documentId, 'hold_placed', 'On Hold', $routeId, $remarks !== '' ? $remarks : 'Records Office placed the document on hold during intake validation.');
    log_document_scan_event($documentId, $routeId, 'flag_mismatch', 'mismatch', $remarks !== '' ? $remarks : 'Intake validation failed.');
    audit_log('place_document_hold', 'document', $documentId, [
        'route_id' => $routeId,
        'context' => 'records_intake',
    ]);

    return false;
}

function perform_records_forward(int $documentId, int $toOfficeId, string $remarks = ''): int
{
    $fromOfficeId = records_office_id();
    $routeId = create_document_route($documentId, $fromOfficeId, $toOfficeId, 'records_to_office', $remarks);

    set_document_workflow_state($documentId, 'Forwarded', $fromOfficeId);
    create_document_timeline_event($documentId, 'forwarded', 'Forwarded', $routeId, $remarks, $toOfficeId);
    log_document_scan_event($documentId, $routeId, 'route', 'valid', $remarks !== '' ? $remarks : 'Records Office forwarded the document.');
    audit_log('forward_document', 'document', $documentId, [
        'route_id' => $routeId,
        'to_office_id' => $toOfficeId,
    ]);

    return $routeId;
}

function perform_office_status_update(int $documentId, string $newStatus, string $remarks = ''): void
{
    if (!in_array($newStatus, ['Under Action', 'Completed'], true)) {
        $newStatus = 'Under Action';
    }

    set_document_workflow_state($documentId, $newStatus, current_office_id());
    create_document_timeline_event($documentId, 'remarked', $newStatus, null, $remarks);
    log_document_scan_event($documentId, null, 'validate', 'valid', $remarks !== '' ? $remarks : 'Office updated the document record.');
    audit_log('office_update_document', 'document', $documentId, [
        'status' => $newStatus,
        'office_id' => current_office_id(),
    ]);
}

function perform_office_forward(int $documentId, int $toOfficeId, string $remarks = ''): int
{
    $fromOfficeId = (int) current_office_id();
    $routeId = create_document_route($documentId, $fromOfficeId, $toOfficeId, 'office_to_office', $remarks);

    set_document_workflow_state($documentId, 'Forwarded', $fromOfficeId);
    create_document_timeline_event($documentId, 'office_forwarded', 'Forwarded', $routeId, $remarks, $toOfficeId);
    log_document_scan_event($documentId, $routeId, 'route', 'valid', $remarks !== '' ? $remarks : 'Office forwarded the document.');
    audit_log('office_forward_document', 'document', $documentId, [
        'route_id' => $routeId,
        'from_office_id' => $fromOfficeId,
        'to_office_id' => $toOfficeId,
    ]);

    return $routeId;
}

function perform_office_receipt_validation(int $documentId, array $route, array $checklist, string $remarks = ''): bool
{
    $routeId = (int) ($route['id'] ?? 0);
    $passed = validation_checklist_passes($checklist);

    create_document_validation_checklist(
        $documentId,
        $routeId > 0 ? $routeId : null,
        'office_receipt',
        $checklist,
        $remarks,
        $passed ? 'pass' : 'fail'
    );

    if ($passed) {
        acknowledge_document_route($routeId);
        set_document_workflow_state($documentId, 'Under Action', (int) $route['to_office_id'], true);
        create_document_timeline_event($documentId, 'received', 'Under Action', $routeId, $remarks, (int) ($route['from_office_id'] ?? 0));
        log_document_scan_event($documentId, $routeId, 'receive', 'valid', $remarks !== '' ? $remarks : 'Destination office acknowledged receipt.');
        audit_log('acknowledge_receipt', 'document', $documentId, ['route_id' => $routeId]);

        return true;
    }

    set_document_workflow_state($documentId, 'On Hold');
    create_document_timeline_event($documentId, 'hold_placed', 'On Hold', $routeId, $remarks !== '' ? $remarks : 'Office receipt validation failed.', (int) ($route['from_office_id'] ?? 0));
    log_document_scan_event($documentId, $routeId, 'flag_mismatch', 'mismatch', $remarks !== '' ? $remarks : 'Office receipt validation failed.');
    audit_log('place_document_hold', 'document', $documentId, [
        'route_id' => $routeId,
        'context' => 'office_receipt',
    ]);

    return false;
}

function perform_return_to_records(int $documentId, string $remarks = ''): int
{
    $fromOfficeId = (int) current_office_id();
    $toOfficeId = records_office_id();
    $routeId = create_document_route($documentId, $fromOfficeId, $toOfficeId, 'office_to_records', $remarks);

    set_document_workflow_state($documentId, 'Returned to Records');
    create_document_timeline_event($documentId, 'returned_to_records', 'Returned to Records', $routeId, $remarks, $toOfficeId);
    log_document_scan_event($documentId, $routeId, 'return_to_records', 'valid', $remarks !== '' ? $remarks : 'Office returned the document to the Records Office.');
    audit_log('return_document_to_records', 'document', $documentId, ['route_id' => $routeId]);

    return $routeId;
}

function perform_records_return_receipt_validation(int $documentId, array $route, array $checklist, string $remarks = ''): bool
{
    $routeId = (int) ($route['id'] ?? 0);
    $passed = validation_checklist_passes($checklist);

    create_document_validation_checklist(
        $documentId,
        $routeId > 0 ? $routeId : null,
        'records_return',
        $checklist,
        $remarks,
        $passed ? 'pass' : 'fail'
    );

    if ($passed) {
        acknowledge_document_route($routeId);
        set_document_workflow_state($documentId, 'Returned to Records', records_office_id(), true);
        create_document_timeline_event($documentId, 'received', 'Returned to Records', $routeId, $remarks, (int) ($route['from_office_id'] ?? 0));
        log_document_scan_event($documentId, $routeId, 'receive', 'valid', $remarks !== '' ? $remarks : 'Records Office received the returned document.');
        audit_log('receive_returned_document', 'document', $documentId, ['route_id' => $routeId]);

        return true;
    }

    set_document_workflow_state($documentId, 'On Hold');
    create_document_timeline_event($documentId, 'hold_placed', 'On Hold', $routeId, $remarks !== '' ? $remarks : 'Records Office return validation failed.', (int) ($route['from_office_id'] ?? 0));
    log_document_scan_event($documentId, $routeId, 'flag_mismatch', 'mismatch', $remarks !== '' ? $remarks : 'Records return validation failed.');
    audit_log('place_document_hold', 'document', $documentId, [
        'route_id' => $routeId,
        'context' => 'records_return',
    ]);

    return false;
}

function resolve_document_hold(int $documentId, string $remarks = ''): string
{
    $document = fetch_document($documentId);
    $route = latest_route_for_document($documentId);
    $routeId = isset($route['id']) ? (int) $route['id'] : null;
    $newStatus = 'Logged';
    $newCurrentOfficeId = records_office_id();
    $counterpartyOfficeId = null;

    if (route_is_pending($route)) {
        if (in_array((string) ($route['handoff_type'] ?? ''), ['office_to_records', 'office_to_office'], true)) {
            $newStatus = 'Under Action';
            $newCurrentOfficeId = (int) ($route['from_office_id'] ?? records_office_id());
            $counterpartyOfficeId = (int) ($route['from_office_id'] ?? 0);
        } else {
            $newStatus = 'Validated';
            $newCurrentOfficeId = records_office_id();
            $counterpartyOfficeId = (int) ($route['to_office_id'] ?? 0);
        }

        cancel_document_route($routeId ?? 0, $remarks !== '' ? 'Hold resolved by Records Office: ' . $remarks : 'Hold resolved by Records Office.');
    } elseif (is_array($document) && !document_is_in_records_custody($document)) {
        $newStatus = 'Under Action';
        $newCurrentOfficeId = (int) ($document['current_office_id'] ?? records_office_id());
    }

    set_document_workflow_state($documentId, $newStatus, $newCurrentOfficeId);
    create_document_timeline_event($documentId, 'hold_resolved', $newStatus, $routeId, $remarks, $counterpartyOfficeId);
    log_document_scan_event($documentId, $routeId, 'resolve_hold', 'valid', $remarks !== '' ? $remarks : 'Hold resolved by Records Office.');
    audit_log('resolve_document_hold', 'document', $documentId, [
        'route_id' => $routeId,
        'restored_status' => $newStatus,
    ]);

    return $newStatus;
}

function perform_complete_document(int $documentId, string $remarks = ''): void
{
    run_in_transaction(static function () use ($documentId, $remarks): void {
        $document = fetch_document($documentId);
        $currentOfficeId = isset($document['current_office_id']) ? (int) $document['current_office_id'] : null;

        set_document_workflow_state($documentId, 'Completed', $currentOfficeId);
        create_document_timeline_event($documentId, 'completed', 'Completed', null, $remarks);
        log_document_scan_event($documentId, null, 'complete', 'valid', $remarks !== '' ? $remarks : 'Document processing marked completed.');
        audit_log('complete_document', 'document', $documentId, [
            'office_id' => $currentOfficeId,
        ]);
    });
}

function perform_archive_document(int $documentId, string $archiveNote): void
{
    run_in_transaction(static function () use ($documentId, $archiveNote): void {
        $stmt = db()->prepare(
            'UPDATE documents
             SET status = :status,
                 archived_at = NOW(),
                 archived_by = :archived_by,
                 archive_note = :archive_note,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'Archived',
            'archived_by' => current_user()['id'] ?? null,
            'archive_note' => $archiveNote,
            'id' => $documentId,
        ]);

        create_document_timeline_event($documentId, 'archived', 'Archived', null, $archiveNote);
        log_document_scan_event($documentId, null, 'archive', 'valid', $archiveNote !== '' ? $archiveNote : 'Document archived.');
        audit_log('archive_document', 'document', $documentId, [
            'actor_user_id' => current_user()['id'] ?? null,
            'actor_name' => current_user()['full_name'] ?? 'Unknown',
            'archive_note' => $archiveNote,
        ]);
    });
}

function perform_restore_archived_document(int $documentId, string $remarks): void
{
    run_in_transaction(static function () use ($documentId, $remarks): void {
        $stmt = db()->prepare(
            'UPDATE documents
             SET status = :status,
                 archived_at = NULL,
                 archived_by = NULL,
                 archive_note = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'Completed',
            'id' => $documentId,
        ]);

        create_document_timeline_event($documentId, 'archive_restored', 'Completed', null, $remarks);
        log_document_scan_event($documentId, null, 'restore_archive', 'valid', $remarks);
        audit_log('restore_document', 'document', $documentId, [
            'actor_user_id' => current_user()['id'] ?? null,
            'actor_name' => current_user()['full_name'] ?? 'Unknown',
            'source' => 'archive',
            'restored_status' => 'Completed',
            'remarks' => $remarks,
        ]);
    });
}

function record_file_validation_result(int $documentId, bool $matches, string $remarks = ''): void
{
    $document = fetch_document($documentId);
    $stageAfter = (string) ($document['status'] ?? 'Under Action');

    create_document_timeline_event($documentId, 'file_validated', $stageAfter, null, $remarks);
    log_document_scan_event($documentId, null, 'validate', $matches ? 'valid' : 'mismatch', $remarks);
    audit_log('validate_file_hash', 'document', $documentId, [
        'matches' => $matches,
    ]);
}

function perform_ocr_review(int $documentId, string $status, string $remarks = ''): void
{
    if (!in_array($status, ['verified', 'rejected'], true)) {
        throw new InvalidArgumentException('Unsupported OCR review status.');
    }

    $stmt = db()->prepare(
        'UPDATE documents
         SET ocr_status = :ocr_status,
             ocr_reviewed_by = :reviewed_by,
             ocr_reviewed_at = NOW(),
             ocr_review_note = :review_note,
             updated_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'ocr_status' => $status,
        'reviewed_by' => current_user()['id'] ?? null,
        'review_note' => $remarks !== '' ? $remarks : null,
        'id' => $documentId,
    ]);

    $document = fetch_document($documentId);
    $eventType = $status === 'verified' ? 'ocr_verified' : 'ocr_rejected';
    $stageAfter = (string) ($document['status'] ?? 'Under Action');

    create_document_timeline_event($documentId, $eventType, $stageAfter, null, $remarks);
    log_document_scan_event($documentId, null, 'validate', $status === 'verified' ? 'valid' : 'mismatch', $remarks);
    audit_log('review_ocr_result', 'document', $documentId, [
        'ocr_status' => $status,
        'reviewed_by' => current_user()['id'] ?? null,
    ]);
}

function backfill_document_timeline_events(PDO $pdo): void
{
    if (!db_table_exists_raw($pdo, 'document_timeline_events')) {
        return;
    }

    $existingRows = (int) $pdo->query('SELECT COUNT(*) FROM document_timeline_events')->fetchColumn();
    if ($existingRows > 0) {
        $nonImportedRows = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM document_timeline_events
             WHERE remarks IS NULL
                OR remarks NOT LIKE 'Imported%'"
        )->fetchColumn();
        if ($nonImportedRows > 0) {
            return;
        }

        $pdo->exec('DELETE FROM document_timeline_events');
    }

    $documentCount = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    if ($documentCount === 0) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO document_timeline_events (
            document_id, route_id, actor_user_id, actor_office_id, counterparty_office_id,
            event_type, stage_after, remarks, created_at
         ) VALUES (
            :document_id, :route_id, :actor_user_id, :actor_office_id, :counterparty_office_id,
            :event_type, :stage_after, :remarks, :created_at
         )'
    );

    $validUserIds = [];
    foreach ($pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $validUserIds[(int) $userId] = true;
    }
    $validActorUserId = static function (mixed $userId) use ($validUserIds): ?int {
        if ($userId === null || $userId === '') {
            return null;
        }

        $normalized = (int) $userId;

        return isset($validUserIds[$normalized]) ? $normalized : null;
    };

    $documents = $pdo->query(
        'SELECT id, created_by, origin_office_id, current_office_id, status, created_at, updated_at, archived_at
         FROM documents
         ORDER BY created_at ASC, id ASC'
    )->fetchAll();

    foreach ($documents as $document) {
        $insert->execute([
            'document_id' => (int) $document['id'],
            'route_id' => null,
            'actor_user_id' => $validActorUserId($document['created_by']),
            'actor_office_id' => $document['origin_office_id'] !== null ? (int) $document['origin_office_id'] : null,
            'counterparty_office_id' => $document['current_office_id'] !== null ? (int) $document['current_office_id'] : null,
            'event_type' => 'registered',
            'stage_after' => 'Under Action',
            'remarks' => 'Imported from the legacy document tracking records.',
            'created_at' => (string) $document['created_at'],
        ]);
    }

    $routes = $pdo->query(
        'SELECT id, document_id, from_office_id, to_office_id, handoff_type, route_status, routed_by, routed_at, received_at, received_by
         FROM document_routes
         ORDER BY routed_at ASC, id ASC'
    )->fetchAll();

    foreach ($routes as $route) {
        $insert->execute([
            'document_id' => (int) $route['document_id'],
            'route_id' => (int) $route['id'],
            'actor_user_id' => $validActorUserId($route['routed_by']),
            'actor_office_id' => $route['from_office_id'] !== null ? (int) $route['from_office_id'] : null,
            'counterparty_office_id' => $route['to_office_id'] !== null ? (int) $route['to_office_id'] : null,
            'event_type' => (string) ($route['handoff_type'] ?? '') === 'office_to_records' ? 'returned_to_records' : 'forwarded',
            'stage_after' => 'Forwarded',
            'remarks' => 'Imported route history entry.',
            'created_at' => (string) $route['routed_at'],
        ]);

        if (!empty($route['received_at']) && (string) ($route['route_status'] ?? '') === 'received') {
            $insert->execute([
                'document_id' => (int) $route['document_id'],
                'route_id' => (int) $route['id'],
                'actor_user_id' => $validActorUserId($route['received_by']),
                'actor_office_id' => $route['to_office_id'] !== null ? (int) $route['to_office_id'] : null,
                'counterparty_office_id' => $route['from_office_id'] !== null ? (int) $route['from_office_id'] : null,
                'event_type' => 'received',
                'stage_after' => (string) ($route['handoff_type'] ?? '') === 'office_to_records' ? 'Returned to Records' : 'Under Action',
                'remarks' => 'Imported receipt acknowledgement.',
                'created_at' => (string) $route['received_at'],
            ]);
        }
    }

    $completedDocuments = $pdo->query(
        "SELECT id, current_office_id, updated_at
         FROM documents
         WHERE status = 'Completed'"
    )->fetchAll();
    foreach ($completedDocuments as $document) {
        $insert->execute([
            'document_id' => (int) $document['id'],
            'route_id' => null,
            'actor_user_id' => null,
            'actor_office_id' => $document['current_office_id'] !== null ? (int) $document['current_office_id'] : null,
            'counterparty_office_id' => null,
            'event_type' => 'completed',
            'stage_after' => 'Completed',
            'remarks' => 'Imported final completion state.',
            'created_at' => (string) $document['updated_at'],
        ]);
    }

    $archivedDocuments = $pdo->query(
        "SELECT id, current_office_id, archived_at
         FROM documents
         WHERE status = 'Archived'
           AND archived_at IS NOT NULL"
    )->fetchAll();
    foreach ($archivedDocuments as $document) {
        $insert->execute([
            'document_id' => (int) $document['id'],
            'route_id' => null,
            'actor_user_id' => null,
            'actor_office_id' => $document['current_office_id'] !== null ? (int) $document['current_office_id'] : null,
            'counterparty_office_id' => null,
            'event_type' => 'archived',
            'stage_after' => 'Archived',
            'remarks' => 'Imported archived state.',
            'created_at' => (string) $document['archived_at'],
        ]);
    }
}
