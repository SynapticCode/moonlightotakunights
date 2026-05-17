#!/usr/bin/env python3
"""Generate the 5 thank-you pages with shared scaffold and per-kind content."""
from pathlib import Path

ROOT = Path("/tmp/mln")

HEAD = """<!DOCTYPE html>
<html lang="en">
<head>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){{w[l]=w[l]||[];w[l].push({{'gtm.start':
new Date().getTime(),event:'gtm.js'}});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
}})(window,document,'script','dataLayer','GTM-WX8WHXSZ');</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{title}</title>
<meta name="description" content="{desc}">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/components/theme.css">
<link rel="stylesheet" href="/components/submission-form.css">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-8W7W5FKYV9"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){{dataLayer.push(arguments);}}gtag('js',new Date());gtag('config','G-8W7W5FKYV9');dataLayer.push({{event:'application_complete',submission_kind:'{kind}'}});</script>
<script>!function(f,b,e,v,n,t,s){{if(f.fbq)return;n=f.fbq=function(){{n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)}};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','1979608179640857');fbq('track','PageView');</script>
</head>
<body class="moonlight-theme">
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-WX8WHXSZ" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>

<div class="sub-wrap">
  <div class="sub-brand">
    <img src="/assets/images/logos/Moonlight Otaku Nights Logo no background clean version.png" alt="">
    <span class="name">Moonlight Otaku Nights</span>
  </div>

  <div class="sub-thanks-hero">
    <div class="check-mark">\u2713</div>
    <h1>{hero_h1}</h1>
    <p class="sub">{hero_sub}</p>
  </div>

  <div class="sub-letter">
    {letter}
  </div>

  <div class="next-steps">
    <h3>Your Next Steps</h3>
    <ol>
      {steps}
    </ol>
    {bonus}
  </div>

  <a href="/" class="sub-back">\u2190 Back to Moonlight</a>
</div>

</body>
</html>
"""

PAGES = {
    "sponsors": {
        "title": "Thank You — Sponsor Application Received | Moonlight Otaku Nights",
        "desc": "We got your sponsor application. Here's what happens next.",
        "kind": "sponsor",
        "hero_h1": "GOT IT. YOU'RE IN THE QUEUE.",
        "hero_sub": "Your application is in front of me. I read every one personally \u2014 usually within 24 hours. Here's exactly what happens next so you're not guessing.",
        "letter": """<p>While you wait \u2014 there are 4 things you can do in the next 10 minutes that will make our call dramatically more useful when we connect.</p>""",
        "steps": [
            ("Check your email \u2014 right now.",
             "We just sent a receipt to the address you used. If it's not there in 5 minutes, check spam. If it's still not there, the email was typed wrong and we have no way to reach you. Email <code>info@moonlightotakunights.com</code> from the right address."),
            ("Whitelist us so the next email actually arrives.",
             "Add <code>info@moonlightotakunights.com</code> to your contacts or safe-senders list. Our follow-up with the deck + booking link goes to spam in about 1 in 8 inboxes if you skip this. We can't book you if you don't see the email."),
            ("Block 20 minutes on your calendar this week.",
             "Once we review your application, we'll send 3 time slots. The faster you grab one, the faster you lock the night. Sponsor slots fill in order of who books the call first \u2014 not who applied first."),
            ("Visit one of our past event recaps.",
             "Open <code>moonlightotakunights.com/past-events</code> in a new tab. See the room, the cosplay, the crowd, the production. You'll come to the call with informed questions instead of starting from zero."),
        ],
        "bonus": ("BONUS", "If you didn't fill out the CPA field on the form, do it now in your head: <strong>what's your current cost per acquisition on your best-performing Meta campaign?</strong> Have that number ready for the call. It's how we calibrate your activation \u2014 if we can beat your CPA, we both win."),
    },
    "djs": {
        "title": "Thank You \u2014 DJ Application Received | Moonlight Otaku Nights",
        "desc": "We got your DJ application. Here's what happens next.",
        "kind": "dj",
        "hero_h1": "GOT IT. THE BOOKING DESK IS LISTENING.",
        "hero_sub": "Your application is in. We'll listen to your mix and you'll hear back within 5 business days \u2014 whether it's a yes, a not-yet, or a 'come open for a residency'.",
        "letter": """<p>While you wait \u2014 a few things that dramatically improve your chances of getting booked. None of these are required. All of them help.</p>""",
        "steps": [
            ("Check your email and whitelist us.",
             "We sent a receipt to the address you used. Add <code>info@moonlightotakunights.com</code> to your contacts so the next email doesn't land in spam. We can't book you if you don't get the booking email."),
            ("Make sure your mix link is public.",
             "If your SoundCloud or Mixcloud is private, change it to public, unlisted, or send us a one-time access link \u2014 we won't book a DJ whose work we can't actually hear."),
            ("Follow Moonlight Otaku Nights on Instagram.",
             "Half of how we evaluate DJs is whether you're an active part of the anime nightlife / EDM scene online. Follow us, react to a few stories, comment on a post. It signals you're in the world we're booking for."),
            ("Pin your best 60-second clip to your IG.",
             "When we look at your profile, what we see in the first 5 seconds decides whether we keep listening. Make sure your best work is on top."),
        ],
        "bonus": ("BONUS", "If you have a date you specifically want \u2014 your birthday, a holiday night, a meaningful anime release window \u2014 reply to the receipt email with the date and a backup. We try to match DJs to nights that matter to them when we can."),
    },
    "idols": {
        "title": "Thank You \u2014 Performer Application Received | Moonlight Otaku Nights",
        "desc": "We got your performance application. Here's what happens next.",
        "kind": "idol",
        "hero_h1": "GOT IT. THE STAGE IS LISTENING.",
        "hero_sub": "Your application is in. We watch every reel. You'll hear back within 5 business days with a yes, a not-yet, or a slot offer.",
        "letter": """<p>While you wait \u2014 a few small things that move you to the top of the booking shortlist.</p>""",
        "steps": [
            ("Check your email and whitelist us.",
             "We sent a receipt to the address you used. Add <code>info@moonlightotakunights.com</code> to your contacts so our follow-up doesn't land in spam."),
            ("Make sure your demo / reel link is public.",
             "If your reel is private or expired, fix it now. We won't book a performer whose work we can't watch."),
            ("Send us your stage tech rider, if you have one.",
             "Reply to the receipt email with anything we should know \u2014 backing track format (WAV preferred), mic count, prop dimensions, costume changes, anything that affects the stage."),
            ("Follow Moonlight Otaku Nights on Instagram.",
             "We tag and shout out booked performers two weeks out. Following means you don't miss the announcement \u2014 and the algorithm shows the post to more of your followers, which moves tickets and helps everyone."),
        ],
        "bonus": ("BONUS", "If your group has more than one performer \u2014 send us a short note about who does what. Lead vocalist, choreographer, costume designer. We feature the people behind the act on socials, and it makes a real difference for your discoverability."),
    },
    "vendors": {
        "title": "Thank You \u2014 Vendor Application Received | Moonlight Otaku Nights",
        "desc": "We got your vendor application. Here's what happens next.",
        "kind": "vendor",
        "hero_h1": "GOT IT. YOU'RE IN THE QUEUE FOR A TABLE.",
        "hero_sub": "We got your vendor application. We review on a rolling basis and confirm category availability within 3 business days.",
        "letter": """<p>While you wait, three things that get you to a confirmed booking faster.</p>""",
        "steps": [
            ("Check your email and whitelist us.",
             "Add <code>info@moonlightotakunights.com</code> to your contacts. Our vendor rate card and booking link goes there \u2014 if it hits spam, you lose your category slot."),
            ("Have your product photos ready.",
             "When we reply, we'll ask for 5\u201310 photos of your actual product and your booth setup. The faster you send them, the faster you lock the night. Have a Google Drive folder or IG link ready to share."),
            ("Confirm your category fit.",
             "Pull up our past events page and look at what other vendors had on the floor. If your products overlap with someone already booked, expect us to ask how yours is different. Have a one-sentence answer ready."),
        ],
        "bonus": ("BONUS", "If you can confirm <strong>3 nights in a row</strong> (not just one), tell us in the reply. Multi-night vendors get the first pick of tier and location, every time. Locked recurring vendors become part of the brand."),
    },
    "investors": {
        "title": "Thank You \u2014 Inquiry Received | Moonlight Otaku Nights",
        "desc": "Your inquiry is in. Here's what happens next.",
        "kind": "investor",
        "hero_h1": "GOT IT. LET'S TALK SOON.",
        "hero_sub": "Your inquiry just came through. I review investor and partner inquiries personally \u2014 you'll hear from me within 24 business hours.",
        "letter": """<p>A few things to expect from the next steps so you can prepare:</p>""",
        "steps": [
            ("Check your email and whitelist us.",
             "I'll reply directly from <code>info@moonlightotakunights.com</code>. Add it to your contacts now \u2014 the email I send will include a Calendly link and a mutual NDA, and you don't want it in spam."),
            ("Block 30 minutes for the intro call.",
             "First conversation is a working call, not a pitch deck reading. I'll show you the dashboard, the booking pipeline, the sponsor list, the festival site, and we'll figure out what scale of conversation makes sense."),
            ("After we mutually NDA, you'll get:",
             "<strong>The data room:</strong> unit economics per night, contribution margin, sponsor pipeline value, attendee acquisition cost, cohort retention, festival projection model with sensitivity analysis, and the operating plan for the next 12 months."),
            ("Send me one question in advance.",
             "Reply to the receipt email with the single most important question you'd want answered before considering capital deployment. I'll have the answer (with data) ready for the call."),
        ],
        "bonus": ("STRATEGIC", "If you're a brand or strategic partner rather than a financial investor \u2014 say so in your reply. The conversation, the deal structure, and the cadence all look different, and I'll route you to the right path from the start."),
    },
}

DIRS = {
    "sponsors": "sponsors/thank-you",
    "djs": "djs/thank-you",
    "idols": "idols/thank-you",
    "vendors": "vendors/thank-you",
    "investors": "investors/thank-you",
}

def build():
    for key, page in PAGES.items():
        steps_html = "\n      ".join(
            f"<li><strong>{title}</strong><br>{body}</li>"
            for title, body in page["steps"]
        )
        bonus_tag, bonus_body = page["bonus"]
        bonus_html = f'<div class="bonus"><span class="tag">{bonus_tag}</span>{bonus_body}</div>'
        html = HEAD.format(
            title=page["title"],
            desc=page["desc"],
            kind=page["kind"],
            hero_h1=page["hero_h1"],
            hero_sub=page["hero_sub"],
            letter=page["letter"],
            steps=steps_html,
            bonus=bonus_html,
        )
        out = ROOT / DIRS[key] / "index.html"
        out.write_text(html)
        print(f"wrote {out} ({len(html)} bytes)")

if __name__ == "__main__":
    build()
