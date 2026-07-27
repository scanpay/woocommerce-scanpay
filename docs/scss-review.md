# SCSS-review — `src/` (3 filer, 269 linjer)

Skrevet 2026-07-27 mod `685a78e`, opdateret 2026-07-28 da §1.1, §1.2 og §2 blev udført.
TypeScript-passet (`374afd3`, `9d2f99d`) landede imellem de to datoer uden at røre en
eneste SCSS-linje, så baseline holder; det er kun `util/meta.ts`-henvisningerne i §3.8
der stammer derfra.
Scope: al SCSS under `src/`, læst i sin helhed, samt den PHP og TypeScript der udsender
markup'en reglerne rammer. Linjehenvisninger peger på arbejdstræet efter rettelserne, ikke
på `685a78e` — undtagen i §1.1 og §2.1, hvor numrene med vilje er dem koden havde før.

```
src/admin/assets/css/settings.scss    121
src/admin/assets/css/meta.scss         95
src/public/assets/css/checkout.scss    53
```

## Konventioner

- **Verificeret** — bekræftet mod kilden i dette repo (enqueue-stedet, markup'en,
  `build.sh`, SVG-filerne), med citat.
- **Udledt** — læst ud af koden og ræsonneret igennem mod CSS-specifikationen, men
  afhænger af browseradfærd der ikke kan køres her.
- **Uverificeret** — kræver en kørende shop. Præsenteres aldrig som faktum.

Der er ingen WordPress-installation og ingen browser i dette repo. Ingen påstand herunder
er set rendere. Al layout-vurdering er læsning af regler mod markup.

To ting er ændret undervejs, ikke kun rapporteret — begge står i §2.1 og §2.2.

---

## 1. Fejl i adfærd

**Begge fund i dette afsnit er rettet** — se "Rettet" til sidst i hver. Beskrivelserne
står i datid og beskriver koden som den så ud i `685a78e`.

### 1.1 Klassisk checkout størrelsessatte kun kortikonerne, ikke MobilePay og Apple Pay

`src/public/assets/css/checkout.scss:50-53` i `685a78e`

```scss
.wcsp-cards > img {
	margin: 0 0 0 5px;
	height: 24px;
}
```

Alle tre gateways pakker deres ikoner i den samme `<span class="wcsp-methods">`:

- `gateways/class-wc-gateway-scanpay-card.php:63` — `<span class="wcsp-methods wcsp-cards">`
- `gateways/class-wc-gateway-scanpay-mobilepay.php:30` — `<span class="wcsp-methods">`
- `gateways/class-wc-gateway-scanpay-applepay.php:77` — `<span class="wcsp-methods">`

`.wcsp-methods` har ingen regel nogen steder. Kun kort-gateway'en tilføjer den ekstra
`.wcsp-cards`, og det er den eneste klasse checkout.scss kender. MobilePay- og
Apple Pay-ikonerne får derfor hverken højde eller venstremargin på den klassiske
checkout; de renderer på deres HTML-attributter (92×23 og 45×20) mens kortikonerne
tvinges til 24px.

Det er **nøjagtig den asymmetri filen selv siger blev rettet på Blocks-siden**
(`checkout.scss:14-19`, commit `cf1c32f` "size every Blocks payment icon, not just two").
Rettelsen indførte `.wcsp-icons` som fælles hook for Blocks. `.wcsp-methods` er den
tilsvarende hook på den klassiske side, og den blev aldrig taget i brug.

**Verificeret** i kilden. Den visuelle konsekvens (23px vs. 24px, manglende 5px afstand)
er kosmetisk og **uverificeret** i browser.

**Rettet.** Selektoren er flyttet fra `.wcsp-cards` til `.wcsp-methods`, så den ene regel
dækker alle tre gateways — den klassiske tvilling til `.wcsp-icons`. Værdierne er
uændrede (24px, 5px), så kortikonerne renderer præcis som før; MobilePay og Apple Pay
kommer nu med. `.wcsp-cards` bliver stående i markup'en som kort-specifik hook for
temaer, men har ikke længere nogen regel i dette repo.

Bemærk konsekvensen for de to andre gateways: deres `<img>` bærer `width="92" height="23"`
og `width="45" height="20"` fra PHP'en, og de attributter er præsentationshints, som
enhver author-regel slår. Med stylesheettet slået til renderer de derfor nu 24px høje
(96px og 54px brede) i stedet for 23px og 20px. Det er hensigten — det er dét ensartet
ikonhøjde betyder — men det er en synlig ændring på klassisk checkout og **uverificeret**
i browser.

### 1.2 CSS'en var den eneste størrelsesbegrænsning på SVG'erne — og den kan slås fra

Det her er det alvorligste fund, fordi det er en indstilling købmanden kan trykke på.

> **Korrektion.** Første udgave af dette afsnit skrev, at fem af elleve SVG'er havde
> `width`/`height` og seks manglede dem. Det var forkert: målingen havde grebet
> `width`/`height` fra baggrunds-`<rect>`'en inde i filen i stedet for fra `<svg>`-roden.
> **Ingen af de elleve** havde dimensioner på roden. Problemet var altså ikke en blanding
> af små og kæmpestore ikoner, men at *hvert eneste* ikon blæste op. Konklusionen og
> fixet er de samme; omfanget er dobbelt så stort.

`stylesheet`-feltet (`admin/settings/fields/scanpay.php:68`) styrer om checkout.css
overhovedet enqueues:

```php
if ( 'yes' === ( $this->settings['stylesheet'] ?? 'yes' ) ) {
	add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_styles' ] );
}
```
`gateways/class-wc-gateway-scanpay-card.php:34-36`

Slås den fra, forsvinder `.wcsp-cards > img { height: 24px }` og
`.wcsp-icons > img { height: 22px !important }`. Spørgsmålet er så hvad ikonerne falder
tilbage på — og svaret var: ingenting.

Ingen af `<img>`-taggene bærer `width`/`height` på kortikonerne. Kort-gateway'en:

```php
$html .= '<img src="' . esc_url( … ) . '" class="wcsp-' . esc_attr( $card ) . '" alt="…" title="…">';
```
`gateways/class-wc-gateway-scanpay-card.php:66-67`

og Blocks-siden heller ikke (`public/assets/js/checkout.ts:44-50` sætter `src`,
`className` og `alt`). Kun MobilePay og Apple Pay bærer attributter, og kun på den
klassiske checkout.

Og ingen af SVG'erne havde dimensioner på `<svg>`-roden — alle elleve havde kun
`viewBox`. Et `<img>` uden angivet størrelse, med et billede der har intrinsic aspect
ratio men ingen intrinsic størrelse, falder tilbage på default sizing algorithm: den
største kasse med billedets ratio der hverken er bredere eller højere end 300×150. For
visa (38/24) bliver det ~237×150 px; for mobilepay (88/22) 300×75 px.

At slå stylesheettet fra gav altså ikke "utema'de ikoner" men en række betalingsikoner
i halvanden hundrede pixels højde — hvert eneste af dem, på både klassisk checkout og
Blocks.

**Udledt** — adfærden følger af CSS-sizing-reglerne for erstattede elementer og er
konsistent på tværs af browsere, men den er ikke set her.

**Rettet i SVG-filerne, ikke i markup'en.** Alle elleve har nu `width`/`height` på roden.
Det er den robuste af de to veje, og grunden er en detalje der er værd at kende: HTML'ens
`width`/`height`-attributter er *præsentationshints*, som taber for enhver author-regel —
også for et temas `img { height: auto }`, som er udbredt i WordPress-temaer. Intrinsic
dimensioner i selve filen er dét `height: auto` slår op i, så de holder også dér.

| Fil | viewBox | ny `width`×`height` |
| :--- | :--- | :--- |
| amex, maestro | 32×20 | 32×20 |
| dankort | 38.5×24 | 38.5×24 |
| forbrugsforeningen | 36×24 | 36×24 |
| mastercard, visa | 38×24 | 38×24 |
| diners, unionpay | 70×48 | 35×24 |
| jcb | 69×48 | 34.5×24 |
| mobilepay | 88×22 | 92×23 |
| applepay | 27×12 | 45×20 |

Tallene er valgt efter to regler, begge maskinelt kontrolleret i det script der udførte
ændringen: **forholdet er nøjagtigt viewBoxens** (ellers ville `object-fit: fill`
forvrænge mærket), og **højden lander i 20-24px-båndet**, så en shop med stylesheettet
slået fra får en jævn ikonrække frem for diners og JCB i dobbelt størrelse. MobilePay og
Apple Pay har fået præcis de tal PHP'en allerede erklærer på deres `<img>`, så de to
kilder ikke kan være uenige.

Med stylesheettet slået til ændrer intet sig: `.wcsp-methods > img` og `.wcsp-icons > img`
er author-regler og slår stadig de nye attributter.

Layout shift er *ikke* løst — det kræver `width`/`height` på `<img>`-taggene, og for
kortikonerne ville det betyde en dimensionstabel per kort i PHP'en, altså en andenliste
der kan drive fra `fields/scanpay.php`. Ikonerne er same-origin og et par hundrede bytes,
så det er ikke pengene værd. Uændret fra før.

### 1.3 Metaboks-rækker kan flyde over i sidebaren

`src/admin/assets/css/meta.scss:66-73`

```scss
.wcsp-meta-li {
	display: flex;
	padding: 0.15em 0;
}

.wcsp-meta-li-title {
	flex: 1;
}
```

Boksen sidder i `side`-konteksten (`admin/orders.php:178`, `admin/subscriptions.php:91`),
som er ~250px bred. Rækkerne er flex uden `min-width: 0` og uden ombrydningsregel, og
flex-items krymper som udgangspunkt ikke under deres min-content-bredde — så et par
label/værdi hvis samlede min-content overstiger boksen skubber ud over kanten frem for
at ombryde.

Værdierne på ordreskærmen er beløb plus valuta og er korte. Abonnementsskærmen er den
udsatte: `subs.ts:62-68` skriver "Payment ID", "Payment date", "Method" og "Card expiry",
og både labels og `fmtMethod()`-værdien vokser på oversatte sprog — dansk "Betalingsdato"
og "Kortudløbsdato" er mærkbart længere end originalen.

**Udledt**, lav sandsynlighed, rent kosmetisk. To deklarationer lukker det:

```scss
.wcsp-meta-li-value {
	min-width: 0;
	overflow-wrap: anywhere;
}
```

(`.wcsp-meta-li-value` sættes allerede i `util/meta.ts:63` og har i dag ingen regel.)

---

## 2. Ændret i denne omgang

### 2.1 `lint:style` validerede kun halvdelen af filerne

Scriptet pegede på én mappe:

```json
"lint:style": "wp-scripts lint-style src/public/assets/css/"
```

`src/admin/assets/css/` — to tredjedele af koden — er aldrig blevet linted. Det er ikke
en teoretisk mangel: da jeg kørte stylelint på hele `src/`, fandt den **15 fejl, hvoraf
alle 15 lå i admin-filerne**. `checkout.scss`, den eneste fil scriptet dækkede, var ren.

Det vejer tungere her end i de fleste repos, fordi `.claude/CLAUDE.md` gør linterne til
hele valideringen: "The linters are the validation. No test suite runs here."

Scriptet dækker nu begge træer, og både `.css` og `.scss`, så en fil der lander uden for
de to nuværende mapper også bliver fanget:

```json
"lint:style": "wp-scripts lint-style \"src/**/*.{css,scss}\""
```

Alle 15 fejl er rettet (linjenumrene herunder er dem fejlene havde i `685a78e`):

- `meta.scss:10` — `#wcsp-meta-box>.inside` manglede mellemrum om `>`; resten af filerne
  skriver `> *`, `> img`, `> h4`.
- Fire kommentarlinjer over 80 tegn i `meta.scss`, fire i `settings.scss` — ombrudt.
  Kommentarindholdet er uændret på nær ét sted, se §2.2.
- `settings.scss:66` — `margin-left: .5em` manglede foranstillet nul (alle andre tal i
  de tre filer har det: `0.95em`, `0.15em`, `0.3em`).
- `settings.scss:86` — `color: rgb(75, 6, 6)` manglede afsluttende semikolon og var den
  eneste `rgb()`-notation i repoet. Nu `#4b0606;`, samme værdi.
- Fire `selector-id-pattern`-fejl på `tr:has(#woocommerce_scanpay_*)`. De er ikke vores
  at rette: WooCommerce bygger id'et ved at konkatenere option-nøglen
  (`WC_Settings_API::get_field_key()`), så underscoren er deres. Gruppen er pakket ind i
  en `stylelint-disable selector-id-pattern -- <begrundelse>` med `stylelint-enable`
  efter, i samme form som husets `phpcs:ignore -- <reason>`. Reglen er stadig aktiv for
  vores egne id'er (`#wcsp-meta`, `#wcsp-meta-box`), som allerede overholder den.

`pnpm lint:style` er grøn på alle tre filer. `sass --style compressed` kompilerer
uændret, og disable-kommentarerne strippes ud af den byggede CSS (verificeret: 0
forekomster i output).

### 2.2 En kommentar var drevet fra koden

`meta.scss` henviste til `types/meta.ts`. Filen hedder `util/meta.ts` — den blev flyttet
i det igangværende arbejde (`git status` viser omdøbningen). Rettet, da guiden regner en
drevet kommentar for en fejlende test: "a comment that has drifted is a broken test."

Jeg tilføjede samtidig én linje til `settings.scss`' kommentarblok om, at `:has()`
degraderer til WooCommerces egen rækkeafstand og aldrig til et brudt layout — se §3.2 for
hvorfor det er den rigtige konklusion at pinne.

---

## 3. Vedligeholdelse og konsistens

Intet herunder er en fejl. Det er steder hvor filerne modsiger deres egne konventioner
eller hvor en fremtidig ændring har let ved at gå galt.

### 3.1 `.wc-admin-header` er den eneste uprefixede selector

`src/admin/assets/css/settings.scss:6-8`

```scss
.wc-admin-header {
	margin-top: 1.5em;
}
```

Filens egen header siger `prefix with wcsp-set-`. Det her er WooCommerces klasse, ikke
vores, og reglen har ingen kommentar — den kom ind med `234a67f` "Improve admin options".

Skaden er begrænset: `settings.css` enqueues kun på pluginets egne skærme
(`admin/settings.php:31-38`, gated på `wc_scanpay_is_settings_screen()`), så vi flytter
ikke WooCommerces header andre steder. Men det er en regel der er afhængig af et
markup-navn WooCommerce ejer og kan omdøbe uden varsel, og der er intet der siger hvad
den kompenserer for. Enten en kommentar, eller en kontrol af om elementet stadig findes
på skærmen. **Uverificeret** — kan ikke afgøres uden en kørende WC-installation.

### 3.2 `:has()` er en hård afhængighed der ikke står i `docs/requirements.md`

Fire regler i `settings.scss` bruger `tr:has(#…)`. `requirements.md` har en
"Browser (frontend and admin assets)"-sektion, men den dækker udelukkende JavaScript-API'er
— der er ingen CSS-baseline nogen steder i dokumentet.

Konsekvensen af manglende understøttelse er ren afstand mellem indstillingsrækker, så
det er ikke et argument for at fjerne `:has()`; det er et argument for at skrive ned, at
kravet er bevidst og hvad det koster hvis det ikke er opfyldt. Jeg har pinnet
degraderingen i selve filen (§2.2), men rækken hører hjemme i tabellen i
`requirements.md` sammen med de øvrige floors.

### 3.3 Advarselspaletten er duplikeret ord for ord mellem to filer

`meta.scss:47-51` og `settings.scss:109-116` bærer den samme palette:

| | meta.scss `.wcsp-meta-alert-warning` | settings.scss `.wcsp-set-alert` |
| :--- | :--- | :--- |
| `color` | `#22240a` | `#22240a` |
| `background` | `#fff9e6` | `#fff9e6` |
| `border-color` | `#c1b7ab` | `#c1b7ab` |

Tre identiske hex-værdier i to filer der kompileres uafhængigt, uden at nogen af dem
nævner den anden. Justeres den ene advarselsfarve, driver de fra hinanden i tavshed.

`build.sh` kompilerer mappevis, og Sass udelader `_`-prefixede filer fra output, så en
`_palette.scss` med de seks-otte farver ville virke uden at ændre en linje i `build.sh`.
Det er den ene ting i disse filer hvor SCSS'ens features rent faktisk ville tjene noget —
i dag bruger alle tre filer kun nesting, og kunne have været `.css`.

Alternativet, hvis en fjerde fil føles som for meget for tre farver, er en krydsreference
i begge kommentarblokke. Vælg én; nu er der ingen af delene.

### 3.4 `!important` uden begrundelse

To steder, begge uden kommentar:

- `meta.scss:63` — `list-style: none !important` (skal formentlig slå WP-admins
  `ul { list-style: disc }` i metaboks-konteksten).
- `checkout.scss:26-27` — `height: 22px !important; max-height: 22px !important`.

Husreglen for kommentarer er "the failure the line prevents", og et `!important` er
per definition en linje der forhindrer noget bestemt. Begge steder er læseren i dag
nødt til at gætte hvis regel der bliver slået.

`max-height` ved siden af `height` er desuden redundant, medmindre den er der for at
overleve en tredjeparts `height: … !important` med senere kildeorden — hvilket i så fald
er præcis den slags oplysning kommentaren skal bære.

### 3.5 `.wcsp-nav*` genimplementerer WordPress' egne `.nav-tab`

`settings.scss:70-94` bygger et fanebladslook fra bunden — ramme, baggrund, `font-size:
14px`, `font-weight: 600`, `margin-bottom: -1px` — inklusive `line-height: 1.71428571`,
som er core's egen 24/14 skrevet ud som decimaltal. Det er WordPress' `.nav-tab` /
`.nav-tab-active` genskabt med et andet klassepræfiks.

Prisen er, at farverne er hardcodede (`#dcdcde`, `#50575e`, `#116995`, `#f0f0f1`) og
derfor ikke følger brugerens admin-farveskema, som core's egne faner gør. Om det er
bevidst kan ikke afgøres fra koden — der er ingen kommentar, og WooCommerces
indstillingsskærme har deres eget faneudseende, hvilket kunne være grunden. Værd at
afgøre og skrive ned; ikke værd at ændre blindt.

### 3.6 Ingen `:hover` eller `:focus-visible` på de to link-typer

`.wcsp-nav-tab` (`admin-options.php:111`) og `.wcsp-nav-logs` (`:120`) er begge `<a>`.
Ingen af dem har hover- eller fokus-styling, og begge sætter `color`, som overskriver
WP-admins linkfarver — så de mister også de tilstande de ellers ville have arvet.
Browserens egen fokusring tegnes stadig, så det er ikke en tastatur-fælde; det er
manglende affordance på et element der ser ud som et faneblad.

### 3.7 Kun fysiske properties — ingen RTL-vej

Samtlige retningsafhængige deklarationer i de tre filer er fysiske: `margin-right: 1em`
(`checkout.scss:11`), `margin-left: 0.5em` (`settings.scss:78`), `margin: 0 0 0 5px`
(`checkout.scss:51`), `margin: 8px 0 0 18px` og `margin: 8px 2px 0 auto`
(`settings.scss:97,103`), `border-left-width: 4px` (`settings.scss:113`).

`build.sh` genererer ingen `-rtl.css`, og der er ingen `wp_style_add_data( …, 'rtl' )`
ved nogen af de fire enqueues. På en RTL-shop peger alle disse afstande derfor forkert.

Logiske properties (`margin-inline-start`, `border-inline-start-width`,
`padding-inline`) løser det uden byggetrin og har været bredt understøttet siden 2019-21
— altså før den `:has()`-baseline filerne allerede forudsætter. Det er den billige vej,
hvis RTL overhovedet er i scope. Om det er, kan jeg ikke afgøre herfra: der er ingen
RTL-oversættelse i `src/languages/`.

### 3.8 Klasser i markup uden regler

Følgende udsendes af PHP eller TS og har ingen CSS nogen steder. De fleste er
JS-håndtag, hvilket er helt legitimt — men listen er værd at have, fordi tre af dem ikke
er det:

| Klasse | Sted | Vurdering |
| :--- | :--- | :--- |
| ~~`wcsp-methods`~~ | alle tre gateways | Var en fejl, §1.1 — har nu reglen |
| `wcsp-cards` | kort-gateway'en | Kort-specifik tema-hook, ingen regel efter §1.1 |
| `wcsp-setup-url` | `admin-options.php:60` | **Se nedenfor** |
| `scanpay-outdated` | `util/meta.ts:83` | **Se nedenfor** |
| `wcsp-meta-li-value` | `util/meta.ts:63` | Kandidat til §1.3 |
| `wcsp-notice` | `admin/settings.php:55` | Håndtag, ok |
| `wcsp-set-api-info`, `wcsp-set-version` | `settings.ts:168,208` | Håndtag, ok |
| `wcsp-set-apikey`, `wcsp-set-reset`, `wcsp-set-reset-msg` | base-gateway'en | Håndtag, ok |
| `wcsp-capture`, `wcsp-meta-head`, `wcsp-meta-foot` | `order.ts` | Håndtag, ok |
| `wcsp-icon`, `wcsp-icons-<name>`, `wcsp-nav-<id>` | TS/PHP | Præfiks-hooks, ok |
| `wcsp-applepay`, `wcsp-mobilepay`, `wcsp-<card>` | gateways | Se §1.1 |

**`wcsp-setup-url`** har en klasse *og* en inline style side om side:

```php
'<br><input type="text" class="wcsp-setup-url" style="width:100%;max-width:34em;margin:6px 0;"' .
```

Klassen er der; deklarationerne ligger bare det forkerte sted. De tre linjer hører i
`settings.scss` under den klasse der allerede står i markup'en. (Bemærk at inline styles
også er dem et tema ikke kan overskrive uden `!important`.)

**`scanpay-outdated`** er uprefixet — resten af repoet bruger `wcsp-`. Værre er, at den
står inde i en oversat streng (`util/meta.ts:83`, og dermed i `.pot` og i den danske
`.po`), så et navneskift ugyldiggør oversættelsen for en ren kosmetisk gevinst. `<b>`
renderer fed uden regel, så der er ingen visuel konsekvens. Min anbefaling er at lade
den ligge og notere hvorfor, frem for at brænde en oversættelsesrunde på den.

### 3.9 Blocks og klassisk checkout er ikke enige om ikonhøjden

`.wcsp-icons > img` sætter 22px (`checkout.scss:32`), `.wcsp-methods > img` sætter 24px
(`:63`). Samme SVG'er, samme shop, to højder afhængigt af hvilken checkout kunden rammer.
Det er sandsynligvis en utilsigtet konsekvens af, at Blocks-reglen blev skrevet i
`cf1c32f` og den klassiske regel er ældre. Vælg ét tal.

Rettelsen af §1.1 gjorde det ikke værre — den ændrede kun hvilke elementer 24px-reglen
rammer, ikke tallet — men den gjorde uenigheden mere synlig, fordi de to regler nu er
åbenlyse tvillinger. Bemærk at et fælles tal ville skulle vælges mellem 22 og 24, og at
`.wcsp-icon-applepay { margin-top: 3px }` kun findes på Blocks-siden; en sammenlægning
bør tage den med.

### 3.10 `.wcsp-set-scanpay`-præfikset er redundant på apikey-reglen

`settings.scss:31-33`:

```scss
.wcsp-set-scanpay .wcsp-set-row-apikey > * {
	padding-top: 5px;
}
```

`apikey` findes kun i `fields/scanpay.php` — MobilePay- og Apple Pay-gateway'ene har
ingen nøglefelt, så `.wcsp-set-row-apikey` kan kun optræde inde i `.wcsp-set-scanpay`.
Præfikset koster ikke noget og dokumenterer hensigten, så det er ikke et argument for at
fjerne det; det er værd at vide, hvis nogen senere flytter nøglefeltet.

---

## 4. Kontrolleret og fundet i orden

Så det ikke gennemgås igen ved næste review.

- **`.wcsp-meta-alert-info` gentager base-reglens palette.** Ser ud som død kode. Det er
  det ikke — `meta.scss:22-24` forklarer hvorfor den er pinnet frem for at falde igennem,
  og det er en bevidst beslutning fra `a75e83b`. Rør den ikke.
- **Gateway-scopingen af rækkepadding.** `.wcsp-set-scanpay` matcher kun kort-gateway'en
  (`admin-options.php:138` bygger klassen af `$gateway->id`, og `scanpay_mobilepay` giver
  et andet klassenavn). Det er korrekt: `stylesheet`, `wc_complete_virtual` og
  `wcs_complete_*` findes kun i `fields/scanpay.php`, så de øvrige faner har ikke
  rækkerne. **Verificeret.**
- **`checkout.css` når også MobilePay-only shops.** Enqueue-hooket sidder i
  kort-gateway'ens konstruktør, hvilket ser ud som om CSS'en forsvinder når kort er slået
  fra. Det gør den ikke: WooCommerce instantierer hver registreret gateway-klasse
  uafhængigt af `enabled`, så konstruktøren — og dermed `add_action` — kører altid. At
  indstillingen bor på kortfanen og styrer alle tre gateways' ikoner er i tråd med at
  kortindstillingerne er den primære/delte option. **Udledt** (WC's egen
  gateway-instantiering kan ikke køres her).
- **`#wcsp-meta { min-height: 80px }`.** `#wcsp-meta` udsendes tom på ordreskærmen
  (`orders.php:147`) og fyldes af JS. Min-højden holder boksen stabil indtil AJAX'en
  lander. Rimeligt, og ikke død kode.
- **Enqueue-tidspunktet for `meta.css`.** Sker i `add_meta_boxes`-hooket, ikke i
  render-callbacket, med en kommentar der forklarer at det er for at ramme `<head>` frem
  for `print_late_styles()`. Korrekt begrundet.
- **Kommentarstilen i de tre filer** følger husreglen: `/* … */` med prosa om *hvorfor*,
  ikke gentagelse af næste linje. `meta.scss:15-30` og `settings.scss:10-29` er begge
  eksempler på det guiden beder om.

---

## 5. Prioritet

| # | Fund | Alvor | Omfang |
| :--- | :--- | :--- | :--- |
| ~~1.2~~ | SVG'er uden fallback-størrelse når stylesheet slås fra | Høj | **Rettet** — 11 SVG-rødder |
| ~~1.1~~ | `.wcsp-methods` ubrugt — MobilePay/Apple Pay ustylede klassisk | Middel | **Rettet** — 1 selektor |
| 3.8 | `wcsp-setup-url` styles inline i PHP | Lav | Flyt 3 deklarationer |
| 3.3 | Duplikeret advarselspalette | Lav | Partial eller kommentar |
| 3.4 | `!important` uden begrundelse | Lav | 2 kommentarer |
| 3.9 | 22px vs. 24px | Lav | 1 tal |
| 1.3 | Metaboks-overløb | Lav | 2 deklarationer |
| 3.7 | Ingen RTL-vej | Afhænger af scope | 6 properties |
| 3.2 | CSS-baseline mangler i `requirements.md` | Dokumentation | 1 række |
| 3.1 | `.wc-admin-header` uden begrundelse | Dokumentation | 1 kommentar |
| 3.5 | `.wcsp-nav*` duplikerer core | Beslutning | — |

§1.1, §1.2 og hele §2 er udført. Alt øvrigt står urørt.

Ingen af de udførte rettelser er set i en browser — der er ingen shop i dette repo. Det
maskinelle belæg er: `pnpm lint:style`, `lint:js`, `lint:pkg-json`, `exec tsc` og `phpcs`
er alle grønne, `./build.sh` kører igennem, og forholdet mellem hver ny SVG-`width`/
`height` og filens `viewBox` er kontrolleret programmatisk til under 1e-9.
