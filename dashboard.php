<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Dashboard';
$currentUser = current_user();

if (($currentUser['role'] ?? '') === 'admin') {
    $userStats = db()->query(
        'SELECT COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_users,
                COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive_users,
                COALESCE(SUM(CASE WHEN must_change_password = 1 THEN 1 ELSE 0 END), 0) AS password_change_required
         FROM users'
    )->fetch() ?: [
        'total_users' => 0,
        'active_users' => 0,
        'inactive_users' => 0,
        'password_change_required' => 0,
    ];

    $officeStats = db()->query(
        'SELECT COUNT(*) AS total_offices,
                COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_offices
         FROM offices'
    )->fetch() ?: [
        'total_offices' => 0,
        'active_offices' => 0,
    ];

    $categoryStats = db()->query(
        'SELECT COUNT(*) AS total_categories,
                COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_categories
         FROM document_categories'
    )->fetch() ?: [
        'total_categories' => 0,
        'active_categories' => 0,
    ];

    $accountRequestStats = [
    'pending_requests' => 0,
];

if (table_exists('account_requests')) {
    $stmt = db()->query(
        "SELECT COUNT(*) AS pending_requests
         FROM account_requests
         WHERE status = 'pending'"
    );

    $accountRequestStats = $stmt->fetch() ?: $accountRequestStats;
}

    $documentOverview = db()->query(
        'SELECT COALESCE(SUM(CASE WHEN status NOT IN ("Archived", "Draft", "Submitted", "Rejected") THEN 1 ELSE 0 END), 0) AS active_docs,
                COALESCE(SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END), 0) AS completed_pending_archive,
                COALESCE(SUM(CASE WHEN status = "Archived" THEN 1 ELSE 0 END), 0) AS archived_docs
         FROM documents
         WHERE deleted_at IS NULL'
    )->fetch() ?: [
        'active_docs' => 0,
        'completed_pending_archive' => 0,
        'archived_docs' => 0,
    ];

    $databaseStatus = database_status();
    $uploadReady = is_dir(UPLOAD_DOCUMENTS) && is_writable(UPLOAD_DOCUMENTS);
    $systemChecks = [
        [
            'label' => 'Database',
            'ok' => (bool) ($databaseStatus['connected'] ?? false) && (bool) ($databaseStatus['ready'] ?? false),
            'detail' => (bool) ($databaseStatus['ready'] ?? false)
                ? 'Connected and required tables are present.'
                : 'Needs attention in System Health.',
        ],
        [
            'label' => 'Uploads',
            'ok' => $uploadReady,
            'detail' => $uploadReady
                ? 'Document upload directory is writable.'
                : 'Upload directory is missing or not writable.',
        ],
        [
            'label' => 'Recovery OTP',
            'ok' => auth_otp_enabled(),
            'detail' => auth_otp_enabled()
                ? 'Account recovery email OTP is enabled.'
                : 'Account recovery email OTP is disabled.',
        ],
        [
            'label' => 'Mail',
            'ok' => smtp_is_configured(),
            'detail' => smtp_is_configured()
                ? 'SMTP settings are configured.'
                : 'SMTP settings are incomplete.',
        ],
    ];

    $recentLogs = [];
    if (table_exists('audit_logs')) {
        $recentLogs = db()->query(
            'SELECT a.*,
                    u.full_name AS actor_name,
                    u.email AS actor_email,
                    u.user_uid AS actor_uid
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 8'
        )->fetchAll();
    }

    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="card page-hero">
        <div class="page-hero-main">
            <div>
                <div class="hero-eyebrow">System Administration</div>
                <h2>Welcome, <?= e($currentUser['full_name']) ?></h2>
                <p class="muted">
                    Monitor account access, system readiness, setup data, and recent administrator activity.
                </p>
            </div>

            <div class="hero-summary">
                <div class="hero-summary-item">
                    <span>Role</span>
                    <strong><?= e(role_label($currentUser['role'])) ?></strong>
                </div>

                <div class="hero-summary-item">
                    <span>Active Users</span>
                    <strong><?= (int) $userStats['active_users'] ?></strong>
                </div>

                <div class="hero-summary-item">
                    <span>System Checks</span>
                    <strong><?= count(array_filter($systemChecks, static fn (array $check): bool => $check['ok'])) ?>/<?= count($systemChecks) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="grid stats-grid">
        <div class="stat">
            <h3>Total Users</h3>
            <strong><?= (int) $userStats['total_users'] ?></strong>
        </div>

        <div class="stat">
            <h3>Deactivated Users</h3>
            <strong><?= (int) $userStats['inactive_users'] ?></strong>
        </div>

        <a class="stat stat-link" href="<?= BASE_URL ?>/admin/account-requests.php">
            <h3>Account Requests</h3>
            <strong><?= (int) $accountRequestStats['pending_requests'] ?></strong>
        </a>

        <div class="stat">
            <h3>Password Change Required</h3>
            <strong><?= (int) $userStats['password_change_required'] ?></strong>
        </div>
    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Quick Actions</h2>
                <p class="muted">Open the main administration areas for account, setup, security, and deployment checks.</p>
            </div>
        </div>

        <div class="actions">
            <a class="btn" href="<?= BASE_URL ?>/users/index.php">Manage Users</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/units/index.php">Manage Offices</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/categories/index.php">Manage Categories</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/logs/index.php">Activity Logs</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/health.php">System Maintenance</a>
        </div>
    </div>

    <div class="grid dashboard-grid">
        <div class="card">
            <h2>System Setup</h2>

            <div class="metric-stack">
                <div class="metric-row">
                    <span>Active Offices</span>
                    <strong><?= (int) $officeStats['active_offices'] ?> / <?= (int) $officeStats['total_offices'] ?></strong>
                </div>

                <div class="metric-row">
                    <span>Active Categories</span>
                    <strong><?= (int) $categoryStats['active_categories'] ?> / <?= (int) $categoryStats['total_categories'] ?></strong>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>System Readiness</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Check</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($systemChecks as $check): ?>
                            <tr>
                                <td><strong><?= e($check['label']) ?></strong></td>
                                <td><span class="<?= $check['ok'] ? 'badge success' : 'badge danger' ?>"><?= $check['ok'] ? 'Ready' : 'Needs Attention' ?></span></td>
                                <td><?= e($check['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- <div class="card">
        <div class="section-heading">
            <div>
                <h2>Document Overview</h2>
                <p class="muted">A secondary campus-wide count of document records, separate from daily workflow queues.</p>
            </div>
        </div>

        <div class="metric-stack">
            <div class="metric-row">
                <span>Active Documents</span>
                <strong><?= (int) $documentOverview['active_docs'] ?></strong>
            </div>

            <div class="metric-row">
                <span>Completed for Archive</span>
                <strong><?= (int) $documentOverview['completed_pending_archive'] ?></strong>
            </div>

            <div class="metric-row">
                <span>Archived Documents</span>
                <strong><?= (int) $documentOverview['archived_docs'] ?></strong>
            </div>
        </div>
    </div> -->

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Recent Activities</h2>
                <p class="muted">Latest security, account, setup, and document actions recorded by the system.</p>
            </div>
            <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/logs/index.php">View All</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentLogs): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">No audit activity has been recorded yet.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?= e(format_datetime($log['created_at'])) ?></td>
                                <td>
                                    <strong><?= e($log['actor_name'] ?? 'System') ?></strong>
                                    <div class="muted"><?= e($log['actor_uid'] ?: ($log['actor_email'] ?? 'No linked account')) ?></div>
                                </td>
                                <td><?= e(audit_log_label((string) $log['action'])) ?></td>
                                <td><?= e(audit_log_label((string) $log['entity_type'])) ?><?= $log['entity_id'] !== null ? ' #' . (int) $log['entity_id'] : '' ?></td>
                                <td><div class="log-details"><?= nl2br(e(format_audit_log_details($log['details']))) ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    return;
}

$fetchDashboardDocuments = static function (string $whereSql, array $params, string $orderSql, int $limit): array {
    $stmt = db()->prepare(
        'SELECT d.id,
                d.tracking_no,
                d.document_number,
                d.document_name,
                d.document_person_name,
                d.subject,
                d.received_date,
                d.status,
                c.name AS category_name,
                o_current.name AS current_office_name,
                o_origin.name AS origin_office_name
         FROM documents d
         LEFT JOIN document_categories c ON c.id = d.category_id
         LEFT JOIN offices o_current ON o_current.id = d.current_office_id
         LEFT JOIN offices o_origin ON o_origin.id = d.origin_office_id
         WHERE d.deleted_at IS NULL ' . $whereSql . '
         ORDER BY ' . $orderSql . '
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
};

$fetchLocationUpdates = static function (?int $officeId = null, bool $recordsOfficeCategoriesOnly = false, ?int $creatorUserId = null, ?string $documentStatus = null): array {
    if (!table_exists('document_timeline_events')) {
        return [];
    }

    $sql = 'SELECT dte.*,
                   d.tracking_no,
                   d.subject,
                   u.full_name AS actor_name,
                   actor_office.name AS actor_office_name,
                   counterparty_office.name AS counterparty_office_name
            FROM document_timeline_events dte
            INNER JOIN documents d ON d.id = dte.document_id
            LEFT JOIN users u ON u.id = dte.actor_user_id
            LEFT JOIN offices actor_office ON actor_office.id = dte.actor_office_id
            LEFT JOIN offices counterparty_office ON counterparty_office.id = dte.counterparty_office_id
            WHERE d.deleted_at IS NULL
              AND dte.event_type = "location_updated"';
    $params = [];

    if ($officeId !== null) {
        $sql .= ' AND (
                    dte.actor_office_id = :actor_office_id
                    OR dte.counterparty_office_id = :counterparty_office_id
                    OR d.current_office_id = :current_office_id
                 )';
        $params['actor_office_id'] = $officeId;
        $params['counterparty_office_id'] = $officeId;
        $params['current_office_id'] = $officeId;
    }

    if ($creatorUserId !== null) {
        $sql .= ' AND d.created_by = :creator_user_id';
        $params['creator_user_id'] = $creatorUserId;
    }

    if ($documentStatus !== null) {
        $sql .= ' AND d.status = :document_status';
        $params['document_status'] = $documentStatus;
    }

    if ($recordsOfficeCategoriesOnly) {
        $recordsCategoryScope = records_office_archive_category_condition('d', 'location_records_category_');
        $sql .= ' AND ' . $recordsCategoryScope['sql'];
        $params = array_merge($params, $recordsCategoryScope['params']);
    }

    $sql .= ' ORDER BY dte.created_at DESC, dte.id DESC LIMIT 8';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
};

$countOfficeCreatedDocuments = static function (?int $officeId): int {
    if ($officeId === null) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM documents
         WHERE deleted_at IS NULL
           AND origin_office_id = :office_id'
    );
    $stmt->execute(['office_id' => $officeId]);

    return (int) $stmt->fetchColumn();
};

$countOfficeArchivedDocuments = static function (?int $officeId): int {
    if ($officeId === null) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM documents
         WHERE deleted_at IS NULL
           AND status = "Archived"
           AND origin_office_id = :office_id'
    );
    $stmt->execute(['office_id' => $officeId]);

    return (int) $stmt->fetchColumn();
};

$countOfficeActiveDocuments = static function (?int $officeId): int {
    if ($officeId === null) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM documents
         WHERE deleted_at IS NULL
           AND status IN ("Under Action", "Completed")
           AND current_office_id = :office_id'
    );
    $stmt->execute(['office_id' => $officeId]);

    return (int) $stmt->fetchColumn();
};

if (($currentUser['role'] ?? '') === 'issuing_authority') {
    $officeId = current_office_id();
    $officeArchivedCount = $countOfficeArchivedDocuments($officeId);
    $officeActiveCount = $countOfficeActiveDocuments($officeId);
    $createdStats = [
        'total_created' => 0,
        'under_action_docs' => 0,
        'completed_docs' => 0,
        'archived_docs' => 0,
    ];
    $officeStatusStats = [
        'under_action_docs' => 0,
        'completed_docs' => 0,
    ];
    $pendingReviewCount = 0;
    if ($officeId !== null) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total_created,
                    COALESCE(SUM(CASE WHEN status = "Under Action" THEN 1 ELSE 0 END), 0) AS under_action_docs,
                    COALESCE(SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END), 0) AS completed_docs,
                    COALESCE(SUM(CASE WHEN status = "Archived" THEN 1 ELSE 0 END), 0) AS archived_docs
             FROM documents
             WHERE deleted_at IS NULL
               AND origin_office_id = :office_id'
        );
        $stmt->execute(['office_id' => $officeId]);
        $createdStats = $stmt->fetch() ?: $createdStats;

        $stmt = db()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN status = "Under Action" THEN 1 ELSE 0 END), 0) AS under_action_docs,
                    COALESCE(SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END), 0) AS completed_docs
             FROM documents
             WHERE deleted_at IS NULL
               AND current_office_id = :office_id'
        );
        $stmt->execute(['office_id' => $officeId]);
        $officeStatusStats = $stmt->fetch() ?: $officeStatusStats;

        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM documents
             WHERE deleted_at IS NULL
               AND status = "Submitted"
               AND origin_office_id = :office_id'
        );
        $stmt->execute(['office_id' => $officeId]);
        $pendingReviewCount = (int) $stmt->fetchColumn();
    }
    $officeSourceActiveCount = (int) $createdStats['under_action_docs'] + (int) $createdStats['completed_docs'];

    $recentCreated = $officeId !== null
        ? $fetchDashboardDocuments(
            'AND d.origin_office_id = :office_id',
            ['office_id' => $officeId],
            'd.created_at DESC, d.id DESC',
            6
        )
        : [];
    $underActionOfficeDocuments = $officeId !== null
        ? $fetchDashboardDocuments(
            'AND d.current_office_id = :office_id AND d.status = "Under Action"',
            ['office_id' => $officeId],
            'd.received_date ASC, d.created_at ASC',
            5
        )
        : [];
    $completedOfficeDocuments = $officeId !== null
        ? $fetchDashboardDocuments(
            'AND d.current_office_id = :office_id AND d.status = "Completed"',
            ['office_id' => $officeId],
            'd.updated_at DESC, d.created_at DESC',
            5
        )
        : [];

    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="card page-hero">
        <div class="page-hero-main">
            <div>
                <div class="hero-eyebrow">Issuing Authority Workspace</div>
                <h2>Welcome, <?= e($currentUser['full_name']) ?></h2>
                <p class="muted">Create official records, monitor documents from your office, and see which items are still under action or waiting for archive.</p>
            </div>

            <div class="hero-summary">
                <div class="hero-summary-item">
                    <span>Role</span>
                    <strong><?= e(role_label($currentUser['role'])) ?></strong>
                </div>

                <div class="hero-summary-item">
                    <span>Office</span>
                    <strong><?= e($currentUser['office_name'] ?: 'No assigned office') ?></strong>
                </div>

                <div class="hero-summary-item">
                    <span>Review Submissions</span>
                    <strong><?= (int) $pendingReviewCount ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="grid stats-grid">
        <div class="stat">
            <h3>Office Created Documents</h3>
            <strong><?= (int) $createdStats['total_created'] ?></strong>
        </div>

        <a class="stat stat-link" href="<?= BASE_URL ?>/documents/review.php">
            <h3>Review Submissions</h3>
            <strong><?= (int) $pendingReviewCount ?></strong>
        </a>

        <div class="stat">
            <h3>Office Active Documents</h3>
            <strong><?= (int) $officeSourceActiveCount ?></strong>
        </div>

        <div class="stat">
            <h3>Currently in Office</h3>
            <strong><?= (int) $officeActiveCount ?></strong>
        </div>

        <div class="stat">
            <h3>Office Under Action</h3>
            <strong><?= (int) $createdStats['under_action_docs'] ?></strong>
        </div>

        <div class="stat">
            <h3>Office Completed</h3>
            <strong><?= (int) $createdStats['completed_docs'] ?></strong>
        </div>

        <a class="stat stat-link" href="<?= BASE_URL ?>/documents/archive.php">
            <h3>Office Archived</h3>
            <strong><?= (int) $officeArchivedCount ?></strong>
        </a>

        <!-- <div class="stat">
            <h3>Archived</h3>
            <strong><?= (int) $createdStats['archived_docs'] ?></strong>
        </div> -->
    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Quick Actions</h2>
                <p class="muted">Register a new document or open your permitted document and QR tools.</p>
            </div>
        </div>

        <div class="actions">
            <a class="btn" href="<?= BASE_URL ?>/documents/create.php">Create Document</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/review.php">Review Submissions</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php">Office Documents</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/scan.php">Scan QR</a>
        </div>
    </div>

    <div class="grid dashboard-grid">
        <div class="card">
            <h2>Recent Office-Created Documents</h2>

            <div class="table-wrap mobile-card-wrap">
                <table class="mobile-card-table">
                    <thead>
                        <tr>
                            <th>Tracking No.</th>
                            <th>Document No.</th>
                            <th>Subject</th>
                            <th>Current Location</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentCreated): ?>
                            <tr><td colspan="5"><div class="empty-state">No office-created documents yet.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($recentCreated as $document): ?>
                                <tr>
                                    <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                                    <td data-label="Document No."><?= e($document['document_number'] ?? '-') ?></td>
                                    <td data-label="Subject"><?= e($document['subject']) ?></td>
                                    <td data-label="Current Location"><?= e($document['current_office_name'] ?? '-') ?></td>
                                    <td data-label="Status"><span class="<?= e(document_status_badge((string) $document['status'])) ?>"><?= e($document['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Office Document Status</h2>

            <div class="metric-stack">
                <div class="metric-row">
                    <span>Under Action in My Office</span>
                    <strong><?= (int) $officeStatusStats['under_action_docs'] ?></strong>
                </div>

                <div class="metric-row">
                    <span>Completed in My Office</span>
                    <strong><?= (int) $officeStatusStats['completed_docs'] ?></strong>
                </div>

                <div class="metric-row">
                    <span>Archived from My Office</span>
                    <strong><?= (int) $officeArchivedCount ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="grid dashboard-grid">
        <div class="card">
            <h2>Still Under Action in My Office</h2>

            <div class="table-wrap mobile-card-wrap">
                <table class="mobile-card-table">
                    <thead>
                        <tr>
                            <th>Tracking No.</th>
                            <th>Subject</th>
                            <th>Current Location</th>
                            <th>Date of Creation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$underActionOfficeDocuments): ?>
                            <tr><td colspan="4"><div class="empty-state">No under action documents are currently in your office.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($underActionOfficeDocuments as $document): ?>
                                <tr>
                                    <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                                    <td data-label="Subject"><?= e($document['subject']) ?></td>
                                    <td data-label="Current Location"><?= e($document['current_office_name'] ?? '-') ?></td>
                                    <td data-label="Date of Creation"><?= e($document['received_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Completed for Archive in My Office</h2>

            <div class="table-wrap mobile-card-wrap">
                <table class="mobile-card-table">
                    <thead>
                        <tr>
                            <th>Tracking No.</th>
                            <th>Subject</th>
                            <th>Date of Creation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$completedOfficeDocuments): ?>
                            <tr><td colspan="3"><div class="empty-state">No completed documents are currently in your office for archive.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($completedOfficeDocuments as $document): ?>
                                <tr>
                                    <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                                    <td data-label="Subject"><?= e($document['subject']) ?></td>
                                    <td data-label="Date of Creation"><?= e($document['received_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    return;
}

if (($currentUser['role'] ?? '') === 'office_staff') {
    $currentUserId = (int) $currentUser['id'];
    $officeId = current_office_id();
    $officeDocumentCount = $countOfficeCreatedDocuments($officeId);
    $officeArchivedCount = $countOfficeArchivedDocuments($officeId);
    $officeActiveCount = $countOfficeActiveDocuments($officeId);
    $officeStats = [
        'current_docs' => 0,
        'under_action_docs' => 0,
        'completed_docs' => 0,
    ];
    $workflowStats = [
        'draft_docs' => 0,
        'submitted_docs' => 0,
        'rejected_docs' => 0,
    ];
    $officeActiveDocuments = [];

    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN status = "Draft" THEN 1 ELSE 0 END), 0) AS draft_docs,
                COALESCE(SUM(CASE WHEN status = "Submitted" THEN 1 ELSE 0 END), 0) AS submitted_docs,
                COALESCE(SUM(CASE WHEN status = "Rejected" THEN 1 ELSE 0 END), 0) AS rejected_docs
         FROM documents
         WHERE deleted_at IS NULL
           AND created_by = :user_id'
    );
    $stmt->execute(['user_id' => $currentUserId]);
    $workflowStats = $stmt->fetch() ?: $workflowStats;

    if ($officeId !== null) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS current_docs,
                    COALESCE(SUM(CASE WHEN status = "Under Action" THEN 1 ELSE 0 END), 0) AS under_action_docs,
                    COALESCE(SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END), 0) AS completed_docs
             FROM documents
             WHERE deleted_at IS NULL
               AND status NOT IN ("Archived", "Draft", "Submitted", "Rejected")
               AND origin_office_id = :office_id'
        );
        $stmt->execute(['office_id' => $officeId]);
        $officeStats = $stmt->fetch() ?: $officeStats;
    }

    $officeActiveDocuments = $officeId !== null
        ? $fetchDashboardDocuments(
            'AND d.current_office_id = :office_id AND d.status IN ("Under Action", "Completed")',
            ['office_id' => $officeId],
            'd.received_date ASC, d.created_at ASC',
            6
        )
        : [];

    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="card page-hero">
        <div class="page-hero-main">
            <div>
                <div class="hero-eyebrow">Office Document Desk</div>
                <h2>Welcome, <?= e($currentUser['full_name']) ?></h2>
                <p class="muted">Monitor the active hardcopy records from your office and keep physical location updates.</p>
            </div>

            <div class="hero-summary">
                <div class="hero-summary-item">
                    <span>Role</span>
                    <strong><?= e(role_label($currentUser['role'])) ?></strong>
                </div>

                <div class="hero-summary-item">
                    <span>Office</span>
                    <strong><?= e($currentUser['office_name'] ?: 'No assigned office') ?></strong>
                </div>

                <div class="hero-summary-item">
                    <span>Office Active</span>
                    <strong><?= (int) $officeStats['current_docs'] ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="grid stats-grid">
        <div class="stat">
            <h3>Office Active Documents</h3>
            <strong><?= (int) $officeStats['current_docs'] ?></strong>
        </div>

        <div class="stat">
            <h3>Office Created Documents</h3>
            <strong><?= (int) $officeDocumentCount ?></strong>
        </div>

        <div class="stat">
            <h3>Currently in Office</h3>
            <strong><?= (int) $officeActiveCount ?></strong>
        </div>

        <a class="stat stat-link" href="<?= BASE_URL ?>/documents/index.php?status=Draft">
            <h3>My Drafts</h3>
            <strong><?= (int) $workflowStats['draft_docs'] ?></strong>
        </a>

        <a class="stat stat-link" href="<?= BASE_URL ?>/documents/index.php?status=Submitted">
            <h3>My Submitted</h3>
            <strong><?= (int) $workflowStats['submitted_docs'] ?></strong>
        </a>

        <a class="stat stat-link" href="<?= BASE_URL ?>/documents/index.php?status=Rejected">
            <h3>My Rejected</h3>
            <strong><?= (int) $workflowStats['rejected_docs'] ?></strong>
        </a>

        <div class="stat">
            <h3>Office Under Action</h3>
            <strong><?= (int) $officeStats['under_action_docs'] ?></strong>
        </div>

        <div class="stat">
            <h3>Office Completed</h3>
            <strong><?= (int) $officeStats['completed_docs'] ?></strong>
        </div>

        <a class="stat stat-link" href="<?= BASE_URL ?>/documents/archive.php">
            <h3>Office Archived</h3>
            <strong><?= (int) $officeArchivedCount ?></strong>
        </a>

    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Quick Actions</h2>
                <p class="muted">Open your document list or scan a QR code to verify and update physical location.</p>
            </div>
        </div>

        <div class="actions">
            <a class="btn" href="<?= BASE_URL ?>/documents/create.php">Create Draft</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php?status=Draft">Drafts</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php?status=Submitted">Submitted</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php?status=Rejected">Rejected</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php">Office Documents</a>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/scan.php">Scan QR</a>
        </div>
    </div>

    <div class="grid dashboard-grid">
        <div class="card">
            <h2>Oldest Active Documents in My Office</h2>

            <div class="table-wrap mobile-card-wrap">
                <table class="mobile-card-table">
                    <thead>
                        <tr>
                            <th>Tracking No.</th>
                            <th>Document No.</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Date of Creation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$officeActiveDocuments): ?>
                            <tr><td colspan="5"><div class="empty-state">No active documents are currently in your office.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($officeActiveDocuments as $document): ?>
                                <tr>
                                    <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                                    <td data-label="Document No."><?= e($document['document_number'] ?? '-') ?></td>
                                    <td data-label="Subject"><?= e($document['subject']) ?></td>
                                    <td data-label="Status"><span class="<?= e(document_status_badge((string) $document['status'])) ?>"><?= e($document['status']) ?></span></td>
                                    <td data-label="Date of Creation"><?= e($document['received_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Office Workload</h2>

            <div class="metric-stack">
                <div class="metric-row">
                    <span>Under Action</span>
                    <strong><?= (int) $officeStats['under_action_docs'] ?></strong>
                </div>

                <div class="metric-row">
                    <span>Completed</span>
                    <strong><?= (int) $officeStats['completed_docs'] ?></strong>
                </div>

            </div>
        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    return;
}

$scope = document_access_condition('d', 'scope_');
$recordsDashboardScope = records_office_archive_category_condition('d', 'dashboard_records_category_');
$statsSql = 'SELECT
                COUNT(*) AS active_docs,
                COUNT(*) AS completed_pending_archive
             FROM documents d
             WHERE d.deleted_at IS NULL
               AND d.status = "Completed"
               AND ' . $recordsDashboardScope['sql'] . $scope['sql'];

$stmt = db()->prepare($statsSql);
$stmt->execute(array_merge($recordsDashboardScope['params'], $scope['params']));

$stats = $stmt->fetch() ?: [
    'active_docs' => 0,
    'completed_pending_archive' => 0,
];

$archiveStmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN d.status = "Archived" THEN 1 ELSE 0 END), 0) AS total_archived,
        COALESCE(SUM(
            CASE
                WHEN d.status = "Archived"
                 AND d.archived_at IS NOT NULL
                 AND YEAR(d.archived_at) = YEAR(CURDATE())
                 AND MONTH(d.archived_at) = MONTH(CURDATE())
                THEN 1
                ELSE 0
            END
        ), 0) AS archived_this_month
     FROM documents d
     WHERE d.deleted_at IS NULL
       AND ' . $recordsDashboardScope['sql'] . $scope['sql']
);

$archiveStmt->execute(array_merge($recordsDashboardScope['params'], $scope['params']));

$archiveVolume = $archiveStmt->fetch() ?: [
    'total_archived' => 0,
    'archived_this_month' => 0,
];

$completedForArchiveStmt = db()->prepare(
    'SELECT d.id,
            d.tracking_no,
            d.document_number,
            d.document_person_name,
            d.subject,
            d.received_date,
            o_current.name AS current_office_name,
            d.status
     FROM documents d
     LEFT JOIN offices o_current ON o_current.id = d.current_office_id
     WHERE d.deleted_at IS NULL
       AND d.status = "Completed"
       AND ' . $recordsDashboardScope['sql'] . $scope['sql'] . '
     ORDER BY d.updated_at ASC, d.received_date ASC, d.created_at ASC
     LIMIT 5'
);

$completedForArchiveStmt->execute(array_merge($recordsDashboardScope['params'], $scope['params']));
$completedForArchive = $completedForArchiveStmt->fetchAll();
$locationUpdates = $fetchLocationUpdates(null, true, null, 'Completed');
$recordsWorkloadScope = records_office_archive_category_condition('d', 'dashboard_workload_category_');
$workloadStmt = db()->prepare(
    'SELECT o.name,
            COUNT(d.id) AS document_count
     FROM offices o
     LEFT JOIN documents d
           ON d.current_office_id = o.id
           AND d.deleted_at IS NULL
           AND d.status = "Completed"
           AND ' . $recordsWorkloadScope['sql'] . '
     GROUP BY o.id, o.name
     ORDER BY document_count DESC, o.name ASC'
);
$workloadStmt->execute($recordsWorkloadScope['params']);
$workload = $workloadStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';

?>

<div class="card page-hero">
    <div class="page-hero-main">
        <div>
            <div class="hero-eyebrow">Records Office Control</div>
            <h2>Welcome, <?= e($currentUser['full_name']) ?></h2>
            <p class="muted">Monitor archive readiness, completed records, and recent location updates.</p>
        </div>

        <div class="hero-summary">
            <div class="hero-summary-item">
                <span>Role</span>
                <strong><?= e(role_label($currentUser['role'])) ?></strong>
            </div>

            <div class="hero-summary-item">
                <span>Office</span>
                <strong><?= e($currentUser['office_name'] ?: 'Campus-wide access') ?></strong>
            </div>

            <div class="hero-summary-item">
                <span>For Archive</span>
                <strong><?= (int) $stats['completed_pending_archive'] ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="grid stats-grid">
    <!-- <div class="stat">
        <h3>Completed for Archive</h3>
        <strong><a href="<?= BASE_URL ?>/documents/index.php?status=Completed"><?= (int) $stats['completed_pending_archive'] ?></a></strong>
    </div> -->

    <a class="stat stat-link" href="<?= BASE_URL ?>/documents/index.php?status=Completed">
        <h3>Completed for Archive</h3>
        <strong><?= (int) $stats['completed_pending_archive'] ?></strong>
    </a>

    <div class="stat">
        <h3>Archived This Month</h3>
        <strong><?= (int) $archiveVolume['archived_this_month'] ?></strong>
    </div>

    <div class="stat">
        <h3>Total Archived</h3>
        <strong><?= (int) $archiveVolume['total_archived'] ?></strong>
    </div>
</div>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Quick Actions</h2>
            <p class="muted">Open records intake, archive queues, archive search, or QR scan tools.</p>
        </div>
    </div>

    <div class="actions">
        <a class="btn" href="<?= BASE_URL ?>/documents/archive-intake.php">Archive Intake</a>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/index.php?status=Completed">For Archiving</a>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/archive.php">Archived Records</a>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/documents/scan.php">Scan QR</a>
    </div>
</div>

<div class="grid dashboard-grid">
    <div class="card">
        <h2>Oldest Completed Documents Waiting for Archive</h2>

        <div class="table-wrap mobile-card-wrap">
            <table class="mobile-card-table">
                <thead>
                    <tr>
                        <th>Tracking No.</th>
                        <th>Document No.</th>
                        <!-- <th>Name in Document</th> -->
                        <th>Subject</th>
                        <th>Current Location</th>
                        <th>Date of Creation</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$completedForArchive): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">No completed documents are waiting for archive.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($completedForArchive as $document): ?>
                            <tr>
                                <td data-label="Tracking No."><a href="<?= BASE_URL ?>/documents/view.php?id=<?= (int) $document['id'] ?>"><?= e($document['tracking_no']) ?></a></td>
                                <td data-label="Document No."><?= e($document['document_number'] ?? '-') ?></td>
                                <!-- <td data-label="Name in Document"><?= e($document['document_person_name'] ?? '-') ?></td> -->
                                <td data-label="Subject"><?= e($document['subject']) ?></td>
                                <td data-label="Current Location"><?= e($document['current_office_name'] ?? '-') ?></td>
                                <td data-label="Date of Creation"><?= e($document['received_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Archive Volume</h2>

        <div class="metric-stack">
            <div class="metric-row">
                <span>Total Archived Records</span>
                <strong><?= (int) $archiveVolume['total_archived'] ?></strong>
            </div>

            <div class="metric-row">
                <span>Archived This Month</span>
                <strong><?= (int) $archiveVolume['archived_this_month'] ?></strong>
            </div>

            <div class="metric-row">
                <span>Archive-Ready Records</span>
                <strong><?= (int) $stats['active_docs'] ?></strong>
            </div>
        </div>
    </div>
</div>

    <div class="card">
        <h2>Archive Location Summary</h2>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Office</th>
                    <th>Completed Records</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$workload): ?>
                    <tr>
                        <td colspan="2">
                            <div class="empty-state">No archive-ready location data is available yet.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workload as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?></td>
                            <td><?= (int) $row['document_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

    <div class="card">
        <h2>Recent Location Updates</h2>

    <div class="table-wrap mobile-card-wrap">
        <table class="mobile-card-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Subject</th>
                    <th>Updated By</th>
                    <th>Actor Office</th>
                    <th>New Location</th>
                    <th>Remarks</th>
                    <th>Time</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$locationUpdates): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                Location updates will appear here when QR scans or document records are used to update physical location.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($locationUpdates as $event): ?>
                        <tr>
                            <td data-label="Tracking No."><?= e($event['tracking_no']) ?></td>
                            <td data-label="Subject"><?= e($event['subject']) ?></td>
                            <td data-label="Updated By"><?= e($event['actor_name'] ?? 'System') ?></td>
                            <td data-label="Actor Office"><?= e($event['actor_office_name'] ?? '-') ?></td>
                            <td data-label="New Location"><?= e($event['counterparty_office_name'] ?? '-') ?></td>
                            <td data-label="Remarks"><?= e($event['remarks'] ?? '-') ?></td>
                            <td data-label="Time"><?= e(format_datetime($event['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
