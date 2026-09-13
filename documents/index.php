<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$pageTitle = 'Documents';
$q = input_string($_GET['q'] ?? '');
$status = input_string($_GET['status'] ?? '');
$officeId = input_int($_GET['office_id'] ?? 0);
$categoryId = input_int($_GET['category_id'] ?? 0);
$page = pagination_page_from_request();
$perPage = pagination_per_page_from_request();
$isRecordsOfficer = has_role('records_officer');
$canFilterCurrentOffice = can_manage_all_documents();
$categories = load_categories(false);
$offices = $canFilterCurrentOffice ? load_offices(false) : [];
$statusOptions = document_index_status_filter_options();
if ($isRecordsOfficer) {
    $status = 'Completed';
}
if ($status !== '' && !in_array($status, $statusOptions, true)) {
    $status = '';
}
if (!$canFilterCurrentOffice) {
    $officeId = 0;
    unset($_GET['office_id']);
}
if ($isRecordsOfficer) {
    $categories = array_values(array_filter(
        $categories,
        static fn (array $category): bool => records_office_archive_category_name_matches((string) ($category['name'] ?? ''))
    ));
    $recordsCategoryIds = array_map(static fn (array $category): int => (int) $category['id'], $categories);
    if ($categoryId > 0 && !in_array($categoryId, $recordsCategoryIds, true)) {
        $categoryId = 0;
    }
}
if ($status === '') {
    unset($_GET['status']);
} else {
    $_GET['status'] = $status;
}
if ($categoryId <= 0) {
    unset($_GET['category_id']);
} else {
    $_GET['category_id'] = (string) $categoryId;
}
$isCompletedForArchiveView = $isRecordsOfficer && $status === 'Completed';
$isWorkflowStatusView = in_array($status, ['Draft', 'Submitted', 'Rejected'], true);
$showsOfficeWorkflowItems = has_role('office_staff') && $status === '';

$scope = document_access_condition('d', 'scope_');
$sql = 'SELECT d.*,
               c.name AS category_name,
               o_current.name AS current_office_name,
               o_origin.name AS origin_office_name,
               creator.full_name AS created_by_name
        FROM documents d
        LEFT JOIN document_categories c ON c.id = d.category_id
        LEFT JOIN offices o_current ON o_current.id = d.current_office_id
        LEFT JOIN offices o_origin ON o_origin.id = d.origin_office_id
        LEFT JOIN users creator ON creator.id = d.created_by
        WHERE d.deleted_at IS NULL
          AND d.status <> "Archived"';
$params = [];

if ($q !== '') {
    $search = build_document_search_clause($q, [
        'c.name',
        'o_current.name',
        'o_origin.name',
    ], 'document_q');
    $sql .= ' AND ' . $search['sql'];
    $params = array_merge($params, $search['params']);
}
if ($status !== '') {
    $sql .= ' AND d.status = :status';
    $params['status'] = $status;
} elseif (!$showsOfficeWorkflowItems) {
    $sql .= ' AND d.status NOT IN ("Draft", "Submitted", "Rejected")';
}
if ($canFilterCurrentOffice && $officeId > 0) {
    $sql .= ' AND d.current_office_id = :office_id';
    $params['office_id'] = $officeId;
}
if ($categoryId > 0) {
    $sql .= ' AND d.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($isRecordsOfficer) {
    $recordsCategoryScope = records_office_archive_category_condition('d', 'index_records_category_');
    $sql .= ' AND ' . $recordsCategoryScope['sql'];
    $params = array_merge($params, $recordsCategoryScope['params']);
}

$sql .= $scope['sql'];
$params = array_merge($params, $scope['params']);

$countStmt = db()->prepare('SELECT COUNT(*) FROM (' . $sql . ') AS filtered_documents');
$countStmt->execute($params);
$totalDocuments = (int) $countStmt->fetchColumn();
$page = min($page, pagination_total_pages($totalDocuments, $perPage));

$sql .= ' ORDER BY d.updated_at DESC, d.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . pagination_offset($page, $perPage);
$stmt = db()->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$selectedOfficeName = '';
if ($canFilterCurrentOffice && $officeId > 0) {
    foreach ($offices as $office) {
        if ((int) $office['id'] === $officeId) {
            $selectedOfficeName = (string) $office['name'];
            break;
        }
    }
}

$selectedCategoryName = '';
if ($categoryId > 0) {
    foreach ($categories as $category) {
        if ((int) $category['id'] === $categoryId) {
            $selectedCategoryName = (string) $category['name'];
            break;
        }
    }
}

// $appliedFilters = [];
// if ($q !== '') {
//     $appliedFilters[] = ['label' => 'Search', 'value' => $q];
// }
// if ($status !== '') {
//     $appliedFilters[] = ['label' => 'Status', 'value' => $status];
// }
// if ($selectedOfficeName !== '') {
//     $appliedFilters[] = ['label' => 'Current Unit', 'value' => $selectedOfficeName];
// }
// if ($selectedCategoryName !== '') {
//     $appliedFilters[] = ['label' => 'Category', 'value' => $selectedCategoryName];
// }
// if ($isRecordsOfficer) {
//     $appliedFilters[] = ['label' => 'Records Categories', 'value' => implode(', ', records_office_archive_category_names())];
// }

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card no-print">
    <form method="get" class="filter-grid">
        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <input type="text" name="q" placeholder="Search tracking no., document no., name, subject, office, or category" value="<?= e($q) ?>">

        <?php if ($isRecordsOfficer): ?>
            <div>
                <input type="hidden" name="status" value="Completed">
                <label>Status</label>
                <input type="text" value="Completed" disabled>
            </div>
        <?php else: ?>
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $option): ?>
                    <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if (can_manage_all_documents()): ?>
            <select name="office_id">
                <option value="">All Current Units</option>
                <?php foreach ($offices as $office): ?>
                    <option value="<?= (int) $office['id'] ?>" <?= $officeId === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select name="category_id">
            <option value=""><?= $isRecordsOfficer ? 'All Records Categories' : 'All Categories' ?></option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="actions">
            <button class="btn" type="submit">Apply Filters</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php<?= $isRecordsOfficer ? '?status=Completed' : '' ?>">Reset</a>
        </div>
    </form>
    <!-- <?php if ($appliedFilters): ?>
        <div class="applied-filters">
            <?php foreach ($appliedFilters as $filter): ?>
                <span class="filter-chip"><strong><?= e($filter['label']) ?>:</strong> <?= e($filter['value']) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?> -->
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2><?= $isCompletedForArchiveView ? 'Completed for Archive' : ($status !== '' ? e($status . ' Documents') : ($showsOfficeWorkflowItems ? 'Office Documents' : 'Active Documents')) ?></h2>
            <p class="muted">
                <?= $isWorkflowStatusView
                    ? 'This workflow view shows unreleased documents.'
                    : ($isCompletedForArchiveView
                    ? 'Open a completed record to review it and archive it with the required archive note.'
                    : ($status !== ''
                    ? 'This view shows documents matching the selected status and filters.'
                    : ($showsOfficeWorkflowItems
                    ? 'This view shows your drafts, submitted items, rejected items, and official documents.'
                    : 'This view shows active hardcopy records and their current physical location.'))) ?>
            </p>
        </div>
        <div class="record-count"><?= (int) $totalDocuments ?> matching record<?= $totalDocuments === 1 ? '' : 's' ?></div>
        <?php if (can_register_documents()): ?>
            <a class="btn" href="<?= BASE_URL ?>/documents/create.php"><?= has_role('office_staff') ? 'Create Draft' : 'Create Document' ?></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap mobile-card-wrap">
        <table class="mobile-card-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Document No.</th>
                    <th>Document Name</th>
                    <!-- <th>Name in Document</th> -->
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Origin Unit</th>
                    <th>Created By</th>
                    <th>Current Location</th>
                    <th>Status</th>
                    <th>Date of Creation</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$documents): ?>
                <tr><td colspan="12"><div class="empty-state"><?= $isCompletedForArchiveView ? 'No completed documents are waiting for archive.' : ($showsOfficeWorkflowItems ? 'No document records matched the selected filters.' : 'No active document records matched the selected filters.') ?></div></td></tr>
            <?php else: ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td data-label="Tracking No."><?= e($document['tracking_no']) ?></td>
                        <td data-label="Document No."><?= e($document['document_number'] ?? '-') ?></td>
                        <td data-label="Document Name"><?= e($document['document_name'] ?? '-') ?></td>
                        <!-- <td data-label="Name in Document"><?= e($document['document_person_name'] ?? '-') ?></td> -->
                        <td data-label="Subject"><?= e($document['subject']) ?></td>
                        <td data-label="Category"><?= e($document['category_name'] ?? '-') ?></td>
                        <td data-label="Origin Unit"><?= e($document['origin_office_name'] ?? '-') ?></td>
                        <td data-label="Created By"><?= e($document['created_by_name'] ?? '-') ?></td>
                        <td data-label="Current Location"><?= e($document['current_office_name'] ?? '-') ?></td>
                        <td data-label="Status"><span class="<?= e(document_status_badge($document['status'])) ?>"><?= e($document['status']) ?></span></td>
                        <td data-label="Date of Creation"><?= e($document['received_date']) ?></td>
                        <td data-label="Action">
                            <?php if (document_requires_records_officer_custody_gate($document) && !document_custody_is_verified($document)): ?>
                                <a class="btn btn-sm" href="<?= BASE_URL ?>/documents/scan.php?id=<?= (int) $document['id'] ?>">Scan QR</a>
                            <?php else: ?>
                                <a class="btn btn-sm" href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>">View</a>
                            <?php endif; ?>
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
