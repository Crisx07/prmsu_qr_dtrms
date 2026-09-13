<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['issuing_authority', 'office_staff']);

$isIssuer = has_role('issuing_authority');
$pageTitle = $isIssuer ? 'Release Document' : 'Create Draft';
$pagePath = '/documents/create.php';
$errors = [];
$categories = load_categories();
$userOfficeId = current_office_id();
$userOffice = $userOfficeId !== null ? load_office_by_id($userOfficeId) : null;
$completedOnReleaseRemarks = 'Document was already signed/completed at release.';

if (is_post()) {
    require_csrf($pagePath);

    $saveAction = input_string($_POST['save_action'] ?? ($isIssuer ? 'release' : 'draft'));
    $releaseNow = $isIssuer;
    $submitForReview = !$isIssuer && $saveAction === 'submit';

    $documentNumber = input_string($_POST['document_number'] ?? '');
    $documentName = input_string($_POST['document_name'] ?? '');
    $documentPersonName = input_string($_POST['document_person_name'] ?? '');
    $subject = input_string($_POST['subject'] ?? '');
    $description = input_string($_POST['description'] ?? '');
    $categoryId = input_int($_POST['category_id'] ?? 0);
    $receivedDate = input_date($_POST['received_date'] ?? '');
    $pageCount = input_int($_POST['page_count'] ?? 0);
    $isCompletedOnRelease = $releaseNow && input_string($_POST['is_completed_on_creation'] ?? '') === '1';
    $ocrRawText = substr(input_string($_POST['ocr_raw_text'] ?? ''), 0, 200000);
    $ocrConfidenceValue = input_string($_POST['ocr_confidence'] ?? '');
    $ocrConfidence = $ocrConfidenceValue !== '' ? max(0, min(100, (float) $ocrConfidenceValue)) : null;
    $ocrStatus = $ocrRawText !== '' ? 'verified' : 'manual';

    if ($userOfficeId === null || !office_exists($userOfficeId)) {
        $errors[] = 'Your account must be assigned to an active office before creating documents.';
    }
    if ($subject === '') {
        $errors[] = 'Subject is required.';
    }
    if (!category_exists($categoryId)) {
        $errors[] = 'Please select an active document category.';
    }
    if ($receivedDate === '') {
        $errors[] = 'Date of creation is required.';
    }
    if ($pageCount < 0) {
        $errors[] = 'Hardcopy page count cannot be negative.';
    }

    if ($releaseNow || $submitForReview) {
        if ($documentName === '') {
            $errors[] = 'Document name is required before submission or release.';
        }
        if (!isset($_FILES['attachment']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = $releaseNow
                ? 'Official attachment is required before release.'
                : 'Attachment is required before submitting for approval.';
        }
    }

    if ($releaseNow && $documentNumber !== '' && document_number_exists($documentNumber)) {
        $errors[] = 'A document with this document number already exists.';
    }

    $upload = null;
    $fileHash = null;
    if (!$errors && isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $upload = upload_file($_FILES['attachment'], UPLOAD_DOCUMENTS);
            $uploadedPath = document_file_path((string) ($upload['path'] ?? ''));
            if ($uploadedPath !== null && is_file($uploadedPath)) {
                $fileHash = hash_file('sha256', $uploadedPath) ?: null;
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            $documentId = run_in_transaction(function () use (
                $userOfficeId,
                $documentNumber,
                $documentName,
                $documentPersonName,
                $subject,
                $description,
                $categoryId,
                $receivedDate,
                $upload,
                $fileHash,
                $pageCount,
                $ocrRawText,
                $ocrConfidence,
                $ocrStatus,
                $releaseNow,
                $submitForReview,
                $isCompletedOnRelease,
                $completedOnReleaseRemarks
            ): int {
                $lastDuplicate = null;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $trackingNo = generate_tracking_number($userOfficeId);

                    try {
                        $stmt = db()->prepare(
                            'INSERT INTO documents (
                                tracking_no, document_number, document_name, document_person_name, qr_token, subject, description, category_id,
                                origin_office_id, current_office_id, received_date, status,
                                attachment_path, attachment_original_name, attachment_mime, attachment_size, file_sha256, page_count,
                                qr_issued_at, validated_at, validated_by, ocr_raw_text, ocr_confidence, ocr_status,
                                ocr_reviewed_by, ocr_reviewed_at, ocr_review_note,
                                released_at, released_by, submitted_at, submitted_by, reviewed_at, reviewed_by, review_note,
                                created_by, created_at, updated_at
                             ) VALUES (
                                :tracking_no, NULL, :document_name, :document_person_name, NULL, :subject, :description, :category_id,
                                :origin_office_id, :current_office_id, :received_date, "Draft",
                                :attachment_path, :attachment_original_name, :attachment_mime, :attachment_size, :file_sha256, :page_count,
                                NULL, NULL, NULL, :ocr_raw_text, :ocr_confidence, :ocr_status,
                                :ocr_reviewed_by, :ocr_reviewed_at, :ocr_review_note,
                                NULL, NULL, NULL, NULL, NULL, NULL, NULL,
                                :created_by, NOW(), NOW()
                             )'
                        );
                        $stmt->execute([
                            'tracking_no' => $trackingNo,
                            'document_name' => $documentName !== '' ? $documentName : null,
                            'document_person_name' => $documentPersonName !== '' ? $documentPersonName : null,
                            'subject' => $subject,
                            'description' => $description,
                            'category_id' => $categoryId,
                            'origin_office_id' => $userOfficeId,
                            'current_office_id' => $userOfficeId,
                            'received_date' => $receivedDate,
                            'attachment_path' => $upload['path'] ?? null,
                            'attachment_original_name' => $upload['original_name'] ?? null,
                            'attachment_mime' => $upload['mime'] ?? null,
                            'attachment_size' => $upload['size'] ?? null,
                            'file_sha256' => $fileHash,
                            'page_count' => $pageCount > 0 ? $pageCount : null,
                            'ocr_raw_text' => $ocrRawText !== '' ? $ocrRawText : null,
                            'ocr_confidence' => $ocrConfidence,
                            'ocr_status' => $ocrStatus,
                            'ocr_reviewed_by' => $ocrRawText !== '' ? (current_user()['id'] ?? null) : null,
                            'ocr_reviewed_at' => $ocrRawText !== '' ? date('Y-m-d H:i:s') : null,
                            'ocr_review_note' => $ocrRawText !== '' ? 'Verified during draft preparation.' : null,
                            'created_by' => current_user()['id'] ?? null,
                        ]);
                    } catch (Throwable $e) {
                        if (is_duplicate_key_error($e)) {
                            $lastDuplicate = $e;
                            continue;
                        }

                        throw $e;
                    }

                    $documentId = (int) db()->lastInsertId();
                    create_document_timeline_event(
                        $documentId,
                        $releaseNow ? 'registered' : 'draft_created',
                        'Draft',
                        null,
                        $releaseNow ? 'Document registered by issuing authority for direct release.' : 'Draft document created.',
                        $userOfficeId
                    );

                    if ($submitForReview) {
                        perform_submit_document_for_review($documentId);
                    }

                    $releasedDocumentNumber = null;
                    if ($releaseNow) {
                        $releasedDocumentNumber = perform_release_document(
                            $documentId,
                            $documentNumber,
                            $isCompletedOnRelease,
                            $isCompletedOnRelease ? $completedOnReleaseRemarks : 'Released directly by issuing authority.'
                        );
                    }

                    audit_log($releaseNow ? 'create_and_release_document' : ($submitForReview ? 'create_and_submit_document' : 'create_document_draft'), 'document', $documentId, [
                        'tracking_no' => $trackingNo,
                        'document_number' => $releasedDocumentNumber,
                        'origin_office_id' => $userOfficeId,
                        'submitted' => $submitForReview,
                        'released' => $releaseNow,
                    ]);

                    return $documentId;
                }

                throw $lastDuplicate ?? new RuntimeException('Unable to generate a unique tracking number.');
            });

            if ($releaseNow) {
                set_flash('success', $isCompletedOnRelease ? 'Document released and marked completed.' : 'Document released successfully.');
            } elseif ($submitForReview) {
                set_flash('success', 'Draft submitted for issuing authority approval.');
            } else {
                set_flash('success', 'Draft saved successfully.');
            }
            redirect('/documents/view.php?id=' . $documentId);
        } catch (Throwable $e) {
            cleanup_uploaded_file($upload);
            $errors[] = 'Unable to save the document: ' . $e->getMessage();
        }
    }
}

$formValues = [
    'document_number' => old('document_number'),
    'document_name' => old('document_name'),
    'document_person_name' => old('document_person_name'),
    'subject' => old('subject'),
    'description' => old('description'),
    'category_id' => input_int($_POST['category_id'] ?? 0),
    'received_date' => old('received_date', date('Y-m-d')),
    'page_count' => input_int($_POST['page_count'] ?? 0),
    'is_completed_on_creation' => input_string($_POST['is_completed_on_creation'] ?? '') === '1',
    'ocr_raw_text' => old('ocr_raw_text'),
    'ocr_confidence' => old('ocr_confidence'),
];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="section-heading">
        <div>
            <h2><?= $isIssuer ? 'Release Document' : 'Create Draft' ?></h2>
            <p class="muted"><?= $isIssuer
                ? 'Create and officially release a document. Enter an official document number when the document has one, otherwise leave it blank. The DTRMS tracking number and QR code are always assigned.'
                : 'Prepare a document draft, upload the attachment, then submit it for issuing authority approval.' ?></p>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <?php if ($userOffice === null): ?>
        <div class="alert error">Your account has no active office assignment. Ask a System Administrator to update your account.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" data-ocr-form>
        <?= csrf_field() ?>

        <div class="grid">
            <div>
                <label>Tracking Number</label>
                <input type="text" value="Generated on save" disabled>
            </div>
            <?php if ($isIssuer): ?>
                <div>
                    <label for="document_number">Official Document Number</label>
                    <input type="text" name="document_number" id="document_number" value="<?= e($formValues['document_number']) ?>" placeholder="Optional — leave blank if not applicable" data-ocr-field="document_number">
                </div>
            <?php else: ?>
                <div>
                    <label>Official Document Number</label>
                    <input type="text" value="Assigned by issuing authority" disabled>
                </div>
            <?php endif; ?>
            <div>
                <label for="document_name">Document Name <?= $isIssuer ? '*' : '' ?></label>
                <input type="text" name="document_name" id="document_name" value="<?= e($formValues['document_name']) ?>" <?= $isIssuer ? 'required' : '' ?> data-ocr-field="document_name">
            </div>
            <!-- <div>
                <label for="document_person_name">Name in Document</label>
                <input type="text" name="document_person_name" id="document_person_name" value="<?= e($formValues['document_person_name']) ?>" data-ocr-field="document_person_name">
            </div> -->
        </div>

        <label for="subject">Subject *</label>
        <input type="text" name="subject" id="subject" value="<?= e($formValues['subject']) ?>" required data-ocr-field="subject">

        <label for="description">Description</label>
        <textarea name="description" id="description" data-ocr-field="description"><?= e($formValues['description']) ?></textarea>

        <div class="grid">
            <div>
                <label for="category_id">Category *</label>
                <select name="category_id" id="category_id" required data-ocr-field="category">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" data-category-name="<?= e(strtolower((string) $category['name'])) ?>" <?= (int) $formValues['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Source Office</label>
                <input type="text" value="<?= e($userOffice['name'] ?? 'No assigned office') ?>" disabled data-ocr-field="source_office">
            </div>
            <div>
                <label for="received_date">Date of Creation *</label>
                <input type="date" name="received_date" id="received_date" value="<?= e($formValues['received_date']) ?>" required data-ocr-field="creation_date">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="page_count">Hardcopy Page Count</label>
                <input type="number" name="page_count" id="page_count" min="0" value="<?= (int) $formValues['page_count'] ?>" placeholder="Optional">
            </div>
            <div>
                <label for="attachment"><?= $isIssuer ? 'Official Attachment *' : 'Attachment' ?></label>
                <div class="attachment-tools">
                    <input type="file" name="attachment" id="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?= $isIssuer ? 'required' : '' ?> data-ocr-file>
                    <label class="btn btn-secondary btn-sm" for="attachment_camera">Take Photo</label>
                    <input class="camera-file-input" type="file" id="attachment_camera" accept="image/*" capture="environment" data-ocr-camera-file>
                </div>
                <p class="muted"><?= $isIssuer
                    ? 'Upload the official file before release.'
                    : 'Required before submitting for approval, optional while saving a draft.' ?></p>
            </div>
        </div>

        <?php if ($isIssuer): ?>
            <label class="checkbox-option" for="is_completed_on_creation">
                <input type="checkbox" name="is_completed_on_creation" id="is_completed_on_creation" value="1" <?= $formValues['is_completed_on_creation'] ? 'checked' : '' ?> data-completed-on-creation>
                <span>
                    <strong>Document is already signed / completed</strong>
                    <small>Use this when no further processing is needed after release.</small>
                </span>
            </label>
        <?php endif; ?>

        <div class="card subtle-card ocr-panel">
            <div class="section-heading">
                <div>
                    <h3>OCR Suggestions</h3>
                    <p class="muted">Run OCR on an image or PDF, then review the suggested document details before saving.</p>
                </div>
                <button class="btn btn-secondary" type="button" data-ocr-run>Run OCR</button>
            </div>
            <div class="progress-bar" aria-hidden="true"><span data-ocr-progress></span></div>
            <p class="muted" data-ocr-status>OCR has not run for this attachment.</p>
            <textarea class="ocr-raw-output" name="ocr_raw_text" data-ocr-raw placeholder="Recognized text will appear here for verification before saving."><?= e($formValues['ocr_raw_text']) ?></textarea>
            <input type="hidden" name="ocr_confidence" value="<?= e($formValues['ocr_confidence']) ?>" data-ocr-confidence>
        </div>

        <div class="grid">
            <div>
                <label>Initial Custodian</label>
                <input type="text" value="<?= e($userOffice['name'] ?? 'Assigned office') ?>" disabled>
            </div>
            <div>
                <label>Initial Stage</label>
                <input type="text" value="<?= $isIssuer ? 'Under Action after release' : 'Draft or Submitted' ?>" disabled data-initial-stage-preview>
            </div>
        </div>

        <div class="actions">
            <?php if ($isIssuer): ?>
                <button class="btn" type="submit" name="save_action" value="release" <?= $userOffice === null ? 'disabled' : '' ?>>Release Document</button>
            <?php else: ?>
                <button class="btn btn-secondary" type="submit" name="save_action" value="draft" <?= $userOffice === null ? 'disabled' : '' ?>>Save Draft</button>
                <button class="btn" type="submit" name="save_action" value="submit" <?= $userOffice === null ? 'disabled' : '' ?>>Submit for Approval</button>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php">Cancel</a>
        </div>
    </form>
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
