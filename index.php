<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (!system_is_ready()) {
    set_flash('error', database_unavailable_message());
    redirect('/auth/login.php');
}

if (current_user()) {
    redirect('/dashboard.php');
}

redirect('/auth/login.php');
