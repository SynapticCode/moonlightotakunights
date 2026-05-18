<?php
/**
 * outbox.php — Approval queue for automated outbound email.
 *
 * GET                  → list view (filter by status, kind, funnel)
 * GET ?id=N            → detail view with editable draft + approve/reject
 * POST action=approve  → outbox_send (immediately sends via SES)
 * POST action=reject   → outbox_reject
 * POST action=update   → outbox_update_draft (edit copy before sending)
 */

declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/outbox.php';
require_once __DIR__ . '/auth/session.php';

$user   = require_login();
$actor  = $user['email'] ?? 'operator';
$pdo    = db();
$flash  = null;
$error  = null;

/* ----------------------- POST handlers ----------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id     = (int)($_POST['id']     ?? 0);
    $action = (string)($_POST['action'] ?? '');

    if ($id <= 0) {
        $error = 'Missing outbox id.';
    } else {
        switch ($action) {
            case 'approve':
                $r = outbox_send($id, $actor);
                $flash = $r['ok'] ? "Sent #$id." : "Send failed for #$id: " . ($r['error'] ?? 'unknown');
                if (!$r['ok']) $error = $flash;
                break;

            case 'reject':
                $note = trim((string)($_POST['note'] ?? ''));
                $r = outbox_reject($id, $actor, $note ?: null);
                $flash = $r['ok'] ? "Rejected #$id." : ('Reject failed: ' . ($r['error'] ?? 'unknown'));
                break;

            case 'update':
                $r = outbox_update_draft($id, [
                    'subject'   => (string)($_POST['subject']   ?? ''),
                    'html_body' => (string)($_POST['html_body'] ?? ''),
                    'to_email'  => (string)($_POST['to_email']  ?? ''),
                    'to_name'   => (string)($_POST['to_name']   ?? ''),
                    'reply_to'  => (string)($_POST['reply_to']  ?? ''),
                ], $actor);
                $flash = $r['ok'] ? "Draft #$id saved." : ('Save failed: ' . ($r['error'] ?? 'unknown'));
                break;

            default:
                $error = 'Unknown action.';
        }
    }

    // PRG to avoid resubmit
    $redirect = '/outbox.php';
    if (!empty($_POST['return_to'])) $redirect = $_POST['return_to'];
    if ($flash) $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'flash=' . urlencode($flash);
    header('Location: ' . $redirect);
    exit;
}

/* ----------------------- Filters / detail ----------------------- */
$id     = (int)($_GET['id']     ?? 0);
$status = (string)($_GET['status'] ?? 'pending');
$kind   = trim((string)($_GET['kind']   ?? ''));
$funnel = trim((string)($_GET['funnel'] ?? ''));
$flash  = $_GET['flash'] ?? null;

$validStatuses = ['pending','approved','sent','rejected','failed'];
if (!in_array($status, $validStatuses, true)) $status = 'pending';

/* Detail view --------------------------------------------------- */
$detail = null;
$detail_actions = [];
if ($id > 0) {
    $s = $pdo->prepare('SELECT * FROM outbox WHERE id = :id LIMIT 1');
    $s->execute([':id' => $id]);
    $detail = $s->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($detail) {
        $s = $pdo->prepare(
            'SELECT * FROM outbox_actions WHERE outbox_id = :id ORDER BY id DESC LIMIT 50'
        );
        $s->execute([':id' => $id]);
        $detail_actions = $s->fetchAll(PDO::FETCH_ASSOC);

        // Log the view (best-effort)
        outbox_log_action($id, 'viewed', $actor);
    }
}

/* List view ---------------------------------------------------- */
$where = ['status = :status'];
$params = [':status' => $status];
if ($kind !== '') {
    $where[] = 'kind = :kind';
    $params[':kind'] = $kind;
}
if ($funnel !== '') {
    $where[] = 'funnel = :funnel';
    $params[':funnel'] = $funnel;
}
$whereSql = implode(' AND ', $where);

$rows = [];
try {
    $sql = "SELECT id, kind, funnel, to_email, to_name, subject, status,
                   created_at, reviewed_at, sent_at, error_summary
            FROM outbox
            WHERE $whereSql
            ORDER BY id DESC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Query failed: ' . $e->getMessage();
}

/* Counts per status for chip nav */
$statusCounts = [];
try {
    $sc = $pdo->query("SELECT status, COUNT(*) c FROM outbox GROUP BY status");
    foreach ($sc as $r) $statusCounts[$r['status']] = (int)$r['c'];
} catch (Throwable $e) { /* swallow */ }

$page_title  = 'Outbox';
$page_active = 'outbox';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Outbox</h1>
        <p class="topbar-sub">送信前承認 · review every automated email before it goes out</p>
    </div>
</div>

<?php if ($flash): ?>
<div class="panel" style="margin-bottom:14px;background:rgba(120,200,140,0.08);border-color:rgba(120,200,140,0.3)">
    <p style="margin:0;padding:10px 14px;font-family:var(--font-mono);font-size:13px;"><?= htmlspecialchars($flash) ?></p>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="panel" style="margin-bottom:14px;background:rgba(220,80,80,0.08);border-color:rgba(220,80,80,0.3)">
    <p style="margin:0;padding:10px 14px;color:#ff8a8a;"><?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>

<?php if ($detail): ?>
<!-- ===================== DETAIL VIEW ===================== -->
<div class="panel" style="padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:16px;">
        <div>
            <div style="font-family:var(--font-mono);font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.1em;">
                <?= htmlspecialchars($detail['kind']) ?>
                <?php if (!empty($detail['funnel'])): ?>· <?= htmlspecialchars($detail['funnel']) ?><?php endif; ?>
                · #<?= (int)$detail['id'] ?>
            </div>
            <h2 style="margin:6px 0 0 0;font-size:22px;">Draft for <?= htmlspecialchars($detail['to_email']) ?></h2>
            <p style="margin:4px 0 0;color:var(--text-dim);font-size:13px;">
                Status: <strong><?= htmlspecialchars($detail['status']) ?></strong>
                · Queued <?= htmlspecialchars(date('M j, Y g:ia', strtotime($detail['created_at']))) ?>
                <?php if ($detail['sent_at']): ?>· Sent <?= htmlspecialchars(date('M j g:ia', strtotime($detail['sent_at']))) ?><?php endif; ?>
            </p>
        </div>
        <a href="/outbox.php?status=<?= urlencode($detail['status']) ?>" class="btn btn-ghost" style="width:auto;">← BACK</a>
    </div>

    <?php if ($detail['error_summary']): ?>
    <div style="background:rgba(220,80,80,0.1);padding:10px 14px;border-radius:6px;margin-bottom:16px;color:#ffb0b0;font-family:var(--font-mono);font-size:12px;">
        <?= htmlspecialchars($detail['error_summary']) ?>
    </div>
    <?php endif; ?>

    <form method="post" style="display:flex;flex-direction:column;gap:14px;">
        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <input type="hidden" name="return_to" value="/outbox.php?id=<?= (int)$detail['id'] ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <label style="font-size:12px;color:var(--text-dim);">
                <div style="margin-bottom:4px;font-family:var(--font-mono);letter-spacing:0.08em;">TO EMAIL</div>
                <input name="to_email" type="email" value="<?= htmlspecialchars($detail['to_email']) ?>"
                       style="width:100%;padding:8px 10px;background:var(--bg-input,#0e0e14);border:1px solid var(--border);color:var(--text);border-radius:6px;">
            </label>
            <label style="font-size:12px;color:var(--text-dim);">
                <div style="margin-bottom:4px;font-family:var(--font-mono);letter-spacing:0.08em;">TO NAME</div>
                <input name="to_name" type="text" value="<?= htmlspecialchars((string)$detail['to_name']) ?>"
                       style="width:100%;padding:8px 10px;background:var(--bg-input,#0e0e14);border:1px solid var(--border);color:var(--text);border-radius:6px;">
            </label>
        </div>

        <label style="font-size:12px;color:var(--text-dim);">
            <div style="margin-bottom:4px;font-family:var(--font-mono);letter-spacing:0.08em;">SUBJECT</div>
            <input name="subject" type="text" value="<?= htmlspecialchars($detail['subject']) ?>"
                   style="width:100%;padding:8px 10px;background:var(--bg-input,#0e0e14);border:1px solid var(--border);color:var(--text);border-radius:6px;">
        </label>

        <label style="font-size:12px;color:var(--text-dim);">
            <div style="margin-bottom:4px;font-family:var(--font-mono);letter-spacing:0.08em;">HTML BODY</div>
            <textarea name="html_body" rows="14"
                      style="width:100%;padding:10px 12px;background:var(--bg-input,#0e0e14);border:1px solid var(--border);color:var(--text);border-radius:6px;font-family:var(--font-mono);font-size:12px;"><?= htmlspecialchars($detail['html_body']) ?></textarea>
        </label>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button name="action" value="update"  class="btn btn-ghost"   style="width:auto;">SAVE DRAFT</button>
            <?php if (in_array($detail['status'], ['pending','failed'], true)): ?>
                <button name="action" value="approve"
                        onclick="return confirm('Send this email to <?= htmlspecialchars($detail['to_email']) ?> now?');"
                        class="btn btn-primary" style="width:auto;">APPROVE &amp; SEND →</button>
            <?php endif; ?>
            <?php if ($detail['status'] === 'pending'): ?>
                <button name="action" value="reject"
                        onclick="return confirm('Reject this draft? It will not be sent.');"
                        class="btn btn-ghost" style="width:auto;color:#ff8a8a;border-color:rgba(220,80,80,0.3);">REJECT</button>
            <?php endif; ?>
        </div>
    </form>

    <h3 style="margin:28px 0 10px;font-size:14px;font-family:var(--font-mono);letter-spacing:0.1em;text-transform:uppercase;color:var(--text-dim);">RENDERED PREVIEW</h3>
    <div style="background:#fff;color:#111;padding:20px;border-radius:8px;max-height:600px;overflow:auto;">
        <?= $detail['html_body'] // intentional: trusted, operator-only view ?>
    </div>

    <h3 style="margin:28px 0 10px;font-size:14px;font-family:var(--font-mono);letter-spacing:0.1em;text-transform:uppercase;color:var(--text-dim);">AUDIT LOG</h3>
    <table class="data-table">
        <thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($detail_actions as $a): ?>
            <tr>
                <td class="num"><?= htmlspecialchars(date('M j g:ia', strtotime($a['created_at']))) ?></td>
                <td><span class="tag"><?= htmlspecialchars($a['action']) ?></span></td>
                <td><?= htmlspecialchars((string)$a['actor']) ?></td>
                <td><?= htmlspecialchars((string)$a['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php else: ?>
<!-- ===================== LIST VIEW ===================== -->
<div class="panel">
    <form class="toolbar" method="get">
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php foreach ($validStatuses as $s):
            $c = $statusCounts[$s] ?? 0;
            $active = $s === $status;
        ?>
            <a href="?status=<?= $s ?>"
               class="btn btn-ghost"
               style="width:auto;padding:6px 12px;<?= $active ? 'background:var(--accent,#7a5cff);color:#fff;border-color:transparent;' : '' ?>">
               <?= strtoupper($s) ?><?= $c ? ' (' . $c . ')' : '' ?>
            </a>
        <?php endforeach; ?>
        </div>
        <div class="toolbar-spacer"></div>
        <select name="funnel" onchange="this.form.submit()">
            <option value="">All funnels</option>
            <?php foreach (['sponsor','investor','dj','idol','vendor','cosplay','guild','donation'] as $f): ?>
                <option value="<?= $f ?>" <?= $funnel===$f?'selected':'' ?>><?= ucfirst($f) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
    </form>

    <?php if (!$rows): ?>
        <div class="empty">
            <div class="empty-icon">✉</div>
            <p>No <?= htmlspecialchars($status) ?> drafts. Automated emails from form submissions queue here for your approval before they go out.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Kind</th>
                <th>To</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Queued</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <span class="tag"><?= htmlspecialchars($r['kind']) ?></span>
                    <?php if (!empty($r['funnel'])): ?>
                        <span class="tag tag--<?= htmlspecialchars($r['funnel']) ?>" style="margin-left:4px;"><?= htmlspecialchars($r['funnel']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($r['to_email']) ?>
                    <?php if (!empty($r['to_name'])): ?>
                        <div style="font-size:11px;color:var(--text-dim);"><?= htmlspecialchars($r['to_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($r['subject']) ?>
                </td>
                <td><span class="tag tag--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="num"><?= htmlspecialchars(date('M j g:ia', strtotime($r['created_at']))) ?></td>
                <td><a href="/outbox.php?id=<?= (int)$r['id'] ?>" class="btn btn-ghost" style="width:auto;padding:4px 10px;">REVIEW →</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
