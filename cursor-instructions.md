# CURSOR INSTRUCTIONS: Moonlight Website Update
## Read This First

I'm updating the copy, navigation, and footer across my Moonlight Otaku Nights website. The site already has WORKING email marketing (Brevo integration, Formspree forms, confetti celebrations, MOONLIGHT10 discount code). **DO NOT replace or overwrite any JavaScript, form action URLs, Brevo API calls, or Formspree endpoint IDs.** Only change the HTML copy, CSS, and structural elements I specify.

Work through this ONE PAGE AT A TIME. After each page, stop and let me verify before moving to the next.

---

## PAGE 1: HOMEPAGE (index.html)

### STEP 1: Update the Navigation Bar
Find the `<nav>` section. Change the nav links to ONLY these items:
- "The Vibe" → links to `#vibe`
- "Events" → links to `#events`
- "Join the Crew" → links to `#crew`
- "TICKETS" → links to `/elven-grove/` (this is the CTA button with special styling)

**REMOVE** any links to `/promote/`, `/partners/`, or `/work-with-us/` from the nav. Those pages should NOT be in the main navigation anymore.

### STEP 2: Update the Hero Section
Replace the current hero copy with:

**Japanese text above the title:** 月光オタクナイト
**Main title:** MOONLIGHT OTAKU NIGHTS (with "MOONLIGHT" in the glowing green style)
**Subtitle/description:** "The night has a frequency. If you've ever felt it—bass rattling through a dark room, your favorite opening blasting while the lights go insane—you already know. This is that. Every month. Newark, NJ."
**Tags below subtitle:** ANISONG | J-CORE | COSPLAY | 21+ | QXT'S NEWARK
**Primary CTA button:** "FIRST EVENT — MAR 26" → links to `/elven-grove/`
**Secondary CTA button:** "SEE WHAT'S COMING" → links to `#events`

### STEP 3: Update the "What Is This" / Vibe Section
Replace whatever the current explainer section is with this:

**Section label (Japanese):** 何これ
**Section title:** "Not a costume party."
**Body copy (left column):**

> There's a difference between throwing on a wig at a bar and walking into a room where **everyone gets it**. Where the DJ drops a Chainsaw Man OP remix and the floor erupts. Where your cosplay isn't weird—it's the standard.

> It's called **anikura** — anime club culture. A real nightclub built around the music, the cosplay, and the people who live this. It exists all over the world. But not here. Not in Jersey. Not yet.

> Monthly themed nights. Real anisong and J-Core DJs. A legitimate venue with a full sound system, professional security, and anime visuals on the screens all night. This isn't going anywhere.

**Right column — 3 info cards:**

Card 1 - THE SOUND 🎵
"Anisong remixes, J-Core, happy hardcore, future bass—all rooted in the culture. No top 40. No filler. Every track hits different when you know the source."

Card 2 - THE ENERGY ⚡
"Themed nights that go deeper than decoration. Isekai forests, cyberpunk rain, mecha arenas—each event is a world you step into."

Card 3 - THE CREW 🌙
"Convention friends. Anime club people. The ones who actually know what 'anikura' means. You'll recognize your people immediately."

**IMPORTANT:** Do NOT mention Club Mogra, Club Kaiju, Tokyo, Dallas, or any other anime nightclub by name. Anywhere. On any page. We don't name-drop competitors.

### STEP 4: Update the Featured Event Section
**Section label (Japanese):** 次のイベント
**Section title:** "Next Event"

Event details:
- **Badge:** EVENT #1
- **Title:** ELVEN GROVE (in green glow text)
- **Japanese subtitle:** エメラルドムーン
- **Description:** "Step through the veil. Isekai fantasy brought to life—enchanted forests, emerald light, and a soundtrack pulled from the worlds you've always wanted to visit. Cosplay as your favorite fantasy character or come as you are. The grove welcomes everyone."
- **Date:** Thursday, March 26
- **Time:** 10 PM – 3 AM
- **Venue:** QXT's Newark
- **Early Bird price:** $18
- **Music tags:** ANISONG | J-CORE | HAPPY HARDCORE | FUTURE BASS | COSPLAY CONTEST
- **CTA:** "GET TICKETS" → /elven-grove/

### STEP 5: Update the Upcoming Events Section
**Section label (Japanese):** 予定
**Section title:** "What's Coming"
**Section subtitle:** "Every month, a new world. Same crew, different dimension."

Two cards:

Card 1:
- Date: APRIL 2026
- Title: NEON RAIN
- Description: "Cyberpunk anime. Edgerunners. Akira. Ghost in the Shell. Rain-soaked neon, synth-heavy sets, and the future you were promised."
- Status: DATE TBA

Card 2:
- Date: MAY 2026
- Title: MECHA NIGHT
- Description: "Gundam. Evangelion. Code Geass. Colossal energy. Heavy bass. Pilot suits encouraged."
- Status: DATE TBA

### STEP 6: Update the Email Signup Section (⚠️ CAREFUL)

**Section label (Japanese):** 仲間入り
**Section title:** "Join the Moonlight Crew"
**Description:** "First access to tickets. Event announcements before anyone else. Exclusive crew perks. This is how you stay in the loop."
**Below form note:** "You'll get a welcome gift — **10% off your first ticket**" (the 10% part in green)

⚠️ **DO NOT CHANGE:**
- The form action URL / Formspree endpoint
- Any Brevo API integration code
- The JavaScript that handles form submission
- The confetti celebration code
- The MOONLIGHT10 discount code display
- The success modal/popup

ONLY change the visible text labels, headings, and descriptions around the form. The form inputs, submission logic, and success behavior must stay exactly as they are.

### STEP 7: Update the Footer
Replace the current footer with this structure:

**Left column (brand):**
- "MOONLIGHT OTAKU NIGHTS" (in display font, green)
- "アニクラ NJ" (small, dim)
- "Building NJ's anime nightlife scene. One night at a time."

**Middle column - NAVIGATE:**
- Current Event → /elven-grove/
- Instagram → https://instagram.com/moonlightotakunights

**Right column - DETAILS:**
- QXT's Newark — 248 Mulberry St
- anikuranj@gmail.com
- 21+ with valid ID
- Community Partners → /partners/ (style this link at 50% opacity, subtle)
- Work With Us → /work-with-us/ (style this link at 50% opacity, subtle)

**Footer bottom:**
- Left: "© 2026 Moonlight Otaku Nights. All rights reserved."
- Right: Social media icons (Instagram, TikTok, Discord)

---

## PAGE 2: PARTNERS PAGE (/promote/index.html → rename folder to /partners/)

### IMPORTANT STRUCTURAL CHANGE
The current folder is probably called `/promote/`. Rename it to `/partners/`. Update all internal links that point to `/promote/` to point to `/partners/` instead.

### STEP 1: Add noindex meta tag
Add this to the `<head>` section:
```html
<meta name="robots" content="noindex, nofollow">
```
This prevents search engines from indexing this page. We don't want it showing up in Google.

### STEP 2: Update the Nav Bar
Same nav as the homepage:
- The Vibe → / (back to homepage)
- Events → /elven-grove/
- Join the Crew → /#crew
- TICKETS (CTA) → /elven-grove/

NO link to "Partners" or "Work With Us" in the nav bar.

Actually, for subpages the nav should be:
- Home → /
- Events → /elven-grove/
- Collaborate → /work-with-us/
- TICKETS (CTA) → /elven-grove/

### STEP 3: Replace ALL copy on this page

**Hero:**
- Japanese label: コミュニティパートナー
- Headline: "This scene doesn't **build itself.**" (the "build itself" part in green gradient)
- Description: "There's no recurring anime nightclub in NJ. We're changing that — but not alone. If you're a cosplayer, content creator, convention regular, or someone who runs in these circles and genuinely wants this to exist, we want to work with you. Not as a sales rep. As someone who's helping build something real."

**How It Works section:**
- Japanese label: 仕組み
- Title: "How it works"
- 4 steps:

Step 01 - YOU REACH OUT
"Tell us who you are and how you're connected to the community. Conventions you attend, cosplay you do, content you create, clubs you're in — whatever your world is."

Step 02 - YOU GET YOUR CODE
"We create a unique discount code for you — something like YOURNAME10. Your people get 10% off tickets when they use it. Everything is tracked through Eventbrite so it's clean and transparent."

Step 03 - SHARE HOW YOU SHARE
"Post on your socials, mention it at a meetup, text your group chat. However you naturally connect with people — do that. No scripts. No pressure. If you think this is worth talking about, talk about it."

Step 04 - WE TAKE CARE OF YOU
"25% of ticket revenue from your code comes back to you. Paid within 7 days after each event — PayPal, Venmo, or Zelle. This isn't charity. Your reach matters and we respect that."

**Compensation section:**
- Japanese label: 透明性
- Title: "The numbers, straight up"
- Intro: "Your code gives people 10% off. You earn 25% of what they pay after the discount. Here's what that actually looks like:"
- Early Bird: $4.50 per ticket ($20 → $18 after discount)
- General: $5.63 per ticket ($25 → $22.50 after discount)
- Example: "If 20 people use your code on early bird tickets, that's **$90**. 50 people? **$225**. Plus you earn free entry after 10 ticket uses and guest passes after 25."

**Partner Tiers:**
- Japanese label: パートナー
- Title: "Partner tiers"

🌱 COMMUNITY PARTNER — Cosplayers, content creators, community members
"You have a following — even a small one — and people trust your taste. You share your code naturally and help get the word out to your circle."

⭐ FEATURED PARTNER — Established creators with consistent reach
"You've got an audience that looks to you. We feature you on our channels, collaborate on content, and give you early access to event details for your posts."

🏢 BUSINESS PARTNER — Shops, clubs, organizations
"Comic stores, manga cafés, anime clubs, convention groups — if your space or org has a built-in audience, we'll create a partnership that works for both of us."

**FAQ section:**
- Japanese label: 質問
- Title: "Common questions"

Q: "Do I have to attend every event?"
A: "No. Partnership isn't about attendance — it's about helping spread the word to your community. Some of our partners can't always make events but genuinely support what we're building."

Q: "What if I have a convention conflict?"
A: "We get it — cons come first. Your code still works whether you're there or not. If Colossal or AnimeNYC falls on the same weekend, no stress."

Q: "How do I get paid?"
A: "Within 7 days after each event. PayPal, Venmo, or Zelle — your choice. We pull the Eventbrite data, calculate your code's performance, and send you a breakdown with payment."

Q: "What's the minimum follower count?"
A: "There isn't one. If you're connected to 30 people in a Rutgers anime club group chat and they all come, that's more valuable than 10K disengaged followers. We care about real community reach."

Q: "Will my name be on a public 'promoters' page?"
A: "No. This page isn't indexed by search engines and your partnership is between us. We'll only feature you publicly if you want to be featured."

**Application form:** ⚠️ Keep the existing Formspree endpoint. Only change the visible labels:
- Name → "Name"
- Email → "Email"
- Social → "Instagram / Social" (placeholder: @yourusername)
- Type → "Partner Type" with options: Community Partner, Featured Partner, Business Partner
- Message → "Tell us about your connection to the anime community" (placeholder: "What conventions do you attend? What communities are you part of? Why do you want to help build this?")
- Submit button: "SEND IT"

### STEP 4: Update Footer
Same footer structure as the homepage.

### STEP 5: Remove ALL language like:
- "Turn your network into cash"
- "Get paid to promote"
- "Earn commission"
- Any MLM / hustle language
- Any mention of "promoter" as a title (use "partner" instead)
- Any mention of Club Mogra, Club Kaiju, Dallas, Tokyo

---

## PAGE 3: WORK WITH US (/work-with-us/index.html)

### STEP 1: Update Nav
Same nav structure as the partners page (Home, Events, Partners, TICKETS CTA).

Actually — for this page:
- Home → /
- Events → /elven-grove/
- Partners → /partners/
- TICKETS (CTA) → /elven-grove/

### STEP 2: Replace ALL copy

**Hero:**
- Japanese label: 一緒に作ろう
- Headline: "Bring your **craft** to the floor." (the "craft" in green gradient)
- Description: "We're building a monthly home for anime nightlife in NJ. That takes DJs who know the music, artists who bring the visuals, vendors with product the community actually wants, and cosplay guests who set the tone. If you do any of that — let's talk."

**Who We're Looking For:**
- Japanese label: 募集中
- Title: "Who we're looking for"

4 role cards:

🎧 DJs
"Anisong, J-Core, happy hardcore, future bass, denpa — if you spin music rooted in anime and Japanese club culture, we want to hear your sets. Full sound system and booth provided by the venue."

🎨 VISUAL ARTISTS
"VJs, projection artists, LED designers — each event has a theme. We need people who can make a room feel like another world. If you've done convention panels or club visuals, you're already ahead."

🛍️ VENDORS
"Anime merch, original art, cosplay accessories, Japanese snacks — if your product belongs at a convention, it belongs here. Flat table fee, you keep 100% of sales at the first event."

✨ COSPLAY GUESTS
"If your cosplay stops people in their tracks, we want you in the room. Featured guests get free entry, promo on our channels, and the spotlight during the cosplay showcase."

**What You Get:**
- Japanese label: 提供
- Title: "What you get"
- Intro: "This isn't a warehouse pop-up. **QXT's is a real nightclub** with a real sound system, professional security, a full bar, and screens running anime visuals all night. We handle the venue — you bring your craft."

5 bullet points:
◆ **Professional venue** — full sound system, lighting, DJ booth, bar, and dedicated security. Everything you need is already there.
◆ **Recurring platform** — this isn't one-and-done. Monthly events mean a monthly home base for your craft.
◆ **Audience that gets it** — convention crowd, anime club members, cosplayers. People who know the music and love the culture.
◆ **Multi-floor setup** — main floor for music and dancing, downstairs area available for vendors and overflow. Space to build something real.
◆ **Fair compensation** — DJs are paid per set. Vendors keep their sales. Cosplay guests receive comps and promotion. We'll work something out that respects your time.

**IMPORTANT:** Do NOT claim CDJ-3000s, "400+ capacity," "30+ years," or any other specific detail we haven't confirmed with the venue owner. Only state what's listed above.

**Contact form:** ⚠️ Keep existing Formspree endpoint. Only change labels:
- Name → "Name / Artist Name" (placeholder: "Your name or stage name")
- Email → "Email"
- Role → dropdown: DJ, VJ / Visual Artist, Vendor, Cosplay Guest, Photographer / Videographer, Other
- Links → "Links to your work" (placeholder: "SoundCloud, Instagram, portfolio, etc.")
- Message → "Tell us about yourself" (placeholder: "What's your experience? What genres/styles do you work with? What excites you about this?")
- Submit: "SEND IT"
- Below form: "Prefer email? Hit us directly at anikuranj@gmail.com"

### STEP 3: Update Footer
Same footer as other pages.

### STEP 4: Remove ALL mentions of:
- Club Mogra, Club Kaiju, Dallas, Tokyo, or any competitor
- CDJ-3000s or specific equipment we haven't confirmed
- "400+ capacity" or any capacity number
- "30+ years" or specific venue age claims

---

## PAGE 4: ELVEN GROVE (/elven-grove/index.html)

### STEP 1: Update Nav
Same nav as homepage: The Vibe, Events, Join the Crew, TICKETS

### STEP 2: Update Footer
Same footer as other pages (with Partners and Work With Us at reduced opacity).

### STEP 3: Copy changes
Scan this page for:
- Any mention of Club Mogra, Club Kaiju, Dallas, Tokyo → REMOVE
- Any mention of CDJ-3000s or unconfirmed equipment → REMOVE
- Any link to `/promote/` → change to `/partners/`
- Make sure the date says March 26 (confirmed by Ronaldo)

⚠️ DO NOT touch any ticketing integration, email signup forms, or JavaScript on this page.

---

## GLOBAL RULES (Apply to ALL pages):

1. **NEVER mention** Club Mogra, Club Kaiju, AniParty, Touch Grass, Dallas, Tokyo, or any other anime nightclub/event by name on the public website.
2. **NEVER claim** CDJ-3000s, "400+ capacity," "30+ years," or anything not explicitly confirmed by the venue.
3. **Partners and Work With Us links** appear ONLY in the footer, at reduced opacity (50%). Never in the main navigation.
4. **The /partners/ page** must have `<meta name="robots" content="noindex, nofollow">` — it should not be indexed by Google.
5. **DO NOT break** any existing email marketing integrations (Brevo, Formspree, confetti, MOONLIGHT10 code).
6. **Preserve** all existing JavaScript functionality, form endpoints, and API calls.
7. The word "promoter" should not appear anywhere on the public site. Use "partner" instead.

---

## HOW TO GIVE THIS TO CURSOR

### Option A: One page at a time (RECOMMENDED)
1. Open the homepage (index.html) in Cursor
2. Paste the PAGE 1 section + GLOBAL RULES into Cursor's chat
3. Say: "Update the copy and navigation on this page according to these instructions. Do NOT modify any JavaScript, form endpoints, or email integration code. Only change the HTML text content, nav structure, and footer."
4. Review the changes, test the email signup still works
5. Commit to git
6. Move to PAGE 2, repeat

### Option B: Give it the full doc
1. Save this entire file somewhere in your project (like /docs/cursor-update-instructions.md)
2. Open Cursor, reference the file
3. Say: "Read cursor-update-instructions.md. Start with PAGE 1 (homepage). Only do one page at a time. Do NOT modify any JavaScript, form action URLs, Brevo API code, or Formspree endpoints. Wait for my confirmation before moving to the next page."

### After EACH page, test:
- [ ] Email signup form still submits
- [ ] Confetti still fires on signup
- [ ] Success modal still appears with MOONLIGHT10 code
- [ ] No broken links
- [ ] Nav shows correct items
- [ ] Footer shows correct items
- [ ] No competitor names anywhere on page
- [ ] No unconfirmed venue claims