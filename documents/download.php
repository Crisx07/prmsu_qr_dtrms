<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$id = input_int($_GET['id'] ?? 0);
$document = require_accessible_document_or_redirect(
    $id,
    '/documents/index.php',
    'You are not allowed to download that attachment.',
    'You are not allowed to download that attachment.'
);
$mode = input_string($_GET['mode'] ?? 'download');

if (!document_records_officer_custody_access_allowed($document)) {
    set_flash('error', records_officer_custody_gate_message());
    redirect('/documents/scan.php?id=' . (int) $document['id']);
}

if (!can_access_document_attachment($document)) {
    set_flash('error', 'You are not allowed to open that attachment.');
    redirect('/documents/view.php?id=' . $id);
}

$path = document_file_path($document['attachment_path']);
if ($path === null || !is_file($path)) {
    set_flash('error', 'Attachment file not found.');
    redirect('/documents/view.php?id=' . $id);
}

$downloadName = normalize_filename((string) ($document['attachment_original_name'] ?: $document['attachment_path']));
$mime = document_attachment_mime($document);
$disposition = $mode === 'view' && document_attachment_is_browser_viewable($document) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
