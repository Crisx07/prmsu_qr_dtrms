<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Change Password';
$pagePath = '/auth/change_password.php';
$errors = [];

if (is_post()) {
    require_csrf($pagePath);

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $user = load_user_by_id((int) current_user()['id']);
    if ($user === null || !password_verify($currentPassword, $user['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    }
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
            'id' => current_user()['id'],
        ]);

        $freshUser = load_user_by_id((int) current_user()['id']);
        if ($freshUser !== null) {
            login_user($freshUser);
        }

        audit_log('change_password', 'user', (int) current_user()['id']);
        set_flash('success', 'Password updated successfully.');
        redirect('/dashboard.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card compact-card">
    <h2>Change Password</h2>
    <p class="muted">Newly created accounts must change their password before they can access the rest of the system.</p>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post">
        <?= csrf_field() ?>

        <label for="current_password">Current Password</label>
        <div class="password-field">
            <input type="password" name="current_password" id="current_password" required>
            <button class="btn btn-secondary btn-sm password-toggle" type="button" data-password-toggle data-password-target="#current_password" aria-pressed="false">Show</button>
        </div>

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
            <button class="btn" type="submit">Save Password</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
