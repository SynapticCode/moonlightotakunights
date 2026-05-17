<?php
/**
 * submissions.php — Unified inbox for sponsor / investor / dj / idol / vendor applications.
 *
 * GET ?id=N           → detail view + status form
 * POST (with id)      → update status + owner_notes
 * GET ?export=csv     → CSV dump for current filter (AI-feed ready)
 * GET ?export=json    → JSON dump for current filter (AI-feed ready)
 * GET (no id)         → list view with filters
 */

declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$validKinds    = ['sponsor','investor','dj','idol','vendor'];
$validStatuses = ['new','reviewing','contacted','accepted','declined','spam'];

/* ----------------------- POST: update submission ----------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['id'])) {
    $id     = (int) $_POST['id'];
    $status = (string) ($_POST['status'] ?? '');
    $notes  = trim((string) ($_POST['owner_notes'] ?? ''));

    if (!in_array($status, $validStatuses, true)) {
        $error = 'Invalid status.';
    } else {
        $touchContacted = in_array($status, ['contacted','accepted','declined'], true) ? 'NOW()' : 'contacted_at';
        $touchDecided   = in_array($status, ['accepted','declined'], true) ? 'NOW()' : 'decided_at';
        db_exec(
            "UPDATE submissions
                SET status = :s,
                    owner_notes = :n,
                    contacted_at = $touchContacted,
                    decided_at   = $touchDecided
              WHERE id = :id",
            [':s' => $status, ':n' => $notes ?: null, ':id' => $id]
        );
        header('Location: /submissions.php?id=' . $id);
        exit;
    }
}

/* ----------------------- Filters ----------------------- */
$kind   = (string)($_GET['kind']   ?? '');
$status = (string)($_GET['status'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$id     = (int)   ($_GET['id']     ?? 0);

$where = [];
$params = [];
if (in_array($kind, $validKinds, true)) {
    $where[] = 'kind = :k';
    $params[':k'] = $kind;
}
if (in_array($status, $validStatuses, true)) {
    $where[] = 'status = :st';
    $params[':st'] = $status;
}
if ($q !== '') {
    $where[] = '(email LIKE :q OR full_name LIKE :q OR org_name LIKE :q OR pitch LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ----------------------- Export ----------------------- */
$export = (string)($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'json') {
    $rows = db_fetch_all(
        "SELECT id, kind, status, full_name, email, phone, instagram, org_name,
                website, pitch, details, owner_notes, source_page, created_at,
                contacted_at, decided_at
           FROM submissions $whereSql ORDER BY created_at DESC LIMIT 2000",
        $params
    ) ?: [];

    if ($export === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="moonlight-submissions-' . date('Y-m-d') . '.json"');
        // Decode details JSON for AI consumption
        foreach ($rows as &$r) {
            if (!empty($r['details'])) $r['details'] = json_decode($r['details'], true);
        }
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="moonlight-submissions-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, $r);
    } else {
        fputcsv($out, ['id','kind','status','full_name','email','pitch']);
    }
    fclose($out);
    exit;
}

/* ----------------------- Detail view ----------------------- */
if ($id > 0) {
    $row = db_fetch("SELECT * FROM submissions WHERE id = :id", [':id' => $id]);
    if (!$row) {
        http_response_code(404);
        echo 'Submission not found.';
        exit;
    }
    $details = !empty($row['details']) ? json_decode($row['details'], true) : [];

    $page_title  = 'Submission #' . $row['id'];
    $page_active = 'submissions';
    ob_start();
    ?>
    <div class="topbar">
        <div>
            <h1><?= htmlspecialchars(ucfirst($row['kind'])) ?> · <?= htmlspecialchars($row['full_name']) ?></h1>
            <p class="topbar-sub"><?= htmlspecialchars($row['email']) ?> · received <?= htmlspecialchars(date('M j, Y g:i a', strtotime($row['created_at']))) ?></p>
        </div>
        <div class="topbar-actions">
            <a href="/submissions.php?<?= http_build_query(['kind'=>$row['kind']]) ?>" class="btn btn-ghost" style="width:auto;">← BACK</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <table class="data-table">
                <tr><th style="width:160px;">Kind</th><td><span class="tag"><?= htmlspecialchars($row['kind']) ?></span></td></tr>
                <tr><th>Status</th><td><span class="tag tag--<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td></tr>
                <tr><th>Name</th><td><?= htmlspecialchars($row['full_name']) ?></td></tr>
                <tr><th>Email</th><td><a href="mailto:<?= htmlspecialchars($row['email']) ?>"><?= htmlspecialchars($row['email']) ?></a></td></tr>
                <?php if ($row['phone']): ?><tr><th>Phone</th><td><?= htmlspecialchars($row['phone']) ?></td></tr><?php endif; ?>
                <?php if ($row['instagram']): ?><tr><th>Instagram</th><td><a href="https://instagram.com/<?= htmlspecialchars($row['instagram']) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($row['instagram']) ?></a></td></tr><?php endif; ?>
                <?php if ($row['org_name']): ?><tr><th>Org / Brand</th><td><?= htmlspecialchars($row['org_name']) ?></td></tr><?php endif; ?>
                <?php if ($row['website']): ?><tr><th>Website</th><td><a href="<?= htmlspecialchars($row['website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($row['website']) ?></a></td></tr><?php endif; ?>
                <tr><th>Pitch</th><td><pre style="white-space:pre-wrap;font-family:inherit;margin:0;"><?= htmlspecialchars($row['pitch']) ?></pre></td></tr>
                <?php foreach ($details as $k => $v): if ($v === null || $v === '' || $v === 0) continue; ?>
                    <tr><th><?= htmlspecialchars(ucwords(str_replace('_',' ',$k))) ?></th><td><?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></td></tr>
                <?php endforeach; ?>
                <tr><th>Source page</th><td><code><?= htmlspecialchars($row['source_page'] ?? '—') ?></code></td></tr>
                <?php if ($row['contacted_at']): ?><tr><th>Contacted</th><td><?= htmlspecialchars(date('M j, Y g:i a', strtotime($row['contacted_at']))) ?></td></tr><?php endif; ?>
                <?php if ($row['decided_at']): ?><tr><th>Decided</th><td><?= htmlspecialchars(date('M j, Y g:i a', strtotime($row['decided_at']))) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Update</h2></div>
        <div class="panel-body">
            <form method="post" class="composer-form">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <div class="form-row">
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($validStatuses as $s): ?>
                            <option value="<?= $s ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>Owner notes (private)</label>
                    <textarea name="owner_notes" rows="4"><?= htmlspecialchars($row['owner_notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:auto;">SAVE</button>
            </form>
        </div>
    </div>
    <?php
    $page_body = ob_get_clean();
    include __DIR__ . '/views/_layout.php';
    exit;
}

/* ----------------------- List view ----------------------- */
$total = (int) (db_fetch("SELECT COUNT(*) c FROM submissions $whereSql", $params)['c'] ?? 0);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$rows = db_fetch_all(
    "SELECT id, kind, status, full_name, email, org_name, source_page, created_at
       FROM submissions $whereSql
       ORDER BY created_at DESC
       LIMIT $perPage OFFSET $offset",
    $params
) ?: [];

$totalPages = max(1, (int) ceil($total / $perPage));

// Quick counts per kind for chip nav
$kindCounts = [];
foreach (db_fetch_all("SELECT kind, COUNT(*) c FROM submissions WHERE status='new' GROUP BY kind") ?: [] as $r) {
    $kindCounts[$r['kind']] = (int)$r['c'];
}

$page_title  = 'Submissions';
$page_active = 'submissions';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Submissions</h1>
        <p class="topbar-sub">応募 · <?= number_format($total) ?> records</p>
    </div>
    <div class="topbar-actions">
        <?php $exportQs = http_build_query(array_filter(['kind'=>$kind,'status'=>$status,'q'=>$q])); ?>
        <a href="?<?= $exportQs ?>&export=csv"  class="btn btn-ghost" style="width:auto;">EXPORT CSV</a>
        <a href="?<?= $exportQs ?>&export=json" class="btn btn-ghost" style="width:auto;">EXPORT JSON</a>
    </div>
</div>

<div class="panel">
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, email, brand, pitch…" autofocus>
        <select name="kind">
            <option value="">All kinds</option>
            <?php foreach ($validKinds as $k): ?>
                <option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>>
                    <?= ucfirst($k) ?><?= !empty($kindCounts[$k]) ? ' (' . $kindCounts[$k] . ' new)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost" style="width:auto; padding: 8px 14px;">FILTER</button>
        <div class="toolbar-spacer"></div>
        <span style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim);">Page <?= $page ?> / <?= $totalPages ?></span>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">
            <div class="empty-icon">◐</div>
            <p>No submissions yet. The five form pages live at <code>/djs</code>, <code>/idols</code>, <code>/vendors</code>, <code>/investors</code>, and <code>/sponsors/apply</code>.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Kind</th>
                <th>Name</th>
                <th>Email</th>
                <th>Org / Brand</th>
                <th>Status</th>
                <th>Received</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="tag"><?= htmlspecialchars($r['kind']) ?></span></td>
                <td><?= htmlspecialchars($r['full_name']) ?></td>
                <td class="email"><?= htmlspecialchars($r['email']) ?></td>
                <td><?= htmlspecialchars($r['org_name'] ?? '—') ?></td>
                <td><span class="tag tag--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="num"><?= htmlspecialchars(date('M j, Y', strtotime($r['created_at']))) ?></td>
                <td><a href="/submissions.php?id=<?= (int)$r['id'] ?>" class="btn btn-ghost" style="width:auto;padding:4px 10px;">VIEW →</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="toolbar" style="border-top: 1px solid var(--border); border-bottom: none;">
        <?php
            $base = '?' . http_build_query(array_filter(['kind'=>$kind,'status'=>$status,'q'=>$q]));
            $sep  = $base === '?' ? '' : '&';
        ?>
        <?php if ($page > 1): ?>
            <a href="<?= $base . $sep ?>page=<?= $page - 1 ?>" class="btn btn-ghost" style="width:auto;">← PREV</a>
        <?php endif; ?>
        <div class="toolbar-spacer"></div>
        <?php if ($page < $totalPages): ?>
            <a href="<?= $base . $sep ?>page=<?= $page + 1 ?>" class="btn btn-ghost" style="width:auto;">NEXT →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
