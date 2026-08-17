# MAVÉA product copy · the first twenty

Drafted 2026-08-16 from the garment renders and `NAMES.md`. One entry per dress, written
to the voice contract in `design/docs/brand-guidelines.md` §2 and §7: say what a thing is,
numbers over adjectives, modesty is the given and never the pitch, no urgency, no scarcity
theatre, no exclamation marks.

**How the fields map to the site.** *Spec line* is the short line under the title on the
PDP, in the format the design already uses ("Unlined linen · falls to the ankle · sleeves
to the wrist"). It doubles as the WooCommerce short description. *Description* is the long
description. *Details* can render as bullets or feed the Measurements accordion.
*Fabric and care* is the first accordion. *Listing* is the taxonomy block: category,
attributes, tags and slug, defined below.

**Before any of this is published.** These garments exist as renders, not yet as cloth.
Every fabric word below, including the ones inside product names, is a read from an image
and is marked ⚠ where it must be checked against the production sample. Nothing here
claims a lining, opacity, stretch or care instruction, on purpose: add those only once the
real cloth is in hand. The verification list is at the bottom.

**Where to start** (for the collection page or a buying guide):
- One dress to live in: **Rahma**, or **Safiya** if you want colour.
- For work: **Hafsa** or **Muneera**.
- For a wedding: **Aleena**, **Nasreen** or **Zarina**.
- Hand-beaded, for the big occasions: **Nadira**, **Zaheera** or **Anisa**.

---

# Taxonomy · how the store is organised

Fixed by `00-PLAN.md` §sitemap and the approved design (desktop nav: New in · Abayas ·
Dresses · Hijabs; mobile filter sheet; colour-led hijab browse). Set these up once in
WooCommerce before listing anything.

## Categories (`product_cat`)

| Category | Slug | Holds today | Notes |
|---|---|---|---|
| Abayas | `abayas` | 19 of the 20 | Sets included (Aleena, Nasreen) until there are enough for their own category |
| Dresses | `dresses` | 1 (Hafsa) | Register now, keep out of the nav until it holds ~3 pieces |
| Hijabs | `hijabs` | 0 | Launch line per the plan (Rs. 1,900 to 3,900, replenishable); the colour-led browse is already designed |
| Hand-beaded | `hand-beaded` | 9 | Cross-cutting collection shelf, decided 2026-08-16: every piece whose defining ornament is set by hand, bead by bead. These sit in Abayas as well; the shelf exists because hand-beading is slow work, priced above the plain pieces, and deserves its own address in the nav |

"New in" in the nav is a sorted view (newest first), never a category. There is no Sale
category and never will be (brand guidelines §7).

## Attributes

**Colour** (`pa_colour`) · one value per product · shown as swatches in the filter sheet.
Canonical values, chosen so no bucket is a singleton shade: the precise shade lives in the
spec line, the filter value stays broad.

| Value | Products | Count |
|---|---|---|
| Black | Suhana, Raihana, Nadira, Shazna, Zaheera, Muneera, Zarina, Latheefa, Naila | 9 |
| Grey | Fizza (silver), Salma (warm), Hafsa (heather) | 3 |
| Blue | Safiya (powder) | 1 |
| Rose | Warda (dusty) | 1 |
| Blush | Anisa | 1 |
| Lilac | Aleena | 1 |
| Sage | Yasmeen | 1 |
| Camel | Maryam | 1 |
| Taupe | Rahma | 1 |
| Mocha | Nasreen | 1 |

**Detail** (`pa_detail`) · one value per product · what the ornament is. The value
Hand-beaded (renamed from Beadwork, 2026-08-16) states the how as well as the what: the
beadwork is handworked, confirmed by Najath, and that claim is cleared for publishing.

| Value | Products | Count |
|---|---|---|
| Hand-beaded | Raihana, Nadira, Shazna, Zaheera, Salma, Rahma, Latheefa, Anisa, Yasmeen | 9 |
| Plain | Safiya, Muneera, Hafsa | 3 |
| Embroidery | Warda, Fizza | 2 |
| Lace | Maryam, Nasreen | 2 |
| Appliqué | Suhana, Zarina | 2 |
| Ruffles | Aleena, Naila | 2 |

**Occasion** (`pa_occasion`) · one or two values per product · this is an attribute and
not a category precisely because occasions overlap.

| Value | Products | Count |
|---|---|---|
| Everyday | Warda, Safiya, Rahma, Yasmeen, Latheefa | 5 |
| Work | Hafsa, Muneera, Salma, Rahma | 4 |
| Weddings & functions | Suhana, Nadira, Warda, Aleena, Shazna, Zaheera, Anisa, Maryam, Muneera, Salma, Nasreen, Zarina, Naila | 13 |
| Eid & Ramadan | Suhana, Raihana, Anisa, Fizza | 4 |

**Fabric** (`pa_fabric`) · ⚠ **do not create this attribute yet.** Every fabric word is a
read from a render. Create it only after the production cloth is confirmed, then fill it
from the verified reads in each Listing block below. Filtering on a guess would bake the
guess into URLs.

## Pricing principle (recorded 2026-08-16, numbers wait for G3)

Hand-beaded pieces price above comparable plain pieces; the handwork is the reason and
the copy says so plainly. Dev placeholder tiers in the local store reflect this. Real
numbers come from gate G3.

## Tags (`product_tag`)

- `set` · Aleena, Nasreen. Promote to a category when sets reach four or five.

## Slugs

Product slug = **the given name alone**, lowercase: `suhana`, `raihana`, `nadira` … all
twenty are unique. Not the full product name: nine fabric words are unverified, and if a
name changes after the cloth check, the URL should not. Categories and attributes use the
bare ASCII slugs shown above. No accents anywhere in a slug.

## Master listing table

| Product | Category | Colour | Detail | Occasion | Fabric ⚠ provisional | Slug |
|---|---|---|---|---|---|---|
| Suhana Satin Abaya | Abayas | Black | Appliqué | Eid & Ramadan · Weddings & functions | Satin | `suhana` |
| Raihana Crepe Abaya | Abayas + Hand-beaded | Black | Hand-beaded | Eid & Ramadan | Crepe | `raihana` |
| Nadira Crepe Abaya | Abayas + Hand-beaded | Black | Hand-beaded | Weddings & functions | Crepe | `nadira` |
| Warda Crepe Abaya | Abayas | Rose | Embroidery | Everyday · Weddings & functions | Crepe | `warda` |
| Aleena Abaya Set | Abayas | Lilac | Ruffles | Weddings & functions | Unconfirmed | `aleena` |
| Hafsa Linen Coat Dress | Dresses | Grey | Plain | Work | Linen | `hafsa` |
| Shazna Crepe Abaya | Abayas + Hand-beaded | Black | Hand-beaded | Weddings & functions | Crepe | `shazna` |
| Zaheera Crepe Abaya | Abayas + Hand-beaded | Black | Hand-beaded | Weddings & functions | Crepe | `zaheera` |
| Safiya Abaya | Abayas | Blue | Plain | Everyday | Unconfirmed | `safiya` |
| Anisa Abaya | Abayas + Hand-beaded | Blush | Hand-beaded | Weddings & functions · Eid & Ramadan | Unconfirmed | `anisa` |
| Maryam Satin Abaya | Abayas | Camel | Lace | Weddings & functions | Satin | `maryam` |
| Muneera Satin Abaya | Abayas | Black | Plain | Work · Weddings & functions | Satin | `muneera` |
| Fizza Abaya | Abayas | Grey | Embroidery | Eid & Ramadan | Unconfirmed | `fizza` |
| Salma Crepe Abaya | Abayas + Hand-beaded | Grey | Hand-beaded | Work · Weddings & functions | Crepe | `salma` |
| Rahma Crepe Abaya | Abayas + Hand-beaded | Taupe | Hand-beaded | Everyday · Work | Crepe | `rahma` |
| Nasreen Lace Abaya | Abayas | Mocha | Lace | Weddings & functions | Crepe shell | `nasreen` |
| Yasmeen Crepe Abaya | Abayas + Hand-beaded | Sage | Hand-beaded | Everyday | Crepe | `yasmeen` |
| Zarina Crepe Abaya | Abayas | Black | Appliqué | Weddings & functions | Crepe body | `zarina` |
| Latheefa Crepe Abaya | Abayas + Hand-beaded | Black | Hand-beaded | Everyday | Crepe | `latheefa` |
| Naila Crepe Abaya | Abayas | Black | Ruffles | Weddings & functions | Crepe | `naila` |

---

## Suhana Satin Abaya

**Spec line:** Black satin ⚠ · velvet appliqué in four colours · falls to the ankle

**Description:**
The one loud garment in a quiet collection. Suhana is black satin scattered with hand-cut
velvet leaves in teal, rust, berry and ivory, each motif edged in black beading, over a
plain zip front and wide sleeves with deep turned cuffs. Everything else about the dress
stays out of the way, so the colour reads as festive rather than busy.

Wear it where the occasion asks for something: Eid lunch, a walima, the dinner after a
nikah. It needs no jewellery to carry it.

**Details:**
- Concealed zip front
- Wide sleeves with deep turned cuffs
- Velvet appliqué across the front and sleeves, edged in black beadwork
- Full A-line through the body

**Listing:** Abayas · Colour: Black · Detail: Appliqué · Occasion: Eid & Ramadan,
Weddings & functions · Fabric ⚠: Satin · Slug: `suhana`

**Fabric and care:** Satin shell with velvet and lace appliqué. ⚠ Confirm composition and
care against the production sample before publishing.

---

## Raihana Crepe Abaya

**Spec line:** Black ⚠ crepe · silver beadwork shoulder to hem · falls to the ankle

**Description:**
Two columns of hand-beaded silver leaf sprigs run the full height of the front, one on either
side of the placket, and the same sprigs band each cuff. That is the whole design, and it
is enough: the beads catch light every time you move.

Wear it for Eid prayers and the day of visiting that follows, or for any evening where
black should not mean invisible.

**Details:**
- V neckline with a small ring stud
- Concealed front placket
- Beaded leaf-sprig columns, shoulder to hem, repeated at the cuffs
- Full skirt with a clean fall
- Beadwork set by hand

**Listing:** Abayas, Hand-beaded · Colour: Black · Detail: Hand-beaded · Occasion: Eid & Ramadan ·
Fabric ⚠: Crepe · Slug: `raihana`

**Fabric and care:** Matte black crepe. ⚠ The crepe read comes from drape in the render;
confirm the actual cloth.

---

## Nadira Crepe Abaya

**Spec line:** Black ⚠ crepe · beaded lattice cuffs with teardrop fringe · falls to the ankle

**Description:**
Nadira is plain black to the elbow. Then each sleeve ends in a broad band of hand-set black lattice
beadwork finished with a fringe of beaded teardrops that swing when your hands move.
Everything the dress has to say, it says there.

The body is a clean A-line with a concealed placket, which is why the cuffs work at a
dinner table: nothing competes with them. Reach for it for evening functions and the kind
of family dinner where the photographs outlive the food.

**Details:**
- Round neckline, concealed placket
- Wide sleeves ending in beaded lattice cuffs
- Hanging beaded teardrop fringe at each cuff, set by hand
- Plain body, full skirt

**Listing:** Abayas, Hand-beaded · Colour: Black · Detail: Hand-beaded · Occasion: Weddings & functions ·
Fabric ⚠: Crepe · Slug: `nadira`

**Fabric and care:** Matte black crepe. ⚠ Confirm against the production cloth.

---

## Warda Crepe Abaya

**Spec line:** Dusty rose ⚠ crepe · ivory piping, embroidered sleeves · falls to the ankle

**Description:**
Dusty rose with fine ivory piping traced down the zip and again at the cuff slits, so the
lines of the dress are quietly drawn on. Tonal rope embroidery climbs the outside of each
sleeve from cuff to shoulder.

The zip makes it practical and the colour makes it daytime: campus, engagement visits, an
aqeeqah, the long afternoon functions where you arrive in daylight and leave after the
lights come on.

**Details:**
- Full-length zip behind ivory piping
- V neckline with keyhole
- Tone-on-tone rope embroidery on the outer sleeves
- Slit cuffs finished with piping

**Listing:** Abayas · Colour: Rose · Detail: Embroidery · Occasion: Everyday,
Weddings & functions · Fabric ⚠: Crepe · Slug: `warda`

**Fabric and care:** Matte crepe in dusty rose. ⚠ Confirm cloth and the exact shade under
daylight.

---

## Aleena Abaya Set

**Spec line:** Lilac two-piece · crinkle-shimmer layer over a plain slip · falls to the ankle

**Description:**
A two-piece set in lilac: a floor-length open layer in a crinkled shimmer, worn over a
plain slip in the same colour. The layer's sleeves gather into smocked cuffs that end in a
small ruffle. The slip underneath is deliberately plain, so the shimmer has something calm
to sit on.

This is the set for a walima or a wedding evening. The layer moves; the slip keeps it
grounded.

**Details:**
- Two pieces, worn together
- Open-front layer with a crinkle-shimmer finish
- Smocked cuffs with a ruffle edge
- Plain matching slip

**Listing:** Abayas · Colour: Lilac · Detail: Ruffles · Occasion: Weddings & functions ·
Fabric ⚠: unconfirmed · Tag: `set` · Slug: `aleena`

**Fabric and care:** Shimmer-finish cloth over a plain slip; the render does not settle
the composition. ⚠ Name the fibre only after the sample is in hand.

---

## Hafsa Linen Coat Dress

**Spec line:** Heather linen ⚠ · notched lapels, seamed waist · falls to the ankle

**Description:**
The one tailored piece in the collection: a coat dress with notched lapels, a seamed
waist and a flared skirt, in a heather linen weave. Eight self-covered buttons close the
bodice, and the cuffs finish with buttoned tabs. It reads as workwear because it is built
like workwear.

Interviews, the office, teaching days, any morning that needs you to look decided. It sits
loose through the body and still holds its line at the shoulders.

**Details:**
- Notched lapels over a buttoned bodice
- Eight self-covered buttons ⚠ (count from render)
- Seamed waist, flared skirt
- Buttoned cuff tabs

**Listing:** Dresses · Colour: Grey · Detail: Plain · Occasion: Work · Fabric ⚠: Linen ·
Slug: `hafsa`

**Fabric and care:** Linen; the weave is visible in the close-up. ⚠ Confirm whether it is
pure linen or a blend, then write care accordingly.

---

## Shazna Crepe Abaya

**Spec line:** Black ⚠ crepe · beaded paisley along each sleeve · falls to the ankle

**Description:**
Plain from collar to hem, with dense hand-set pewter beadwork running down the outside of each
sleeve: paisley medallions stacked shoulder to cuff. Face on, it is a quiet black abaya.
The moment you turn or raise a hand, it is not.

Jummah, dinners, evenings where you would rather the detail be discovered than announced.

**Details:**
- Round neckline with a small keyhole
- Pewter paisley columns on the outer sleeves, beaded by hand
- Plain body with a centre seam
- Full A-line skirt

**Listing:** Abayas, Hand-beaded · Colour: Black · Detail: Hand-beaded · Occasion: Weddings & functions ·
Fabric ⚠: Crepe · Slug: `shazna`

**Fabric and care:** Matte black crepe. ⚠ Confirm against the production cloth.

---

## Zaheera Crepe Abaya

**Spec line:** Black ⚠ crepe · gold rosette cuffs, keyhole back · falls to the ankle

**Description:**
Wide bell sleeves carry rows of small gold and bronze rosettes, each beaded around a pearl
centre, so the deep cuffs read as jewellery. The body stays plain, and the back closes
with a keyhole.

An evening abaya for receptions and wedding season. Bangles are optional; the sleeves have
that covered.

**Details:**
- Wide bell sleeves with deep ornamented cuffs
- Rosettes on pearl centres, in rows, beaded by hand
- Keyhole back closure ⚠ (from the naming pass; re-verify on sample)
- Plain body, full skirt

**Listing:** Abayas, Hand-beaded · Colour: Black · Detail: Hand-beaded · Occasion: Weddings & functions ·
Fabric ⚠: Crepe · Slug: `zaheera`

**Fabric and care:** Matte black crepe. ⚠ Confirm against the production cloth.

---

## Safiya Abaya

**Spec line:** Powder blue · one button, nothing else · falls to the ankle

**Description:**
Powder blue, one button at the neck, and nothing else. The placket is concealed, the body
is a clean A-line, and the cloth has a soft, low sheen that keeps the plainness from going
flat.

This is the everyday one: work, errands, the school gate, visits. If you are building a
small rotation, this is the colour that goes with everything you already own.

**Details:**
- Single button at the neckline
- Concealed front placket
- Clean A-line, no ornament
- Soft low-sheen finish

**Listing:** Abayas · Colour: Blue · Detail: Plain · Occasion: Everyday ·
Fabric ⚠: unconfirmed · Slug: `safiya`

**Fabric and care:** Smooth low-sheen cloth; the render does not settle the fibre.
⚠ Name the composition only after the sample is confirmed.

---

## Anisa Abaya

**Spec line:** Blush crinkle shimmer · hand-beaded organza cuffs · falls to the ankle

**Description:**
Blush pink in a crinkled, light-catching finish, plain through the body, with wide sheer
organza cuffs beaded in pastel confetti: pearl, silver, gold, rose. The cuffs are the
celebration. The crinkle keeps everything else soft.

Daytime occasions: an aqeeqah, a birthday lunch, the second day of Eid when you still want
colour but the big dress has had its turn.

**Details:**
- V neckline with a fine pin closure
- Plain crinkle-finish body
- Wide organza cuffs with pastel beadwork, set by hand
- Full skirt

**Listing:** Abayas, Hand-beaded · Colour: Blush · Detail: Hand-beaded · Occasion: Weddings & functions,
Eid & Ramadan · Fabric ⚠: unconfirmed · Slug: `anisa`

**Fabric and care:** Crinkle shimmer cloth with organza cuffs; composition not readable
from the render. ⚠ Confirm before publishing.

---

## Maryam Satin Abaya

**Spec line:** Camel satin ⚠ · scalloped lace cuffs · falls to the ankle

**Description:**
Camel satin with an unmistakable sheen, closed with a single button at the V. The cuffs
are deep scalloped bands of lace, beaded in black, brown and cream, ending in a lace edge
that falls over the hand.

Formal daytime: a nikah, an engagement, the kind of lunch where the photographs are
planned in advance. It pairs with brown or black without arguing.

**Details:**
- V neckline with a single button
- Deep scalloped lace cuff bands, beaded in three colours
- Lace edge falling past the wrist
- Plain satin body, full skirt

**Listing:** Abayas · Colour: Camel · Detail: Lace · Occasion: Weddings & functions ·
Fabric ⚠: Satin · Slug: `maryam`

**Fabric and care:** Satin shell, lace cuffs. ⚠ Confirm composition and how the lace
behaves in a wash before writing care.

---

## Muneera Satin Abaya

**Spec line:** Black satin ⚠ · pintucked, no beadwork · falls to the ankle

**Description:**
Black satin worked in vertical pintucks: panels of them run the length of the front and
are echoed on the back, with stacked pintuck bands at the cuffs. There is no beadwork
anywhere on it. The light moves along the folds instead.

The office black, the formal black, the black for days that ask for restraint. It holds
formality without a single ornament, which is exactly the point.

**Details:**
- V neckline with a small ring closure
- Vertical pintuck panels, front and back ⚠ (back from naming pass)
- Stacked pintuck bands at the cuffs
- No applied ornament

**Listing:** Abayas · Colour: Black · Detail: Plain · Occasion: Work,
Weddings & functions · Fabric ⚠: Satin · Slug: `muneera`

**Fabric and care:** Satin. ⚠ Confirm cloth; pintucks change how a fabric should be
pressed, so care copy waits for the sample.

---

## Fizza Abaya

**Spec line:** Silver-grey shimmer · chevron cuff bands · falls to the ankle

**Description:**
Silver-grey with a fine metallic shimmer running through the weave, a neat V inset at the
collar and a narrow chevron band embroidered at each cuff. It is the least decorated of
the shine pieces, which makes it the easiest one to wear often.

Ramadan evenings, iftar invitations, prayer halls with white light. The shimmer earns its
keep there.

**Details:**
- V inset at the collar
- Narrow embroidered chevron band at each cuff
- Plain body, full skirt
- Metallic-thread shimmer through the cloth

**Listing:** Abayas · Colour: Grey · Detail: Embroidery · Occasion: Eid & Ramadan ·
Fabric ⚠: unconfirmed · Slug: `fizza`

**Fabric and care:** Shimmer weave with a metallic thread; fibre not readable from the
render. ⚠ Confirm composition; metallic threads usually mean gentle care, verify first.

---

## Salma Crepe Abaya

**Spec line:** Warm grey ⚠ crepe · beaded reeds at the cuffs · falls to the ankle

**Description:**
Warm grey, plain through the body, with a spray of hand-beaded reeds rising from each cuff:
fine stems in black, white, gold and lilac, as if drawn on with a thin pen. It sits
exactly between plain and dressed.

Work, then dinner, without going home in between. If Rahma is the quietest of the twenty,
Salma is one step up.

**Details:**
- V neckline with a ring closure
- Reed sprays rising from each cuff, beaded by hand
- Plain body, concealed placket
- Full skirt

**Listing:** Abayas, Hand-beaded · Colour: Grey · Detail: Hand-beaded · Occasion: Work,
Weddings & functions · Fabric ⚠: Crepe · Slug: `salma`

**Fabric and care:** Matte crepe. ⚠ Confirm against the production cloth.

---

## Rahma Crepe Abaya

**Spec line:** Taupe ⚠ crepe · one gold vine at each cuff · falls to the ankle

**Description:**
Taupe, a concealed placket, and one slim hand-beaded gold vine circling each cuff. That is the
entire ornament. It looks the same on the thirtieth wearing as on the first.

The workhorse: office, errands, travel, visits. If you buy one dress from this collection
to live in, make it this one.

**Details:**
- V neckline with a single button
- Concealed front placket
- One gold vine at each cuff, beaded by hand
- Plain body, full skirt

**Listing:** Abayas, Hand-beaded · Colour: Taupe · Detail: Hand-beaded · Occasion: Everyday, Work ·
Fabric ⚠: Crepe · Slug: `rahma`

**Fabric and care:** Matte crepe. ⚠ Confirm against the production cloth.

---

## Nasreen Lace Abaya

**Spec line:** Mocha open layer over cream ⚠ · lace at hem and cuffs · falls to the ankle

**Description:**
An open-front layer in mocha over a cream slip, with blush embroidered lace set into the
lower side panels and flaring from the cuffs, each join edged in a fine line of beads. The
lace sits where the garment moves: at the hem and the wrist.

A wedding-guest set for walimahs and evening functions. The cream underneath keeps the
lace legible; the mocha keeps the whole thing grown up.

**Details:**
- Open-front layer with a clasp at the V
- Blush embroidered lace in the lower side panels
- Lace-flared cuffs with beaded joins
- Cream slip worn underneath

**Listing:** Abayas · Colour: Mocha · Detail: Lace · Occasion: Weddings & functions ·
Fabric ⚠: Crepe shell · Tag: `set` · Slug: `nasreen`

**Fabric and care:** Crepe-read shell with embroidered lace panels. ⚠ Confirm both cloths;
lace panels set the care rules here.

---

## Yasmeen Crepe Abaya

**Spec line:** Sage crinkle ⚠ crepe · tonal beaded flowers · falls to the ankle

**Description:**
Sage green in a crinkle finish, closed with a zip, carrying large flower sprays drawn in
tonal pin-beads: one on each sleeve, one low on the front of the skirt, one on the back.
Because the flowers match the dress, they surface as texture first and pattern second.

Daytime and outdoors: garden functions, campus, long afternoons. The green photographs
kindly in Sri Lankan light.

**Details:**
- V inset neckline, concealed zip
- Pin-bead flower sprays on sleeves, front and back, set by hand ⚠ (back from naming pass)
- Crinkle-finish cloth
- Full skirt

**Listing:** Abayas, Hand-beaded · Colour: Sage · Detail: Hand-beaded · Occasion: Everyday ·
Fabric ⚠: Crepe · Slug: `yasmeen`

**Fabric and care:** Crinkle crepe. ⚠ Confirm against the production cloth.

---

## Zarina Crepe Abaya

**Spec line:** Black ⚠ crepe · velvet leaves on organza, hem and cuffs · falls to the ankle

**Description:**
Black to the knee, and then the hem turns into a ring of champagne velvet leaves set on
sheer organza, with a single matching leaf floating in each sheer cuff. Against the light
the leaves hold their shape and the organza disappears.

An occasion abaya for the evening: receptions, walimahs, a milestone birthday. It does its
best work standing up.

**Details:**
- Soft collar with keyhole and button
- Sheer organza cuff sections, one velvet leaf in each
- Organza hem flounce ringed with velvet leaves
- Plain body above the knee

**Listing:** Abayas · Colour: Black · Detail: Appliqué · Occasion: Weddings & functions ·
Fabric ⚠: Crepe body · Slug: `zarina`

**Fabric and care:** Crepe-read body with organza and velvet trim. ⚠ Confirm all three
cloths; the organza sections will drive the care instructions.

---

## Latheefa Crepe Abaya

**Spec line:** Black ⚠ crepe · a beaded bow at each cuff · falls to the ankle

**Description:**
A plain black abaya finished with one small hand-beaded bow and a fine trailing bead line at
each cuff, edged with a narrow sheer band. You could miss the detail across a room. Up
close, it is the whole story.

A first abaya, a daily abaya, a travel abaya. It asks for nothing and goes everywhere.

**Details:**
- Round neckline with a single button
- Small bow and trailing bead line at each cuff, set by hand
- Narrow sheer band at the cuff edge
- Plain body, full skirt

**Listing:** Abayas, Hand-beaded · Colour: Black · Detail: Hand-beaded · Occasion: Everyday ·
Fabric ⚠: Crepe · Slug: `latheefa`

**Fabric and care:** Matte black crepe. ⚠ Confirm against the production cloth.

---

## Naila Crepe Abaya

**Spec line:** Black ⚠ crepe · ruffle collar, tiered tie sleeves · falls to the ankle

**Description:**
The romantic one. A ruffled collar stands at the neck above a small keyhole, and each
sleeve is drawn in twice on fine ties before releasing into a tiered ruffle at the wrist.
All of it in one black, so the shape does the work.

Dinners, mehndi nights, photographs. The ties let you decide how full the sleeve sits.

**Details:**
- Ruffled stand collar with keyhole and bead button
- Sleeves gathered twice on drawstring ties
- Tiered ruffle at each wrist
- Plain body, full skirt

**Listing:** Abayas · Colour: Black · Detail: Ruffles · Occasion: Weddings & functions ·
Fabric ⚠: Crepe · Slug: `naila`

**Fabric and care:** Matte black crepe. ⚠ Confirm against the production cloth.

---

# Verification list · before any entry is published

Every ⚠ above, gathered in one place:

1. **Fabric words in names and copy.** Satin (Suhana, Maryam, Muneera), linen (Hafsa) are
   confident reads from the renders. The nine "crepe" garments are named from drape alone
   and are the most likely to change. Aleena, Safiya, Anisa and Fizza deliberately name no
   fibre. When the production cloth is known, correct the copy *and the product names*
   where they disagree. Slugs survive either way: they carry no fabric word.
2. **The `pa_fabric` attribute does not exist yet, on purpose.** Create it only after the
   cloth check, from the provisional column in the master table.
3. **Details taken from the naming pass, not re-verifiable from the surviving images:**
   Zaheera's keyhole back, Muneera's back pintuck panels, Yasmeen's back flower spray.
   The `source/` folder that showed backs was deleted on 2026-08-16, so these three are
   checked on the physical sample.
4. **Counts and colours.** Hafsa's eight buttons; every colour name under real daylight
   (screen colour is not cloth colour). The `pa_colour` filter values are broader than the
   copy shades by design; only the copy needs recolouring if a shade shifts.
5. **What is deliberately absent.** No lining, opacity, stretch, weight or care claims
   anywhere above. Add them per garment once the cloth is in hand. The photography rule
   in the brand guidelines (opaque, non-clinging) is a shoot constraint, not a product
   claim; do not copy it into product copy until the sample proves it.
6. **Prices and sizes** are not in this document. They come from the pricing work (gate
   G3) and the size chart, and belong to the PDP template, not the copy.
