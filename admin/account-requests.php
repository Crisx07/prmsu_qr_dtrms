<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$pageTitle = 'Account Requests';
$pagePath = '/admin/account-requests.php';

$errors = [];
$roles = role_options();
$offices = load_offices(true);


/*
 * ---------------------------------------------------------
 * FORMAT DATE/TIME
 * ---------------------------------------------------------
 */
function format_account_request_datetime(?string $datetime): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '-';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return $datetime;
    }

    return date('M d, Y h:i A', $timestamp);
}


/*
 * ---------------------------------------------------------
 * PROCESS ACCOUNT REQUEST ACTIONS
 * ---------------------------------------------------------
 */
if (is_post()) {

    require_csrf($pagePath);

    $action = input_string($_POST['action'] ?? '');
    $requestId = input_int($_POST['request_id'] ?? 0);


    /*
     * -----------------------------------------------------
     * LOAD ACCOUNT REQUEST
     * -----------------------------------------------------
     */
    $stmt = db()->prepare(
        'SELECT ar.*, o.name AS office_name
         FROM account_requests ar
         INNER JOIN offices o ON o.id = ar.office_id
         WHERE ar.id = :id
         LIMIT 1'
    );

    $stmt->execute([
        'id' => $requestId,
    ]);

    $request = $stmt->fetch();


    if (!$request) {

        $errors[] = 'The account request could not be found.';


    /*
     * =====================================================
     * REJECT REQUEST
     * =====================================================
     */
    } elseif ($action === 'reject') {

        $reason = input_string(
            $_POST['rejection_reason'] ?? ''
        );


        /*
         * Validate rejection reason.
         */
        if ($reason === '') {

            $errors[] =
                'Please enter a reason for rejecting the request.';

        } elseif ($request['status'] !== 'pending') {

            $errors[] =
                'Only pending requests can be rejected.';

        } else {

            $stmt = db()->prepare(
                "UPDATE account_requests
                 SET status = 'rejected',
                     rejection_reason = :reason,
                     reviewed_at = NOW(),
                     reviewed_by = :reviewed_by
                 WHERE id = :id
                   AND status = 'pending'"
            );

            $stmt->execute([
                'reason' => $reason,
                'reviewed_by' => (int) current_user()['id'],
                'id' => $requestId,
            ]);


            if ($stmt->rowCount() !== 1) {

                $errors[] =
                    'The account request could not be rejected. ' .
                    'It may have already been processed.';

            } else {

                /*
                 * Audit rejection.
                 */
                audit_log(
                    'reject_account_request',
                    'account_request',
                    $requestId,
                    [
                        'email' => $request['email'],
                        'office_id' => (int) $request['office_id'],
                        'reason' => $reason,
                    ]
                );


                /*
                 * Send rejection email.
                 */
                try {

                    send_account_request_rejected_email(
                        $request,
                        $reason
                    );

                    audit_log(
                        'send_account_request_rejected_email',
                        'account_request',
                        $requestId,
                        [
                            'email' => $request['email'],
                        ]
                    );

                    set_flash(
                        'success',
                        'Account request rejected. A rejection email was sent to ' .
                        $request['email'] .
                        '.'
                    );

                } catch (Throwable $mailError) {

                    audit_log(
                        'send_account_request_rejected_email_failed',
                        'account_request',
                        $requestId,
                        [
                            'email' => $request['email'],
                            'error' => $mailError->getMessage(),
                        ]
                    );

                    set_flash(
                        'warning',
                        'Account request rejected, but the rejection email could not be sent. Check the SMTP settings.'
                    );
                }


                redirect($pagePath);
            }
        }


    /*
     * =====================================================
     * APPROVE REQUEST
     * =====================================================
     */
    } elseif ($action === 'approve') {

        $role = input_string(
            $_POST['role'] ?? ''
        );

        $password = (string) (
            $_POST['temporary_password'] ?? ''
        );


        /*
         * -------------------------------------------------
         * SERVER-SIDE ROLE VALIDATION
         * -------------------------------------------------
         */
        if (!isset($roles[$role])) {

            $errors[] =
                'Please choose a valid role for the requested account.';
        }


        /*
         * -------------------------------------------------
         * SERVER-SIDE PASSWORD VALIDATION
         * -------------------------------------------------
         */
        if (strlen($password) < password_min_length()) {

            $errors[] =
                'The temporary password must be at least ' .
                password_min_length() .
                ' characters.';
        }


        /*
         * -------------------------------------------------
         * CREATE ACCOUNT
         * -------------------------------------------------
         */
        if (!$errors) {

            try {

                $approval = run_in_transaction(
                    function () use (
                        $requestId,
                        $roles,
                        $role,
                        $password
                    ): array {


                        /*
                         * Lock the request.
                         *
                         * This prevents two administrators from
                         * approving the same request simultaneously.
                         */
                        $stmt = db()->prepare(
                            'SELECT ar.*,
                                    o.name AS office_name,
                                    o.is_active AS office_is_active
                             FROM account_requests ar
                             LEFT JOIN offices o ON o.id = ar.office_id
                             WHERE ar.id = :id
                             LIMIT 1
                             FOR UPDATE'
                        );

                        $stmt->execute([
                            'id' => $requestId,
                        ]);

                        $lockedRequest = $stmt->fetch();


                        if (!$lockedRequest) {

                            throw new RuntimeException(
                                'The account request could not be found.'
                            );
                        }


                        /*
                         * Request must still be pending.
                         */
                        if (
                            (string) $lockedRequest['status']
                            !== 'pending'
                        ) {

                            throw new RuntimeException(
                                'Only pending requests can be approved. ' .
                                'This request is already ' .
                                (string) $lockedRequest['status'] .
                                '.'
                            );
                        }


                        /*
                         * Validate role again inside transaction.
                         */
                        if (!isset($roles[$role])) {

                            throw new RuntimeException(
                                'Please choose a valid role for the requested account.'
                            );
                        }


                        /*
                         * Validate password again inside transaction.
                         */
                        if (
                            strlen($password)
                            < password_min_length()
                        ) {

                            throw new RuntimeException(
                                'The temporary password must be at least ' .
                                password_min_length() .
                                ' characters.'
                            );
                        }


                        /*
                         * Validate email.
                         */
                        $email = strtolower(
                            trim(
                                (string) $lockedRequest['email']
                            )
                        );


                        if (
                            !filter_var(
                                $email,
                                FILTER_VALIDATE_EMAIL
                            )
                        ) {

                            throw new RuntimeException(
                                'The account request has an invalid email address.'
                            );
                        }


                        /*
                         * Check whether email already exists.
                         */
                        if (
                            load_user_by_email_any_status($email)
                            !== null
                        ) {

                            throw new RuntimeException(
                                'A user account with this email already exists.'
                            );
                        }


                        /*
                         * Make sure requested office is active.
                         */
                        if (
                            (int) (
                                $lockedRequest['office_is_active']
                                ?? 0
                            ) !== 1
                        ) {

                            throw new RuntimeException(
                                'The requested office is no longer active.'
                            );
                        }


                        $officeId = (int) (
                            $lockedRequest['office_id']
                        );

                        $fullName = (string) (
                            $lockedRequest['full_name']
                        );


                        /*
                         * -------------------------------------------------
                         * CREATE USER
                         * -------------------------------------------------
                         *
                         * The temporary password is immediately hashed.
                         *
                         * must_change_password = 1 means the new user
                         * must change the temporary password before
                         * accessing the rest of the system.
                         */
                        $columns =
                            'user_uid,
                             full_name,
                             email,
                             password_hash,
                             role,
                             office_id,
                             is_active,
                             must_change_password,
                             created_at';

                        $values =
                            ':user_uid,
                             :full_name,
                             :email,
                             :password_hash,
                             :role,
                             :office_id,
                             1,
                             1,
                             NOW()';


                        $baseParams = [
                            'full_name' => $fullName,
                            'email' => $email,
                            'password_hash' => password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            ),
                            'role' => $role,
                            'office_id' => $officeId,
                        ];


                        /*
                         * Support databases that still have the
                         * legacy users.office_name column.
                         */
                        $legacyOfficeName =
                            users_office_name_column_exists()
                                ? (string) (
                                    $lockedRequest['office_name']
                                    ?? ''
                                )
                                : null;


                        if ($legacyOfficeName !== null) {

                            $columns .= ', office_name';
                            $values .= ', :office_name';

                            $baseParams['office_name'] =
                                $legacyOfficeName;
                        }


                        $insertUser = db()->prepare(
                            sprintf(
                                'INSERT INTO users (%s) VALUES (%s)',
                                $columns,
                                $values
                            )
                        );


                        $newUserId = 0;
                        $newUserUid = '';
                        $lastDuplicate = null;
                        $nextSequence = null;


                        /*
                         * Try several User IDs in case another
                         * account receives the same generated ID.
                         */
                        for ($attempt = 0; $attempt < 5; $attempt++) {

                            if ($nextSequence === null) {

                                $candidate = next_user_uid();

                                $prefix = user_uid_year_prefix();

                                $nextSequence =
                                    str_starts_with(
                                        $candidate,
                                        $prefix
                                    )
                                        ? (int) substr(
                                            $candidate,
                                            strlen($prefix)
                                        )
                                        : 0;

                            } else {

                                $nextSequence++;

                                $candidate =
                                    format_user_uid(
                                        $nextSequence
                                    );
                            }


                            $params = $baseParams;

                            $params['user_uid'] =
                                $candidate;


                            try {

                                $insertUser->execute(
                                    $params
                                );

                                $newUserUid =
                                    $candidate;

                                $newUserId =
                                    (int) db()->lastInsertId();

                                break;


                            } catch (Throwable $e) {

                                /*
                                 * User ID collision.
                                 */
                                if (
                                    is_duplicate_key_error($e) &&
                                    str_contains(
                                        $e->getMessage(),
                                        'idx_users_user_uid'
                                    )
                                ) {

                                    $lastDuplicate = $e;

                                    continue;
                                }


                                /*
                                 * Email collision.
                                 */
                                if (
                                    is_duplicate_key_error($e)
                                ) {

                                    throw new RuntimeException(
                                        'A user account with this email already exists.',
                                        0,
                                        $e
                                    );
                                }


                                throw $e;
                            }
                        }


                        if (
                            $newUserId <= 0 ||
                            $newUserUid === ''
                        ) {

                            throw $lastDuplicate ??
                                new RuntimeException(
                                    'Unable to generate a unique User ID.'
                                );
                        }


                        /*
                         * Mark request as approved.
                         */
                        $stmt = db()->prepare(
                            "UPDATE account_requests
                             SET status = 'approved',
                                 reviewed_at = NOW(),
                                 reviewed_by = :reviewed_by,
                                 approved_user_id = :approved_user_id,
                                 rejection_reason = NULL
                             WHERE id = :id
                               AND status = 'pending'"
                        );


                        $stmt->execute([
                            'reviewed_by' =>
                                (int) current_user()['id'],

                            'approved_user_id' =>
                                $newUserId,

                            'id' =>
                                $requestId,
                        ]);


                        if ($stmt->rowCount() !== 1) {

                            throw new RuntimeException(
                                'The account request changed before approval completed. Please reload and try again.'
                            );
                        }


                        /*
                         * Audit approval.
                         */
                        audit_log(
                            'approve_account_request',
                            'account_request',
                            $requestId,
                            [
                                'email' => $email,
                                'office_id' => $officeId,
                                'role' => $role,
                                'user_id' => $newUserId,
                                'user_uid' => $newUserUid,
                            ]
                        );


                        /*
                         * Audit user creation.
                         */
                        audit_log(
                            'create_user_from_account_request',
                            'user',
                            $newUserId,
                            [
                                'user_uid' => $newUserUid,
                                'role' => $role,
                                'office_id' => $officeId,
                                'source_request_id' =>
                                    $requestId,
                            ]
                        );


                        return [
                            'user_id' => $newUserId,

                            'user_uid' => $newUserUid,

                            'created_user' => [
                                'id' => $newUserId,
                                'user_uid' => $newUserUid,
                                'full_name' => $fullName,
                                'email' => $email,
                                'role' => $role,
                                'office_id' => $officeId,
                            ],
                        ];
                    }
                );


                $newUserId =
                    (int) $approval['user_id'];

                $newUserUid =
                    (string) $approval['user_uid'];

                $createdUser =
                    $approval['created_user'];


                /*
                 * -------------------------------------------------
                 * SEND ACCOUNT CREDENTIALS
                 * -------------------------------------------------
                 */
                try {

                    send_account_created_email(
                        $createdUser,
                        $password
                    );


                    audit_log(
                        'send_account_created_email',
                        'user',
                        $newUserId,
                        [
                            'email' =>
                                $createdUser['email'],

                            'source_request_id' =>
                                $requestId,
                        ]
                    );


                    set_flash(
                        'success',
                        'Account approved. User ID ' .
                        $newUserUid .
                        ' was created and the User ID and temporary password were emailed to ' .
                        $createdUser['email'] .
                        '.'
                    );


                } catch (Throwable $mailError) {

                    audit_log(
                        'send_account_created_email_failed',
                        'user',
                        $newUserId,
                        [
                            'email' =>
                                $createdUser['email'],

                            'source_request_id' =>
                                $requestId,

                            'error' =>
                                $mailError->getMessage(),
                        ]
                    );


                    set_flash(
                        'warning',
                        'Account approved and User ID ' .
                        $newUserUid .
                        ' was created, but the account email could not be sent. Check the SMTP settings and resend the credentials manually.'
                    );
                }


                redirect($pagePath);


            } catch (Throwable $e) {

                $errors[] =
                    'Unable to approve the request: ' .
                    $e->getMessage();
            }
        }


    /*
     * =====================================================
     * INVALID ACTION
     * =====================================================
     */
    } else {

        $errors[] =
            'Invalid account request action.';
    }
}


/*
 * ---------------------------------------------------------
 * LOAD PENDING REQUESTS
 * ---------------------------------------------------------
 */
$pending = db()->query(
    "SELECT ar.*, o.name AS office_name
     FROM account_requests ar
     INNER JOIN offices o ON o.id = ar.office_id
     WHERE ar.status = 'pending'
     ORDER BY ar.requested_at ASC, ar.id ASC"
)->fetchAll();


/*
 * ---------------------------------------------------------
 * LOAD REQUEST HISTORY
 * ---------------------------------------------------------
 */
$history = db()->query(
    "SELECT ar.*,
            o.name AS office_name,
            ru.full_name AS reviewed_by_name,
            au.user_uid AS approved_user_uid
     FROM account_requests ar
     INNER JOIN offices o ON o.id = ar.office_id
     LEFT JOIN users ru ON ru.id = ar.reviewed_by
     LEFT JOIN users au ON au.id = ar.approved_user_id
     WHERE ar.status <> 'pending'
     ORDER BY ar.reviewed_at DESC, ar.id DESC
     LIMIT 100"
)->fetchAll();


require_once __DIR__ . '/../includes/header.php';
?>


<!-- =======================================================
     PAGE HEADER
======================================================= -->

<div class="card page-hero page-hero-compact">

    <div class="page-hero-main">

        <div>

            <div class="hero-eyebrow">
                Administrator Only
            </div>

            <h2>
                Account Requests
            </h2>

            <p class="muted">
                Review account requests and verify the submitted
                PRMSU ID before creating an account.
            </p>

        </div>


        <div class="hero-summary">

            <div class="hero-summary-item">

                <span>
                    Pending
                </span>

                <strong>
                    <?= count($pending) ?>
                </strong>

            </div>

        </div>

    </div>

</div>


<!-- =======================================================
     SERVER-SIDE ERRORS
======================================================= -->

<?php foreach ($errors as $error): ?>

    <div class="alert error">
        <?= e($error) ?>
    </div>

<?php endforeach; ?>


<!-- =======================================================
     PENDING REQUESTS
======================================================= -->

<div class="card">

    <div class="section-heading">

        <div>

            <h2>
                Pending Requests
            </h2>

            <p class="muted">
                Review the information and PRMSU ID
                before creating an account.
            </p>

        </div>

    </div>


    <?php if (!$pending): ?>

        <div class="empty-state">
            There are no pending account requests.
        </div>


    <?php else: ?>


        <?php foreach ($pending as $request): ?>

            <div
                class="card"
                style="margin-bottom: 1rem;"
            >

                <!-- REQUEST INFORMATION -->
                <div class="section-heading">

                    <div>

                        <h3>
                            <?= e($request['full_name']) ?>
                        </h3>

                        <p class="muted">
                            <?= e($request['email']) ?>
                            ·
                            <?= e($request['office_name']) ?>
                        </p>

                    </div>

                    <span class="badge">
                        Pending
                    </span>

                </div>


                <p class="muted">

                    Requested at
                    <?= e(
                        format_account_request_datetime(
                            $request['requested_at'] ?? null
                        )
                    ) ?>

                </p>


                <!-- =================================================
                     PRMSU ID
                ================================================== -->

                <div style="margin-top: 1rem;">

                    <p style="margin-bottom: .5rem;">
                        <strong>
                            PRMSU ID:
                        </strong>
                    </p>


                    <?php if (
                        !empty($request['id_front_path']) ||
                        !empty($request['id_back_path'])
                    ): ?>

                        <div class="actions">


                            <?php if (
                                !empty(
                                    $request['id_front_path']
                                )
                            ): ?>

                                <a
                                    class="btn btn-secondary btn-sm"
                                    href="<?= BASE_URL ?>/admin/account-request-id.php?id=<?= (int) $request['id'] ?>&side=front"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    View Front
                                </a>

                            <?php endif; ?>


                            <?php if (
                                !empty(
                                    $request['id_back_path']
                                )
                            ): ?>

                                <a
                                    class="btn btn-secondary btn-sm"
                                    href="<?= BASE_URL ?>/admin/account-request-id.php?id=<?= (int) $request['id'] ?>&side=back"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    View Back
                                </a>

                            <?php endif; ?>


                        </div>


                    <?php else: ?>

                        <span class="muted">
                            Not attached
                        </span>

                    <?php endif; ?>

                </div>


                <!-- =================================================
                     APPROVAL FORM
                ================================================== -->

                <form
                    method="post"
                    class="account-approval-form"
                    novalidate
                    style="margin-top: 1.5rem;"
                >

                    <?= csrf_field() ?>


                    <input
                        type="hidden"
                        name="action"
                        value="approve"
                    >


                    <input
                        type="hidden"
                        name="request_id"
                        value="<?= (int) $request['id'] ?>"
                    >


                    <div class="grid grid-3">


                        <!-- ROLE -->
                        <div>

                            <label
                                for="role_<?= (int) $request['id'] ?>"
                            >
                                Assign Role
                            </label>


                            <select
                                name="role"
                                id="role_<?= (int) $request['id'] ?>"
                                aria-describedby="role_error_<?= (int) $request['id'] ?>"
                                aria-invalid="false"
                                required
                            >

                                <option value="">
                                    Select role
                                </option>


                                <?php foreach (
                                    $roles
                                    as $value => $label
                                ): ?>

                                    <option
                                        value="<?= e($value) ?>"
                                    >
                                        <?= e($label) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <p
                                class="field-error"
                                id="role_error_<?= (int) $request['id'] ?>"
                                aria-live="polite"
                                hidden
                            >
                                Please select a role before
                                approving the account.
                            </p>

                        </div>


                        <!-- TEMPORARY PASSWORD -->
                        <div>

                            <label
                                for="password_<?= (int) $request['id'] ?>"
                            >
                                Temporary Password
                            </label>


                            <div class="password-field">

                                <input
                                    type="password"
                                    name="temporary_password"
                                    id="password_<?= (int) $request['id'] ?>"
                                    data-min-length="<?= password_min_length() ?>"
                                    aria-describedby="password_error_<?= (int) $request['id'] ?>"
                                    aria-invalid="false"
                                    autocomplete="new-password"
                                    required
                                >


                                <button
                                    class="btn btn-secondary btn-sm password-toggle"
                                    type="button"
                                    data-password-toggle
                                    data-password-target="#password_<?= (int) $request['id'] ?>"
                                    aria-pressed="false"
                                >
                                    Show
                                </button>

                            </div>


                            <p
                                class="field-error"
                                id="password_error_<?= (int) $request['id'] ?>"
                                aria-live="polite"
                                hidden
                            >
                                Please enter a temporary password.
                            </p>

                        </div>


                        <!-- USER ID -->
                        <div>

                            <label>
                                User ID
                            </label>


                            <input
                                type="text"
                                value="Auto-generated upon approval"
                                disabled
                            >

                        </div>

                    </div>


                    <div class="actions">

                        <button
                            class="btn"
                            type="submit"
                            data-confirm="Approve this request and create the user account?"
                        >
                            Approve &amp; Create Account
                        </button>

                    </div>

                </form>


                <!-- =================================================
                     REJECTION FORM
                ================================================== -->

                <form
                    method="post"
                    class="account-rejection-form"
                    style="margin-top: 1rem;"
                    novalidate
                >

                    <?= csrf_field() ?>


                    <input
                        type="hidden"
                        name="action"
                        value="reject"
                    >


                    <input
                        type="hidden"
                        name="request_id"
                        value="<?= (int) $request['id'] ?>"
                    >


                    <label
                        for="reason_<?= (int) $request['id'] ?>"
                    >
                        Rejection Reason
                    </label>


                    <input
                        type="text"
                        name="rejection_reason"
                        id="reason_<?= (int) $request['id'] ?>"
                        maxlength="500"
                        placeholder="Why is this request being rejected?"
                        aria-describedby="reason_error_<?= (int) $request['id'] ?>"
                        aria-invalid="false"
                        autocomplete="off"
                        required
                    >


                    <p
                        class="field-error"
                        id="reason_error_<?= (int) $request['id'] ?>"
                        aria-live="polite"
                        hidden
                    >
                        Please enter a rejection reason before
                        rejecting this request.
                    </p>


                    <button
                        class="btn btn-secondary"
                        type="submit"
                        data-confirm="Reject this account request?"
                    >
                        Reject Request
                    </button>

                </form>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>


<!-- =======================================================
     REQUEST HISTORY
======================================================= -->

<div class="card">

    <div class="section-heading">

        <div>

            <h2>
                Request History
            </h2>

            <p class="muted">
                The latest approved and rejected requests are
                shown here.
            </p>

        </div>

    </div>


    <?php if (!$history): ?>

        <div class="empty-state">
            No processed account requests yet.
        </div>


    <?php else: ?>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Request Name</th>
                        <th>Email</th>
                        <th>Office</th>
                        <th>Status</th>
                        <th>Reviewed By</th>
                        <th>PRMSU ID</th>
                        <th>Result</th>
                    </tr>

                </thead>


                <tbody>


                <?php foreach ($history as $request): ?>

                    <tr>

                        <td>
                            <?= e($request['full_name']) ?>
                        </td>


                        <td>
                            <?= e($request['email']) ?>
                        </td>


                        <td>
                            <?= e($request['office_name']) ?>
                        </td>


                        <td>
                            <?= e(
                                ucfirst(
                                    (string) $request['status']
                                )
                            ) ?>
                        </td>


                        <td>

                            <?= e(
                                $request['reviewed_by_name']
                                ?? '-'
                            ) ?>

                            <br>

                            <span class="muted">

                                <?= e(
                                    format_account_request_datetime(
                                        $request['reviewed_at']
                                        ?? null
                                    )
                                ) ?>

                            </span>

                        </td>


                        <!-- PRMSU ID -->
                        <td>

                            <?php if (
                                !empty(
                                    $request['id_front_path']
                                )
                            ): ?>

                                <a
                                    class="btn btn-secondary btn-sm"
                                    href="<?= BASE_URL ?>/admin/account-request-id.php?id=<?= (int) $request['id'] ?>&side=front"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Front
                                </a>

                            <?php endif; ?>


                            <?php if (
                                !empty(
                                    $request['id_back_path']
                                )
                            ): ?>

                                <a
                                    class="btn btn-secondary btn-sm"
                                    href="<?= BASE_URL ?>/admin/account-request-id.php?id=<?= (int) $request['id'] ?>&side=back"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Back
                                </a>

                            <?php endif; ?>


                            <?php if (
                                empty(
                                    $request['id_front_path']
                                ) &&
                                empty(
                                    $request['id_back_path']
                                )
                            ): ?>

                                <span class="muted">
                                    Not attached
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- RESULT -->
                        <td>

                            <?php if (
                                $request['status']
                                === 'approved'
                            ): ?>

                                User ID:

                                <strong>
                                    <?= e(
                                        $request[
                                            'approved_user_uid'
                                        ] ?? '-'
                                    ) ?>
                                </strong>


                            <?php else: ?>

                                <?= e(
                                    $request[
                                        'rejection_reason'
                                    ] ?? '-'
                                ) ?>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>


                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
     * =====================================================
     * APPROVAL FORMS
     * =====================================================
     */
    document
        .querySelectorAll('.account-approval-form')
        .forEach(function (form) {

            form.addEventListener('submit', function (event) {

                let valid = true;


                /*
                 * Get request ID.
                 */
                const requestIdInput =
                    form.querySelector(
                        'input[name="request_id"]'
                    );


                if (!requestIdInput) {

                    event.preventDefault();

                    return;
                }


                const requestId =
                    requestIdInput.value;


                /*
                 * Get fields.
                 */
                const role =
                    form.querySelector(
                        'select[name="role"]'
                    );


                const password =
                    form.querySelector(
                        'input[name="temporary_password"]'
                    );


                /*
                 * Get error elements.
                 */
                const roleError =
                    document.getElementById(
                        'role_error_' + requestId
                    );


                const passwordError =
                    document.getElementById(
                        'password_error_' + requestId
                    );


                /*
                 * =================================================
                 * ROLE VALIDATION
                 * =================================================
                 */
                if (
                    !role ||
                    role.value.trim() === ''
                ) {

                    if (roleError) {

                        roleError.textContent =
                            'Please select a role before approving the account.';

                        roleError.hidden = false;
                    }


                    if (role) {

                        role.setAttribute(
                            'aria-invalid',
                            'true'
                        );
                    }


                    valid = false;


                } else {

                    if (roleError) {

                        roleError.hidden = true;
                    }


                    role.setAttribute(
                        'aria-invalid',
                        'false'
                    );
                }


                /*
                 * =================================================
                 * TEMPORARY PASSWORD VALIDATION
                 * =================================================
                 *
                 * IMPORTANT:
                 *
                 * Do NOT rely only on HTML minlength.
                 *
                 * We explicitly check the actual value length here.
                 */
                if (!password) {

                    valid = false;


                } else {

                    const minLength =
                        parseInt(
                            password.dataset.minLength || '0',
                            10
                        );


                    const passwordValue =
                        password.value;


                    /*
                     * EMPTY PASSWORD
                     */
                    if (
                        passwordValue.trim() === ''
                    ) {

                        if (passwordError) {

                            passwordError.textContent =
                                'Please enter a temporary password.';

                            passwordError.hidden = false;
                        }


                        password.setAttribute(
                            'aria-invalid',
                            'true'
                        );


                        valid = false;


                    /*
                     * PASSWORD TOO SHORT
                     */
                    } else if (
                        passwordValue.length < minLength
                    ) {

                        if (passwordError) {

                            passwordError.textContent =
                                'Temporary password must be at least ' +
                                minLength +
                                ' characters.';

                            passwordError.hidden = false;
                        }


                        password.setAttribute(
                            'aria-invalid',
                            'true'
                        );


                        valid = false;


                    /*
                     * PASSWORD VALID
                     */
                    } else {

                        if (passwordError) {

                            passwordError.hidden = true;
                        }


                        password.setAttribute(
                            'aria-invalid',
                            'false'
                        );
                    }
                }


                /*
                 * =================================================
                 * STOP SUBMISSION IF INVALID
                 * =================================================
                 */
                if (!valid) {

                    event.preventDefault();


                    /*
                     * Focus the first invalid field.
                     */
                    if (
                        role &&
                        role.value.trim() === ''
                    ) {

                        role.focus();


                    } else if (
                        password &&
                        (
                            password.value.trim() === '' ||
                            password.value.length <
                            parseInt(
                                password.dataset.minLength || '0',
                                10
                            )
                        )
                    ) {

                        password.focus();
                    }


                    return;
                }

            });

        });


    /*
     * =====================================================
     * ROLE LIVE VALIDATION
     * =====================================================
     *
     * Removes the role error as soon as a role is selected.
     */
    document
        .querySelectorAll(
            '.account-approval-form select[name="role"]'
        )
        .forEach(function (role) {

            role.addEventListener(
                'change',
                function () {

                    const requestId =
                        role.id.replace(
                            'role_',
                            ''
                        );


                    const error =
                        document.getElementById(
                            'role_error_' +
                            requestId
                        );


                    if (
                        role.value.trim() !== ''
                    ) {

                        role.setAttribute(
                            'aria-invalid',
                            'false'
                        );


                        if (error) {

                            error.hidden = true;
                        }
                    }

                }
            );

        });


    /*
     * =====================================================
     * PASSWORD LIVE VALIDATION
     * =====================================================
     *
     * Shows the error immediately when the password is
     * shorter than the required minimum.
     */
    document
        .querySelectorAll(
            '.account-approval-form input[name="temporary_password"]'
        )
        .forEach(function (password) {

            password.addEventListener(
                'input',
                function () {

                    const requestId =
                        password.id.replace(
                            'password_',
                            ''
                        );


                    const error =
                        document.getElementById(
                            'password_error_' +
                            requestId
                        );


                    const minLength =
                        parseInt(
                            password.dataset.minLength || '0',
                            10
                        );


                    const passwordLength =
                        password.value.length;


                    /*
                     * EMPTY
                     */
                    if (
                        password.value.trim() === ''
                    ) {

                        if (error) {

                            error.textContent =
                                'Please enter a temporary password.';

                            error.hidden = false;
                        }


                        password.setAttribute(
                            'aria-invalid',
                            'true'
                        );


                        return;
                    }


                    /*
                     * TOO SHORT
                     */
                    if (
                        passwordLength < minLength
                    ) {

                        if (error) {

                            error.textContent =
                                'Temporary password must be at least ' +
                                minLength +
                                ' characters.';

                            error.hidden = false;
                        }


                        password.setAttribute(
                            'aria-invalid',
                            'true'
                        );


                        return;
                    }


                    /*
                     * VALID
                     */
                    if (error) {

                        error.hidden = true;
                    }


                    password.setAttribute(
                        'aria-invalid',
                        'false'
                    );

                }
            );

        });


    /*
     * =====================================================
     * REJECTION FORMS
     * =====================================================
     */
    document
        .querySelectorAll('.account-rejection-form')
        .forEach(function (form) {

            form.addEventListener(
                'submit',
                function (event) {

                    const requestIdInput =
                        form.querySelector(
                            'input[name="request_id"]'
                        );


                    if (!requestIdInput) {

                        event.preventDefault();

                        return;
                    }


                    const requestId =
                        requestIdInput.value;


                    const reason =
                        form.querySelector(
                            'input[name="rejection_reason"]'
                        );


                    const reasonError =
                        document.getElementById(
                            'reason_error_' +
                            requestId
                        );


                    /*
                     * Empty reason.
                     */
                    if (
                        !reason ||
                        reason.value.trim() === ''
                    ) {

                        event.preventDefault();


                        if (reasonError) {

                            reasonError.textContent =
                                'Please enter a rejection reason before rejecting this request.';

                            reasonError.hidden = false;
                        }


                        if (reason) {

                            reason.setAttribute(
                                'aria-invalid',
                                'true'
                            );

                            reason.focus();
                        }


                        return;
                    }


                    /*
                     * Valid reason.
                     */
                    if (reasonError) {

                        reasonError.hidden = true;
                    }


                    reason.setAttribute(
                        'aria-invalid',
                        'false'
                    );

                }
            );

        });


    /*
     * =====================================================
     * REJECTION REASON LIVE VALIDATION
     * =====================================================
     */
    document
        .querySelectorAll(
            '.account-rejection-form input[name="rejection_reason"]'
        )
        .forEach(function (reason) {

            reason.addEventListener(
                'input',
                function () {

                    const requestId =
                        reason.id.replace(
                            'reason_',
                            ''
                        );


                    const error =
                        document.getElementById(
                            'reason_error_' +
                            requestId
                        );


                    if (
                        reason.value.trim() !== ''
                    ) {

                        reason.setAttribute(
                            'aria-invalid',
                            'false'
                        );


                        if (error) {

                            error.hidden = true;
                        }
                    }

                }
            );

        });

});
</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
