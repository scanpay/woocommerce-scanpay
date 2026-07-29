# TypeScript-review — `src/` (10 filer, 1148 linjer)

Opdateret 2026-07-29 mod `adb090c`. Første udgave skrevet 2026-07-27 mod `685a78e`.
Scope: al TypeScript under `src/`, læst i sin helhed. Ingen kode er ændret.

```
src/admin/assets/js/order.ts              215
src/admin/assets/js/settings.ts           223
src/admin/assets/js/subs.ts               108
src/admin/assets/js/types/order.d.ts       38
src/admin/assets/js/util/compat.ts        107
src/admin/assets/js/util/i18n.ts           11
src/admin/assets/js/util/meta.ts           94
src/public/assets/js/applepay.ts          111
src/public/assets/js/checkout.ts          160
src/public/assets/js/types/checkout.d.ts   81
```

Ti af den forrige udgaves punkter er rettet i mellemtiden; de står opsummeret til sidst.
Resten er verificeret igen mod den nuværende kode, og der er kommet ét nyt punkt til i
toppen af listen.

## Konventioner

- **Verificeret** — bekræftet mod kilden i dette repo (PHP-endpointet, enqueue-stedet,
  `build.sh`), med citat.
- **Udledt** — læst ud af koden og ræsonneret igennem, men afhænger af runtime-adfærd
  der ikke kan køres her.
- **Uverificeret** — kræver en kørende shop. Præsenteres aldrig som faktum.

`pnpm exec tsc` og `pnpm lint:js` er begge rene på `adb090c`. Det er hele den maskinelle
validering der findes for TypeScript her, så alt nedenfor er semantik som ingen linter
ser. Der er ingen WordPress-installation i dette repo; ingen påstand herunder er afprøvet
i en browser.

---

## Ret nu — brugersynlig forkert adfærd

### 1. En frisk installation viser *"Error: Something went wrong"* så snart købmanden vender tilbage til fanebladet

`src/admin/assets/js/settings.ts:187-190`

```ts
// checkPing when the tab is visible again
document.addEventListener('visibilitychange', () => {
	if (document.visibilityState === 'visible') checkMtime();
});
```

Listeneren er ugatet, mens den tilsvarende kaldsvej ved sideindlæsning ikke er. Linje
166 vælger bevidst mellem to tilstande:

```ts
if (alertBox.dataset.shopid === '0') {
	// … render "You can find your Scanpay API key here" ved siden af nøglefeltet
} else {
	checkMtime();
}
```

`$shopid` udledes af nøglen selv — `(int) strstr( $settings['apikey'] ?? '', ':', true )`
(`admin-options.php:19`) — så `shopid === '0'` betyder *ingen API-nøgle gemt endnu*.
`checkMtime()` springes derfor korrekt over ved indlæsning. Men `visibilitychange` kalder
den uden den betingelse, og `checkMtime()`s egen vagt hjælper ikke:

```ts
if (!alertBox.dataset.secret) return;
```

Secret'et er der. `install.php:107-111` minter det, og filen requires fra
aktiveringshook'et (`woocommerce-scanpay.php:290`) — altså længe før nogen nøgle er
indtastet. Så `getLastSync()` fyrer `?x=ping`, og endpointet svarer **403 `invalid
apikey`** på præcis denne tilstand:

```php
$shopid = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );
if ( 0 === $shopid ) {
	status_header( 403, 'Forbidden' );
	echo 'invalid apikey';
```
(`wp-scanpay-fetch-ping.php:33-39`)

`compat.ts:32` kaster body'en videre, og `.catch()`-grenen i `settings.ts:97-105` renderer:

> **Error: Something went wrong**
> Your system responded with the following error message: invalid apikey

Det er ikke en kant. Det er den tilsigtede vej gennem opsætningen: hjælpelinket som
grenen *selv* lige har indsat er `target="_blank"` (`settings.ts:170`), så det at hente
sin nøgle åbner et nyt faneblad, skjuler det gamle, og fyrer eventet ved tilbagevenden.
Førstegangsoplevelsen af pluginet er en rød fejlbesked om at noget gik galt, på et
tidspunkt hvor intet er gået galt. Og fordi `getLastSync()` kaster før
`localStorage.setItem`, caches der intet — den kommer igen ved hvert fanebladsskift.

**Verificeret** i hele kæden (`admin-options.php`, `install.php`,
`wp-scanpay-fetch-ping.php`, `compat.ts`, `settings.ts`). Kun selve browser-adfærden ved
`target="_blank"` er udledt.

**Rettelse:** gate listeneren på samme betingelse som indlæsningen — flyt den ind i
`else`-grenen, eller lad handleren læse `alertBox.dataset.shopid !== '0'`. Én linje.

Værd at bemærke undervejs: `.catch()` på linje 97 hænger på hele `.then()`-kæden, så den
også fanger DOM-fejl fra render-grenen (fx non-null-assertionen på `getElementById(
'wcsp-set-nav-mtime')!`, linje 65) og rapporterer dem som noget *systemet svarede*. Der
er ingen konsekvens i dag — elementet udskrives i `admin-options.php:120`, ti linjer over
`#wcsp-set-alert` — men beskeden lover en oprindelse den ikke kan holde.

### 2. `fmtDate()` viser betalingsdatoen i UTC, ikke i shoppens tidszone

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
`subscriptions.php:60-64` udsender allerede `data-*`-attributter, så enten en
`data-utc-offset`, eller en færdigformateret streng fra PHP, holder formateringen ét sted.

Samme rod i `fmtExp()` (`subs.ts:48-53`), der læser `getUTCMonth()`/`getUTCFullYear()`.
Dér er konsekvensen forsvindende — et kort skal udløbe præcis på et månedsskifte inden
for det samme 1-2 timers vindue — men det er den samme antagelse.

### 3. Kommentaren om `%s` i abonnementsbetingelserne passer ikke til koden

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
på enden: *"Jeg accepterer betingelserne.abonnementsbetingelser"*. Linket er der, det
står bare det forkerte sted.

Kilden er verificeret delt med den klassiske renderer: begge bruger msgid'et
`'I accept the %s.'` (`wcs-scanpay-checkout-terms.php:25-27` og
`class-wc-scanpay-blocks-support.php:81`), så en oversættelse der taber `%s` rammer også
klassisk checkout — dér gennem `sprintf()`, som bare dropper argumentet og *faktisk*
giver ren tekst uden link. De to checkouts degraderer altså forskelligt.

Guiden er eksplicit på dette punkt: *"a comment that has drifted is a broken test."*
Enten skal `<a>` gøres betinget af `after !== undefined`, eller også skal kommentaren
rette sig efter koden. **Verificeret** i begge renderers.

---

## Ret snart — invarianter der kun holdes af tilfældigheder, og som brydes tavst

### 4. Meta-boksens shell-markup har to ejere, og ingen af dem nævner den anden

`order.ts:52-57` bygger `#wcsp-meta-head` / `#wcsp-meta-ul` / `#wcsp-meta-foot` i
browseren. `admin/subscriptions.php:65-66` udskriver `#wcsp-meta-head` / `#wcsp-meta-ul`
fra serveren. `util/meta.ts` slår begge id'er op med `getElementById` og **no-op'er
tavst**, hvis de ikke er der (`meta.ts:37-40`, `:51-54`).

Det er kombinationen der er problemet: to uafhængige kilder til den samme kontrakt, plus
en forbruger der fejler i stilhed. Omdøbes et id ét sted, forsvinder abonnementsboksens
indhold uden en eneste fejl nogen steder — hverken i `tsc`, i `phpcs` eller i konsollen.

Guiden accepterer eksplicit, at intet værktøj tjekker feltnavne, og udpeger kommentarer
som kompensationen. Her er der ingen: `renderShell()` har slet ingen kommentar, og
`subscriptions.php:58-59` forklarer kun `data-endpoint`. **Verificeret.**

**Rettelse:** en linje hvert sted, der peger på den anden. Alternativt lade
`subscriptions.php` udsende en tom `<div id="wcsp-meta">` og lade `subs.ts` kalde en delt
`renderShell()` — så er der én ejer.

### 5. `applepay.ts` re-destrukturerer `window.wp.i18n` — præcis det mønster `util/i18n.ts` findes for at forhindre

`src/public/assets/js/applepay.ts:14`

```ts
const { __ } = window.wp.i18n;
```

`util/i18n.ts`s dokblok advarer i klartekst mod netop denne linje: to
`const { __ } = window.wp.i18n` i samme bundle får esbuild til at omdøbe den ene til
`__2`, som `wp i18n make-pot` ikke genkender — og strengene falder lydløst ud af
kataloget.

Det er sikkert i dag, af to grunde der begge er verificerbare og begge er tilfældigheder:
`build.sh:41-47` behandler hver `assets/js/*.ts` i topniveau som sit eget entry point, så
`applepay.ts` er en bundle for sig selv; og `util/i18n.ts` ligger under `admin/`, så
public-siden ikke *kan* importere den uden at krydse grænsen.

Invarianten holdes altså udelukkende af, at der lige nu findes præcis én public-fil der
oversætter. Den næste public-fil med et `__()` genindfører fejlen, og den fejler tavst.

**Rettelse:** en `public/assets/js/util/i18n.ts`-tvilling. Elleve linjers duplikering mod
en strukturel garanti. **Verificeret** (build-flow og mappelayout).

### 6. Tre msgids bærer et mellemrum i kanten

Indledende og afsluttende mellemrum i et msgid er en klassisk katalogfælde: de er
usynlige i PO-filen, og oversættere taber dem. Der er tre:

```
settings.ts:100   'Your system responded with the following error message: '
settings.ts:155   ' Could not delete the data: %s'
settings.ts:204   'There is a new version of the plugin available. '
```

Det danske katalog bevarer dem alle tre i dag (`src/languages/*.po:473`, `:493`, `:501`),
så der er ingen aktiv fejl — men det er tre steder hvor en fremtidig oversættelse
lydløst kan klistre to ord sammen. **Verificeret** mod PO-filen.

**Rettelse:** flyt mellemrummet ud i markup eller CSS. (`checkout.ts:135` har et bevidst
`' '` som React-child; det er ikke et msgid og er ikke omfattet.)

---

## Oprydning

### 7. `fmtMoney()` ignorerer alle WooCommerces valutaformat-indstillinger på nær decimalantallet

`src/admin/assets/js/order.ts:31-40`

Funktionen normaliserer decimaldelen og sætter valutakoden bagefter. En dansk shop får
`1234.56 DKK` i Scanpay-boksen, mens WooCommerces egne ordretotaler i samme skærmbillede
står som `1.234,56 kr.`

Dokblokken er præcis om *hvorfor* der ikke regnes (penge er decimalstrenge, ingen
float-aritmetik — helt korrekt og på linje med `library/math.php`), men formatering er
ikke aritmetik. `wc_decimals` sendes allerede med fra `orders.php:115`;
`wc_get_price_decimal_separator()`, `wc_get_price_thousand_separator()` og
symbol/position er tre felter mere i det samme `$props`-array.

**Udledt.** Kosmetisk, men det er købmandens egne penge på købmandens egen skærm, ved
siden af tal der er formateret rigtigt.

### 8. `refresh()` kan ikke skelne "synkroniseringen er ikke landet" fra "pollet fejler"

`src/admin/assets/js/order.ts:175-200`

`catch {}` er tom med kommentaren `/* transient; keep polling */`, og `res.ok === false`
falder igennem til samme sted. En 403 fra et roteret secret, en netværksfejl og en række
der endnu ikke er opdateret, giver alle tre det samme udfald: tre forsøg, `false`, og
beskeden *"Capture requested — figures will refresh on the next sync."*

Beskeden er ikke forkert — hævningen *lykkedes*, det er kun opdateringen der mangler —
men et vedvarende brudt poll-endpoint er usynligt for købmanden. Efter et reset er det
netop scenariet: secret'et er roteret (`wp-ajax-wc-scanpay-reset.php`), og et faneblad
der stadig står åbent med det gamle payload i `window.ScanpayOrderData` vil 403'e i
tavshed.

**Udledt.** Lav prioritet — selvhelbredelsen sker ved næste sideload.

### 9. `util/compat.ts` har intet filhoved, og navnet beskriver ikke indholdet

Filen åbner direkte på `function safeJsonParse`. Guiden kræver et filhoved undtagen hvor
formålet er selvindlysende, og "compat" er det ikke: filen indeholder to helt adskilte
ting — sync-status-pollet mod `?x=ping` (`getLastSync`, med sin egen localStorage-cache)
og opdateringstjekket mod GitHubs release-API (`checkVersion`, `isVersionGreater`, med
en anden cache og en anden TTL). Ingen af delene handler om kompatibilitet.

De ni øvrige TS-filer har alle et hoved. **Verificeret.**

**Rettelse:** et hoved der siger hvad de to halvdele er, og hvorfor de deler fil. Et
omdøbe (`util/remote.ts`, `util/status.ts`) er større end det problemet fortjener, men
navnet bør så nævnes i hovedet.

### 10. `util/meta.ts`s filhoved beskriver én af sine fire eksporter

`src/admin/assets/js/util/meta.ts:1-5`

```
/*
	Show a warning message in the meta box.
	Identical messages are shown once (see showWarning).
	Every helper no-ops when the meta box is not on the page.
*/
```

Filen eksporterer `showError`, `showWarning`, `buildTable` og `pluginVersionCheck`.
Første linje læses som filens formål, men beskriver kun den anden af de fire. Hovedet
overlevede flytningen fra `types/` til `util/` uændret. Det er desuden `/*`, hvor de
øvrige ni filer bruger `/** */`.

Tredje linje — *"Every helper no-ops when the meta box is not on the page"* — er den
præcise og værdifulde sætning, og den er samtidig den halve forklaring på §4.

### 11. Endpoint-fallbacken står tre gange

`order.ts:176-181`, `subs.ts:25-28`, `compat.ts:24-28`

Alle tre har `endpoint || '../wp-scanpay/fetch'` og en 3-5 linjers forklaring af hvorfor.
`order.ts` har siden fået en fjerde linje om hvorfor konstanten er flyttet ind i
funktionen, så de to admin-kommentarer er ikke længere ordret ens — men grunden de
forklarer er den samme, og ændres fallback-stien skal tre steder rettes.

Lav prioritet — filen-er-modulet-princippet gør en vis duplikering til et bevidst valg —
men en delt konstant i `util/` ville koste én import og fjerne muligheden for at de tre
kommer fra hinanden.

### 12. `registerPaymentMethod: any`

`types/checkout.d.ts:30`. Det mest konsekvensfyldte kald i public-bundlen er utypet.
`eslint.config.mjs:40` slår `no-explicit-any` fra med kommentaren `// allow any tmp`,
dateret 2024-09-11 i filhovedet. Nævnt her fordi "tmp" nu er knap to år gammel, ikke
fordi denne ene skal rettes først.

Samme fil har `wcSettings.getSetting: (key: string) => any` (linje 42), som er den anden
ende af den utypede grænseflade: `checkout.ts:13` caster resultatet til
`WooPaymentMethodData` uden nogen kontrol af at payload'et overhovedet kom med.

---

## Rettet siden forrige udgave (`685a78e` → `adb090c`)

Efterladt her, så en læser med den gamle udgave i hånden kan se hvad der er lukket.
Alle er verificeret rettet i den nuværende kode.

| Var | Punkt | Rettet i |
| --- | --- | --- |
| §1.2 | `td.innerHTML +=` genparsede API-nøglefeltet | `settings.ts:177-181` — `insertAdjacentHTML`, med kommentaren om hvorfor |
| §1.3 | `renderFoot()` validerede `voided` løsere end de to andre beløb | `order.ts:92-96` — `isMoney(meta.voided)` tilføjet, med begrundelse |
| §1.6 | `getLastSync()`s døde `force`-parameter, og ping-cachen der overlevede et reset | `compat.ts:18` (parameter fjernet), `settings.ts:142-147` (`removeItem` i succes-grenen) |
| §2.1 | `types/meta.ts` var et runtime-modul i en `types/`-mappe | Flyttet til `util/meta.ts`, to importlinjer opdateret |
| §2.5 | Blocks-bundlen registreret uden `wp-i18n`, uden at det stod nogen steder | `class-wc-scanpay-blocks-support.php:34-37` — kommentar der siger hvad der skal med, hvis et `__()` tilføjes |
| §3.1 | Tre filhoveder navngav en `.js`-fil | `settings.ts`, `applepay.ts`, `checkout.ts` — alle tre retter navnet; `settings.ts` fik desuden et rigtigt hoved |
| §3.2 | `order.d.ts`: forældet filnavn, fire-mellemrums-indrykning, ubrugt `wcSettings` | Alle tre — filen er nu tabuleret og hovedet beskriver kontrakten mod `orders.php` |
| §3.4 | Genoprettelseslabelen var hardcodet i stedet for gemt | `settings.ts:138` + `:151` — `btn.textContent` gemmes, som `order.ts` allerede gjorde |
| §3.5 | `parseVersion()`s dokblok beskrev adfærd som vagten forhindrer | `compat.ts:77-86` — omskrevet, og siger nu hvad der faktisk sker i ubygget `src/` |
| §3.6 | `if (dom && data)` beskyttede mod noget der allerede var sket | `order.ts:176-181` + `:202-204` — `ep` flyttet ind i `refresh()`, så vagten igen er den første dereference |

---

## Kontrolleret og bevidst ikke rapporteret

Så næste review ikke bruger tid på det igen.

- **Secret'et i `window.ScanpayOrderData` og i `data-secret`.** Afgjort i CLAUDE.md
  ("The admin-AJAX secret is unscoped, and stays that way"), med begrundelsen at
  ordreredigeringsadgang på en WooCommerce-shop er alt-eller-intet. Ikke flagget.

- **Terms-checkboxen når vores egne gateways er slået fra.** Så først ud til at falde
  bort med dem, siden `$data['terms']` rejser i betalingsmetodens payload. Det gør den
  ikke: `get_payment_method_data()` sætter nøglen uden for `enabled`-gaten
  (`class-wc-scanpay-blocks-support.php:66-86`), og
  `AbstractPaymentMethodType::is_active()` defaulter til `true`, så handlet registreres
  uanset. Kommentaren på stedet siger det allerede. **Cart-level-invarianten holder.**

- **`subs.ts:76-79` — `rev=0` og long-poll.** Kommentaren siger, at `rev=0` returnerer
  rækken uden at holde forbindelsen. Det så først forkert ud, fordi
  `wp-scanpay-fetch-sub.php` long-poller på `$rev >= $sub['rev']`, hvilket ville være
  sandt for en række med `rev = 0`. Men `class-wc-scanpay-sync.php` afviser `rev <= 0`
  med en exception, så `scanpay_subs.rev` er altid ≥ 1. **Kommentaren er korrekt.**

- **`subs.ts` kalder aldrig `renderShell()`.** Ligner en tom meta-boks, er det ikke:
  `subscriptions.php:65-66` udskriver `#wcsp-meta-head` og `#wcsp-meta-ul` server-side.
  Koblingen er §4, men der er ingen fejl.

- **`#wcsp-set-alert`-castet uden nullcheck** (`settings.ts:165-166`). `as HTMLElement`
  efterfulgt af en dereference. Sikkert: `settings.js` enqueues kun på pluginets egne
  settings-skærme (`settings.php:32-35`, gated på `wc_scanpay_is_settings_screen()`), og
  `admin-options.php:128-134` udskriver elementet ubetinget på dem alle.

- **Keep-alive-`\n` før JSON-body'en.** `wp-scanpay-fetch-meta.php` og
  `wp-scanpay-fetch-sub.php` skriver `"\n"` per runde *før* `wp_send_json()`, så body'en
  er `"\n\n\n{…}"`. `JSON.parse` springer indledende whitespace over per specifikation,
  så `res.json()` klarer det. Ikke et problem.

- **`insertAdjacentHTML`/`innerHTML` med oversatte strenge** (`settings.ts:40`,
  `meta.ts:82`). Hvert eneste argument er en katalogstreng, og alt dynamisk går ind som
  tekstnode — `err.message` gennem `detail` (`settings.ts:42`), versionsnummeret gennem
  `span.textContent` (`settings.ts:218`, `meta.ts:88`). Oversætter-leveret markup i
  `__()` er standardmønsteret i WordPress. Ikke flagget.

- **`for (const name in data.methods)`** (`checkout.ts:29`). `for...in` over et objekt
  fra `JSON.parse` har ingen nedarvede enumerable properties. Sikkert.

- **`applepay.ts`s `blocked`-flag.** Så først ud til at kunne efterlade `#place_order`
  permanent deaktiveret, hvis Apple Pay forsvandt helt fra listen ved en
  fragment-opdatering (early return på `!row`, `apply()` linje 78-81). Det gør det ikke:
  WooCommerce erstatter hele `.woocommerce-checkout-payment` inklusive knappen, og
  `setSubmitDisabled()`s `state === blocked`-vagt (linje 39) konvergerer på næste kørsel.
  Gaten mod at fortryde en deaktivering WooCommerce selv har foretaget, er korrekt
  implementeret.

- **`applepay.js`s dependencies.** `['jquery', 'wp-i18n']`
  (`class-wc-gateway-scanpay-applepay.php:55-61`) plus `wp_set_script_translations()`.
  Modultopniveau-destruktureringen af `window.wp.i18n` er dækket. (Mønstret er stadig
  §5 — men afhængigheden er der.)

- **`ExtensionData` ryddes ikke ved unmount** (`checkout.ts:120-121`). Cleanup-funktionen
  rydder valideringsfejlen, men ikke `setExtensionData('scanpay', …)`. Uden konsekvens:
  `wcs_scanpay_blocks_validate_terms()` udleder selv fra kurven om samtykket overhovedet
  kræves, og afviser i Store API'et. Serveren er autoriteten. Ikke flagget.

- **ES2020-syntaks uden `--target` på esbuild.** `build.sh:41-47` sender hverken
  `--target` eller `--format`, så esbuild default'er til `esnext` og sender `?.` og `??`
  uændret igennem. Understøttet i alle browsere siden 2020. Værd at vide, at der ikke
  findes et erklæret browser-mål nogen steder, men det er ikke en fejl i 2026.

---

## Opsummering

Koden er i bedre stand end ved forrige review: ti punkter er lukket, og rettelserne har
gennemgående efterladt en kommentar der forklarer *hvorfor*, ikke bare ændret linjen.
`order.ts:202-204` er det bedste eksempel — vagten er ikke bare bevaret, den er gjort
sand igen, og kommentaren siger hvad der holder den sand.

Det ene nye punkt, §1, er også det vigtigste i hele listen. Det er ikke opstået af en
ændring; det har ligget der hele tiden, og det slap gennem forrige review fordi jeg læste
`checkMtime()`s egen `secret`-vagt som dækkende og ikke fulgte secret'et tilbage til
`install.php`. Det rammer den allerførste skærm en ny købmand ser, ad den vej pluginet
selv anviser, og rettelsen er én linje.

Prioriteret rækkefølge, hvis der kun er tid til noget af det:

1. **§1** — `visibilitychange` bag samme gate som indlæsningen. Én linje; fjerner en
   falsk fejlbesked fra opsætningsflowet.
2. **§2** — UTC-datoen i abonnementsboksen. Forkert data på skærmen.
3. **§3** — `%s`-kommentaren i `checkout.ts`. Enten koden eller kommentaren; som det
   står, er invarianten ikke sand.
4. **§4** og **§5** — de to steder hvor en invariant kun holdes af tilfældigheder, og
   hvor bruddet vil være tavst.
5. **§6** — de tre msgids med mellemrum i kanten, mens kataloget stadig er rigtigt.

Resten er oprydning, der med fordel kan følge med, når filerne alligevel er åbne.
