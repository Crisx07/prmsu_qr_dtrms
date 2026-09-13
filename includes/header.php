<?php
$flash = get_flash();
$user = current_user();
$changePasswordRequired = $user && (int) ($user['must_change_password'] ?? 0) === 1;
$changePasswordActive = current_path() === BASE_URL . '/auth/change_password.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(ROOT_PATH . '/assets/css/style.css') ?>">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/prmsu-logo.png">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="app-sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo-pair">
                <?php foreach (app_brand_logos() as $logo): ?>
                    <img class="brand-logo sidebar-logo" src="<?= e($logo['src']) ?>" alt="<?= e($logo['alt']) ?>">
                <?php endforeach; ?>
            </div>
            <div class="sidebar-brand-copy">
                <h1><?= e(APP_NAME) ?></h1>
                <p class="sidebar-subtitle">Campus-wide document QR tracking, location updates, archiving, and reporting.</p>
            </div>
        </div>

        <?php if ($user): ?>
            <nav class="nav">
                <div class="nav-group">
                    <div class="nav-group-label">Workspace</div>
                    <a class="<?= nav_is_active(['/dashboard.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
                    <?php if (can_access_document_module()): ?>
                        <?php if (can_archive_intake_documents()): ?>
                            <a class="<?= nav_is_active(['/documents/archive-intake.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/archive-intake.php">Archive Intake</a>
                            <a class="<?= nav_is_active(['/documents/index.php', '/documents/view.php']) && ($_GET['status'] ?? '') === 'Completed' ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/index.php?status=Completed">For Archiving</a>
                            <a class="<?= nav_is_active(['/documents/archive.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/archive.php">Archived</a>
                        <?php else: ?>
                            <a class="<?= nav_is_active(['/documents/index.php', '/documents/view.php', '/documents/edit.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/index.php">Documents</a>
                            <?php if (has_role('issuing_authority')): ?>
                                <a class="<?= nav_is_active(['/documents/review.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/review.php">Review Submissions</a>
                            <?php endif; ?>
                            <?php if (can_register_documents()): ?>
                                <a class="<?= nav_is_active(['/documents/create.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/create.php"><?= has_role('office_staff') ? 'Create Draft' : 'Create Document' ?></a>
                            <?php endif; ?>
                            <a class="<?= nav_is_active(['/documents/scan.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/scan.php">Scan QR</a>
                            <a class="<?= nav_is_active(['/documents/archive.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/documents/archive.php">Archived</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (can_view_reports()): ?>
                        <a class="<?= nav_is_active(['/reports/']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/reports/index.php">Reports</a>
                    <?php endif; ?>
                </div>
                <?php if (can_manage_system_settings()): ?>
                    <div class="nav-group">
                        <div class="nav-group-label">Administration</div>
                        <a class="<?= nav_is_active(['/units/', '/offices/']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/units/index.php">Offices</a>
                        <a class="<?= nav_is_active(['/categories/']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/categories/index.php">Categories</a>
                        <a class="<?= nav_is_active(['/users/']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/users/index.php">Users</a>
                        <a class="<?= nav_is_active(['/admin/account-requests.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/account-requests.php">Account Requests</a>
                        <!-- <a class="<?= nav_is_active(['/admin/backups.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/backups.php">Backups</a>
                        <a class="<?= nav_is_active(['/admin/migrations.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/migrations.php">Migrations</a> -->
                        <a class="<?= nav_is_active(['/logs/']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/logs/index.php">Activity Logs</a>
                        <a class="<?= nav_is_active(['/admin/health.php']) ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/health.php">Maintenance</a>
                    </div>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </aside>

    <main class="main-content">
        <header class="topbar no-print">
            <div class="topbar-left">
                <?php if ($user): ?>
                    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Toggle navigation" aria-controls="app-sidebar" aria-expanded="false">Menu</button>
                <?php endif; ?>
                <div class="topbar-page">
                    <div class="topbar-kicker">PRMSU Iba Campus Documents Tracking</div>
                    <strong class="topbar-title"><?= e($pageTitle ?? APP_NAME) ?></strong>
                    <?php if ($user && !empty($user['office_name'])): ?>
                        <div class="topbar-subtitle"><?= e($user['office_name']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($user): ?>
                <div class="topbar-right">
                    <div class="user-pill">
                        <strong><?= e($user['full_name']) ?></strong>
                        <span><?= e(role_label($user['role'])) ?></span>
                    </div>
                    <a
                        class="account-link<?= $changePasswordActive ? ' active' : '' ?><?= $changePasswordRequired ? ' required' : '' ?>"
                        href="<?= BASE_URL ?>/auth/change_password.php"
                    >
                        Change Password
                    </a>
                    <form method="post" action="<?= BASE_URL ?>/auth/logout.php" class="inline" data-confirm="Are you sure you want to log out?">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-secondary" type="submit">Logout</button>
                    </form>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($flash): ?>
            <div class="alert <?= e($flash['type']) ?> no-print"><?= e($flash['message']) ?></div>
        <?php endif; ?>
