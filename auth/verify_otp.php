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

$recoveryState = pending_recovery_otp();
if (!is_array($recoveryState)) {
    set_flash('error', 'There is no verification request to complete.');
    redirect('/auth/login.php');
}

if (!auth_otp_enabled()) {
    clear_pending_login_otp();
    clear_pending_recovery_otp();
    clear_verified_recovery_reset();
    set_flash('error', 'Account recovery email verification is currently disabled. Please start again.');
    redirect('/auth/login.php');
}

$pageTitle = 'Verify Recovery Code';
$pagePath = '/auth/verify_otp.php';
$errors = [];
$stateEmail = (string) ($recoveryState['email'] ?? '');
$recoveryIdentity = (string) ($recoveryState['recovery_identity'] ?? $stateEmail);
$maskedEmail = (string) ($recoveryState['masked_email'] ?? masked_email($stateEmail));
$challengeId = (int) ($recoveryState['challenge_id'] ?? 0);
$challenge = $challengeId > 0 ? load_email_otp_by_id($challengeId) : null;
$neutralRecoveryMessage = 'If an active account matches that User ID and email address, we sent a verification code.';
$formatResendCountdown = static function (int $seconds): string {
    $seconds = max(0, $seconds);

    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
};

if (is_array($challenge)) {
    if (($challenge['purpose'] ?? '') !== 'recovery' || (string) ($challenge['email'] ?? '') !== $stateEmail) {
        $challenge = null;
    }
}

if (is_post()) {
    require_csrf($pagePath);

    $action = input_string($_POST['action'] ?? 'verify', 'verify');

    if ($action === 'resend') {
        if ($challenge !== null) {
            $remaining = email_otp_resend_remaining($challenge);
            if ($remaining > 0) {
                $errors[] = 'Please wait ' . $formatResendCountdown($remaining) . ' before requesting another code.';
            }
        }

        if (!$errors) {
            $user = load_user_by_id((int) ($recoveryState['user_id'] ?? 0));

            if ($user !== null && (int) $user['is_active'] === 1) {
                $newChallenge = null;

                try {
                    $newChallenge = create_email_otp($user, 'recovery');
                    send_email_otp($user, 'recovery', (string) $newChallenge['plain_code']);
                    set_pending_recovery_otp((string) $user['email'], (int) $user['id'], (int) $newChallenge['id'], true, $recoveryIdentity);
                    audit_log('send_recovery_otp', 'user', (int) $user['id'], [
                        'user_uid' => $user['user_uid'] ?? null,
                        'email' => $user['email'],
                        'resend' => true,
                    ]);
                    set_flash('success', $neutralRecoveryMessage);
                    redirect($pagePath);
                } catch (Throwable $e) {
                    if (is_array($newChallenge) && isset($newChallenge['id'])) {
                        consume_email_otp((int) $newChallenge['id']);
                    }

                    $errors[] = $e->getMessage();
                }
            } else {
                set_flash('success', $neutralRecoveryMessage);
                redirect($pagePath);
            }
        }
    } else {
        $otpCode = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? '')) ?? '';

        if (strlen($otpCode) !== otp_code_length()) {
            $errors[] = 'Enter the ' . otp_code_length() . '-digit verification code from your email.';
        } elseif ($challenge === null) {
            $errors[] = 'That verification code is invalid or has expired. Request a new code and try again.';
        } else {
            $result = verify_email_otp($challenge, $otpCode);

            if (!(bool) ($result['ok'] ?? false)) {
                $errors[] = (string) ($result['message'] ?? 'Unable to verify that code.');
            } else {
                /** @var array<string, mixed> $verifiedChallenge */
                $verifiedChallenge = $result['challenge'];
                $user = load_user_by_id((int) ($recoveryState['user_id'] ?? 0));

                if ($user === null || (int) $user['is_active'] !== 1) {
                    clear_pending_recovery_otp();
                    clear_verified_recovery_reset();
                    set_flash('error', 'Start account recovery again to continue.');
                    redirect('/auth/forgot_password.php');
                }

                consume_email_otp((int) $verifiedChallenge['id']);
                auth_rate_limit_clear('recovery', $recoveryIdentity);
                set_verified_recovery_reset($user, $verifiedChallenge);
                redirect('/auth/reset_password.php');
            }
        }
    }
}

$challenge = $challengeId > 0 ? load_email_otp_by_id($challengeId) : null;
if (is_array($challenge) && (($challenge['purpose'] ?? '') !== 'recovery' || (string) ($challenge['email'] ?? '') !== $stateEmail)) {
    $challenge = null;
}

$resendRemaining = is_array($challenge) ? email_otp_resend_remaining($challenge) : 0;
$pageMessage = 'If an active account matches <strong>' . e($maskedEmail) . '</strong>, enter the code sent to that address to continue.';
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
                <div class="auth-eyebrow">Recovery Verification</div>
                <h1><?= e(APP_NAME) ?></h1>
                <p class="muted">Verify your email code to continue account recovery.</p>
            </div>
        </div>
        <?php if ($flash = get_flash()): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <div class="otp-panel">
            <p><?= $pageMessage ?></p>
            <p class="muted field-hint">Codes expire after <?= otp_expires_minutes() ?> minute<?= otp_expires_minutes() === 1 ? '' : 's' ?>.</p>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify">

            <label for="otp_code">Verification Code</label>
            <input
                type="text"
                name="otp_code"
                id="otp_code"
                class="otp-input"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="<?= otp_code_length() ?>"
                value="<?= e(old('otp_code')) ?>"
                required
            >

            <div class="actions">
                <button class="btn" type="submit">Verify Code</button>
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/auth/forgot_password.php">Use Different Email</a>
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/auth/login.php">Cancel</a>
            </div>
        </form>

        <form method="post" class="auth-support resend-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <button
                class="btn btn-secondary"
                type="submit"
                data-resend-button
                <?= $resendRemaining > 0 ? 'disabled' : '' ?>
            >
                Resend Code
            </button>
            <?php if ($resendRemaining > 0): ?>
                <span class="muted field-hint" data-resend-remaining="<?= $resendRemaining ?>">
                    You can request a new code in <?= e($formatResendCountdown($resendRemaining)) ?>.
                </span>
            <?php else: ?>
                <span class="muted field-hint">Need another code? Request a new one here.</span>
            <?php endif; ?>
        </form>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= filemtime(ROOT_PATH . '/assets/js/app.js') ?>"></script>
</body>
</html>
