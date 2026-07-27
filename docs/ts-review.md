# TypeScript-review — `src/` (10 filer, 1124 linjer)

Skrevet 2026-07-27 mod `685a78e`. Scope: al TypeScript under `src/`, læst i sin helhed.
Ingen kode er ændret.

```
src/admin/assets/js/order.ts              207
src/admin/assets/js/settings.ts           208
src/admin/assets/js/subs.ts               108
src/admin/assets/js/types/meta.ts          94
src/admin/assets/js/types/order.d.ts       40
src/admin/assets/js/util/compat.ts        104
src/admin/assets/js/util/i18n.ts           11
src/public/assets/js/applepay.ts          111
src/public/assets/js/checkout.ts          160
src/public/assets/js/types/checkout.d.ts   81
```

## Konventioner

- **Verificeret** — bekræftet mod kilden i dette repo (PHP-endpointet, enqueue-stedet,
  `build.sh`), med citat.
- **Udledt** — læst ud af koden og ræsonneret igennem, men afhænger af runtime-adfærd
  der ikke kan køres her.
- **Uverificeret** — kræver en kørende shop. Præsenteres aldrig som faktum.

`pnpm exec tsc` og `pnpm lint:js` er begge rene på `685a78e`. Det er hele den maskinelle
validering der findes for TypeScript her, så alt nedenfor er semantik som ingen linter
ser. Der er ingen WordPress-installation i dette repo; ingen påstand herunder er afprøvet
i en browser.

En del af arbejdet gik med at kontrollere TS'ens antagelser mod PHP-siden — wire-formatet
på `?x=`-endpointene, hvilke `data-*`-attributter der faktisk udsendes, og hvilke
script-dependencies der er erklæret. De kontroller der bestod, står i §4, så de ikke
bliver gennemgået igen ved næste review.

---

## 1. Fejl i adfærd

### 1.1 `fmtDate()` viser betalingsdatoen i UTC, ikke i shoppens tidszone

`src/admin/assets/js/subs.ts:43-46`

```ts
function fmtDate(unixSecs: string): string {
	const n = parseInt(unixSecs, 10);
	return n > 0 ? new Date(n * 1000).toISOString().split('T')[0] : '—';
}
```

`toISOString()` er per definition UTC. For en dansk shop (UTC+1 om vinteren, UTC+2 om
sommeren) betyder det, at en betaling foretaget mellem midnat og kl. 01:59 lokal
sommertid falder på den foregående UTC-dag og vises med gårsdagens dato. Resten af
abonnementsskærmen — WooCommerces egne datoer — renderes i site-tid via
`wc_format_datetime()`, så de to står side om side og er uenige.

**Udledt.** Vinduet er smalt (1-2 timer i døgnet) og betalingstrafikken der er lav, men
det er en forkert dato på en skærm, hvor købmanden bruger den til at genkende en
betaling.

`toLocaleDateString()` er ikke rettelsen: den bruger *browserens* tidszone, ikke sitets,
og en købmand der administrerer en dansk shop fra udlandet ville få et tredje svar.
`subscriptions.php:62-69` udsender allerede `data-*`-attributter, så enten en
`data-utc-offset`, eller en færdigformateret streng fra PHP, holder formateringen ét sted.

Samme rod i `fmtExp()` (`subs.ts:48-53`), der læser `getUTCMonth()`/`getUTCFullYear()`.
Dér er konsekvensen forsvindende — et kort skal udløbe præcis på et månedsskifte inden
for det samme 1-2 timers vindue — men det er den samme antagelse.

### 1.2 `td.innerHTML += html` genparser hele `<td>`'et, inklusive API-nøglefeltet

`src/admin/assets/js/settings.ts:166`

```ts
const td = field.closest('td');
if (td) td.innerHTML += html;
```

`innerHTML +=` er en serialisering efterfulgt af en fuld genparsning. Hver eneste
child-node i `<td>`'et destrueres og bygges på ny — herunder
`<input id="woocommerce_scanpay_apikey">`, som er selve feltet købmanden skal indsætte
sin API-nøgle i (`abstract-wc-gateway-scanpay-base.php:254-258`). Alt hvad WooCommerces
egen settings-JS har bundet til det felt (bl.a. dens "changes you made may not be saved"-vagt)
ryger med, og en værdi der allerede måtte stå i feltet går tabt.

Scriptet indlæses med `'strategy' => 'defer'` (`settings.php:34`), så i praksis kører det
før nogen når at taste — men det er timing, ikke et argument.

Filen bruger allerede det rigtige kald ét sted (`settings.ts:37`,
`div.insertAdjacentHTML('beforeend', msg)`). **Rettelse:**
`td.insertAdjacentHTML('beforeend', html)`.

**Verificeret** for så vidt angår, hvad `<td>`'et indeholder; effekten på WC's egne
listeners er **udledt**.

### 1.3 `renderFoot()` validerer `voided` løsere end de to andre beløb

`src/admin/assets/js/order.ts:94-98`

```ts
const capturable =
	isMoney(meta.authorized) &&
	isMoney(meta.captured) &&
	isZeroMoney(meta.voided) &&
	fmtMoney(meta.captured, decimals) !== fmtMoney(meta.authorized, decimals);
```

`authorized` og `captured` går gennem `isMoney()`; `voided` gør ikke. Og `isZeroMoney()`
er `!/[1-9]/.test(raw)` — den svarer `true` på `''`, på `'abc'`, på alt uden et ciffer i
1-9. Et misdannet `voided` bliver altså læst som "ikke annulleret", og Capture-knappen
tilbydes på en ordre, hvis annulleringstilstand reelt er ukendt.

`renderFigures()` fanger det og viser en fejl (`order.ts:68-78`), men den `continue`'r
bare over rækken, og `renderFoot()` kører uafhængigt bagefter. Det er den permissive
retning på præcis den slags data, som `fail-loud-on-backend-protocol-violations` siger
skal fejle højlydt.

Sprængradius er begrænset: `WC_Scanpay_Capture` er autoriteten og no-op'er, hvis der
intet er at hæve (`wp-ajax-wc-scanpay-capture.php:51`). Skaden er en vildledende knap,
ikke en forkert hævning.

**Rettelse:** tilføj `isMoney(meta.voided) &&` til konjunktionen.

### 1.4 Kommentaren om `%s` i abonnementsbetingelserne passer ikke til koden

`src/public/assets/js/checkout.ts:100-102` og `:137`

```ts
// A translation that drops %s degrades to plain text with no link, never to a broken one.
const [before, after] = terms.label.split('%s');
```

Hvis en oversættelse taber `%s`, returnerer `split` et array med ét element: `before`
bliver hele sætningen, `after` bliver `undefined`. Men `<a>`-elementet på linje 137
oprettes **ubetinget**:

```ts
before,
createElement('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, link),
after
```

Resultatet er ikke "plain text with no link" — det er sætningen med linkteksten klistret
på enden: *"Jeg accepterer betingelserne.subscription terms"*. Linket er der, det står
bare det forkerte sted.

Kilden er verificeret delt med den klassiske renderer: begge bruger msgid'et
`'I accept the %s.'` (`wcs-scanpay-checkout-terms.php:23` og
`class-wc-scanpay-blocks-support.php:75`), så en oversættelse der taber `%s` rammer også
klassisk checkout — dér gennem `sprintf()`, som bare dropper argumentet og *faktisk*
giver ren tekst uden link. De to checkouts degraderer altså forskelligt.

Guiden er eksplicit på dette punkt: *"a comment that has drifted is a broken test."*
Enten skal `<a>` gøres betinget af `after !== undefined`, eller også skal kommentaren
rette sig efter koden. **Verificeret** i begge renderers.

### 1.5 `refresh()` kan ikke skelne "synkroniseringen er ikke landet" fra "pollet fejler"

`src/admin/assets/js/order.ts:176-195`

`catch {}` er tom med kommentaren `/* transient; keep polling */`, og `res.ok === false`
falder igennem til samme sted. En 403 fra et roteret secret, en netværksfejl og en række
der endnu ikke er opdateret, giver alle tre det samme udfald: tre forsøg, `false`, og
beskeden *"Capture requested — figures will refresh on the next sync."*

Beskeden er ikke forkert — hævningen *lykkedes*, det er kun opdateringen der mangler —
men et vedvarende brudt poll-endpoint er usynligt for købmanden. Efter et reset er det
netop scenariet: secret'et er roteret (`wp-ajax-wc-scanpay-reset.php:204-207`), og en
faneblad der stadig står åbent med det gamle payload i `window.ScanpayOrderData` vil
403'e i tavshed.

**Udledt.** Lav prioritet — selvhelbredelsen sker ved næste sideload.

### 1.6 `getLastSync()`s `force`-parameter har ingen kalder

`src/admin/assets/js/util/compat.ts:14`

```ts
export function getLastSync(secret: string, endpoint: string, force = false): Promise<number>
```

Eneste kalder er `settings.ts:46`, som ikke sender tredje argument. Parameteren er død.

Interessant er *hvorfor* den ser ud til at have været tiltænkt noget: cachen
(`localStorage['scanpay_lastPing']`, 300 sekunders levetid) ryddes aldrig efter et reset.
`onReset()` genindlæser siden ved succes (`settings.ts:135`), og den genindlæste side kan
derefter i op til fem minutter påstå *"Synchronized: N seconds ago"* om en shop, hvis
tabeller lige er droppet. Det er nøjagtig det tilfælde, `force` ligner den var skrevet til.

**Rettelse, mindst indgribende:** `localStorage.removeItem('scanpay_lastPing')` i
`postReset()`s succes-gren, og fjern så `force`. **Verificeret** at cachen ikke ryddes
noget sted (`grep scanpay_lastPing` giver kun de to steder i `compat.ts`).

### 1.7 `fmtMoney()` ignorerer alle WooCommerces valutaformat-indstillinger på nær decimalantallet

`src/admin/assets/js/order.ts:36-45`

Funktionen normaliserer decimaldelen og sætter valutakoden bagefter. En dansk shop får
`1234.56 DKK` i Scanpay-boksen, mens WooCommerces egne ordretotaler i samme skærmbillede
står som `1.234,56 kr.`

Dokblokken er præcis om *hvorfor* der ikke regnes (penge er decimalstrenge, ingen
float-aritmetik — helt korrekt og på linje med `library/math.php`), men formatering er
ikke aritmetik. `wc_decimals` sendes allerede med fra `orders.php:118`;
`wc_get_price_decimal_separator()`, `wc_get_price_thousand_separator()` og
symbol/position er tre felter mere i det samme `$props`-array.

**Udledt.** Kosmetisk, men det er købmandens egne penge på købmandens egen skærm, ved
siden af tal der er formateret rigtigt.

---

## 2. Struktur og vedligeholdelse

### 2.1 `types/meta.ts` er et runtime-modul i en `types/`-mappe

`src/admin/assets/js/types/meta.ts` eksporterer fire funktioner — `showError`,
`showWarning`, `buildTable`, `pluginVersionCheck` — og ikke én type. Naboen
`types/order.d.ts` er derimod ægte deklarationer.

`util/` ligger ved siden af og indeholder allerede nøjagtig denne slags: `compat.ts`,
`i18n.ts`. Både `order.ts:9` og `subs.ts:12` importerer altså kørende kode fra en sti,
der læser som deklarationer.

Flytning til `util/meta.ts` er to importlinjer. Værdien er, at næste læser ikke antager
noget `.d.ts`-formet om filen — og at `build.sh`s `--exclude='*.ts'` aldrig kommer til at
se ud som om den også kunne gælde her.

### 2.2 Meta-boksens shell-markup har to ejere, og ingen af dem nævner den anden

`order.ts:57-62` bygger `#wcsp-meta-head` / `#wcsp-meta-ul` / `#wcsp-meta-foot` i
browseren. `admin/subscriptions.php:67-68` udskriver `#wcsp-meta-head` / `#wcsp-meta-ul`
fra serveren. `types/meta.ts` slår begge id'er op med `getElementById` og **no-op'er
tavst**, hvis de ikke er der (`meta.ts:38-40`, `:52-54`).

Det er kombinationen der er problemet: to uafhængige kilder til den samme kontrakt, plus
en forbruger der fejler i stilhed. Omdøbes et id ét sted, forsvinder abonnementsboksens
indhold uden en eneste fejl nogen steder — hverken i `tsc`, i `phpcs` eller i konsollen.

Guiden accepterer eksplicit, at intet værktøj tjekker feltnavne, og udpeger kommentarer
som kompensationen. Her er der ingen: `renderShell()` har slet ingen kommentar, og
`subscriptions.php:62-69` forklarer kun `data-endpoint`. **Verificeret.**

**Rettelse:** en linje hvert sted, der peger på den anden. Alternativt lade
`subscriptions.php` udsende en tom `<div id="wcsp-meta">` og lade `subs.ts` kalde en delt
`renderShell()` — så er der én ejer.

### 2.3 Endpoint-fallbacken står tre gange, kommentaren ordret to gange

`order.ts:15-18`, `subs.ts:25-28`, `compat.ts:22-26`

Alle tre har `endpoint || '../wp-scanpay/fetch'` og en 3-4 linjers forklaring af hvorfor.
`order.ts` og `subs.ts` har den *ordret* identisk. Ændres fallback-stien, skal tre steder
rettes, og de to kommentarer skal holdes i takt.

Lav prioritet — filen-er-modulet-princippet gør en vis duplikering til et bevidst valg —
men en delt konstant i `util/` ville koste én import og fjerne muligheden for at de tre
kommer fra hinanden.

### 2.4 `applepay.ts` re-destrukturerer `window.wp.i18n` — præcis det mønster `util/i18n.ts` findes for at forhindre

`src/public/assets/js/applepay.ts:14`

```ts
const { __ } = window.wp.i18n;
```

`util/i18n.ts`s dokblok advarer i klartekst mod netop denne linje: to
`const { __ } = window.wp.i18n` i samme bundle får esbuild til at omdøbe den ene til
`__2`, som `wp i18n make-pot` ikke genkender — og strengene falder lydløst ud af
kataloget.

Det er sikkert i dag, af to grunde der begge er verificerbare og begge er tilfældigheder:
`build.sh:43-46` behandler hver `assets/js/*.ts` i topniveau som sit eget entry point, så
`applepay.ts` er en bundle for sig selv; og `util/i18n.ts` ligger under `admin/`, så
public-siden ikke *kan* importere den uden at krydse grænsen.

Invarianten holdes altså udelukkende af, at der lige nu findes præcis én public-fil der
oversætter. Den næste public-fil med et `__()` genindfører fejlen, og den fejler tavst.

**Rettelse:** en `public/assets/js/util/i18n.ts`-tvilling. Elleve linjers duplikering mod
en strukturel garanti. **Verificeret** (build-flow og mappelayout).

### 2.5 `checkout.js` registreres uden `wp-i18n` og uden `wp_set_script_translations()`

`src/gateways/blocks/class-wc-scanpay-blocks-support.php:26`

```php
[ 'wc-blocks-registry', 'wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-element' ],
```

Korrekt som det står — `checkout.ts` kalder ikke `__()` én eneste gang; alle dens strenge
(`data.terms.label`, `.link`, `.error`) er oversat på PHP-siden. Men det er en usagt
forudsætning på et registreringssted, hvor alle *andre* enqueues i pluginet har
`wp-i18n` med. Det første `__()` nogen tilføjer til `checkout.ts` producerer engelsk uden
en advarsel.

`util/i18n.ts`s dokblok påstår i øvrigt, at afhængigheden er *"declared at every enqueue
site"*. Det er sandt for de scripts der bruger i18n, men læses let som en generel regel
der ikke gælder. **Verificeret.**

**Rettelse:** en kommentar ved registreringen der siger, at denne bundle bevidst ikke
oversætter, og hvad der skal med hvis det ændrer sig.

---

## 3. Kommentarer, typer og i18n-detaljer

Småting. Samlet her, fordi guiden behandler filhoveder og dokblokke som dokumentation,
der skal passe.

**3.1 Tre filhoveder navngiver en `.js`-fil.** `settings.ts:2` (`settings.js`),
`applepay.ts:2` (`applepay.js`), `checkout.ts:2` (`checkout.js`). `order.ts` og `subs.ts`
navngiver rigtigt `.ts`. `settings.ts`s hoved har desuden en indledende tabulator inde i
kommentaren og er den eneste af de fem, der ikke siger noget om, *hvad* filen gør ud over
navnet.

**3.2 `types/order.d.ts` har tre ting.** Linje 1 er `// types/global.d.ts` — et forældet
filnavn. Filen er indrykket med fire mellemrum, hvor alle ni andre TS-filer bruger
tabulatorer (`@wordpress/prettier-config` giver tabulatorer;
`wp-scripts lint-js` fanger det ikke). Og `Window.wcSettings: unknown` (linje 37) læses
ikke af nogen: koden bruger `window.wc.wcSettings`, som er typet i
`public/assets/js/types/checkout.d.ts:41-43`.

**3.3 Msgid med indledende mellemrum.** `settings.ts:142`:
`__(' Could not delete the data: %s', …)`. Indledende og afsluttende mellemrum i et msgid
er en klassisk katalogfælde — de er usynlige i PO-filen, og oversættere taber dem. Sæt
mellemrummet i markup eller CSS.

**3.4 Genoprettelseslabel hardcodet i stedet for gemt.** `settings.ts:138` skriver
`__('Delete data and change API key', …)` tilbage i knappen efter en fejlet reset. Præcis
det samme msgid står i `abstract-wc-gateway-scanpay-base.php:266`, og de to skal nu holdes
i takt. `order.ts:onCapture` gør det rigtige for den tilsvarende knap: gemmer
`btn.textContent` i `label` før overskrivning og genskaber derfra (`order.ts:142-146`).
Samme mønster her fjerner en katalogpost. **Verificeret** at msgid'erne er identiske.

**3.5 `parseVersion()`s dokblok beskriver en adfærd, som vagten forhindrer.**
`compat.ts:78-82`:

> *"Anything still unparseable -- an unsubstituted `'{{ VERSION }}'` in unbuilt src/ --
> becomes 0. Every NaN comparison below returns false, so the update banner would
> silently never fire."*

Anden sætning er i nutid, men beskriver hvad der ville ske *uden* `Number.isNaN`-vagten i
linjen ovenover. Med vagten er der ingen NaN-sammenligninger tilbage. Den faktiske adfærd
i ubygget `src/` er den modsatte: `'{{ VERSION }}'` → `[0]` → enhver udgivet version er
større → banneret fyrer *altid*.

Det rammer kun ubygget kildekode (`build.sh` substituerer tokenet, og gør det som det
allersidste, efter minificeringen — verificeret i `build.sh:101-103`), så der er ingen
konsekvens i drift. Men sætningen er en kontrafaktisk påstand skrevet som en beskrivelse,
og det er den slags, der læses forkert næste gang.

**3.6 `if (dom && data)` beskytter mod noget, der allerede er sket.** `order.ts:197`
tjekker `data` — men `order.ts:18` har læst `data.endpoint` 179 linjer tidligere. Var
`window.ScanpayOrderData` fraværende, ville modulet kaste ved linje 18, og vagten ville
aldrig køre. Den er heller ikke fraværende: `orders.php:142-146` printer den med
`wp_add_inline_script(..., 'before')`, og `order.d.ts:36` typer den som ikke-nullable, så
`&& data` er bevisligt død. Det er ikke en fejl — det er en vagt der læser som beskyttelse
uden at være det. **Verificeret.**

**3.7 `registerPaymentMethod: any`.** `types/checkout.d.ts:30`. Det mest
konsekvensfyldte kald i public-bundlen er utypet. `eslint.config.mjs:40` slår
`no-explicit-any` fra med kommentaren `// allow any tmp`, dateret 2024-09-11 i
filhovedet. Nævnt her fordi "tmp" nu er knap to år gammel, ikke fordi denne ene skal
rettes først.

---

## 4. Kontrolleret og bevidst ikke rapporteret

Så næste review ikke bruger tid på det igen.

- **Secret'et i `window.ScanpayOrderData` og i `data-secret`.** Afgjort i CLAUDE.md
  ("The admin-AJAX secret is unscoped, and stays that way"), med begrundelsen at
  ordreredigeringsadgang på en WooCommerce-shop er alt-eller-intet. Ikke flagget.

- **`subs.ts:76-79` — `rev=0` og long-poll.** Kommentaren siger, at `rev=0` returnerer
  rækken uden at holde forbindelsen. Det så først forkert ud, fordi
  `wp-scanpay-fetch-sub.php:40` long-poller på `$rev >= $sub['rev']`, hvilket ville være
  sandt for en række med `rev = 0`. Men `class-wc-scanpay-sync.php:427-430` afviser
  `rev <= 0` med en exception, så `scanpay_subs.rev` er altid ≥ 1. **Kommentaren er
  korrekt.**

- **`subs.ts` kalder aldrig `renderShell()`.** Ligner en tom meta-boks, er det ikke:
  `subscriptions.php:67-68` udskriver `#wcsp-meta-head` og `#wcsp-meta-ul` server-side.
  Den koblingen er §2.2, men der er ingen fejl.

- **Keep-alive-`\n` før JSON-body'en.** `wp-scanpay-fetch-meta.php:58` og
  `wp-scanpay-fetch-sub.php:62` skriver `"\n"` per runde *før* `wp_send_json()`, så body'en er
  `"\n\n\n{…}"`. `JSON.parse` springer indledende whitespace over per specifikation, så
  `res.json()` klarer det. Ikke et problem.

- **`insertAdjacentHTML`/`innerHTML` med oversatte strenge** (`settings.ts:37`,
  `meta.ts:82`). Hvert eneste argument er en katalogstreng, og alt dynamisk går ind som
  tekstnode — `err.message` gennem `detail` (`settings.ts:39`), versionsnummeret gennem
  `span.textContent` (`settings.ts:203`, `meta.ts:88`). Oversætter-leveret markup i
  `__()` er standardmønsteret i WordPress. Ikke flagget.

- **`for (const name in data.methods)`** (`checkout.ts:29`). `for...in` over et objekt
  fra `JSON.parse` har ingen nedarvede enumerable properties. Sikkert.

- **`applepay.ts`s `blocked`-flag.** Så først ud til at kunne efterlade `#place_order`
  permanent deaktiveret, hvis Apple Pay forsvandt helt fra listen ved en
  fragment-opdatering (early return på `!row`, `apply()` linje 79-81). Det gør det ikke:
  WooCommerce erstatter hele `.woocommerce-checkout-payment` inklusive knappen, og
  `setSubmitDisabled()`s `state === blocked`-vagt (linje 39) konvergerer på næste kørsel.
  Gaten mod at fortryde en deaktivering WooCommerce selv har foretaget, er korrekt
  implementeret.

- **`applepay.js`s dependencies.** `['jquery', 'wp-i18n']`
  (`class-wc-gateway-scanpay-applepay.php:56`) plus `wp_set_script_translations()` på
  linje 60. Modultopniveau-destruktureringen af `window.wp.i18n` er dækket. (Mønstret er
  stadig §2.4 — men afhængigheden er der.)

- **`ExtensionData` ryddes ikke ved unmount** (`checkout.ts:121`). Cleanup-funktionen
  rydder valideringsfejlen, men ikke `setExtensionData('scanpay', …)`. Uden konsekvens:
  `wcs_scanpay_blocks_validate_terms()` (`woocommerce-scanpay.php:282-297`) udleder selv
  fra kurven om samtykket overhovedet kræves, og afviser i Store API'et. Serveren er
  autoriteten. Ikke flagget.

- **ES2020-syntaks uden `--target` på esbuild.** `build.sh:45` sender hverken `--target`
  eller `--format`, så esbuild default'er til `esnext` og sender `?.` og `??` uændret
  igennem. Understøttet i alle browsere siden 2020. Værd at vide, at der ikke findes et
  erklæret browser-mål nogen steder, men det er ikke en fejl i 2026.

---

## Opsummering

Koden er i god stand. Den er kommenteret tættere end det meste TypeScript, kommentarerne
forklarer overvejende *hvorfor* frem for *hvad*, og de tre steder hvor jeg gik efter en
mistænkelig konstruktion (`rev=0`, den manglende `renderShell()`, `blocked`-flaget) viste
sig at være rigtige og velbegrundede.

Prioriteret rækkefølge, hvis der kun er tid til noget af det:

1. **§1.2** — `insertAdjacentHTML` i stedet for `innerHTML +=`. Ét ord, rører ved
   API-nøglefeltet.
2. **§1.1** — UTC-datoen i abonnementsboksen. Forkert data på skærmen.
3. **§1.4** — `%s`-kommentaren i `checkout.ts`. Enten koden eller kommentaren; som det
   står, er invarianten ikke sand.
4. **§1.3** — `isMoney(meta.voided)`. Ét led i en konjunktion.
5. **§2.2** og **§2.4** — de to steder hvor en invariant kun holdes af tilfældigheder, og
   hvor bruddet vil være tavst.

Resten er oprydning, der med fordel kan følge med, når filerne alligevel er åbne.
