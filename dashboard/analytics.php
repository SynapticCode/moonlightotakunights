<?php
/**
 * analytics.php — Funnel & traffic analytics.
 *
 * Combines:
 *   - Internal funnel metrics: submissions by kind, conversion %, status mix,
 *     7/30-day trends from MySQL (submissions + contacts).
 *   - GA4 Data API: site sessions, users, pageviews, top pages, top sources,
 *     per-funnel landing-page traffic, page → submit conversion.
 *
 * Range selector: ?range=7|30|90 (default 30). Cached 5 min per range.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/ga4_client.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$range = (int) ($_GET['range'] ?? 30);
if (!in_array($range, [7, 30, 90], true)) $range = 30;

$KINDS = ['sponsor','investor','dj','idol','vendor'];
$KIND_LABELS = [
    'sponsor'  => 'Sponsors',
    'investor' => 'Investors',
    'dj'       => 'DJs',
    'idol'     => 'Idols',
    'vendor'   => 'Vendors',
];
$KIND_PATHS = [
    'sponsor'  => '/sponsors',
    'investor' => '/investors',
    'dj'       => '/djs',
    'idol'     => '/idols',
    'vendor'   => '/vendors',
];

/* ---------------- Internal funnel metrics ---------------- */

$rangeSql = (int) $range;

$totalSubs = (int) (db_fetch(
    "SELECT COUNT(*) c FROM submissions WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)",
    [':d' => $rangeSql]
)['c'] ?? 0);

$totalSubsAllTime = (int) (db_fetch("SELECT COUNT(*) c FROM submissions")['c'] ?? 0);

$subsByKind = db_fetch_all(
    "SELECT kind, COUNT(*) total,
            SUM(status='new')        AS new_count,
            SUM(status='reviewing')  AS reviewing_count,
            SUM(status='contacted')  AS contacted_count,
            SUM(status='accepted')   AS accepted_count,
            SUM(status='declined')   AS declined_count,
            SUM(status='spam')       AS spam_count
       FROM submissions
      WHERE created_at >= DATE_SUB(NOW(), INTERVAL $rangeSql DAY)
      GROUP BY kind"
) ?: [];

// Normalise into kind-keyed array
$byKind = array_fill_keys($KINDS, [
    'total'=>0,'new_count'=>0,'reviewing_count'=>0,'contacted_count'=>0,
    'accepted_count'=>0,'declined_count'=>0,'spam_count'=>0,
]);
foreach ($subsByKind as $row) {
    $k = (string) $row['kind'];
    if (isset($byKind[$k])) {
        $byKind[$k] = array_map('intval', $row);
        $byKind[$k]['total'] = (int) $row['total'];
    }
}

// Tier breakdown for sponsors (from details.tier_interest)
$sponsorTiers = db_fetch_all(
    "SELECT
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(details, '$.tier_interest')), ''), 'unspecified') AS tier,
        COUNT(*) c
       FROM submissions
      WHERE kind='sponsor'
        AND created_at >= DATE_SUB(NOW(), INTERVAL $rangeSql DAY)
      GROUP BY tier
      ORDER BY c DESC"
) ?: [];

// Time series (daily submissions in range)
$tsRaw = db_fetch_all(
    "SELECT DATE(created_at) d, kind, COUNT(*) c
       FROM submissions
      WHERE created_at >= DATE_SUB(NOW(), INTERVAL $rangeSql DAY)
      GROUP BY d, kind
      ORDER BY d ASC"
) ?: [];

// Build a date-indexed series
$ts = [];
for ($i = $rangeSql - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $ts[$d] = array_fill_keys($KINDS, 0) + ['_total' => 0];
}
foreach ($tsRaw as $r) {
    $d = (string) $r['d'];
    $k = (string) $r['kind'];
    if (!isset($ts[$d])) continue;
    $ts[$d][$k] = (int) $r['c'];
    $ts[$d]['_total'] += (int) $r['c'];
}

// Source-page breakdown
$bySourcePage = db_fetch_all(
    "SELECT COALESCE(NULLIF(source_page,''),'(unknown)') p, COUNT(*) c
       FROM submissions
      WHERE created_at >= DATE_SUB(NOW(), INTERVAL $rangeSql DAY)
      GROUP BY p
      ORDER BY c DESC
      LIMIT 12"
) ?: [];

// Recent submissions feed
$recent = db_fetch_all(
    "SELECT id, kind, full_name, email, status, created_at
       FROM submissions
      ORDER BY created_at DESC
      LIMIT 10"
) ?: [];

// Contacts attributed to a funnel (first_source ends in _apply)
$contactsFromFunnels = (int) (db_fetch(
    "SELECT COUNT(*) c FROM contacts
      WHERE first_source LIKE '%\\_apply'
        AND first_seen_at >= DATE_SUB(NOW(), INTERVAL $rangeSql DAY)"
)['c'] ?? 0);

/* ---------------- GA4 metrics ---------------- */

$ga4Enabled  = ga4_enabled();
$ga4Summary  = $ga4Enabled ? ga4_traffic_summary($range) : null;
$ga4Pages    = $ga4Enabled ? ga4_traffic_by_page($range, 15) : [];
$ga4Sources  = $ga4Enabled ? ga4_traffic_by_source($range, 12) : [];
$ga4Series   = $ga4Enabled ? ga4_traffic_timeseries($range) : [];
$ga4Funnels  = $ga4Enabled ? ga4_funnel_pageviews(array_values($KIND_PATHS), $range) : [];

// Build per-funnel conversion table: pageviews → submissions in range.
$funnelTable = [];
foreach ($KINDS as $k) {
    $path = $KIND_PATHS[$k];
    $pv   = (int) ($ga4Funnels[$path]['pageviews'] ?? 0);
    $u    = (int) ($ga4Funnels[$path]['users']     ?? 0);
    $subs = (int) ($byKind[$k]['total'] ?? 0);
    $conv = ($u > 0) ? round(($subs / $u) * 100, 2) : null;
    $funnelTable[$k] = [
        'label'       => $KIND_LABELS[$k],
        'path'        => $path,
        'pageviews'   => $pv,
        'users'       => $u,
        'submissions' => $subs,
        'conversion'  => $conv,
        'accepted'    => (int) ($byKind[$k]['accepted_count'] ?? 0),
    ];
}

// Timeseries JSON for chart
$tsLabels = array_keys($ts);
$tsTotals = array_map(fn($d) => $ts[$d]['_total'], $tsLabels);
$tsByKind = [];
foreach ($KINDS as $k) {
    $tsByKind[$k] = array_map(fn($d) => $ts[$d][$k], $tsLabels);
}

// GA4 series aligned to same labels (zero-fill missing)
$gaSessionsMap = [];
foreach ($ga4Series as $row) $gaSessionsMap[$row['date']] = (int) $row['sessions'];
$gaSeriesAligned = array_map(fn($d) => $gaSessionsMap[$d] ?? 0, $tsLabels);

$page_title  = 'Analytics';
$page_active = 'analytics';
ob_start();

function pct(float $n, int $digits = 0): string {
    return number_format($n * (in_array($digits, [0,1,2]) ? 100 : 1), $digits) . '%';
}
function fmtSeconds(float $s): string {
    if ($s <= 0) return '0s';
    $m = floor($s / 60); $sec = (int) round($s - $m * 60);
    return $m > 0 ? sprintf('%dm %02ds', $m, $sec) : sprintf('%ds', $sec);
}
function statusBadge(string $s): string {
    $map = [
        'new'        => 'badge--new',
        'reviewing'  => 'badge--review',
        'contacted'  => 'badge--contact',
        'accepted'   => 'badge--accept',
        'declined'   => 'badge--decline',
        'spam'       => 'badge--spam',
    ];
    $cls = $map[$s] ?? 'badge--new';
    return "<span class=\"badge $cls\">" . htmlspecialchars($s) . "</span>";
}
?>

<style>
/* Analytics-only styles. Kept inline to avoid bloating dashboard.css before review. */
.analytics-toolbar {
    display:flex; gap:8px; align-items:center; margin: 6px 0 20px;
    font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase;
}
.analytics-toolbar a {
    padding: 6px 14px; border: 1px solid rgba(255,255,255,0.08);
    border-radius: 999px; color: var(--text-dim); text-decoration: none;
}
.analytics-toolbar a.is-active {
    border-color: var(--acc-cyan); color: var(--acc-cyan);
}
.analytics-section { margin-top: 28px; }
.analytics-section h2 {
    font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.3em; text-transform: uppercase;
    color: var(--text-dim); margin: 0 0 12px;
}
.an-grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.an-grid-3 { display:grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
@media (max-width: 1100px) { .an-grid-2, .an-grid-3 { grid-template-columns: 1fr; } }
.an-card {
    background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06);
    border-radius: 14px; padding: 18px;
}
.an-card-title {
    font-family: var(--font-mono); font-size: 10.5px; letter-spacing: 0.28em;
    text-transform: uppercase; color: var(--text-mute); margin: 0 0 14px;
}
.an-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.an-table th, .an-table td {
    text-align: left; padding: 9px 8px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.an-table th {
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em;
    text-transform: uppercase; color: var(--text-mute); font-weight: 500;
}
.an-table tbody tr:hover { background: rgba(0,240,255,0.03); }
.an-table .num { text-align: right; font-family: var(--font-mono); }
.an-bar {
    display: inline-block; height: 6px; background: linear-gradient(90deg, var(--acc-magenta), var(--acc-purple));
    border-radius: 4px; vertical-align: middle;
}
.an-empty {
    color: var(--text-mute); font-style: italic; padding: 10px 4px; font-size: 13px;
}
.an-note {
    background: rgba(127,187,224,0.05);
    border: 1px solid rgba(127,187,224,0.18);
    border-radius: 10px; padding: 12px 14px; color: var(--text-dim);
    font-size: 13px; line-height: 1.5;
}
.an-note strong { color: var(--acc-ice); }
.badge {
    display: inline-block; padding: 2px 9px; border-radius: 999px;
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.18em;
    text-transform: uppercase; border: 1px solid;
}
.badge--new      { color: var(--acc-cyan);    border-color: rgba(0,240,255,0.35); }
.badge--review   { color: #ffd166;            border-color: rgba(255,209,102,0.35); }
.badge--contact  { color: var(--acc-ice);     border-color: rgba(127,187,224,0.35); }
.badge--accept   { color: var(--acc-good);    border-color: rgba(72,201,176,0.35); }
.badge--decline  { color: var(--acc-bad);     border-color: rgba(231,76,60,0.35); }
.badge--spam     { color: var(--text-mute);   border-color: rgba(255,255,255,0.12); }
.spark-wrap {
    position: relative; height: 180px; width: 100%;
}
.spark-wrap canvas { width: 100% !important; height: 100% !important; }
</style>

<div class="topbar">
    <div>
        <h1>Analytics</h1>
        <p class="topbar-sub">アナリティクス · FUNNEL + TRAFFIC</p>
    </div>
    <div class="topbar-actions">
        <a href="/submissions.php" class="btn btn-ghost" style="width:auto;">SUBMISSIONS INBOX</a>
    </div>
</div>

<div class="analytics-toolbar">
    <span style="color:var(--text-mute); margin-right:4px;">RANGE</span>
    <?php foreach ([7, 30, 90] as $r): ?>
        <a href="?range=<?= $r ?>" class="<?= $r === $range ? 'is-active' : '' ?>">
            <?= $r ?>d
        </a>
    <?php endforeach; ?>
    <?php if ($ga4Enabled): ?>
        <span style="margin-left:auto; color:var(--acc-good);">● GA4 LIVE</span>
    <?php else: ?>
        <span style="margin-left:auto; color:#ffd166;">● GA4 NOT CONFIGURED · see footer</span>
    <?php endif; ?>
</div>

<!-- ============ TOP STAT CARDS ============ -->
<div class="stat-grid">
    <div class="stat-card">
        <p class="stat-label">Submissions (<?= $range ?>d)</p>
        <p class="stat-value"><?= number_format($totalSubs) ?></p>
        <p class="stat-delta"><?= number_format($totalSubsAllTime) ?> all-time</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Funnel Contacts (<?= $range ?>d)</p>
        <p class="stat-value"><?= number_format($contactsFromFunnels) ?></p>
        <p class="stat-delta">First-source = *_apply</p>
    </div>
    <?php if ($ga4Enabled && $ga4Summary): ?>
    <div class="stat-card">
        <p class="stat-label">Sessions (<?= $range ?>d)</p>
        <p class="stat-value"><?= number_format($ga4Summary['sessions']) ?></p>
        <p class="stat-delta"><?= number_format($ga4Summary['users']) ?> users · <?= number_format($ga4Summary['pageviews']) ?> pageviews</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Engagement (<?= $range ?>d)</p>
        <p class="stat-value"><?= number_format($ga4Summary['engagement_rate'] * 100, 1) ?>%</p>
        <p class="stat-delta">Avg session <?= fmtSeconds($ga4Summary['avg_session_sec']) ?></p>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <p class="stat-label">Sessions (<?= $range ?>d)</p>
        <p class="stat-value" style="color:var(--text-mute)">—</p>
        <p class="stat-delta">GA4 not configured</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Engagement</p>
        <p class="stat-value" style="color:var(--text-mute)">—</p>
        <p class="stat-delta">GA4 not configured</p>
    </div>
    <?php endif; ?>
</div>

<!-- ============ FUNNEL CONVERSION TABLE ============ -->
<div class="analytics-section">
    <h2>Funnel Conversion</h2>
    <div class="an-card">
        <table class="an-table">
            <thead>
                <tr>
                    <th>Funnel</th>
                    <th>Path</th>
                    <th class="num">Pageviews</th>
                    <th class="num">Users</th>
                    <th class="num">Submissions</th>
                    <th class="num">Conv %</th>
                    <th class="num">Accepted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($funnelTable as $k => $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['label']) ?></strong></td>
                    <td><code style="color:var(--text-mute);"><?= htmlspecialchars($row['path']) ?></code></td>
                    <td class="num"><?= $ga4Enabled ? number_format($row['pageviews']) : '<span style="color:var(--text-mute)">—</span>' ?></td>
                    <td class="num"><?= $ga4Enabled ? number_format($row['users'])     : '<span style="color:var(--text-mute)">—</span>' ?></td>
                    <td class="num"><?= number_format($row['submissions']) ?></td>
                    <td class="num">
                        <?php if ($row['conversion'] === null): ?>
                            <span style="color:var(--text-mute)">—</span>
                        <?php else: ?>
                            <span style="color: <?= $row['conversion'] >= 1.0 ? 'var(--acc-good)' : 'var(--text)' ?>"><?= number_format($row['conversion'], 2) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format($row['accepted']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$ga4Enabled): ?>
            <p class="an-empty" style="margin-top:14px;">Add GA4 credentials (see bottom of page) to see Pageviews / Users / Conversion %.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ============ SUBMISSIONS OVER TIME ============ -->
<div class="analytics-section">
    <h2>Submissions Over Time</h2>
    <div class="an-card">
        <div class="spark-wrap">
            <canvas id="chart-subs"></canvas>
        </div>
    </div>
</div>

<!-- ============ STATUS MIX + SPONSOR TIERS ============ -->
<div class="analytics-section an-grid-2">
    <div class="an-card">
        <p class="an-card-title">Status Mix by Funnel</p>
        <table class="an-table">
            <thead>
                <tr>
                    <th>Funnel</th>
                    <th class="num">New</th>
                    <th class="num">Reviewing</th>
                    <th class="num">Contacted</th>
                    <th class="num">Accepted</th>
                    <th class="num">Declined</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($KINDS as $k): $b = $byKind[$k]; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($KIND_LABELS[$k]) ?></strong></td>
                    <td class="num"><?= (int) $b['new_count'] ?></td>
                    <td class="num"><?= (int) $b['reviewing_count'] ?></td>
                    <td class="num"><?= (int) $b['contacted_count'] ?></td>
                    <td class="num" style="color:var(--acc-good)"><?= (int) $b['accepted_count'] ?></td>
                    <td class="num" style="color:var(--acc-bad)"><?= (int) $b['declined_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="an-card">
        <p class="an-card-title">Sponsor Tier Interest</p>
        <?php if (!$sponsorTiers): ?>
            <p class="an-empty">No sponsor submissions in the last <?= $range ?> days.</p>
        <?php else:
            $maxTier = max(array_map(fn($r) => (int) $r['c'], $sponsorTiers)) ?: 1; ?>
            <table class="an-table">
                <thead><tr><th>Tier</th><th class="num">Count</th><th>Share</th></tr></thead>
                <tbody>
                <?php foreach ($sponsorTiers as $row):
                    $c = (int) $row['c'];
                    $w = max(8, (int) round($c / $maxTier * 220)); ?>
                <tr>
                    <td><?= htmlspecialchars($row['tier']) ?></td>
                    <td class="num"><?= $c ?></td>
                    <td><span class="an-bar" style="width:<?= $w ?>px;"></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ============ GA4: TOP PAGES + TOP SOURCES ============ -->
<?php if ($ga4Enabled): ?>
<div class="analytics-section an-grid-2">
    <div class="an-card">
        <p class="an-card-title">Top Pages (<?= $range ?>d)</p>
        <?php if (!$ga4Pages): ?>
            <p class="an-empty">No traffic data yet.</p>
        <?php else:
            $maxPv = max(array_map(fn($r) => (int) $r['pageviews'], $ga4Pages)) ?: 1; ?>
        <table class="an-table">
            <thead><tr><th>Path</th><th class="num">Pageviews</th><th class="num">Users</th><th>Share</th></tr></thead>
            <tbody>
            <?php foreach ($ga4Pages as $row):
                $w = max(6, (int) round($row['pageviews'] / $maxPv * 160)); ?>
            <tr>
                <td><code style="color:var(--acc-ice);"><?= htmlspecialchars($row['path']) ?></code></td>
                <td class="num"><?= number_format($row['pageviews']) ?></td>
                <td class="num"><?= number_format($row['users']) ?></td>
                <td><span class="an-bar" style="width:<?= $w ?>px;"></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="an-card">
        <p class="an-card-title">Top Acquisition Sources (<?= $range ?>d)</p>
        <?php if (!$ga4Sources): ?>
            <p class="an-empty">No traffic data yet.</p>
        <?php else: ?>
        <table class="an-table">
            <thead><tr><th>Channel</th><th>Source</th><th class="num">Sessions</th><th class="num">Users</th></tr></thead>
            <tbody>
            <?php foreach ($ga4Sources as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['channel'] ?: '(direct)') ?></td>
                <td style="color:var(--text-dim)"><?= htmlspecialchars($row['source'] ?: '(direct)') ?></td>
                <td class="num"><?= number_format($row['sessions']) ?></td>
                <td class="num"><?= number_format($row['users']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============ INTERNAL SOURCE PAGES + RECENT ============ -->
<div class="analytics-section an-grid-2">
    <div class="an-card">
        <p class="an-card-title">Submissions by Source Page (<?= $range ?>d)</p>
        <?php if (!$bySourcePage): ?>
            <p class="an-empty">No submissions yet in this window.</p>
        <?php else:
            $maxC = max(array_map(fn($r) => (int) $r['c'], $bySourcePage)) ?: 1; ?>
        <table class="an-table">
            <thead><tr><th>Page</th><th class="num">Subs</th><th>Share</th></tr></thead>
            <tbody>
            <?php foreach ($bySourcePage as $row):
                $w = max(6, (int) round($row['c'] / $maxC * 200)); ?>
            <tr>
                <td><code style="color:var(--acc-ice);"><?= htmlspecialchars($row['p']) ?></code></td>
                <td class="num"><?= (int) $row['c'] ?></td>
                <td><span class="an-bar" style="width:<?= $w ?>px;"></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="an-card">
        <p class="an-card-title">Recent Submissions</p>
        <?php if (!$recent): ?>
            <p class="an-empty">No submissions yet.</p>
        <?php else: ?>
        <table class="an-table">
            <thead><tr><th>When</th><th>Kind</th><th>Name</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
            <tr>
                <td style="white-space:nowrap; color:var(--text-dim);"><?= htmlspecialchars(date('M j · g:ia', strtotime((string) $row['created_at']))) ?></td>
                <td><?= htmlspecialchars($KIND_LABELS[$row['kind']] ?? $row['kind']) ?></td>
                <td><a href="/submissions.php?id=<?= (int) $row['id'] ?>" style="color:var(--text);"><?= htmlspecialchars($row['full_name']) ?></a></td>
                <td><?= statusBadge((string) $row['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!$ga4Enabled): ?>
<div class="analytics-section">
    <div class="an-note">
        <strong>Enable GA4 metrics:</strong> Create a Google service account, download its JSON key,
        grant it Viewer access on the GA4 property, then set in your <code>.env</code>:
        <code>GA4_PROPERTY_ID=123456789</code> and
        <code>GA4_SERVICE_ACCOUNT_JSON_PATH=/home/u833453975/.secrets/ga4-sa.json</code>.
        The dashboard caches GA4 responses for 5 minutes per range.
    </div>
</div>
<?php endif; ?>

<!-- Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels  = <?= json_encode($tsLabels) ?>;
    const totals  = <?= json_encode($tsTotals) ?>;
    const byKind  = <?= json_encode($tsByKind) ?>;
    const ga4     = <?= json_encode($gaSeriesAligned) ?>;
    const ga4On   = <?= $ga4Enabled ? 'true' : 'false' ?>;
    const colors  = {
        sponsor:  '#ac2181',
        investor: '#7fbbe0',
        dj:       '#694591',
        idol:     '#ff5fa2',
        vendor:   '#48c9b0',
    };
    const labelNames = {
        sponsor:'Sponsors', investor:'Investors', dj:'DJs', idol:'Idols', vendor:'Vendors'
    };
    const datasets = Object.keys(byKind).map(k => ({
        label: labelNames[k],
        data:  byKind[k],
        borderColor:     colors[k],
        backgroundColor: colors[k] + '22',
        tension: 0.3, fill: false, borderWidth: 2, pointRadius: 0,
    }));
    if (ga4On) {
        datasets.push({
            label: 'Sessions (GA4)',
            data:  ga4,
            yAxisID: 'y2',
            borderColor: 'rgba(255,255,255,0.45)',
            borderDash: [4, 4],
            tension: 0.3, fill: false, borderWidth: 1.5, pointRadius: 0,
        });
    }
    const ctx = document.getElementById('chart-subs');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#cfd3dc', font: { size: 11 } } },
                tooltip: { backgroundColor: '#0a0a0f', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1 },
            },
            scales: {
                x: { ticks: { color: '#7a7e88', maxTicksLimit: 12, font:{size:10} }, grid: { color: 'rgba(255,255,255,0.04)' } },
                y: { beginAtZero: true, ticks: { color: '#7a7e88', precision: 0, font:{size:10} }, grid: { color: 'rgba(255,255,255,0.04)' }, title: { display: true, text: 'Submissions', color: '#7a7e88', font:{size:10} } },
                y2:{ beginAtZero: true, position: 'right', ticks: { color: '#7a7e88', font:{size:10} }, grid: { display: false }, title: { display: ga4On, text: 'Sessions', color: '#7a7e88', font:{size:10} }, display: ga4On },
            }
        }
    });
})();
</script>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
