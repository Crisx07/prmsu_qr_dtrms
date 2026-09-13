<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(document_module_roles());

$pageTitle = 'Reports';
$q = input_string($_GET['q'] ?? '');
$status = input_string($_GET['status'] ?? '');
$officeId = input_int($_GET['office_id'] ?? 0);
$categoryId = input_int($_GET['category_id'] ?? 0);
$createdById = input_int($_GET['created_by'] ?? 0);
$dateFrom = input_date($_GET['date_from'] ?? '');
$dateTo = input_date($_GET['date_to'] ?? '');
$year = input_int($_GET['year'] ?? 0);
$month = input_int($_GET['month'] ?? 0);
$export = input_string($_GET['export'] ?? '');
$page = pagination_page_from_request();
$perPage = pagination_per_page_from_request();
$isRecordsOfficer = has_role('records_officer');
$isIssuingAuthority = has_role('issuing_authority');
$statusOptions = document_report_status_filter_options();
$canFilterCurrentOffice = $isRecordsOfficer;
$canFilterCreator = $isIssuingAuthority;
$statusPlaceholder = $isRecordsOfficer ? 'Completed and Archived' : 'All Statuses';
$categoryPlaceholder = $isRecordsOfficer ? 'All Records Categories' : 'All Categories';

if ($status !== '' && !in_array($status, $statusOptions, true)) {
    $status = '';
}
if (!$canFilterCurrentOffice) {
    $officeId = 0;
    unset($_GET['office_id']);
}
if (!$canFilterCreator) {
    $createdById = 0;
    unset($_GET['created_by']);
}

$categories = [];
if ($isRecordsOfficer) {
    $categories = array_values(array_filter(
        load_categories(false),
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
if ($officeId <= 0) {
    unset($_GET['office_id']);
} else {
    $_GET['office_id'] = (string) $officeId;
}
if ($categoryId <= 0) {
    unset($_GET['category_id']);
} else {
    $_GET['category_id'] = (string) $categoryId;
}

$buildStatusCondition = static function (string $documentAlias, string $paramPrefix, array $allowedStatuses, array &$targetParams): string {
    if ($allowedStatuses === []) {
        return '1 = 0';
    }

    $placeholders = [];
    foreach (array_values($allowedStatuses) as $index => $allowedStatus) {
        $key = $paramPrefix . $index;
        $placeholders[] = ':' . $key;
        $targetParams[$key] = $allowedStatus;
    }

    return $documentAlias . '.status IN (' . implode(', ', $placeholders) . ')';
};

$creatorOptions = [];
if ($canFilterCreator) {
    $creatorScope = document_report_scope_condition('d', 'report_creator_scope_');
    $creatorParams = $creatorScope['params'];
    $creatorStatusSql = $buildStatusCondition('d', 'report_creator_status_', $statusOptions, $creatorParams);
    $creatorStmt = db()->prepare(
        'SELECT DISTINCT creator.id, creator.full_name
         FROM users creator
         INNER JOIN documents d ON d.created_by = creator.id
         WHERE d.deleted_at IS NULL
           AND ' . $creatorStatusSql . '
           AND ' . $creatorScope['sql'] . '
         ORDER BY creator.full_name ASC'
    );
    $creatorStmt->execute($creatorParams);
    $creatorOptions = $creatorStmt->fetchAll();
    $allowedCreatorIds = array_map(static fn (array $creator): int => (int) $creator['id'], $creatorOptions);
    if ($createdById > 0 && !in_array($createdById, $allowedCreatorIds, true)) {
        $createdById = 0;
    }
    if ($createdById <= 0) {
        unset($_GET['created_by']);
    } else {
        $_GET['created_by'] = (string) $createdById;
    }
}

$reportScope = document_report_scope_condition('d', 'report_scope_');
$params = $reportScope['params'];
$statusScopeSql = $buildStatusCondition('d', 'report_status_', $statusOptions, $params);

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
          AND ' . $statusScopeSql . '
          AND ' . $reportScope['sql'];

if ($q !== '') {
    $search = build_document_search_clause($q, [
        'c.name',
        'o_origin.name',
        'o_current.name',
    ], 'report_q');
    $sql .= ' AND ' . $search['sql'];
    $params = array_merge($params, $search['params']);
}
if ($status !== '') {
    $sql .= ' AND d.status = :status';
    $params['status'] = $status;
}
if ($canFilterCurrentOffice && $officeId > 0) {
    $sql .= ' AND d.current_office_id = :office_id';
    $params['office_id'] = $officeId;
}
if ($categoryId > 0) {
    $sql .= ' AND d.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($canFilterCreator && $createdById > 0) {
    $sql .= ' AND d.created_by = :created_by';
    $params['created_by'] = $createdById;
}
if ($dateFrom !== '') {
    $sql .= ' AND d.received_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND d.received_date <= :date_to';
    $params['date_to'] = $dateTo;
}
if (($periodSql = date_range_filter_sql('d.received_date', $year, $month, 'report_period', $params)) !== '') {
    $sql .= ' AND ' . $periodSql;
}

$summaryStmt = db()->prepare(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = "Draft" THEN 1 ELSE 0 END) AS draft,
            SUM(CASE WHEN status = "Submitted" THEN 1 ELSE 0 END) AS submitted,
            SUM(CASE WHEN status = "Rejected" THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN status = "Under Action" THEN 1 ELSE 0 END) AS under_action,
            SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = "Archived" THEN 1 ELSE 0 END) AS archived
     FROM (' . $sql . ') AS filtered_report'
);
$summaryStmt->execute($params);
$summaryRow = $summaryStmt->fetch() ?: [];
$totalDocuments = (int) ($summaryRow['total'] ?? 0);

$sql .= ' ORDER BY d.received_date DESC, d.created_at DESC';
if ($export !== 'csv') {
    $page = min($page, pagination_total_pages($totalDocuments, $perPage));
    $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . pagination_offset($page, $perPage);
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="prmsu_iba_hardcopy_report.csv"');

    $output = fopen('php://output', 'wb');

    csv_write_row($output, [
        'Tracking No.',
        'Document No.',
        'Document Name',
        'Subject',
        'Description',
        'Category',
        'Origin Unit',
        'Current Location',
        'Status',
        'Date of Creation',
        'Archived Month/Year',
        'Created By'
    ]);

    foreach ($documents as $document) {
        $creationDate = (string) ($document['received_date'] ?? '');
        $archiveDate = (string) ($document['archived_at'] ?? '');
        $archiveTimestamp = $archiveDate !== '' ? strtotime($archiveDate) : false;
        $archiveMonthYear = '';

        if ($archiveTimestamp !== false && strtolower((string) ($document['status'] ?? '')) === 'archived') {
            $archiveMonthYear = date('F Y', $archiveTimestamp);
        }

        csv_write_row($output, [
            $document['tracking_no'],
            $document['document_number'],
            $document['document_name'],
            $document['subject'],
            $document['description'],
            $document['category_name'],
            $document['origin_office_name'],
            $document['current_office_name'],
            $document['status'],
            $creationDate,
            $archiveMonthYear,
            $document['created_by_name'],
        ]);
    }

    fclose($output);
    exit;
}

$summary = [
    'count' => $totalDocuments,
    'draft' => (int) ($summaryRow['draft'] ?? 0),
    'submitted' => (int) ($summaryRow['submitted'] ?? 0),
    'rejected' => (int) ($summaryRow['rejected'] ?? 0),
    'under_action' => (int) ($summaryRow['under_action'] ?? 0),
    'completed' => (int) ($summaryRow['completed'] ?? 0),
    'archived' => (int) ($summaryRow['archived'] ?? 0),
];

$offices = [];
if ($canFilterCurrentOffice) {
    $officeScope = document_report_scope_condition('d', 'report_office_scope_');
    $officeParams = $officeScope['params'];
    $officeStatusSql = $buildStatusCondition('d', 'report_office_status_', $statusOptions, $officeParams);
    $officeStmt = db()->prepare(
        'SELECT DISTINCT o.*
         FROM offices o
         INNER JOIN documents d ON d.current_office_id = o.id
         WHERE d.deleted_at IS NULL
           AND ' . $officeStatusSql . '
           AND ' . $officeScope['sql'] . '
         ORDER BY o.name ASC'
    );
    $officeStmt->execute($officeParams);
    $offices = $officeStmt->fetchAll();
}

if (!$isRecordsOfficer) {
    $categoryScope = document_report_scope_condition('d', 'report_category_scope_');
    $categoryParams = $categoryScope['params'];
    $categoryStatusSql = $buildStatusCondition('d', 'report_category_status_', $statusOptions, $categoryParams);
    $categoryStmt = db()->prepare(
        'SELECT DISTINCT c.*
         FROM document_categories c
         INNER JOIN documents d ON d.category_id = c.id
         WHERE d.deleted_at IS NULL
           AND ' . $categoryStatusSql . '
           AND ' . $categoryScope['sql'] . '
         ORDER BY c.name ASC'
    );
    $categoryStmt->execute($categoryParams);
    $categories = $categoryStmt->fetchAll();
}

$yearScope = document_report_scope_condition('d', 'report_year_scope_');
$yearParams = $yearScope['params'];
$yearStatusSql = $buildStatusCondition('d', 'report_year_status_', $statusOptions, $yearParams);
$yearStmt = db()->prepare(
    'SELECT DISTINCT YEAR(d.received_date) AS report_year
     FROM documents d
     WHERE d.deleted_at IS NULL
       AND ' . $yearStatusSql . '
       AND d.received_date IS NOT NULL
       AND ' . $yearScope['sql'] . '
     ORDER BY report_year DESC'
);
$yearStmt->execute($yearParams);
$reportYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

$summaryStatusCards = $isRecordsOfficer
    ? [
        ['label' => 'Completed', 'key' => 'completed'],
        ['label' => 'Archived', 'key' => 'archived'],
    ]
    : [
        ['label' => 'Draft', 'key' => 'draft'],
        ['label' => 'Submitted', 'key' => 'submitted'],
        ['label' => 'Rejected', 'key' => 'rejected'],
        ['label' => 'Under Action', 'key' => 'under_action'],
        ['label' => 'Completed', 'key' => 'completed'],
        ['label' => 'Archived', 'key' => 'archived'],
    ];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid">
    <div class="stat"><h3>Filtered Records</h3><strong><?= $summary['count'] ?></strong></div>
    <?php foreach ($summaryStatusCards as $card): ?>
        <div class="stat"><h3><?= e($card['label']) ?></h3><strong><?= (int) $summary[$card['key']] ?></strong></div>
    <?php endforeach; ?>
</div>

<div class="card no-print">
    <form method="get" class="filter-grid">
        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <input type="text" name="q" placeholder="Search tracking no., document no., document name, subject, description, unit, or category" value="<?= e($q) ?>">

        <select name="status">
            <option value=""><?= e($statusPlaceholder) ?></option>
            <?php foreach ($statusOptions as $option): ?>
                <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($canFilterCurrentOffice): ?>
            <select name="office_id">
                <option value="0">All Units</option>
                <?php foreach ($offices as $office): ?>
                    <option value="<?= (int) $office['id'] ?>" <?= $officeId === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($canFilterCreator): ?>
            <select name="created_by">
                <option value="0">All Creators</option>
                <?php foreach ($creatorOptions as $creator): ?>
                    <option value="<?= (int) $creator['id'] ?>" <?= $createdById === (int) $creator['id'] ? 'selected' : '' ?>><?= e($creator['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select name="category_id">
            <option value="0"><?= e($categoryPlaceholder) ?></option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="year">
            <option value="0">All Years</option>
            <?php foreach ($reportYears as $reportYear): ?>
                <option value="<?= (int) $reportYear ?>" <?= $year === (int) $reportYear ? 'selected' : '' ?>><?= (int) $reportYear ?></option>
            <?php endforeach; ?>
        </select>

        <select name="month">
            <option value="0">All Months</option>
            <?php for ($monthValue = 1; $monthValue <= 12; $monthValue++): ?>
                <option value="<?= $monthValue ?>" <?= $month === $monthValue ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $monthValue, 1))) ?></option>
            <?php endfor; ?>
        </select>

        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">

        <div class="actions">
            <button class="btn" type="submit">Apply Filters</button>
            <button class="btn btn-secondary" type="submit" name="export" value="csv">Download Records</button>
            <button class="btn btn-secondary" type="button" onclick="window.print()">Print</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/reports/index.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Document Report</h2>
            <p class="muted">Use the filters above to generate document reports by status, creator, category, date of creation, year, and month.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Document No.</th>
                    <th>Document Name</th>
                    <th>Subject</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Origin Unit</th>
                    <th>Current Location</th>
                    <th>Status</th>
                    <th>Date of Creation</th>
                    <th>Created By</th>
                    <!-- <th>OCR</th> -->
                </tr>
            </thead>
            <tbody>
            <?php if (!$documents): ?>
                <tr><td colspan="13"><div class="empty-state">No records match the current report filters.</div></td></tr>
            <?php else: ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td><?= e($document['tracking_no']) ?></td>
                        <td><?= e($document['document_number'] ?? '-') ?></td>
                        <td><?= e($document['document_name'] ?? '-') ?></td>
                        <td><?= e($document['subject']) ?></td>
                        <td><?= render_collapsible_text($document['description'] ?? '') ?></td>
                        <td><?= e($document['category_name'] ?? '-') ?></td>
                        <td><?= e($document['origin_office_name'] ?? '-') ?></td>
                        <td><?= e($document['current_office_name'] ?? '-') ?></td>
                        <td><span class="<?= e(document_status_badge($document['status'])) ?>"><?= e($document['status']) ?></span></td>
                        <td><?= e($document['received_date']) ?></td>
                        <td><?= e($document['created_by_name'] ?? '-') ?></td>
                        <!-- <td><span class="<?= e(ocr_status_badge($document['ocr_status'] ?? 'manual')) ?>"><?= e(ocr_status_label($document['ocr_status'] ?? 'manual')) ?></span></td> -->
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination_controls($totalDocuments, $page, $perPage) ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
