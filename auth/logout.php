<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

require_csrf('/auth/login.php');

$user = current_user();

if ($user !== null) {
    audit_log('logout', 'user', (int) $user['id']);
}

logout_user();
set_flash('success', 'You have been logged out.');
redirect('/auth/login.php');
