<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['records_officer']);

$pageTitle = 'OCR Review';
$pagePath = '/documents/ocr-review.php';
$q = input_string($_GET['q'] ?? '');
$categoryId = input_int($_GET['category_id'] ?? 0);
$officeId = input_int($_GET['office_id'] ?? 0);
$categories = load_categories(false);
$offices = load_offices(false);

if (is_post()) {
    require_csrf($pagePath);

    $id = input_int($_POST['id'] ?? 0);
    $reviewStatus = input_string($_POST['ocr_status'] ?? '');
    $remarks = input_string($_POST['remarks'] ?? '');
    $document = require_document_or_redirect($id, $pagePath);

    if ((string) ($document['ocr_status'] ?? '') !== 'pending_review') {
        set_flash('error', 'Only documents with pending OCR review can be updated here.');
        redirect($pagePath);
    }
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

$params = [];
$sql = 'SELECT d.*,
               c.name AS category_name,
               o_origin.name AS origin_office_name,
               o_current.name AS current_office_name,
               creator.full_name AS created_by_name
        FROM documents d
        LEFT JOIN document_categories c ON c.id = d.category_id
        LEFT JOIN offices o_origin ON o_origin.id = d.origin_office_id
        LEFT JOIN offices o_current ON o_current.id = d.current_office_id
        LEFT JOIN users creator ON creator.id = d.created_by
        WHERE d.deleted_at IS NULL
          AND d.ocr_status = "pending_review"';

if ($q !== '') {
    $search = build_like_search_clause($q, [
        'd.tracking_no',
        'd.document_number',
        'd.document_name',
        'd.document_person_name',
        'd.subject',
        'c.name',
        'o_origin.name',
        'o_current.name',
        'creator.full_name',
    ], 'ocr_q');
    $sql .= ' AND ' . $search['sql'];
    $params = array_merge($params, $search['params']);
}
if ($categoryId > 0) {
    $sql .= ' AND d.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($officeId > 0) {
    $sql .= ' AND d.current_office_id = :office_id';
    $params['office_id'] = $officeId;
}

$sql .= ' ORDER BY d.created_at ASC, d.id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card no-print">
    <div class="section-heading filter-intro">
        <div>
            <h2>Filter OCR Queue</h2>
            <p class="muted">Review documents created with OCR suggestions before they are treated as verified metadata.</p>
        </div>
        <div class="record-count"><?= count($documents) ?> pending record<?= count($documents) === 1 ? '' : 's' ?></div>
    </div>
    <form method="get" class="filter-grid">
        <input type="text" name="q" placeholder="Search tracking no., document no., name in document, subject, office, or issuer" value="<?= e($q) ?>">

        <select name="category_id">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="office_id">
            <option value="0">All Current Offices</option>
            <?php foreach ($offices as $office): ?>
                <option value="<?= (int) $office['id'] ?>" <?= $officeId === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="actions">
            <button class="btn" type="submit">Apply Filters</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/ocr-review.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Pending OCR Review</h2>
            <p class="muted">Verify correct OCR metadata or reject it with notes for correction.</p>
        </div>
    </div>

    <div class="table-wrap mobile-card-wrap">
        <table class="mobile-card-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Document No.</th>
                    <th>Document Name</th>
                    <!-- <th>Name in Document</th> -->
                    <th>Category</th>
                    <th>Source Office</th>
                    <th>Current Location</th>
                    <th>Confidence</th>
                    <th>Created By</th>
                    <th>Review</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$documents): ?>
                <tr><td colspan="10"><div class="empty-state">No documents are waiting for OCR review.</div></td></tr>
            <?php else: ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                        <td data-label="Document No."><?= e($document['document_number'] ?? '-') ?></td>
                        <td data-label="Document Name">
                            <strong><?= e($document['document_name'] ?? '-') ?></strong><br>
                            <span class="muted"><?= e($document['subject'] ?? '-') ?></span>
                        </td>
                        <!-- <td data-label="Name in Document"><?= e($document['document_person_name'] ?? '-') ?></td> -->
                        <td data-label="Category"><?= e($document['category_name'] ?? '-') ?></td>
                        <td data-label="Source Office"><?= e($document['origin_office_name'] ?? '-') ?></td>
                        <td data-label="Current Location"><?= e($document['current_office_name'] ?? '-') ?></td>
                        <td data-label="Confidence"><?= $document['ocr_confidence'] !== null ? e((string) $document['ocr_confidence']) . '%' : '-' ?></td>
                        <td data-label="Created By"><?= e($document['created_by_name'] ?? '-') ?></td>
                        <td data-label="Review">
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $document['id'] ?>">
                                <select name="ocr_status" required>
                                    <option value="verified">Verify</option>
                                    <option value="rejected">Reject</option>
                                </select>
                                <textarea name="remarks" placeholder="Remarks"></textarea>
                                <button class="btn btn-sm" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
