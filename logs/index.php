<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$pageTitle = 'Activity Logs';
$q = input_string($_GET['q'] ?? '');
$action = input_string($_GET['action'] ?? '');
$userId = input_int($_GET['user_id'] ?? 0);
$entityType = input_string($_GET['entity_type'] ?? '');
$dateFrom = input_date($_GET['date_from'] ?? '');
$dateTo = input_date($_GET['date_to'] ?? '');
$page = pagination_page_from_request();
$perPage = pagination_per_page_from_request();

$sql = 'SELECT a.*, u.full_name AS actor_name, u.email AS actor_email
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE 1 = 1';
$params = [];

if ($q !== '') {
    $search = build_like_search_clause($q, [
        'a.action',
        'a.entity_type',
        'a.details',
        'u.full_name',
        'u.email',
    ], 'log_q');
    $sql .= ' AND ' . $search['sql'];
    $params = array_merge($params, $search['params']);
}

if ($action !== '') {
    $sql .= ' AND a.action = :action';
    $params['action'] = $action;
}

if ($userId > 0) {
    $sql .= ' AND a.user_id = :user_id';
    $params['user_id'] = $userId;
}

if ($entityType !== '') {
    $sql .= ' AND a.entity_type = :entity_type';
    $params['entity_type'] = $entityType;
}

if ($dateFrom !== '') {
    $sql .= ' AND a.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $sql .= ' AND a.created_at < :date_to';
    $params['date_to'] = date_string_end_exclusive($dateTo) . ' 00:00:00';
}

$countStmt = db()->prepare('SELECT COUNT(*) FROM (' . $sql . ') AS filtered_logs');
$countStmt->execute($params);
$totalLogs = (int) $countStmt->fetchColumn();
$page = min($page, pagination_total_pages($totalLogs, $perPage));

$sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . pagination_offset($page, $perPage);

$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$filterOptions = audit_log_filter_options();
$activeFilterCount = 0;
foreach ([$q !== '', $action !== '', $userId > 0, $entityType !== '', $dateFrom !== '', $dateTo !== ''] as $isActive) {
    if ($isActive) {
        $activeFilterCount++;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card page-hero page-hero-compact">
    <div class="page-hero-main">
        <div>
            <div class="hero-eyebrow">System Administrator Activity Trail</div>
            <h2>Activity Logs</h2>
            <p class="muted">Review account, document, office, and category activities in one secure timeline for easier tracking and monitoring.</p>
        </div>
        <div class="hero-summary">
            <div class="hero-summary-item">
                <span>Visible Logs</span>
                <strong><?= (int) $totalLogs ?></strong>
            </div>
            <div class="hero-summary-item">
                <span>Active Filters</span>
                <strong><?= $activeFilterCount ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="card no-print">
    <div class="section-heading filter-intro">
        <div>
            <h2>Filter Activity</h2>
            <p class="muted">Narrow results by actor, action, entity, and date range.</p>
        </div>
        <div class="record-count"><?= (int) $totalLogs ?> matching entr<?= $totalLogs === 1 ? 'y' : 'ies' ?></div>
    </div>
    <form method="get" class="filter-grid">
        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <input type="text" name="q" placeholder="Search action, actor, entity, or details" value="<?= e($q) ?>">

        <select name="action">
            <option value="">All Actions</option>
            <?php foreach ($filterOptions['actions'] as $option): ?>
                <option value="<?= e((string) $option) ?>" <?= $action === (string) $option ? 'selected' : '' ?>>
                    <?= e(audit_log_label((string) $option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="user_id">
            <option value="0">All Actors</option>
            <?php foreach ($filterOptions['users'] as $actor): ?>
                <option value="<?= (int) $actor['user_id'] ?>" <?= $userId === (int) $actor['user_id'] ? 'selected' : '' ?>>
                    <?= e($actor['full_name']) ?> (<?= e($actor['email']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <select name="entity_type">
            <option value="">All Entity Types</option>
            <?php foreach ($filterOptions['entity_types'] as $option): ?>
                <option value="<?= e((string) $option) ?>" <?= $entityType === (string) $option ? 'selected' : '' ?>>
                    <?= e(audit_log_label((string) $option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">

        <div class="actions">
            <button class="btn" type="submit">Apply Filters</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/logs/index.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Activity Log Records</h2>
            <p class="muted">Review account, document, office, and category activity across campus hardcopy tracking.</p>
        </div>
        <div class="record-count"><?= (int) $totalLogs ?> log entr<?= $totalLogs === 1 ? 'y' : 'ies' ?></div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Entity Type</th>
                    <th>Entity ID</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="7"><div class="empty-state">No activity logs match the current filters.</div></td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e(format_datetime($log['created_at'])) ?></td>
                        <td>
                            <strong><?= e($log['actor_name'] ?? 'System') ?></strong>
                            <div class="muted"><?= e($log['actor_email'] ?? 'No linked account') ?></div>
                        </td>
                        <td><?= e(audit_log_label((string) $log['action'])) ?></td>
                        <td><?= e(audit_log_label((string) $log['entity_type'])) ?></td>
                        <td><?= $log['entity_id'] !== null ? (int) $log['entity_id'] : '-' ?></td>
                        <td><div class="log-details"><?= nl2br(e(format_audit_log_details($log['details']))) ?></div></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination_controls($totalLogs, $page, $perPage) ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
