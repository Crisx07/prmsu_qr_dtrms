<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$id = input_int($_GET['id'] ?? 0);
$side = strtolower(input_string($_GET['side'] ?? ''));

if (!in_array($side, ['front', 'back'], true)) {
    http_response_code(400);
    exit('Invalid ID side.');
}

$columns = $side === 'front'
    ? ['path' => 'id_front_path', 'mime' => 'id_front_mime', 'name' => 'id_front_original_name']
    : ['path' => 'id_back_path', 'mime' => 'id_back_mime', 'name' => 'id_back_original_name'];

$stmt = db()->prepare(
    'SELECT ' . $columns['path'] . ' AS file_path,
            ' . $columns['mime'] . ' AS file_mime,
            ' . $columns['name'] . ' AS original_name
     FROM account_requests
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $id]);
$request = $stmt->fetch();

if (!$request || empty($request['file_path'])) {
    http_response_code(404);
    exit('ID ' . $side . ' photo not found.');
}

$path = UPLOAD_ACCOUNT_REQUESTS . '/' . basename((string) $request['file_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('ID ' . $side . ' photo file not found.');
}

$mime = strtolower((string) ($request['file_mime'] ?? ''));
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
}

if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported ID photo type.');
}

$originalName = (string) ($request['original_name'] ?? ('prmsu-id-' . $side));
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: ('prmsu-id-' . $side);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . addslashes($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
