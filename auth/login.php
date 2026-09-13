<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$dbStatus = database_status();
$dbReady = $dbStatus['ready'];

if ($dbReady && current_user()) {
    if ((int) (current_user()['must_change_password'] ?? 0) === 1) {
        redirect('/auth/change_password.php');
    }

    redirect('/dashboard.php');
}

$errors = [];

if (!$dbReady) {
    $errors[] = database_unavailable_message($dbStatus);
} elseif (is_post()) {
    require_csrf('/auth/login.php');

    $userUid = normalize_user_uid(input_string($_POST['user_uid'] ?? ''));
    $password = $_POST['password'] ?? '';
    $remaining = auth_rate_limit_remaining_seconds('login', $userUid);

    if ($remaining > 0) {
        $errors[] = auth_rate_limit_message($remaining);
    }

    $user = !$errors ? load_user_by_uid_any_status($userUid) : null;

    if (!$errors && $user && password_verify($password, $user['password_hash'])) {
        if ((int) $user['is_active'] !== 1) {
            auth_rate_limit_record_failure('login', $userUid);
            $errors[] = deactivated_account_message();
        } else {
            login_user($user);
            auth_rate_limit_clear('login', $userUid);
            audit_log('login', 'user', (int) $user['id'], [
                'user_uid' => $user['user_uid'] ?? null,
                'email' => $user['email'],
            ]);

            if ((int) $user['must_change_password'] === 1) {
                redirect('/auth/change_password.php');
            }

            redirect('/dashboard.php');
        }
    } elseif (!$errors) {
        auth_rate_limit_record_failure('login', $userUid);
        $errors[] = 'Invalid User ID or Password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
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
                <div class="auth-eyebrow">PRMSU Iba Main Campus</div>
                <h1><?= e(APP_NAME) ?></h1>
                <p class="muted">Log in to manage documents, archives, and campus records.</p>
            </div>
        </div>
        <?php if ($flash = get_flash()): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($dbReady): ?>
            <form method="post">
                <?= csrf_field() ?>

                <label for="user_uid">User ID</label>
                <input type="text" name="user_uid" id="user_uid" value="<?= e(old('user_uid')) ?>" required>

                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" name="password" id="password" required>
                    <button class="btn btn-secondary btn-sm password-toggle" type="button" data-password-toggle data-password-target="#password" aria-pressed="false">Show</button>
                </div>

                <button class="btn" type="submit">Login</button>
                <div class="auth-support">
                    <a class="auth-link" href="<?= BASE_URL ?>/auth/request_account.php">Request an Account</a>
                    <!-- <span class="auth-link-separator">|</span> -->
                    <a class="auth-link" href="<?= BASE_URL ?>/auth/forgot_password.php">Forgot password?</a>
                </div>
            </form>
        <?php else: ?>
            <p class="muted">Sign-in is unavailable until the database is ready.</p>
        <?php endif; ?>
        <p class="muted auth-footer-note">Secure access for documents tracking, archiving, and reporting operations.</p>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= filemtime(ROOT_PATH . '/assets/js/app.js') ?>"></script>
</body>
</html>
