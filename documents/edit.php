<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$id = input_int($_GET['id'] ?? 0);
$pagePath = '/documents/edit.php?id=' . $id;
$document = require_accessible_document_or_redirect(
    $id,
    '/documents/index.php',
    'Document not found.',
    'You are not allowed to edit that document.'
);

if (!can_edit_document_record($document)) {
    set_flash('error', 'You are not allowed to edit that document.');
    redirect('/documents/view.php?id=' . $id);
}

$isDraftEdit = can_edit_document_draft($document);
$isOfficialMetadataEdit = can_edit_document_metadata() && !document_is_unreleased($document) && (string) ($document['status'] ?? '') !== 'Archived';
$isCreatorOfficialMetadataEdit = can_edit_document_creator_official_metadata($document);
$canEditDocumentNumber = $isOfficialMetadataEdit;
$canEditOriginOffice = $isOfficialMetadataEdit;
$requiresDocumentName = $isOfficialMetadataEdit || $isCreatorOfficialMetadataEdit;
$categories = load_categories();
$offices = load_offices(false);
$errors = [];

if (is_post()) {
    require_csrf($pagePath);

    $saveAction = input_string($_POST['save_action'] ?? ($isDraftEdit ? 'draft' : 'save'));
    $submitForReview = $isDraftEdit && $saveAction === 'submit';

    $documentNumber = input_string($_POST['document_number'] ?? '');
    $documentName = input_string($_POST['document_name'] ?? '');
    $documentPersonName = input_string($_POST['document_person_name'] ?? '');
    $subject = input_string($_POST['subject'] ?? '');
    $description = input_string($_POST['description'] ?? '');
    $categoryId = input_int($_POST['category_id'] ?? 0);
    $originOfficeId = $isOfficialMetadataEdit
        ? input_int($_POST['origin_office_id'] ?? 0)
        : (isset($document['origin_office_id']) && $document['origin_office_id'] !== null ? (int) $document['origin_office_id'] : null);
    $receivedDate = input_date($_POST['received_date'] ?? '');
    $pageCount = input_int($_POST['page_count'] ?? 0);

    if ($isOfficialMetadataEdit && $documentNumber !== '' && document_number_exists($documentNumber, $id)) {
        $errors[] = 'A document with this document number already exists.';
    }
    if ($subject === '') {
        $errors[] = 'Subject is required.';
    }
    if ($submitForReview && $documentName === '') {
        $errors[] = 'Document name is required before submitting for approval.';
    }
    if ($requiresDocumentName && $documentName === '') {
        $errors[] = 'Document name is required.';
    }
    if (!category_exists($categoryId)) {
        $errors[] = 'Please select an active category.';
    }
    if (($isDraftEdit || $isOfficialMetadataEdit) && ($originOfficeId === null || !office_exists($originOfficeId, false))) {
        $errors[] = 'Please select a valid source office.';
    }
    if ($isDraftEdit && current_office_id() !== null && $originOfficeId !== (int) current_office_id()) {
        $errors[] = 'Drafts must stay assigned to your office.';
    }
    if ($receivedDate === '') {
        $errors[] = 'Date of creation is required.';
    }
    if ($pageCount < 0) {
        $errors[] = 'Hardcopy page count cannot be negative.';
    }

    $hasExistingAttachment = !empty($document['attachment_path']);
    if ($submitForReview && !$hasExistingAttachment && (!isset($_FILES['attachment']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors[] = 'Attachment is required before submitting for approval.';
    }

    $upload = null;
    $fileHash = $document['file_sha256'] ?? null;

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
            run_in_transaction(static function () use (
                $id,
                $document,
                $isDraftEdit,
                $isOfficialMetadataEdit,
                $isCreatorOfficialMetadataEdit,
                $submitForReview,
                $documentNumber,
                $documentName,
                $documentPersonName,
                $subject,
                $description,
                $categoryId,
                $originOfficeId,
                $receivedDate,
                $upload,
                $fileHash,
                $pageCount
            ): void {
                $statusSql = '';
                $params = [
                    'document_name' => $documentName !== '' ? $documentName : null,
                    'document_person_name' => $documentPersonName !== '' ? $documentPersonName : null,
                    'subject' => $subject,
                    'description' => $description,
                    'category_id' => $categoryId,
                    'origin_office_id' => $originOfficeId,
                    'received_date' => $receivedDate,
                    'attachment_path' => $upload['path'] ?? $document['attachment_path'],
                    'attachment_original_name' => $upload['original_name'] ?? $document['attachment_original_name'],
                    'attachment_mime' => $upload['mime'] ?? $document['attachment_mime'],
                    'attachment_size' => $upload['size'] ?? $document['attachment_size'],
                    'file_sha256' => $fileHash,
                    'page_count' => $pageCount > 0 ? $pageCount : null,
                    'id' => $id,
                ];

                if ($isOfficialMetadataEdit) {
                    $documentNumberSql = 'document_number = :document_number,';
                    $params['document_number'] = $documentNumber;
                } else {
                    $documentNumberSql = '';
                }

                if ($isDraftEdit && (string) ($document['status'] ?? '') === 'Rejected' && !$submitForReview) {
                    $statusSql = 'status = "Draft",';
                }
                $currentOfficeSql = $isDraftEdit ? 'current_office_id = :current_office_id,' : '';
                if ($isDraftEdit) {
                    $params['current_office_id'] = $originOfficeId;
                }

                $stmt = db()->prepare(
                    'UPDATE documents
                     SET ' . $documentNumberSql . '
                         document_name = :document_name,
                         document_person_name = :document_person_name,
                         subject = :subject,
                         description = :description,
                         category_id = :category_id,
                         origin_office_id = :origin_office_id,
                         ' . $currentOfficeSql . '
                         received_date = :received_date,
                         attachment_path = :attachment_path,
                         attachment_original_name = :attachment_original_name,
                         attachment_mime = :attachment_mime,
                         attachment_size = :attachment_size,
                         file_sha256 = :file_sha256,
                         page_count = :page_count,
                         ' . $statusSql . '
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute($params);

                if ($submitForReview) {
                    perform_submit_document_for_review($id);
                }

                audit_log($isDraftEdit ? ($submitForReview ? 'edit_and_submit_document_draft' : 'edit_document_draft') : ($isCreatorOfficialMetadataEdit ? 'edit_creator_official_document' : 'edit_document'), 'document', $id, [
                    'document_number' => $isOfficialMetadataEdit ? $documentNumber : null,
                    'origin_office_id' => $originOfficeId,
                    'category_id' => $categoryId,
                    'submitted' => $submitForReview,
                ]);
            });

            set_flash('success', $submitForReview ? 'Draft submitted for issuing authority approval.' : 'Document details updated successfully.');
            redirect('/documents/view.php?id=' . $id);
        } catch (Throwable $e) {
            cleanup_uploaded_file($upload);
            $errors[] = 'Unable to update document details. Please try again.';
        }
    }
}

$pageTitle = $isDraftEdit ? 'Edit Draft' : 'Edit Official Record';
$editIntro = $isDraftEdit
    ? 'Update the draft details, replace the attachment if needed, then submit it for issuing authority approval.'
    : ($isCreatorOfficialMetadataEdit
        ? 'Update the basic details and attachment for your official document. Document number, source office, location, and stage stay locked.'
        : 'Records Officers can correct official metadata after creation. Location and completion changes are recorded separately.');
$formValues = [
    'document_number' => old('document_number', $document['document_number'] ?? ''),
    'document_name' => old('document_name', $document['document_name'] ?? ''),
    'document_person_name' => old('document_person_name', $document['document_person_name'] ?? ''),
    'subject' => old('subject', $document['subject']),
    'description' => old('description', $document['description'] ?? ''),
    'category_id' => input_int($_POST['category_id'] ?? ($document['category_id'] ?? 0)),
    'origin_office_id' => input_int($_POST['origin_office_id'] ?? ($document['origin_office_id'] ?? 0)),
    'received_date' => old('received_date', $document['received_date']),
    'page_count' => input_int($_POST['page_count'] ?? ($document['page_count'] ?? 0)),
];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="section-heading">
        <div>
            <h2><?= $isDraftEdit ? 'Edit Draft' : 'Edit Official Record' ?></h2>
            <p class="muted"><?= e($editIntro) ?></p>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <?php if ($isDraftEdit && !empty($document['review_note'])): ?>
        <div class="alert warning"><strong>Review Remarks:</strong> <?= e($document['review_note']) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <label>Tracking Number</label>
        <input type="text" value="<?= e($document['tracking_no']) ?>" disabled>

        <div class="grid">
            <?php if ($canEditDocumentNumber): ?>
                <div>
                    <label for="document_number">Document Number</label>
                    <input type="text" name="document_number" id="document_number" value="<?= e($formValues['document_number']) ?>" placeholder="Optional — leave blank if not applicable">
                </div>
            <?php else: ?>
                <div>
                    <label>Official Document Number</label>
                    <input type="text" value="<?= e(document_is_official($document) ? (string) (($document['document_number'] ?? '') ?: '-') : 'Assigned by issuing authority') ?>" disabled>
                </div>
            <?php endif; ?>
            <div>
                <label for="document_name">Document Name <?= $requiresDocumentName ? '*' : '' ?></label>
                <input type="text" name="document_name" id="document_name" value="<?= e($formValues['document_name']) ?>" <?= $requiresDocumentName ? 'required' : '' ?>>
            </div>
            <!-- <div>
                <label for="document_person_name">Name in Document</label>
                <input type="text" name="document_person_name" id="document_person_name" value="<?= e($formValues['document_person_name']) ?>">
            </div> -->
        </div>

        <label for="subject">Subject *</label>
        <input type="text" name="subject" id="subject" value="<?= e($formValues['subject']) ?>" required>

        <label for="description">Description</label>
        <textarea name="description" id="description"><?= e($formValues['description']) ?></textarea>

        <div class="grid">
            <div>
                <label for="category_id">Category *</label>
                <select name="category_id" id="category_id" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (int) $formValues['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label <?= $canEditOriginOffice ? 'for="origin_office_id"' : '' ?>>Source Office <?= $canEditOriginOffice ? '*' : '' ?></label>
                <?php if (!$canEditOriginOffice): ?>
                    <input type="text" value="<?= e($document['origin_office_name'] ?? '-') ?>" disabled>
                <?php else: ?>
                    <select name="origin_office_id" id="origin_office_id" required>
                        <option value="">Select office</option>
                        <?php foreach ($offices as $office): ?>
                            <option value="<?= (int) $office['id'] ?>" <?= (int) $formValues['origin_office_id'] === (int) $office['id'] ? 'selected' : '' ?>>
                                <?= e($office['name']) ?><?= (int) $office['is_active'] === 0 ? ' (Inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div>
                <label for="received_date">Date of Creation *</label>
                <input type="date" name="received_date" id="received_date" value="<?= e($formValues['received_date']) ?>" required>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="page_count">Hardcopy Page Count</label>
                <input type="number" name="page_count" id="page_count" min="0" value="<?= (int) $formValues['page_count'] ?>" placeholder="Optional">
            </div>
            <div>
                <label>Current Custodian</label>
                <input type="text" value="<?= e($document['current_office_name'] ?? '-') ?>" disabled>
            </div>
            <div>
                <label>Current Stage</label>
                <input type="text" value="<?= e($document['status']) ?>" disabled>
            </div>
        </div>

        <label for="attachment"><?= $isDraftEdit ? 'Replace / Add Attachment' : 'Replace Official Attachment' ?> <?= $isDraftEdit && empty($document['attachment_path']) ? '(required before submit)' : '(optional)' ?></label>
        <input type="file" name="attachment" id="attachment">

        <div class="actions">
            <?php if ($isDraftEdit): ?>
                <button class="btn btn-secondary" type="submit" name="save_action" value="draft">Save Draft</button>
                <button class="btn" type="submit" name="save_action" value="submit">Submit for Approval</button>
            <?php else: ?>
                <button class="btn" type="submit" name="save_action" value="save">Update Record</button>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $id ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
