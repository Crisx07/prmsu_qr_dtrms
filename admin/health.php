<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup-restore.php';

require_roles(['admin']);

$pageTitle = 'Maintenance';
$databaseStatus = database_status();
$sensitiveDenyFiles = [
    '.env files' => base_path('.htaccess'),
    'config' => base_path('config/.htaccess'),
    'database' => base_path('database/.htaccess'),
    'vendor' => base_path('vendor/.htaccess'),
    'uploads' => base_path('uploads/.htaccess'),
];
$missingDenyFiles = [];
foreach ($sensitiveDenyFiles as $label => $path) {
    if (!is_file($path)) {
        $missingDenyFiles[] = $label;
    }
}

$uploadReady = is_dir(UPLOAD_DOCUMENTS) && is_writable(UPLOAD_DOCUMENTS);
$httpsDetected = config_request_is_https();
$publicUrlHttps = app_public_url_is_https();
$pendingMigrations = $databaseStatus['pending_migrations'] ?? [];
$ocrAssetPaths = [
    'Tesseract.js' => base_path('assets/js/vendor/ocr/tesseract/tesseract.min.js'),
    'Tesseract worker' => base_path('assets/js/vendor/ocr/tesseract/worker.min.js'),
    'Tesseract core' => base_path('assets/js/vendor/ocr/tesseract-core/tesseract-core-lstm.wasm.js'),
    'Tesseract English data' => base_path('assets/js/vendor/ocr/tessdata/eng.traineddata.gz'),
    'PDF.js' => base_path('assets/js/vendor/ocr/pdfjs/pdf.min.js'),
    'PDF.js worker' => base_path('assets/js/vendor/ocr/pdfjs/pdf.worker.min.js'),
];
$missingOcrAssets = [];
foreach ($ocrAssetPaths as $label => $path) {
    if (!is_file($path)) {
        $missingOcrAssets[] = $label;
    }
}
$checks = [
    [
        'label' => 'Database Connection',
        'ok' => (bool) $databaseStatus['connected'],
        'detail' => $databaseStatus['connected'] ? 'Connected to MySQL.' : (string) ($databaseStatus['error'] ?? 'Unable to connect.'),
    ],
    [
        'label' => 'Database Schema',
        'ok' => (bool) $databaseStatus['ready'],
        'detail' => $databaseStatus['ready']
            ? 'Required tables are present.'
            : 'Missing tables: ' . implode(', ', $databaseStatus['missing_tables'] ?? []),
    ],
    [
        'label' => 'Upload Directory',
        'ok' => $uploadReady,
        'detail' => UPLOAD_DOCUMENTS . ' / max upload ' . format_bytes(max_upload_bytes()),
    ],
    [
        'label' => 'App Key',
        'ok' => app_key_is_configured(),
        'detail' => app_key_is_configured() ? 'Configured.' : 'Set a unique APP_KEY value in the environment file.',
    ],
    [
        'label' => 'Mail / Recovery OTP',
        'ok' => !auth_otp_enabled() || mail_is_configured(),
        'detail' => auth_otp_enabled()
            ? (mail_is_configured() ? 'Recovery OTP is enabled and mail prerequisites are present.' : 'Recovery OTP is enabled but APP_KEY or SMTP settings are incomplete.')
            : 'Recovery email OTP is disabled.',
    ],
    [
        'label' => 'Session Cookie Security',
        'ok' => true,
        'detail' => $httpsDetected
            ? 'HTTPS detected; secure, HttpOnly, SameSite cookies are active.'
            : 'HttpOnly and SameSite cookies are active; the Secure flag turns on when served over HTTPS.',
    ],
    [
        'label' => 'Public HTTPS URL',
        'ok' => $publicUrlHttps,
        'detail' => APP_PUBLIC_URL !== ''
            ? ($publicUrlHttps ? APP_PUBLIC_URL : 'APP_PUBLIC_URL should use https:// for printed QR links on a campus server.')
            : 'Set APP_PUBLIC_URL to the campus HTTPS URL before printing production QR cover sheets.',
    ],
    [
        'label' => 'Security Headers',
        'ok' => SECURITY_HEADERS_ENABLED,
        'detail' => SECURITY_HEADERS_ENABLED
            ? 'CSP, frame protection, content type, referrer, permissions, and HTTPS HSTS headers are enabled.'
            : 'Set SECURITY_HEADERS_ENABLED=true before production use.',
    ],
    [
        'label' => 'Encrypted Backups',
        'ok' => backup_encryption_key_is_configured(),
        'detail' => backup_encryption_key_is_configured()
            ? 'BACKUP_ENCRYPTION_KEY is configured; new backups are encrypted.'
            : 'Set BACKUP_ENCRYPTION_KEY before creating production backups.',
    ],
    [
        'label' => 'Backup Retention',
        'ok' => BACKUP_RETENTION_DAYS >= 0,
        'detail' => BACKUP_RETENTION_DAYS > 0
            ? 'Local encrypted backups older than ' . BACKUP_RETENTION_DAYS . ' days are removed after new backup creation.'
            : 'Automatic local backup retention is disabled.',
    ],
    [
        'label' => 'Schema Migrations',
        'ok' => $pendingMigrations === [],
        'detail' => $pendingMigrations === []
            ? 'No pending migrations. Auto-run is ' . (MIGRATIONS_AUTO_RUN ? 'enabled.' : 'disabled.')
            : 'Pending migrations: ' . implode(', ', $pendingMigrations) . '. Run Admin > Migrations.',
    ],
    [
        'label' => 'Sensitive Folder Access Rules',
        'ok' => $missingDenyFiles === [],
        'detail' => $missingDenyFiles === []
            ? 'Deny rules are present for config, database, vendor, uploads, and .env files.'
            : 'Missing deny rules: ' . implode(', ', $missingDenyFiles),
    ],
    [
        'label' => 'OCR Browser Dependency',
        'ok' => $missingOcrAssets === [],
        'detail' => $missingOcrAssets === []
            ? 'Local Tesseract.js, PDF.js, worker, core, and English trained data assets are installed.'
            : 'Missing local OCR assets: ' . implode(', ', $missingOcrAssets),
    ],
];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card page-hero page-hero-compact">
    <div class="page-hero-main">
        <div>
            <div class="hero-eyebrow">Administrator Deployment Check</div>
            <h2>System Health</h2>
            <p class="muted">Review core readiness checks for database access, uploads, mail, session security, and protected folders.</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Optimization</h2>
            <p class="muted">These checks do not expose credentials or document contents.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $check): ?>
                    <tr>
                        <td><strong><?= e($check['label']) ?></strong></td>
                        <td><span class="<?= $check['ok'] ? 'badge success' : 'badge danger' ?>"><?= $check['ok'] ? 'Ready' : 'Needs Attention' ?></span></td>
                        <td><?= e($check['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
