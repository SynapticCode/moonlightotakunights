# Moonlight Otaku Nights — System Blueprint

**Last updated:** 2026-05-14
**Owner:** Azael (anikuranj@gmail.com)
**Brand:** Moonlight Otaku Nights (anime / cosplay / rave events, NY/NJ)

This document is the single source of truth for anyone (engineer or AI) jumping into this codebase. Read it top to bottom before changing anything.

---

## 1. North Star

Every product, UI, copy, and ops decision is judged against **brand affinity, follower growth, and engagement** — not just functional shipping. If a feature works but doesn't make the brand stickier, it's the wrong feature.

Secondary goal: Azael never has to log into a third-party SaaS to run the business. Everything funnels through one dashboard at `dashboard.moonlightotakunights.com`.

---

## 2. Account & Ownership Map

| Resource | Account | Notes |
|---|---|---|
| Hostinger (hosting + DB + domain) | montejoazaeljr@gmail.com | prefix `u833453975`, srv2104 |
| GitHub repo | SynapticCode | https://github.com/SynapticCode/moonlightotakunights |
| AWS (SES, IAM, S3) | anikuranj@gmail.com | account `646424554005`, region `us-east-1` |
| Google (GA4, Ads, GTM, OAuth) | anikuranj@gmail.com | — |
| Meta (Pixel, CAPI, Pages) | anikuranj@gmail.com | — |
| Posh / Eventbrite | anikuranj@gmail.com | — |

**Rule:** when working on any platform, always confirm logged-in account is anikuranj@gmail.com — except Hostinger which is montejoazaeljr@.

---

## 3. Top-level Architecture

```
                  ┌─────────────────────────────────────┐
                  │   moonlightotakunights.com (public) │
                  │   static HTML + CSS + JS (no PHP)   │
                  │   ─ landing, event pages, recap     │
                  │   ─ /submit/  (UGC submission form) │
                  └──────────────┬──────────────────────┘
                                 │ POST /api/ugc/submit
                                 ▼
                  ┌─────────────────────────────────────┐
                  │ dashboard.moonlightotakunights.com  │
                  │ (PHP 8, Hostinger Premium, locked)  │
                  │ ─ login (allowlist by email)        │
                  │ ─ contacts / events / compose / etc │
                  │ ─ ugc.php (moderation queue)        │
                  │ ─ api/ugc/*  (submit, list, approve)│
                  └────┬───────────────┬────────────────┘
                       │               │
                       ▼               ▼
               ┌──────────────┐  ┌──────────────────────┐
               │ MySQL        │  │ AWS S3               │
               │ u833...      │  │ bucket: mon-ugc      │
               │ _mon_dash    │  │ region: us-east-1    │
               └──────────────┘  └──────────────────────┘
                       │
                       ▼
               ┌──────────────────────┐
               │ AWS SES (us-east-1)  │
               │ MAIL_FROM: 4 senders │
               │ smtp creds in .env   │
               └──────────────────────┘
```

**Two domains, one origin of truth (DB).** The public site never reads the DB directly — only via dashboard API endpoints.

---

## 4. Hosts, Paths, Doc Roots

| Host | Hostinger doc root | Purpose |
|---|---|---|
| `moonlightotakunights.com` | `/home/u833453975/domains/moonlightotakunights.com/public_html/` | static marketing site |
| `dashboard.moonlightotakunights.com` | `/home/u833453975/domains/moonlightotakunights.com/public_html/dashboard/` | private dashboard |

**Critical Hostinger constraint:** `open_basedir` is per-vhost. The dashboard subdomain CANNOT read files above its own doc root. That is why `.env` lives at `/dashboard/.env` and the env loader (`api/includes/config.php`) checks the subdomain doc root **first**.

**Deploy mechanism:** GitHub `main` → Hostinger Git integration. **No webhook is wired**; Azael clicks "Redeploy" in hPanel after each merge. (TODO: add GitHub Actions → Hostinger deploy webhook — Session 2.)

**SSH:** disabled on this Hostinger account. When SSH is needed, use the Comet local browser helper or hPanel File Manager.

---

## 5. Repo Layout

```
/                          ← marketing site root (deployed to public_html)
├── index.html
├── elven-grove/           ← upcoming event page (2026-03-26)
├── hatsune-miku-after-party/  ← past event recap (Miku Vol 02)
├── neon-rain/, mecha-night/, cosplay-signup/, past-events/, etc.
├── partners/, sponsors/, work-with-us/, welcome/, unsubscribe/
├── privacy-policy/, terms/, thank-you*.html
├── assets/, components/, sandbox/
├── api/                   ← shared PHP (used by dashboard)
│   └── includes/
│       ├── config.php     ← env loader (4 candidate paths, subdomain first)
│       ├── bootstrap.php  ← DB-independent setup (errors, paths, helpers)
│       ├── db.php         ← PDO singleton; 503 on connect fail
│       ├── audit.php      ← audit_log_event, rate_limit_db, senders_list
│       ├── csv_detect.php ← auto-detects Posh/Brevo/cosplay/Eventbrite
│       ├── ses.php        ← SES SMTP wrapper
│       ├── tokens.php     ← signed-URL / submission tokens
│       └── tracking.php   ← GA4/Meta CAPI event helpers
├── dashboard/             ← deployed to subdomain doc root
│   ├── login.php          ← host-aware; redirects path-mounted → subdomain
│   ├── index.php          ← dashboard home (KPIs)
│   ├── contacts.php, events.php, compose.php, broadcasts.php
│   ├── import.php         ← smart CSV import w/ csv_detect
│   ├── cosplay.php        ← cosplay signup list
│   ├── ugc.php            ← UGC moderation queue (NEW — Session 1.5)
│   ├── migrate.php        ← runs pending migrations (idempotent)
│   ├── diag.php           ← gated by DIAG_TOKEN
│   ├── health.php         ← public liveness ping
│   ├── .htaccess          ← HTTPS, HSTS, CSP, X-Robots noindex, blocks AI bots + curl/wget
│   ├── auth/              ← session + Google OAuth (OAuth restore pending)
│   ├── api/               ← dashboard-scoped JSON endpoints
│   ├── views/, templates/, assets/
│   └── .env               ← LIVE secrets (not in git)
├── database/
│   └── migrations/
│       ├── 001_session1_pipeline.sql   ← shipped
│       └── 002_ugc.sql                 ← Session 1.5 (this build)
├── docs/
│   ├── blueprint.md       ← THIS FILE
│   └── ses-production-access-request.md
├── scripts/
└── robots.txt, favicon.ico, index.css
```

---

## 6. Environment (`.env`)

Lives at `/home/u833453975/domains/moonlightotakunights.com/public_html/dashboard/.env`. Never committed.

| Group | Keys | Status |
|---|---|---|
| App | `APP_ENV`, `APP_BASE_URL` | set |
| Database | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | set (host=localhost, name=u833453975_mon_dashboard, user=u833453975_mon_admin) |
| SES SMTP | `SES_SMTP_HOST`, `SES_SMTP_PORT`, `SES_SMTP_USER`, `SES_SMTP_PASS` | set (IAM user `ses-smtp-moonlight-2`) |
| Mail | `MAIL_FROM_DEFAULT`, `MAIL_REPLY_TO` | set |
| Auth | `DASHBOARD_ALLOWED_EMAILS=anikuranj@gmail.com` | set |
| Privacy | `IP_HASH_SALT` | set |
| Tracking | `GTM_CONTAINER_ID=GTM-WX8WHXSZ`, `GA4_MEASUREMENT_ID=G-8W7W5FKYV9`, `META_PIXEL_ID=1979608179640857`, `META_CAPI_API_VERSION=v21.0`, `TRACKING_ENABLED=1` | set |
| Diag | `DIAG_TOKEN` | set |
| **AWS S3 (NEW)** | `AWS_S3_REGION`, `AWS_S3_BUCKET`, `AWS_S3_KEY`, `AWS_S3_SECRET`, `AWS_S3_PUBLIC_BASE` | **to add Session 1.5** |
| **UGC** | `UGC_MAX_BYTES`, `UGC_ALLOWED_MIME`, `UGC_RATE_PER_HOUR` | **to add Session 1.5** |
| Pending restore | `GOOGLE_OAUTH_*`, `META_CAPI_ACCESS_TOKEN`, `GA4_API_SECRET`, `GOOGLE_ADS_*` | blank (restore Session 2) |

---

## 7. Database

Connection: `localhost` / `u833453975_mon_dashboard` / `u833453975_mon_admin` / password in `.env`.
Engine: InnoDB, charset utf8mb4, collation utf8mb4_unicode_ci.

### Baseline schema (`database/schema.sql`)
| Table | Purpose |
|---|---|
| `events` | event catalog |
| `contacts` | unified contact records |
| `contact_sources` | per-contact provenance |
| `verification_tokens` | email verify / OTP magic-link tokens |
| `cosplay_signups` | cosplay form submissions |
| `email_log` | every outbound SES message |
| `broadcast_log` | broadcast sends |
| `site_health_log` | health pings |
| `dashboard_users` | dashboard user accounts |
| `dashboard_sessions` | dashboard auth sessions |
| `gads_conversion_queue` | Google Ads offline-conversion outbox |
| `tracking_log` | server-side tracking events |

### Migration 001 (shipped — Session 1 pipeline)
| Table | Purpose |
|---|---|
| `sender_addresses` | The 4 from-addresses (hello@, events@, talent@, moonlightotakunights@gmail.com fallback) |
| `event_attendees` | Imported ticket-buyer rows (Posh / Eventbrite / Brevo / cosplay) |
| `audit_log` | Every dashboard action |
| `rate_limit_log` | DB-backed rate limit buckets |
| `import_jobs` | CSV import history + dedupe stats |

### Tables added in this session (migration 002 — UGC)
| Table | Columns (short) |
|---|---|
| `ugc_submissions` | `id`, `event_slug`, `display_name`, `instagram_handle`, `email` (nullable), `caption`, `s3_key`, `mime`, `width`, `height`, `bytes`, `status` ENUM(`pending`,`approved`,`rejected`), `consent_repost` TINYINT, `consent_age` TINYINT, `ip_hash`, `user_agent`, `submitted_at`, `moderated_at`, `moderated_by`, `reject_reason` |

Indexes: `(status, submitted_at DESC)`, `(event_slug, status)`, `(instagram_handle)`.

---

## 8. Auth & Security

- **Dashboard access**: email allowlist via `DASHBOARD_ALLOWED_EMAILS` (currently `anikuranj@gmail.com` only). Two login paths:
  1. **Google OAuth** — `dashboard/auth/google-callback.php` (creds blanked in `.env`, restore Session 2).
  2. **OTP magic-link** — `dashboard/auth/otp-request.php` + `otp-verify.php`. Emails a token via SES. This is the active path while OAuth creds are blank.
  - Sessions live in `dashboard_sessions` table, users in `dashboard_users`.
- **`.htaccess` lockdown** on `dashboard/`:
  - Force HTTPS + HSTS (`max-age=31536000; includeSubDomains`)
  - `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex`
  - CSP: `default-src 'self'; img-src 'self' data: https://*.moonlightotakunights.com; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none';`
  - **CSP gap for UGC:** `img-src` does NOT yet allow the S3 bucket. Session 1.5 must extend `img-src` to include `AWS_S3_PUBLIC_BASE` before the moderation page can render thumbnails.
  - Blocks AI/scraper UAs: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, anthropic-ai, Claude-Web, CCBot, Bytespider, FacebookBot, Meta-ExternalAgent, Amazonbot, Applebot-Extended, Diffbot, Omgilibot, YouBot, cohere-ai, ImagesiftBot, AI2Bot, Timpibot, MagpieCrawler
  - Blocks generic tooling UAs: HTTrack, wget, curl, libwww, Scrapy, python-requests, nikto, sqlmap, nmap, masscan, nuclei, gobuster, dirbuster, wpscan
  - **Note:** PerplexityBot and Google-Extended are NOT currently in the blocklist (consider adding).
- **Public submit endpoint** (`/api/ugc/submit`) is the ONLY non-dashboard write surface. It is rate-limited (per IP-hash) and CSRF-token gated.
- **IP hashing**: every stored IP is HMAC-SHA256(`IP_HASH_SALT`, ip). Raw IPs are never persisted.
- **Migration runner** (`dashboard/migrate.php`): gated by `DIAG_TOKEN`. Strips leading comment lines per SQL chunk (bug fixed in PR #12).
- **DIAG_TOKEN**: required query param for `migrate.php` and `diag.php`.

---

## 9. Email (AWS SES)

- Account `646424554005`, region `us-east-1`.
- Production access lift filed 2026-05-14, decision expected ~2026-05-15 18:17 EDT.
- IAM SMTP user: `ses-smtp-moonlight-2`.
- Four sender identities seeded in `sender_addresses`. Verification of `hello@`, `events@`, `talent@` happens after prod access is granted.
- All outbound email goes through SES SMTP. No SendGrid, no Mailgun, no Brevo for sending.

---

## 10. Tracking

- GTM container `GTM-WX8WHXSZ` wired on public site.
- GA4 `G-8W7W5FKYV9` via GTM; server-side ingestion deferred to Session 2.
- Meta Pixel `1979608179640857` client-side; CAPI server-side restore deferred to Session 2.
- Stape: pending cancellation by Azael (out of scope here).

---

## 11. Current Build — Session 1.5: UGC + Site Polish

### 11.1 Scope (locked, no creep)
1. DB migration `002_ugc.sql` — `ugc_submissions` table.
2. S3 client (`api/includes/s3.php`) + bootstrap config additions.
3. Public submission page `/submit/index.html` + endpoint `dashboard/api/ugc/submit.php`.
4. Dashboard `/ugc.php` moderation queue (approve / reject / delete).
5. Wire the existing **"Your Night, Your Frame"** community wall on the Miku recap (`hatsune-miku-after-party/index.html`, `<article class="community-wall" id="community-wall">` at line 488, grid container at line 494 with `data-feed="instagram"`, empty-state at line 495) to render approved photos from S3 instead of the empty placeholder. Endpoint: `dashboard/api/ugc/list.php?event=miku-vol-02&status=approved` (publicly readable, CORS-allowed for the apex domain only).
6. Make Instagram handles clickable everywhere they appear in copy (regex pass over `index.html` and event pages).
7. Rewrite the over-promising lines on public pages (specifically the "We repost everything" line — softens to "We feature our favorites").

Out of scope: IG auto-posting via Graph API, talent agency module, identity-resolved persons DB, audiences, Miku P&L import.

### 11.2 UGC submission flow
```
User on phone after the event
  │  taps QR / link → /submit/?event=miku-vol-02
  │  uploads 1 photo, types IG handle + name, checks two consent boxes
  ▼
POST /api/ugc/submit  (multipart)
  ├─ validates: size ≤ UGC_MAX_BYTES, mime in UGC_ALLOWED_MIME, dimensions sane
  ├─ rate-limit: max UGC_RATE_PER_HOUR per ip_hash
  ├─ uploads to S3:   ugc/<event_slug>/<uuid>.<ext>
  ├─ inserts row in ugc_submissions  status=pending
  └─ returns {ok:true, id, message:"Got it. We'll review and post soon."}

Dashboard /ugc.php
  ├─ filter by status / event
  ├─ thumbnail grid, one-click approve / reject (writes audit_log)
  └─ approved rows expose s3_key via /api/ugc/list

Public wall on event recap
  └─ fetch /api/ugc/list?event=<slug>&status=approved
     render <img src="<AWS_S3_PUBLIC_BASE>/<s3_key>"> + @handle clickable
```

### 11.3 Consent & legal
Submission form checkboxes (both required):
- "I confirm I'm 18 or older, this is my photo, and I'm in it (or have permission from everyone in it)."
- "I give Moonlight Otaku Nights permission to repost this photo on our website and social media, with credit to the @handle I provided."

These consent flags are stored on the row. Wording lives in `/submit/index.html`. Privacy page already covers data retention.

### 11.4 Copy rewrite (over-promising fix)
| File | Old | New |
|---|---|---|
| `hatsune-miku-after-party/index.html` L492 | `Tag <a ...>@moonlightotakunights</a> in your photos and reels from the night. We repost everything — your shot might end up here.` | `Tag <a ...>@moonlightotakunights</a> in your photos and reels from the night, or <a href="/submit/?event=miku-vol-02">drop them here</a>. We feature our favorites on this page and on our IG.` |

Other occurrences scanned via grep before changes.

### 11.5 Clickable IG handles
A full grep across all public HTML files (`index.html`, `elven-grove/`, `hatsune-miku-after-party/`, `neon-rain/`, `mecha-night/`, `cosplay-signup/`, `past-events/`, `partners/`, `sponsors/`) found only **one** unlinked plain-text @handle in user-visible copy:

| File | Line | Current | Change to |
|---|---|---|---|
| `hatsune-miku-after-party/index.html` | 506 | `<span class="recap-credit-handle">@tenryu.photo</span>` | `<a class="recap-credit-handle" href="https://instagram.com/tenryu.photo" target="_blank" rel="noopener">@tenryu.photo</a>` |

All other `@handle` occurrences in HTML are either already linked (`<a href="https://instagram.com/...">`), `mailto:` addresses, form `placeholder`/`name`/`id` attributes, or CSS at-rules (`@media`, `@keyframes`, etc.) which must be left alone.

Additionally: every UGC submission rendered on the wall must have its stored `instagram_handle` rendered as a clickable link — see §11.6 for behavior.

### 11.6 Instagram link behavior (`assets/js/ig-link.js`)

One small helper, loaded on every public page and the dashboard. Auto-wires any anchor marked `data-ig="<handle>"` (handle without the `@`).

**On phone (touch device / mobile UA):**
- Tap opens the Instagram app via `instagram://user?username=<handle>`.
- If the app isn't installed, the OS falls through to the anchor's `href` (which stays `https://instagram.com/<handle>`), so they still land on the profile in their phone browser.

**On laptop / desktop:**
- Click opens `https://instagram.com/<handle>` in a new tab via `window.open(url, '_blank')`.
- Immediately calls `window.focus()` on the original window so the new tab opens **behind** the current page. The user keeps scrolling our content; Instagram is waiting for them later.
- Caveat: Safari sometimes brings the new tab forward regardless — documented browser limitation, no workaround.

**Markup pattern (one shape, works everywhere):**
```html
<a href="https://instagram.com/tenryu.photo"
   data-ig="tenryu.photo"
   target="_blank" rel="noopener">@tenryu.photo</a>
```

Without JS (script blocked, no-script user), the anchor still works as a plain `target="_blank"` link — graceful degradation.

**Where it ships:**
- `assets/js/ig-link.js` (public site) — loaded by `index.html`, all event pages, `/submit/`.
- `dashboard/assets/js/ig-link.js` (same file, copied) — loaded by `ugc.php` so the moderator can click a submitter's `@handle` and spot-check their profile without losing the moderation queue tab.
- Every clickable `@handle` on the site gets `data-ig="<handle>"` added — the single `@tenryu.photo` linkification, the existing `@moonlightotakunights` links, and every dynamically-rendered UGC handle on the wall and in the moderation queue.

---

## 12. Roadmap

| # | Module | Session | Status |
|---|---|---|---|
| 1 | Smart CSV import + multi-from compose + audit | 1 | shipped |
| 2 | Migration runner / login redirect / env loader fixes | 1.1 | shipped |
| 3 | SES prod-access lift filed | 1.2 | awaiting AWS |
| 4 | Subdomain auth + .env recovery | 1.3 | shipped |
| 5 | UGC submission + wall + clickable @s + copy rewrite | **1.5** | **building now** |
| 6 | Identity-resolved persons DB + GA4/Meta CAPI ingestion | 2 | planned |
| 7 | Audiences module (segments → Meta/Google/SES push) | 2 | planned |
| 8 | Ops & Finance module (P&L, payouts, partner ledger) | 2 | planned |
| 9 | Creative Library + attribution + Meta Ad Library scrape | 3 | planned |
| 10 | Partner & Placement Tracker | 4 | planned |
| 11 | SEO across main site | 5 | planned |

Deferred Session-2 chores: Hostinger Git auto-deploy webhook, OAuth + CAPI + GA4 secret restore, Miku Vol 02 CSV import.

---

## 13. Three-Strike Rule

Operating principle saved across the project: if any single sub-task (a redeploy, an auth fix, a file write) fails three times, **stop**. Tell Azael exactly which link to visit and exactly what to click or paste, then resume. Do not burn credits looping.

---

## 14. Known Hostinger Quirks

- `open_basedir` is per-vhost. Files above the doc root are invisible.
- File Manager 403s under load (openresty). Reload or wait.
- SSH disabled — use Comet local browser or hPanel.
- No deploy webhook — Azael clicks Redeploy after merges.
- `.htaccess` blocks curl/wget user-agents. Test endpoints from a real browser or pass a normal UA.

---

## 15. How to Pick This Up Cold

If you're a new engineer or AI joining mid-stream:
1. Read this file end-to-end.
2. Read the four PHP includes: `config.php`, `bootstrap.php`, `db.php`, `audit.php`.
3. Read the current open session in `docs/blueprint.md` § 11 (Current Build).
4. Check `git log --oneline -20` for recent merges.
5. Visit `dashboard.moonlightotakunights.com/health.php` — should return 200 JSON.
6. Before any deploy, confirm `.env` placement on the subdomain doc root.
7. Honor the Three-Strike Rule and the brand-affinity North Star.

End of blueprint.
