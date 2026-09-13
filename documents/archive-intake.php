<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['records_officer']);

$pageTitle = 'Archive Intake';
$pagePath = '/documents/archive-intake.php';

$errors = [];
$pendingKey = 'archive_intake_pending';
$pending = $_SESSION[$pendingKey] ?? null;
$showReview = is_array($pending);

$categories = load_archive_intake_categories();
$recordOfficeId = current_office_id() ?? records_office_id();
$sourceOffices = load_offices(false);
$documentCreators = [];

try {
    $stmt = db()->query(
        'SELECT id, full_name
         FROM users
         WHERE is_active = 1
         ORDER BY full_name ASC'
    );
    $documentCreators = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Unable to load document creators.';
}

/**
 * Validate a scanned archive file without treating it as a normal DTRMS
 * validation/QR document.
 */
function validate_archive_intake_file(array $file): array
{
    $errors = [];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['Scanned PDF/image attachment is required.'];
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['The scanned file could not be uploaded. Please try again.'];
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = 'Archive intake only accepts scanned PDF, JPG, JPEG, or PNG files.';
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $errors[] = 'The uploaded file could not be verified.';
        return $errors;
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        $errors[] = 'The uploaded file is empty.';
        return $errors;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);

    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    if (!isset($allowedMimes[$extension]) || !in_array($mime, $allowedMimes[$extension], true)) {
        $errors[] = 'The uploaded file type does not match its file extension.';
    }

    if ($extension === 'pdf') {
        $handle = @fopen($tmpPath, 'rb');
        $header = $handle !== false ? fread($handle, 5) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($header !== '%PDF-') {
            $errors[] = 'The PDF file could not be verified as a readable PDF.';
        }
    } else {
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            $errors[] = 'The image file could not be read. Please upload a valid scan.';
        }
    }

    return $errors;
}

/**
 * Store a pending archive review in the session. The file is already in the
 * application's upload storage, but it is not entered into documents until
 * the Records Officer confirms the review checklist.
 */
function set_pending_archive_intake(array $data): void
{
    $_SESSION['archive_intake_pending'] = $data;
}

function clear_pending_archive_intake(bool $deleteFile = true): void
{
    $pending = $_SESSION['archive_intake_pending'] ?? null;

    if ($deleteFile && is_array($pending)) {
        cleanup_uploaded_file([
            'path' => $pending['upload']['path'] ?? null,
        ]);
    }

    unset($_SESSION['archive_intake_pending']);
}

/**
 * Final database insertion for a reviewed archive-intake record.
 *
 * Important: Archive Intake does NOT set qr_token/qr_issued_at or
 * validated_by/validated_at. Those fields belong to the normal DTRMS
 * tracking/validation workflow.
 */
function archive_reviewed_document(array $pending, int $recordOfficeId): int
{
    $trackingNo = (string) ($pending['tracking_no'] ?? '');
    $documentNumber = $pending['document_number'] !== '' ? (string) $pending['document_number'] : null;
    $documentName = (string) $pending['document_name'];
    $documentPersonName = (string) ($pending['document_person_name'] ?? '');
    $documentCreatorId = (int) $pending['document_creator_id'];
    $sourceOfficeId = (int) $pending['source_office_id'];
    $subject = (string) $pending['subject'];
    $description = (string) $pending['description'];
    $categoryId = (int) $pending['category_id'];
    $receivedDate = (string) $pending['received_date'];
    $pageCount = (int) $pending['page_count'];
    $ocrRawText = (string) ($pending['ocr_raw_text'] ?? '');
    $ocrConfidence = $pending['ocr_confidence'] !== null && $pending['ocr_confidence'] !== ''
        ? (float) $pending['ocr_confidence']
        : null;
    $ocrStatus = $ocrRawText !== '' ? 'verified' : 'manual';
    $archiveNote = (string) ($pending['archive_note'] ?? '');
    $upload = $pending['upload'];
    $fileHash = (string) ($pending['file_sha256'] ?? '');
    $currentUserId = current_user()['id'] ?? null;

    if (!is_array($upload) || empty($upload['path'])) {
        throw new RuntimeException('The pending scanned file is no longer available. Please start the archive intake again.');
    }

    $uploadedPath = document_file_path((string) $upload['path']);
    if ($uploadedPath === null || !is_file($uploadedPath)) {
        throw new RuntimeException('The pending scanned file could not be found. Please start the archive intake again.');
    }

    $currentHash = hash_file('sha256', $uploadedPath) ?: '';
    if ($fileHash === '' || !hash_equals($fileHash, $currentHash)) {
        throw new RuntimeException('The scanned file changed or failed its integrity check. Please start the archive intake again.');
    }

    return run_in_transaction(
        function () use (
            $trackingNo,
            $documentNumber,
            $documentName,
            $documentPersonName,
            $documentCreatorId,
            $sourceOfficeId,
            $subject,
            $description,
            $categoryId,
            $recordOfficeId,
            $receivedDate,
            $upload,
            $currentHash,
            $pageCount,
            $ocrRawText,
            $ocrConfidence,
            $ocrStatus,
            $archiveNote,
            $currentUserId
        ): int {
            $trackingNoWasSupplied = $trackingNo !== '';
            $lastDuplicate = null;
            $maxAttempts = $trackingNoWasSupplied ? 1 : 5;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $documentTrackingNo = $trackingNoWasSupplied
                    ? $trackingNo
                    : generate_tracking_number($recordOfficeId);

                try {
                    $stmt = db()->prepare(
                        'INSERT INTO documents (
                            tracking_no,
                            document_number,
                            document_name,
                            created_by,
                            document_person_name,
                            qr_token,
                            subject,
                            description,
                            category_id,
                            origin_office_id,
                            current_office_id,
                            received_date,
                            status,
                            attachment_path,
                            attachment_original_name,
                            attachment_mime,
                            attachment_size,
                            file_sha256,
                            page_count,
                            qr_issued_at,
                            validated_at,
                            validated_by,
                            ocr_raw_text,
                            ocr_confidence,
                            ocr_status,
                            ocr_reviewed_by,
                            ocr_reviewed_at,
                            ocr_review_note,
                            released_at,
                            released_by,
                            archived_at,
                            archived_by,
                            archive_note,
                            created_at,
                            updated_at
                        ) VALUES (
                            :tracking_no,
                            :document_number,
                            :document_name,
                            :created_by,
                            :document_person_name,
                            NULL,
                            :subject,
                            :description,
                            :category_id,
                            :origin_office_id,
                            :current_office_id,
                            :received_date,
                            "Archived",
                            :attachment_path,
                            :attachment_original_name,
                            :attachment_mime,
                            :attachment_size,
                            :file_sha256,
                            :page_count,
                            NULL,
                            NULL,
                            NULL,
                            :ocr_raw_text,
                            :ocr_confidence,
                            :ocr_status,
                            :ocr_reviewed_by,
                            :ocr_reviewed_at,
                            :ocr_review_note,
                            NULL,
                            NULL,
                            NOW(),
                            :archived_by,
                            :archive_note,
                            NOW(),
                            NOW()
                        )'
                    );

                    $stmt->execute([
                        'tracking_no' => $documentTrackingNo,
                        'document_number' => $documentNumber,
                        'document_name' => $documentName,
                        'created_by' => $documentCreatorId,
                        'document_person_name' => $documentPersonName !== '' ? $documentPersonName : null,
                        'subject' => $subject,
                        'description' => $description,
                        'category_id' => $categoryId,
                        'origin_office_id' => $sourceOfficeId,
                        'current_office_id' => $recordOfficeId,
                        'received_date' => $receivedDate,
                        'attachment_path' => $upload['path'] ?? null,
                        'attachment_original_name' => $upload['original_name'] ?? null,
                        'attachment_mime' => $upload['mime'] ?? null,
                        'attachment_size' => $upload['size'] ?? null,
                        'file_sha256' => $currentHash,
                        'page_count' => $pageCount > 0 ? $pageCount : null,
                        'ocr_raw_text' => $ocrRawText !== '' ? $ocrRawText : null,
                        'ocr_confidence' => $ocrConfidence,
                        'ocr_status' => $ocrStatus,
                        'ocr_reviewed_by' => $ocrRawText !== '' ? $currentUserId : null,
                        'ocr_reviewed_at' => $ocrRawText !== '' ? date('Y-m-d H:i:s') : null,
                        'ocr_review_note' => $ocrRawText !== '' ? 'Reviewed during Archive Intake Review.' : null,
                        'archived_by' => $currentUserId,
                        'archive_note' => $archiveNote,
                    ]);
                } catch (Throwable $e) {
                    if (!$trackingNoWasSupplied && is_duplicate_key_error($e)) {
                        $lastDuplicate = $e;
                        continue;
                    }
                    throw $e;
                }

                $documentId = (int) db()->lastInsertId();

                create_document_timeline_event(
                    $documentId,
                    'registered',
                    'Archived',
                    null,
                    'Existing pre-DTRMS record registered through Archive Intake Review.',
                    $recordOfficeId
                );

                create_document_timeline_event(
                    $documentId,
                    'archived',
                    'Archived',
                    null,
                    $archiveNote,
                    $recordOfficeId
                );

                log_document_scan_event(
                    $documentId,
                    null,
                    'archive',
                    'valid',
                    'Scanned file reviewed and accepted through Archive Intake Review.'
                );

                audit_log(
                    'archive_intake_document',
                    'document',
                    $documentId,
                    [
                        'tracking_no' => $documentTrackingNo,
                        'document_number' => $documentNumber,
                        'document_name' => $documentName,
                        'document_person_name' => $documentPersonName,
                        'document_creator_id' => $documentCreatorId,
                        'source_office_id' => $sourceOfficeId,
                        'category_id' => $categoryId,
                        'ocr_status' => $ocrStatus,
                        'file_sha256' => $currentHash,
                        'workflow' => 'archive_intake_review',
                    ]
                );

                return $documentId;
            }

            throw $lastDuplicate ?? new RuntimeException('Unable to generate a unique tracking number.');
        }
    );
}

if (is_post()) {
    require_csrf($pagePath);
    $action = input_string($_POST['action'] ?? 'review');

    if ($action === 'cancel_review') {
        clear_pending_archive_intake(true);
        redirect($pagePath);
    }

    if ($action === 'confirm_archive') {
        if (!is_array($_SESSION[$pendingKey] ?? null)) {
            $errors[] = 'The archive review has expired. Please enter the document again.';
            $showReview = false;
        } else {
            $pending = $_SESSION[$pendingKey];

            $confirmations = [
                !empty($_POST['file_readable']),
                !empty($_POST['document_complete']),
                !empty($_POST['metadata_checked']),
                !empty($_POST['ocr_checked']),
            ];

            if (in_array(false, $confirmations, true)) {
                $errors[] = 'Please confirm all Archive Intake Review checks before archiving the document.';
                $showReview = true;
            } else {
                try {
                    $documentId = archive_reviewed_document($pending, $recordOfficeId);
                    unset($_SESSION[$pendingKey]);
                    set_flash('success', 'Existing document reviewed and archived successfully.');
                    redirect('/documents/view.php?id=' . $documentId);
                } catch (Throwable $e) {
                    $errors[] = 'Unable to archive the document: ' . $e->getMessage();
                    $showReview = true;
                }
            }
        }
    } elseif ($action === 'review') {
        $trackingNo = substr(input_string($_POST['tracking_no'] ?? ''), 0, 60);
        $documentNumber = substr(input_string($_POST['document_number'] ?? ''), 0, 80);
        $documentName = substr(input_string($_POST['document_name'] ?? ''), 0, 180);
        $documentPersonName = substr(input_string($_POST['document_person_name'] ?? ''), 0, 180);
        $documentCreatorId = input_int($_POST['document_creator_id'] ?? 0);
        $sourceOfficeId = input_int($_POST['source_office_id'] ?? 0);
        $subject = substr(input_string($_POST['subject'] ?? ''), 0, 255);
        $description = input_string($_POST['description'] ?? '');
        $categoryId = input_int($_POST['category_id'] ?? 0);
        $receivedDate = input_date($_POST['received_date'] ?? '');
        $pageCount = input_int($_POST['page_count'] ?? 0);
        $archiveNote = input_string($_POST['archive_note'] ?? '');
        $ocrRawText = substr(input_string($_POST['ocr_raw_text'] ?? ''), 0, 200000);
        $ocrConfidenceValue = input_string($_POST['ocr_confidence'] ?? '');
        $ocrConfidence = $ocrConfidenceValue !== '' ? max(0, min(100, (float) $ocrConfidenceValue)) : null;

        if ($documentNumber !== '' && document_number_exists($documentNumber)) {
            $errors[] = 'A document with this document number already exists.';
        }
        if ($trackingNo !== '') {
            $stmt = db()->prepare('SELECT COUNT(*) FROM documents WHERE tracking_no = :tracking_no');
            $stmt->execute(['tracking_no' => $trackingNo]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = 'A document with this tracking number already exists.';
            }
        }
        if ($documentName === '') $errors[] = 'Document name is required.';
        if ($sourceOfficeId <= 0 || !load_office_by_id($sourceOfficeId)) $errors[] = 'Please select the source office that owns the archived document.';
        if ($subject === '') $errors[] = 'Subject is required.';
        if ($description === '') $errors[] = 'Description is required.';
        if (!archive_intake_category_exists($categoryId)) $errors[] = 'Please select Travel Order, Office Order, or Memorandum.';
        if ($receivedDate === '') $errors[] = 'Date of creation is required.';
        if ($pageCount <= 0) $errors[] = 'Page count must be at least 1 so the Records Officer can confirm the scan is complete.';

        $creator = null;
        if ($documentCreatorId <= 0) {
            $errors[] = 'Please select the Document Creator.';
        } else {
            $stmt = db()->prepare('SELECT id, full_name FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $documentCreatorId]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$creator) $errors[] = 'The selected Document Creator is invalid.';
        }

        $fileErrors = validate_archive_intake_file($_FILES['attachment'] ?? []);
        $errors = array_merge($errors, $fileErrors);

        if (!$errors) {
            try {
                $upload = upload_file($_FILES['attachment'], UPLOAD_DOCUMENTS);
                $uploadedPath = document_file_path((string) ($upload['path'] ?? ''));
                if ($uploadedPath === null || !is_file($uploadedPath)) {
                    throw new RuntimeException('The uploaded file could not be stored safely.');
                }

                $fileHash = hash_file('sha256', $uploadedPath) ?: '';
                if ($fileHash === '') {
                    cleanup_uploaded_file($upload);
                    throw new RuntimeException('Unable to calculate the SHA-256 integrity hash for the scanned file.');
                }

                if ($archiveNote === '') {
                    $archiveNote = 'Archived through Records Office archive intake.';
                }

                set_pending_archive_intake([
                    'tracking_no' => $trackingNo,
                    'document_number' => $documentNumber,
                    'document_name' => $documentName,
                    'document_person_name' => $documentPersonName,
                    'document_creator_id' => $documentCreatorId,
                    'document_creator_name' => (string) $creator['full_name'],
                    'source_office_id' => $sourceOfficeId,
                    'source_office_name' => (string) (load_office_by_id($sourceOfficeId)['name'] ?? '-'),
                    'subject' => $subject,
                    'description' => $description,
                    'category_id' => $categoryId,
                    'category_name' => (string) (load_category_by_id($categoryId)['name'] ?? '-'),
                    'received_date' => $receivedDate,
                    'page_count' => $pageCount,
                    'archive_note' => $archiveNote,
                    'ocr_raw_text' => $ocrRawText,
                    'ocr_confidence' => $ocrConfidence,
                    'upload' => $upload,
                    'file_sha256' => $fileHash,
                ]);

                $pending = $_SESSION[$pendingKey];
                $showReview = true;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$formValues = [
    'tracking_no' => old('tracking_no'),
    'document_number' => old('document_number'),
    'document_name' => old('document_name'),
    'document_person_name' => old('document_person_name'),
    'document_creator_id' => input_int($_POST['document_creator_id'] ?? 0),
    'source_office_id' => input_int($_POST['source_office_id'] ?? 0),
    'subject' => old('subject'),
    'description' => old('description'),
    'category_id' => input_int($_POST['category_id'] ?? 0),
    'received_date' => old('received_date', date('Y-m-d')),
    'page_count' => input_int($_POST['page_count'] ?? 0),
    'archive_note' => old('archive_note'),
    'ocr_raw_text' => old('ocr_raw_text'),
    'ocr_confidence' => old('ocr_confidence'),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="section-heading">
        <div>
            <h2><?= $showReview ? 'Archive Intake Review' : 'Archive Intake' ?></h2>
            <p class="muted">
                <?= $showReview
                    ? 'Review the scanned file and metadata before placing this existing pre-DTRMS record into the digital archive.'
                    : 'Register existing hard-copy records that were created before the DTRMS and prepare their scanned copies for digital archiving.' ?>
            </p>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <?php if ($showReview && is_array($pending)): ?>
        <div class="card subtle-card">
            <div class="section-heading">
                <div>
                    <h3>Digital File</h3>
                    <p class="muted">This is a basic archive-intake review, not the normal DTRMS QR/document validation.</p>
                </div>
            </div>

            <div class="grid">
                <div><strong>File name</strong><p><?= e((string) ($pending['upload']['original_name'] ?? '-')) ?></p></div>
                <div><strong>File type</strong><p><?= e((string) ($pending['upload']['mime'] ?? '-')) ?></p></div>
                <div><strong>File size</strong><p><?= e(format_bytes((int) ($pending['upload']['size'] ?? 0))) ?></p></div>
                <div><strong>Pages recorded</strong><p><?= (int) $pending['page_count'] ?></p></div>
            </div>

            <div class="alert success">
                File format and basic readability checks passed. SHA-256 integrity hash was generated for the stored scan.
            </div>

            <div class="card subtle-card">
                <h3>Document Information</h3>
                <div class="grid">
                    <div><strong>Document name</strong><p><?= e($pending['document_name']) ?></p></div>
                    <div><strong>Document number</strong><p><?= e($pending['document_number'] !== '' ? $pending['document_number'] : '-') ?></p></div>
                    <div><strong>Category</strong><p><?= e($pending['category_name']) ?></p></div>
                    <div><strong>Source / Owner Office</strong><p><?= e($pending['source_office_name']) ?></p></div>
                    <div><strong>Document Creator</strong><p><?= e($pending['document_creator_name']) ?></p></div>
                    <div><strong>Date of Creation</strong><p><?= e($pending['received_date']) ?></p></div>
                    <div><strong>Subject</strong><p><?= e($pending['subject']) ?></p></div>
                    <div><strong>Description</strong><p><?= nl2br(e($pending['description'])) ?></p></div>
                </div>
            </div>

            <div class="card subtle-card">
                <h3>OCR Review</h3>
                <?php if ((string) $pending['ocr_raw_text'] !== ''): ?>
                    <p class="muted">OCR text is available and must be checked against the scanned document.</p>
                    <textarea class="ocr-raw-output" readonly><?= e($pending['ocr_raw_text']) ?></textarea>
                    <p class="muted">OCR confidence: <?= $pending['ocr_confidence'] !== null && $pending['ocr_confidence'] !== '' ? e((string) $pending['ocr_confidence']) . '%' : 'Not provided' ?></p>
                <?php else: ?>
                    <p class="muted">No OCR text was submitted. The metadata must be checked directly against the scanned physical record.</p>
                <?php endif; ?>
            </div>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_archive">

                <div class="card subtle-card">
                    <h3>Archive Intake Review Checklist</h3>
                    <p class="muted">Confirm these checks before the record is permanently added to the digital archive.</p>
                    <label><input type="checkbox" name="file_readable" value="1" required> The digital file opens and is readable.</label>
                    <label><input type="checkbox" name="document_complete" value="1" required> The scanned document is complete and no pages are missing.</label>
                    <label><input type="checkbox" name="metadata_checked" value="1" required> The document name, number, category, source office, creator, date, subject, description, and page count match the physical record.</label>
                    <label><input type="checkbox" name="ocr_checked" value="1" required> The OCR information is correct, or I confirmed that the metadata was checked manually when OCR was not used.</label>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Archive Document</button>
                </div>
            </form>

            <form method="post" class="actions no-print">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_review">
                <button class="btn btn-secondary" type="submit">Cancel Review</button>
            </form>
        </div>

    <?php else: ?>
        <?php if (!$categories): ?>
            <div class="alert error">Travel Order, Office Order, and Memorandum categories are not available. Ask an administrator to activate them.</div>
        <?php endif; ?>

        <?php if (!$documentCreators): ?>
            <div class="alert error">No active document creators are available. Ask an administrator to create or activate user accounts.</div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" data-ocr-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="review">

            <div class="grid">
                <div>
                    <label for="tracking_no">Document Tracking Number</label>
                    <input type="text" name="tracking_no" id="tracking_no" value="<?= e($formValues['tracking_no']) ?>" placeholder="Generated on archive if blank" data-ocr-field="tracking_no">
                </div>
                <div>
                    <label for="document_number">Document Number</label>
                    <input type="text" name="document_number" id="document_number" value="<?= e($formValues['document_number']) ?>" placeholder="Optional" data-ocr-field="document_number">
                </div>
                <div>
                    <label for="document_name">Document Name *</label>
                    <input type="text" name="document_name" id="document_name" value="<?= e($formValues['document_name']) ?>" maxlength="180" required>
                </div>
                <!-- <div>
                    <label for="document_person_name">Name in Document</label>
                    <input type="text" name="document_person_name" id="document_person_name" value="<?= e($formValues['document_person_name']) ?>" maxlength="180" data-ocr-field="person_name">
                </div> -->
            </div><br>

            <div class="grid">
                <div>
                    <label for="source_office_id">Source Office *</label>
                    <select name="source_office_id" id="source_office_id" required>
                        <option value="">Select source office</option>
                        <?php foreach ($sourceOffices as $office): ?>
                            <option value="<?= (int) $office['id'] ?>" <?= (int) $formValues['source_office_id'] === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="category_id">Document Category *</label>
                    <select name="category_id" id="category_id" required data-ocr-field="category">
                        <option value="">Select category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= (int) $formValues['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="document_creator_id">Document Creator *</label>
                    <select name="document_creator_id" id="document_creator_id" required>
                        <option value="">Select document creator</option>
                        <?php foreach ($documentCreators as $creator): ?>
                            <option value="<?= (int) $creator['id'] ?>" <?= (int) $formValues['document_creator_id'] === (int) $creator['id'] ? 'selected' : '' ?>><?= e($creator['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="received_date">Date of Creation *</label>
                    <input type="date" name="received_date" id="received_date" value="<?= e($formValues['received_date']) ?>" required data-ocr-field="creation_date">
                </div>
                <div>
                    <label for="page_count">Page Count *</label>
                    <input type="number" name="page_count" id="page_count" min="1" value="<?= (int) $formValues['page_count'] ?>" required>
                </div>
            </div><br>

            <div class="grid">
                <div>
                    <label for="subject">Subject *</label>
                    <input type="text" name="subject" id="subject" value="<?= e($formValues['subject']) ?>" required data-ocr-field="subject">
                </div>
                <div>
                    <label for="description">Description *</label>
                    <textarea name="description" id="description" required data-ocr-field="description"><?= e($formValues['description']) ?></textarea>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="attachment">Scanned PDF/Image *</label>
                    <div class="attachment-tools">
                        <input type="file" name="attachment" id="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required data-ocr-file>
                        <label class="btn btn-secondary btn-sm" for="attachment_camera">Take Photo</label>
                        <input class="camera-file-input" type="file" id="attachment_camera" accept="image/*" capture="environment" data-ocr-camera-file>
                    </div>
                    <p class="muted">Only PDF, JPG, JPEG, and PNG files are accepted.</p>
                </div>
                <div>
                    <label for="archive_note">Archive / Logbook Note</label>
                    <textarea name="archive_note" id="archive_note" placeholder="Optional note for the digital logbook"><?= e($formValues['archive_note']) ?></textarea>
                </div>
            </div>

            <div class="card subtle-card ocr-panel">
                <div class="section-heading">
                    <div>
                        <h3>OCR Suggestions</h3>
                        <p class="muted">Run OCR on the scan, then check the extracted information before moving to Archive Intake Review.</p>
                    </div>
                    <button class="btn btn-secondary" type="button" data-ocr-run>Run OCR</button>
                </div>
                <div class="progress-bar" aria-hidden="true"><span data-ocr-progress></span></div>
                <p class="muted" data-ocr-status>OCR has not run for this attachment.</p>
                <textarea class="ocr-raw-output" name="ocr_raw_text" data-ocr-raw placeholder="Recognized text will appear here for verification."><?= e($formValues['ocr_raw_text']) ?></textarea>
                <input type="hidden" name="ocr_confidence" value="<?= e($formValues['ocr_confidence']) ?>" data-ocr-confidence>
            </div>

            <div class="actions">
                <button class="btn" type="submit" <?= (!$categories || !$documentCreators) ? 'disabled' : '' ?>>Review Archive</button>
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/archive.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script>
if (window.pdfjsLib) {
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
}
</script>
<script src="<?= BASE_URL ?>/assets/js/document-ocr.js?v=<?= filemtime(__DIR__ . '/../assets/js/document-ocr.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
