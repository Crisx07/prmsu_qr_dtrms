<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if (!system_is_ready()) {
    set_flash('error', database_unavailable_message());
    redirect('/auth/login.php');
}

if (current_user()) {
    redirect('/dashboard.php');
}

$recoveryReset = verified_recovery_reset();
if (!is_array($recoveryReset)) {
    set_flash('error', 'Start account recovery again to reset your password.');
    redirect('/auth/forgot_password.php');
}

$pageTitle = 'Reset Password';
$pagePath = '/auth/reset_password.php';
$errors = [];
$user = load_user_by_id((int) ($recoveryReset['user_id'] ?? 0));

if ($user === null || (int) $user['is_active'] !== 1) {
    clear_verified_recovery_reset();
    set_flash('error', 'Start account recovery again to reset your password.');
    redirect('/auth/forgot_password.php');
}

if (is_post()) {
    require_csrf($pagePath);

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < password_min_length()) {
        $errors[] = 'New password must be at least ' . password_min_length() . ' characters.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 must_change_password = 0
             WHERE id = :id'
        );
        $stmt->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $user['id'],
        ]);

        consume_email_otp((int) ($recoveryReset['challenge_id'] ?? 0));
        clear_verified_recovery_reset();
        audit_log('recovery_password_reset', 'user', (int) $user['id'], ['email' => $user['email']]);
        set_flash('success', 'Password reset successfully. You can now log in.');
        redirect('/auth/login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/prmsu-logo.png">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="brand-logo-pair">
                <?php foreach (app_brand_logos() as $logo): ?>
                    <img class="brand-logo auth-logo" src="<?= e($logo['src']) ?>" alt="<?= e($logo['alt']) ?>">
                <?php endforeach; ?>
            </div>
            <div class="auth-brand-copy">
                <div class="auth-eyebrow">Recovery Complete</div>
                <h1><?= e(APP_NAME) ?></h1>
                <p class="muted">Set a new password for <?= e(masked_email((string) $user['email'])) ?>.</p>
            </div>
        </div>
        <?php if ($flash = get_flash()): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>

            <label for="new_password">New Password</label>
            <div class="password-field">
                <input type="password" name="new_password" id="new_password" required>
                <button class="btn btn-secondary btn-sm password-toggle" type="button" data-password-toggle data-password-target="#new_password" aria-pressed="false">Show</button>
            </div>

            <label for="confirm_password">Confirm New Password</label>
            <div class="password-field">
                <input type="password" name="confirm_password" id="confirm_password" required>
                <button class="btn btn-secondary btn-sm password-toggle" type="button" data-password-toggle data-password-target="#confirm_password" aria-pressed="false">Show</button>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Reset Password</button>
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/auth/login.php">Cancel</a>
            </div>
        </form>
        <p class="muted auth-footer-note">This reset will replace your current password immediately.</p>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= filemtime(ROOT_PATH . '/assets/js/app.js') ?>"></script>
</body>
</html>
