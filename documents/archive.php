<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$pageTitle = 'Archived Records';
$q = input_string($_GET['q'] ?? '');
$officeId = input_int($_GET['office_id'] ?? 0);
$categoryId = input_int($_GET['category_id'] ?? 0);
$archivedById = input_int($_GET['archived_by'] ?? 0);
$dateFrom = input_date($_GET['date_from'] ?? '');
$dateTo = input_date($_GET['date_to'] ?? '');
$year = input_int($_GET['year'] ?? 0);
$month = input_int($_GET['month'] ?? 0);
$export = input_string($_GET['export'] ?? '');
$page = pagination_page_from_request();
$perPage = pagination_per_page_from_request();
$isRecordsOfficer = has_role('records_officer');
$categories = load_categories(false);
$categoryPlaceholder = 'All Categories';
if ($isRecordsOfficer) {
    $categories = array_values(array_filter(
        $categories,
        static fn (array $category): bool => records_office_archive_category_name_matches((string) ($category['name'] ?? ''))
    ));
    $recordsCategoryIds = array_map(static fn (array $category): int => (int) $category['id'], $categories);
    if ($categoryId > 0 && !in_array($categoryId, $recordsCategoryIds, true)) {
        $categoryId = 0;
    }
    $categoryPlaceholder = 'All Records Categories';
}
if ($categoryId <= 0) {
    unset($_GET['category_id']);
} else {
    $_GET['category_id'] = (string) $categoryId;
}
$offices = can_manage_all_documents() ? load_offices(false) : [];
$archiveAccess = document_archive_access_condition('d', 'archive_scope_');
$params = [];
$sql = 'SELECT d.*,
               c.name AS category_name,
               o_origin.name AS origin_office_name,
               o_current.name AS current_office_name,
               creator.full_name AS created_by_name,
               archiver.full_name AS archived_by_name
        FROM documents d
        LEFT JOIN document_categories c ON c.id = d.category_id
        LEFT JOIN offices o_origin ON o_origin.id = d.origin_office_id
        LEFT JOIN offices o_current ON o_current.id = d.current_office_id
        LEFT JOIN users creator ON creator.id = d.created_by
        LEFT JOIN users archiver ON archiver.id = d.archived_by
        WHERE d.deleted_at IS NULL
          AND d.status = "Archived"';

if ($q !== '') {
    $search = build_document_search_clause($q, [
        'c.name',
        'd.document_name',
        'd.document_creator',
        'o_origin.name',
        'o_current.name',
        'creator.full_name',
        'archiver.full_name',
    ], 'archive_q');
    $sql .= ' AND ' . $search['sql'];
    $params = array_merge($params, $search['params']);
}
if ($officeId > 0) {
    $sql .= ' AND d.current_office_id = :office_id';
    $params['office_id'] = $officeId;
}
if ($categoryId > 0) {
    $sql .= ' AND d.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($archivedById > 0) {
    $sql .= ' AND d.archived_by = :archived_by';
    $params['archived_by'] = $archivedById;
}
if ($dateFrom !== '') {
    $sql .= ' AND d.archived_at >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND d.archived_at < :date_to';
    $params['date_to'] = date_string_end_exclusive($dateTo);
}
if (($periodSql = date_range_filter_sql('d.archived_at', $year, $month, 'archive_period', $params)) !== '') {
    $sql .= ' AND ' . $periodSql;
}

$sql .= ' AND ' . $archiveAccess['sql'];
$params = array_merge($params, $archiveAccess['params']);

$countStmt = db()->prepare('SELECT COUNT(*) FROM (' . $sql . ') AS filtered_archive');
$countStmt->execute($params);
$totalDocuments = (int) $countStmt->fetchColumn();

$sql .= ' ORDER BY c.name ASC, d.archived_at DESC, d.received_date DESC';
if ($export !== 'csv') {
    $page = min($page, pagination_total_pages($totalDocuments, $perPage));
    $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . pagination_offset($page, $perPage);
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$archiveMonthYear = static function (?string $archivedAt): string {
    if (!$archivedAt) {
        return '-';
    }

    $timestamp = strtotime($archivedAt);

    return $timestamp !== false ? date('F Y', $timestamp) : '-';
};

if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="prmsu_iba_archived_logbook.csv"');

    $output = fopen('php://output', 'wb');
    csv_write_row($output, ['Tracking No.', 'Document No.', 'Document Name', 'Subject', 'Description', 'Category', 'Source Office', 'Created By', 'Date of Creation', 'Archive Month/Year', 'Current Location', 'Archived By', 'Archived At', 'Archive Note']);
    foreach ($documents as $document) {
        csv_write_row($output, [
            $document['tracking_no'],
            $document['document_number'],
            $document['document_name'],
            $document['subject'],
            $document['description'],
            $document['category_name'],
            $document['origin_office_name'],
            $document['created_by_name'] ?? '-',
            $document['received_date'],
            $archiveMonthYear($document['archived_at'] ?? null),
            $document['current_office_name'],
            $document['archived_by_name'],
            format_datetime($document['archived_at']),
            $document['archive_note'],
        ]);
    }
    fclose($output);
    exit;
}

$archiveGroups = [];
foreach ($documents as $document) {
    $categoryName = (string) ($document['category_name'] ?? 'Uncategorized');
    $monthKey = $archiveMonthYear($document['archived_at'] ?? null);
    $groupKey = $categoryName . '|' . $monthKey;

    if (!isset($archiveGroups[$groupKey])) {
        $archiveGroups[$groupKey] = [
            'category' => $categoryName,
            'month' => $monthKey,
            'documents' => [],
        ];
    }

    $archiveGroups[$groupKey]['documents'][] = $document;
}

$archiverSql = 'SELECT DISTINCT u.id, u.full_name
                FROM documents d
                INNER JOIN users u ON u.id = d.archived_by
                WHERE d.deleted_at IS NULL
                  AND d.status = "Archived"';
$archiverSql .= ' AND ' . $archiveAccess['sql'] . ' ORDER BY u.full_name ASC';
$archiverStmt = db()->prepare($archiverSql);
$archiverStmt->execute($archiveAccess['params']);
$archivers = $archiverStmt->fetchAll();

$yearSql = 'SELECT DISTINCT YEAR(d.archived_at) AS archive_year
            FROM documents d
            WHERE d.deleted_at IS NULL
              AND d.status = "Archived"
              AND d.archived_at IS NOT NULL';
$yearSql .= ' AND ' . $archiveAccess['sql'] . ' ORDER BY archive_year DESC';
$yearStmt = db()->prepare($yearSql);
$yearStmt->execute($archiveAccess['params']);
$archiveYears = array_filter(array_map('intval', $yearStmt->fetchAll(PDO::FETCH_COLUMN)));

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card no-print">
    <div class="section-heading filter-intro">
        <div>
            <h2>Filter Archived Records</h2>
            <p class="muted">Search archived campus records by tracking number, document number, name, subject, description, category, source office, current office, archiver, archive year, and archive month.</p>
        </div>
        <div class="record-count"><?= (int) $totalDocuments ?> matching record<?= $totalDocuments === 1 ? '' : 's' ?></div>
    </div>
    <form method="get" class="filter-grid">
        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <input type="text" name="q" placeholder="Search tracking no., document no., document name, subject, description, category, source office, note, or archiver" value="<?= e($q) ?>">

        <?php if (can_manage_all_documents()): ?>
            <select name="office_id">
                <option value="0">All Current Units</option>
                <?php foreach ($offices as $office): ?>
                    <option value="<?= (int) $office['id'] ?>" <?= $officeId === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select name="category_id">
            <option value="0"><?= e($categoryPlaceholder) ?></option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="archived_by">
            <option value="0">All Archived By</option>
            <?php foreach ($archivers as $archiver): ?>
                <option value="<?= (int) $archiver['id'] ?>" <?= $archivedById === (int) $archiver['id'] ? 'selected' : '' ?>><?= e($archiver['full_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="year">
            <option value="0">All Years</option>
            <?php foreach ($archiveYears as $archiveYear): ?>
                <option value="<?= (int) $archiveYear ?>" <?= $year === (int) $archiveYear ? 'selected' : '' ?>><?= (int) $archiveYear ?></option>
            <?php endforeach; ?>
        </select>

        <select name="month">
            <option value="0">All Months</option>
            <?php for ($monthValue = 1; $monthValue <= 12; $monthValue++): ?>
                <option value="<?= $monthValue ?>" <?= $month === $monthValue ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $monthValue, 1))) ?></option>
            <?php endfor; ?>
        </select>

        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" title="Archive date from">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>" title="Archive date to">

        <div class="actions">
            <button class="btn" type="submit">Apply Filters</button>
            <button class="btn btn-secondary" type="submit" name="export" value="csv">Download Records</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/archive.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Archived Documents</h2>
            <p class="muted">Archived records are grouped by category and archive month while preserving their creation date, archive note, archiver, and timestamp.</p>
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
                    <th>Subject</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Source Office</th>
                    <th>Created By</th>
                    <th>Date of Creation</th>
                    <th>Archive Month/Year</th>
                    <th>Current Location</th>
                    <th>Archived By</th>
                    <th>Archived At</th>
                    <th>Archive Note</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$documents): ?>
                    <tr><td colspan="17"><div class="empty-state">No archived documents matched the selected filters.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($archiveGroups as $group): ?>
                        <tr>
                            <td class="mobile-card-group" colspan="17"><strong><?= e($group['category']) ?> / <?= e($group['month']) ?></strong></td>
                        </tr>
                        <?php foreach ($group['documents'] as $document): ?>
                            <tr>
                                <td data-label="Tracking No."><?= e($document['tracking_no']) ?></td>
                                <td data-label="Document No."><?= e($document['document_number'] ?? '-') ?></td>
                                <td data-label="Document Name"><?= e($document['document_name'] ?? '-') ?></td>
                                <!-- <td data-label="Name in Document"><?= e(($document['document_person_name'] ?? '-')) ?></td> -->
                                <td data-label="Subject"><?= e($document['subject']) ?></td>
                                <td data-label="Description"><?= render_collapsible_text($document['description'] ?? '') ?></td>
                                <td data-label="Category"><?= e($document['category_name'] ?? '-') ?></td>
                                <td data-label="Source Office"><?= e($document['origin_office_name'] ?? '-') ?></td>
                                <td data-label="Created By"><?= e($document['created_by_name'] ?? '-') ?></td>
                                <td data-label="Date of Creation"><?= e($document['received_date']) ?></td>
                                <td data-label="Archive Month/Year"><?= e($archiveMonthYear($document['archived_at'] ?? null)) ?></td>
                                <td data-label="Current Location"><?= e($document['current_office_name'] ?? '-') ?></td>
                                <td data-label="Archived By"><?= e($document['archived_by_name'] ?? '-') ?></td>
                                <td data-label="Archived At"><?= e(format_datetime($document['archived_at'])) ?></td>
                                <td data-label="Archive Note"><?= e($document['archive_note']) ?></td>
                                <td data-label="Action"><a class="btn btn-sm" href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination_controls($totalDocuments, $page, $perPage) ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
