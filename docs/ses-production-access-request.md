# SES Production Access Request — Moonlight Otaku Nights

Use this content when filing the "Request production access" form in the SES console.

**Console path:** SES → Account dashboard → "Request production access" (top right banner).

---

## Form fields

### Mail type
**Transactional**

### Website URL
`https://moonlightotakunights.com`

### Use case description
We operate Moonlight Otaku Nights — an in-person anime/cosplay nightclub event series in Newark, NJ. We use Amazon SES to send transactional email triggered by user action on our website:

1. **Email verification (double opt-in)** — When someone signs up for our Guild newsletter on moonlightotakunights.com, we send them a verification link. Membership only activates after they click it.
2. **Cosplay performer confirmations** — When someone applies to perform at an event via our cosplay signup form, we email back a confirmation with event details, dress code, and arrival instructions.
3. **Event reminders** — 48 hours before each event, we email confirmed Guild members who explicitly opted in for event reminders.
4. **Account / dashboard notifications** — Password reset and admin notification emails for our internal staff dashboard (5 internal users).

We do not send marketing or promotional blasts. Every email is triggered by an explicit user action on our website.

### How do you plan to build or acquire your mailing list?
Our mailing list is built exclusively through double opt-in on our website. Visitors submit their email through the Guild signup form (moonlightotakunights.com), receive a verification email, and must click the link before any further mail is sent. We do not import contact lists, do not purchase lists, and do not use any third-party lead source.

### How do you plan to handle bounces and complaints?
We have already integrated SES bounce + complaint handling:

- **Bounces:** SES is configured to send bounce notifications to `info@moonlightotakunights.com`. Hard bounces are recorded in our `email_log` table and the contact is immediately set to `status = bounced` in the `contacts` table — preventing further sends.
- **Complaints:** Complaint notifications go to the same address. Any complaint sets the contact to `status = unsubscribed` and we honor it permanently.
- **Unsubscribe link:** Every transactional email includes an unsubscribe link pointing to `/api/unsubscribe.php`, which immediately removes the user and writes the change to `email_log`.
- **List hygiene:** We never send to addresses that have not completed double opt-in, never re-send to bounced addresses, and never send to unsubscribed addresses.

### How can we contact you?
Reply via email to `info@moonlightotakunights.com` or `anikuranj@gmail.com`.

### Any additional information?
- Domain `moonlightotakunights.com` is fully DKIM + SPF + DMARC verified in SES.
- Custom MAIL FROM domain (`mail.moonlightotakunights.com`) is verified.
- We have already sent ~5 sandbox test emails to verified addresses with 0 bounces and 0 complaints.
- Current sending volume estimate: 200-400 transactional emails per week as we ramp up events.
- We have no intention of sending bulk marketing — this is purely transactional infrastructure.

---

## Notes for filing
- File from AWS account `646424554005` (`anikuranj@gmail.com`)
- Region: `us-east-1`
- After approval, sandbox lifts to 50,000/day with 14/sec rate cap (standard new-account starting point)
