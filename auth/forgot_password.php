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

$pageTitle = 'Account Recovery';
$errors = [];
$neutralMessage = 'If an active account matches that User ID and email address, we sent a verification code.';

if (is_post()) {
    require_csrf('/auth/forgot_password.php');

    $userUid = normalize_user_uid(input_string($_POST['user_uid'] ?? ''));
    $email = input_string($_POST['email'] ?? '');
    $rateLimitIdentity = $userUid . '|' . strtolower($email);
    $remaining = auth_rate_limit_remaining_seconds('recovery', $rateLimitIdentity);

    if ($remaining > 0) {
        $errors[] = auth_rate_limit_message($remaining);
    } elseif (!auth_otp_enabled()) {
        $errors[] = 'Account recovery is unavailable until recovery email OTP is enabled and SMTP is configured.';
    } elseif ($userUid === '') {
        $errors[] = 'Enter your User ID.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } else {
        auth_rate_limit_record_failure('recovery', $rateLimitIdentity);
        $user = load_user_by_uid_any_status($userUid);
        $emailMatchesUser = $user && strcasecmp((string) $user['email'], $email) === 0;

        if (!$emailMatchesUser || (int) $user['is_active'] !== 1) {
            audit_log('recovery_otp_not_sent', 'user', $user ? (int) $user['id'] : null, [
                'user_uid' => $userUid,
                'email_match' => (bool) $emailMatchesUser,
                'active' => $user ? (int) $user['is_active'] === 1 : false,
            ]);
            set_flash('success', $neutralMessage);
            redirect('/auth/forgot_password.php');
        } else {
            $challenge = null;

            try {
                $challenge = create_email_otp($user, 'recovery');
                send_email_otp($user, 'recovery', (string) $challenge['plain_code']);
                set_pending_recovery_otp((string) $user['email'], (int) $user['id'], (int) $challenge['id'], true, $rateLimitIdentity);
                audit_log('send_recovery_otp', 'user', (int) $user['id'], [
                    'user_uid' => $user['user_uid'] ?? null,
                    'email' => $user['email'],
                ]);
                set_flash('success', $neutralMessage);
                redirect('/auth/verify_otp.php');
            } catch (Throwable $e) {
                if (is_array($challenge) && isset($challenge['id'])) {
                    consume_email_otp((int) $challenge['id']);
                }

                clear_pending_recovery_otp();
                audit_log('send_recovery_otp_failed', 'user', (int) $user['id'], [
                    'user_uid' => $user['user_uid'] ?? null,
                    'email' => $user['email'],
                    'error' => $e->getMessage(),
                ]);
                set_flash('success', $neutralMessage);
                redirect('/auth/forgot_password.php');
            }
        }
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
                <div class="auth-eyebrow">Account Recovery</div>
                <h1><?= e(APP_NAME) ?></h1>
                <p class="muted">Enter your User ID and email address. We will send a one-time code if both match an account.</p>
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

            <label for="user_uid">User ID</label>
            <input type="text" name="user_uid" id="user_uid" value="<?= e(old('user_uid')) ?>" required>

            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" value="<?= e(old('email')) ?>" required>

            <button class="btn" type="submit">Send Recovery Code</button>
            <div class="auth-support">
                <a class="auth-link" href="<?= BASE_URL ?>/auth/login.php">Back to login</a>
            </div>
        </form>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= filemtime(ROOT_PATH . '/assets/js/app.js') ?>"></script>
</body>
</html>
