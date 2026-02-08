# CURSOR INSTRUCTIONS: Moonlight Website Update

> ## Read This First

> I'm updating the copy, navigation, and footer across my Moonlight Otaku Nights website. The site already has WORKING email marketing (Brevo integration, Formspree forms, confetti celebrations, MOONLIGHT10 discount code). **DO NOT replace or overwrite any JavaScript, form action URLs, Brevo API calls, or Formspree endpoint IDs.** Only change the HTML copy, CSS, and structural elements I specify.

> Work through this ONE PAGE AT A TIME. After each page, stop and let me verify before moving to the next.

---

> ## ═══════════════════════════════════════════════════════════
> ## GLOBAL RULES — Apply to ALL pages, ALL copy
> ## These are NON-NEGOTIABLE. Apply to EVERY output.
> ## ═══════════════════════════════════════════════════════════

> 1. **NO NEGATIVE COMPARISONS** — Only describe what Moonlight IS, never what it ISN'T. No "not generic EDM," "not a costume party," "no top 40," "not a warehouse," "there's no recurring anime nightclub in NJ." Describe what we are, positively.

> 2. **NO ARTIST NAME-DROPS** — Never mention specific DJs, producers, or artists by name unless that person is CONFIRMED to perform at the specific event being promoted. Always describe genres: "anisong, J-Core, happy hardcore, J-pop." Never: "USAO, t+pazolite, Camellia, DJ Genki, S3RL, Gammer." Naming unconfirmed artists is false advertising.

> 3. **NO COMPETITOR NAMES** — Never mention other anime nightclub brands, events, or venues by name. No Club Mogra, Club Kaiju, AniParty, Touch Grass, Dallas, Tokyo, or any other anime nightclub/event. These are internal strategy references only.

> 4. **NO DOLLAR AMOUNTS FOR PRIZES** — Never say "$200 prize" or "$200 cosplay competition." Say "cosplay competition with prizes" or "winners take home exclusive collectibles, replica props, and bragging rights."

> 5. **INCLUSIVE DRESS CODE** — Never say "club attire" or "nightclub-ready" or deny entry based on clothing. Say "whatever you want — cosplay, goth, punk, steampunk, rave gear, kandi, casual. No costume? No problem."

> 6. **INCLUSIVE ENTRY LANGUAGE** — Never say "no exceptions" or aggressive enforcement language. Say "21+ with valid ID required at entry."

> 7. **RE-ENTRY IS ALLOWED** — QXT's allows re-entry. Never say "no re-entry." Say "Re-entry allowed — let security know at the door."

> 8. **EARLY BIRD TICKETS** — No overselling language. No "no codes, no catches." Just: "First 50 tickets. First come, first served."

> 9. **VENDING VILLAGE** — Always mention the vending village (vendors downstairs with anime merch, collectibles, cosplay accessories) as part of the event experience.

> 10. **PARTNER LANGUAGE** — Never use "promoter," "promote," "earn commission," "turn your network into cash." Always use "community partner," "founding partner," "help build the scene."

> ## ADDITIONAL GLOBAL RULES:

> - **NEVER claim** CDJ-3000s, "400+ capacity," "30+ years," or anything not explicitly confirmed by the venue.
> - **Partners and Work With Us links** appear ONLY in the footer, at reduced opacity (50%). Never in the main navigation.
> - **The /partners/ page** must have `<meta name="robots" content="noindex, nofollow">` — it should not be indexed by Google.
> - **DO NOT break** any existing email marketing integrations (Brevo, Formspree, confetti, MOONLIGHT10 code).
> - **Preserve** all existing JavaScript functionality, form endpoints, and API calls.
> - The word "promoter" should not appear anywhere on the public site. Use "partner" instead.

> ## EMAIL ADDRESSES — Use the correct one for each context:

> - **Website footer / general contact:** info@moonlightotakunights.com
> - **Work With Us / talent page:** talent@moonlightotakunights.com
> - **Partners page:** promo@moonlightotakunights.com
> - **NEVER use anikuranj@gmail.com on any public page.** That is the internal backend only.

> ## TICKET PRICING (Current / Correct):

> - **Early Bird:** $15 (first 50 tickets, no promo codes apply)
> - **GA:** $20 (before 11:30pm, MOONLIGHT10 = 10% off = $18)
> - **Late Night:** $25 (after 11:30pm or at door, MOONLIGHT10 = 10% off = $22.50)

---

> ## PAGE 1: HOMEPAGE (index.html)
> ## ✅ COMPLETED — Do not redo unless specifically asked.

---

> ## PAGE 2: PARTNERS PAGE (/promote/index.html → rename folder to /partners/)

> ### IMPORTANT STRUCTURAL CHANGE

> The current folder is probably called `/promote/`. Rename it to `/partners/`. Update all internal links that point to `/promote/` to point to `/partners/` instead.

> ### STEP 1: Add noindex meta tag

> Add this to the `<head>` section:

> ```html
> <meta name="robots" content="noindex, nofollow">
> ```

> This prevents search engines from indexing this page. We don't want it showing up in Google.

> ### STEP 2: Update the Nav Bar

> For subpages the nav should be:

> - Home → /
> - Events → /elven-grove/
> - Collaborate → /work-with-us/
> - TICKETS (CTA) → /elven-grove/

> NO link to "Partners" or "Promote" in the nav bar.

> ### STEP 3: Replace ALL copy on this page

> **Hero:**

> - Japanese label: コミュニティパートナー
> - Headline: "This scene doesn't **build itself.**" (the "build itself" part in green gradient)
> - Description: "We're building NJ's first recurring anime nightclub — monthly themed nights with real anisong and J-Core music, cosplay competitions, and a community that gets it. If you're a cosplayer, content creator, convention regular, or someone who runs in these circles and wants to be part of this, we want to work with you. Not as a sales rep. As someone who's helping build something real."

> **How It Works section:**

> - Japanese label: 仕組み
> - Title: "How it works"
> - 4 steps:

> Step 01 - YOU REACH OUT

> "Tell us who you are and how you're connected to the community. Conventions you attend, cosplay you do, content you create, clubs you're in — whatever your world is."

> Step 02 - YOU GET YOUR CODE

> "We create a unique discount code for you — something like YOURNAME10. Your people get 10% off GA and Late Night tickets when they use it. Everything is tracked through Eventbrite so it's clean and transparent."

> Step 03 - SHARE HOW YOU SHARE

> "Post on your socials, mention it at a meetup, text your group chat. However you naturally connect with people — do that. No scripts. No pressure. If you think this is worth talking about, talk about it."

> Step 04 - WE TAKE CARE OF YOU

> "25% of ticket revenue from your code comes back to you. Paid within 7 days after each event — PayPal, Venmo, or Zelle. Your reach matters and we respect that."

> **Compensation section:**

> - Japanese label: 透明性
> - Title: "The numbers, straight up"
> - Intro: "Your code gives people 10% off GA and Late Night tickets. You earn 25% of what they pay after the discount. Here's what that actually looks like:"

> - GA Tickets: $4.50 per ticket ($20 → $18 after discount, you earn 25% = $4.50)
> - Late Night Tickets: $5.63 per ticket ($25 → $22.50 after discount, you earn 25% = $5.63)
> - Early Bird Tickets: No partner codes apply. Early Bird is first 50, first come first served.

> - Example: "If 20 people use your code on GA tickets, that's **$90**. 50 people? **$225**. Plus you earn free entry after 10 ticket uses and guest passes after 25."

> **Partner Tiers:**

> - Japanese label: パートナー
> - Title: "Partner tiers"

> 🌱 COMMUNITY PARTNER — Cosplayers, content creators, community members

> "You have a following — even a small one — and people trust your taste. You share your code naturally and help get the word out to your circle."

> ⭐ FEATURED PARTNER — Established creators with consistent reach

> "You've got an audience that looks to you. We feature you on our channels, collaborate on content, and give you early access to event details for your posts."

> 🏢 BUSINESS PARTNER — Shops, clubs, organizations

> "Comic stores, manga cafés, anime clubs, convention groups — if your space or org has a built-in audience, we'll create a partnership that works for both of us."

> **FAQ section:**

> - Japanese label: 質問
> - Title: "Common questions"

> Q: "Do I have to attend every event?"
> A: "No. Partnership isn't about attendance — it's about helping spread the word to your community. Some of our partners can't always make events but genuinely support what we're building."

> Q: "What if I have a convention conflict?"
> A: "We get it — cons come first. Your code still works whether you're there or not. No stress."

> Q: "How do I get paid?"
> A: "Within 7 days after each event. PayPal, Venmo, or Zelle — your choice. We pull the Eventbrite data, calculate your code's performance, and send you a breakdown with payment."

> Q: "What's the minimum follower count?"
> A: "There isn't one. If you're connected to 30 people in a Rutgers anime club group chat and they all come, that's more valuable than 10K disengaged followers. We care about real community reach."

> Q: "Will my name be on a public 'promoters' page?"
> A: "No. This page isn't indexed by search engines and your partnership is between us. We'll only feature you publicly if you want to be featured."

> **Application form:** ⚠️ Keep the existing Formspree endpoint. Only change the visible labels:

> - Name → "Name"
> - Email → "Email"
> - Social → "Instagram / Social" (placeholder: @yourusername)
> - Type → "Partner Type" with options: Community Partner, Featured Partner, Business Partner
> - Message → "Tell us about your connection to the anime community" (placeholder: "What conventions do you attend? What communities are you part of? Why do you want to help build this?")
> - Submit button: "SEND IT"
> - Below form: "Prefer email? Hit us directly at promo@moonlightotakunights.com"

> ### STEP 4: Update Footer

> Same footer structure as the homepage (see footer spec below).

> ### STEP 5: Remove ALL language like:

> - "Turn your network into cash"
> - "Get paid to promote"
> - "Earn commission"
> - Any MLM / hustle language
> - Any mention of "promoter" as a title (use "partner" instead)
> - Any mention of Club Mogra, Club Kaiju, Dallas, Tokyo
> - Any reference to anikuranj@gmail.com

---

> ## PAGE 3: WORK WITH US (/work-with-us/index.html)

> ### STEP 1: Update Nav

> - Home → /
> - Events → /elven-grove/
> - Partners → /partners/
> - TICKETS (CTA) → /elven-grove/

> ### STEP 2: Replace ALL copy

> **Hero:**

> - Japanese label: 一緒に作ろう
> - Headline: "Bring your **craft** to the floor." (the "craft" in green gradient)
> - Description: "We're building a monthly home for anime nightlife in NJ. That takes DJs who know the music, artists who bring the visuals, vendors with product the community actually wants, and cosplay guests who set the tone. If you do any of that — let's talk."

> **Who We're Looking For:**

> - Japanese label: 募集中
> - Title: "Who we're looking for"

> 4 role cards:

> 🎧 DJs

> "Anisong, J-Core, happy hardcore, future bass, denpa — if you spin music rooted in anime and Japanese club culture, we want to hear your sets. Full sound system and booth provided by the venue."

> 🎨 VISUAL ARTISTS

> "VJs, projection artists, LED designers — each event has a theme. We need people who can make a room feel like another world. If you've done convention panels or club visuals, you're already ahead."

> 🛍️ VENDORS

> "Anime merch, original art, cosplay accessories, Japanese snacks — if your product belongs at a convention, it belongs here. Flat table fee, you keep 100% of sales at the first event."

> ✨ COSPLAY GUESTS

> "If your cosplay stops people in their tracks, we want you in the room. Featured guests get free entry, promo on our channels, and the spotlight during the cosplay showcase."

> **What You Get:**

> - Japanese label: 提供
> - Title: "What you get"
> - Intro: "**QXT's is a venue built on inclusivity** — a space where alternative culture has always been welcome. Real sound system, professional security, a full bar, and screens running anime visuals all night. We handle the venue — you bring your craft."

> 5 items:

> ◆ **Professional venue** — full sound system, lighting, DJ booth, bar, and dedicated security. Everything you need is already there.

> ◆ **Recurring platform** — this isn't one-and-done. Monthly events mean a monthly home base for your craft.

> ◆ **Audience that gets it** — convention crowd, anime club members, cosplayers. People who know the music and love the culture.

> ◆ **Multi-floor setup** — main floor for music and dancing, downstairs vending village for vendors, merch, and overflow. Space to build something real.

> ◆ **Fair compensation** — DJs are paid per set. Vendors keep their sales. Cosplay guests receive comps and promotion. We'll work something out that respects your time.

> **IMPORTANT:** Do NOT claim CDJ-3000s, "400+ capacity," "30+ years," or any other specific detail we haven't confirmed with the venue owner. Only state what's listed above.

> **Contact form:** ⚠️ Keep existing Formspree endpoint. Only change labels:

> - Name → "Name / Artist Name" (placeholder: "Your name or stage name")
> - Email → "Email"
> - Role → dropdown: DJ, VJ / Visual Artist, Vendor, Cosplay Guest, Photographer / Videographer, Other
> - Links → "Links to your work" (placeholder: "SoundCloud, Instagram, portfolio, etc.")
> - Message → "Tell us about yourself" (placeholder: "What's your experience? What genres/styles do you work with? What excites you about this?")
> - Submit: "SEND IT"
> - Below form: "Prefer email? Hit us directly at talent@moonlightotakunights.com"

> ### STEP 3: Update Footer

> Same footer as other pages (see footer spec below).

> ### STEP 4: Remove ALL mentions of:

> - Club Mogra, Club Kaiju, Dallas, Tokyo, or any competitor
> - CDJ-3000s or specific equipment we haven't confirmed
> - "400+ capacity" or any capacity number
> - "30+ years" or specific venue age claims
> - anikuranj@gmail.com

---

> ## PAGE 4: ELVEN GROVE (/elven-grove/index.html)

> ### STEP 1: Update Nav

> Same nav as homepage: The Vibe, Events, Join the Crew, TICKETS

> ### STEP 2: Update Footer

> Same footer as other pages (see footer spec below).

> ### STEP 3: Copy changes

> Scan this page for:

> - Any mention of Club Mogra, Club Kaiju, Dallas, Tokyo → REMOVE
> - Any mention of CDJ-3000s or unconfirmed equipment → REMOVE
> - Any link to `/promote/` → change to `/partners/`
> - Any reference to anikuranj@gmail.com → change to info@moonlightotakunights.com
> - Make sure the date says March 26 (confirmed by Ronaldo)
> - Make sure ticket pricing shows: $15 Early Bird / $20 GA / $25 Late Night
> - Make sure dress code says: "whatever you want — cosplay, goth, punk, steampunk, rave gear, kandi, casual. No costume? No problem."
> - Make sure re-entry says: "Re-entry allowed — let security know at the door."
> - Make sure entry language says: "21+ with valid ID required at entry." (NOT "no exceptions")
> - Make sure cosplay competition says "cosplay competition with prizes" (NOT "$200 cosplay competition")
> - Make sure vending village is mentioned as part of the event experience
> - Parking: Free street parking nearby. Paid garage one block from venue.

> ⚠️ DO NOT touch any ticketing integration, email signup forms, or JavaScript on this page.

---

> ## FOOTER SPEC (Use on ALL pages)

> **Left column (brand):**

> - "MOONLIGHT OTAKU NIGHTS" (in display font, green)
> - "アニクラ NJ" (small, dim)
> - "Building NJ's anime nightlife scene. One night at a time."

> **Middle column - NAVIGATE:**

> - Current Event → /elven-grove/
> - Instagram → https://instagram.com/moonlightotakunights

> **Right column - DETAILS:**

> - QXT's Newark — 248 Mulberry St, Newark NJ 07102
> - info@moonlightotakunights.com
> - 21+ with valid ID
> - Community Partners → /partners/ (style this link at 50% opacity, subtle)
> - Work With Us → /work-with-us/ (style this link at 50% opacity, subtle)

> **Footer bottom:**

> - Left: "© 2026 Moonlight Otaku Nights. All rights reserved."
> - Right: Social media icons (Instagram, TikTok, Discord)

---

> ## HOW TO GIVE THIS TO CURSOR

> ### Option A: One page at a time (RECOMMENDED)

> 1. Open the relevant page file in Cursor
> 2. Paste the relevant PAGE section + GLOBAL RULES into Cursor's chat
> 3. Say: "Update the copy and navigation on this page according to these instructions. Do NOT modify any JavaScript, form endpoints, or email integration code. Only change the HTML text content, nav structure, and footer."
> 4. Review the changes, test the email signup still works
> 5. Commit to git
> 6. Move to next PAGE, repeat

> ### Option B: Give it the full doc

> 1. Save this entire file somewhere in your project (like /docs/cursor-update-instructions.md)
> 2. Open Cursor, reference the file
> 3. Say: "Read cursor-update-instructions.md. Start with PAGE 2 (Partners — Page 1 is already done). Only do one page at a time. Do NOT modify any JavaScript, form action URLs, Brevo API code, or Formspree endpoints. Wait for my confirmation before moving to the next page."

> ### After EACH page, test:

> - [ ] Email signup form still submits
> - [ ] Confetti still fires on signup
> - [ ] Success modal still appears with MOONLIGHT10 code
> - [ ] No broken links
> - [ ] Nav shows correct items
> - [ ] Footer shows correct items with info@moonlightotakunights.com
> - [ ] No competitor names anywhere on page
> - [ ] No unconfirmed venue claims
> - [ ] No anikuranj@gmail.com on any public page
> - [ ] No artist name-drops
> - [ ] No dollar amounts for prizes
> - [ ] Dress code is inclusive ("whatever you want")
> - [ ] Re-entry is allowed
> - [ ] Entry language says "21+ with valid ID required at entry"