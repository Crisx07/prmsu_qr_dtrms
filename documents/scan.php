<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

const QR_LOCATION_CONTEXT_TTL_SECONDS = 900;
const QR_SCAN_MISMATCH_MESSAGE = 'This is not the QR code or QR URL for this document. Please scan the QR printed or paste the full QR URL for the selected document.';

function qr_location_contexts(): array
{
    $contexts = $_SESSION['qr_location_contexts'] ?? [];
    if (!is_array($contexts)) {
        return [];
    }

    $now = time();
    foreach ($contexts as $contextToken => $expiresAt) {
        if (!is_string($contextToken) || (int) $expiresAt < $now) {
            unset($contexts[$contextToken]);
        }
    }

    $_SESSION['qr_location_contexts'] = $contexts;

    return $contexts;
}

function qr_location_context_is_active(string $token): bool
{
    $contexts = qr_location_contexts();

    return isset($contexts[$token]) && (int) $contexts[$token] >= time();
}

function remember_qr_location_context(string $token): void
{
    if ($token === '') {
        return;
    }

    $contexts = qr_location_contexts();
    $contexts[$token] = time() + QR_LOCATION_CONTEXT_TTL_SECONDS;
    $_SESSION['qr_location_contexts'] = $contexts;
}

function qr_value_is_full_scan_url(string $rawValue, string $token): bool
{
    $value = trim(html_entity_decode(rawurldecode($rawValue), ENT_QUOTES, 'UTF-8'));
    if ($value === '' || $token === '') {
        return false;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path']) || empty($parts['query'])) {
        return false;
    }

    $path = str_replace('\\', '/', (string) $parts['path']);
    if (!str_ends_with(strtolower($path), '/documents/scan.php')) {
        return false;
    }

    parse_str((string) $parts['query'], $query);

    return isset($query['t']) && is_string($query['t']) && strtolower($query['t']) === strtolower($token);
}

function qr_scan_submission_can_update_location(string $source, string $rawValue, string $token): bool
{
    if (!in_array($source, ['camera', 'photo', 'manual'], true)) {
        return false;
    }

    return qr_value_is_full_scan_url($rawValue, $token);
}

function qr_scan_page_path(int $expectedDocumentId = 0, string $token = ''): string
{
    $params = [];
    if ($expectedDocumentId > 0) {
        $params['id'] = $expectedDocumentId;
    }
    if ($token !== '') {
        $params['t'] = $token;
    }

    return '/documents/scan.php' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

function log_qr_scan_mismatch(array $expectedDocument, string $submittedToken): void
{
    $documentId = (int) ($expectedDocument['id'] ?? 0);
    if ($documentId <= 0) {
        return;
    }

    log_document_scan_event($documentId, null, 'scan', 'mismatch', QR_SCAN_MISMATCH_MESSAGE);
    audit_log('qr_scan_mismatch', 'document', $documentId, [
        'expected_tracking_no' => $expectedDocument['tracking_no'] ?? null,
        'submitted_token_prefix' => substr($submittedToken, 0, 12),
    ]);
}

$pageTitle = 'Scan QR';
$rawValue = input_string($_GET['t'] ?? '');
$token = extract_qr_token($rawValue);
$expectedDocumentId = input_int($_GET['id'] ?? ($_POST['expected_document_id'] ?? 0));
$errors = [];
$document = null;
$expectedDocument = null;
$scanBasePath = qr_scan_page_path($expectedDocumentId);
$pagePath = qr_scan_page_path($expectedDocumentId, $token);

if ($expectedDocumentId > 0) {
    $expectedDocument = require_accessible_document_or_redirect(
        $expectedDocumentId,
        '/documents/index.php',
        'Document not found.',
        'You are not allowed to scan for that document.'
    );
}

if (is_post()) {
    $action = input_string($_POST['action'] ?? '');

    if ($action === 'open_scan') {
        require_csrf($scanBasePath);

        $submitted = input_string($_POST['qr_value'] ?? '');
        $scanSource = input_string($_POST['scan_source'] ?? 'manual');
        $submittedToken = extract_qr_token($submitted);
        if ($submittedToken === '') {
            set_flash('error', 'Invalid QR value. Paste the full QR URL.');
            redirect($scanBasePath);
        }

        if ($expectedDocument !== null && !document_qr_token_matches($expectedDocument, $submitted)) {
            log_qr_scan_mismatch($expectedDocument, $submittedToken);
            set_flash('error', QR_SCAN_MISMATCH_MESSAGE);
            redirect($scanBasePath);
        }

        if (qr_scan_submission_can_update_location($scanSource, $submitted, $submittedToken)) {
            remember_qr_location_context($submittedToken);
        }

        redirect(qr_scan_page_path($expectedDocumentId, $submittedToken));
    }
}

if ($token !== '') {
    if ($expectedDocument !== null && !document_qr_token_matches($expectedDocument, $token)) {
        log_qr_scan_mismatch($expectedDocument, $token);
        $errors[] = QR_SCAN_MISMATCH_MESSAGE;
    } else {
        $document = fetch_document_by_qr_token($token);

        if ($document !== null && $expectedDocumentId > 0 && (int) $document['id'] !== $expectedDocumentId) {
            log_qr_scan_mismatch($expectedDocument ?? $document, $token);
            $errors[] = QR_SCAN_MISMATCH_MESSAGE;
            $document = null;
        } elseif ($document === null) {
            audit_log('invalid_qr_scan', 'document', null, [
                'token_prefix' => substr($token, 0, 12),
            ]);
            $errors[] = 'Invalid QR code. No active document record was found for this token.';
        } else {
            if (!is_post()) {
                log_document_scan_event((int) $document['id'], null, 'scan', 'valid', 'QR code scanned and document record opened.');
                audit_log('qr_scan', 'document', (int) $document['id'], [
                    'tracking_no' => $document['tracking_no'],
                ]);
            }

            if (is_post()) {
                require_csrf($pagePath);
                $action = input_string($_POST['action'] ?? '');

                if ($action === 'update_location') {
                    if (!qr_location_context_is_active($token)) {
                        set_flash('error', 'Scan the QR code or paste the full QR URL to update the document location.');
                        redirect($pagePath);
                    }

                    $locationOffices = location_update_offices();
                    $locationOfficeIds = [];
                    foreach ($locationOffices as $office) {
                        $locationOfficeIds[(int) $office['id']] = true;
                    }

                    if (!can_update_document_location($document)) {
                        set_flash('error', 'You are not allowed to update this document location.');
                        redirect($pagePath);
                    }

                    $requiresCustodyGate = document_requires_records_officer_custody_gate($document);
                    $officeId = $requiresCustodyGate ? records_office_id() : input_int($_POST['current_office_id'] ?? 0);
                    $remarks = input_string($_POST['remarks'] ?? '');
                    $physicalHandlerName = substr(input_string($_POST['physical_handler_name'] ?? ''), 0, 150);
                    if ($officeId <= 0 || !isset($locationOfficeIds[$officeId])) {
                        set_flash('error', 'Please choose a valid location.');
                        redirect($pagePath);
                    }
                    if ($remarks === '') {
                        set_flash('error', 'Please add remarks before updating the location.');
                        redirect($pagePath);
                    }

                    perform_document_location_update((int) $document['id'], $officeId, $remarks, $physicalHandlerName);
                    $updatedDocument = fetch_document((int) $document['id']);
                    if (is_array($updatedDocument) && document_requires_records_officer_custody_gate($updatedDocument) && is_records_office_id((int) ($updatedDocument['current_office_id'] ?? 0))) {
                        remember_document_custody_verification($updatedDocument);
                    }
                    set_flash('success', 'Document location updated.');
                    redirect($pagePath);
                }

                if ($action === 'confirm_custody') {
                    if (!qr_location_context_is_active($token)) {
                        set_flash('error', 'Scan the QR code or paste the full QR URL to update the document location.');
                        redirect($pagePath);
                    }

                    if (!document_requires_records_officer_custody_gate($document) || !can_update_document_location($document)) {
                        set_flash('error', 'You are not allowed to confirm custody for this document.');
                        redirect($pagePath);
                    }

                    if (!is_records_office_id((int) ($document['current_office_id'] ?? 0))) {
                        set_flash('error', 'Update the document location to the Records Office before confirming custody.');
                        redirect($pagePath);
                    }

                    $remarks = input_string($_POST['remarks'] ?? '');
                    $physicalHandlerName = substr(input_string($_POST['physical_handler_name'] ?? ''), 0, 150);
                    if ($remarks === '') {
                        set_flash('error', 'Please add remarks before confirming custody.');
                        redirect($pagePath);
                    }

                    perform_records_officer_custody_confirmation((int) $document['id'], $remarks, $physicalHandlerName);
                    $updatedDocument = fetch_document((int) $document['id']);
                    if (is_array($updatedDocument)) {
                        remember_document_custody_verification($updatedDocument);
                    }
                    set_flash('success', 'Records Office custody confirmed.');
                    redirect($pagePath);
                }

                if ($action === 'archive') {
                    if (!document_records_officer_custody_access_allowed($document)) {
                        set_flash('error', records_officer_custody_gate_message());
                        redirect($pagePath);
                    }

                    if (!document_can_archive($document, null)) {
                        set_flash('error', 'This document must be archived by its assigned archiving office.');
                        redirect($pagePath);
                    }

                    $archiveNote = input_string($_POST['archive_note'] ?? '');
                    if ($archiveNote === '') {
                        set_flash('error', 'Please provide an archive note before archiving this document.');
                        redirect($pagePath);
                    }

                    perform_archive_document((int) $document['id'], $archiveNote);
                    set_flash('success', 'Document archived successfully.');
                    redirect($pagePath);
                }
            }

            $document = fetch_document((int) $document['id']);
        }
    }
}

$locationOffices = $document ? location_update_offices() : [];
$requiresCustodyGate = $document ? document_requires_records_officer_custody_gate($document) : false;
$documentAtRecordsOffice = $document ? is_records_office_id((int) ($document['current_office_id'] ?? 0)) : false;
$hasQrLocationContext = $document ? qr_location_context_is_active($token) : false;
$hasCustodyVerification = $document ? document_custody_is_verified($document) : false;
$canOpenFullRecord = $document ? can_access_document($document) && document_records_officer_custody_access_allowed($document) : false;
$canConfirmCustody = $document
    ? $requiresCustodyGate && $hasQrLocationContext && !$hasCustodyVerification && $documentAtRecordsOffice && can_update_document_location($document)
    : false;
$canUpdateLocation = $document
    ? $hasQrLocationContext
        && can_update_document_location($document)
        && (!$requiresCustodyGate || (!$hasCustodyVerification && !$documentAtRecordsOffice))
    : false;
$canDownloadAttachment = $document ? $requiresCustodyGate && $hasCustodyVerification && $canOpenFullRecord && !empty($document['attachment_path']) : false;
$canPrintQr = $document ? document_can_print_qr($document) : false;
$canArchiveFromScan = $document ? $requiresCustodyGate && $hasCustodyVerification && document_can_archive($document, null) : false;

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card no-print">
    <div class="section-heading">
        <div>
            <h2><?= $expectedDocument ? 'Scan Selected Document QR' : 'QR Document Lookup' ?></h2>
            <p class="muted"><?= $expectedDocument ? 'Scan the QR printed for the selected document before updating custody or opening the full record.' : 'Scan the printed QR cover sheet or paste the QR URL to open the document record and update its current location.' ?></p>
        </div>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php">Back to Documents</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <?php if ($expectedDocument): ?>
        <p class="muted record-subtitle">Selected document: <strong><?= e($expectedDocument['tracking_no']) ?></strong></p>
    <?php endif; ?>

    <div class="qr-scanner-grid">
        <div>
            <video id="qr-video" class="qr-video" muted playsinline></video>
            <canvas id="qr-canvas" hidden></canvas>
            <div class="actions qr-scan-actions">
                <button class="btn" type="button" id="qr-start">Start Camera Scan</button>
                <button class="btn btn-secondary" type="button" id="qr-stop">Stop</button>
                <label class="btn btn-secondary" for="qr-photo">Scan QR from Photo</label>
                <input class="camera-file-input" type="file" id="qr-photo" accept="image/*">
            </div>
            <p class="muted" id="qr-status">Use the device camera or scan from a photo. Photo scanning is available even when live camera access is blocked.</p>
        </div>
        <form method="post" class="qr-manual-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="open_scan">
            <input type="hidden" name="scan_source" id="qr_scan_source" value="manual">
            <?php if ($expectedDocument): ?>
                <input type="hidden" name="expected_document_id" value="<?= (int) $expectedDocument['id'] ?>">
            <?php endif; ?>
            <label for="qr_value">Manual QR URL</label>
            <textarea name="qr_value" id="qr_value" placeholder="Paste full QR URL"></textarea>
            <button class="btn" type="submit">Open Document</button>
        </form>
    </div>
</div>

<?php if ($document): ?>
    <div class="card">
        <div class="record-header no-print">
            <div>
                <h2 class="record-heading">Scanned Document Record</h2>
                <p class="muted">Tracking No.: <strong><?= e($document['tracking_no']) ?></strong></p>
                <p class="muted record-subtitle"><?= e(document_custody_summary($document, null)) ?></p>
            </div>
            <div class="actions">
                <?php if ($canOpenFullRecord): ?>
                    <a class="btn" href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>">Open Full Record</a>
                <?php endif; ?>
                <?php if ($canDownloadAttachment): ?>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/download.php?id=<?= (int) $document['id'] ?>">Download Attachment</a>
                <?php endif; ?>
                <?php if ($canPrintQr): ?>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/print-qr.php?id=<?= (int) $document['id'] ?>">Print QR Cover Sheet</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="validation-panel">
            <div><strong>QR Status</strong><br><span class="badge success">Valid Internal PRMSU QR</span></div>
            <div><strong>Verification Code</strong><br><?= e(document_verification_code($document['qr_token'] ?? '')) ?></div>
            <div><strong>Document No.</strong><br><?= e($document['document_number'] ?? '-') ?></div>
            <div><strong>Document Name</strong><br><?= e($document['document_name'] ?? '-') ?></div>
            <!-- <div><strong>Name in Document</strong><br><?= e($document['document_person_name'] ?? '-') ?></div> -->
            <div><strong>Subject</strong><br><?= e($document['subject']) ?></div>
            <div><strong>Category</strong><br><?= e($document['category_name'] ?? '-') ?></div>
            <div>
                <strong>Source Office</strong><br><?= e($document['origin_office_name'] ?? '-') ?>
                <span class="muted office-contact"><?= e(office_contact_label($document['origin_office_trunk_line'] ?? null, $document['origin_office_local_number'] ?? null)) ?></span>
            </div>
            <div>
                <strong>Current Location</strong><br><?= e($document['current_office_name'] ?? '-') ?>
                <span class="muted office-contact"><?= e(office_contact_label($document['current_office_trunk_line'] ?? null, $document['current_office_local_number'] ?? null)) ?></span>
            </div>
            <div><strong>Status</strong><br><span class="<?= e(document_status_badge((string) $document['status'])) ?>"><?= e($document['status']) ?></span></div>
            <div><strong>Date of Creation</strong><br><?= e($document['received_date']) ?></div>
            <div><strong>Hardcopy Page Count</strong><br><?= $document['page_count'] ? (int) $document['page_count'] : '-' ?></div>
        </div>
    </div>

    <?php if ($canUpdateLocation): ?>
        <div class="card no-print">
            <h2><?= $requiresCustodyGate ? 'Receive Physical Copy' : 'Update Location' ?></h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_location">
                <?php if ($requiresCustodyGate): ?>
                    <input type="hidden" name="current_office_id" value="<?= (int) records_office_id() ?>">
                    <p class="muted">Update this document location to <?= e(office_name_for_id(records_office_id()) ?? 'Records Office') ?> before opening the full record or archiving.</p>
                <?php else: ?>
                    <label for="scan_current_office_id">Current Location</label>
                    <select name="current_office_id" id="scan_current_office_id" required>
                        <option value="">Select office</option>
                        <?php foreach ($locationOffices as $office): ?>
                            <option value="<?= (int) $office['id'] ?>" <?= (int) ($document['current_office_id'] ?? 0) === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <label for="scan_location_remarks">Remarks</label>
                <textarea name="remarks" id="scan_location_remarks" placeholder="Describe where the document moved or why the location was updated" required></textarea>
                <label for="scan_location_handler">Received / Handled By</label>
                <input type="text" name="physical_handler_name" id="scan_location_handler" maxlength="150" placeholder="Optional name of receiving or handling person">
                <button class="btn" type="submit"><?= $requiresCustodyGate ? 'Receive at Records Office' : 'Save Location' ?></button>
            </form>
        </div>
    <?php elseif ($canConfirmCustody): ?>
        <div class="card no-print">
            <h2>Confirm Physical Custody</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_custody">
                <p class="muted">The current location is already <?= e($document['current_office_name'] ?? 'Records Office') ?>. Confirm the physical copy is present before opening the full record or archiving.</p>
                <label for="scan_custody_remarks">Remarks</label>
                <textarea name="remarks" id="scan_custody_remarks" placeholder="Confirm who received or verified the physical copy" required></textarea>
                <label for="scan_custody_handler">Verified By</label>
                <input type="text" name="physical_handler_name" id="scan_custody_handler" maxlength="150" placeholder="Optional name of custody verifier">
                <button class="btn" type="submit">Confirm Custody</button>
            </form>
        </div>
    <?php elseif (!$hasQrLocationContext): ?>
        <div class="card no-print">
            <p class="muted record-subtitle">Scan the QR code or paste the full QR URL to update the document location.</p>
        </div>
    <?php endif; ?>

    <?php if ($canArchiveFromScan): ?>
        <div class="card no-print">
            <h2>Archive Document</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="archive">
                <label for="scan_archive_note">Archive Note</label>
                <textarea name="archive_note" id="scan_archive_note" placeholder="Reason or note for final archiving" required></textarea>
                <button class="btn btn-secondary" type="submit">Archive</button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/vendor/jsQR.js"></script>
<script src="<?= BASE_URL ?>/assets/js/qr-scan.js?v=<?= filemtime(__DIR__ . '/../assets/js/qr-scan.js') ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
