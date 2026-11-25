<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

get_db();

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

header('Location: ' . (is_admin() ? 'admin.php' : 'user.php'));
exit;
