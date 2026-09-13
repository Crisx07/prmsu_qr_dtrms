<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$dbStatus = database_status();
$dbReady = $dbStatus['ready'];
$errors = [];
$offices = $dbReady ? load_offices(true) : [];

if ($dbReady && is_post()) {
    require_csrf('/auth/request_account.php');

    $fullName = input_string($_POST['full_name'] ?? '');
    $email = strtolower(input_string($_POST['email'] ?? ''));
    $officeId = input_int($_POST['office_id'] ?? 0);

    if ($fullName === '' || strlen($fullName) < 2) {
        $errors[] = 'Please enter your full name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    $office = $officeId > 0 ? load_office_by_id($officeId) : null;
    if ($office === null || (int) ($office['is_active'] ?? 0) !== 1) {
        $errors[] = 'Please select a valid office.';
    }

    if (!$errors) {
        $existingUser = load_user_by_email_any_status($email);
        if ($existingUser !== null) {
            $errors[] = 'An account with this email address already exists. Please contact the administrator.';
        }
    }

    if (!$errors) {
        $stmt = db()->prepare(
            "SELECT id FROM account_requests
             WHERE email = :email AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            $errors[] = 'You already have a pending account request for this email address.';
        }
    }

    $idFrontUpload = null;
    $idBackUpload = null;

    if (!$errors) {
        $idFiles = [
            'id_front' => 'PRMSU ID front',
            'id_back' => 'PRMSU ID back',
        ];

        foreach ($idFiles as $field => $label) {
            if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errors[] = "Please attach the {$label}.";
                continue;
            }

            if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = "The {$label} could not be uploaded. Please try again.";
                continue;
            }

            $originalName = (string) ($_FILES[$field]['name'] ?? '');
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $errors[] = "{$label} must be a JPG, JPEG, or PNG image.";
            }
        }
    }

    if (!$errors) {
        try {
            $idFrontUpload = upload_file($_FILES['id_front'], UPLOAD_ACCOUNT_REQUESTS);
            $idBackUpload = upload_file($_FILES['id_back'], UPLOAD_ACCOUNT_REQUESTS);
        } catch (RuntimeException $e) {
            if (!empty($idFrontUpload['path'])) {
                @unlink(UPLOAD_ROOT . '/account_requests/' . basename((string) $idFrontUpload['path']));
            }
            if (!empty($idBackUpload['path'])) {
                @unlink(UPLOAD_ROOT . '/account_requests/' . basename((string) $idBackUpload['path']));
            }
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO account_requests (
                    full_name, email, office_id,
                    id_front_path, id_front_original_name, id_front_mime, id_front_size,
                    id_back_path, id_back_original_name, id_back_mime, id_back_size,
                    status, requested_at
                 ) VALUES (
                    :full_name, :email, :office_id,
                    :id_front_path, :id_front_original_name, :id_front_mime, :id_front_size,
                    :id_back_path, :id_back_original_name, :id_back_mime, :id_back_size,
                    \'pending\', NOW()
                 )'
            );
            $stmt->execute([
                'full_name' => $fullName,
                'email' => $email,
                'office_id' => $officeId,
                'id_front_path' => $idFrontUpload['path'] ?? null,
                'id_front_original_name' => $idFrontUpload['original_name'] ?? null,
                'id_front_mime' => $idFrontUpload['mime'] ?? null,
                'id_front_size' => $idFrontUpload['size'] ?? null,
                'id_back_path' => $idBackUpload['path'] ?? null,
                'id_back_original_name' => $idBackUpload['original_name'] ?? null,
                'id_back_mime' => $idBackUpload['mime'] ?? null,
                'id_back_size' => $idBackUpload['size'] ?? null,
            ]);
        } catch (Throwable $e) {
            if (!empty($idFrontUpload['path'])) {
                @unlink(UPLOAD_ROOT . '/account_requests/' . basename((string) $idFrontUpload['path']));
            }
            if (!empty($idBackUpload['path'])) {
                @unlink(UPLOAD_ROOT . '/account_requests/' . basename((string) $idBackUpload['path']));
            }
            $errors[] = 'The account request could not be submitted. Please try again or contact the administrator.';
        }

        if (!$errors) {
            set_flash('success', 'Your account request has been submitted. The administrator will review your information before approval.');
            redirect('/auth/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request an Account</title>
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
                <h1>Request an Account</h1>
                <p class="muted">Submit your information. The system administrator will verify your request and create your account.</p>
            </div>
        </div>

        <?php if (!$dbReady): ?>
            <div class="alert error"><?= e(database_unavailable_message($dbStatus)) ?></div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if ($dbReady): ?>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <label for="full_name">Full Name</label>
                <input type="text" name="full_name" id="full_name" value="<?= e(old('full_name')) ?>" maxlength="150" required autocomplete="name">

                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" value="<?= e(old('email')) ?>" maxlength="150" required autocomplete="email">

                <label for="office_id">Office</label>
                <select name="office_id" id="office_id" required>
                    <option value="">Select your office</option>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?= (int) $office['id'] ?>" <?= input_int($_POST['office_id'] ?? 0) === (int) $office['id'] ? 'selected' : '' ?>>
                            <?= e($office['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="id_front">PRMSU ID - Front</label>
                <input type="file" name="id_front" id="id_front" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>

                <label for="id_back">PRMSU ID - Back</label>
                <input type="file" name="id_back" id="id_back" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>

                <div class="actions">
                    <button class="btn" type="submit">Submit Request</button>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/auth/login.php">Back to Login</a>
                </div>
            </form>
        <?php endif; ?>

        <p class="muted auth-footer-note">Your role, User ID, and temporary password will be assigned by the administrator after approval.</p>
    </div>
</div>
</body>
</html>
