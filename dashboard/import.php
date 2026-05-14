<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$page_title  = 'CSV Import';
$page_active = 'import';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>CSV Import</h1>
        <p class="topbar-sub">輸入 · ONE-TIME MIGRATION FROM FORMSPREE / BREVO / POSH / EVENTBRITE</p>
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
                <select id="src" name="source" required>
                    <option value="">Choose…</option>
                    <option value="import_formspree">Formspree (cosplay signups)</option>
                    <option value="import_brevo">Brevo (newsletter)</option>
                    <option value="import_posh">Posh (ticket buyers)</option>
                    <option value="import_eventbrite">Eventbrite (attendees)</option>
                    <option value="import_manual">Manual / Other</option>
                </select>
                <small class="form-help">Which platform this CSV came from. Used to tag every imported row so you can segment later.</small>
            </div>

            <div class="form-row">
                <label>CSV file</label>
                <label for="csv" class="dropzone" id="dropzone">
                    <strong>DROP CSV OR CLICK TO BROWSE</strong>
                    <span>UTF-8 encoded. First row must be headers. Email column required.</span>
                    <input type="file" id="csv" name="csv" accept=".csv,text/csv" required hidden>
                </label>
                <p id="filename" class="form-filename"></p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" style="width:auto;">IMPORT</button>
            </div>
            <p class="auth-status" id="import-status"></p>
        </form>

        <div id="import-result" style="margin-top: 24px;"></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">How it works</h2>
    </div>
    <div class="panel-body">
        <ul style="line-height: 1.85; color: var(--text-mute); padding-left: 18px; margin: 0;">
            <li>Rows are matched on <code>email</code> (case-insensitive). Existing contacts get patched, never overwritten with blanks.</li>
            <li>Every imported row creates a <code>contact_sources</code> entry tagging where it came from and when.</li>
            <li>Imports do <strong>not</strong> trigger a verification email — these are pre-existing contacts.</li>
            <li>Status defaults to <code>verified</code> for known opt-ins (Brevo, Posh attendees). Adjust later if needed.</li>
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
        setStatus('Uploading and importing… (this can take a minute for large files)');
        result.innerHTML = '';

        const fd = new FormData(form);
        try {
            const r = await fetch('/api/import-csv.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Import failed');
            setStatus(`Imported ${data.created} new, updated ${data.updated}, skipped ${data.skipped} of ${data.total}.`, 'ok');
            if (data.errors && data.errors.length) {
                result.innerHTML = '<div class="auth-note auth-note--warn"><strong>Warnings:</strong><br>' +
                    data.errors.slice(0, 20).map(e => '• ' + e).join('<br>') + '</div>';
            }
        } catch (err) {
            setStatus(err.message, 'err');
        }
    });
})();
</script>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
