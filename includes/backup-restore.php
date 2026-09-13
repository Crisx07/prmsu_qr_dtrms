<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function restore_backup_dir_path(): string
{
    return rtrim(str_replace('\\', '/', BACKUP_DIR), '/');
}

function restore_backup_filename_is_valid(string $filename): bool
{
    return preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip(?:\.enc)?$/', $filename) === 1;
}

function restore_ensure_backup_directory(): string
{
    $dir = restore_backup_dir_path();
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

function restore_backup_file_path(string $filename): string
{
    $filename = basename($filename);
    if (!restore_backup_filename_is_valid($filename)) {
        throw new RuntimeException('Invalid backup file.');
    }

    $path = restore_ensure_backup_directory() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        throw new RuntimeException('Backup file was not found.');
    }

    return $path;
}

function restore_backup_read_manifest(string $path): array
{
    $manifest = [];
    $readPath = $path;
    $tempPath = '';

    if (backup_file_is_encrypted($path)) {
        $manifest['encrypted'] = true;
        if (!backup_encryption_key_is_configured()) {
            return $manifest;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'dtrms-manifest-');
        if (!is_string($tempPath) || $tempPath === '') {
            return $manifest;
        }

        try {
            backup_decrypt_file_to($path, $tempPath);
            $readPath = $tempPath;
        } catch (Throwable $e) {
            @unlink($tempPath);
            $manifest['manifest_error'] = $e->getMessage();
            return $manifest;
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($readPath) === true) {
        $raw = $zip->getFromName('manifest.json');
        $zip->close();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $manifest = array_merge($manifest, $decoded);
            }
        }
    }

    if ($tempPath !== '') {
        @unlink($tempPath);
    }

    return $manifest;
}

function restore_backup_list(): array
{
    $dir = restore_ensure_backup_directory();
    $files = glob($dir . DIRECTORY_SEPARATOR . 'backup-*.zip*') ?: [];
    $backups = [];

    foreach ($files as $path) {
        $filename = basename($path);
        if (!restore_backup_filename_is_valid($filename) || !is_file($path)) {
            continue;
        }

        $manifest = restore_backup_read_manifest($path);
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

function restore_token_is_configured(): bool
{
    return trim((string) BACKUP_RESTORE_TOKEN) !== '';
}

function backup_encryption_key_is_configured(): bool
{
    return trim((string) BACKUP_ENCRYPTION_KEY) !== '';
}

function backup_encryption_key_bytes(): string
{
    $key = trim((string) BACKUP_ENCRYPTION_KEY);
    if ($key === '') {
        throw new RuntimeException('Backup encryption key is not configured. Set BACKUP_ENCRYPTION_KEY in .env before creating or restoring encrypted backups.');
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $key) === 1) {
        $binary = hex2bin($key);
        if (is_string($binary) && strlen($binary) === 32) {
            return $binary;
        }
    }

    return hash('sha256', $key, true);
}

function backup_encryption_magic(): string
{
    return 'DTRMSENC1';
}

function backup_file_is_encrypted(string $path): bool
{
    if (!is_file($path)) {
        return str_ends_with(strtolower($path), '.enc');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return str_ends_with(strtolower($path), '.enc');
    }

    $magic = fread($handle, strlen(backup_encryption_magic()));
    fclose($handle);

    return $magic === backup_encryption_magic();
}

function backup_encrypt_file(string $sourcePath, string $targetPath): void
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for encrypted backups.');
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Backup source archive was not found for encryption.');
    }

    $plain = file_get_contents($sourcePath);
    if (!is_string($plain)) {
        throw new RuntimeException('Unable to read backup archive for encryption.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', backup_encryption_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to encrypt backup archive.');
    }

    $payload = backup_encryption_magic() . $iv . $tag . $cipher;
    if (file_put_contents($targetPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write encrypted backup archive.');
    }
}

function backup_decrypt_file_to(string $sourcePath, string $targetPath): void
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required to restore encrypted backups.');
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Encrypted backup file was not found.');
    }

    $payload = file_get_contents($sourcePath);
    if (!is_string($payload)) {
        throw new RuntimeException('Unable to read encrypted backup file.');
    }

    $magic = backup_encryption_magic();
    $minimumLength = strlen($magic) + 12 + 16;
    if (strlen($payload) <= $minimumLength || substr($payload, 0, strlen($magic)) !== $magic) {
        throw new RuntimeException('Backup file is not a recognized encrypted archive.');
    }

    $offset = strlen($magic);
    $iv = substr($payload, $offset, 12);
    $offset += 12;
    $tag = substr($payload, $offset, 16);
    $offset += 16;
    $cipher = substr($payload, $offset);

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', backup_encryption_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        throw new RuntimeException('Unable to decrypt backup archive. Verify BACKUP_ENCRYPTION_KEY.');
    }

    if (file_put_contents($targetPath, $plain, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write decrypted backup workspace archive.');
    }
}

function restore_validate_token(string $submittedToken): void
{
    $configuredToken = trim((string) BACKUP_RESTORE_TOKEN);
    if ($configuredToken === '') {
        throw new RuntimeException('Emergency restore token is not configured. Set BACKUP_RESTORE_TOKEN in .env before restoring.');
    }

    if (!hash_equals($configuredToken, trim($submittedToken))) {
        throw new RuntimeException('Invalid emergency restore token.');
    }
}

function restore_admin_session_available(): bool
{
    $user = current_user();

    return is_array($user) && (string) ($user['role'] ?? '') === 'admin';
}

function restore_mysql_candidates(): array
{
    $configured = trim((string) BACKUP_MYSQL_PATH);
    $candidates = [
        $configured,
        'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe',
        dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe',
        'C:/xampp/mysql/bin/mysql.exe',
        'mysql',
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

function restore_mysql_plugin_dir_for(string $mysql): string
{
    $configured = trim((string) BACKUP_MYSQL_PLUGIN_DIR);
    if ($configured !== '') {
        return $configured;
    }

    if (!is_file($mysql)) {
        return '';
    }

    $binDir = dirname($mysql);
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

function restore_mysql_option_value(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function restore_mysql_command(string $mysql, string $defaultsFile): string
{
    $command = escapeshellarg($mysql)
        . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
        . ' --binary-mode=1';

    $pluginDir = restore_mysql_plugin_dir_for($mysql);
    if ($pluginDir !== '') {
        $command .= ' --plugin-dir=' . escapeshellarg($pluginDir);
    }

    return $command;
}

function restore_mysql_failure_detail(string $mysql, int|string $exitCode, string $stderr): string
{
    $detail = trim($stderr);
    $message = 'mysql client ' . $mysql . ' failed';
    if ($exitCode !== '') {
        $message .= ' with exit code ' . $exitCode;
    }
    if ($detail !== '') {
        $message .= ': ' . $detail;
    }

    return $message;
}

function restore_run_mysql_import(string $sqlPath, string $workDir): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is required to run mysql restore.');
    }
    if (!is_file($sqlPath) || filesize($sqlPath) <= 0) {
        throw new RuntimeException('Backup database dump is missing or empty.');
    }

    $defaultsFile = $workDir . DIRECTORY_SEPARATOR . 'mysql-restore-client.cnf';
    $defaults = "[client]\n"
        . 'host=' . restore_mysql_option_value(DB_HOST) . "\n"
        . 'port=' . (int) DB_PORT . "\n"
        . 'user=' . restore_mysql_option_value(DB_USER) . "\n";
    if ((string) DB_PASS !== '') {
        $defaults .= 'password=' . restore_mysql_option_value(DB_PASS) . "\n";
    }
    file_put_contents($defaultsFile, $defaults);

    $failureDetails = [];
    foreach (restore_mysql_candidates() as $mysql) {
        $stderrPath = $workDir . DIRECTORY_SEPARATOR . 'mysql-restore.err';
        $stdoutPath = $workDir . DIRECTORY_SEPARATOR . 'mysql-restore.out';
        @unlink($stderrPath);
        @unlink($stdoutPath);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(restore_mysql_command($mysql, $defaultsFile), $descriptorSpec, $pipes, ROOT_PATH);
        if (!is_resource($process)) {
            $failureDetails[] = restore_mysql_failure_detail($mysql, '', 'Unable to start process.');
            continue;
        }

        $input = fopen($sqlPath, 'rb');
        if ($input === false) {
            fclose($pipes[0]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Unable to read database dump.');
        }

        stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            @unlink($defaultsFile);
            return;
        }

        $failureDetails[] = restore_mysql_failure_detail($mysql, $exitCode, (string) $stderr);
    }

    @unlink($defaultsFile);
    throw new RuntimeException('Database restore failed. ' . implode(' ', $failureDetails));
}

function restore_remove_directory(string $dir): void
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

function restore_remove_path(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        restore_remove_directory($path);
        return;
    }

    if (file_exists($path)) {
        @unlink($path);
    }
}

function restore_zip_member_name_is_safe(string $name): bool
{
    $name = str_replace('\\', '/', $name);
    if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
        return false;
    }

    foreach (explode('/', $name) as $part) {
        if ($part === '..') {
            return false;
        }
    }

    return true;
}

function restore_extract_zip_contents_safely(string $zipPath, string $targetDir): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Unable to open uploads archive from backup.');
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        $zip->close();
        throw new RuntimeException('Unable to create staged uploads directory.');
    }

    $fileCount = 0;
    $totalBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
        $rawName = str_replace('\\', '/', $name);
        if ($rawName === '') {
            continue;
        }
        if (!restore_zip_member_name_is_safe($rawName)) {
            $zip->close();
            throw new RuntimeException('Uploads archive contains an unsafe path: ' . $name);
        }

        $normalizedName = trim($rawName, '/');
        if ($normalizedName === '') {
            continue;
        }

        if (str_ends_with($rawName, '/')) {
            $dir = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedName);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $zip->close();
                throw new RuntimeException('Unable to create upload restore directory.');
            }
            continue;
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedName);
        $targetParent = dirname($targetPath);
        if (!is_dir($targetParent) && !mkdir($targetParent, 0775, true) && !is_dir($targetParent)) {
            $zip->close();
            throw new RuntimeException('Unable to create upload restore directory.');
        }

        $input = $zip->getStream($name);
        $output = fopen($targetPath, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $zip->close();
            throw new RuntimeException('Unable to extract uploaded attachment from backup.');
        }

        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        $fileCount++;
        $totalBytes += is_file($targetPath) ? (filesize($targetPath) ?: 0) : 0;
    }

    $zip->close();

    return ['file_count' => $fileCount, 'total_bytes' => $totalBytes];
}

function restore_absolute_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }
    if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
        return rtrim($path, '/');
    }

    return rtrim(str_replace('\\', '/', ROOT_PATH . '/' . $path), '/');
}

function restore_path_is_filesystem_root(string $path): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');

    return $path === '' || $path === '/' || preg_match('/^[A-Za-z]:$/', $path) === 1;
}

function restore_upload_target_path(): string
{
    $target = restore_absolute_path(UPLOAD_DOCUMENTS);
    $root = restore_absolute_path(ROOT_PATH);
    $backupDir = restore_absolute_path(BACKUP_DIR);

    if (restore_path_is_filesystem_root($target) || $target === $root || $target === $backupDir) {
        throw new RuntimeException('Upload restore target is unsafe: ' . UPLOAD_DOCUMENTS);
    }

    return $target;
}

function restore_replace_upload_directory(string $stagedUploadsDir): array
{
    if (!is_dir($stagedUploadsDir)) {
        throw new RuntimeException('Staged uploads directory is missing.');
    }

    $target = restore_upload_target_path();
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Unable to create uploads parent directory.');
    }

    $oldPath = '';
    if (file_exists($target)) {
        $oldPath = $target . '-restore-old-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        if (!rename($target, $oldPath)) {
            throw new RuntimeException('Unable to move existing uploads directory before restore.');
        }
    }

    if (!rename($stagedUploadsDir, $target)) {
        if ($oldPath !== '' && !file_exists($target)) {
            @rename($oldPath, $target);
        }
        throw new RuntimeException('Unable to replace uploads directory with restored files.');
    }

    if ($oldPath !== '') {
        restore_remove_path($oldPath);
    }

    return ['uploads_path' => $target];
}

function restore_extract_backup_files(string $backupPath, string $workDir): array
{
    $readPath = $backupPath;
    if (backup_file_is_encrypted($backupPath)) {
        $readPath = $workDir . DIRECTORY_SEPARATOR . 'decrypted-backup.zip';
        backup_decrypt_file_to($backupPath, $readPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($readPath) !== true) {
        throw new RuntimeException('Unable to open backup archive.');
    }

    $requiredFiles = ['database.sql', 'uploads-documents.zip', 'manifest.json'];
    foreach ($requiredFiles as $requiredFile) {
        if ($zip->locateName($requiredFile) === false) {
            $zip->close();
            throw new RuntimeException('Backup archive is missing ' . $requiredFile . '.');
        }
    }

    if (!$zip->extractTo($workDir, $requiredFiles)) {
        $zip->close();
        throw new RuntimeException('Unable to extract restore files from backup.');
    }
    $zip->close();

    $manifestPath = $workDir . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Backup manifest is invalid.');
    }

    return $manifest;
}

function restore_audit_log(string $filename, array $manifest, array $uploadStats): void
{
    try {
        audit_log('restore_backup', 'backup', null, [
            'file' => $filename,
            'database_name' => $manifest['database_name'] ?? DB_NAME,
            'upload_file_count' => $uploadStats['file_count'] ?? null,
            'restored_by_session_user' => current_user()['id'] ?? null,
        ]);
    } catch (Throwable) {
        // The database may have just been recreated; audit logging is best-effort here.
    }
}

function restore_system_backup(string $filename): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive extension is required to restore backups.');
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }

    $backupPath = restore_backup_file_path($filename);
    $backupDir = restore_ensure_backup_directory();
    $workDir = $backupDir . DIRECTORY_SEPARATOR . 'restore-work-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($workDir, 0775, true) && !is_dir($workDir)) {
        throw new RuntimeException('Unable to create temporary restore workspace.');
    }

    try {
        $manifest = restore_extract_backup_files($backupPath, $workDir);

        $stagedUploadsDir = $workDir . DIRECTORY_SEPARATOR . 'uploads-stage';
        $uploadStats = restore_extract_zip_contents_safely($workDir . DIRECTORY_SEPARATOR . 'uploads-documents.zip', $stagedUploadsDir);

        restore_run_mysql_import($workDir . DIRECTORY_SEPARATOR . 'database.sql', $workDir);
        restore_replace_upload_directory($stagedUploadsDir);
        restore_audit_log(basename($backupPath), $manifest, $uploadStats);

        return [
            'file' => basename($backupPath),
            'manifest' => $manifest,
            'upload_file_count' => $uploadStats['file_count'],
            'upload_total_bytes' => $uploadStats['total_bytes'],
        ];
    } finally {
        restore_remove_directory($workDir);
    }
}
