<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/session.php';

destroy_current_session();
header('Location: /dashboard/login.php');
exit;
