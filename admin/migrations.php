<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$pageTitle = 'Migrations';
$pagePath = '/admin/migrations.php';
$errors = [];
$ran = [];

if (is_post()) {
    require_csrf($pagePath);

    try {
        ensure_database_schema(db());
        $ran = run_pending_schema_migrations(db());
        set_flash('success', $ran ? 'Migrations applied: ' . implode(', ', $ran) . '.' : 'Database schema is already up to date.');
        redirect($pagePath);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$pending = [];
$dbStatus = database_status();
try {
    $pending = pending_schema_migrations();
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card page-hero page-hero-compact">
    <div class="page-hero-main">
        <div>
            <div class="hero-eyebrow">Administrator Schema Control</div>
            <h2>Migrations</h2>
            <p class="muted">Run schema maintenance deliberately before setting <code>MIGRATIONS_AUTO_RUN=false</code> in production.</p>
        </div>
        <div class="hero-summary">
            <div class="hero-summary-item">
                <span>Auto Run</span>
                <strong><?= MIGRATIONS_AUTO_RUN ? 'Enabled' : 'Disabled' ?></strong>
            </div>
            <div class="hero-summary-item">
                <span>Pending</span>
                <strong><?= count($pending) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <div class="section-heading">
        <div>
            <h2>Pending Migrations</h2>
            <p class="muted">This includes hardening tables, full-text search, and scan-log workflow additions.</p>
        </div>
    </div>

    <?php if (!$dbStatus['connected']): ?>
        <div class="alert error"><?= e(database_unavailable_message($dbStatus)) ?></div>
    <?php elseif (!$pending): ?>
        <div class="empty-state">No pending migrations.</div>
    <?php else: ?>
        <ul class="plain-list">
            <?php foreach ($pending as $migration): ?>
                <li><code><?= e($migration) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" class="actions">
        <?= csrf_field() ?>
        <button class="btn" type="submit" data-confirm="Run pending database migrations now? Take a backup first on production systems.">Run Migrations</button>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/health.php">Maintenance</a>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
