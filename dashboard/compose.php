<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/audit.php';
require_once __DIR__ . '/../api/includes/segments.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$segDefs   = segments_definitions();
$segCounts = segment_counts();
$senders   = senders_list();

$page_title  = 'Compose';
$page_active = 'compose';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Compose Broadcast</h1>
        <p class="topbar-sub">放送 · BROADCAST COMPOSER</p>
    </div>
</div>

<div class="composer-grid">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Compose</h2>
        </div>
        <div class="panel-body">
            <form id="compose-form" class="composer-form">

                <div class="form-row">
                    <label for="from">From address</label>
                    <select id="from" name="from">
                        <?php foreach ($senders as $s):
                            $verified = !empty($s['ses_verified_at']);
                        ?>
                            <option value="<?= htmlspecialchars($s['email']) ?>" <?= $s['is_default'] ? 'selected' : '' ?> <?= $verified ? '' : 'disabled' ?>>
                                <?= htmlspecialchars($s['display_name']) ?> &lt;<?= htmlspecialchars($s['email']) ?>&gt;<?= $verified ? '' : ' — pending verification' ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$senders): ?>
                            <option value="info@moonlightotakunights.com" selected>Moonlight Otaku Nights &lt;info@moonlightotakunights.com&gt;</option>
                        <?php endif; ?>
                    </select>
                    <small class="form-help">Which mailbox the email comes from. New addresses appear here once verified in SES.</small>
                </div>

                <div class="form-row">
                    <label for="seg">Segment</label>
                    <select id="seg" name="segment">
                        <?php foreach ($segDefs as $key => $def):
                            $n = (int)($segCounts[$key] ?? 0);
                        ?>
                            <option value="<?= htmlspecialchars($key) ?>"<?= $key === 'verified' ? ' selected' : '' ?>>
                                <?= htmlspecialchars($def['label']) ?> (<?= number_format($n) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-help">Counts refresh on page load. "Verified Guild" = double opt-in confirmed. Applicant segments use first-touch attribution (<code>first_source</code>). Use SEND TEST below to dry-run to yourself.</small>
                </div>

                <div class="form-row">
                    <label for="subj">Subject</label>
                    <input type="text" id="subj" name="subject" maxlength="200" required placeholder="What's the next event?">
                </div>

                <div class="form-row">
                    <label for="head">Heading (big text in email)</label>
                    <input type="text" id="head" name="heading" maxlength="80" required value="THE NEXT NIGHT">
                </div>

                <div class="form-row">
                    <label for="sub">Subheading (small japanese label)</label>
                    <input type="text" id="sub" name="subheading" maxlength="80" value="次回のイベント · NEXT EVENT">
                </div>

                <div class="form-row">
                    <label for="bod">Body</label>
                    <textarea id="bod" name="body" required placeholder="Write your message. Plain text or basic HTML. Line breaks preserved."></textarea>
                </div>

                <div class="form-row">
                    <label for="cta">CTA Label</label>
                    <input type="text" id="cta" name="cta_label" maxlength="40" value="MORE DETAILS">
                    <small class="form-help">Text on the big button inside the email (e.g. GET TICKETS, RSVP, LEARN MORE).</small>
                </div>

                <div class="form-row">
                    <label for="ctau">CTA URL</label>
                    <input type="url" id="ctau" name="cta_url" placeholder="https://moonlightotakunights.com/...">
                    <small class="form-help">Where the button takes the reader. Use a full URL with https://.</small>
                </div>

                <div style="display:flex; gap:8px; margin-top:8px;">
                    <button type="button" id="preview-btn" class="btn btn-ghost" style="width:auto;">↻ PREVIEW</button>
                    <button type="button" id="test-btn" class="btn btn-ghost" style="width:auto;">SEND TEST</button>
                    <button type="submit" class="btn btn-primary" style="width:auto;">SEND BROADCAST</button>
                </div>

                <p class="auth-status" id="compose-status"></p>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Preview</h2>
        </div>
        <div class="panel-body" style="padding: 0;">
            <iframe id="preview" class="preview-frame" title="Email preview"></iframe>
        </div>
    </div>
</div>

<script>
(function () {
    const form    = document.getElementById('compose-form');
    const preview = document.getElementById('preview');
    const status  = document.getElementById('compose-status');

    function getPayload() {
        const fd = new FormData(form);
        const obj = {};
        fd.forEach((v, k) => obj[k] = v);
        return obj;
    }
    function setStatus(msg, kind) {
        status.textContent = msg || '';
        status.className = 'auth-status' + (kind ? ' auth-status--' + kind : '');
    }

    async function refreshPreview() {
        const payload = getPayload();
        try {
            const r = await fetch('/api/preview.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const html = await r.text();
            preview.srcdoc = html;
        } catch (err) {
            setStatus('Preview failed: ' + err.message, 'err');
        }
    }

    document.getElementById('preview-btn').addEventListener('click', refreshPreview);

    document.getElementById('test-btn').addEventListener('click', async () => {
        setStatus('Sending test…');
        const r = await fetch('/api/send-broadcast.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...getPayload(), mode: 'test' })
        });
        const data = await r.json();
        setStatus(data.ok ? 'Test sent to your operator email.' : 'Test failed: ' + (data.error || ''), data.ok ? 'ok' : 'err');
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm('Send this broadcast to all matching contacts?')) return;
        setStatus('Queuing broadcast…');
        const r = await fetch('/api/send-broadcast.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...getPayload(), mode: 'send' })
        });
        const data = await r.json();
        setStatus(data.ok ? `Queued ${data.queued} recipients.` : 'Failed: ' + (data.error || ''), data.ok ? 'ok' : 'err');
    });

    // Live preview on input (debounced)
    let t;
    form.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(refreshPreview, 400);
    });
    refreshPreview();
})();
</script>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
