# CURSOR INSTRUCTIONS v2: Moonlight Website Update
## Updated March 11, 2026 — Supersedes All Prior cursor-instructions.md

---

## ⚠️ READ THIS FIRST

I'm updating the Moonlight Otaku Nights website to reflect major changes since the last update. The site has WORKING email marketing (Brevo, Formspree, confetti, MOONLIGHT10 code). **DO NOT replace or overwrite any JavaScript, form action URLs, Brevo API calls, or Formspree endpoint IDs.**

Work through this ONE PAGE AT A TIME. After each page, stop and let me verify before moving to the next.

### What Changed Since Last Instructions
- **Neon Rain and Mecha Night are SHELVED.** They are no longer Events #2 and #3. Replaced by Vocaloid Nights (May 6–7).
- **Full DJ lineup is CONFIRMED and SIGNED** for Event #1 — all 4 acts. AniParty (DJ Appare + DJ Th3rdEye) was missing from the website and must be added.
- **greenteawasted's set time was wrong on the site** — shows 10PM–12AM, should be 10PM–11PM.
- **New full-lineup flyer exists** — must replace old flyer images across the site.
- **"DUBSTEP" genre tag must be removed** from Elven Grove page — replace with FUTURE BASS.
- **The word "authentic" is BANNED** from all public copy — it appears on the homepage and must be removed.
- **Vocaloid Nights (May 6–7)** needs placeholder content on the homepage.
- **Side quest system and enhanced cosplay/vendor info** should be highlighted on the event page.

---

## TASK 0: FILE SYSTEM AUDIT (DO THIS FIRST)

Before making any content changes, run this audit and report back what you find.

### 0A: List all image files in `/assets/images/flyers/`
Report every file name and note which ones are currently referenced in HTML. Flag any that are NOT referenced anywhere (orphan files).

### 0B: List all image files in `/assets/images/dj_images/`
Report every file. Confirm we have images for: greenteawasted, FaithInTheGlitch, Jalisha_Paz, AND DJ Appare + DJ Th3rdEye (AniParty). If AniParty images are missing, flag it.

### 0C: Check for orphan pages/folders
List all top-level folders and HTML pages. Flag anything that looks like:
- Old `/promote/` folder (should have been renamed to `/partners/`)
- Any test pages, draft pages, or temp files
- Any AI-generated content files (audio, video, images with "suno" or "ai" in the filename)

### 0D: Check for old email references
Search all HTML files for `anikuranj@gmail.com` — this email should NOT appear anywhere on the public site. The correct email is `info@moonlightotakunights.com`. Report any instances found.

### 0E: Check for banned words/phrases
Search all HTML files for:
- "authentic" (BANNED — Rule #12)
- "Club Mogra" or "Club Kaiju" (BANNED — Rule #4)
- "promoter" (should be "partner")
- "Vending Village" (should be "vendor pop-up")
- "400+ capacity" or "30+ years" (unconfirmed claims)
- "CDJ-3000" (unconfirmed equipment)

Report all instances with file name and line number.

### 0F: Verify meta tags
Confirm that `/partners/index.html` has `<meta name="robots" content="noindex, nofollow">` in the `<head>`.

**STOP after Task 0 and report findings before proceeding.**

---

## PAGE 1: HOMEPAGE (index.html)

### CHANGE 1: Fix the banned word "authentic"

Find the section that says:
```
An authentic anikura experience.
```

Change to:
```
A dedicated anikura experience.
```

The word "authentic" is permanently banned from all public copy per brand rules. Use "dedicated" or "anikura-style" instead.

### CHANGE 2: Replace "What's Coming" section

The current section shows two cards:
- **APRIL 2026 — NEON RAIN** (cyberpunk theme)
- **MAY 2026 — MECHA NIGHT** (mecha theme)

**These are SHELVED.** Replace the entire "What's Coming" section with this:

**Section label (Japanese):** 予定
**Section title:** "What's Coming"
**Section subtitle:** "Every month, a new world. Same crew, different dimension."

**Card 1:**
- Date: MAY 6, 2026
- Title: VOCALOID NIGHTS — PRE-PARTY
- Description: "The night before Hatsune Miku EXPO hits Newark. Vocaloid, future funk, and denpa all night. Two stages. The warm-up starts here."
- Status: DETAILS COMING SOON

**Card 2:**
- Date: MAY 7, 2026
- Title: VOCALOID NIGHTS — AFTER-PARTY
- Description: "Prudential Center closes. QXT's opens. Two blocks away. The unofficial after-party for everyone who isn't ready to go home."
- Status: DETAILS COMING SOON

**Card 3 (optional — add only if design supports 3 cards):**
- Date: COMING SOON
- Title: NEON RAIN
- Description: "Cyberpunk anime. Edgerunners. Akira. Ghost in the Shell. This one's been postponed — not cancelled. Stay tuned."
- Status: POSTPONED — DATE TBA

If the design only supports 2 cards, drop Neon Rain entirely and just use the two Vocaloid Nights cards.

**IMPORTANT:** Do NOT use "official" anywhere in the Vocaloid Nights descriptions. Always frame as "unofficial." Do NOT use "Hatsune Miku" in the event title — only in the description as context. "Vocaloid" is acceptable in the title.

### CHANGE 3: Update the homepage flyer image

The current flyer image in the "Next Event" section uses:
```
/assets/images/flyers/elven-grove.jpg
```

Replace with the new full-lineup flyer:
```
/assets/images/flyers/4_5 flyer Moonlight Otaku Nights march 26 2026 full lineup.png
```

**NOTE TO ME (Azael):** You need to upload this file from your Google Drive (`G:\My Drive\business\Moonlight\website\Moonlight Otaku Nights\website\assets\images\flyers\`) to the GitHub repo's `/assets/images/flyers/` folder. Rename it to something clean like `elven-grove-full-lineup.png` before uploading (no spaces in filenames for web). Then tell Cursor to reference `/assets/images/flyers/elven-grove-full-lineup.png`.

### CHANGE 4: Add Presale SOLD OUT indicator

In the "Next Event" section, the current copy says:
```
🎟️ PRESALE TICKETS LIVE NOW — Limited quantity
```

Change to:
```
🎟️ PRESALE SOLD OUT — General Admission starts at $20
```

### NO OTHER CHANGES to homepage. Leave the nav, hero, vibe section (except the "authentic" fix), email signup, and footer exactly as they are.

---

## PAGE 2: ELVEN GROVE (/elven-grove/index.html)

### CHANGE 1: Replace the flyer image

Current flyer image:
```
/assets/images/flyers/9_16_flyer website flyer.png
```

Replace with the new full-lineup flyer:
```
/assets/images/flyers/elven-grove-full-lineup.png
```

(Same file as homepage — see NOTE in Page 1, Change 3 about uploading.)

### CHANGE 2: Fix greenteawasted set time

Current:
```
greenteawasted
DJ — 10PM–12AM
```

Change to:
```
greenteawasted
DJ — 10PM–11PM
```

His contracted set time is 10:00 PM to 11:00 PM. The "10PM–12AM" is wrong.

### CHANGE 3: Restructure the entire lineup section

The lineup section needs a complete restructure. AniParty (DJ Appare + DJ Th3rdEye) is MISSING entirely, and Jalisha Paz needs to be separated from the DJ row as a live performer.

**New layout — TWO ROWS:**

**ROW 1: THE DJs (4 circular photo cards, same style as current)**

All 4 circles should be the same size and style (green glowing border). On desktop: 4 across. On mobile: single column — each card gets its own row, full width, so users scroll through them one by one. Do NOT use a 2x2 grid on mobile.

1. **greenteawasted**
   - Image: `/assets/images/dj_images/greenteawasted.jpg` (existing)
   - Name: greenteawasted
   - Subtitle: DJ — 10PM–11PM

2. **FaithInTheGlitch**
   - Image: `/assets/images/dj_images/faithintheglitch.jpg` (existing)
   - Name: FaithInTheGlitch
   - Subtitle: DJ — 11PM–12AM

3. **DJ Appare**
   - Image: `/assets/images/dj_images/dj_appare.avif` (NEW — Azael uploading)
   - Name: DJ Appare
   - Subtitle: DJ + VDJ — 12:30AM–3AM

4. **DJ Th3rdEye**
   - Image: `/assets/images/dj_images/DJ Th3rdEye.avif` (NEW — Azael uploading)
   - Name: DJ Th3rdEye
   - Subtitle: DJ — 12:30AM–3AM

**Below DJ Appare + DJ Th3rdEye (connecting them as a unit):**
Add a small badge or label that visually links cards 3 and 4 together. Display the AniParty logo image at small size (roughly 80–120px wide) with text: **"AniParty · Live VDJ Anime Visuals"**
- Logo image: `/assets/images/logos/Aniparty_logo_blocktransparent.png` (NEW — Azael uploading, white text version for dark background)
- This should sit centered beneath cards 3 and 4, not beneath all 4 cards
- Keep it subtle — a small logo + one line of text. Don't make it bigger than the DJ cards themselves.

**ROW 2: LIVE PERFORMANCE (Jalisha Paz — solo, centered)**

Separate from the DJ row with a different sub-header or visual break.

- Sub-header text: **"Performing Live"** (centered, above her card)
- Image: `/assets/images/dj_images/Jalisha_Paz.jpg` (existing)
- Name: **Jalisha Paz**
- Subtitle: **Kaigai Idol Performance + Cosplay Competition Host — Midnight**
- This should be a single centered card, slightly larger than the DJ circles if possible, or same size but clearly on its own row to emphasize she's a different kind of act.

**IMPORTANT:** All 4 DJs must appear equal in size. The AniParty logo badge is supplementary — it does NOT replace or enlarge their individual cards. The goal is: FaithInTheGlitch gets the same visual weight as DJ Appare and DJ Th3rdEye. AniParty's distinction comes from the logo badge and the VDJ callout, not from bigger cards.

**File upload checklist (Azael does this BEFORE Cursor runs):**
- [ ] `/assets/images/dj_images/dj_appare.avif` — DJ Appare photo
- [ ] `/assets/images/dj_images/DJ Th3rdEye.avif` — DJ Th3rdEye photo  
- [ ] `/assets/images/logos/Aniparty_logo_blocktransparent.png` — White text AniParty logo

### CHANGE 4: Fix genre tags

Current genre tags:
```
J-CORE | ANISONG | HAPPY HARDCORE | DUBSTEP | COSPLAY CONTEST
```

Change to:
```
J-CORE | ANISONG | HAPPY HARDCORE | FUTURE BASS | COSPLAY CONTEST
```

Remove "DUBSTEP" — replace with "FUTURE BASS." Dubstep is not in our core genre stack.

Also update the hero section tags if they appear there separately (the top of the page).

### CHANGE 5: Add re-entry information

Add a line in the event details section (near the venue/age info):
```
🔄 Re-entry: Allowed — let security know at the door
```

### CHANGE 6: Enhance "What to Expect" section

The current "What to Expect" section has these cards:
- Cosplay Contest
- Prize Raffle
- Enchanted Atmosphere
- Anime Visuals
- The Music

**Add a new card** for the Quest System (insert after Cosplay Contest):

```
🗺️ HIDDEN SIDE QUESTS
Discover hidden relics scattered across the venue. Complete quests, collect stamps, earn exclusive rewards. We're not telling you more than that — you'll have to find them yourself.
```

**Update the Cosplay Contest card** to include more detail:

```
✨ COSPLAY COMPETITION
Cosplay competition at midnight hosted by Jalisha Paz. Two categories: Judge's Pick and Crowd Favorite. Pre-register at moonlightotakunights.com/cosplay-signup and our DJs will queue your character's theme song for your walk-on. Day-of sign-ups welcome if spots remain. 12 contestant cap.
```

### CHANGE 7: Add diagonal SOLD OUT banner to the Presale ticket card

The Presale card currently shows:
```
⚡ PRESALE
$15
Limited quantity • Best price • No codes needed
```

**Do NOT remove the card.** Keep it visible but add a visual "SOLD OUT" overlay:

1. Add `position: relative; overflow: hidden;` to the Presale card container
2. Add a diagonal banner using a pseudo-element (`::before` or `::after`):
   ```css
   .presale-card::after {
     content: "SOLD OUT";
     position: absolute;
     top: 20px;
     right: -35px;
     background: #ff3333;
     color: white;
     padding: 5px 40px;
     font-size: 14px;
     font-weight: bold;
     transform: rotate(45deg);
     z-index: 10;
     letter-spacing: 1px;
   }
   ```
3. Optionally reduce the card's opacity slightly (e.g., `opacity: 0.7`) to de-emphasize it vs. the GA and Late Night cards
4. Change the description text from "Limited quantity • Best price • No codes needed" to:
   ```
   Sold out • Next best price: $20 GA
   ```
5. Either grey out or remove the "BUY ON EVENTBRITE" button on the Presale card specifically, OR change the button text to "SOLD OUT" and disable the link

**The "BEST VALUE" badge** above the card can stay — it reinforces FOMO for people who missed it.

**The GA and Late Night cards stay exactly as they are** — those are still active.

### CHANGE 8: Add "Who's Performing" highlight section (optional enhancement)

If time permits, add a brief section above or near the lineup that teases the night's flow:

```
## The Night Unfolds

10PM — Doors open. greenteawasted sets the tone with anisong and future bass.
11PM — FaithInTheGlitch takes over. Energy builds.
Midnight — Jalisha Paz hits the stage for a live kaigai idol performance, then hosts the cosplay competition.
12:30AM — AniParty closes the night. DJ Appare and DJ Th3rdEye with live anime VDJ visuals — your favorite openings synced to the music on the big screen — until 3AM.
```

This is optional — only add if it fits the page design naturally.

### NO CHANGES to the ticketing integration, email forms, venue section, cosplay inspiration section, or footer.

---

## PAGE 3: PARTNERS PAGE (/partners/index.html)

### CHANGE 1: Fix ticket tier names in compensation section

The current compensation section uses inconsistent labels. Update to match actual Eventbrite tiers:

Current:
```
General Admissions (usually until 11:00 or 11:30pm depending on the night and event) ($20 → $18 after discount)
$4.50 per ticket

Late Night tickets (around 11:00 to 11:30pm) ($25 → $22.50 after discount)
$5.63 per ticket
```

Change to:
```
General Admission — before 11:30 PM ($20 → $18 after discount)
$4.50 per ticket

Late Night — after 11:30 PM ($25 → $22.50 after discount)
$5.63 per ticket
```

Clean, matches the Eventbrite language exactly.

### CHANGE 2: Remove "early bird" references

The example section currently says:
```
If 20 people use your code on early bird tickets, that's $90. 50 people? $225.
```

Change to:
```
If 20 people use your code on GA tickets, that's $90. 50 people? $225.
```

"Early bird" is not an actual tier name. Use "GA" to match reality.

### NO OTHER CHANGES to partners page. The rest looks correct.

---

## PAGE 4: WORK WITH US (/work-with-us/index.html)

### CHANGE 1: Verify email reference

The "Prefer email?" line at the bottom of the form should say:
```
Prefer email? Hit us directly at info@moonlightotakunights.com
```

If it says `anikuranj@gmail.com`, change it to `info@moonlightotakunights.com`.

### NO OTHER CHANGES needed. This page looks correct.

---

## GLOBAL RULES (Apply to ALL pages)

These rules carry forward from v1 and are NON-NEGOTIABLE:

1. **NEVER mention** Club Mogra, Club Kaiju, Touch Grass, SonicBoomBox, Dallas, Tokyo, or any other anime nightclub/event by name on the public website. **Exception:** AniParty appears on the Elven Grove lineup page because they are contracted performers — this is NOT a competitor mention, it's talent credit.
2. **NEVER claim** CDJ-3000s, "400+ capacity," "30+ years," or anything not explicitly confirmed by the venue.
3. **Partners and Work With Us links** appear ONLY in the footer, at reduced opacity (50%). Never in the main navigation.
4. **The /partners/ page** must have `<meta name="robots" content="noindex, nofollow">`.
5. **DO NOT break** any existing email marketing integrations (Brevo, Formspree, confetti, MOONLIGHT10 code).
6. **Preserve** all existing JavaScript functionality, form endpoints, and API calls.
7. The word **"promoter"** should not appear anywhere on the public site. Use "partner."
8. **NO NEGATIVE COMPARISONS** in public copy. Only describe what Moonlight IS.
9. **NEVER use the word "authentic."** Use "dedicated," "anikura-style," or describe it specifically.
10. **NEVER say "official"** when referring to Vocaloid Nights or any connection to Hatsune Miku EXPO. Always "unofficial."
11. **Stage names render EXACTLY as styled:** greenteawasted (all lowercase), FaithInTheGlitch, DJ Appare, DJ Th3rdEye, AniParty.
12. **"Vendor pop-up"** or "merch + artist tables" or "artist alley" — NEVER "Vending Village."
13. The correct general email is **info@moonlightotakunights.com**. The old email anikuranj@gmail.com must not appear on the public site.
14. Always present Event #1 as: **Moonlight Otaku Nights presents: Elven Grove — Emerald Moon**

---

## PRIORITY ORDER

1. **Task 0 — File System Audit** (report back first)
2. **Page 2 — Elven Grove** (most critical: missing AniParty, wrong set time, wrong genre tags, old flyer)
3. **Page 1 — Homepage** (banned word fix, shelved events replacement, flyer swap, presale status)
4. **Page 3 — Partners** (minor tier name fixes)
5. **Page 4 — Work With Us** (email verification only)

---

## HOW TO GIVE THIS TO CURSOR

### Recommended approach: One section at a time

1. Open Cursor
2. Reference this file
3. Say: **"Read cursor-instructions-v2.md. Start with Task 0 (File System Audit). List all findings before making any changes."**
4. Review the audit results
5. Then say: **"Now do Page 2 (Elven Grove). Only change what's specified. Do NOT modify any JavaScript, form endpoints, or email integration code."**
6. Review, test, commit
7. Repeat for each page

### After EACH page, test:
- [ ] Email signup form still submits
- [ ] Confetti still fires on signup
- [ ] Success modal still appears with MOONLIGHT10 code
- [ ] No broken links
- [ ] Nav shows correct items
- [ ] Footer shows correct items
- [ ] No competitor names anywhere on page
- [ ] No unconfirmed venue claims
- [ ] No instances of "authentic" anywhere
- [ ] No instances of "DUBSTEP" in genre tags
- [ ] greenteawasted shows 10PM–11PM (not 12AM)
- [ ] AniParty appears in the Elven Grove lineup
- [ ] New flyer image displays correctly
- [ ] Neon Rain / Mecha Night no longer appear as upcoming (unless marked "Postponed")
- [ ] Presale card shows diagonal "SOLD OUT" banner
- [ ] Presale "BUY ON EVENTBRITE" button is disabled or changed to "SOLD OUT"
- [ ] AniParty logo badge appears beneath DJ Appare + DJ Th3rdEye cards only
- [ ] Jalisha Paz is on her own row with "Performing Live" header
- [ ] All 4 DJ circles are equal size (FaithInTheGlitch not visually smaller than AniParty DJs)
- [ ] DJ photos (AVIF) load correctly across browsers

---

## FILES YOU (AZAEL) NEED TO UPLOAD BEFORE CURSOR STARTS

1. **New full-lineup flyer** → rename to `elven-grove-full-lineup.png` (no spaces) → push to `/assets/images/flyers/`
   - Source: `G:\My Drive\business\Moonlight\website\Moonlight Otaku Nights\website\assets\images\flyers\4_5 flyer Moonlight Otaku Nights march 26 2026 full lineup.png`

2. **DJ Appare photo** → push to `/assets/images/dj_images/dj_appare.avif`
   - Source: `G:\My Drive\business\Moonlight\website\Moonlight Otaku Nights\website\assets\images\dj_images\dj_appare.avif`

3. **DJ Th3rdEye photo** → push to `/assets/images/dj_images/DJ_Th3rdEye.avif`
   - Source: `G:\My Drive\business\Moonlight\website\Moonlight Otaku Nights\website\assets\images\dj_images\DJ Th3rdEye.avif`
   - ⚠️ Rename to `DJ_Th3rdEye.avif` (underscore instead of space) for web safety

4. **AniParty logo (white text on dark)** → push to `/assets/images/logos/Aniparty_logo_blocktransparent.png`
   - Source: `G:\My Drive\business\Moonlight\website\Moonlight Otaku Nights\website\assets\images\logos\Aniparty_logo_blocktransparent.png`

5. Once all 4 files are pushed to GitHub, tell Cursor to proceed with the page updates.

**⚠️ AVIF NOTE:** The DJ photos are `.avif` format. AVIF has good modern browser support (Chrome, Firefox, Edge, Safari 16+). If Cursor flags compatibility concerns, convert to `.jpg` or `.webp` and update filenames in these instructions accordingly.

---

## CLEANUP: FILES TO CONSIDER REMOVING

After the audit, consider removing these from the repo (if found):
- Old flyer versions that are no longer referenced (keep only `elven-grove-full-lineup.png` and any referenced images)
- Any AI-generated audio/video files (these should have been removed already, but verify)
- The old `9_16_flyer website flyer.png` AFTER the new flyer is confirmed working
- Any draft or test HTML pages not linked from the live site