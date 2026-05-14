<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

// Pull events for the optional event-tag dropdown
$events = db_fetch_all(
    "SELECT id, name, event_date FROM events
      WHERE status IN ('past','live','upcoming')
      ORDER BY COALESCE(event_date, '1970-01-01') DESC, id DESC"
) ?: [];

// Pull recent imports for the activity panel
$recent = db_fetch_all(
    "SELECT id, source, filename, rows_total, rows_created, rows_updated,
            rows_attendees, status, started_at
       FROM import_jobs
       ORDER BY started_at DESC
       LIMIT 8"
) ?: [];

$page_title  = 'CSV Import';
$page_active = 'import';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>CSV Import</h1>
        <p class="topbar-sub">輸入 · DROP A CSV — POSH / BREVO / COSPLAY / EVENTBRITE — AUTO-DETECTED</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Upload CSV</h2>
    </div>
    <div class="panel-body">
        <form id="import-form" class="composer-form" enctype="multipart/form-data">
            <div class="form-row">
                <label for="src">Source</label>
                <select id="src" name="source">
                    <option value="auto" selected>Auto-detect (recommended)</option>
                    <option value="import_posh">Posh — ticket buyers</option>
                    <option value="import_brevo">Brevo — newsletter list</option>
                    <option value="cosplay_signup">Cosplay contest signups</option>
                    <option value="import_eventbrite">Eventbrite — attendees</option>
                    <option value="import_manual">Generic CSV (email + name)</option>
                </select>
                <small class="form-help">Leave on auto-detect unless the file doesn't match its real source. The importer recognises Posh, Brevo, cosplay exports, and Eventbrite by their column headers.</small>
            </div>

            <div class="form-row">
                <label for="event_id">Tag against an event (optional)</label>
                <select id="event_id" name="event_id">
                    <option value="">— none —</option>
                    <?php foreach ($events as $e): ?>
                        <option value="<?= (int)$e['id'] ?>">
                            <?= htmlspecialchars($e['name']) ?><?= $e['event_date'] ? ' — ' . htmlspecialchars(date('M j, Y', strtotime($e['event_date']))) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-help">Posh + Eventbrite imports tagged to an event create attendee records with scan/purchase data, used by the Operations module.</small>
            </div>

            <div class="form-row">
                <label>CSV file</label>
                <label for="csv" class="dropzone" id="dropzone">
                    <strong>DROP CSV OR CLICK TO BROWSE</strong>
                    <span>Comma, semicolon, or tab delimited. First row = headers. UTF-8 or BOM both fine.</span>
                    <input type="file" id="csv" name="csv" accept=".csv,text/csv" required hidden>
                </label>
                <p id="filename" class="form-filename"></p>
            </div>

            <div class="form-row" style="display:flex; gap:8px; align-items:center;">
                <label style="display:flex; gap:8px; align-items:center; cursor:pointer; margin:0;">
                    <input type="checkbox" id="dry_run" name="dry_run" value="1">
                    <span>Dry run (preview without writing)</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" style="width:auto;">IMPORT</button>
            </div>
            <p class="auth-status" id="import-status"></p>
        </form>

        <div id="import-result" style="margin-top: 24px;"></div>
    </div>
</div>

<?php if ($recent): ?>
<div class="panel">
    <div class="panel-head"><h2 class="panel-title">Recent imports</h2></div>
    <div class="panel-body" style="padding: 0;">
        <table class="data-table">
            <thead>
                <tr><th>When</th><th>Source</th><th>File</th><th class="num">Rows</th><th class="num">New</th><th class="num">Updated</th><th class="num">Attendees</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="num"><?= htmlspecialchars(date('M j, g:i A', strtotime($r['started_at']))) ?></td>
                    <td><code><?= htmlspecialchars($r['source']) ?></code></td>
                    <td><?= htmlspecialchars($r['filename'] ?? '—') ?></td>
                    <td class="num"><?= (int)$r['rows_total'] ?></td>
                    <td class="num"><?= (int)$r['rows_created'] ?></td>
                    <td class="num"><?= (int)$r['rows_updated'] ?></td>
                    <td class="num"><?= (int)$r['rows_attendees'] ?></td>
                    <td><span class="tag tag--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2 class="panel-title">How it works</h2></div>
    <div class="panel-body">
        <ul style="line-height: 1.85; color: var(--text-mute); padding-left: 18px; margin: 0;">
            <li>Rows are matched on <code>email</code> (case-insensitive). Existing contacts get patched — blank fields never overwrite real data.</li>
            <li>Every row logs a <code>contact_sources</code> entry tagging where it came from + the file row number.</li>
            <li>Imports do <strong>not</strong> trigger a verification email — pre-opted contacts (Posh, Brevo, Eventbrite, cosplay) are imported as verified.</li>
            <li>Posh + Eventbrite imports tagged to an event also populate <code>event_attendees</code> with scan status, ticket price, gender, and city — needed by the Operations module for accurate cost-per-attendee.</li>
        </ul>
    </div>
</div>

<script>
(function () {
    const form    = document.getElementById('import-form');
    const csv     = document.getElementById('csv');
    const drop    = document.getElementById('dropzone');
    const fname   = document.getElementById('filename');
    const status  = document.getElementById('import-status');
    const result  = document.getElementById('import-result');

    function setStatus(msg, kind) {
        status.textContent = msg || '';
        status.className = 'auth-status' + (kind ? ' auth-status--' + kind : '');
    }
    function esc(s) { return String(s ?? '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }

    drop.addEventListener('click', () => csv.click());
    csv.addEventListener('change', () => {
        if (csv.files[0]) fname.textContent = '📄 ' + csv.files[0].name + ' (' + Math.round(csv.files[0].size / 1024) + ' KB)';
    });
    ['dragenter','dragover'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('is-dragover'); }));
    ['dragleave','drop'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.remove('is-dragover'); }));
    drop.addEventListener('drop', ev => {
        if (ev.dataTransfer.files[0]) {
            csv.files = ev.dataTransfer.files;
            fname.textContent = '📄 ' + csv.files[0].name;
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!csv.files[0]) { setStatus('Choose a CSV file first.', 'err'); return; }
        const isDry = document.getElementById('dry_run').checked;
        setStatus(isDry ? 'Running dry-run…' : 'Uploading and importing… (can take a minute on large files)');
        result.innerHTML = '';

        const fd = new FormData(form);
        try {
            const r = await fetch('/api/import-csv.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Import failed');

            const detected = data.detected ? `${data.detected.label} (${data.detected.confidence})` : '—';
            setStatus(
                isDry
                    ? `Dry run — detected ${detected}. ${data.total} rows, ${data.created} new, ${data.updated} updates.`
                    : `Done. ${data.created} new · ${data.updated} updates · ${data.skipped} skipped · ${data.attendees} attendees · ${data.total} total.`,
                'ok'
            );

            let html = '<div class="auth-note auth-note--ok"><strong>Result:</strong><br>'
                + '<code>source=' + esc(data.source) + ' · delimiter=' + esc(data.delimiter) + ' · detected=' + esc(detected) + '</code></div>';

            if (data.preview_rows && data.preview_rows.length) {
                html += '<div style="margin-top:16px;"><strong>Preview (first 20 rows):</strong>'
                    + '<table class="data-table" style="margin-top:8px;"><thead><tr><th>Email</th><th>Name</th><th>Phone</th><th>IG</th></tr></thead><tbody>';
                for (const r of data.preview_rows) {
                    html += '<tr><td class="email">' + esc(r.email) + '</td><td>' + esc(r.name || '—') + '</td><td>' + esc(r.phone || '—') + '</td><td>' + esc(r.ig || '—') + '</td></tr>';
                }
                html += '</tbody></table></div>';
            }
            if (data.errors && data.errors.length) {
                html += '<div class="auth-note auth-note--warn" style="margin-top:12px;"><strong>' + data.errors.length + ' warning(s):</strong><br>'
                    + data.errors.slice(0, 25).map(e => '• ' + esc(e)).join('<br>') + '</div>';
            }
            result.innerHTML = html;
        } catch (err) {
            setStatus(err.message, 'err');
        }
    });
})();
</script>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
