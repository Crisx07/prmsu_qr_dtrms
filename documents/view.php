<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$id = input_int($_GET['id'] ?? 0);
$pagePath = '/documents/view.php?id=' . $id;
$document = require_accessible_document_or_redirect(
    $id,
    '/documents/index.php',
    'Document not found.',
    'You are not allowed to view that document.'
);

if (!document_records_officer_custody_access_allowed($document)) {
    set_flash('error', records_officer_custody_gate_message());
    redirect('/documents/scan.php?id=' . (int) $document['id']);
}

$hasAttachment = !empty($document['attachment_path']);
$canOpenAttachment = $hasAttachment && can_access_document_attachment($document);
$canViewAttachment = $canOpenAttachment && document_attachment_is_browser_viewable($document);
$attachmentName = (string) (($document['attachment_original_name'] ?? '') ?: 'attachment');

if (is_post()) {
    require_csrf($pagePath);

    $action = input_string($_POST['action'] ?? '');

    if ($action === 'update_location') {
        set_flash('error', 'Scan the QR code or paste the full QR URL to update the document location.');
        redirect($pagePath);
    }

    if ($action === 'complete') {
        if (!document_can_complete($document, null)) {
            set_flash('error', 'This document cannot be marked completed right now.');
            redirect($pagePath);
        }

        $remarks = input_string($_POST['remarks'] ?? '');
        if ($remarks === '') {
            set_flash('error', 'Please provide completion notes before marking this document completed.');
            redirect($pagePath);
        }

        perform_complete_document($id, $remarks);
        set_flash('success', 'Document processing marked completed.');
        redirect($pagePath);
    }

    if ($action === 'compare_file_hash') {
        if (!document_is_official($document)) {
            set_flash('error', 'Only officially released documents can be downloaded or file-validated.');
            redirect($pagePath);
        }
        if (empty($document['file_sha256'])) {
            set_flash('error', 'No official digital file hash is stored for this document.');
            redirect($pagePath);
        }
        if (!isset($_FILES['compare_file']) || ($_FILES['compare_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            set_flash('error', 'Please choose a file to validate.');
            redirect($pagePath);
        }
        if (($_FILES['compare_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            set_flash('error', 'The validation file could not be read.');
            redirect($pagePath);
        }

        $tmpName = (string) ($_FILES['compare_file']['tmp_name'] ?? '');
        $submittedHash = $tmpName !== '' && is_uploaded_file($tmpName) ? hash_file('sha256', $tmpName) : '';
        $matches = is_string($submittedHash) && hash_equals((string) $document['file_sha256'], $submittedHash);
        $remarks = $matches
            ? 'Uploaded validation file matches the official SHA-256 hash.'
            : 'Uploaded validation file does not match the official SHA-256 hash.';

        record_file_validation_result($id, $matches, $remarks);
        set_flash($matches ? 'success' : 'error', $matches ? 'File validated successfully.' : 'File validation failed.');
        redirect($pagePath);
    }

    if ($action === 'archive') {
        if (!document_can_archive($document, null)) {
            set_flash('error', 'This document must be archived by its assigned archiving office.');
            redirect($pagePath);
        }

        $archiveNote = input_string($_POST['archive_note'] ?? '');
        if ($archiveNote === '') {
            set_flash('error', 'Please provide an archive note before archiving this document.');
            redirect($pagePath);
        }

        perform_archive_document($id, $archiveNote);
        set_flash('success', 'Document archived successfully.');
        redirect($pagePath);
    }

    if ($action === 'restore_archive') {
        if (!document_can_restore_archive($document)) {
            $message = (string) ($document['status'] ?? '') === 'Archived'
                ? 'This archived document must be restored by its assigned archiving office.'
                : 'Only archived documents can be restored.';

            set_flash('error', $message);
            redirect($pagePath);
        }

        $restoreRemarks = input_string($_POST['restore_remarks'] ?? '');
        if ($restoreRemarks === '') {
            set_flash('error', 'Please provide restore remarks before returning this document to completed status.');
            redirect($pagePath);
        }

        perform_restore_archived_document($id, $restoreRemarks);
        set_flash('success', 'Archived document restored to completed status.');
        redirect($pagePath);
    }

    if ($action === 'ocr_review') {
        if (!can_review_ocr_results()) {
            set_flash('error', 'Only Records Officers can review OCR results.');
            redirect($pagePath);
        }

        $reviewStatus = input_string($_POST['ocr_status'] ?? '');
        $remarks = input_string($_POST['remarks'] ?? '');
        if (!in_array($reviewStatus, ['verified', 'rejected'], true)) {
            set_flash('error', 'Please choose a valid OCR review decision.');
            redirect($pagePath);
        }
        if ($reviewStatus === 'rejected' && $remarks === '') {
            set_flash('error', 'Please add remarks when rejecting OCR results.');
            redirect($pagePath);
        }

        perform_ocr_review($id, $reviewStatus, $remarks);
        set_flash('success', 'OCR review saved.');
        redirect($pagePath);
    }
}

$document = fetch_document($id) ?? $document;
$canViewPrivateLogs = can_view_document_private_logs($document);
$timelineEvents = $canViewPrivateLogs ? document_timeline_events($id, 200) : [];
$scanLogs = $canViewPrivateLogs ? document_scan_logs($id, 50) : [];
$canEdit = can_edit_document_record($document);
$canComplete = document_can_complete($document, null);
$canPrintQr = document_can_print_qr($document);
$canReviewRelease = can_review_document_release($document);
$pageTitle = 'View Document';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="record-header no-print">
        <div>
            <h2 class="record-heading">Document Record</h2>
            <p class="muted">Tracking No.: <strong><?= e($document['tracking_no']) ?></strong></p>
            <p class="muted record-subtitle"><?= e(document_custody_summary($document, null)) ?></p>
        </div>
        <div class="actions">
            <button class="btn btn-secondary" type="button" onclick="window.print()">Print</button>
            <?php if ($canPrintQr): ?>
                <a class="btn" href="<?= BASE_URL ?>/documents/print-qr.php?id=<?= (int) $document['id'] ?>">Print QR Cover Sheet</a>
            <?php endif; ?>
            <?php if ($canReviewRelease): ?>
                <a class="btn" href="<?= BASE_URL ?>/documents/review.php">Review Submission</a>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/edit.php?id=<?= (int) $document['id'] ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid">
        <div><strong>Document Number</strong><br><?= e($document['document_number'] ?? '-') ?></div>
        <div><strong>Document Name</strong><br><?= e($document['document_name'] ?? '-') ?></div>
        <!-- <div><strong>Name in Document</strong><br><?= e( ($document['document_person_name'] ?? '-')) ?></div> -->
        <div><strong>Subject</strong><br><?= e($document['subject']) ?></div>
        <div><strong>Category</strong><br><?= e($document['category_name'] ?? '-') ?></div>
        <div><strong>Status</strong><br><span class="<?= e(document_status_badge((string) $document['status'])) ?>"><?= e($document['status']) ?></span></div>
        <div>
            <strong>Source Office</strong><br><?= e($document['origin_office_name'] ?? '-') ?>
            <span class="muted office-contact"><?= e(office_contact_label($document['origin_office_trunk_line'] ?? null, $document['origin_office_local_number'] ?? null)) ?></span>
        </div>
        <div>
            <strong>Current Location</strong><br><?= e($document['current_office_name'] ?? '-') ?>
            <span class="muted office-contact"><?= e(office_contact_label($document['current_office_trunk_line'] ?? null, $document['current_office_local_number'] ?? null)) ?></span>
        </div>
        <div><strong>Date of Creation</strong><br><?= e($document['received_date']) ?></div>
        <div><strong>QR Verification Code</strong><br><?= e(document_verification_code($document['qr_token'] ?? '')) ?></div>
        <div><strong>Hardcopy Page Count</strong><br><?= $document['page_count'] ? (int) $document['page_count'] : '-' ?></div>
        <div><strong>OCR Status</strong><br><span class="<?= e(ocr_status_badge($document['ocr_status'] ?? 'manual')) ?>"><?= e(ocr_status_label($document['ocr_status'] ?? 'manual')) ?></span></div>
        <!-- <div><strong>OCR Confidence</strong><br><?= $document['ocr_confidence'] !== null ? e((string) $document['ocr_confidence']) . '%' : '-' ?></div> -->
        <div><strong>Created By</strong><br><?= e($document['created_by_name'] ?? '-') ?></div>
        <div><strong>Date Created</strong><br><?= e(format_datetime($document['created_at'])) ?></div>
        <div><strong>Date Submitted</strong><br><?= !empty($document['submitted_at']) ? e(format_datetime($document['submitted_at'])) : '-' ?></div>
        <div><strong>Released By</strong><br><?= e($document['released_by_name'] ?? '-') ?></div>
        <div><strong>Date Reviewed</strong><br><?= !empty($document['reviewed_at']) ? e(format_datetime($document['reviewed_at'])) : '-' ?></div>
        <div><strong>Date Released</strong><br><?= !empty($document['released_at']) ? e(format_datetime($document['released_at'])) : '-' ?></div>
    </div>

    <div class="content-section">
        <strong>Description</strong>
        <div class="document-description">
            <?= render_collapsible_text($document['description'] ?? '', 220, true) ?>
        </div>
    </div>

    <div class="card subtle-card content-section">
        <h3 class="content-section-heading">Next Action</h3>
        <p class="muted record-subtitle"><?= e(document_next_action_hint($document, null)) ?></p>
    </div>

    <?php if (!empty($document['review_note'])): ?>
        <div class="card subtle-card content-section">
            <h3 class="content-section-heading">Review Remarks</h3>
            <p><?= nl2br(e($document['review_note'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($hasAttachment): ?>
        <p>
            <strong><?= document_is_official($document) ? 'Official Attachment' : 'Attachment' ?>:</strong>
            <?= e($attachmentName) ?>
            <?php if ($canOpenAttachment): ?>
                <?php if ($canViewAttachment): ?>
                    <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/documents/download.php?id=<?= (int) $document['id'] ?>&mode=view" target="_blank" rel="noopener">View</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/documents/download.php?id=<?= (int) $document['id'] ?>">Download</a>
            <?php else: ?>
                <span class="muted">You do not have permission to open this attachment.</span>
            <?php endif; ?>
        </p>
        <!-- <?php if (document_is_official($document)): ?>
            <p><strong>Digital File SHA-256:</strong> <span class="break-word"><?= e($document['file_sha256'] ?? '-') ?></span></p>
        <?php endif; ?> -->
    <?php endif; ?>

    <?php if (!empty($document['ocr_raw_text'])): ?>
        <details class="no-print content-section">
            <summary><strong>OCR Extracted Text</strong></summary>
            <pre class="log-details pre-wrap"><?= e($document['ocr_raw_text']) ?></pre>
            <?php if (!empty($document['ocr_reviewed_at'])): ?>
                <p class="muted">Reviewed by <?= e($document['ocr_reviewed_by_name'] ?? '-') ?> on <?= e(format_datetime($document['ocr_reviewed_at'])) ?>.</p>
                <?php if (!empty($document['ocr_review_note'])): ?>
                    <p><?= e($document['ocr_review_note']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </details>
    <?php endif; ?>

    <?php if ($document['archived_at']): ?>
        <p><strong>Archived At:</strong> <?= e(format_datetime($document['archived_at'])) ?></p>
        <p><strong>Archived By:</strong> <?= e($document['archived_by_name'] ?? '-') ?></p>
        <p><strong>Archive Note:</strong> <?= e($document['archive_note']) ?></p>
    <?php endif; ?>
</div>

<?php if ($canComplete): ?>
    <div class="card no-print">
        <h2>Mark Processing Completed</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="complete">
            <label for="complete_remarks">Completion Notes</label>
            <textarea name="remarks" id="complete_remarks" placeholder="Explain how processing was completed" required></textarea>
            <button class="btn" type="submit">Mark Completed</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!empty($document['file_sha256']) && document_is_official($document)): ?>
    <div class="card no-print">
        <h2>Validate Digital File</h2>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="compare_file_hash">
            <input type="file" name="compare_file" required>
            <button class="btn btn-secondary" type="submit">Compare File</button>
        </form>
    </div>
<?php endif; ?>

<?php if (can_review_ocr_results() && (string) ($document['ocr_status'] ?? 'manual') === 'pending_review'): ?>
    <div class="card no-print">
        <h2>Review OCR Result</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ocr_review">
            <label for="ocr_status">Decision</label>
            <select name="ocr_status" id="ocr_status" required>
                <option value="verified">Verify OCR Metadata</option>
                <option value="rejected">Reject OCR Metadata</option>
            </select>
            <label for="ocr_review_remarks">Review Remarks</label>
            <textarea name="remarks" id="ocr_review_remarks" placeholder="Required when rejecting OCR results"></textarea>
            <button class="btn" type="submit">Save OCR Review</button>
        </form>
    </div>
<?php endif; ?>

<?php if (document_can_archive($document, null)): ?>
    <div class="card no-print">
        <h2>Archive Document</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="archive">
            <label for="archive_note">Archive Note</label>
            <textarea name="archive_note" id="archive_note" placeholder="Reason or note for final archiving" required></textarea>
            <button class="btn btn-secondary" type="submit">Archive</button>
        </form>
    </div>
<?php elseif (document_can_restore_archive($document)): ?>
    <div class="card no-print">
        <h2>Restore Archived Document</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore_archive">
            <label for="restore_remarks">Restore Remarks</label>
            <textarea name="restore_remarks" id="restore_remarks" placeholder="Reason for restoring this archived document" required></textarea>
            <button class="btn" type="submit">Restore To Completed</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($canViewPrivateLogs): ?>
    <div class="card">
        <h2>Document Timeline</h2>
        <p class="muted">This ledger records creation, location updates, completion, archive actions, and file validation.</p>
        <div class="table-wrap mobile-card-wrap">
            <table class="mobile-card-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Processed By</th>
                        <th>Processing Office</th>
                        <th>Related Office</th>
                        <th>Remarks</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$timelineEvents): ?>
                    <tr><td colspan="7"><div class="empty-state">No timeline entries recorded yet.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($timelineEvents as $event): ?>
                        <tr>
                            <td data-label="Event"><span class="<?= e(document_timeline_event_badge((string) $event['event_type'])) ?>"><?= e(document_timeline_event_label((string) $event['event_type'])) ?></span></td>
                            <td data-label="Status After"><?= e($event['stage_after']) ?></td>
                            <td data-label="Actor"><?= e($event['actor_name'] ?? 'System') ?></td>
                            <td data-label="Actor Office">
                                <?= e($event['actor_office_name'] ?? '-') ?>
                                <span class="muted office-contact"><?= e(office_contact_label($event['actor_office_trunk_line'] ?? null, $event['actor_office_local_number'] ?? null)) ?></span>
                            </td>
                            <td data-label="Related Office">
                                <?= e($event['counterparty_office_name'] ?? '-') ?>
                                <span class="muted office-contact"><?= e(office_contact_label($event['counterparty_office_trunk_line'] ?? null, $event['counterparty_office_local_number'] ?? null)) ?></span>
                            </td>
                            <td data-label="Remarks"><?= e($event['remarks'] ?? '-') ?></td>
                            <td data-label="Time"><?= e(format_datetime($event['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>QR Scan and Location Log</h2>
        <div class="table-wrap mobile-card-wrap">
            <table class="mobile-card-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Result</th>
                        <th>User</th>
                        <th>Office</th>
                        <th>Handler</th>
                        <th>Remarks</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$scanLogs): ?>
                    <tr><td colspan="7"><div class="empty-state">No QR scan or location log entries recorded yet.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($scanLogs as $log): ?>
                        <tr>
                            <td data-label="Action"><?= e(ucwords(str_replace('_', ' ', (string) $log['action']))) ?></td>
                            <td data-label="Result"><span class="<?= e(scan_result_badge((string) $log['result'])) ?>"><?= e(ucfirst((string) $log['result'])) ?></span></td>
                            <td data-label="User"><?= e($log['user_name'] ?? '-') ?></td>
                            <td data-label="Office">
                                <?= e($log['office_name'] ?? '-') ?>
                                <span class="muted office-contact"><?= e(office_contact_label($log['office_trunk_line'] ?? null, $log['office_local_number'] ?? null)) ?></span>
                            </td>
                            <td data-label="Handler"><?= e($log['physical_handler_name'] ?? '-') ?></td>
                            <td data-label="Remarks"><?= e($log['remarks'] ?? '-') ?></td>
                            <td data-label="Time"><?= e(format_datetime($log['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
