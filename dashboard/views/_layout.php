<?php
/**
 * _layout.php — Dashboard shell.
 *
 * Each view does:
 *   $page_title = '...';
 *   $page_active = 'contacts';   // sidebar key
 *   ob_start();
 *   // ... view markup ...
 *   $page_body = ob_get_clean();
 *   include __DIR__ . '/_layout.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../auth/session.php';

$user = require_login();

$page_title  = $page_title  ?? 'Dashboard';
$page_active = $page_active ?? 'overview';
$page_body   = $page_body   ?? '';

$nav = [
    ['main', [
        ['key' => 'overview',  'href' => '/dashboard/',                 'label' => 'Overview',      'icon' => '◉'],
        ['key' => 'contacts',  'href' => '/dashboard/contacts.php',     'label' => 'Contacts',      'icon' => '◇'],
        ['key' => 'guild',     'href' => '/dashboard/contacts.php?source=guild', 'label' => 'Guild', 'icon' => '✦'],
        ['key' => 'cosplay',   'href' => '/dashboard/cosplay.php',      'label' => 'Cosplay',       'icon' => '✶'],
        ['key' => 'compose',   'href' => '/dashboard/compose.php',      'label' => 'Compose',       'icon' => '✎'],
        ['key' => 'broadcasts','href' => '/dashboard/broadcasts.php',   'label' => 'Broadcasts',    'icon' => '⤴'],
    ]],
    ['data', [
        ['key' => 'import',    'href' => '/dashboard/import.php',       'label' => 'CSV Import',    'icon' => '⤵'],
        ['key' => 'events',    'href' => '/dashboard/events.php',       'label' => 'Events',        'icon' => '☉'],
        ['key' => 'health',    'href' => '/dashboard/health.php',       'label' => 'Site Health',   'icon' => '◈'],
    ]],
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> · Moonlight Dashboard</title>
    <link rel="stylesheet" href="/dashboard/assets/dashboard.css">
    <meta name="robots" content="noindex, nofollow">
</head>
<body>

<div class="app">

    <aside class="sidebar">
        <a href="/dashboard/" class="sidebar-brand">
            <img src="/assets/images/logos/Moonlight Otaku Nights Logo no background clean version.png" alt="Moonlight">
            <div class="sidebar-brand-text">
                Moonlight
                <span>運営 · OPS</span>
            </div>
        </a>

        <?php foreach ($nav as [$group, $items]): ?>
        <nav class="nav-section">
            <div class="nav-heading"><?= htmlspecialchars($group) ?></div>
            <?php foreach ($items as $it): ?>
            <a href="<?= htmlspecialchars($it['href']) ?>"
               class="nav-link<?= $it['key'] === $page_active ? ' is-active' : '' ?>">
                <span class="nav-link-icon" aria-hidden="true"><?= htmlspecialchars($it['icon']) ?></span>
                <span><?= htmlspecialchars($it['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endforeach; ?>

        <div class="sidebar-foot">
            <div>Signed in as <strong><?= htmlspecialchars($user['email']) ?></strong></div>
            <a href="/dashboard/auth/logout.php">Sign out →</a>
        </div>
    </aside>

    <main class="main">
        <?= $page_body ?>
    </main>

</div>

</body>
</html>
