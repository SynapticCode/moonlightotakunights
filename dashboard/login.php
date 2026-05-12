<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/auth/session.php';

session_start();

// If already logged in, bounce to home
if (current_user()) {
    header('Location: /dashboard/');
    exit;
}

$cfg = config('google_oauth');

// Generate CSRF state for Google flow
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));
$googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $cfg['client_id'],
    'redirect_uri'  => $cfg['redirect_uri'],
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $_SESSION['oauth_state'],
    'prompt'        => 'select_account',
    'access_type'   => 'online',
]);
$googleConfigured = !empty($cfg['client_id']);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Moonlight Otaku Nights Dashboard</title>
    <link rel="stylesheet" href="/dashboard/assets/dashboard.css">
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="auth-page">

<div class="auth-stage">
    <div class="auth-card moonlight-theme">
        <div class="auth-card-accent" aria-hidden="true"></div>
        <header class="auth-head">
            <p class="auth-eyebrow">MOONLIGHT OPERATIONS</p>
            <p class="auth-jp">運営ダッシュボード</p>
            <h1 class="auth-title">DASHBOARD ACCESS</h1>
            <p class="auth-sub">Authorized operators only. Sign in with Google or request a one-time code.</p>
        </header>

        <?php if ($googleConfigured): ?>
        <a href="<?= htmlspecialchars($googleAuthUrl, ENT_QUOTES) ?>" class="btn btn-google">
            <span class="g-icon" aria-hidden="true">G</span>
            <span>Continue with Google</span>
        </a>
        <?php else: ?>
        <div class="auth-note auth-note--warn">
            Google OAuth not configured. Set <code>GOOGLE_CLIENT_ID</code> / <code>GOOGLE_CLIENT_SECRET</code> in env to enable. Use the OTP path below.
        </div>
        <?php endif; ?>

        <div class="auth-divider"><span>OR</span></div>

        <form id="otp-form" class="auth-form" autocomplete="off">
            <label class="auth-label" for="otp-email">Email</label>
            <input type="email" id="otp-email" name="email" required placeholder="anikuranj@gmail.com" autocomplete="email">

            <button type="submit" class="btn btn-primary" id="otp-request-btn">SEND ONE-TIME CODE</button>

            <div id="otp-step-2" hidden>
                <label class="auth-label" for="otp-code">6-Digit Code</label>
                <input type="text" id="otp-code" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••" autocomplete="one-time-code">
                <button type="button" class="btn btn-primary" id="otp-verify-btn">VERIFY &amp; SIGN IN</button>
            </div>

            <p class="auth-status" id="auth-status"></p>
        </form>

        <p class="auth-foot">Newark, NJ · single-operator phase</p>
    </div>
</div>

<script>
(function () {
    const form        = document.getElementById('otp-form');
    const emailEl     = document.getElementById('otp-email');
    const requestBtn  = document.getElementById('otp-request-btn');
    const step2       = document.getElementById('otp-step-2');
    const codeEl      = document.getElementById('otp-code');
    const verifyBtn   = document.getElementById('otp-verify-btn');
    const statusEl    = document.getElementById('auth-status');

    function setStatus(msg, kind) {
        statusEl.textContent = msg || '';
        statusEl.className = 'auth-status' + (kind ? ' auth-status--' + kind : '');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!emailEl.value) return;
        requestBtn.disabled = true;
        setStatus('Sending code…');
        try {
            const r = await fetch('/dashboard/auth/otp-request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: emailEl.value })
            });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Request failed');
            step2.hidden = false;
            codeEl.focus();
            setStatus('Code sent. Check your inbox.', 'ok');
        } catch (err) {
            setStatus(err.message, 'err');
        } finally {
            requestBtn.disabled = false;
        }
    });

    verifyBtn.addEventListener('click', async () => {
        if (!/^\d{6}$/.test(codeEl.value || '')) {
            setStatus('Enter the 6-digit code.', 'err');
            return;
        }
        verifyBtn.disabled = true;
        setStatus('Verifying…');
        try {
            const r = await fetch('/dashboard/auth/otp-verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: emailEl.value, otp: codeEl.value })
            });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Verify failed');
            window.location = data.redirect || '/dashboard/';
        } catch (err) {
            setStatus(err.message, 'err');
            verifyBtn.disabled = false;
        }
    });
})();
</script>
</body>
</html>
