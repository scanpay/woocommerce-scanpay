# PHP-review af `src/` — commit `095fa64` + uncommitteret arbejdstræ

Et nyt, uafhængigt gennemløb af alle 35 PHP-filer i `src/` (5.953 linjer), fordelt
på seks parallelle reviewere med hver sit felt, hvorefter hver finding er
efterprøvet en gang til mod upstream-kilden.

**Ingen kode er rettet i denne omgang.** Dokumentet er ren rapport.

Reviewet dækker **arbejdstræet, ikke HEAD**: `src/library/class-wc-scanpay-sync.php`
bærer den uncommitterede `save_or_report()`-rettelse fra det forrige review. To af
fundene nedenfor (6 og 10) rammer netop den rettelse.

Dette dokument **erstatter** den forrige `RESULTS.md`. Status på dens tre findings:

| Forrige finding | Status nu |
|---|---|
| 1 — ubeskyttede `save()` i sync | Rettet i arbejdstræet, men **rettelsen er ufuldstændig** → fund 6 og 10 |
| 2 — 2.1.3-migrationen springes over uden WCS | **Står åben**, og har nu en langt værre tvilling → fund 1 |
| 3 — `get_payment_method()` på `WC_Order_Refund` | **Står åben**, uændret. Gentages ikke her |

`AGENTS.md` § *Settled*, `docs/requirements.md`, `docs/performance-review.md`,
`docs/ts-review.md`, `docs/scss-review.md` og begge `HANDOFF`-filer er læst først og
behandlet som uden for scope.

**Verdict-skalaen:** *Bekræftet* = mekanismen er bevist i koden, og jeg har selv læst
upstream-kilden efter. *Sandsynlig* = mekanismen holder, men konsekvensen hviler på en
antagelse, som er navngivet ved fundet.

---

## 1. `upgrade.php:158` kalder `wcs_get_subscription()` før WooCommerce har en order factory — hele opgraderingen kiler fast for evigt

**Fil / symbol:** `src/upgrade.php:158` i 2.1.3-grenen
**Verdict: Bekræftet.** Reviewets alvorligste fund.

```php
$wc_sub = wcs_get_subscription( $oid );   // :158
```

`upgrade.php` køres fra `wc_scanpay_plugins_loaded()` (`woocommerce-scanpay.php:481`),
altså på **`plugins_loaded` prioritet 10**. Men `wcs_get_subscription()` dereferencerer
factory'en helt uden guard:

```php
$subscription = WC()->order_factory->get_order( $the_subscription );
```
— `subscriptions-core/wcs-functions.php:86`

og `WC()->order_factory` er `null` indtil **`init` prioritet 0**:

- `class-woocommerce.php:143` — `public $order_factory = null;`
- `class-woocommerce.php:330` — `add_action( 'init', array( $this, 'init' ), 0 );`
- `class-woocommerce.php:964` — `public function init() {`
- `class-woocommerce.php:978` — `$this->order_factory = new WC_Order_Factory();` ← eneste tildeling i hele træet

Resultatet er `Error: Call to a member function get_order() on null`.

Til sammenligning har `wc_get_order()` netop den guard, som `wcs_get_subscription()`
mangler (`wc-order-functions.php:90-93`: `did_action( 'woocommerce_after_register_post_type' )`).
Grenens eget `wc_get_orders()`-kald på `:136` overlever, fordi `'return' => 'ids'`
aldrig instantierer et ordreobjekt — så løkken *nås*, og fataler først på første
iteration.

**Fejlscenariet, og hvorfor det ikke standser ved én fejl.** Kæden er selvopretholdende:

1. `Error` fanges af loaderens `catch ( Throwable )` (`woocommerce-scanpay.php:483`).
2. Transienten **bevares bevidst** ved fejl (`:485-488`) — den throttler til ét forsøg
   hvert 5. minut.
3. `upgrade.php:259` stempler versionen **sidst** og nås derfor aldrig.
4. Porten åbner igen 5 minutter senere. I det uendelige.

Og fordi migrationerne kører sekventielt i én fil, nås **ingen senere gren nogensinde**:

- `< 2.5.0` sætter `wc_autocapture`. Kører den ikke, matcher
  `wc_scanpay_order_status_completed()` (`:174`) aldrig `'completed'`, og **autocapture
  er tavst slået fra permanent**.
- `< 3.0.0` dropper `NOT NULL`-kolonner i `scanpay_meta`. Kører den ikke, fejler hvert
  v3-insert under strict SQL mode (MySQL 1364), cursoren kan ikke rykke, og **shoppen
  registrerer ingen betalinger overhovedet**. Præcis den konsekvens står allerede
  skrevet i kommentaren på `upgrade.php:209-217` — grenen er bare uopnåelig.

**Komplementariteten med den åbne finding 2 fra forrige review er det virkelig ubehagelige.**
Samme gren, modsat betingelse:

- WCS **inaktiv** på den request der krydser porten → grenen springes permanent over (forrige finding 2).
- WCS **aktiv** og der findes 1.x-abonnementer → fatal `Error`, evig løkke (dette fund).

Grenen fuldfører altså kun korrekt, når WCS er aktiv **og** der intet er at migrere.

**Hvordan efterprøvet.** Jeg har selv læst `class-woocommerce.php:143`, `:330`, `:964`,
`:978` og bekræftet at `:978` er den eneste tildeling; `wcs-functions.php:78-93` for det
manglende værn; `wc-order-functions.php:86-95` for det tilsvarende værn i `wc_get_order()`.
Reviewerens modbevis-forsøg er gengivet og holder: `wc_get_orders()` bygger SQL uafhængigt
af typeregistrering i begge datastores, så løkken nås reelt.

**Forslag.** Flyt migrationsgaten fra `plugins_loaded` 10 til `init` prioritet 7 — efter
WC's `init`:0 (factory), WC's `init`:5 og WCS' `init`:6 (ordretyper). Kommentaren på
`woocommerce-scanpay.php:472-475` skal så skifte begrundelse fra "WC core er loadet" til
"ordretyperne er registreret". Mere kirurgisk alternativ: udskyd kun 2.1.3-løkken og lad
stemplingen ske derfra.

**Holdt op imod:** `AGENTS.md` § *Settled* ("Migrations stamp the version last" — som
handler om afbrudte kørsler og her er præcis det, der gør løkken evig). Ikke tidligere
rapporteret.

---

## 2. Thankyou-gaten er en uautentificeret "sluk pluginnet for denne request"-kontakt — og den omgår Blocks-validering af abonnementsvilkår

**Fil / symbol:** `src/woocommerce-scanpay.php:109-112`, sekundært `:98-100`
**Verdict: Bekræftet mekanisme og reachability.** Ikke kørt mod en levende shop.

```php
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'], $_GET['key'] )
     && in_array( $_GET['scanpay_type'], [ 'wc', 'wcs', 'wcs_free' ], true ) ) {
	require WC_SCANPAY_DIR . '/public/wp-scanpay-thankyou.php';
	return;
}
```

Gaten dispatcher udelukkende på **parametre**, aldrig på hvilken slags request det er.
`?scanpay_thankyou=0&scanpay_type=wc&key=` opfylder den (`isset` er sand for `"0"` og
`""`), og inde i handleren giver `absint( '0' )` → `0` → `return` på
`wp-scanpay-thankyou.php:102`. Ingen DB-forespørgsel, intet output — en helt tavs
kontakt, der springer resten af bootstrappet over.

Alt registreret **efter** gaten går tabt for den request: `plugins_loaded`-loaderen
(gateways, migrationer, capture, WCS-hooks, Blocks), `before_woocommerce_init`,
`init`:0, `admin_init`:0, `admin_menu`:999.

**Fejlscenariet.** `wcs_scanpay_blocks_validate_terms()` registreres kun på `:518`, inde
i loaderen. Dens egen docblock (`:296-301`) siger at den findes *"so a crafted request
cannot bypass acceptance"*, og `:283` at *"Blocks is unaffected … and
wcs_scanpay_blocks_validate_terms() stays strict."*

En kunde med et abonnement i kurven POSTer Store-API-checkoutet til
`/wp-json/wc/store/v1/checkout?scanpay_thankyou=0&scanpay_type=wc&key=`. Pluginnet
returnerer i gaten, `woocommerce_store_api_checkout_update_order_from_request` har ingen
listener, og **abonnementsvilkårene accepteres aldrig — checkoutet går igennem**. WP's
REST-lag afviser ikke ukendte query-parametre.

For klassisk checkout er tabet mindre: `wcs_scanpay_validate_terms()` kræver allerede
markøren `wcssp-terms-field` i POST'en (`:287`), og den svaghed er dokumenteret og
accepteret (`:280-283`). Blocks-vejen var netop den stramme halvdel — og det er den,
gaten åbner.

**Ping-gaten har samme form.** `str_ends_with( $uri, 'wc_scanpay' )` på `:98` køres på
hele `REQUEST_URI` **inklusive query string**, så `/?wc-ajax=checkout&x=wc_scanpay` plus
en vilkårlig `X-Signature`-header rammer også `return`. Kommentaren på `:82-83` — *"the
rest of the bootstrap is skipped only when the URI really is that endpoint"* — er derfor
ikke sand.

**AJAX-gaten (`:137-149`) er til gengæld stram**, og det er værd at sige eksplicit: alle
tre endpoints terminerer selv, og en ukendt `x`-værdi giver `null` fra `match` og falder
igennem til normal load.

**Forslag.** Snævr de to gates ind på requestens art — én betingelse hver:

- Thankyou: kræv `'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! defined( 'DOING_CRON' )`.
  Et ægte betalingsreturn er altid en GET-redirect; både klassisk checkout og Store API er POST.
- Ping: tilføj `'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )` til `:98`, og ret
  kommentaren på `:82-83` til at beskrive, hvad koden faktisk matcher.

---

## 3. MobilePay og Apple Pay kan oprette abonnements-subscribere via order-pay-siden

**Fil / symbol:** `src/gateways/class-wc-gateway-scanpay-mobilepay.php:42-47`,
`class-wc-gateway-scanpay-applepay.php:89-94`, sammen med
`src/public/generate-payment-link.php:121-127` og `:286-300`
**Verdict: Bekræftet** for kodestien. Konsekvensen hos Scanpay er *sandsynlig* — den
hviler på, at `/v1/new` med `subscriber.ref` og `?go=mobilepay` gør noget andet end et
kort-subscriber.

Alle tre gateways kalder den samme funktion, og den modtager **ingen gateway-identitet**:

```php
function wc_scanpay_process_payment( int $oid, array $settings ): array {   // :76
```

Abonnementsstatus afgøres udelukkende af ordren — `_scanpay_subid` → `renew()` (`:121-127`)
og `WC_Subscriptions_Product::is_subscription()` → `$data['subscriber']` (`:286-300`).
Der findes intet `supports()`-tjek nogen steder i kaldet, og kun kort-gatewayen erklærer
`subscriptions` (`class-wc-gateway-scanpay-card.php:17`; basisklassen sætter `['products']`).

**Den eneste håndhævelse er WCS' filter — og det springer order-pay eksplicit over:**

```php
// We don't want to filter the available payment methods while the customer is
// paying for a standard order via the order-pay screen.
if ( is_wc_endpoint_url( 'order-pay' ) ) {
	return $available_gateways;
}
```
— `subscriptions-core/…/class-wc-subscriptions-core-payment-gateways.php:79-82`

Og `WC_Form_Handler::pay_action()` henter netop gatewayen derfra
(`class-wc-form-handler.php:530`) og kalder `process_payment()` (`:544`).

**Fejlscenariet.** Butik med abonnementer og MobilePay slået til. En kunde har en
pending/failed ordre med et abonnementsprodukt. Kunden går til Min konto → Ordrer →
"Betal" og vælger MobilePay. Payloaden får `subscriber.ref = 'wcs[]N'`, og
betalingsvinduet åbnes med `?go=mobilepay`. Enten registreres et MobilePay-subscriber,
som `WCS_Scanpay_Charge` derefter trækker fornyelser på — et flow pluginnet ikke erklærer
at understøtte — eller Scanpay afviser, og kunden får en fejl på en gateway, butikken selv
viste dem.

Variant på samme sti: en fejlet fornyelsesordre bærer `_scanpay_subid` (gateway-agnostisk
meta), så `:124` rammer `renew()`-grenen. Kunden klikker "MobilePay", havner på en
**kortopdaterings**side med `?go=mobilepay` klistret på, og får ordrenoten fra `:177-180`
("Your payment details were updated"). Ingen penge flyttes, og noten er forkert.

**Blocks og klassisk checkout er ikke ramt** — begge validerer det valgte betalingsmiddel
mod `get_available_payment_gateways()`, hvor WCS-filteret kører normalt.

**Hvordan efterprøvet.** Jeg har selv læst WCS' early return, `pay_action()`-kæden, og
bekræftet med grep at pluginnet hverken har en `is_available()`-override eller et eget
`woocommerce_available_payment_gateways`-filter.

**Forslag.** Giv `wc_scanpay_process_payment()` en typet parameter frem for en defensiv
`if` — huset foretrækker den form: `wc_scanpay_process_payment( int $oid, array $settings, bool $subs )`.
Kort-gatewayen sender `true`, de to andre `false`. Ved `false` kastes en oversat
`Exception` i `:124`- og `:286`-grenene; `pay_action()` fanger den og viser den som
notice. **Degradér ikke** (dropp `subscriber`-refen og opkræv som almindelig ordre) — så
ville fornyelserne fejle tavst for altid.

---

## 4. REST- og CLI-stien slår gateways til uden nogen nøglevalidering og kasserer indstillinger tavst

**Fil / symbol:** `src/gateways/abstract-wc-gateway-scanpay-base.php:54-58`
(`init_form_fields()` er tom), i samspil med `:70-85`
**Verdict: Bekræftet.**

`init_form_fields()` er bevidst tom, og `get_form_fields()` er den dovne loader. Men
WooCommerces REST-controllere kalder `init_form_fields()` og læser derefter **egenskaben
`$gateway->form_fields` direkte** — de kalder aldrig `get_form_fields()`. Jeg har verificeret
kaldstederne:

- `Version3/class-wc-rest-payment-gateways-controller.php:75`
- `Version2/class-wc-rest-payment-gateways-v2-controller.php:170`, `:295`
- `Internal/RestApi/Routes/V4/Settings/PaymentGateways/Controller.php:230`
- `…/Schema/AbstractPaymentGatewaySettingsSchema.php:198`, `:238`, `:485`, `:559`

Med tom `form_fields` itererer `foreach ( $gateway->form_fields as $key => $field )` i
`update_item()` over ingenting: **hver posted `settings`-værdi kasseres tavst**, og svaret
rapporterer succes.

Værre for pengesiden: `update_item()` behandler `enabled` **uden for** den løkke
(`:197-200`) og skriver optionen direkte med `update_option()` (`:216`) — altså **uden**
`process_admin_options()`. Pluginnets `validate_apikey_field()`, nøglevalideringen mod
`seq(0)` og force-disable-stien kører aldrig ad den vej, og `needs_setup()` konsulteres
heller ikke.

**Filens egen docblock leverer modargumentet.** `:26-28` siger:

> *"it is the properties, not our getters, that inherited `is_available()`, **the REST
> controllers**, the CLI and the tracker read."*

Præmissen er altså erkendt — men kun fulgt op for `$enabled`, `$title` og `$description`,
ikke for `$form_fields`. Kommentaren på `:54-58`, der kalder tomheden bevidst, er dermed
drevet fra koden.

**Fejlscenariet.** En administrator, WooCommerce-mobilappen eller
`wp wc payment_gateway update scanpay --enabled=1` sender
`PUT /wp-json/wc/v3/payment_gateways/scanpay` med
`{"enabled": true, "settings": {"wc_autocapture": {"value": "off"}}}`. Gatewayen slås til
uden nogen nøglevalidering, `wc_autocapture` ændres ikke, og svaret melder `settings: {}`.
Har butikken ingen API-nøgle (fx efter reset), er gatewayen nu synlig i checkout, og hver
kunde der vælger den får "Error: The payment plugin is not configured".

**Forslag.** Flyt den dovne krop fra `get_form_fields()` ind i `init_form_fields()`, og
lad `get_form_fields()` kalde den. Dovenskaben bevares fuldstændigt — konstruktøren kalder
ikke `init_form_fields()`, og WooCommerce kalder den kun fra de stier, der vil have
felterne.

---

## 5. Prioritet 5 på `woocommerce_order_status_completed` forhindrer ikke det, kommentaren lover

**Fil / symbol:** `src/woocommerce-scanpay.php:499-510`
**Verdict: Bekræftet.** Fundet uafhængigt af to reviewere med forskellig indgang — én via
status-maskinen på tværs af filer, én via `capture_or_hold()`s egen kaldsvej.

Kommentaren skriver:

> *Capturing first means a failure parks the order 'on-hold' **before the customer is
> mailed "completed" and granted download permissions for goods that were never paid
> for**. WooCommerce's own PayPal gateway captures at the default 10, after both […];
> ours is deliberately stricter.*

**Mekanismen.** `do_action( 'woocommerce_order_status_completed', … )`
(`class-wc-order.php:457`) gennemløber **hele** callback-listen. At vores callback på
prioritet 5 kalder `update_status( 'on-hold' )` afbryder ikke `do_action` — prioritet 10
og 11 kører bagefter uanset. Og ingen af lytterne genlæser statussen. Jeg har verificeret
begge de vigtigste:

- `WC_Email_Customer_Completed_Order::trigger()`
  (`emails/class-wc-email-customer-completed-order.php:64-81`) har **ingen** statuskontrol
  — den kalder `send_notification()` betingelsesløst.
- `wc_maybe_reduce_stock_levels()` (`wc-stock-functions.php:104-123`) kontrollerer **ingen**
  status overhovedet, kun `get_stock_reduced()` — og er desuden selv hooked på
  `woocommerce_order_status_on-hold` (`:127`), så vores egen indlejrede transition
  nedskriver lageret først.
- `wc_downloadable_product_permissions()` (`wc-order-functions.php:465-489`) kontrollerer
  kun `has_status( PROCESSING )`; `on-hold` passerer, og downloads tildeles.

Prioritet 5 køber altså rækkefølge i tid, ikke forhindring. Det eneste, den reelt køber,
er at et *vellykket* capture er gennemført, når mailen komponeres.

**Fejlscenariet.** `wc_autocapture = 'completed'`. Fysisk ordre, 500 DKK autoriseret.
Købmanden sætter status til Completed. Capture-kaldet timer ud (klientens budget er 20 s).
Ordren står `on-hold`, og kunden har alligevel fået "din ordre er gennemført",
downloadadgang, lagernedskrivning, salgstal og kuponforbrug — for penge der aldrig blev
hævet. Notesporet bliver desuden misvisende, fordi den ydre `status_transition()` skriver
sin *"Order status changed from Processing to Completed"* **efter** `do_action`.

**De to admin-stier gør det rigtigt** og er upåvirkede: `wp-ajax-wc-mark-order-status.php:96, 116-120`
og `wp-bulk-actions.php:19, 64-68` fjerner hooken og capturer *før* `set_status`, altså
uden for `do_action`-kæden.

**Forslag.** Koden kan ikke levere påstanden fra en post-transition-hook, så det er
kommentaren, der skal rettes — den er testen. Skriv hvad prioritet 5 faktisk køber, og hvad
den ikke køber. Udvid desuden on-hold-noten i `class-wc-scanpay-capture.php:191-196` med, at
gennemførelses-sideeffekterne allerede er kørt — det er den eneste kanal, købmanden læser.

---

## 6. `set_status()` i sync ligger uden for den indeslutning, den nye `save_or_report()` skulle give

**Fil / symbol:** `src/library/class-wc-scanpay-sync.php:555-560` (`subscriber()`)
**Verdict: Bekræftet.** Rammer den uncommitterede rettelse.

```php
$parent->set_status( 'completed', __( 'Subscription initiated without payment.', … ), true );
// The widest third-party surface in the drain: set_status() only queues the
// transition, so it is this save() that runs it -- …
$this->save_or_report( $parent, … );
```

**Kommentaren er faktuelt forkert for netop den kaldsform.** `WC_Order::set_status( $new_status, $note, $manual_update )`
udfører synkront, *inde i* `set_status()`, og har **ingen** try/catch:

- `class-wc-order.php:330-332` — `if ( $manual_update ) { do_action( 'woocommerce_order_edit_status', … ); }`.
  Kaldstedet sender netop `true` som tredje argument.
- `:334` — `maybe_set_date_paid()`, som på `:366` anvender
  `apply_filters( 'woocommerce_payment_complete_order_status', … )`. Filteret rammes, fordi
  `date_paid` er tom på præcis denne ubetalte forældreordre.

Vagten på `:322` er opfyldt: ordren er læst fra DB, og `pending` → `completed`.

Kun `WC_Order::save()` ligger inde i `save_or_report()`. Indeslutningen begynder altså
**én linje for sent**, og de callbacks `set_status()` selv afsender er udækkede.

**Fejlscenariet** er nøjagtigt det, helperen blev skrevet for at forhindre: en `Error` fra
et tredjeparts-callback på `woocommerce_order_edit_status` — en bredt lyttet action, WC
core fyrer den selv fra `class-wc-ajax.php:676` — slipper ud af `set_status()`, ud af
`subscriber()`, ind i drainens `catch ( Throwable )` (`wc-scanpay-ping.php:375`) → 500.
Cursor-`UPDATE`'et kører aldrig, Scanpay serverer samme seq-side på hvert 5-minutters
keepalive, og **ingen ordre i shoppen synkroniserer igen**, indtil tredjepartens hook fjernes.

**Forslag.** Træk transitionen ind i den try, der allerede findes — fx ved at give
`save_or_report()` en valgfri `?string $status` og sætte statussen inde i try'en. Bemærk at
det samtidig dropper `$manual_update = true`, hvilket er den rigtige værdi her uanset: dette
er en baggrunds-drain, ikke en administrator der redigerer ordren. Det er en adfærdsændring
(`woocommerce_order_edit_status` fyrer ikke længere herfra) — træf den bevidst.

---

## 7. `wc_autocapture` har to forskellige defaults: producenterne siger `'completed'`, forbrugerne siger `''`

**Fil / symbol:** `src/public/generate-payment-link.php:91` og
`src/library/class-wcs-scanpay-charge.php:282` (begge `?? 'completed'`) mod
`src/woocommerce-scanpay.php:174`, `src/admin/orders.php:12` og
`src/admin/hooks/wp-ajax-wc-mark-order-status.php:105` (alle `?? ''`)
**Verdict: Mekanisme bekræftet.** Rækkevidden er *sandsynlig* — den hviler på, at
settings-optionen kan eksistere som array **uden** nøglen.

Feltdefinitionens default er `'completed'` (`admin/settings/fields/scanpay.php:78`).

Med nøglen fraværende læser betalingslink-bygningen `'completed'` → for en fysisk ordre
bliver payloaden `autocapture: false`, altså "Scanpay autoriserer, vi hæver lokalt ved
gennemførelse". De tre steder, der *skal* hæve lokalt, læser `''`, og `'completed' !== ''`
→ de returnerer med det samme. **Ingen capture, ingen log-linje, ingen note.**

**Tilstanden er nåelig, og migrationen skriver den selv.** `upgrade.php:56-71` genopbygger
optionen med `capture_on_complete` og **uden** `wc_autocapture`; nøglen tilføjes først af
grenen på `:193-206`. Imellem dem ligger 2.1.3-grenen — som ifølge fund 1 fataler
deterministisk. I det vindue kører hele shoppen med nøglen fraværende.

**Forslag.** Ret de tre forbrugere til `?? 'completed'`, så alle fem læsere deler
feltdefinitionens default. `! is_array( $settings )`-guarden bliver stående og dækker den
helt fraværende option.

---

## 8. Kontrakten "det næste ping afstemmer ordren automatisk" holder ikke for nogen ordre, hvis betaling er synkroniseret

**Fil / symbol:** `src/library/class-wc-scanpay-capture.php:141-145` og `:184-186` mod
`src/library/class-wc-scanpay-sync.php:261` og `src/woocommerce-scanpay.php:98-100`
**Verdict: Bekræftet.** Ligeledes fundet uafhængigt af to reviewere.

Kontrakten siger: *"'on-hold' is in PAYMENT_COMPLETE_STATUSES, so the next ping reconciles
the order automatically and the merchant can safely retry."*

**To lag modsiger den.** `sync()` gater hele payment_complete-blokken på
`empty( $wco->get_transaction_id( 'edit' ) )` (`:261`). En ordre, capture overhovedet kan
køre på, har en `scanpay_meta`-række — og den række skrives i samme gennemløb, som sætter
`transaction_id`. Altså: rækken findes ⇒ `transaction_id` er sat ⇒ **intet senere ping
rører ordren igen**.

Selv i det ene kapløb, hvor et ping *kan* løfte ordren, sker der ikke noget capture:
ping-requesten `return`er på `woocommerce-scanpay.php:99`, så
`add_action( 'woocommerce_order_status_completed', … )` på `:510` **aldrig registreres**.
Sync kan genskabe statussen, men aldrig hæve pengene.

**Fejlscenariet.** 500 DKK autoriseret, capture fejler på en forbigående netværksfejl,
ordre parkeret `on-hold`. Ping kører hvert 5. minut i evighed uden at røre ordren.
Autorisationen udløber, og pengene er væk.

**Forslag.** Ret kontrakten i `:141-145`: et ping afstemmer kun en ordre, hvis betalingen
**endnu ikke er synkroniseret**. **Tilføj ikke retry-maskineri** — `AGENTS.md` § *Settled*
forbyder netop det, og manuelt retry er allerede dækket af remaining-amount-guarden.

---

## 9. En capture, der flytter penge, skriver ingen log-linje — og notens returværdi ignoreres

**Fil / symbol:** `src/library/class-wc-scanpay-capture.php:109-135` (`capture()`)
**Verdict: Bekræftet** for det manglende log-spor. Notens fejlvej er *sandsynlig* — den
afhænger af, hvor ofte en comment-indsættelse faktisk fejler.

Efter `self::$client->capture()` returnerer — *"The money moved when client->capture()
returned"*, `:116` — er den eneste holdbare lokale registrering `add_order_note()` på `:124`.
To ting mangler:

**1. Ingen log-linje på succes.** Filens seks `scanpay_log()`-kald ligger på `:105` (skip),
`:134` (noten fejlede), `:173` (memo), `:187`, `:204` og `:210` (fejlveje). **Ingen på den
vej, der flytter penge.**

Husets egen kode har allerede løst præcis dette problem ét sted — og skrevet begrundelsen
ned. `WCS_Scanpay_Charge::charge():348-354`:

> *"Every other outcome of a renewal writes a log line; the one that moves money wrote none.
> On a shop whose pings are blocked this is the only store-side record that the customer was
> charged."*

Argumentet gælder ordret for capture. Det er ikke overført.

**2. `add_order_note()`s returværdi ignoreres.** `try { … } catch ( \Throwable )` på `:133`
fanger kun kast. Men `WC_Order::add_order_note()` (`class-wc-order.php:2080`) returnerer `0`
for en ugemt ordre og ellers `wp_insert_comment()`s `int|false` — altså falsk **uden** at
kaste. Det er nøjagtig den form, som samme fil rapporterer for `update_status()` på
`:203-205`, med en kommentar om hvorfor den skal bemærkes.

**Fejlscenariet.** Bulk-handleren læner sig eksplicit på noten:
`admin/hooks/wp-bulk-actions.php:50-52` — *"only capture()'s note — written as soon as the
money moves — keeps that from being silent."* På en shop hvor pings er blokeret, og hvor
comment-indsættelsen fejler, bliver kunden opkrævet, ordren viser intet, WooCommerce-loggen
viser intet, og der findes **ingen store-side registrering overhovedet**. `capture_or_hold()`
returnerer `true`, og bulk-loopet gennemfører ordren.

**Forslag.** Én linje efter klientkaldet, spejlet på `charge():354`:

```php
scanpay_log( 'info', "captured $amount on order #$oid" );
```

Vil man også lukke den anden halvdel, testes notens returværdi som `:203-205` allerede gør
det for `update_status()`.

---

## 10. `save_or_report()`s returværdi er `true` også når skrivningen fejlede

**Fil / symbol:** `src/library/class-wc-scanpay-sync.php:521-529`
**Verdict: Bekræftet.** Rammer den uncommitterede rettelse.

`save_or_report()` returnerer `false` **kun når `save()` kaster**. Men
`WC_Abstract_Order::save()` fanger selv `Exception` (`abstract-wc-order.php:265`), router
den gennem `handle_exception()` og returnerer derefter `get_id()` normalt (`:278`).

Den almindelige skrivefejl — datastore-fejl, `WC_Data_Exception` — får altså
`save_or_report()` til at returnere **`true`**, `continue` springes over, og forælderen
fuldføres alligevel med et uskrevet `_scanpay_subid`. Vagten fanger kun `Error`-delmængden.

Docblocken er ærlig om det (*"The ordinary failure is already contained upstream, and only
that one"*), men kaldstedskommentaren læser som om vagten dækker uskrevne subid'er generelt:
*"an unwritten subid is its precondition failing"*. Den dækker et mindretal af dem.

**Sekundært (sandsynlig, kræver flere abonnementer på én ordre):** løftet *"leaving the
parent pending keeps the half-finished state"* holder kun, når den fejlende subscription er
den eneste eller den første i `$subs`. `find_subs_from_ref()` kan give flere id'er, og
søskende deler forældreordre — går sub 12 igennem og sub 13 i stykker, har iterationen for
12 allerede sat forælderen til `completed`.

**Forslag.** `continue`-semantikken er korrekt (det er `continue 1` i den eneste løkke) —
det er kommentaren, der skal sige, hvad vagten faktisk er. Skal løftet holde i stedet, er den
mindste rigtige rettelse at læse `_scanpay_subid` tilbage fra en frisk `wcs_get_subscription()`
før forælderblokken.

---

## 11. `is_available()` spørger aldrig, om API-nøglen findes

**Fil / symbol:** `src/gateways/abstract-wc-gateway-scanpay-base.php:377-380`
(`needs_setup()`); ingen `is_available()`-override
**Verdict: Bekræftet.**

`needs_setup()` kender den rigtige betingelse — tom `apikey` — men bruges kun ét sted i
WooCommerce: AJAX-toggle'en i Payments-listen (`class-wc-ajax.php:4191`).
Checkout-udvælgelsen går gennem den nedarvede `is_available()`
(`abstract-wc-payment-gateway.php:346-357`), som kun ser `'yes' === $this->enabled`. Der er
ingen kobling.

Pluginnet lukker de fleste døre selv, og det er gjort omhyggeligt (reset sætter
`enabled => 'no'` på alle tre optioner; `process_admin_options()` force-disabler ved ugyldig
nøgle; migrationen bærer `enabled` og `apikey` frem sammen). De resterende døre er REST/CLI-stien
fra fund 4 og enhver direkte option-manipulation.

**Forslag.** Én metode i basisklassen, som genbruger det prædikat der allerede findes:

```php
/** Never offer a gateway that cannot reach the API: needs_setup() owns that question. */
public function is_available(): bool {
	return parent::is_available() && ! $this->needs_setup();
}
```

Det lukker samtidig fund 4's checkout-symptom.

---

## 12. Betalingsreturssiden giver en kunde et permanent håndtag på ~3,5 s workertid per request

**Fil / symbol:** `src/public/wp-scanpay-thankyou.php:106-138`
**Verdict: Bekræftet mekanisme.** Konsekvensen er *sandsynlig* — den afhænger af
workerpool-størrelsen.

Ejerskabsgaten (`:120-126`) er korrekt bygget: `hash_equals` mod ordrenøglen, ikke-tom-krav
på den lagrede nøgle, og den kører **før** enhver ventetid. Ordreoptælling er ikke mulig.
Det er ikke problemet.

Problemet er, hvad gaten ikke dækker: den beskytter kun mod **andres** ordrer. Løkken kører
til `transaction_id` er skrevet eller 17 iterationer — sumtid ≈ **3.527 ms**, hvilket
matcher kommentarens "~3.5s" præcist.

En kunde med en egen ubetalt Scanpay-ordre opfylder de tre betingelser på ubestemt tid —
også efter at WooCommerce annullerer ordren, for hverken `payment_method` eller
`transaction_id` ændres. Thank-you-URL'en er dermed et permanent håndtag, der koster 3,5 s
workertid per request, uden rate limit. `usleep()` tæller ikke mod `max_execution_time` på
Unix, så værtens timeout afbryder ikke polleren.

**Forslag.** Tilføj én betingelse i ejerskabsgaten: poll kun for ordrer, der plausibelt lige
er kommet retur fra betalingsvinduet — fx `WC_SCANPAY_URI_PTIME` inden for de sidste par
minutter. Konstanten findes allerede (`woocommerce-scanpay.php:34`). En genbesøgt eller
annulleret ordre falder så igennem til øjeblikkelig render.

---

## 13. `< 2.0.0`-migrationsgrenen er ikke idempotent ved retry

**Fil / symbol:** `src/upgrade.php:56-71`
**Verdict: Bekræftet.**

Grenen genopbygger hele settings-optionen fra `$old`. `secret` er eksplicit beskyttet mod en
afbrudt gen-kørsel (`:67-69`) — forfatteren har tænkt scenariet igennem. Men samme ræsonnement
er ikke anvendt på resten:

```php
'wcs_complete_renewal' => $old['autocomplete_renewalorders'] ?? 'no',
'wc_complete_virtual'  => 'no',
'wcs_complete_initial' => 'no',
'stylesheet'           => 'yes',
```

Kildenøglen `autocomplete_renewalorders` skrives ikke ind i `$arr`, så ved anden gennemløb er
den væk, og `wcs_complete_renewal` falder tilbage til `'no'`. De tre andre er hårdkodede.

**Fejlscenariet.** Enhver `throw` efter `:71` og før stemplingen på `:259` udløser fuld
gen-kørsel — hvilket fund 1 garanterer. Forsøg 1 migrerer `'yes'`; forsøg 2 fem minutter
senere sætter den tilbage til `'no'`. Og fordi løkken er evig, omgøres hver indstilling
købmanden ændrer, inden for 5 minutter — uden log, uden note.

**Forslag.** Læs 3.x-navnet først, som `secret` allerede gør:
`$old['wcs_complete_renewal'] ?? $old['autocomplete_renewalorders'] ?? 'no'`, og tilsvarende
for de tre øvrige.

---

## 14. Ordrelistens "marker som gennemført" annoncerer en statusændring, der måske ikke skete

**Fil / symbol:** `src/admin/hooks/wp-ajax-wc-mark-order-status.php:106-109`
**Verdict: Bekræftet** som kodefaktum; fejlscenariet er *sandsynligt*.

```php
$wco->update_status( 'completed', '', true );          // ← retur kasseres
do_action( 'woocommerce_order_edit_status', $oid, 'completed' );   // ← fyres ubetinget
wp_safe_redirect( … );
```

`update_status()` kaster ikke: den returnerer `false` for en ugemt ordre
(`class-wc-order.php:403-405`) og fanger `Exception` fra `set_status()`/`save()`
(`:409-424`) og returnerer `false`.

**Asymmetrien findes ti linjer nede i samme fil:** søstergrenen `:116-120` gater sit
`do_action` på `capture_or_hold()`s resultat. Og husets mønster er eksplicit begge de andre
steder: `class-wc-scanpay-capture.php:202-205` og
`woocommerce-scanpay.php:390-394` behandler begge `false` som et førsteklasses udfald med en
kommentar om hvorfor. Dette er det eneste af tre kaldesteder, der kasserer returværdien.

Ved en DB-skrivefejl beholder ordren sin gamle status, men `woocommerce_order_edit_status`
fortæller alle lyttere — ERP-, fragt- og bogføringsintegrationer — at ordren blev gennemført.

**Forslag.** Husets mønster:

```php
if ( ! $wco->update_status( 'completed', '', true ) ) {
	scanpay_log( 'error', "Order #$oid was not completed: update_status() returned false" );
} else {
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
}
```

---

## 15. Uescaped formatstreng i admin-notice

**Fil / symbol:** `src/admin/settings/admin-options.php:35-39`, forbrugt på `:48`
**Verdict: Bekræftet.**

`wc_scanpay_admin_notice()` (`admin/settings.php:53-56`) echoer `$msg` råt under et
`phpcs:ignore`, hvis begrundelse er en eksplicit invariant: *"$msg is trusted, pre-escaped
HTML assembled by the callers"*. Der er præcis to kaldere, og alle strenge i begge bruger
`esc_html__()` eller `esc_url()` — **på nær én**:

```php
$setup_text = sprintf(
	__( 'To get started, please complete the setup using our %s.', … ),   // ← bar __()
	$guide_link
);
```

`$guide_link` er selv escapet; det er *formatstrengen*, der ikke er det. Den kommer fra
MO-katalogen, så en oversættelse med markup eksekveres i admin-konteksten — og netop
`$shopid === 0`-grenen rammer **hver eneste friske installation**, før nøglen er sat.

**Forslag.** `__(` → `esc_html__(` på `:37`. `esc_html()` rører ikke `%s`, så rettelsen er
adfærdsneutral.

---

## Mindre ting og konsistens

1. **`JSON_UNESCAPED_SLASHES` fjerner `</script>`-værnet** (`admin/orders.php:142-146`).
   Uden flaget koder `json_encode()` `/` som `\/`, hvilket forhindrer element-breakout.
   Flaget er rent kosmetisk — `\/` afkodes identisk af `JSON.parse` — men det koster
   pluginnet hele dets forsvar mod en fremtidig `$props`-nøgle. Alle otte nuværende nøgler
   er gennemgået og kan ikke bære `<`; der er **ingen levende injektionssti i dag**. Ren
   hardening.

2. **`INT unsigned` mod validatorer, der kun bounder fortegn.** `scanpay_meta.id`,
   `scanpay_meta.shopid`, `scanpay_subs.subid` og `scanpay_seq.seq` er 32-bit, mens `sync()`
   kun validerer `is_int( $x ) && $x > 0`. Under strict SQL mode giver en værdi over
   4.294.967.295 fejl 1264 → `throw` → 500 → cursoren rykker aldrig. Uden strict mode klippes
   værdien i stedet, og `idempotency_key()` finder aldrig rækken → hver fornyelse fejler.
   Kan ikke afgøres herfra, om Scanpays id-rum kan nå derop.

3. **`scanpay_subs.method VARCHAR(64)` mod `ctype_alnum` uden længdegrænse**
   (`class-wc-scanpay-sync.php:479-481`). Samme fejlklasse (1406 under strict mode). Alle
   andre strengkolonner er implicit længdebundne af deres validator; `method` er undtagelsen.

4. **`'view'`-kontekst hvor resten af træet insisterer på `'edit'`.** To steder:
   `public/generate-payment-link.php:60` og `admin/subscriptions.php:16`. Reglen er skrevet
   ned mindst fem steder. Konsekvensen er lille, men afvigelsen er utilsigtet. *(Uændret fra
   forrige review.)*

5. **`callback/wc-scanpay-ping.php:23-24` fører `$settings['apikey']` videre uden
   `(string)`-cast** — det eneste sted i træet. Med en beskadiget option bliver `strstr()` en
   `TypeError` i toplevel-scope uden for `try`. *(Uændret fra forrige review.)*

6. **Underbetalings-noten gentages.** `class-wc-scanpay-sync.php:287-295` ligger inde i
   `empty( get_transaction_id() )`-grenen, og en underbetalt ordre får aldrig et transaction
   id — så hver ny revision tilføjer endnu en identisk note.

7. **To kald uden for `generate-payment-link.php`s egen indeslutning** (`:92`
   `needs_processing()` og `:287` `wc_scanpay_subref()`). Begge kører tredjeparts-filtre, som
   filens egen doktrin (`:229-238`) siger skal ligge inde i `try`. En `Error` bliver et hvidt
   skærmbillede i checkout i stedet for den oversatte besked på `:283`.

8. **`WC_Blocks_Utils::has_block_in_page()` mangler i `docs/requirements.md`**
   (`class-wc-gateway-scanpay-applepay.php:38-39`). Guarden er korrekt — klassen og metoden
   kom i samme release (WC 5.1.0) — men kommentaren siger "for the WooCommerce 3.6 floor",
   hvilket antyder det modsatte, og tabellen over guardede API'er over gulvet mangler rækken.

9. **Filhenvisning tvetydig** i prioritet-5-kommentaren (`woocommerce-scanpay.php:502-504`):
   `wc_update_total_sales_counts (:992)` og `wc_update_coupon_usage_counts (:1069)` står
   umiddelbart efter `(wc-stock-functions.php:125)`, men ligger i `wc-order-functions.php`.
   Linjenumrene er rigtige.

10. **`WC_Scanpay_Client::capture()` er den eneste API-metode, der ikke validerer sit svar**
    (`:191-193`). `new_url`/`renew` tjekker URL'en, `charge` kræver `type === 'charge'` og et
    `int id`, `seq` håndhæver monotoni. `capture` returnerer `request()` uændret, og
    `WC_Scanpay_Capture::capture():109-115` kasserer returværdien — så enhver 200 med gyldig
    JSON (en proxy-side, en "maintenance"-JSON) læses som "pengene er flyttet". Hviler på, at
    Scanpay kan svare 200 uden at have kapitaliseret; kan ikke afgøres herfra.

11. **`'index' => (int) $meta['nacts']` (`class-wc-scanpay-capture.php:113`) står
    ukommenteret.** Det er den eneste replay-beskyttelse, capture har — der er ingen
    `Idempotency-Key` på captures — og efter husets kommentardoktrin er netop "the failure the
    line prevents" det, der skal stå.

12. **`$item->get_quantity()` (`class-wcs-scanpay-charge.php:272`) mangler `'edit'`**, hvor
    resten af `charge()` insisterer på det. Kun kosmetisk i payloaden — mængden indgår ikke i
    beløbet — men afvigelsen er utilsigtet.

13. **`error_body()` slipper NUL, TAB og ANSI-escapes igennem.** Flerlinje og oversize
    maskeres korrekt (efterprøvet); kontroltegn gør ikke. Meget lav vægt.

---

## Efterprøvet og fundet i orden

Læst og specifikt undersøgt, uden noget at rapportere. De to første er **verificeret ved
kørsel**, ikke ved læsning:

- **`math.php` — 267.101 par-checks mod bcmath, nul afvigelser.** Hvert par kørte
  `addmoney`/`submoney`/`cmpmoney`/`money_equals`/`is_zero` mod bcmath, plus to
  orakel-uafhængige identiteter (`(a+b)-b == a` og kommutativitet). Dækningen var 2.401
  håndplukkede kanter (nul, negativt nul, `'-000.000'`, bæretog, int64-skinner, 20-cifrede
  heltal, 20 decimaler), 250.000 tilfældige med 1–22 heltalscifre × 0–12 decimaler, et
  udtømmende øre-sweep i `-500..500` mod heltalsaritmetik, og 14.700 par med *forskellig*
  decimallængde (alle kombinationer 0–6 × 0–6). Afvisningerne blev efterprøvet særskilt:
  `' 1'`, `'1 '`, `'+1'`, `'1e3'`, `''`, `'.'`, `'1.'`, `'.5'`, `'1.2.3'`, `"1\n"`, `"1\0"`,
  `'--5'`, `'1,5'`, `'0x10'`, `"\t1"`, `'٣'` m.fl. afvises alle, og hver får *alle fem*
  offentlige helpers til at kaste `InvalidArgumentException` i begge argumentpositioner. En
  separat kørsel med 300.000 iterationer og **enhver PHP-diagnostik forfremmet til exception**
  gav nul notices, warnings eller deprecations. `is_money()` på 1M+ cifre rammer PCRE's
  backtrack-grænse, men `1 === preg_match(...)` gør det **fail-closed**.
- **Capture-aritmetikken — 200.000 replays mod bcmath, nul afvigelser.** Hele kæden (total −
  WC-refusioner − (captured − refunded), klemt mod `authorized − captured`) ved variabel
  præcision i både ordre (0–4 decimaler) og DB (0–6). Invarianten "kapitalisér aldrig mere end
  autorisationen levner" holdt i alle. Klemmen på `:101-103` er det, der gør både "sync spejler
  ikke Scanpay-refusioner" og "købmanden bogførte en manuel WC-refusion" korrekte.
- **`class-wc-scanpay-client.php` — drevet mod en rigtig HTTP-server.** TLS-verifikation
  arver libcurls sikre defaults og kan ikke slås fra via php.ini (fail-closed). Redirects
  følges ikke — et 302 blev til `RuntimeException`, så `Authorization` kan ikke gensendes til
  en fremmed vært. Timeouts holdt (2 s afbrød et 5-sekunders svar). **API-nøglen kan ikke lække
  eller injicere:** testet med en nøgle der indeholder newline — `base64_encode()` giver kun
  `[A-Za-z0-9+/=]`, så headeren kan ikke brydes, og nøglen optræder i ingen exception-besked.
  `X-Cardholder-IP` afviste `"203.0.113.9\r\nIdempotency-Key: forged"` og tre andre
  injektionsforsøg. Ingen tilstandslækage over den delte curl-handle, verificeret over seks
  skiftende kald. Den private `header_callback()` **bliver** kaldt — værd at måle, for ellers
  ville hver abonnementsopkrævning kaste efter at pengene var flyttet.
- **HMAC-verifikationen.** Body læses råt fra `php://input`; `hash_equals()` i korrekt
  argumentrækkefølge; `sanitize_text_field()` på signaturen er en no-op på base64-alfabetet;
  længden verificeres mod `Content-Length` før HMAC; 512-byte-loft; `is_int()` på `seq`.
  **Ingen præ-auth informationslækage** — hvert svar før signaturkontrollen er en fast literal.
- **Al SQL.** Hver interpoleret værdi er enten en `(int)`-cast eller valideret først (`$cur`
  gennem `ctype_upper`, beløb gennem `wc_scanpay_is_money()`, `$pm_type` gennem `ctype_alnum`).
  Identifikatorer kommer udelukkende fra `$wpdb->prefix`, som WP hårdt afviser uden for
  `[a-z0-9_]i`. `schema.php` binder korrekt det eneste, der kan bindes.
- **Autorisation og CSRF på alle skrivestier.** Reset: capability **og** nonce før noget som
  helst læses. Capture: capability → parse → per-ordre nonce → opslag; alt over nonce'en er
  rene læsninger. Bulk: WP/WC tjekker begge dele før filteret. Den positionelle invariant i
  `wp-ajax-wc-mark-order-status.php` holder — `remove_action()` er den første linje med
  sideeffekt, og den ligger under nonce-checket.
- **De tre lette AJAX-endpoints.** `hash_equals()` med kendt streng først alle tre steder,
  `'' === $secret` → 403 (**fejler lukket**), backoff-tallene matcher kommentarerne eksakt
  (5,5 s og 15,5 s), og long-poll'en ligger *efter* auth, så den ikke er en uautentificeret
  DoS-flade.
- **Reset-endpointet.** Ingen variabelkollision med `install.php` (`$wcsp_*` mod
  `$seq_tbl`/`$meta_tbl`/… — disjunkte). Postbetingelserne er reelle og matcher `install.php`s
  DDL kolonne-for-kolonne. `Scanpay_Flock::release()` er idempotent, så fejl-udgangen er sikker.
- **`uninstall.php`.** Komplet: alle tre gateway-options, versionen, transienten og de tre
  tabeller. Ingen `update_site_option`, ingen egne cron-jobs. To-pas-strukturen (credentials
  før DDL) og `restore_current_blog()` i `finally` er korrekte, og pagineringen lukker en reel
  trunkering i `WP_Site_Query`.
- **`install.php`.** Rå `CREATE TABLE` bag `SHOW TABLES LIKE` er i dag korrekt: 2.x-skemaet er
  et ægte supersæt af v3's, verificeret mod `git show v2.5.0:includes/install.php`. `esc_like`
  + `prepare` på LIKE-mønstret er rigtigt.
- **Ordreejerskab i checkout.** En kunde kan ikke generere et betalingslink til en fremmed
  ordre: `pay_action()` verificerer ordrenøglen med `hash_equals()` før `process_payment()`, og
  renew-grenens `$subid` læses fra ordrens egen meta, ikke fra request. `successurl` bygges
  udelukkende af serverberegnede værdier.
- **Beløbets rejse ende-til-ende.** Alle wire-beløb bygges som
  `wc_format_decimal( …, wc_get_price_decimals() )` eller fra `get_total( 'edit' )`, som WC selv
  normaliserer med samme kald. `extract_amount()` accepterer formen igen på vej tilbage.
  Negative linjer springes bevidst over og udløser mismatch-tjekket, så kunden altid opkræves
  ordretotalen. De to payload-byggere (checkout og fornyelse) er identiske og kan ikke drive fra
  hinanden.
- **Meta-nøglerne.** Alle fem har mindst én skriver og én læser, og typeantagelserne stemmer.
  `_COMPLETE` skrives som bool og læses som `true === $want || '1' === $want`, hvilket dækker både
  før og efter persistering.
- **Replay-sikkerhed.** `AGENTS.md`s påstand holder: ingen sti dobbelt-fyrer mail, lager, capture
  eller statusskift ved replay. `sync()`s `transaction_id`-gate er tilstrækkelig for
  `payment_complete()`; fri-prøve-gennemførelsen er gated på `'pending'` i `'edit'`-kontekst.
- **Blocks vs. klassisk checkout.** Ingen divergens i gateway-udvælgelsen; `supports`-arrayet i
  Blocks-payloaden matcher `$this->supports` i alle tre gateways nøjagtigt.
- **Write-once-nøglen** er intakt hele vejen: `validate_apikey_field()` afviser både tom og
  erstatning, og `$is_card`-gaten forhindrer fantomnøgler på de to sekundære optioner.
- **Escaping i admin** er ellers konsekvent — alle attributter gennem `esc_attr`/`esc_url`, og
  `wcs_scanpay_payment_method_to_display()`s returværdi escapes af alle WCS-forbrugere.
- **Versionsgulve.** Intet uguardet API over PHP 8.0 / WP 6.3 / WC 3.6 fundet. `strategy => defer`
  er nøjagtigt WP 6.3.0 og dokumenteret i `docs/requirements.md`.
- **Kommentarernes linjehenvisninger** blev stikprøvet bredt og holdt næsten overalt — bl.a.
  `class-wc-emails.php:141/:145`, `wc-order-functions.php:494/:992/:1069`,
  `wc-stock-functions.php:125/:495`, `class-wc-gateway-paypal.php:196`,
  `class-wc-admin-menus.php:39-60`, `PaymentsController.php:36/:72`, `plugin.php:703`,
  `LegacyRestApiStub.php:36/:166`. Undtagelserne er fund 5, 6, 8 og mindre punkt 9.

Desuden efterprøvet: **idempotensnøglen** `orderid_rev_day` er stabil og kollisionsfri — alle
tre felter er `int`, og `WC_DateTime::getTimestamp()` er ægte UTC-epoch. Kausalkæden holder:
`scanpay_subs`-INSERT'en ligger **før** `wcs_enabled`-returnen, så `rev` ikke kan rykke uden
at samme drain også skrev `scanpay_meta` — nøglen kan ikke overhale already-paid-guarden.
**Exception-topologien** er konsistent: `capture_or_hold()`, `charge()` og
`wcs_scanpay_scheduled_charge()` fanger alle `\Throwable`, ikke kun `\Exception`, hvilket er
korrekt, da WooCommerce kun fanger `Exception`. Action Scheduler ser derfor aldrig en escaping
Throwable fra vores kode.

**Værktøjer kørt:** `php -l` på alle 35 filer — rene (PHP 8.3.29). `pnpm phpcs` — ren. Hertil
de tre fuzz-/integrationsscripts beskrevet ovenfor (math, capture-aritmetik, curl-klient) mod
bcmath, heltalsaritmetik og en lokal HTTP-server. `pnpm lint:js`, `pnpm lint:style` og
`pnpm exec tsc` er ikke kørt: reviewet rører kun PHP, og ingen kode er ændret.

**Konventioner:** alle 35 filer har `declare(strict_types=1)` og `defined( 'ABSPATH' )`, og
alle 83 oversatte strenge bruger tekstdomænet `scanpay-for-woocommerce`. Undtagelsesfrit.

---

---

## Kan ikke afgøres statisk

1. **Fund 1's udbredelse.** Mekanismen er sikker. Hvor mange butikker der faktisk står på
   < 2.1.3 med WCS aktiv og gamle `_scanpay_subscriber_id`-rækker, kan kun aflæses i drift.
2. **Fund 2's cron-arm.** Terms-bypasset er verificeret på registreringsniveau. At en
   renewal-action uden listener efterlades tavst ubehandlet er ikke sporet til bunds i WCS.
3. **Fund 3's konsekvens hos Scanpay.** At `/v1/new` med `subscriber.ref` og `?go=mobilepay`
   opfører sig anderledes end et kort-subscriber er en backend-antagelse.
4. **Fund 11's tærskel.** Hvor mange samtidige requests der mætter poolen afhænger af
   `pm.max_children`, reverse proxy og eventuel rate limiting foran PHP.
5. **Strict SQL mode.** Om kolonnefejlene i fund 1 og mindre punkt 2-3 udløses, afhænger af
   værtens `sql_mode`.
6. **Alt bag Scanpays API** — idempotensnøglens 24-timers binding, at `/renew` ikke opkræver,
   og om et beløb uden decimaler (`"1000 DKK"`) eller med mere end to accepteres.
   `wc_get_price_decimals()` kan sættes til 4, og `digformat()` kan producere begge former.
7. **`Idempotency-Status`-kontrakten.** Koden kræver præcis `'ok'`
   (`class-wc-scanpay-client.php:122`). Hvad Scanpay sender ved et *replay* inden for 24 t er
   en backend-kendsgerning. Sender den noget andet, kaster `charge()` **efter** at pengene er
   flyttet, og fornyelsen markeres fejlet. Mekanikken omkring headeren er verificeret;
   værdimængden er det ikke.
8. **Svarformen fra `/v1/transactions/{id}/capture`** (mindre punkt 10) og om `index`/`nacts`
   håndhæves server-side som replay-værn.
7. **`Scanpay_Flock` på delt `/tmp`.** Uændret fra forrige review; `WP_TEMP_DIR` er den
   dokumenterede udvej.

**Intet af dette er afprøvet på en kørende shop.** Alle fund hviler på kildelæsning af `src/`
og af upstream i `.stubs/` (WC 11.1.0-dev, WCS 8.7.1, WP 7.1-beta3), mens pluginnet
understøtter WC 3.6 / WP 6.3 — kommentarer, der påstår noget om adfærd under gulvet, kan ikke
efterprøves herfra.

---

*Ingen pull request åbnet. Ingen kode ændret i denne omgang. Arbejdstræet indeholder fortsat
kun den uncommitterede `save_or_report()`-rettelse fra det forrige review — som fund 6 og 10
viser er ufuldstændig.*
