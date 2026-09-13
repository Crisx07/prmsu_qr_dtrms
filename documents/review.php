<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['issuing_authority']);

$pageTitle = 'Review Submissions';
$pagePath = '/documents/review.php';
$errors = [];
$officeId = current_office_id();

if ($officeId === null || !office_exists($officeId)) {
    set_flash('error', 'Your account must be assigned to an active office before reviewing submitted documents.');
    redirect('/dashboard.php');
}

if (is_post()) {
    require_csrf($pagePath);

    $id = input_int($_POST['id'] ?? 0);
    $action = input_string($_POST['action'] ?? '');
    $document = require_accessible_document_or_redirect(
        $id,
        $pagePath,
        'Document not found.',
        'You are not allowed to review that document.'
    );

    if (!can_review_document_release($document)) {
        set_flash('error', 'Only submitted documents from your office can be reviewed here.');
        redirect($pagePath);
    }

    if ($action === 'approve') {
        $reviewNote = input_string($_POST['review_note'] ?? '');
        $completedOnRelease = input_string($_POST['is_completed_on_release'] ?? '') === '1';
        $documentNumber = input_string($_POST['document_number'] ?? '');

        try {
            $releasedDocumentNumber = perform_release_document($id, $documentNumber, $completedOnRelease, $reviewNote);
            $numberMessage = $releasedDocumentNumber !== null ? ' Official document number: ' . $releasedDocumentNumber . '.' : ' No official document number was assigned.';
            set_flash('success', ($completedOnRelease ? 'Document approved, released, and marked completed.' : 'Document approved and released.') . $numberMessage);
        } catch (Throwable $e) {
            set_flash('error', 'Unable to approve the document: ' . $e->getMessage());
        }
        redirect($pagePath);
    }

    if ($action === 'reject') {
        $reviewNote = input_string($_POST['review_note'] ?? '');
        if ($reviewNote === '') {
            set_flash('error', 'Review remarks are required when rejecting a document.');
            redirect($pagePath);
        }

        try {
            perform_reject_document_release($id, $reviewNote);
            set_flash('success', 'Document rejected and returned to the creator.');
        } catch (Throwable $e) {
            set_flash('error', 'Unable to reject the document: ' . $e->getMessage());
        }
        redirect($pagePath);
    }

    set_flash('error', 'Please choose a valid review action.');
    redirect($pagePath);
}

$q = input_string($_GET['q'] ?? '');
$categoryId = input_int($_GET['category_id'] ?? 0);
$page = pagination_page_from_request();
$perPage = pagination_per_page_from_request();
$categories = load_categories(false);
$params = [
    'office_id' => $officeId,
];

$sql = 'SELECT d.*,
               c.name AS category_name,
               o_origin.name AS origin_office_name,
               creator.full_name AS created_by_name
        FROM documents d
        LEFT JOIN document_categories c ON c.id = d.category_id
        LEFT JOIN offices o_origin ON o_origin.id = d.origin_office_id
        LEFT JOIN users creator ON creator.id = d.created_by
        WHERE d.deleted_at IS NULL
          AND d.status = "Submitted"
          AND d.origin_office_id = :office_id';

if ($q !== '') {
    $search = build_like_search_clause($q, [
        'd.tracking_no',
        'd.document_name',
        'd.document_person_name',
        'd.subject',
        'd.description',
        'c.name',
        'creator.full_name',
    ], 'review_q');
    $sql .= ' AND ' . $search['sql'];
    $params = array_merge($params, $search['params']);
}
if ($categoryId > 0) {
    $sql .= ' AND d.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

$countStmt = db()->prepare('SELECT COUNT(*) FROM (' . $sql . ') AS filtered_review');
$countStmt->execute($params);
$totalDocuments = (int) $countStmt->fetchColumn();
$page = min($page, pagination_total_pages($totalDocuments, $perPage));

$sql .= ' ORDER BY d.submitted_at ASC, d.created_at ASC LIMIT ' . $perPage . ' OFFSET ' . pagination_offset($page, $perPage);
$stmt = db()->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card no-print">
    <div class="section-heading filter-intro">
        <div>
            <h2>Filter Submissions</h2>
            <p class="muted">Review submitted drafts from your office and approve or reject them.</p>
        </div>
        <div class="record-count"><?= (int) $totalDocuments ?> pending submission<?= $totalDocuments === 1 ? '' : 's' ?></div>
    </div>
    <form method="get" class="filter-grid">
        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <input type="text" name="q" placeholder="Search tracking no., document name, subject, category, or creator" value="<?= e($q) ?>">

        <select name="category_id">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="actions">
            <button class="btn" type="submit">Apply Filters</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/review.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Submitted Documents</h2>
            <p class="muted">Approval releases the document and generates its QR code. Enter an official document number only when the document has one.</p>
        </div>
    </div>

    <div class="table-wrap mobile-card-wrap">
        <table class="mobile-card-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Document</th>
                    <!-- <th>Name in Document</th> -->
                    <th>Category</th>
                    <th>Submitted By</th>
                    <th>Submitted At</th>
                    <th>Review</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$documents): ?>
                <tr><td colspan="7"><div class="empty-state">No submitted documents are waiting for your review.</div></td></tr>
            <?php else: ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                        <td data-label="Document">
                            <strong><?= e($document['document_name'] ?? '-') ?></strong><br>
                            <span class="muted"><?= e($document['subject'] ?? '-') ?></span>
                        </td>
                        <!-- <td data-label="Name in Document"><?= e($document['document_person_name'] ?? '-') ?></td> -->
                        <td data-label="Category"><?= e($document['category_name'] ?? '-') ?></td>
                        <td data-label="Submitted By"><?= e($document['created_by_name'] ?? '-') ?></td>
                        <td data-label="Submitted At"><?= e(format_datetime($document['submitted_at'] ?? $document['updated_at'])) ?></td>
                        <td data-label="Review">
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $document['id'] ?>">
                                <label for="document_number_<?= (int) $document['id'] ?>">Document Number</label>
                                <input type="text" name="document_number" id="document_number_<?= (int) $document['id'] ?>" value="<?= e($document['document_number'] ?? '') ?>" placeholder="Optional — leave blank if not applicable">
                                <label class="checkbox-option" for="completed_<?= (int) $document['id'] ?>">
                                    <input type="checkbox" name="is_completed_on_release" id="completed_<?= (int) $document['id'] ?>" value="1">
                                    <span><strong>Completed on release</strong></span>
                                </label>
                                <textarea name="review_note" placeholder="Review remarks"></textarea>
                                <div class="actions">
                                    <button class="btn btn-sm" type="submit" name="action" value="approve">Approve</button>
                                    <button class="btn btn-sm btn-danger" type="submit" name="action" value="reject">Reject</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination_controls($totalDocuments, $page, $perPage) ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
