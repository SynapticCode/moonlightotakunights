# Session 1.5 deploy runbook

Step-by-step. Do them in order. None of the steps are reversible-destructive.

---

## 1. Merge the PR

Open https://github.com/SynapticCode/moonlightotakunights/pull/15
→ click **Merge pull request** → **Confirm merge**.

---

## 2. Create the S3 bucket

You need a bucket for the UGC photos. AWS account: **anikuranj@gmail.com**, region **us-east-1**.

1. Go to https://us-east-1.console.aws.amazon.com/s3/bucket/create?region=us-east-1
   (Make sure top-right shows you logged in as anikuranj@gmail.com. If not, sign out and back in.)
2. **Bucket name:** `moonlight-ugc`
3. **Region:** US East (N. Virginia) us-east-1
4. **Object Ownership:** ACLs enabled → Bucket owner preferred
5. **Block Public Access:** **uncheck "Block all public access"**, then check the warning box that appears. (Approved photos need to be publicly readable.)
6. Leave everything else default → **Create bucket**.

Then add a bucket policy so objects are readable by the public:

1. Open the bucket → **Permissions** tab → **Bucket policy** → **Edit**.
2. Paste this exactly, then **Save changes**:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublicReadGetObject",
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::moonlight-ugc/*"
    }
  ]
}
```

3. Same bucket → **Permissions** tab → **Cross-origin resource sharing (CORS)** → **Edit**. Paste this and save:

```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["GET"],
    "AllowedOrigins": [
      "https://moonlightotakunights.com",
      "https://www.moonlightotakunights.com",
      "https://dashboard.moonlightotakunights.com"
    ],
    "ExposeHeaders": []
  }
]
```

---

## 3. Create the IAM user for uploads

1. Go to https://us-east-1.console.aws.amazon.com/iam/home#/users/create
2. **User name:** `moonlight-ugc-uploader`
3. **Access type:** programmatic only (no console password).
4. **Permissions:** Attach policies directly → Create policy (opens a new tab).
   - Policy JSON tab, paste:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:PutObjectAcl", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::moonlight-ugc/*"
    }
  ]
}
```

   - Name it `moonlight-ugc-uploader-policy` → Create policy.
5. Back on the create-user tab, refresh the policy list, check `moonlight-ugc-uploader-policy`, then **Next** → **Create user**.
6. Open the new user → **Security credentials** tab → **Create access key** → **Application running outside AWS** → **Next** → **Create access key**.
7. **Copy the Access key ID and Secret access key now.** AWS only shows the secret once. Paste them into a temporary note — you'll use them in step 4.

---

## 4. Add the S3 secrets to .env on the dashboard

Hostinger account: **montejoazaeljr@gmail.com**.

1. Go to https://hpanel.hostinger.com/websites/moonlightotakunights.com/file-manager
2. Navigate to `domains/moonlightotakunights.com/public_html/dashboard/`
3. Open `.env` (right-click → Edit).
4. **Append** these lines (don't replace anything):

```
AWS_S3_REGION=us-east-1
AWS_S3_BUCKET=moonlight-ugc
AWS_S3_KEY=<paste the Access key ID from step 3.7>
AWS_S3_SECRET=<paste the Secret access key from step 3.7>
AWS_S3_PUBLIC_BASE=https://moonlight-ugc.s3.us-east-1.amazonaws.com
```

5. Save.

---

## 5. Redeploy from GitHub

1. Go to https://hpanel.hostinger.com/websites/moonlightotakunights.com/advanced/git
2. Click **Deploy** (or "Pull latest"). Wait for the green checkmark.

---

## 6. Run the migration

In your browser, open:

```
https://dashboard.moonlightotakunights.com/migrate.php?token=lM8b14lTv1LjuZZM4NhiZRNrxe6Z0hXsiak5z0j7yBw
```

You should see an entry for `002_ugc.sql` with `ok: CREATE TABLE IF NOT EXISTS ugc_submissions`. Migration 001 will show as ok'd-no-op (already shipped).

---

## 7. Smoke test

1. **Public submit form:** open https://moonlightotakunights.com/submit/?event=miku-vol-02 on your phone, drop a photo, fill in your handle, check both boxes, hit Send it. You should see "Got it. We'll review and post our favorites soon."
2. **Moderation queue:** open https://dashboard.moonlightotakunights.com/ugc.php — your submission should be in the Pending tab. Click Approve.
3. **Wall renders:** open https://moonlightotakunights.com/hatsune-miku-after-party/#community-wall — your approved photo should appear in YOUR NIGHT, YOUR FRAME.
4. **Click a handle on desktop:** verify it opens Instagram in a background tab.
5. **Tap a handle on phone:** verify it opens the Instagram app directly.

---

## If something breaks

| Symptom | Likely cause | Fix |
|---|---|---|
| Submit form says "Upload failed" | S3 creds wrong in `.env`, or bucket name typo | Re-check step 4 keys; verify bucket name matches `moonlight-ugc` exactly |
| `/ugc.php` shows broken thumbs | CSP not picking up new `.htaccess` (caching), or bucket public-read policy missing | Hard-refresh the page; re-check step 2 bucket policy |
| Approved photo doesn't show on wall | `data-event` attribute missing on `#community-wall` (only Miku page has it) — by design for now | Working as intended; we'll wire other events next time |
| Migration says "Not found" | DIAG_TOKEN missing from `.env` | The token above is current; verify `.env` still has `DIAG_TOKEN=lM8b14lTv1LjuZZM4NhiZRNrxe6Z0hXsiak5z0j7yBw` |
| Anything else 500 | Check `dashboard/error_log` via File Manager | Bring me the last 20 lines |

Three-strike rule applies — if something fails three times, stop and bring me the exact symptom + screenshots.
