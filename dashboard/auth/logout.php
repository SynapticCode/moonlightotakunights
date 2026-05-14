<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/session.php';

destroy_current_session();
$base = rtrim(config('app')['dashboard_url'] ?? '', '/');
header('Location: ' . ($base !== '' ? $base . '/login.php' : '/login.php'));
exit;
