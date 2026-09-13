<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup-restore.php';

require_roles(['admin']);

$pageTitle = 'Backups';
$pagePath = '/admin/backups.php';
$errors = [];

function backup_dir_path(): string
{
    return rtrim(str_replace('\\', '/', BACKUP_DIR), '/');
}

function backup_filename_is_valid(string $filename): bool
{
    return preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip(?:\.enc)?$/', $filename) === 1;
}

function ensure_backup_directory(): string
{
    $dir = backup_dir_path();
    if ($dir === '') {
        throw new RuntimeException('Backup directory is not configured.');
    }

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create backup directory: ' . $dir);
    }

    $realDir = realpath($dir);
    $realRoot = realpath(ROOT_PATH);
    if ($realDir === false || $realRoot === false) {
        throw new RuntimeException('Unable to resolve backup directory path.');
    }

    $normalizedDir = rtrim(str_replace('\\', '/', $realDir), '/');
    $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
    if ($normalizedDir === $normalizedRoot || str_starts_with($normalizedDir . '/', $normalizedRoot . '/')) {
        throw new RuntimeException('Backup directory must be outside the public application folder.');
    }

    if (!is_writable($realDir)) {
        throw new RuntimeException('Backup directory is not writable: ' . $realDir);
    }

    $denyFile = $realDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\nDeny from all\n");
    }

    return $realDir;
}

function backup_file_path(string $filename): string
{
    $filename = basename($filename);
    if (!backup_filename_is_valid($filename)) {
        throw new RuntimeException('Invalid backup file.');
    }

    $dir = ensure_backup_directory();
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        throw new RuntimeException('Backup file was not found.');
    }

    return $path;
}

function backup_mysqldump_candidates(): array
{
    $configured = trim((string) BACKUP_MYSQLDUMP_PATH);

    $candidates = [
        $configured,
        'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump.exe',
        dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        'C:/xampp/mysql/bin/mysqldump.exe',
        'mysqldump',
    ];

    $unique = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        $realPath = is_file($candidate) ? realpath($candidate) : false;
        $key = strtolower(str_replace('\\', '/', $realPath !== false ? $realPath : $candidate));
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $unique[] = $realPath !== false ? $realPath : $candidate;
    }

    return $unique;
}

function backup_mysql_plugin_dir_for(string $mysqldump): string
{
    $configured = trim((string) BACKUP_MYSQL_PLUGIN_DIR);
    if ($configured !== '') {
        return $configured;
    }

    if (!is_file($mysqldump)) {
        return '';
    }

    $binDir = dirname($mysqldump);
    $candidates = [
        dirname($binDir) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'plugin',
        $binDir . DIRECTORY_SEPARATOR . 'plugin',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function backup_mysqldump_command(string $mysqldump, string $defaultsFile): string
{
    $command = escapeshellarg($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($defaultsFile);

    $pluginDir = backup_mysql_plugin_dir_for($mysqldump);
    if ($pluginDir !== '') {
        $command .= ' --plugin-dir=' . escapeshellarg($pluginDir);
    }

    return $command
        . ' --single-transaction --routines --triggers --events --hex-blob --databases '
        . escapeshellarg(DB_NAME);
}

function backup_mysqldump_failure_detail(string $mysqldump, int|string $exitCode, string $stderr): string
{
    $detail = trim((string) $stderr);
    $message = 'mysqldump client ' . $mysqldump . ' failed';
    if ($exitCode !== '') {
        $message .= ' with exit code ' . $exitCode;
    }

    if ($detail !== '') {
        $message .= ': ' . $detail;
    }

    return $message;
}

function backup_mysqldump_auth_hint(string $stderr): string
{
    if (stripos($stderr, 'caching_sha2_password') === false) {
        return '';
    }

    return ' The selected backup client cannot load the MySQL 8 caching_sha2_password authentication plugin.'
        . ' Configure BACKUP_MYSQLDUMP_PATH to a MySQL 8 mysqldump.exe.'
        . ' Set BACKUP_MYSQL_PLUGIN_DIR only when the plugin DLL exists in a custom folder.';
}

function backup_mysql_option_value(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function backup_run_mysqldump(string $dumpPath, string $workDir): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is required to run mysqldump.');
    }

    $defaultsFile = $workDir . DIRECTORY_SEPARATOR . 'mysql-client.cnf';
    $defaults = "[client]\n"
        . 'host=' . backup_mysql_option_value(DB_HOST) . "\n"
        . 'port=' . (int) DB_PORT . "\n"
        . 'user=' . backup_mysql_option_value(DB_USER) . "\n";
    if ((string) DB_PASS !== '') {
        $defaults .= 'password=' . backup_mysql_option_value(DB_PASS) . "\n";
    }
    file_put_contents($defaultsFile, $defaults);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $dumpPath, 'w'],
        2 => ['pipe', 'w'],
    ];

    $failureDetails = [];
    $authHint = '';
    foreach (backup_mysqldump_candidates() as $mysqldump) {
        @unlink($dumpPath);
        $command = backup_mysqldump_command($mysqldump, $defaultsFile);
        $process = proc_open($command, $descriptorSpec, $pipes, ROOT_PATH);
        if (!is_resource($process)) {
            $failureDetails[] = backup_mysqldump_failure_detail($mysqldump, '', 'Unable to start process.');
            continue;
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0 && is_file($dumpPath) && filesize($dumpPath) > 0) {
            @unlink($defaultsFile);
            return;
        }

        @unlink($dumpPath);
        $authHint = $authHint ?: backup_mysqldump_auth_hint((string) $stderr);
        $failureDetails[] = backup_mysqldump_failure_detail($mysqldump, $exitCode, (string) $stderr);
    }

    @unlink($defaultsFile);

    $detail = implode(' ', $failureDetails);
    throw new RuntimeException('Database backup failed.' . ($detail !== '' ? ' ' . $detail : '') . $authHint);
}

function backup_zip_directory(string $sourceDir, string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create uploads archive.');
    }

    $fileCount = 0;
    $totalBytes = 0;
    if (is_dir($sourceDir)) {
        $root = realpath($sourceDir);
        if ($root !== false) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile()) {
                    continue;
                }

                $path = $item->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                $zip->addFile($path, $relative);
                $fileCount++;
                $totalBytes += $item->getSize();
            }
        }
    }

    if (!$zip->close()) {
        throw new RuntimeException('Unable to finalize uploads archive.');
    }

    return ['file_count' => $fileCount, 'total_bytes' => $totalBytes];
}

function backup_add_file(ZipArchive $zip, string $path, string $localName): void
{
    if (!is_file($path) || !$zip->addFile($path, $localName)) {
        throw new RuntimeException('Unable to add ' . $localName . ' to backup.');
    }
}

function backup_apply_retention(string $dir): void
{
    $days = max(0, (int) BACKUP_RETENTION_DAYS);
    if ($days <= 0 || !is_dir($dir)) {
        return;
    }

    $cutoff = time() - ($days * 86400);
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'backup-*.zip*') ?: [] as $path) {
        $filename = basename($path);
        if (!backup_filename_is_valid($filename) || !is_file($path)) {
            continue;
        }

        $mtime = filemtime($path);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($path);
        }
    }
}

function backup_remove_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

function create_system_backup(): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive extension is required to create backups.');
    }
    if (!backup_encryption_key_is_configured()) {
        throw new RuntimeException('Set BACKUP_ENCRYPTION_KEY in .env before creating encrypted backups.');
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $backupDir = ensure_backup_directory();
    $timestamp = date('Y-m-d-His');
    $backupName = 'backup-' . $timestamp;
    $finalPath = $backupDir . DIRECTORY_SEPARATOR . $backupName . '.zip.enc';
    if (is_file($finalPath)) {
        throw new RuntimeException('A backup with this timestamp already exists. Try again.');
    }

    $workDir = $backupDir . DIRECTORY_SEPARATOR . $backupName . '-work-' . bin2hex(random_bytes(4));
    if (!mkdir($workDir, 0775, true) && !is_dir($workDir)) {
        throw new RuntimeException('Unable to create temporary backup workspace.');
    }

    try {
        $databaseDumpPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
        backup_run_mysqldump($databaseDumpPath, $workDir);

        $uploadsZipPath = $workDir . DIRECTORY_SEPARATOR . 'uploads-documents.zip';
        $uploadStats = backup_zip_directory(UPLOAD_DOCUMENTS, $uploadsZipPath);

        $envIncluded = false;
        $envSource = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
        $envCopyPath = $workDir . DIRECTORY_SEPARATOR . 'env-copy.txt';
        if (is_file($envSource) && is_readable($envSource)) {
            copy($envSource, $envCopyPath);
            $envIncluded = true;
        } else {
            file_put_contents($envCopyPath, "No readable .env file was found during backup.\n");
        }

        clearstatcache(true, $databaseDumpPath);
        clearstatcache(true, $uploadsZipPath);
        $manifest = [
            'backup_name' => $backupName,
            'created_at' => date('c'),
            'app_name' => APP_NAME,
            'app_path' => ROOT_PATH,
            'database_name' => DB_NAME,
            'database_dump_status' => 'ok',
            'database_dump_bytes' => is_file($databaseDumpPath) ? filesize($databaseDumpPath) : 0,
            'uploads_path' => UPLOAD_DOCUMENTS,
            'upload_file_count' => $uploadStats['file_count'],
            'upload_total_bytes' => $uploadStats['total_bytes'],
            'env_included' => $envIncluded,
            'encrypted' => true,
            'restore_test_status' => 'not_tested',
            'created_by' => [
                'id' => current_user()['id'] ?? null,
                'user_uid' => current_user()['user_uid'] ?? null,
                'name' => current_user()['full_name'] ?? null,
            ],
        ];
        $manifestPath = $workDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $plainZipPath = $workDir . DIRECTORY_SEPARATOR . $backupName . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($plainZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create final backup archive.');
        }

        backup_add_file($zip, $databaseDumpPath, 'database.sql');
        backup_add_file($zip, $uploadsZipPath, 'uploads-documents.zip');
        backup_add_file($zip, $envCopyPath, 'env-copy.txt');
        backup_add_file($zip, $manifestPath, 'manifest.json');
        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize backup archive.');
        }

        backup_encrypt_file($plainZipPath, $finalPath);
        clearstatcache(true, $finalPath);
        $manifest['backup_file'] = basename($finalPath);
        $manifest['backup_bytes'] = is_file($finalPath) ? filesize($finalPath) : 0;
        backup_apply_retention($backupDir);

        audit_log('create_backup', 'backup', null, [
            'file' => $manifest['backup_file'],
            'size' => $manifest['backup_bytes'],
            'upload_file_count' => $manifest['upload_file_count'],
            'encrypted' => true,
        ]);

        return $manifest;
    } finally {
        backup_remove_directory($workDir);
    }
}

function backup_read_manifest(string $path): array
{
    return restore_backup_read_manifest($path);
}

function backup_list(): array
{
    $dir = ensure_backup_directory();
    $files = glob($dir . DIRECTORY_SEPARATOR . 'backup-*.zip*') ?: [];
    $backups = [];

    foreach ($files as $path) {
        $filename = basename($path);
        if (!backup_filename_is_valid($filename) || !is_file($path)) {
            continue;
        }

        $manifest = backup_read_manifest($path);
        $backups[] = [
            'file' => $filename,
            'size' => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: 0,
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'database_dump_status' => (string) ($manifest['database_dump_status'] ?? 'unknown'),
            'upload_file_count' => (int) ($manifest['upload_file_count'] ?? 0),
            'restore_test_status' => (string) ($manifest['restore_test_status'] ?? 'not_tested'),
            'encrypted' => backup_file_is_encrypted($path),
            'legacy' => !backup_file_is_encrypted($path),
        ];
    }

    usort($backups, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    return $backups;
}

if (($_GET['action'] ?? '') === 'download') {
    try {
        $filename = input_string($_GET['file'] ?? '');
        $path = backup_file_path($filename);
        audit_log('download_backup', 'backup', null, ['file' => basename($path), 'size' => filesize($path) ?: 0]);

        header('Content-Type: ' . (backup_file_is_encrypted($path) ? 'application/octet-stream' : 'application/zip'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        redirect($pagePath);
    }
}

if (is_post()) {
    require_csrf($pagePath);
    $action = input_string($_POST['action'] ?? '');

    if ($action === 'create_backup') {
        try {
            $manifest = create_system_backup();
            set_flash('success', 'Backup created: ' . ($manifest['backup_file'] ?? 'backup file') . '.');
            redirect($pagePath);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'restore_backup') {
        try {
            restore_validate_token(input_string($_POST['restore_token'] ?? ''));
            $filename = input_string($_POST['file'] ?? '');
            $result = restore_system_backup($filename);
            set_flash('success', 'Backup restored: ' . ($result['file'] ?? $filename) . '. Please sign in again to continue.');
            redirect('/auth/login.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'delete_backup') {
        try {
            $filename = input_string($_POST['file'] ?? '');
            $path = backup_file_path($filename);
            $size = filesize($path) ?: 0;
            if (!unlink($path)) {
                throw new RuntimeException('Unable to delete backup file.');
            }
            audit_log('delete_backup', 'backup', null, ['file' => basename($path), 'size' => $size]);
            set_flash('success', 'Backup deleted.');
            redirect($pagePath);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$backupDir = '';
$backups = [];
try {
    $backupDir = ensure_backup_directory();
    $backups = backup_list();
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    $backupDir = backup_dir_path();
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card page-hero page-hero-compact">
    <div class="page-hero-main">
        <div>
            <div class="hero-eyebrow">System Administrator Disaster Recovery</div>
            <h2>Backups</h2>
            <p class="muted">Create protected backups of the database, uploaded document files, environment settings, and a manifest for restore checks.</p>
        </div>
        <div class="hero-summary">
            <div class="hero-summary-item">
                <span>Backup Directory</span>
                <strong><?= e($backupDir) ?></strong>
            </div>
            <div class="hero-summary-item">
                <span>Available Backups</span>
                <strong><?= count($backups) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Create Backup</h2>
            <p class="muted">New backups are encrypted <code>.zip.enc</code> archives containing <code>database.sql</code>, <code>uploads-documents.zip</code>, <code>env-copy.txt</code>, and <code>manifest.json</code>.</p>
        </div>
    </div>

    <?php if (!backup_encryption_key_is_configured()): ?>
        <div class="alert warning">Set <code>BACKUP_ENCRYPTION_KEY</code> in <code>.env</code> before creating backups.</div>
    <?php endif; ?>

    <form method="post" class="actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_backup">
        <button class="btn" type="submit" data-confirm="Create a full encrypted system backup now? This may take a few minutes." <?= !backup_encryption_key_is_configured() ? 'disabled' : '' ?>>Create Backup Now</button>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Restore Backup</h2>
            <p class="muted">Restore imports the database dump first, then replaces uploaded document files from the selected backup.</p>
        </div>
    </div>

    <?php if (!restore_token_is_configured()): ?>
        <div class="alert warning">Set <code>BACKUP_RESTORE_TOKEN</code> in <code>.env</code> before using restore.</div>
    <?php elseif (!$backups): ?>
        <div class="empty-state">No backup files are available to restore.</div>
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

    <div class="actions">
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/emergency-restore.php">Open Recovery Mode</a>
    </div>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Existing Backups</h2>
            <p class="muted">Store a weekly copy on an external drive or secure cloud storage. Restore-test at least monthly.</p>
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
                    <th>Restore Test</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$backups): ?>
                    <tr><td colspan="8"><div class="empty-state">No backup files have been created yet.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= e($backup['file']) ?></td>
                            <td><?= e($backup['created_at'] !== '' ? format_datetime($backup['created_at']) : format_datetime(date('Y-m-d H:i:s', (int) $backup['mtime']))) ?></td>
                            <td><?= e(format_bytes((int) $backup['size'])) ?></td>
                            <td><span class="<?= $backup['encrypted'] ? 'badge success' : 'badge warning' ?>"><?= $backup['encrypted'] ? 'Encrypted' : 'Legacy' ?></span></td>
                            <td><span class="<?= $backup['database_dump_status'] === 'ok' ? 'badge success' : 'badge warning' ?>"><?= e($backup['database_dump_status']) ?></span></td>
                            <td><?= (int) $backup['upload_file_count'] ?></td>
                            <td><span class="badge muted"><?= e($backup['restore_test_status']) ?></span></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm" href="<?= BASE_URL ?>/admin/backups.php?action=download&amp;file=<?= e(rawurlencode((string) $backup['file'])) ?>">Download</a>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="file" value="<?= e($backup['file']) ?>">
                                        <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete this backup file?">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Retention Guide</h2>
            <p class="muted">Recommended policy: keep daily backups for 7 days, weekly backups for 4 weeks, and monthly backups for 12 months.</p>
        </div>
    </div>

    <div class="metric-stack">
        <div class="metric-row">
            <span>Monthly Restore Test</span>
            <strong>Required</strong>
        </div>
        <div class="metric-row">
            <span>External Copy</span>
            <strong>Weekly</strong>
        </div>
        <div class="metric-row">
            <span>Automatic Local Retention</span>
            <strong><?= BACKUP_RETENTION_DAYS > 0 ? (int) BACKUP_RETENTION_DAYS . ' days' : 'Disabled' ?></strong>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
