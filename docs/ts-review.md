# TypeScript-review — `src/` (10 filer, 1191 linjer)

Opdateret 2026-07-29 mod `9fe3b5c`. Første udgave skrevet 2026-07-27 mod `685a78e`.
Scope: al TypeScript under `src/`, læst i sin helhed.

```
src/admin/assets/js/order.ts              208
src/admin/assets/js/settings.ts           227
src/admin/assets/js/subs.ts               110
src/admin/assets/js/types/order.d.ts       38
src/admin/assets/js/util/compat.ts        119
src/admin/assets/js/util/i18n.ts           11
src/admin/assets/js/util/meta.ts          114
src/public/assets/js/applepay.ts          122
src/public/assets/js/checkout.ts          162
src/public/assets/js/types/checkout.d.ts   80
```

Seks af den forrige udgaves tretten punkter er rettet og taget ud: den ugatede
`visibilitychange` (§1), `%s`-kommentaren i `checkout.ts` (§3), metaboksens to ejere (§4),
`compat.ts`s manglende filhoved (§9), `meta.ts`s filhoved (§10) og `setSubmitDisabled()`s
vagt (§13). De syv der står tilbage er nummereret om og efterprøvet mod koden som den er nu.

## Konventioner

- **Verificeret** — bekræftet mod kilden i dette repo (PHP-endpointet, enqueue-stedet,
  `build.sh`), med citat.
- **Udledt** — læst ud af koden og ræsonneret igennem, men afhænger af runtime-adfærd
  der ikke kan køres her.
- **Uverificeret** — kræver en kørende shop. Præsenteres aldrig som faktum.

`pnpm exec tsc`, `pnpm lint:js`, `pnpm phpcs` og `pnpm lint:style` er alle rene på
`9fe3b5c`. Det er hele den maskinelle validering der findes, så alt nedenfor er semantik
som ingen linter ser. Der er ingen WordPress-installation i dette repo; ingen påstand
herunder er afprøvet i en browser.

---

## Ret nu — brugersynlig forkert adfærd

### 1. `fmtDate()` viser betalingsdatoen i UTC, ikke i shoppens tidszone

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

Samme rod i `fmtExp()` (`subs.ts:48-53`), der læser `getUTCMonth()`/`getUTCFullYear()`.
Dér er konsekvensen forsvindende — et kort skal udløbe præcis på et månedsskifte inden
for det samme 1-2 timers vindue — men det er den samme antagelse.

**Rettelsen er delt, og det er med vilje.** `ptime` kommer fra PHP og bør præformateres
dér med `wp_date()`; `subscriptions.php:64-68` udsender allerede `data-*`-attributter, så
det er ét felt mere. `method_exp` ankommer derimod fra JSON-endpointet og kan ikke
præformateres uden at ændre endpointet — dér skal en `data-utc-offset` til. En offset
aflæst ved render-tid er forkert med en time for en betaling fra den anden halvdel af
året, hvilket er netop derfor `ptime` ikke bør bruge den.

---

## Ret snart — invarianter der kun holdes af tilfældigheder, og som brydes tavst

### 2. `applepay.ts` re-destrukturerer `window.wp.i18n` — præcis det mønster `util/i18n.ts` findes for at forhindre

`src/public/assets/js/applepay.ts:14`

```ts
const { __ } = window.wp.i18n;
```

`util/i18n.ts`s dokblok advarer i klartekst mod netop denne linje: to
`const { __ } = window.wp.i18n` i samme bundle får esbuild til at omdøbe den ene til
`__2`, som `wp i18n make-pot` ikke genkender — og strengene falder lydløst ud af
kataloget.

Det er sikkert i dag, af to grunde der begge er verificerbare og begge er tilfældigheder:
`build.sh:40-50` behandler hver `assets/js/*.ts` i topniveau som sit eget entry point, så
`applepay.ts` er en bundle for sig selv; og `util/i18n.ts` ligger under `admin/`, så
public-siden ikke *kan* importere den uden at krydse grænsen.

Invarianten holdes altså udelukkende af, at der lige nu findes præcis én public-fil der
oversætter. Den næste public-fil med et `__()` genindfører fejlen, og den fejler tavst.

**Rettelse:** en `public/assets/js/util/i18n.ts`-tvilling. Elleve linjers duplikering mod
en strukturel garanti. **Verificeret** (build-flow og mappelayout).

### 3. Tre msgids bærer et mellemrum i kanten

Indledende og afsluttende mellemrum i et msgid er en klassisk katalogfælde: de er
usynlige i PO-filen, og oversættere taber dem. Der er tre:

```
settings.ts:100   'Your system responded with the following error message: '
settings.ts:155   ' Could not delete the data: %s'
settings.ts:208   'There is a new version of the plugin available. '
```

Det danske katalog bevarer dem alle tre i dag (`src/languages/*.po:467`, `:487`, `:495`),
så der er ingen aktiv fejl — men det er tre steder hvor en fremtidig oversættelse
lydløst kan klistre to ord sammen. **Verificeret** mod PO-filen.

**Rettelsen koster ikke det samme for de tre.** `settings.ts:208` er en trailing space i
en `<h4>`-`textContent`; den kollapser allerede ved rendering, så mellemrummet kan bare
slettes. `:100` kompenseres i JS. `:155` er værst: `.wcsp-set-reset-msg` har **ingen**
CSS-regel overhovedet, så mellemrummet er det eneste der adskiller beskeden fra knappen —
det kræver en ny selector. Alle tre ændrer msgid'et, så `wp i18n update-po` forælder de
danske oversættelser, og de skal genindsættes i samme ombæring.

(`checkout.ts:132` har et bevidst `' '` som React-child; det er ikke et msgid og er ikke
omfattet.)

---

## Oprydning

### 4. `fmtMoney()` ignorerer alle WooCommerces valutaformat-indstillinger på nær decimalantallet

`src/admin/assets/js/order.ts:31-40`

Funktionen normaliserer decimaldelen og sætter valutakoden bagefter. En dansk shop får
`1234.56 DKK` i Scanpay-boksen, mens WooCommerces egne ordretotaler i samme skærmbillede
står som `1.234,56 kr.`

Dokblokken er præcis om *hvorfor* der ikke regnes (penge er decimalstrenge, ingen
float-aritmetik — helt korrekt og på linje med `library/math.php`), men formatering er
ikke aritmetik. `wc_decimals` sendes allerede med fra `orders.php:117`;
`wc_get_price_decimal_separator()`, `wc_get_price_thousand_separator()` og
symbol/position er tre felter mere i det samme `$props`-array.

**Udledt.** Kosmetisk, men det er købmandens egne penge på købmandens egen skærm, ved
siden af tal der er formateret rigtigt. Det er samtidig listens dyreste punkt: fire nye
payload-felter, matchende felter i `order.d.ts`, tusindtalsgruppering og fire
symbolpositioner (`left`/`right`/`left_space`/`right_space`).

### 5. `refresh()` kan ikke skelne "synkroniseringen er ikke landet" fra "pollet fejler"

`src/admin/assets/js/order.ts:168-194`

`catch {}` er tom med kommentaren `/* transient; keep polling */` (linje 189), og
`res.ok === false` falder igennem til samme sted. En 403 fra et roteret secret, en
netværksfejl og en række der endnu ikke er opdateret, giver alle tre det samme udfald:
tre forsøg, `false`, og beskeden *"Capture requested — figures will refresh on the next
sync."*

Beskeden er ikke forkert — hævningen *lykkedes*, det er kun opdateringen der mangler —
men et vedvarende brudt poll-endpoint er usynligt for købmanden. Efter et reset er det
netop scenariet: secret'et er roteret (`wp-ajax-wc-scanpay-reset.php`), og et faneblad
der stadig står åbent med det gamle payload i `window.ScanpayOrderData` vil 403'e i
tavshed.

**Udledt.** Lav prioritet — selvhelbredelsen sker ved næste sideload. Rettelsen kræver en
tri-state returværdi og **et nyt msgid**, altså katalogpåvirkning.

### 6. Endpoint-fallbacken står tre gange

`order.ts:174`, `subs.ts:28`, `compat.ts:40`

Alle tre har `endpoint || '../wp-scanpay/fetch'` og en 3-5 linjers forklaring af hvorfor.
`order.ts` har siden fået en fjerde linje om hvorfor konstanten er flyttet ind i
funktionen, så de to admin-kommentarer er ikke længere ordret ens — men grunden de
forklarer er den samme, og ændres fallback-stien skal tre steder rettes. Alle tre ligger
under `admin/`, så én delt eksport ville dække.

**Smagssag, ikke defekt.** Filen-er-modulet-princippet gør en vis duplikering til et
bevidst valg, og guiden siger det eksplicit. Nævnt fordi de tre kommentarer kan drive
fra hinanden, ikke fordi koden er forkert.

### 7. `registerPaymentMethod: any`

`types/checkout.d.ts:30`. Det mest konsekvensfyldte kald i public-bundlen er utypet.
`eslint.config.mjs:40` slår `no-explicit-any` fra med kommentaren `// allow any tmp`,
dateret 2024-09-11 i filhovedet. Nævnt her fordi "tmp" nu er knap to år gammel, ikke
fordi denne ene skal rettes først.

Samme fil har `wcSettings.getSetting: (key: string) => any` (linje 42), som er den anden
ende af den utypede grænseflade: `checkout.ts:13` caster resultatet til
`WooPaymentMethodData` uden nogen kontrol af at payload'et overhovedet kom med.

**Ingen fejlsituation.** Det er en note, ikke en defekt — og den snævre rettelse (type
netop de felter `checkout.ts` sender) rører ikke den egentlige opgave, som er at slå
`no-explicit-any` til igen. Det ville også ramme `dispatch`, `useSelect` og `getSetting`.

---

## Kontrolleret og bevidst ikke rapporteret

Så næste review ikke bruger tid på det igen.

- **Secret'et i `window.ScanpayOrderData` og i `data-secret`.** Afgjort i CLAUDE.md
  ("The admin-AJAX secret is unscoped, and stays that way"), med begrundelsen at
  ordreredigeringsadgang på en WooCommerce-shop er alt-eller-intet. Ikke flagget.

- **Terms-checkboxen når vores egne gateways er slået fra.** Så først ud til at falde
  bort med dem, siden `$data['terms']` rejser i betalingsmetodens payload. Det gør den
  ikke: `get_payment_method_data()` sætter nøglen før gatewayløkkens `is_available()`-gate,
  og
  `AbstractPaymentMethodType::is_active()` defaulter til `true`, så handlet registreres
  uanset. Kommentaren på stedet siger det allerede. **Cart-level-invarianten holder.**

- **Vilkårslinket ligger inde i `<label>`, så et klik på det også vipper
  afkrydsningsfeltet.** Gælder begge renderers. Det er ikke vores afvigelse: WooCommerce
  gør nøjagtig det samme klassisk (`templates/checkout/terms.php:29-32`) og i Blocks,
  hvor `CheckboxControl` wrapper sine children i `<label htmlFor>`
  (`packages/components/checkbox-control/index.tsx:70-98`). At rette det ville kun gøre
  os inkonsistente med værtens egen vilkårsboks. **Ikke flagget.**

- **Vi håndruller `<label><input>` i `checkout.ts`,** hvor både core og WooCommerces
  officielle scaffold til tredjeparts-checkoutblokke bruger `CheckboxControl` fra
  `@woocommerce/blocks-checkout` — som allerede er indlæst hos os, siden
  `registerCheckoutBlock` hentes fra `window.wc.blocksCheckout`. Et skifte ville give
  cores styling plus dens `hasError`/`aria-describedby`-kobling gratis og fjerne vores
  håndrullede `wc-block-components-validation-error`-markup (`checkout.ts:148`). En
  mulig forbedring, ikke en defekt — derfor noteret her frem for som punkt.

- **`subs.ts:76-79` — `rev=0` og long-poll.** Kommentaren siger, at `rev=0` returnerer
  rækken uden at holde forbindelsen. Det så først forkert ud, fordi
  `wp-scanpay-fetch-sub.php` long-poller på `$rev >= $sub['rev']`, hvilket ville være
  sandt for en række med `rev = 0`. Men `class-wc-scanpay-sync.php` afviser `rev <= 0`
  med en exception, så `scanpay_subs.rev` er altid ≥ 1. **Kommentaren er korrekt.**

- **`#wcsp-set-alert`-castet uden nullcheck** (`settings.ts:165-166`). `as HTMLElement`
  efterfulgt af en dereference. Sikkert: `settings.js` enqueues kun på pluginets egne
  settings-skærme (`settings.php:33-36`, gated på `wc_scanpay_is_settings_screen()`), og
  `admin-options.php:128-134` udskriver elementet ubetinget på dem alle.

- **Keep-alive-`\n` før JSON-body'en.** `wp-scanpay-fetch-meta.php` og
  `wp-scanpay-fetch-sub.php` skriver `"\n"` per runde *før* `wp_send_json()`, så body'en
  er `"\n\n\n{…}"`. `JSON.parse` springer indledende whitespace over per specifikation,
  så `res.json()` klarer det. Ikke et problem.

- **`insertAdjacentHTML`/`innerHTML` med oversatte strenge** (`settings.ts:40`,
  `meta.ts:102`). Hvert eneste argument er en katalogstreng, og alt dynamisk går ind som
  tekstnode — `err.message` gennem `detail` (`settings.ts:42`), versionsnummeret gennem
  `span.textContent` (`settings.ts:222`, `meta.ts:108`). Oversætter-leveret markup i
  `__()` er standardmønsteret i WordPress, og WooCommerce gør selv det samme i sin
  checkout-terms-blok. Ikke flagget.

- **`for (const name in data.methods)`** (`checkout.ts:29`). `for...in` over et objekt
  fra `JSON.parse` har ingen nedarvede enumerable properties. Sikkert.

- **`applepay.ts`s `blocked`-flag.** Vagten er asymmetrisk (linje 45): deaktivering slår
  altid igennem, aktivering kun når scriptet selv deaktiverede. Det dækker begge de
  tilstande der er i spil — en knap udskiftet af `updated_checkout` bliver gendeaktiveret,
  og en deaktivering WooCommerce selv har foretaget bliver ikke fortrudt. **Korrekt som
  den står.**

- **`applepay.js`s dependencies.** `['jquery', 'wp-i18n']`
  (`class-wc-gateway-scanpay-applepay.php:59`) plus `wp_set_script_translations()`
  (`:63`). Modultopniveau-destruktureringen af `window.wp.i18n` er dækket. (Mønstret er
  stadig §2 — men afhængigheden er der.)

- **`ExtensionData` ryddes ikke ved unmount** (`checkout.ts:117-118`). Cleanup-funktionen
  rydder valideringsfejlen, men ikke `setExtensionData('scanpay', …)`. Uden konsekvens:
  `wcs_scanpay_blocks_validate_terms()` udleder selv fra kurven om samtykket overhovedet
  kræves, og afviser i Store API'et. Serveren er autoriteten. Ikke flagget.

- **ES2020-syntaks uden `--target` på esbuild.** `build.sh:40-50` sender hverken
  `--target` eller `--format`, så esbuild default'er til `esnext` og sender `?.` og `??`
  uændret igennem. Understøttet i alle browsere siden 2020. Værd at vide, at der ikke
  findes et erklæret browser-mål nogen steder, men det er ikke en fejl i 2026.

---

## Opsummering

Syv punkter tilbage, og listen har ændret karakter: efter de seks rettelser er der kun
ét tilbage, hvor koden gør noget forkert på skærmen — UTC-datoen. Resten er invarianter
der holder af tilfældigheder (§2, §3) eller oprydning.

To af de syv er strengt taget ikke defekter. §6 er en smagssag, som guiden allerede har
taget stilling til i den anden retning, og §7 har ingen fejlsituation. Trækkes de fra,
er der fem reelle punkter.

Prioriteret rækkefølge, hvis der kun er tid til noget af det:

1. **§1** — UTC-datoen i abonnementsboksen. Det eneste sted brugeren ser noget forkert.
2. **§2** — i18n-tvillingen. Elleve linjer mod en fejl der ellers vil ramme tavst næste
   gang en public-fil oversætter.
3. **§3** — de tre msgids med mellemrum i kanten, mens kataloget stadig er rigtigt.
4. **§5** — de tre udfald i `refresh()`, hvis der alligevel skal røres ved kataloget.
5. **§4** — valutaformateringen. Størst, og rent kosmetisk.

§6 og §7 ville jeg lade ligge.
