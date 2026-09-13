<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$pageTitle = 'Users';
$pagePath = '/users/index.php';
$errors = [];
$roles = role_options();
$offices = load_offices(false);
$officeNameById = [];
foreach ($offices as $office) {
    $officeNameById[(int) $office['id']] = $office['name'];
}

$editUserId = input_int($_GET['edit'] ?? 0);
$editingUser = $editUserId > 0 ? load_user_by_id($editUserId) : null;

if (is_post()) {
    require_csrf($pagePath);

    $action = input_string($_POST['action'] ?? 'save', 'save');

    if ($action === 'toggle') {
        $toggleId = input_int($_POST['user_id'] ?? 0);
        if ($toggleId === current_user()['id']) {
            set_flash('error', 'You cannot deactivate your own account.');
            redirect($pagePath);
        }

        $stmt = db()->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
        $stmt->execute(['id' => $toggleId]);
        audit_log('toggle_user_status', 'user', $toggleId);
        set_flash('success', 'User status updated.');
        redirect($pagePath);
    }

    $userId = input_int($_POST['user_id'] ?? 0);
    $fullName = input_string($_POST['full_name'] ?? '');
    $email = input_string($_POST['email'] ?? '');
    $officeId = input_int($_POST['office_id'] ?? 0);
    $role = input_string($_POST['role'] ?? 'office_staff', 'office_staff');
    $password = $_POST['password'] ?? '';

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (!isset($roles[$role])) {
        $errors[] = 'Please select a valid role.';
    }
    if ($userId > 0 && $userId === (int) current_user()['id'] && $role !== 'admin') {
        $errors[] = 'You cannot remove your own administrator role.';
    }
    if ($officeId <= 0 || !isset($officeNameById[$officeId])) {
        $errors[] = 'Please select a unit.';
    }
    if ($userId === 0 && strlen($password) < password_min_length()) {
        $errors[] = 'Temporary password must be at least ' . password_min_length() . ' characters.';
    }
    if ($userId > 0 && $password !== '' && strlen($password) < password_min_length()) {
        $errors[] = 'New temporary password must be at least ' . password_min_length() . ' characters.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id <> :id');
        $stmt->execute([
            'email' => $email,
            'id' => $userId,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'That email address is already in use.';
        }
    }

    if (!$errors) {
        $legacyOfficeName = users_office_name_column_exists() ? $officeNameById[$officeId] : null;

        if ($userId > 0) {
            $params = [
                'id' => $userId,
                'full_name' => $fullName,
                'email' => $email,
                'office_id' => $officeId,
                'role' => $role,
            ];

            $sql = 'UPDATE users
                    SET full_name = :full_name,
                        email = :email,
                        office_id = :office_id,
                        role = :role';

            if ($legacyOfficeName !== null) {
                $sql .= ',
                        office_name = :office_name';
                $params['office_name'] = $legacyOfficeName;
            }

            if ($password !== '') {
                $sql .= ',
                        password_hash = :password_hash,
                        must_change_password = 1';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= ' WHERE id = :id';

            $stmt = db()->prepare($sql);
            $stmt->execute($params);

            $updatedUser = load_user_by_id($userId) ?? [
                'id' => $userId,
                'user_uid' => $editingUser['user_uid'] ?? null,
                'full_name' => $fullName,
                'email' => $email,
            ];

            if ($userId === current_user()['id']) {
                refresh_current_user();
            }

            audit_log('update_user', 'user', $userId, [
                'user_uid' => $updatedUser['user_uid'] ?? null,
                'role' => $role,
                'office_id' => $officeId,
                'reset_password' => $password !== '',
            ]);

            if ($password !== '') {
                try {
                    send_temporary_password_reset_email($updatedUser, $password);
                    audit_log('send_temporary_password_reset_email', 'user', $userId, [
                        'user_uid' => $updatedUser['user_uid'] ?? null,
                        'email' => $email,
                    ]);
                    set_flash('success', 'User account updated. Temporary password notification sent to ' . $email . '.');
                } catch (Throwable $e) {
                    audit_log('send_temporary_password_reset_email_failed', 'user', $userId, [
                        'user_uid' => $updatedUser['user_uid'] ?? null,
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                    set_flash('warning', 'User account updated, but the temporary password email was not sent: ' . $e->getMessage() . ' Please manually give the user their temporary password.');
                }
            } else {
                set_flash('success', 'User account updated.');
            }
        } else {
            $newUserUid = '';
            $newUserId = run_in_transaction(function () use (
                $fullName,
                $email,
                $password,
                $role,
                $officeId,
                $legacyOfficeName,
                &$newUserUid
            ): int {
                $columns = 'user_uid, full_name, email, password_hash, role, office_id, is_active, must_change_password, created_at';
                $values = ':user_uid, :full_name, :email, :password_hash, :role, :office_id, 1, 1, NOW()';
                $baseParams = [
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'office_id' => $officeId,
                ];

                if ($legacyOfficeName !== null) {
                    $columns .= ', office_name';
                    $values .= ', :office_name';
                    $baseParams['office_name'] = $legacyOfficeName;
                }

                $stmt = db()->prepare(sprintf('INSERT INTO users (%s) VALUES (%s)', $columns, $values));
                $lastDuplicate = null;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidateUserUid = next_user_uid();
                    $params = $baseParams;
                    $params['user_uid'] = $candidateUserUid;

                    try {
                        $stmt->execute($params);
                        $newUserUid = $candidateUserUid;
                        return (int) db()->lastInsertId();
                    } catch (Throwable $e) {
                        if (is_duplicate_key_error($e) && str_contains($e->getMessage(), 'idx_users_user_uid')) {
                            $lastDuplicate = $e;
                            continue;
                        }

                        throw $e;
                    }
                }

                throw $lastDuplicate ?? new RuntimeException('Unable to generate a unique User ID.');
            });

            audit_log('create_user', 'user', $newUserId, [
                'user_uid' => $newUserUid,
                'role' => $role,
                'office_id' => $officeId,
            ]);

            $createdUser = [
                'id' => $newUserId,
                'user_uid' => $newUserUid,
                'full_name' => $fullName,
                'email' => $email,
            ];

            try {
                send_account_created_email($createdUser, $password);
                audit_log('send_account_created_email', 'user', $newUserId, [
                    'user_uid' => $newUserUid,
                    'email' => $email,
                ]);
                set_flash('success', 'User account created with User ID ' . $newUserUid . '. Temporary password notification sent to ' . $email . '.');
            } catch (Throwable $e) {
                audit_log('send_account_created_email_failed', 'user', $newUserId, [
                    'user_uid' => $newUserUid,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
                set_flash('warning', 'User account created with User ID ' . $newUserUid . ', but the temporary password email was not sent: ' . $e->getMessage() . ' Please manually give the user their User ID and temporary password.');
            }
        }

        redirect($pagePath);
    }
}

$users = db()->query(
    'SELECT u.*, o.name AS office_name
     FROM users u
     LEFT JOIN offices o ON o.id = u.office_id
     ORDER BY u.created_at DESC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';

$formValues = [
    'user_id' => $editingUser['id'] ?? 0,
    'user_uid' => old('user_uid', $editingUser['user_uid'] ?? ''),
    'full_name' => old('full_name', $editingUser['full_name'] ?? ''),
    'email' => old('email', $editingUser['email'] ?? ''),
    'office_id' => input_int($_POST['office_id'] ?? ($editingUser['office_id'] ?? 0)),
    'role' => old('role', $editingUser['role'] ?? 'office_staff'),
];
?>
<div class="grid page-grid">
    <div class="card">
        <div class="section-heading">
            <div>
                <h2><?= $editingUser ? 'Edit User' : 'Create User' ?></h2>
                <p class="muted"><?= $editingUser ? 'Update role, unit assignment, or reset a temporary password.' : 'New users receive an User ID and must change the temporary password on first login.' ?></p>
            </div>
            <?php if ($editingUser): ?>
                <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/users/index.php">New User</a>
            <?php endif; ?>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post"<?= $editingUser ? '' : ' data-confirm="Are you sure you want to create this account?"' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= (int) $formValues['user_id'] ?>">

            <?php if ($editingUser): ?>
                <label for="user_uid_display">User ID</label>
                <input type="text" id="user_uid_display" value="<?= e($formValues['user_uid'] ?: '-') ?>" disabled>
            <?php endif; ?>

            <label for="full_name">Full Name</label>
            <input type="text" name="full_name" id="full_name" value="<?= e($formValues['full_name']) ?>" required>

            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" value="<?= e($formValues['email']) ?>" required>

            <label for="office_id">Office</label>
            <select name="office_id" id="office_id" required>
                <option value="">Select office</option>
                <?php foreach ($offices as $office): ?>
                    <option value="<?= (int) $office['id'] ?>" <?= (int) $formValues['office_id'] === (int) $office['id'] ? 'selected' : '' ?>>
                        <?= e($office['name']) ?><?= (int) $office['is_active'] === 0 ? ' (Inactive)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="role">Role</label>
            <select name="role" id="role" required>
                <?php foreach ($roles as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $formValues['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="password"><?= $editingUser ? 'New Temporary Password (optional)' : 'Temporary Password' ?></label>
            <div class="password-field">
                <input type="password" name="password" id="password" <?= $editingUser ? '' : 'required' ?>>
                <button class="btn btn-secondary btn-sm password-toggle" type="button" data-password-toggle data-password-target="#password" aria-pressed="false">Show</button>
            </div>

            <div class="actions">
                <button class="btn" type="submit"><?= $editingUser ? 'Update User' : 'Create User' ?></button>
                <?php if ($editingUser): ?>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/users/index.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>User Accounts</h2>
                <p class="muted">Manage activation, office assignments, and role access for each account.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Email Address</th>
                        <th>Office</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Password Reset</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="8"><div class="empty-state">No users found yet.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= e($user['user_uid'] ?? '-') ?></td>
                            <td><?= e($user['full_name']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td><?= e($user['office_name'] ?? '-') ?></td>
                            <td><?= e(role_label($user['role'])) ?></td>
                            <td><?= (int) $user['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            <td><?= (int) $user['must_change_password'] === 1 ? 'Required' : 'No' ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/users/index.php?edit=<?= (int) $user['id'] ?>">Edit</a>
                                    <?php if ((int) $user['id'] !== current_user()['id']): ?>
                                        <form method="post" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                            <button class="btn btn-sm <?= (int) $user['is_active'] === 1 ? 'btn-danger' : 'btn-secondary' ?>" type="submit" data-confirm="<?= (int) $user['is_active'] === 1 ? 'Deactivate this user account?' : 'Activate this user account?' ?>">
                                                <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Current user</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
