<?php

declare(strict_types=1);

use chillerlan\QRCode\QRCode;

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$id = input_int($_GET['id'] ?? 0);
$document = require_accessible_document_or_redirect(
    $id,
    '/documents/index.php',
    'Document not found.',
    'You are not allowed to print the QR cover sheet for that document.'
);

if (!document_can_print_qr($document)) {
    if (!document_can_have_qr($document)) {
        set_flash('error', 'QR cover sheets are available only after a document is officially released.');
    } else {
        set_flash('error', 'This document does not have a QR token yet.');
    }
    redirect('/documents/view.php?id=' . $id);
}

$token = trim((string) ($document['qr_token'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/i', $token) !== 1) {
    set_flash('error', 'This document does not have a QR token yet.');
    redirect('/documents/view.php?id=' . $id);
}
if (!production_public_url_is_ready()) {
    set_flash('error', 'Set APP_PUBLIC_URL to the campus HTTPS URL before printing production QR cover sheets.');
    redirect('/documents/view.php?id=' . $id);
}
$token = strtolower($token);
$document['qr_token'] = $token;
$qrUrl = document_qr_url($document);
$qrImageUrl = (new QRCode())->render($qrUrl);
$pageTitle = 'Print QR Cover Sheet';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card qr-cover-sheet">
    <div class="actions record-header no-print">
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>">Back to Document</a>
        <button class="btn" type="button" onclick="window.print()">Print Cover Sheet</button>
    </div>

    <div class="qr-cover-header">
        <div class="brand-logo-group">
            <?php foreach (app_brand_logos() as $logo): ?>
                <div class="brand-logo-badge">
                    <img class="brand-logo qr-cover-logo" src="<?= e($logo['src']) ?>" alt="<?= e($logo['alt']) ?>">
                </div>
            <?php endforeach; ?>
        </div>
        <div>
            <h1>PRMSU Iba Campus</h1>
            <p>QR Hardcopy Document Tracking</p>
            <!-- <strong>Records Office QR Cover Sheet</strong> -->
        </div>
    </div>

    <div class="qr-cover-body">
        <div class="qr-code-box">
            <img src="<?= e($qrImageUrl) ?>" alt="QR code for <?= e($document['tracking_no']) ?>">
            <p class="muted no-print">QR image is generated from the secure URL.</p>
        </div>
        <div class="qr-cover-details">
            <table>
                <tbody>
                    <tr><th>Tracking No.</th><td><?= e($document['tracking_no']) ?></td></tr>
                    <tr><th>Document No.</th><td><?= e($document['document_number'] ?? '-') ?></td></tr>
                    <tr><th>Document Name</th><td><?= e($document['document_name'] ?? '-') ?></td></tr>
                    <!-- <tr><th>Name in Document</th><td><?= e($document['document_person_name'] ?? '-') ?></td></tr> -->
                    <tr><th>Verification Code</th><td><?= e(document_verification_code($token)) ?></td></tr>
                    <tr><th>Subject</th><td><?= e($document['subject']) ?></td></tr>
                    <tr><th>Category</th><td><?= e($document['category_name'] ?? '-') ?></td></tr>
                    <tr>
                        <th>Origin Unit</th>
                        <td>
                            <?= e($document['origin_office_name'] ?? '-') ?>
                            <span class="muted office-contact"><?= e(office_contact_label($document['origin_office_trunk_line'] ?? null, $document['origin_office_local_number'] ?? null)) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Current Location</th>
                        <td>
                            <?= e($document['current_office_name'] ?? '-') ?>
                            <span class="muted office-contact"><?= e(office_contact_label($document['current_office_trunk_line'] ?? null, $document['current_office_local_number'] ?? null)) ?></span>
                        </td>
                    </tr>
                    <tr><th>Status</th><td><?= e($document['status']) ?></td></tr>
                    <tr><th>Hardcopy Page Count</th><td><?= $document['page_count'] ? (int) $document['page_count'] : '-' ?></td></tr>
                    <tr><th>Date of Creation</th><td><?= e($document['received_date']) ?></td></tr>
                    <tr><th>Created By</th><td><?= e($document['created_by_name'] ?? '-') ?></td></tr>
                    <tr><th>Created At</th><td><?= !empty($document['created_at']) ? e(format_datetime($document['created_at'])) : '-' ?></td></tr>
                    <tr><th>Released By</th><td><?= e($document['released_by_name'] ?? '-') ?></td></tr>
                    <tr><th>Released At</th><td><?= !empty($document['released_at']) ? e(format_datetime($document['released_at'])) : '-' ?></td></tr>
                    <!-- <tr><th>Digital File Hash</th><td><?= !empty($document['file_sha256']) ? e($document['file_sha256']) : '-' ?></td></tr> -->
                </tbody>
            </table>
        </div>
    </div>

    <div class="qr-cover-instructions">
        <h2>Tracking Instruction</h2>
        <p>Attach this sheet to the hardcopy document. Scan the QR whenever the physical document changes location so the system can show where it is.</p>
        <p><strong>QR URL:</strong><br><span class="break-word"><?= e($qrUrl) ?></span></p>
        <!-- <p class="muted no-print">For mobile live camera scanning, open this system through HTTPS or localhost. Set <code>APP_PUBLIC_URL</code> before printing when QR links must use a specific public HTTPS address.</p> -->
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
