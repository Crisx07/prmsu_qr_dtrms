<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/backup-restore.php';

$pageTitle = 'Emergency Recovery Mode';
$pagePath = '/admin/emergency-restore.php';
$errors = [];

if (is_post()) {
    require_csrf($pagePath);
    $action = input_string($_POST['action'] ?? '');

    if ($action === 'restore_backup') {
        try {
            restore_validate_token(input_string($_POST['restore_token'] ?? ''));
            $filename = input_string($_POST['file'] ?? '');
            $result = restore_system_backup($filename);
            set_flash('success', 'Backup restored: ' . ($result['file'] ?? $filename) . '. Please sign in again to continue.');
            redirect('/auth/login.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } else {
        $errors[] = 'Please choose a valid restore action.';
    }
}

$backupDir = restore_backup_dir_path();
$backups = [];
try {
    $backupDir = restore_ensure_backup_directory();
    $backups = restore_backup_list();
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$flash = get_flash();
$stylePath = ROOT_PATH . '/assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string) filemtime($stylePath) : (string) time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= e($styleVersion) ?>">
</head>
<body>
<main class="main-content">
    <div class="card page-hero page-hero-compact">
        <div class="page-hero-main">
            <div>
                <div class="hero-eyebrow">Standalone Disaster Recovery</div>
                <h2>Emergency Recovery Mode</h2>
                <p class="muted">Restore a protected backup from a recovery screen that runs outside the normal app navigation.</p>
                <?php if (restore_admin_session_available()): ?>
                    <div class="actions">
                        <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/backups.php">Back to Backups</a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="hero-summary">
                <div class="hero-summary-item">
                    <span>Backup Directory</span>
                    <strong><?= e($backupDir) ?></strong>
                </div>
                <div class="hero-summary-item">
                    <span>Session</span>
                    <strong><?= restore_admin_session_available() ? 'Admin detected' : 'Token required' ?></strong>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Restore From Backup</h2>
                <p class="muted">A restore decrypts encrypted backups when needed, imports <code>database.sql</code>, and replaces uploaded document attachments from <code>uploads-documents.zip</code>.</p>
            </div>
        </div>

        <?php if (!restore_token_is_configured()): ?>
            <div class="alert warning">Set <code>BACKUP_RESTORE_TOKEN</code> in <code>.env</code> before using emergency restore.</div>
        <?php elseif (!$backups): ?>
            <div class="empty-state">No backup files are available in the configured backup directory.</div>
        <?php else: ?>
            <form method="post" class="filter-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restore_backup">
                <select name="file" required>
                    <option value="">Select backup</option>
                    <?php foreach ($backups as $backup): ?>
                        <option value="<?= e($backup['file']) ?>"><?= e($backup['file']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="password" name="restore_token" placeholder="Restore token" required autocomplete="off">
                <div class="actions">
                    <button class="btn btn-danger" type="submit" data-confirm="Restore this backup now? This will replace the current database and uploaded document files.">Restore Selected Backup</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Available Backups</h2>
                <p class="muted">Only files named <code>backup-YYYY-MM-DD-HHMMSS.zip</code> or <code>backup-YYYY-MM-DD-HHMMSS.zip.enc</code> in the configured backup directory are eligible.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Backup File</th>
                        <th>Created</th>
                        <th>Size</th>
                        <th>Encryption</th>
                        <th>Database</th>
                        <th>Upload Files</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$backups): ?>
                        <tr><td colspan="6"><div class="empty-state">No backup files were found.</div></td></tr>
                    <?php else: ?>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td><?= e($backup['file']) ?></td>
                                <td><?= e($backup['created_at'] !== '' ? format_datetime($backup['created_at']) : format_datetime(date('Y-m-d H:i:s', (int) $backup['mtime']))) ?></td>
                                <td><?= e(format_bytes((int) $backup['size'])) ?></td>
                                <td><span class="<?= $backup['encrypted'] ? 'badge success' : 'badge warning' ?>"><?= $backup['encrypted'] ? 'Encrypted' : 'Legacy' ?></span></td>
                                <td><span class="<?= $backup['database_dump_status'] === 'ok' ? 'badge success' : 'badge warning' ?>"><?= e($backup['database_dump_status']) ?></span></td>
                                <td><?= (int) $backup['upload_file_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= filemtime(ROOT_PATH . '/assets/js/app.js') ?>"></script>
</body>
</html>
