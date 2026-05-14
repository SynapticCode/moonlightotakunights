<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

// Toggle cosplay_contest_active inline
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['toggle_event'])) {
    $id = (int) $_POST['toggle_event'];
    if ($id) {
        db_exec("UPDATE events SET cosplay_contest_active = 1 - cosplay_contest_active WHERE id = :id", [':id' => $id]);
    }
    header('Location: /events.php');
    exit;
}

$events = db_fetch_all("SELECT * FROM events ORDER BY event_date DESC, id DESC") ?: [];

$page_title  = 'Events';
$page_active = 'events';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Events</h1>
        <p class="topbar-sub">イベント · <?= count($events) ?> events on file</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">All Events</h2>
    </div>
    <?php if (!$events): ?>
        <div class="empty"><div class="empty-icon">☉</div><p>No events.</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Date</th>
                <th>Status</th>
                <th>Cosplay Contest</th>
                <th>Page</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['name']) ?></td>
                <td><code><?= htmlspecialchars($e['slug']) ?></code></td>
                <td class="num"><?= $e['event_date'] ? htmlspecialchars(date('M j, Y', strtotime($e['event_date']))) : '—' ?></td>
                <td><span class="tag tag--<?= $e['status'] === 'past' ? 'neutral' : 'verified' ?>"><?= htmlspecialchars($e['status']) ?></span></td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="toggle_event" value="<?= (int)$e['id'] ?>">
                        <button type="submit" class="tag tag--<?= $e['cosplay_contest_active'] ? 'verified' : 'pending' ?>" style="border:none;cursor:pointer;">
                            <?= $e['cosplay_contest_active'] ? 'ACTIVE' : 'inactive' ?>
                        </button>
                    </form>
                </td>
                <td><?php if ($e['page_path']): ?><?php $public_url = rtrim(config('app')['base_url'], '/') . '/' . ltrim($e['page_path'], '/'); ?><a href="<?= htmlspecialchars($public_url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($e['page_path']) ?></a><?php else: ?>—<?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
