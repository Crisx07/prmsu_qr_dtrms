<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!system_is_ready()) {
    set_flash('error', database_unavailable_message());
    redirect('/auth/login.php');
}

refresh_current_user();

if (!current_user()) {
    if (consume_auth_logout_reason() === auth_logout_reason_deactivated()) {
        set_flash('error', deactivated_account_message());
    } else {
        set_flash('error', 'Please log in to continue.');
    }
    redirect('/auth/login.php');
}

if ((int) (current_user()['must_change_password'] ?? 0) === 1 && current_path() !== BASE_URL . '/auth/change_password.php') {
    set_flash('warning', 'Please change your password before continuing.');
    redirect('/auth/change_password.php');
}
