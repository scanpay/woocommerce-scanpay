# PHP-review af `src/` — commit `bf49020`

Et uafhængigt gennemløb af alle 36 PHP-filer i `src/` (5.666 linjer), delt i seks felter
efter arkitekturens flowgrænser. Hvert fund bærer citat, konkret fejlscenarie og et
modargument, og alt i de to øverste alvorsgrader er efterprøvet en ekstra gang mod
upstream-kilden.

**Ingen kode er rettet i denne omgang.** Dokumentet er ren rapport — med én undtagelse:
fund 1 er siden lukket i `19246d7` og er markeret som sådan nedenfor. Resten står åben.

## Udgangspunkt

`pnpm phpcs` er ren på `bf49020`. Det er hele den maskinelle validering der findes for PHP
her, så alt nedenfor er semantik ingen linter ser. Der er ingen WordPress-installation i
dette repo; ingen påstand herunder er afprøvet på en kørende shop.

Upstream er læst i de rigtige træer, ikke i trimmede stubs:
`/code/snare/modules/stubs/wordpress` (WP trunk),
`/code/snare/modules/stubs/woocommerce/plugins/woocommerce` (WC 11.1.0-dev) og
`/code/snare/modules/stubs/woocommerce-subscriptions` (WCS 8.7.1).

Der ligger et tidligere PHP-review i git-historikken, skrevet mod `d342427`.
`git diff d342427..HEAD -- src/` rører 45 filer med ~1.500 linjer ændret, så dette
gennemløb er lavet fra bunden frem for som en efterprøvning af det gamle. Ét fund (nr. 1)
stod også dér og overlevede begge gennemløb; det er siden lukket, se fundet.

## Konventioner

- **Verificeret** — bekræftet mod kilden i dette repo eller i upstream-træet, med citat.
- **Udledt** — ræsonneret ud af koden, men afhænger af runtime-adfærd der ikke kan køres her.
- **Uverificeret** — kræver en kørende shop. Præsenteres aldrig som faktum.

Intet fund gentager noget fra guidens **Settled**-liste. Femminutters-keepalivet, den
låsefri charge-concurrency, den uscopede admin-AJAX-hemmelighed, den write-once API-nøgle og
migrationernes versionsstempling til sidst er behandlet som afgjort. Hvor et fund rører et
af de områder, angår det implementeringen, aldrig designet.

## Oversigt

| # | Fund | Alvor | Tillid |
|---|------|-------|--------|
| 1 | Migrationen kalder `wcs_get_subscription()` før WooCommerce har en order factory — **lukket i `19246d7`** | høj | Verificeret |
| 2 | `?change_payment_method` er ubeskyttet: et forfalsket checkout giver en gratis "ordre modtaget" | høj | Verificeret |
| 3 | Metodeskift opkræver et helt abonnementsbeløb på en `subscriptions-core`-butik | høj | Udledt |
| 4 | Blocks-checkout viser vilkårsfejl selv når boksen er sat | høj | Verificeret |
| 5 | Prioritet 5 forhindrer ikke mail og downloads for ubetalte varer | høj | Verificeret |
| 6 | Tre query-parametre slår pluginnet fra for et request og omgår vilkårsvalidering | middel | Verificeret |
| 7 | WooCommerces REST API ser slet ingen gateway-indstillinger | middel | Verificeret |
| 8 | Blocks-vilkårscheckboxen renderes hvor intet validerer den | middel | Udledt |
| 9 | Fejlet tabeloprettelse efter nøglegemning giver kritisk fejlside og en butik der aldrig synkroniserer | middel | Verificeret |
| 10 | `wc_autocapture` læses med to modstridende fallbacks | middel | Udledt |
| 11 | En capture der nedskaleres til autorisationens rest, siger ingenting | middel | Verificeret |
| 12 | Et gentaget migrationsgennemløb nulstiller `wcs_complete_renewal` | middel | Verificeret |
| 13 | Opgraderingens transient udløber mens migrationen stadig kører | middel | Udledt |
| 14 | Ping-stien er undtaget fra versionsgaten | middel | Verificeret |
| 15 | Med `set_time_limit()` slået fra virker pluginnet slet ikke | **høj** | Verificeret |
| 16 | En fejlet migration lukker ikke betalingsindgangene | middel | Verificeret |
| 17 | Uden ext-curl fataler checkout | middel | Verificeret |
| 18–39 | Mindre ting — se nedenfor | lav | blandet |
| 40–42 | Kommentardrift uden runtime-fejl | lav | Verificeret |
| 43 | Hazard uden foreslået rettelse | — | Uverificeret |

Afsnittene grupperer efter **hvor hurtigt der bør handles**, mens `Alvor` beskriver
konsekvensen hvis fundet udløses. De to kan afvige: fund 15 har høj alvor, men ligger i
"Bør rettes", fordi udløseren er en værtskonfiguration frem for en kodesti enhver butik går ad.

Fund 1–5 er de eneste med brugersynlig forkert adfærd på en normalt opsat butik, og fire af
dem er verificerede mod upstream-kilden — kun fund 3 er udledt. Fund 2 og 3 deler én rettelse.

### Krydstjek mod et parallelt review

Fund 5, 14–17 og 33–39 kom til ved at holde dette review op mod `docs/php-review-2.md`, et
uafhængigt gennemløb i samme arbejdstræ. Hvert af dem er efterprøvet her mod kilden frem for
overtaget, og to blev afvist i processen:

- *"Forældede signerede pings afvises i stedet for at kvitteres"* — koden håndterer allerede
  `$ping_seq === $seq` som et 200-heartbeat (`wc-scanpay-ping.php:187-195`). Kun *strengt*
  forældede pings får 400, og det er en ægte anomali værd at melde.
- *"Sync accepterer inkonsistente totaler"* — mekanismen er korrekt læst, men intet følger af
  den: `scanpay_meta.currency` har ingen læser overhovedet i pluginnet, alle fire totaler
  parres kun med WooCommerce-ordrens valuta, og capture-loftet (`authorized - captured`)
  udelukker allerede `refunded`. Forslagets anden halvdel — at kaste på `refunded > captured`
  — ville desuden lægge en ubevislig invariant på den sti hvis fejltilstand er permanent
  synkroniseringsstop for hele butikken.

Undervejs blev to af mine egne formuleringer også rettet: fund 39's fejltilstand er tavs
klipning, ikke en afvist skrivning (WordPress fjerner `STRICT_TRANS_TABLES`), og præmissen er
belagt her i træet frem for uverificerbar.

---

## Ret nu

### 1. Migrationen kalder `wcs_get_subscription()` før WooCommerce har en order factory

`src/upgrade.php:154`, kommentaren på `src/woocommerce-scanpay.php:253-255` — **Verificeret**
(mekanisme) / **Udledt** (konsekvenskæde) — **LUKKET i `19246d7`**, se *Status* nederst.

```php
// upgrade.php:154
$wc_sub = wcs_get_subscription( $oid );
```

Migrationen kører fra `wc_scanpay_plugins_loaded()`, som er hooket på **`plugins_loaded`
prioritet 10**. Men `WC()->order_factory` oprettes inde i `WooCommerce::init()`:

```php
// includes/class-woocommerce.php:978,992
public function init() {
	…
	$this->order_factory = new WC_Order_Factory();
```

og `init()` er hooket på **`init` prioritet 0** (`class-woocommerce.php:331`) — altså efter
`plugins_loaded`. Egenskaben er erklæret `public $order_factory = null;` (`:144`) og sættes
intet andet sted. På migrationstidspunktet er den derfor `null`, og WCS gør:

```php
// includes/core/wcs-functions.php:102
$subscription = WC()->order_factory->get_order( $the_subscription );
```

Det er en `Error: Call to a member function get_order() on null`.

Samme rækkefølgeproblem rammer forespørgslen ovenfor: WCS registrerer `shop_subscription`
på `add_action( 'init', …, 6 )`
(`class-wc-subscriptions-core-plugin.php:248`), så ordretypen er heller ikke registreret
endnu. De to datastores opfører sig forskelligt her — HPOS filtrerer på `wc_orders.type` som
en almindelig kolonne uden registreringstjek (`OrdersTableQuery.php:726, :1164`), så
rækkerne kommer tilbage og løkken når det fatale kald.

**Fejlscenarie.** En butik med WCS installeret og lagret version < 2.1.3 opgraderer.
`Error` fanges af loaderens `catch ( Throwable $e )`, så der er ingen hvid skærm — men
versionen stemples aldrig. Følgen er at:

- `upgrade.php` køres forfra hvert femte minut, for evigt;
- 2.1.3-subscriber-migrationen aldrig fuldføres;
- og **3.0.0-migrationen på linje 211 aldrig nås**. Den er den der dropper `scanpay_meta.method`,
  som filens egen kommentar beskriver: kolonnen er `NOT NULL` uden `DEFAULT`, så under en
  streng SQL-tilstand fejler hver v3-insert (MySQL 1364) og **synkroniseringscursoren kan
  ikke rykke sig**. Butikken markerer aldrig en ordre som betalt.

**Modargument.** Kommentaren på `woocommerce-scanpay.php:254-255` siger: *"The guard above
means WC core is loaded, so upgrade.php may use `wc_get_orders()`."* Den er sand for
`wc_get_orders()` med `'return' => 'ids'`, som ikke rører factoryen — men den forveksler
"klasserne er indlæst" med "runtime er initialiseret", og den dækker ikke
`wcs_get_subscription()`, som er det kald der fejler. Det er kommentardrift oveni fejlen.
Grenen kræver både `$wcs_exists` og version < 2.1.3, hvilket indsnævrer den til en
opgraderingssti — men på de butikker er konsekvensen total.

**Rettelse.** Kald ikke ind i WooCommerces objektlag fra `plugins_loaded`. Enten udskyd den
gren der har brug for det til `init` (efter prioritet 6), eller erstat
`wcs_get_subscription( $oid )` med en direkte metalæsning, som er alt løkken faktisk bruger:
den læser to meta-nøgler og skriver én. `WC_Data`-vejen er ikke nødvendig her.

**Status.** Lukket i `19246d7`, men ikke ved nogen af de to rettelser ovenfor. Hele
2.1.3-grenen er slettet — 92 linjer, inklusive det derefter døde `$wcs_exists` og den sidste
læsning af 1.x-nøglen `_scanpay_subscriber_id`. Begrundelsen ligger uden for repoet: ingen
installation parrer WCS med en lagret version under 2.1.3, så backfill'et har ingen
modtagere. Kaldstedet findes dermed ikke længere, og hele migrationsstien — `upgrade.php`,
`install.php` og `library/schema.php` — rører nu udelukkende `$wpdb` og options-API'et, så
fejlklassen er strukturelt væk fra filen frem for flyttet til et senere hook. Kommentaren på
`woocommerce-scanpay.php:253-255` er omskrevet fra at *give lov til* `wc_get_orders()` til at
angive begrænsningen, og henvisningen til grenen i `class-wcs-scanpay-charge.php` er fjernet,
så ingen af de to driver.

**Restrisiko.** Løsningen bevarer ikke backfill'et, som begge de foreslåede rettelser ville.
Findes der alligevel en WCS-butik under 2.1.3 — særligt i vinduet 2.0.0–2.1.2, som gaten
dækker, men som en "opgraderet til over 2.0" ikke udelukker — er fejlmåden nu et manglende
subid, så fornyelser fejler med *Invalid Scanpay subscriber ID*, i stedet for et nedbrud der
blokerer 3.0.0-migrationen. Dårligere end et fungerende backfill, bedre end nedbruddet det
afløser.

> Dette fund stod også i reviewet mod `d342427` (dengang linje 158) og overlevede begge
> gennemløb urettet.

---

### 2. `?change_payment_method` er ubeskyttet: et forfalsket checkout giver en gratis "ordre modtaget"

`src/public/generate-payment-link.php:185-218`, `:42-52` — **Verificeret**

```php
if ( wcs_scanpay_is_payment_method_change() ) {
	/*
	 * A method change on a subscription we do not know yet: … It must register a card and
	 * charge nothing …
	 */
	$data['subscriber'] = [ 'ref' => wc_scanpay_subref( $oid, $wco ) ];
	…
	return [ 'result' => 'success', 'redirect' => $link ];
}
```

Grenens præmis — i dens egen kommentar: "`$wco` is the WCS subscription" — kontrolleres
aldrig. Dens eneste gate er et prædikat der bunder i en bar query-string-sniff, sat på
`plugins_loaded` for **ethvert** request der bærer parameteren:

```php
// class-wc-subscriptions-change-payment-gateway.php:29, :83-87
add_action( 'plugins_loaded', __CLASS__ . '::set_change_payment_method_flag' );
…
public static function set_change_payment_method_flag() {
	if ( isset( $_GET['change_payment_method'] ) ) {
		self::$is_request_to_change_payment = true;
	}
}
```

Ingen nonce, intet ejerskabstjek, intet krav om at requestet overhovedet er et
metodeskift. WCS' *rigtige* handler, `change_payment_method_via_pay_shortcode()`, er
beskyttet af `_wcsnonce` + `woocommerce_change_payment` + en ordrenøgle-sammenligning — men
det offentlige statiske flag den også sætter er ikke, og flaget er det eneste
`wc_scanpay_process_payment()` konsulterer.

**Fejlscenarie.** WCS aktiv, kortgateway slået til, almindelig ikke-abonnementskurv. Kunden
sender det klassiske checkout til `/?wc-ajax=checkout&change_payment_method=1` med
`payment_method=scanpay`. Kortgatewayen overlever WCS' eget
`get_available_payment_gateways()`-filter, fordi den erklærer
`subscription_payment_method_change_customer`. `WC_Checkout::create_order()` skriver en
`pending` ordre, og `:185`-grenen fyrer: Scanpay bliver bedt om en kortregistreringsside i
stedet for en betaling. `/v1/new` postes med en `subscriber.ref` og **ingen `items`-nøgle**,
hvilket ifølge kommentaren på `:199-205` "creates a subscriber and nothing else: no
transaction". Kunden registrerer et kort, sendes til `get_checkout_order_received_url()` og
får "Thank you. Your order has been received." Ordren blev aldrig opkrævet, intet
`_scanpay_payid` blev stemplet, og Scanpay holder nu en subscriber hvis ref
(`'wcs[]' . $oid` med et almindeligt *ordre*-id) ikke peger på noget abonnement.

**Modargument.** Ingen af de tre gateways guarder det: alle kalder
`wc_scanpay_process_payment()` direkte fra `process_payment()`, og
`WC_Checkout::process_order_payment()` inspicerer ikke query-strengen. Hver anden
WCS-adfærd der bygger på flaget alene er desuden gated på `is_checkout_pay_page()` eller
`order-pay`, som ingen af delene er sande på checkout-siden. Der stjæles ikke penge —
ordren forbliver `pending` — så dette er høj og ikke kritisk: skaden er en ordre der ser
afgivet ud og aldrig opkræves, en vildledt kunde og affaldsrækker i `scanpay_subs`.

**Rettelse.** Lad grenen bevise sin præmis. Én lokal variabel, beregnet én gang og brugt
både på `:146` og `:185`, hejst op over `if ( $wcs )` — hvilket samtidig retter fund 3:

```php
$is_method_change = wcs_scanpay_is_payment_method_change() && $wco instanceof WC_Subscription;
```

`WC_Subscription` er WCS 2.0, allerede den erklærede floor, og den ligger i
subscriptions-core, så testen er gyldig i begge konfigurationer.

---

### 3. Metodeskift opkræver et helt abonnementsbeløb på en `subscriptions-core`-butik

`src/public/generate-payment-link.php:123-233` — **Udledt**

```php
$wcs = class_exists( 'WC_Subscriptions', false ) && method_exists( 'WC_Subscriptions_Product', 'is_subscription' );
if ( $wcs ) {
	…
	if ( wcs_scanpay_is_payment_method_change() ) {
```

Begge udgange for et metodeskift ligger **inde i** `if ( $wcs )`, som kræver det fulde
`WC_Subscriptions`-plugin. Men prædikatet der gør metodeskiftet nåeligt kræver kun
`WC_Subscriptions_Change_Payment_Gateway`. Efterprøvet: `WC_Subscriptions` deklareres alene
i det betalte plugins rodfil (`woocommerce-subscriptions.php:73`), mens
`WC_Subscriptions_Change_Payment_Gateway` ligger i `includes/core/` (`:11`) — altså i
`subscriptions-core`, som følger med andre udvidelser uden det fulde plugin. På sådan en
butik er `$wcs` falsk, begge udgange springes over, og kontrollen når præcis den
item-bygning kommentaren siger den aldrig må nå.

**Fejlscenarie.** Butikken kører WooPayments' medfølgende abonnementer. WCS kalder
`process_payment( $subscription->get_id() )`. `$wco` er nu `WC_Subscription`; løkken læser
de gentagne linjebeløb; `get_total( 'edit' )` returnerer det u-nulstillede beløb, fordi WCS'
nulstilling er filteret `woocommerce_subscription_get_total`
(`class-wc-subscriptions-change-payment-gateway.php:33`), som `WC_Data::get_prop()` kun
anvender i `view`-kontekst. Kunden, der bad om at opdatere sit kort, **opkræves én hel
abonnementsperiode**, der oprettes ingen Scanpay-subscriber, og `_scanpay_payid` m.fl.
skrives på abonnementet, hvorfra WCS' datakopiering fører dem videre til hver fremtidig
fornyelsesordre.

**Modargument.** Arkitekturen understøtter bevidst kun det fulde plugin
(`woocommerce-scanpay.php:284`). Men den beslutning handler om *hvilke features der
tilbydes*, og koden ræsonnerer andetsteds eksplicit om core-only-butikker:
`public/subscriptions.php:9-12` flytter `wcs_scanpay_terms_url()` ud af filen præcis fordi
"subscriptions-core ships without `WC_Subscriptions`". "Ikke understøttet" er et forsvarligt
udfald; at opkræve et gentagent beløb under en kortopdatering er det ikke.

**Rettelse.** Samme ene linje som fund 2: hejs metodeskift-udgangen op over `$wcs`-gaten.
Ingen af grenene har brug for `WC_Subscriptions_Product`.

---

### 4. Blocks-checkout viser vilkårsfejl selv når boksen er sat

`src/public/subscriptions.php:107-123` — **Verificeret**

```php
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'wcs_scanpay_blocks_validate_terms', 10, 2 );
```

Hooket er ikke place-order-only. `update_order_from_request()` fyrer det
(`src/StoreApi/Utilities/CheckoutTrait.php:247`) og kaldes fra **to** ruter:

```
get_route_post_response()    Checkout.php:504   → kald på :578   (POST, bærer extensions)
get_route_update_response()  Checkout.php:375   → kald på :394   (PUT/PATCH, gør ikke)
```

Blocks-klientens PUT-body er typet `CheckoutPutData` og indeholder kun `additional_fields`,
`order_notes` og `payment_method`. På hvert PUT med en kladdeordre i sessionen er
`$extensions['scanpay']['terms']` derfor fraværende, og handleren kaster en hård 400 for et
kald der aldrig var et købsforsøg.

**Fejlscenarie.** Kunden lægger ordren, får betalingsvinduets URL og trykker Tilbage i
stedet for at betale. Kladdeordren er stadig gyldig. Kunden ændrer nu betalingsmetode eller
retter et felt → PUT → handleren kaster → blokken viser "You must accept the subscription
terms to complete your purchase", **selvom boksen er sat**, fordi fluebenet kun rejser med
POST. Hver videre interaktion gentager det.

**Modargument.** Docblocken siger "re-checked here so a crafted request cannot bypass
acceptance", hvilket er den rigtige hensigt — POST-stien *har* brug for kontrollen. Men
hooket er dokumenteret som "updates an order from the API request data", og WooCommerces
egen forbruger af det (`OrderAttributionBlocksController.php:99`) skriver kun, kaster aldrig.

**Rettelse.** `if ( 'POST' !== $request->get_method() ) { return; }` øverst i funktionen.
POST-stien kører den stadig før `process_payment()`, så anti-tamper-egenskaben er uændret.

---

### 5. Prioritet 5 forhindrer ikke det kommentaren lover: kunden får mail og downloads for ubetalte varer

`src/woocommerce-scanpay.php:277-281` — **Verificeret**

```php
// Priority 5 is load-bearing: every WooCommerce listener here runs at 10 or later -- the
// transactional emails, wc_downloadable_product_permissions, the stock and sales counts.
// Capturing first parks a failure 'on-hold' before the customer is mailed "completed" and
// granted downloads for unpaid goods. WC's own PayPal gateway captures at 10; we do not.
add_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2 );
```

Kommentarens første halvdel er sand: de nævnte lyttere ligger alle på prioritet 10.
Efterprøvet i WC-kilden — `queue_transactional_email` (`class-wc-emails.php:119, :142`),
`wc_downloadable_product_permissions` (`wc-order-functions.php:494`),
`wc_update_total_sales_counts` (`:1002`) og `wc_update_coupon_usage_counts` (`:1079`).

Anden halvdel holder ikke. At vores callback på prioritet 5 parkerer ordren `on-hold` stopper
ikke `do_action()` — den kører hele sin lyttekæde igennem uanset hvad en callback undervejs
gør ved ordren. Og ingen af de to konsekvenstunge lyttere gentjekker status:

```php
// wc-order-functions.php:465-474 -- ingen kontrol af 'completed'
function wc_downloadable_product_permissions( $order_id, $force = false ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ( $order->get_data_store()->get_download_permissions_granted( $order ) && ! $force ) ) {
		return;
	}
	if ( $order->has_status( OrderStatus::PROCESSING ) && 'no' === get_option( … ) ) {
		return;
	}
```

```php
// class-wc-email-customer-completed-order.php:64-78 -- sender uden statuskontrol
public function trigger( $order_id, $order = false ) {
	…
	$this->send_notification();
```

Prioritet 5 køber altså *rækkefølge*, ikke *forhindring*.

**Fejlscenarie.** Butik med `wc_autocapture = 'completed'` og et downloadbart produkt. Scanpays
API er utilgængeligt i det øjeblik købmanden markerer ordren Gennemført. Vores callback på
prioritet 5 fejler capture og sætter ordren `on-hold` med en note. `do_action` fortsætter til
prioritet 10: kunden får "Din ordre er gennemført"-mailen, downloadrettighederne tildeles, og
salgs- og kupontællere opdateres — for en ordre der aldrig blev indløst og nu står `on-hold`.
Købmanden ser en on-hold-ordre og aner ikke at varen allerede er udleveret.

**Modargument.** Prioritet 5 er stadig bedre end 10: ordrenoten og `on-hold`-statusen er på
plads *inden* mailen sendes, så købmandens revisionsspor er korrekt, og en administrator der
kigger på ordren ser den rigtige tilstand. Kommentarens sidste sætning — at WC's egen
PayPal-gateway capturer på 10 og vi ikke — er også korrekt og relevant. Det er kun løftet om
at kunden *ikke* mailes og *ikke* får downloads der er forkert, og efter guiden er en
kommentar der overdriver sin invariant netop det der er under test.

**Rettelse.** Prioriteten kan ikke løse det; `do_action` kan ikke afbrydes. Enten skal
kommentaren skrives om til hvad prioriteten faktisk køber (rækkefølge og et korrekt
revisionsspor), eller også skal de efterfølgende lyttere aktivt afkobles når capture fejler —
f.eks. ved i fejlgrenen at fjerne `wc_downloadable_product_permissions` og
`WC_Emails::queue_transactional_email` fra det igangværende `woocommerce_order_status_completed`,
hvilket `remove_action()` godt kan midt i et `do_action`-gennemløb. Det er den eneste af de to
der lukker hullet, men det er en reel adfærdsændring og bør besluttes bevidst.

---

## Bør rettes

### 6. Tre query-parametre slår pluginnet fra for et request og omgår vilkårsvalidering

`src/woocommerce-scanpay.php:84-88` mod påstanden i `src/public/subscriptions.php:100-103`
— **Verificeret**

```php
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'], $_GET['key'] ) && in_array( $_GET['scanpay_type'], [ 'wc', 'wcs', 'wcs_free' ], true ) ) {
	require WC_SCANPAY_DIR . '/public/wp-scanpay-thankyou.php';
	return;
}
```

Det `return` afslutter plugin-filen på filniveau, så `add_action( 'plugins_loaded',
'wc_scanpay_plugins_loaded', 10 )` på linje 289 aldrig nås. Og det er
`wc_scanpay_plugins_loaded()` der requirer `public/subscriptions.php` — filen som
registrerer både `wcs_scanpay_blocks_validate_terms()` og
`woocommerce_store_api_register_endpoint_data()`-namespacet. Alle tre parametre er
angribervalgte og uautentificerede: `scanpay_thankyou` og `key` må være hvad som helst,
`scanpay_type` én af tre literaler.

Et request kan altså undertrykke netop den validator hvis kommentar siger *"a crafted
request cannot bypass acceptance"*.

**Fejlscenarie.** Kunden POSTer
`/wp-json/wc/store/v1/checkout?scanpay_thankyou=0&scanpay_type=wc&key=x` uden `extensions`.
`plugins_loaded` kører aldrig vores loader, intet er hooket, og ordren afgives uden at
abonnementsvilkårene nogensinde blev givet eller registreret. Thank-you-handleren der *blev*
loadet returnerer straks (`absint( '0' )` er falsy), så requestet er ellers ikke til at
skelne fra et normalt.

**Modargument.** Gatens egen kommentar siger "A genuine return carries all three params and
a known type; anything else falls through to a normal load" — bogstaveligt sandt, men den
beskriver kun det negative tilfælde, og intet sted står at et *forfalsket* sæt af de tre er
harmløst. Det er en generel "slå pluginnet fra for dette request"-primitiv, ikke kun en
vilkårsomgåelse; de øvrige hooks den dropper er enten selvmodsigende for en angriber eller
ikke kundeudløselige, hvilket er grunden til at vilkårsomgåelsen er den ene konkrete
konsekvens der anføres. At samtykket er jura og ikke penge er grunden til middel frem for
høj.

**Rettelse.** Kræv at requestet er hvad gaten påstår den router. En ægte returnering er
altid en browser-GET fra betalingsvinduet, og begge omgåelsesveje er POST:

```php
if (
	'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
	&& isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'], $_GET['key'] )
	&& in_array( $_GET['scanpay_type'], [ 'wc', 'wcs', 'wcs_free' ], true )
) {
```

Det strukturelle alternativ er at flytte requiret af `public/subscriptions.php` ud af
`wc_scanpay_plugins_loaded()`, så hook-registrering aldrig afhænger af dispatch-gatene.

---

### 7. WooCommerces REST API ser slet ingen gateway-indstillinger

`src/gateways/abstract-wc-gateway-scanpay-base.php:58-88` — **Verificeret**

```php
/**
 * Deliberately empty. WooCommerce builds the form fields on every request; that is
 * pure overhead off the settings screen, so get_form_fields() loads them lazily.
 */
public function init_form_fields(): void {}
```

REST-controllerne kalder ikke `get_form_fields()`. De kalder `init_form_fields()` og læser
derefter **egenskaben** `$gateway->form_fields` direkte:

```php
// Version3/class-wc-rest-payment-gateways-controller.php:73-76
$gateway->init_form_fields();
foreach ( $gateway->form_fields as $id => $field ) {

// Version2/class-wc-rest-payment-gateways-v2-controller.php:170-175
$gateway->init_form_fields();
$settings = $gateway->settings;
if ( isset( $request['settings'] ) ) {
	foreach ( $gateway->form_fields as $key => $field ) {
```

Med overriden tom er egenskaben `[]` på hvert REST-kald. `WC_Settings_API` kalder den aldrig
selv — `get_option()`, `process_admin_options()` og `generate_settings_html()` går alle
gennem `get_form_fields()`, som overriden håndterer, så admin-skærmen er upåvirket.

**Fejlscenarie.** `GET /wp-json/wc/v3/payment_gateways/scanpay` returnerer `"settings": []`,
mens hver anden gateway i butikken lister sine felter. `PUT` med
`{"settings":{"wc_autocapture":"on"}}` svarer HTTP 200 og ændrer intet: løkken itererer et
tomt array, hvorefter den uændrede `$settings` skrives tilbage. `wp wc payment_gateway
update scanpay --settings=…` fejler tavst på samme måde. Kun `enabled`, `title` og `order`
er skrivbare, fordi de har egne grene uden for løkken.

**Fælde i rettelsen.** Netop denne tomhed er i dag det eneste der forhindrer REST i at
udskifte en gemt API-nøgle. V2's `update_item()` dispatcher `validate_setting_{$type}_field`
og falder tilbage til `validate_setting_text_field()` for en ukendt type — vores
`validate_apikey_field()` konsulteres aldrig. Så snart `form_fields` er fyldt, ville
`PUT {"settings":{"apikey":"9999:…"}}` overskrive nøglen og efterlade `scanpay_seq` og
`scanpay_meta` forældreløse på en anden shop. Uanset hvordan der rettes, skal `apikey` holdes
ude af det array REST-controlleren itererer.

**Rettelse.**

```php
public function init_form_fields(): void {
	$fields = $this->get_form_fields();
	unset( $fields['apikey'] );
	$this->form_fields = $fields;
}
```

og docblocken omskrevet til at sige at metoden kun kaldes af REST-controllerne.

---

### 8. Blocks-vilkårscheckboxen renderes hvor intet validerer den

`src/gateways/blocks/class-wc-scanpay-blocks-support.php:66-86` — **Udledt**.
Fundet uafhængigt af to reviewere.

```php
if ( class_exists( 'WC_Subscriptions_Cart', false ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
	$terms_url = wcs_scanpay_terms_url();
	if ( '' !== $terms_url ) {
		$data['terms'] = [
```

Dette er den eneste af de fire vilkårs-gates der er nåelig uden det fulde plugin. Den
klassiske renderer, den klassiske validator og Blocks-validatoren ligger alle i
`src/public/subscriptions.php`, som routeren kun loader bag `class_exists( 'WC_Subscriptions' )`.
Efterprøvet: `WC_Subscriptions_Cart` ligger i `includes/core/class-wc-subscriptions-cart.php:13`
(subscriptions-core), mens `WC_Subscriptions` kun findes i det fulde plugin.

**Fejlscenarie.** Butik med subscriptions-core, abonnement i kurven, `wcs_terms` peger på en
publiceret side (feltet tilbydes uanset WCS). Blocks-checkout renderer checkboxen og blokerer
"Afgiv ordre". Den afsendte `extensions.scanpay.terms` droppes derefter tavst af Store API,
fordi namespacet aldrig blev registreret — droppet, ikke afvist, så ingen 400. Klassisk
checkout på samme butik renderer slet ingen checkbox. Samtykket er altså rent kosmetisk i
Blocks og ikke-eksisterende i klassisk, ud fra samme indstilling.

**Modargument.** Filhovedet i `subscriptions.php` dokumenterer gate-valget og forklarer
hvorfor `wcs_scanpay_terms_url()` blev hejst ud. Det forklarer *hvorfor* funktionen ligger
hvor den ligger; det hævder ikke at splittet er tilsigtet. Invarianten koden måles mod står
på `wcs_scanpay_terms_url()` selv (`woocommerce-scanpay.php:158-163`): *"The single predicate
behind both renderers (classic and Blocks) and both validators, so the checkbox is never
enforced unrendered, or the reverse."* Her er den renderet uden håndhævelse — præcis "the
reverse". Filhovedets egen invariant, "nothing reachable outside that guard may live here",
brydes af samme grund.

**Rettelse.** Vælg ét prædikat. Minimalt: tilføj `class_exists( 'WC_Subscriptions', false ) &&`
til `$data['terms']`-gaten.

---

### 9. Fejlet tabeloprettelse efter nøglegemning giver kritisk fejlside og en butik der aldrig synkroniserer

`src/gateways/class-wc-gateway-scanpay-card.php:93-110` — **Verificeret** (kodesti) /
**Udledt** (runtime)

```php
if ( $new && $new !== $old ) {
	require WC_SCANPAY_DIR . '/install.php';
}
```

`install.php` kaster `Exception` fire steder (`:30`, `:54`, `:74`, `:99`) — tre fejlede
`CREATE TABLE` og seq-rækkens seed. Hver anden kalder indeslutter den: loaderen wrapper
`upgrade.php` i `try/catch` (`woocommerce-scanpay.php:260-268`), og reset-endpointet wrapper
den direkte (`wp-ajax-wc-scanpay-reset.php:140-146`). Netop dette kaldested gør det ikke, og
kæden over det — `WC_Settings_Payment_Gateways::save()` → `WC_Admin_Settings::save()` — har
intet `try/catch`.

**Fejlscenarie.** Købmanden indsætter en gyldig nøgle og gemmer. Nøglen gemmes og valideres
mod `seq(0)` — begge lykkes. `install.php` fejler derefter i at indsætte `scanpay_seq`-rækken
(DB-fejl, read-only replika, fuld tablespace). Undtagelsen slipper ud og wp-admin viser
WordPress' kritiske fejlside. Nøglen er allerede gemt, så ved hver senere gemning returnerer
`validate_apikey_field()` den lagrede værdi, `$new === $old`, og `install.php` requires aldrig
igen; versionsgaten kører heller ikke `upgrade.php` igen, fordi versionen blev stemplet ved
aktivering. Nettoresultat: en butik med en maskeret, fungerende nøgle, en grøn
indstillingsskærm og ingen seq-række — ping-handleren svarer "shop not configured" for altid.
Eneste udvej er reset-knappen, hvis tekst handler om at slette data.

**Modargument.** "Fail loud" er den erklærede politik, og et ufanget kast er højlydt. Men
guidens formulering er *"Primitives throw; one place per flow catches"* — og dette flow har
ingen fanger. Udslippet ødelægger desuden det retry kastet findes for at udløse.

**Rettelse.** Wrap requiret og rapportér gennem samme kanal som resten af metoden
(`scanpay_log()` + `WC_Admin_Settings::add_error()`). Der skal en retry-sti oveni, da
`$new !== $old`-gaten forbliver falsk bagefter; billigst er at re-require når butikkens
seq-række mangler, frem for kun når nøglen skiftede.

---

### 10. `wc_autocapture` læses med to modstridende fallbacks

`src/woocommerce-scanpay.php:147`, `src/admin/orders.php:20`,
`src/admin/hooks/wp-ajax-wc-mark-order-status.php:91` mod
`src/admin/settings/fields/scanpay.php:76` — **Udledt**

Feltets default er `'completed'`. De to *producenter* følger den; de tre *forbrugere* gør
det modsatte:

```php
public/generate-payment-link.php:93:      $autocapture = $settings['wc_autocapture'] ?? 'completed';
library/class-wcs-scanpay-charge.php:265: $autocapture = $this->settings['wc_autocapture'] ?? 'completed';

// mod, tre steder:
if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
```

Betalings*oprettelses*-siden tolker en manglende nøgle som `'completed'` — "autocapture ikke
hos Scanpay, butikken capturer ved ordreafslutning" — mens hver *afslutnings*-side tolker den
som alt-andet-end-`'completed'` og derfor aldrig capturer.
`WC_Settings_API::get_option( 'wc_autocapture' )` ville også svare `'completed'`, så
afslutningssiderne er uenige med WooCommerces egen opfattelse af de gemte indstillinger.

**Fejlscenarie.** Indstillingsarrayet findes, men mangler nøglen. En dokumenteret vej ind:
`upgrade.php`'s `< 2.0.0`-gren (`:56-72`) skriver et array med `capture_on_complete` men
**ikke** `wc_autocapture`; først `< 2.5.0`-grenen (`:188-200`) udleder den. Imellem dem ligger
WCS 2.1.3-migrationen — den samme der er brudt i fund 1. Et kast dér afbryder filen før
2.5.0-grenen, og fordi versionen stemples til sidst, består tilstanden, mens `enabled` og
`apikey` blev båret uændret over, så butikken er live. I det vindue får en fysisk ordre
`'autocapture' => false` i sit betalingslink (kun autorisation), og at afslutte den læser
`''`, springer capture over og skriver hverken note eller logline. Købmanden ser en afsluttet,
"betalt" ordre og sender varer mod en autorisation der aldrig indløses.

**Modargument.** Efter enhver gemning af kortindstillingerne skriver
`process_admin_options()` samtlige formularfelter, så nøglen er til stede på en normalt
konfigureret butik. Vinduet er smalt: et halvmigreret eller håndredigeret option, ikke
normaltilstanden. Det er stadig en divergens med pengekonsekvens og uden værn, og
`''`-fallbacken er den eneste af de fire der modsiger feltets default.

**Rettelse.** Lad de tre afslutningssider læse `?? 'completed'`. At ændre de to andre til
`''` er den forkerte retning: det ville tavst skifte en halvmigreret butik til
øjeblikkelig-capture-semantik.

---

### 11. En capture der nedskaleres til autorisationens rest, siger ingenting

`src/library/class-wc-scanpay-capture.php:96-107` — **Verificeret**

```php
$remaining_on_auth = wc_scanpay_submoney( $meta['authorized'], $meta['captured'] );
if ( wc_scanpay_cmpmoney( $to_capture, $remaining_on_auth ) > 0 ) {
	$to_capture = $remaining_on_auth;
}
if ( wc_scanpay_cmpmoney( $to_capture, '0' ) <= 0 ) {
	scanpay_log( 'debug', "Skipping capture: nothing left to capture on order #$oid" );
	return;
}
```

Når ordren skylder mere end autorisationen tillader, nedskalerer klemmen tavst beløbet, og
`capture()` returnerer normalt. `capture_or_hold()` returnerer `true`, og hver kalder
behandler det som fuld succes. Intet logges, og ingen note skelner en fuld capture fra en
delvis — eneste spor er beløbet inde i succes-noten, som læser præcis som en normal capture.
Grenen lige nedenunder *logger* derimod, hvilket viser at filen allerede regner denne klasse
af udfald for værd at registrere.

**Fejlscenarie.** Ordre autoriseret til 100,00 DKK. Købmanden lægger en linje på 50,00 DKK
til, total 150,00, og markerer Gennemført. `$to_capture` bliver `'150'`, klemmes til `'100'`.
Scanpay capturer 100. Note: "Scanpay capture of 100 DKK completed." Ordren forbliver
`completed`, mails sendes, varer afsendes. Købmanden mangler 50 DKK, og hverken log eller
ordre siger det.

**Modargument.** Kommentaren forklarer korrekt *hvorfor* klemmen findes — en refundering
genskaber ikke autorisationshovedrum. Problemet er at manglen ikke rapporteres. Kodebasens
egen standard for den analoge situation er højere: `WC_Scanpay_Sync::sync()` (`:274-292`)
både logger en fejl *og* tilføjer en ordrenote når det autoriserede beløb ikke dækker totalen.

**Rettelse.** Log manglen før `$to_capture` overskrives. En købmandssynlig note er bedre
endnu, men skal ligge i det eksisterende `try/catch`-mønster og *efter* at capture er
returneret.

---

### 12. Et gentaget migrationsgennemløb nulstiller `wcs_complete_renewal`

`src/upgrade.php:55-72`, mod kontrakten i `src/upgrade.php:4-7` — **Verificeret**

```php
$arr = [
	'enabled'              => $old['enabled'] ?? 'no',
	…
	'wcs_complete_renewal' => $old['autocomplete_renewalorders'] ?? 'no',
	…
	// Preserve an existing secret, or an interrupted re-run invalidates the in-flight
	// admin-AJAX token.
	'secret'               => $old['secret'] ?? bin2hex( random_bytes( 32 ) ),
];
update_option( WC_SCANPAY_URI_SETTINGS, $arr, true );
```

Filhovedet lover: *"Each branch is idempotent and the version is stamped last, so an
interrupted run simply re-runs from the start on the next request."* Hver post ovenfor læses
under samme nøgle som den skrives til — så et gentaget gennemløb læser tilbage hvad det
forrige skrev — med præcis én undtagelse: `wcs_complete_renewal` læses fra *1.x*-nøglen
`autocomplete_renewalorders`, som ikke er medlem af `$arr` og derfor slettes af selve
`update_option()` på linje 72. På andet gennemløb falder `??` igennem til `'no'`.
`secret`-linjen viser at forfatteren tænkte på præcis dette gentagelsestilfælde én linje
tidligere; fornyelsesflaget blev overset.

**Fejlscenarie.** 1.x-butik med `autocomplete_renewalorders = 'yes'`. Første gennemløb skriver
`wcs_complete_renewal = 'yes'`, hvorefter filen kaster senere (fund 1, en fejlende `ALTER`
eller et rent tidsudløb). Fem minutter efter genopbygger andet gennemløb `$arr` ud fra første
gennemløbs option og skriver tavst `wcs_complete_renewal = 'no'`. Fornyelsesordrer holder op
med at blive auto-afsluttet. Intet logges, og indstillingen købmanden ville kigge på står nu
'no', som om de selv havde sat den.

**Modargument.** Kunne `$old` stadig bære 1.x-nøglen ved andet gennemløb? Nej —
`update_option()` erstatter hele arrayet, `$arr` har ingen `autocomplete_renewalorders`, og
intet genindsætter den. Er et andet gennemløb overhovedet nåeligt? Ja, og efter hensigten:
versionen stemples først på linje 252, og hovedet planlægger eksplicit for en afbrudt kørsel
der starter forfra.

**Rettelse.** Læs nyeste nøgle først, som `secret`-linjen allerede gør:

```php
'wcs_complete_renewal' => $old['wcs_complete_renewal'] ?? $old['autocomplete_renewalorders'] ?? 'no',
```

Samme form ville hærde de tre hårdkodede værdier (`wc_complete_virtual`,
`wcs_complete_initial`, `'stylesheet' => 'yes'`): en 1.x-butik kan ikke have sat dem før
første gennemløb, men indstillingsskærmen er fuldt brugbar mellem gennemløbene, så en købmand
der ændrer en af dem får den rullet tilbage ved næste retry.

---

### 13. Opgraderingens transient udløber mens migrationen den beskytter stadig kører

`src/woocommerce-scanpay.php:253-269` mod `src/upgrade.php:16`, `:147-153` — **Udledt**

```php
// transient serializes two requests racing it; upgrade.php's steps are idempotent.
if (
	get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' )
) {
	set_transient( 'wc_scanpay_updating', true, 5 * MINUTE_IN_SECONDS );
```

To påstande i loaderens kommentarer holder ikke. **(a)** "The transient serializes two
requests racing it" — læsningen på linje 257 og skrivningen på 259 er ikke atomare, så to
samtidige requests ser begge `false` og fortsætter begge. **(b)** Mere konkret: transientens
levetid er faste fem minutter, mens migrationen den beskytter fornyer sit eget tidsbudget uden
nogen samlet grænse (`upgrade.php:150-152`). En migration der legitimt kører længere end fem
minutter — hvilket pagineringsløkken på `:128-181` findes netop for at understøtte — overlever
sit eget værn, så næste request starter en anden samtidig opgradering. Dermed er "én kørsel
per 5 minutter"-throttlingen på `:264-266` kun reel for fejl der er hurtige.

**Fejlscenarie.** Butik med ~20.000 abonnementer der bærer `_scanpay_subscriber_id`. Worker A
går ind ved T=0 og pagerer 500 ad gangen. Ved T=5 min udløber transienten; næste
frontend-request ser versionen ustemplet og ingen transient, og starter worker B, som
genlæser `$max_trn`, gennemløber de samme abonnementer og udsteder de samme
`save_meta_data()`-skrivninger side om side med A — og sætter transienten igen, så ved
T=10 min støder worker C til. Intet data korrumperes (skrivningerne er idempotente), men
butikken betaler N gange for migrationen.

**Modargument.** Kommentaren indrømmer selv at "upgrade.php's steps are idempotent", så overlap
kan være en accepteret omkostning — men samme kommentar læner sig på transienten for en garanti
den ikke kan give ("serializes", "one attempt per 5 minutes"), og efter guiden er en kommentar
der overdriver en invariant netop det der er under test. Transienten er heller ikke en
permanent baglås i noget tilfælde, så fejltilstanden er dobbeltarbejde, ikke en kilet
opgradering.

**Rettelse.** Forny værnet dér hvor tidsgrænsen fornys — tilføj
`set_transient( 'wc_scanpay_updating', true, 5 * MINUTE_IN_SECONDS );` ved siden af
`set_time_limit( 60 )` på `upgrade.php:151` — og blødgør loaderens kommentar fra "serializes"
til hvad den faktisk gør.

---

### 14. Ping-stien er strukturelt undtaget fra versionsgaten og kan synkronisere mod et umigreret skema

`src/woocommerce-scanpay.php:63-77` mod `src/upgrade.php:202-209` — **Verificeret**

Ping-gaten returnerer på linje 75, altså på filniveau og længe før
`add_action( 'plugins_loaded', 'wc_scanpay_plugins_loaded', 10 )` på linje 289. Versionsgaten
— det eneste sted `upgrade.php` køres fra — er derfor aldrig registreret på et ping-request.
Ping-handleren loader så `WC_Scanpay_Sync` og skriver 3.x-formede rækker.

Migrationens egen kommentar beskriver præcis hvad det koster mod et 2.x-skema:

```php
 *  scanpay_meta.method is NOT NULL with no DEFAULT, so under a strict SQL mode every v3
 *  insert fails (MySQL 1364) and the cursor cannot advance past that change.
```

Og `upsert_meta()`s INSERT nævner ganske rigtigt ikke `method`
(`class-wc-scanpay-sync.php:228-229`), så påstanden holder.

**Fejlscenarie.** En 2.x-butik opdateres. Det første request efter opdateringen er et ping —
realistisk, da Scanpay pinger hvert femte minut. Sync forsøger at indsætte uden `method`,
MySQL 1364 afviser, cursoren rykker sig ikke, og pinget svarer 500. Scanpay prøver igen fem
minutter senere, med samme udfald, indtil et *andet* request end et ping rammer butikken og
kører migrationen.

**Modargument.** Dette selvheler på enhver frontend- eller admin-visning, og
femminutters-keepalivet er den erklærede recovery-backstop (Settled), så isoleret set er
fundet lavt. Det der gør det værd at rapportere er kombinationen med **fund 1**: er
migrationen kilet fast, kommer ping-stien aldrig videre af sig selv, og de to fund sammen
forklarer hvorfor en ramt butik holder helt op med at registrere betalinger.

**Rettelse.** Enten kør den samme idempotente migration før drain på ping-stien, eller lad
pinget svare et genforsøgeligt svar mens `wc_scanpay_version !== WC_SCANPAY_VERSION`.

---

### 15. På en vært der har slået `set_time_limit()` fra, virker pluginnet slet ikke

`src/callback/wc-scanpay-ping.php:23-24`, `:317`, `src/upgrade.php:16`, `:151`,
`src/uninstall.php:20`, `:81`, `:107`, `src/admin/hooks/wp-bulk-actions.php:40`, `:50`,
`src/admin/ajax/wp-scanpay-fetch-meta.php:50`, `src/admin/ajax/wp-scanpay-fetch-sub.php:50` —
**Verificeret** (eksekveret)

Alle tolv kaldsteder er uguardede. `set_time_limit()` og `ignore_user_abort()` kan slås fra
med `disable_functions`, og i PHP 8 fjernes en deaktiveret funktion fra funktionstabellen.
Afgjort empirisk på PHP 8.3.29:

```
$ php -d disable_functions=set_time_limit -r 'var_dump(function_exists("set_time_limit"));'
bool(false)
$ php -d disable_functions=set_time_limit -r 'set_time_limit(60);'
PHP Fatal error:  Uncaught Error: Call to undefined function set_time_limit()
```

Det er altså en `Error`, ikke en advarsel.

**Fejlscenarie.** Delt hosting med `disable_functions=set_time_limit`. Ping-filen kalder den på
**linje 24 — før HMAC-verifikationen** — så hvert eneste ping fataler med en 500 uden
kontrolleret svar. Femminutters-keepalivet reproducerer det, så butikken synkroniserer
aldrig. Samtidig fataler `upgrade.php:16` (fanges af loaderen, men versionen stemples aldrig →
migrationen kører forfra for evigt) og `uninstall.php:20` (afinstallation efterlader tabeller
og API-nøgler). Bulk-handlinger og admin-pollingen fejler også.

**Modargumentet holder ikke.** Man kunne indvende at en sådan vært ville knække WordPress
selv. Det gør den ikke: **core guarder kaldet hvert eneste sted** —
`wp-admin/includes/update-core.php:1104`, `class-wp-upgrader.php:533`, `file.php:553`,
`class-wp-automatic-updater.php:553`, `ajax-actions.php:3579`, `wp-includes/comment.php:3364`
og `deprecated.php:3682` står alle bag `if ( function_exists( 'set_time_limit' ) )`. De eneste
uguardede kald i core ligger i medfølgende tredjepartsbiblioteker, og PHPMailer bruger
`@`-operatoren. Konfigurationen er altså forudset af platformen, og det er dette plugin der
knækker på den.

**Rettelse.** `if ( function_exists( 'set_time_limit' ) )` om hvert kald, og fortsæt med
værtens eksisterende tidsbudget når den mangler. Ping-stien er den vigtigste: den skal nå frem
til HMAC-kontrollen og et kontrolleret svar uanset.

---

### 16. En fejlet migration lukker ikke betalingsindgangene

`src/woocommerce-scanpay.php:256-287` — **Verificeret**

```php
	} catch ( Throwable $e ) {
		scanpay_log( 'error', 'Upgrade failed: ' . $e->getMessage() );
	}
}

define( 'WC_SCANPAY_URL', … );
add_filter( 'allowed_redirect_hosts', … );
add_filter( 'woocommerce_payment_gateways', 'wc_scanpay_register_gateways' );
add_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2 );
add_action( 'woocommerce_blocks_payment_method_type_registration', … );
```

Efter `catch` fortsætter bootstrappen ubetinget: gateways, capture-hook, Blocks og
abonnementshooks registreres, uanset om migrationen lykkedes. Det samme gælder når
transienten er sat og migrationen springes helt over. Et halvmigreret skema kan altså tage
imod nye checkout-betalinger og fornyelser, som skaber betalingsforsøg hos Scanpay mens de
lokale sync- og capture-tabeller er inkompatible.

**Modargument — og det er stærkt.** Valget er dokumenteret på stedet: at lade kastet slippe
ud ville "fatal plugins_loaded on every request and take out wp-admin". At fejle lukket ville
tage butikken offline ved en forbigående DB-hikke, hvilket for de fleste købmænd er værre end
en forsinket synkronisering — og replay-sikkerheden betyder at betalingen registreres så snart
migrationen lykkes. Fail-open er altså et forsvarligt valg, ikke en forglemmelse.

Det rapporteres fordi konsekvensen ikke er afgrænset når migrationen *aldrig* lykkes: sammen
med fund 1 og 14 betyder det en butik der tager imod betalinger i det uendelige uden at
registrere dem.

**Rettelse.** Mellemvejen frem for at fejle lukket: behold admin-diagnostik og
indstillingsskærmen, men undlad at registrere de kundevendte gateways og
capture-hooket når `wc_scanpay_version !== WC_SCANPAY_VERSION` *og* sidste forsøg fejlede —
altså først efter at retry-mekanismen har opgivet, ikke ved første fejl.

---

### 17. Uden ext-curl gemmes en aktiveret gateway der fataler ved checkout

`src/gateways/abstract-wc-gateway-scanpay-base.php:162-170`,
`src/public/generate-payment-link.php:92` — **Verificeret**

```php
// Without ext-curl the client's constructor fatals on curl_init(), and the catch
// below cannot soften it: an undefined function raises an Error, which does not
// extend Exception, so saving this form would be a white screen instead of a notice.
// Reported, not repaired -- the parent has already stored the settings.
if ( ! function_exists( 'curl_init' ) ) {
	WC_Admin_Settings::add_error( … );
	return true;
}
```

Kommentaren er ærlig om hvad grenen gør — "Reported, not repaired" — men beslutningen har en
konsekvens den ikke nævner. Nøglen er gemt og maskeret, gatewayen forbliver aktiveret, og
hverken basen eller kortgatewayen overskriver `is_available()`, så intet gater på curl.
Nede i betalingsstien konstrueres klienten uden for enhver `try`:

```php
// generate-payment-link.php:92
$client = new WC_Scanpay_Client( (string) $settings['apikey'] );
```

`WC_Scanpay_Client::__construct()` kalder `curl_init()` på første linje. Uden ext-curl er det
en `Error`, som gatewayens `catch ( Exception )` ikke fanger.

**Fejlscenarie.** Vært uden ext-curl. Købmanden indsætter en nøgle, ser admin-notitsen og
overser den — nøglen er nu maskeret og write-once, så den kan ikke fjernes igen uden
reset-knappen. En kunde vælger Scanpay ved checkout og får en hvid fatal-side i stedet for
WooCommerces generiske fejlbesked.

**Modargument.** Forudsætningen er smal: en WooCommerce-vært uden ext-curl. Derfor middel og
ikke høj. Men netop write-once-nøglen gør tilstanden svær at komme ud af igen, hvilket taler
for at fejle tidligere.

**Rettelse.** Lad curl-grenen spejle den eksisterende ugyldig-nøgle-`catch`: tvangsdeaktivér
gatewayen og ryd nøglen når den blev ændret i netop denne gemning. Og gør
`WC_Scanpay_Client::__construct()` selv til et `RuntimeException` ved manglende curl, så hver
kalder får noget de kan fange.

---

## Mindre ting

### 18. Det capturede beløb sendes uden valutaens decimaler

`src/library/class-wc-scanpay-capture.php:108-129` — **Verificeret**

`wc_scanpay_digformat()` dropper hele fraktionen når alle decimaler er nul (dokumenteret på
`math.php:57-58`). Da `$to_capture` altid kommer fra `wc_scanpay_submoney()`, mister ethvert
helt kronebeløb sine øre. Translator-kommentaren på `:123` siger at værdien ser ud som
`"99.00 DKK"`; for den almindeligste capture er den `"99 DKK"`. Eksekveret:

```
submoney('199.00','0.00')  => '199'      -> 'total' => '199 DKK'
submoney('199.50','0.00')  => '199.50'   -> 'total' => '199.50 DKK'
```

Inkonsistent formatering fra samme kodesti, og ingen af delene matcher det dokumenterede
eksempel. De to andre payloads i pluginnet bygger begge beløb med
`wc_format_decimal( …, wc_get_price_decimals() )`. Om API'et afviser `"199 DKK"` for en
2-decimalers valuta er **uverificeret**; noten og inkonsistensen står uanset.

**Rettelse.** `wc_format_decimal( $to_capture, wc_get_price_decimals() )` det ene sted
beløbet forlader pengelaget — eller ret translator-kommentaren. De to skal stemme.

### 19. `JSON_UNESCAPED_SLASHES` fjerner det eneste værn mod `</script>` i metaboksens payload

`src/admin/orders.php:139-143` — **Verificeret** (flaget) / **Uverificeret** (udnyttelighed)

```php
'window.ScanpayOrderData = ' . wp_json_encode( $props, JSON_UNESCAPED_SLASHES ) . ';',
```

`json_encode()` escaper `/` som `\/`, og det er den eneste mekanisme der forhindrer en
strengværdi med `</script>` i at afslutte elementet — `<` og `>` escapes ikke uden
`JSON_HEX_TAG`. Flaget køber intet i den anden ende: `JSON.parse` afkoder `\/` og `/` ens.
Alle nuværende værdier er begrænsede (ints, hex, `ctype_upper`-valuta,
`wc_scanpay_is_money`-beløb), så dette er dybdeforsvar, ikke et levende hul. Det rapporteres
fordi flaget er en bevidst svækkelse uden angivet grund og uden gevinst, netop det sted der
skriver en serverværdi direkte ind i en `<script>`-krop.

**Rettelse.** Drop andet argument.

### 20. Et array-typet `key` kastes til streng uden kontrol

`src/public/wp-scanpay-thankyou.php:110`, `:150` — **Verificeret**

`$_GET['key']` kan være et array (`?key[]=x`). `wp_unslash()` returnerer arrayet uændret, så
`(string)` kaster et array — `"Array"` plus `PHP Warning: Array to string conversion`.
Routeren laver kun `isset()`, så intet opstrøms begrænser typen. Sammenligningen fejler
stadig sikkert; defekten er advarslen, rejst fra `woocommerce_init` inde i `init`, altså før
headers er sendt — på en butik med `WP_DEBUG_DISPLAY` printer den ind i svaret.
`absint( wp_unslash( $_GET['scanpay_thankyou'] ) )` er array-sikker til sammenligning, så kun
`key` er ramt.

**Rettelse.** `sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) )` i begge handlere.

### 21. Betalingsreturssidens ~3,5 s poll kan afspilles ubegrænset

`src/public/wp-scanpay-thankyou.php:97-125` — **Udledt**

Ejerskabsgaten binder ventetiden til en gyldig ordrenøgle, men ikke til et *levende*
betalingsforsøg. `transaction_id` forbliver tom for evigt på en ordre der aldrig betales, så
samme URL koster fulde ~3,53 s workertid ved hvert kald, uden øvre grænse. En angriber lægger
en gæsteordre, forlader betalingsvinduet og beholder URL'en; N parallelle genafspilninger
binder N workere i 3,5 s hver. Eksponeringen er tilgængelighed alene, og forudsætningen (læg
en ordre) er en reel omkostning — derfor lav.

**Rettelse.** Bind ventetiden til et nyligt forsøg: ordren bærer allerede
`WC_SCANPAY_URI_PTIME`, og at læse den i samme forespørgsel og springe løkken over når den er
ældre end linkets 15-minutters levetid ville holde enhver ægte returnering hurtig.

### 22. `get_icon()` kalder `get_option()`, det ene kald klassen dokumenterer som farligt

`src/gateways/class-wc-gateway-scanpay-card.php:64-68` — **Verificeret**

To kommentarer i klassen fastslår reglen denne linje bryder:
`WC_Settings_API::get_option()` tvangsindlæser de dovne formularfelter for en nøgle der
mangler i det gemte option, og kortets feltfil åbner med et ubegrænset `get_pages()`.
`get_icon()` er den tredje frontend-læser af en kortindstilling og gør det modsatte af de to
andre. Med et gemt option uden `card_icons` betyder rendering af klassisk checkout altså en
forespørgsel over hver eneste side på sitet, midt i checkout-renderingen.

**Rettelse.** `$this->settings['card_icons'] ?? [ 'visa', 'mastercard' ]`, med den
`array_filter` der allerede står der — og opdatér den efterfølgende kommentar, som i dag
krediterer `get_option()` for `''`→`[]`-konverteringen.

### 23. `get_title()` returnerer "Scanpay" under `admin-ajax.php`

`src/gateways/abstract-wc-gateway-scanpay-base.php:96-103` — **Udledt, spekulativt**

`is_admin()` er sand for hvert kald til `wp-admin/admin-ajax.php`, også `nopriv`-kald forfra.
WooCommerce registrerer `update_order_review` på både `wp_ajax_`, `wp_ajax_nopriv_` og
`wc_ajax_`, og handleren gen-renderer betalingslisten. På den transport ville alle tre rækker
gen-rendere som "Scanpay" i stedet for købmandens titler.

**Modargument.** WooCommerces egen checkout-JS bruger `wc_checkout_params.wc_ajax_url`
(`?wc-ajax=…`), hvor `is_admin()` er falsk, så en standardbutik rammer det aldrig.
`wp_ajax_*`-registreringerne er bagudkompatibilitet. Derfor spekulativt.

**Rettelse.** `is_admin() && ! wp_doing_ajax()`.

### 24. Blocks-payloadens `description`- og `card_icons`-fallbacks spejler ikke gatewayens defaults

`src/gateways/blocks/class-wc-scanpay-blocks-support.php:87-98`, `:116-117`, `:127-128` —
**Verificeret** (divergensen) / **Udledt** (nåelighed). Fundet uafhængigt af to reviewere.

Kommentaren to linjer over forpligter payloaden på at holde trit med gatewayens defaults; det
holder for `title` alene:

| nøgle | klassisk fallback | Blocks-fallback |
| :-- | :-- | :-- |
| `description` (kort) | `'Pay with a payment card via Scanpay.'` | `''` |
| `description` (MobilePay) | `'Pay with MobilePay.'` | `''` |
| `description` (Apple Pay) | `'Pay with Apple Pay.'` | `''` |
| `card_icons` | `[ 'visa', 'mastercard' ]` | `[]` |

Kun ved en *fraværende* nøgle; tom værdi håndteres ens på begge sider. Nåeligheden er tynd —
den eneste fundne vej er REST-controllerens `update_item()` med `{"enabled": true}` på en
butik hvis option stadig er det `[ 'secret' => … ]`-array `install.php` laver ved aktivering.

**Rettelse.** Brug samme literaler som `default_description()`, eller drop `??`-fallbacksene
helt. Kravet er at de to stemmer.

### 25. Rå backend-diagnostik havner i ordrenoten ved fejlet capture

`src/library/class-wc-scanpay-capture.php:177-187` — **Verificeret** (mekanisme)

Samme `$e->getMessage()` går både i loggen og i en persisteret ordrenote. Nogle beskeder er
backend-interne: `"Payment lookup failed for order #$oid: {$wpdb->last_error}"` (rå
MySQL-fejlstreng), `curl_strerror()`-tekst, eller op til 512 bytes af hvad API'et eller en
proxy returnerede. Pluginnet erklærer den modsatte politik for søsterflowet i
`woocommerce-scanpay.php:224`: *"`$diagnostic` is internal; `$reason` reaches the merchant, so
never pass a backend message"* — og `WCS_Scanpay_Charge::charge()` overholder den.

Noten er admin-only og mailes aldrig (efterprøvet: `add_status_transition_note()` kalder
`add_order_note( …, 0, … )`), så der er intet kundelæk. Og flere beskeder *er* præcis hvad
købmanden har brug for ("Transaction has been voided", "No payment details found on order"),
så rettelsen skal være selektiv.

**Rettelse.** Behold domænebeskederne i noten og send kun de maskingenererede til loggen.

### 26. Valutaen kontrolleres ikke før capture

`src/library/class-wc-scanpay-capture.php:68-74`, `:108` — **Udledt**

Capture-beløbet denomineres i ordrens *nuværende* valuta, men autorisationen det indløser er
denomineret i `scanpay_meta.currency` — en kolonne der findes (`install.php:44`) og udfyldes
af sync (`:221`), men hverken læses her eller står i `SELECT`-listen. Sync validerer at de to
stemmer (`class-wc-scanpay-sync.php:262-265`), men kun inde i
`empty( $wco->get_transaction_id( 'edit' ) )`-grenen, altså præcis én gang. Scanpay afviser
formentlig en capture hvis valuta ikke matcher, hvilket gør udfaldet til et rent on-hold frem
for en forkert capture — derfor lav og udledt.

**Rettelse.** Tilføj `currency` til `SELECT` og sammenlign før payloaden bygges.

### 27. Nul-beløbs-fornyelsesgrenen afslutter en ordre der derefter fejler capture

`src/library/class-wcs-scanpay-charge.php:179-196` — **Udledt**

To ting. **(a)** Den angivne begrundelse er forkert: WCS "auto-completer" ikke
nul-beløbs-fornyelser, den fyrer simpelthen aldrig hooket. Efterprøvet i
`class-wc-subscriptions-payment-gateways.php:114-120`, som gater på `get_total() > 0`.
**(b)** Grenen kalder `payment_complete()`, og en Action Scheduler-kørsel er et normalt
request med fuld bootstrap, så prioritet-5-hooket *er* live. På en fornyelse hvis linjer alle
er virtuelle og downloadable sætter `payment_complete()` `completed`, hvilket udløser capture
— og der er ingen `scanpay_meta`-række, så capture kaster og ordren parkeres `on-hold` med en
note der påstår betalingsfejl.

**Modargument.** Svær at nå: begge WCS-indgange gater på `get_total() > 0`, og den foregående
`wc_scanpay_money_equals()`-kontrol betyder at et ikke-positivt beløb indebærer en
ikke-positiv ordretotal.

**Rettelse.** Fjern completion-hooket omkring netop det ene `payment_complete()`-kald, som
`wp-bulk-actions.php:18` allerede gør, og ret kommentaren.

### 28. Tidsgrænse og cache-flush ligger kun i den ene gren af drain-løkken

`src/callback/wc-scanpay-ping.php:301-332` — **Verificeret** (kontrolflow) / **Udledt**
(konsekvens)

Begge langtidsværn — `set_time_limit()`-fornyelsen og objekt-cache-flushet — ligger
udelukkende i `$target > $seq`-grenen. `else`-grenen er ikke en løkkeudgang: den genlæser
ping-kolonnen og hæver `$target` når en travl worker har registreret et nyere ping, hvorefter
`while` fortsætter. En drain der gentagne gange tømmer hele sit udestående interval i én runde
og så forlænges igen, looper derfor gennem `else`-grenen uden nogensinde at forny
60-sekunders-bevillingen eller flushe.

Skaden er begrænset — cursor-UPDATE'en committer efter hver side, og keepalivet henter
butikken hjem inden for minutter. Det rapporteres som at værnene er unåelige på en sti koden
bevidst holder kørende, ikke som datatab.

**Rettelse.** Flyt de to `if`-blokke ud af grenen, til lige før `$elapsed`-debuglinjen.

### 29. Et ping uden `Content-Length` afvises og kommer aldrig videre

`src/callback/wc-scanpay-ping.php:136-151` — **Udledt**

`CONTENT_LENGTH` behandles som obligatorisk, men det er en hop-for-hop-artefakt af hvordan
requestet nåede PHP, ikke noget afsenderen styrer ende-til-ende. Et request der ankommer med
`Transfer-Encoding: chunked` — eller via en HTTP/2-frontend der ikke syntetiserer en længde på
FastCGI-hoppet — har intet `$_SERVER['CONTENT_LENGTH']`, så hvert ping svares 400 før HMAC'en
kontrolleres. Fordi fejlen ligger i framingen og ikke i payloaden, reproducerer både retries og
keepalivet den: butikken synkroniserer aldrig, og eneste signal er en `wc-scanpay`-log uden
poster.

**Modargument.** Scanpay ejer pingeren og sender formentlig altid `Content-Length`, og headeren
er nyttig som billig præ-læsningsgrænse. Pointen er at *origin-sidens* framing ikke er Scanpays
at garantere.

**Rettelse.** Læs først og begræns læsningen
(`stream_get_contents( fopen( 'php://input', 'r' ), 513 )`), og behold `$cl`-lighedskontrollen
kun når headeren findes.

### 30. En oversat streng med pladsholder når den prækodede notice uescaped

`src/admin/settings/admin-options.php:29-48` — **Verificeret**

`wc_scanpay_admin_notice()` echoer `$msg` råt, og dens docblock siger
`@param string $msg Pre-escaped HTML message.` `$setup_text` bryder kontrakten: pladsholderen
fyldes med et præescapet link, men selve msgid'et går ind gennem bar `__()`. Hver anden streng
i samme kald bruger `esc_html__()`. Fordi værdien sendes som argument frem for at blive echoet,
kan PHPCS' `WordPress.Security.EscapeOutput` ikke se det — samme blinde vinkel kodebasen
allerede dokumenterer i `admin/settings.php:73-75`. Oversættelsen er ikke versionsstyret i dette
repo.

**Rettelse.** `esc_html__()`. Linkargumentet er allerede escapet, så rækkefølgen er korrekt.

### 31. Tre steder genimplementerer `wc_scanpay_is_scanpay_order()`, og kopierne er allerede drevet fra hinanden

`src/admin/hooks/wp-ajax-wc-mark-order-status.php:57`,
`src/admin/hooks/wp-ajax-wc-scanpay-capture.php:41`, `src/upgrade.php:158` — **Verificeret**

```php
wp-ajax-wc-mark-order-status.php:57: if ( ! str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
wp-ajax-wc-scanpay-capture.php:41:   if ( ! $wco || ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
upgrade.php:158:                     if ( ! $wc_sub || ! str_starts_with( $wc_sub->get_payment_method( 'edit' ), 'scanpay' ) ) {
```

`library/functions.php` erklærer selv sit formål som "Helpers shared by … so they cannot drift
apart", og `admin/orders.php:13` requirer den før begge hook-filer, så hjælperen *er* i scope.
De genimplementerer den alligevel, og de har allerede drevet: capture-endpointet beholdt
`(string)`-castet, de to andre droppede det. Alle filerne erklærer `strict_types=1`, så hvis
`get_payment_method( 'edit' )` nogensinde returnerer `null`, giver de to en `TypeError`. Ingen
af de to datastores kunne fås til at returnere `null`, så dette er robusthed og drift, ikke et
levende nedbrud.

**Rettelse.** Kald `wc_scanpay_is_scanpay_order( $wco )` alle tre steder.

### 32. Linjeantallet læses i `view`-kontekst, modsat hver anden værdi i payloaden

`src/public/generate-payment-link.php:240-248` — **Verificeret**

Hver anden værdi i payloaden læses i `edit`-kontekst — `get_name( 'edit' )` på linjen ovenover,
`get_currency( 'edit' )`, `get_total( 'edit' )`, alle ti `get_billing_*( 'edit' )`.
`get_quantity()` alene tager WooCommerces default `view`, som går gennem
`WC_Data::get_prop()`s filter og dermed lægger en vilkårlig tredjepartsværdi af
`woocommerce_order_item_get_quantity` direkte i en autentificeret API-request-body.

Indesluttet — et kast lander i item-bygningens `catch`, og beløbet kommer fra `total`, ikke
`quantity` — men filens egen kommentar på `:226-228` ræsonnerer eksplicit om hvilke filtre
løkken er eksponeret for, og dette er eksponering filens egen konvention ellers ville have
fjernet.

**Rettelse.** `$item->get_quantity( 'edit' )`, med forbehold for at basisklassen
`WC_Order_Item::get_quantity()` ikke tager noget argument.

### 33. 2.1.3-migrationen springes permanent over hvis Subscriptions er slået fra under opgraderingen

`src/upgrade.php:15`, `:99`, `:252` — **Verificeret**

```php
:15   $wcs_exists = class_exists( 'WC_Subscriptions', false );
:99   if ( $wcs_exists && version_compare( $version, '2.1.3', '<' ) ) {
:252  update_option( 'wc_scanpay_version', WC_SCANPAY_VERSION, true );
```

`$wcs_exists` gater grenen, men ikke versionsstemplet. Ét request med Subscriptions
deaktiveret er nok til at stemple forbi 2.1.3 permanent; at genaktivere Subscriptions bagefter
genåbner aldrig grenen, fordi `version_compare` nu siger at migrationen har kørt. Filens eget
design er ellers omhyggeligt netop om den skelnen — `$fresh_install`-diskriminatoren findes
fordi en versionstest alene ikke kan skelne "aldrig nødvendig" fra "allerede gjort".

**Fejlscenarie.** Købmanden deaktiverer WooCommerce Subscriptions for at isolere et
checkout-problem, opdaterer Scanpay fra 2.1.0 til 3.x i samme ombæring og genaktiverer
Subscriptions. Abonnementer hvis 1.x `_scanpay_subscriber_id` er det nyere id beholder det
forældede `WC_SCANPAY_URI_SUBID`, og fornyelser opkræver den forkerte subscriber —
`class-wcs-scanpay-charge.php:122` dokumenterer at den afhænger af netop denne backfill.

**Rettelse.** En korrekt rettelse kræver en beslutning (et markør-option for udskudt migration
kontra ikke at stemple forbi 2.1.2 mens Subscriptions mangler, hvilket ville genindtræde i
`upgrade.php` på hvert request for butikker der aldrig installerer det). Det minimale,
beslutningsfri skridt er at gøre tabet synligt: en `scanpay_log( 'warning', … )` i en
`else`-gren, så tilfældet dukker op i loggen frem for først i en fejlopkrævet fornyelse
måneder senere.

### 34. Tabellerne oprettes efter den gren der læser dem

`src/upgrade.php:110-114` mod `:211-215` — **Udledt**

`install.php` — det eneste i træet der genskaber en manglende tabel — requires i `< 2.0.0`-grenen
og igen i `< 3.0.0`-grenen, men `< 2.1.3`-grenen ligger imellem dem og læser `scanpay_meta` råt,
med et kast på enhver `$wpdb->last_error`. For en butik hvis lagrede version ligger i
`[2.0.0, 2.1.3)` har ingen af de to requires kørt endnu — `< 2.0.0` er falsk, så `elseif
< 2.2.0` tager grenen i stedet — så en manglende `scanpay_meta` er et hårdt stop som intet retry
kan rydde: MySQL 1146 ved hvert forsøg, fem minutter imellem, for evigt.

**Modargument.** Kastet er bevidst, og begrundelsen er sund: et tomt `$max_trn` fra en *fejlet*
forespørgsel ville tavst adoptere 1.x' subid på abonnementer hvis nuværende faktisk er nyere.
Den begrundelse berøres ikke af rettelsen — med `install.php` kørt først findes tabellen og er
tom, hvilket er samme legitime tilstand som en butik uden `scanpay_meta`-rækker. Dette handler
om rækkefølge, ikke om at svække kontrollen.

**Rettelse.** Hejs `require WC_SCANPAY_DIR . '/install.php';` ud af `< 3.0.0`-grenen til lige
efter loglinjen på `:46`, så hver opgraderingssti har sine tabeller før nogen gren læser dem.
Den er idempotent (tre `SHOW TABLES LIKE`), den kan ikke stemple en version dér, og
`< 2.0.0`-grenens eget require på `:53` bliver dermed overflødigt.

### 35. Manuel capture kan køre på en ordre i papirkurven

`src/admin/hooks/wp-ajax-wc-scanpay-capture.php:14-50` — **Verificeret**

Endpointet kontrollerer rettighed, en per-ordre-nonce og at betalingsmetoden starter med
`scanpay` — og går derefter direkte til `capture_or_hold()`. Der er ingen statuskontrol.

Row-action-filen guarder derimod eksplicit, og dens kommentar formulerer positionen:

```php
// wp-ajax-wc-mark-order-status.php:61-70
// … What it buys is that no capture runs, so the customer is not charged; the untrash is
// core behaviour and not ours to stop from here.
if ( in_array( $wco->get_status( 'edit' ), [ 'completed', 'trash' ], true ) ) {
	return;
}
```

Bulk-handleren har samme guard. Pluginnets erklærede position er altså at en ordre i
papirkurven ikke må captures, og capture-endpointet bryder den.

**Modargument.** Row-action-kommentarens *begrundelse* gælder ikke her: den handler om at
dens nonce er per-*handling* og derfor genbrugelig for ethvert ordre-id, mens
capture-endpointets nonce er per-ordre (`'scanpay-order-' . $oid`). Trusselsmodellen er
dermed en anden — det kræver en åben fane med en gyldig nonce for netop den ordre, som
trashes i mellemtiden. Men princippet i kommentarens sidste sætning handler om *udfaldet*,
ikke om nonce-genbrug, og det udfald kan endpointet frembringe.

**Rettelse.** Afvis trashede ordrer før capture, f.eks. en 409 når
`'trash' === $wco->get_status( 'edit' )`.

### 36. Den forældede `scanpay_queue` drænes aldrig ved opgradering

`src/upgrade.php:50`, `src/uninstall.php:63-67` — **Verificeret**

`upgrade.php:50` dropper 1.x/2.x-tabellen `woocommerce_scanpay_queuedcharges`, men
`scanpay_queue` — som `uninstall.php`s kommentar dokumenterer blev oprettet af v2.0.0–2.1.4
og "holder order ids and amounts" — røres slet ikke af nogen migration. Kun `uninstall.php:67`
dropper den. 3.x-koden læser den aldrig, så var der ikke-behandlet arbejde i køen på
opgraderingstidspunktet, strandes det tavst mens versionen stemples som fuldført.

**Modargument.** Køen indeholdt kun *ventende* arbejde i netop det øjeblik opgraderingen kørte,
og på de fleste butikker er den tom. Rækkerne er desuden ikke tabt data — Scanpay er
sandhedskilden, og en capture der aldrig kørte kan stadig køres manuelt. Derfor lav.

**Rettelse.** Detektér tabellen før 3.x-migrationen stemples. Har den rækker, så log dem
mindst — eller dræn dem gennem den nuværende capture-sti — frem for at lade dem forsvinde
uset.

### 37. `CREATE TABLE` kaster på en tabt kapløbssituation, selv når postbetingelsen er opfyldt

`src/install.php:18-32`, `:35-56`, `:62-76` — **Verificeret**

Hver af de tre tabeller følger mønsteret `SHOW TABLES LIKE` → `CREATE TABLE` →
`if ( true !== $res ) throw`. To samtidige installationer kan begge se tabellen mangle; den
ene opretter den, den anden får MySQL 1050 ("table already exists"), `$res` bliver `false`, og
den kaster — selv om den ønskede sluttilstand nu er opfyldt.

Filen håndterer den *anden* kapløbssituation korrekt og siger det udtrykkeligt:

```php
// Re-read rather than test the INSERT's return: two racing activations lose the
// duplicate-key race harmlessly, and it is the row's presence that matters, not who
// wrote it.
```

Seq-rækken genlæses altså frem for at stole på returværdien, mens tabeloprettelsen lige
ovenfor ikke gør det tilsvarende. Det er en indre inkonsistens.

**Modargument.** Kastet fanges af begge indesluttede kaldere (loaderen og reset-endpointet), og
`install.php` er idempotent, så næste forsøg lykkes. Kun kortgatewayens ukontrollerede
`require` — **fund 9** — gør et tabt kapløb til en kritisk fejlside. Derfor lav i sig selv.

**Rettelse.** Genlæs tabellen efter en fejlet `CREATE TABLE`. Findes det forventede skema nu,
så accepter det; ellers kast den oprindelige databasefejl.

### 38. Admin-hooks rammer ikke HPOS-skærmen for en rolle uden `edit_others_shop_orders`

`src/admin/orders.php:29`, `:61`, `:177`, `src/admin/subscriptions.php:91` — **Verificeret**

Pluginnet registrerer HPOS-hooks på hardkodede skærm-id'er:

```php
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', … ); // HPOS
add_filter( 'bulk_actions-woocommerce_page_wc-orders', … );        // HPOS
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', … );      // HPOS
```

Men WooCommerce afleder id'et betinget (`includes/admin/wc-admin-functions.php:82`):

```php
$screen_id = ( \WC_Admin_Menus::can_view_woocommerce_menu_item() ? 'woocommerce_page_wc-orders' : 'admin_page_wc-orders' ) . ( 'shop_order' === $for ? '' : '--' . $for );
```

og prædikatet er (`class-wc-admin-menus.php:156-158`):

```php
public static function can_view_woocommerce_menu_item() {
	return current_user_can( 'edit_others_shop_orders' );
}
```

En bruger med `edit_shop_orders` men **uden** `edit_others_shop_orders` lander altså på
`admin_page_wc-orders`, hvor ingen af pluginnets hooks er registreret.

**Fejlscenarie.** En butik med en tilpasset rolle der må redigere egne ordrer, men ikke andres.
Den bruger åbner en Scanpay-ordre på HPOS og ser ingen Scanpay-metaboks — hverken
betalingsdetaljer eller capture-knappen. Bulk-halvdelen er derimod uden virkning for dem:
HPOS' egen `get_bulk_actions()` returnerer alligevel `[]` uden `edit_others_shop_orders`.

**Modargument.** WooCommerces indbyggede `shop_manager`-rolle *har*
`edit_others_shop_orders`, så en standardinstallation er upåvirket. Det kræver en tilpasset
rolle, og konsekvensen er en manglende metaboks, ikke forkerte penge. Derfor lav.

**Rettelse.** Aflede suffikset gennem `wc_get_page_screen_id( 'shop-order' )` — og
`wcs_get_page_screen_id( 'shop_subscription' )`, som findes i
`wcs-compatibility-functions.php:656` — bag de sædvanlige `function_exists()`-værn, eller
registrere begge HPOS-varianter ved siden af de eksisterende legacy-hooks.

### 39. Scanpay-id'er og synkroniseringscursoren er 32-bit kolonner, og et overløb ville klippe tavst

`src/install.php:22-23`, `:39-41`, `:65` — **Verificeret** (mekanisme) / **Udledt** (horisont)

Alle Scanpay-kontrollerede tal er `INT unsigned`, med loft på 4.294.967.295:
`scanpay_seq.seq` og `.ping` (cursoren), `scanpay_seq.shopid`, `scanpay_meta.shopid`,
`.id` (transaktions-id), `.subid` og `scanpay_subs.subid`. De lokalt afledte felter er derimod
allerede brede: `scanpay_meta.orderid`, `mtime` og `method_exp` er `BIGINT unsigned`.

To kolonner hører **ikke** med: `nacts` er et lokalt `count( $c['acts'] )`
(`class-wc-scanpay-sync.php:216`), og `rev` er reelt 32-bit — se beviset nedenfor.

**PHP klipper ikke.** Hver værdi er `is_int()`-gatet og interpoleres derefter som en 64-bit
PHP-`int` (`wc-scanpay-ping.php:171`, `class-wc-scanpay-client.php:170`,
`class-wc-scanpay-sync.php:197-215`). `json_decode()` giver en `float` over `PHP_INT_MAX`, som
falder på `is_int()` og kaster. PHP bærer altså enten hele værdien eller fejler højlydt;
databasekolonnen er det eneste sted en værdi kan gå tavst tabt.

**Og den ville gå tavst tabt.** Jeg antog først at streng SQL-tilstand ville afvise
skrivningen — det er forkert. WordPress fjerner strengheden fra hver forbindelse
(`wp-includes/class-wpdb.php:644-651`):

```php
protected $incompatible_modes = array(
	'NO_ZERO_DATE',
	'ONLY_FULL_GROUP_BY',
	'STRICT_TRANS_TABLES',
	'STRICT_ALL_TABLES',
	'TRADITIONAL',
	'ANSI',
);
```

Standardkørslen er derfor ikke-streng: værdien klippes til 4.294.967.295 med advarsel 1264,
skrivningen lykkes, `$wpdb->last_error` forbliver tom, og `$wpdb->query()` returnerer ikke
`false`. Ingen af pluginnets fejlkontroller fanger det.

**Konsekvenser hvis det sker.**

- **`scanpay_meta.id` klippet.** Første ændring indsættes med det klippede id; hver senere
  ændring på samme transaktion rammer `upsert_meta()`s ejerskabsgate (`:161-165`), hvor
  `4294967295 !== 5000000000`, og rækken opdateres aldrig igen. Beløbene fryser, og capture
  kalder `/v1/transactions/4294967295/capture` — et fremmed id, som afvises, hvorefter ordren
  parkeres on-hold.
- **`scanpay_seq.seq` klippet.** Cursor-UPDATE'ens værn
  (`false === $res_seq || $wpdb->rows_affected < 1`) fyrer *ikke*, fordi `mtime = $now` ændrer
  rækken alligevel. Pinget svarer 200 mens den persisterede cursor står fast, og hvert
  efterfølgende ping gendræner hele historikken. Replay er sikkert by design, så intet
  korrumperes — men arbejdet fuldføres aldrig.
- **`scanpay_subs.subid` er PRIMARY KEY.** To forskellige subscriber-id'er over 2³² klipper til
  samme værdi og **smelter sammen til én række**, hvilket forstyrrer `idempotency_key()`s
  `rev`-aflæsning.

**Premissen er belagt her i træet** — ikke kun i en ekstern kilde. Forfatterens egen Go-klient
til samme API (`/code/snare/go-scanpay/api_types.go`) trækker præcis den grænse fundet beder
om:

```go
Seq  uint64  // :63        ID   uint64  // :70, :86, :109
Rev  uint32  // :71
```

`Seq` og hvert `ID` er `uint64`, mens `Rev` er `uint32`. Søstermodulerne til PrestaShop
(`module/scanpay.php:41-42`) og Magento 2 bruger desuden allerede `BIGINT UNSIGNED` til
`shopid`, `seq` og `trnid`.

**Hvorfor lav alligevel.** `seq` er beviseligt butiksscoped: klientens
monotonicitetskontrol (`class-wc-scanpay-client.php:174`) kræver at en tom seq-side returnerer
præcis samme tal, hvilket en globalt delt tæller ikke kunne. Et overløb dér ville kræve ~4,3
milliarder ændringer på én butik. Den plausible halvdel er `id` og `subid`, og kun hvis
Scanpays id-rum er globalt frem for per butik — det kan ikke afgøres ud fra dette repo.

**Rettelse.** Udvid de Scanpay-kontrollerede id- og cursorkolonner til `BIGINT unsigned` i
`install.php`, og lad `rev` og `nacts` blive. Tilføj en versionsgatet `ALTER TABLE … MODIFY`
per tabel i `upgrade.php`, formet som den eksisterende 3.0.0-gren; `MODIFY` er idempotent og
opfylder dermed reglen om at stemple versionen sidst. Intet i PHP skal ændres, og ingen floor
flytter sig — `BIGINT` er ældre end MySQL 5.5.5-floor'en.

---

## Kommentardrift

Guiden siger at *"a comment that has drifted is a broken test"*. Disse tre påstår noget der
ikke holder, uden at koden fejler.

### 40. `schema.php` hævder at identifiers ikke kan bindes

`src/library/schema.php:13-23` — **Verificeret**

> `$table` must be a `$wpdb->prefix`-derived name: an identifier cannot be bound, only the LIKE
> pattern can.

Det har ikke været sandt siden WordPress 6.2.0, som tilføjede `%i`-pladsholderen.
`docs/requirements.md` sætter WP-floor til **6.3.0**, så `%i` ligger *under* floor og kræver
intet runtime-værn — dette er altså ikke en "du kunne bruge et nyere API"-note. Efterprøvet i
`wp-includes/class-wpdb.php`: identifier-argumenter escapes gennem `_escape_identifier_value()`
(`:1390`). Ingen runtime-fejl i dag — begge kaldere sender `$wpdb->prefix . '<literal>'`.
Fejlen er den guiden nævner: en læser stoler på invarianten og bliver ved med at
håndinterpolere identifiers i troen på at der ikke findes et alternativ.

### 41. Reset-endpointets begrundelse beskriver poll-endpointerne forkert

`src/admin/hooks/wp-ajax-wc-scanpay-reset.php:140-143` — **Verificeret**

> Recreate the tables empty: the order meta box and the polling endpoints still query
> `scanpay_meta` for old Scanpay orders even with no key configured.

Efter et reset har indstillingerne ingen `apikey`, så begge poll-endpoints stopper før de rører
tabellen: `wp-scanpay-fetch-meta.php:36-38` og `wp-scanpay-fetch-sub.php` svarer
`invalid shopid`, og `wp-scanpay-fetch-ping.php:34-39` svarer 403. Kun metaboksen
(`admin/orders.php:105`) forespørger reelt `scanpay_meta` ubetinget. Konklusionen (genskab
tabellerne) holder på metaboksen alene; det er begrundelsen der er forkert.

### 42. `WC_Blocks_Utils` ligger over WooCommerce-floor, men står ikke i tabellen over værnede API'er

`src/gateways/class-wc-gateway-scanpay-applepay.php:34-43` — **Verificeret**

Koden er korrekt: værnet er der, og det falder til "klassisk" når klassen mangler. Men
`WC_Blocks_Utils::has_block_in_page()` er `@version 5.0.0`, altså langt over den erklærede WC
3.6.0-floor, og `docs/requirements.md`'s "Guarded APIs above the floor"-tabel nævner den ikke —
selv om tabellens egen regel er at netop den slags skal stå der.

**Rettelse.** Én række i `docs/requirements.md`.

---

## Hazard uden foreslået rettelse

### 43. `scanpay_meta`-rækken skrives før ordren er bekræftet som vores

`src/library/class-wc-scanpay-sync.php:237-261` — **Uverificeret, spekulativt**

Rækken lander i `scanpay_meta` nøglet på `orderid`, før noget har fastslået at den lokale ordre
med det id overhovedet er en Scanpay-ordre. Når den først er skrevet, er den autoritativ for to
andre læsere: `upsert_meta()`s ejerskabsgate og `WCS_Scanpay_Charge::charge()`s
dobbeltopkrævningsværn, som behandler *enhver* række for det `orderid` som bevis på at ordren
er betalt.

To WooCommerce-installationer med *samme* API-nøgle (en staging-klon af en live-shop er det
realistiske tilfælde) drainer samme feed. Butik B's transaktion for dens egen ordre #500 skriver
en række på butik A. A's ægte transaktion for dens egen #500 rammer så ejerskabsgaten og
returnerer — kunden har betalt, ordren står pending, ingen note.

**Hvorfor det ikke er rapporteret som defekt.** Udløseren er en fejlkonfiguration, ikke en
understøttet tilstand. Og den oplagte "tjek `shopid` først"-rettelse virker ikke: begge butikker
deler én nøgle og dermed ét `shopid`, som er præcis den kolonne rækken bærer. At skelne ville
kræve den lokale `_scanpay_payid`-kobling, hvilket er en designændring. Den nuværende
rækkefølge er desuden bærende for det erklærede design: rækken skal genopbygges ved replay, også
for ordrer der ikke længere findes lokalt. Hører formentlig hjemme i dokumentationen ("én
API-nøgle per butik") frem for i `sync()`.

---

## Efterprøvet og fundet i orden

Anført eksplicit, så det læses som kontrolleret frem for sprunget over.

**`math.php` er korrekt.** Ekstraheret og eksekveret på PHP 8.3.29. En differentiel fuzz på
200.000 tilfældige par gennem `addmoney`, `submoney`, `cmpmoney` og `money_equals`, hver
sammenlignet med `bcadd`/`bcsub`/`bccomp` i korrekt scale, gav **nul afvigelser**. Yderligere
50.000 par bekræftede at output fra add og sub altid accepteres af `wc_scanpay_is_money()` —
typen er lukket under sine egne operationer. Ingen PHP-diagnostik under `E_ALL`. Inputvalidering
afviser `+1`, ` 1`, `1e3`, `.5`, `5.`, `''`, `abc`, `1.2.3`, `--5`, `0x10`, `"1\n"`, `INF`,
`NAN`. `'1.10'` er lig `'1.1'`; `'-0.00'` normaliseres og formateres aldrig som `'-0'`. Intet
heltalsoverløb — al aritmetik er ciffer-for-ciffer, så præcisionen er ubegrænset og uafhængig af
`PHP_INT_MAX`. Hjælperne er agnostiske over for antal decimaler, så 0-, 2- og 3-decimalers
valutaer er alle eksakte. Der er ingen afrunding overhovedet, så halv-op/halv-lige-spørgsmålet
opstår ikke.

**Penge er aldrig et flydende tal.** Ingen `(float)`, `floatval`, `number_format`, `round()`
eller bar aritmetik på en beløbsvariabel nogen steder. De få `wc_format_decimal()`-kald
konverterer *ind i* strengdomænet ved WooCommerce-grænsen, hvilket er guidens "validate at the
boundary"-regel.

**Beløbet der opkræves er altid ordrens total.** Hvor summen af linjer og totalen er uenige —
negative gebyrlinjer, som `>= 0`-filteret dropper, eller under-øre-afrunding — erstatter
uenighedsgrenen listen med én `Total`-linje.

**HTTP-klienten.** TLS-verifikation står på libcurls sikre defaults, og `curl_reset()` før hvert
kald forhindrer et tidligere kald i at svække dem. `CURLOPT_FOLLOWLOCATION` er slået fra, så en
3xx ikke kan genafspille `Authorization`-headeren til en anden vært. Timeouts er proportionale
(10 s ved checkout, 20 s ved capture, 120 s ved Action Scheduler-charge). Kun 200 accepteres.
`JSON_THROW_ON_ERROR` på både encode og decode. API-nøglen interpoleres i ingen
undtagelsesbesked. `Idempotency-Key` er stabil på tværs af retries af samme charge og distinkt
på tværs af forskellige.

**HMAC-verifikationen** beregnes over de rå `php://input`-bytes, med `hash_equals()` i korrekt
argumentrækkefølge, og *før* `json_decode()`, før al DB-adgang og før låsen røres.

**Cursor- og låsedisciplin.** Præ-lås-læsningen bruges kun til at vælge gren og genlæses
eksplicit under låsen. Cursor-UPDATE'en bærer `AND seq < $seq` og kontrolleres for både `false`
og `rows_affected < 1`. Ingen sti svarer 200 med en upersisteret cursor. Replay-sikkerheden
holder som guiden beskriver.

**SQL-injektion: ingen.** Hver værdi der interpoleres i en håndbygget sætning er enten en
`int`-typet PHP-variabel eller valideret til en citationsfri tegnklasse før den når en
strengliteral.

**Autentifikation og rettigheder i admin.** Alle tre hemmelighedsbaserede endpoints
genverificerer før enhver sideeffekt, med `hash_equals()` og argumenterne i rigtig rækkefølge;
`'' === $secret`-halvdelen er det der får vinduet efter et reset til at fejle lukket.
Reset-endpointet kontrollerer `manage_woocommerce` **og** nonce før noget som helst. Capture- og
mark-status-endpointerne kontrollerer `edit_shop_orders` — samme rettighed
`WC_AJAX::mark_order_status()` selv bruger — og verificerer nonce før enhver skrivning.
Bulk-handlingernes præmis om at WooCommerce allerede har kontrolleret nonce og rettigheder holder
på både legacy- og HPOS-listetabellen.

**Betalingsreturssidens bootstrap.** Hvert symbol stien rører blev opregnet mod det den
oversprungne bootstrap ville have leveret. Filen er reelt `$wpdb`-only som dens hoved påstår:
ingen `__()`, ingen `WC_SCANPAY_URL`, ingen sync, klient, ordre-API, statusskrivning eller mail.
Begge SQL-sætninger navngiver rigtige kolonner i begge datastores.

**Returssidens autentifikation** bruger `hash_equals()` med den lagrede nøgle først, kører før
enhver `usleep()`, og `'' === (string) $row['order_key']`-forudsætningen lukker det hul et bart
`key=` ellers ville åbne. Intet skrives og intet echoes, så der er hverken IDOR eller
opregningskanal.

**`uninstall.php` er komplet.** Krydstjekket mod hver `update_option`/`add_option`/`set_transient`
i hele `src/`: de tre gateway-optioner, `wc_scanpay_version` og `wc_scanpay_updating`-transienten
er alle dækket, og der findes ingen `update_site_option`. Multisite-stien pagineres efter id
(`get_sites()` defaulter til 100), læser `$wpdb->prefix` efter hvert `switch_to_blog()` og
gendanner konteksten i en `finally`. Legacy-tabellerne fra 2.x droppes også.
`WP_UNINSTALL_PLUGIN`-guarden er på plads. Den per-ordre `_scanpay_*`-meta efterlades bevidst og
dokumenteret.

**DDL-returværdier.** `install.php` tester `true !== $res` på `CREATE TABLE`, `upgrade.php`
tester `false === $res` på `ALTER`. Begge er korrekte: `wpdb::query()` returnerer
`$this->result` for `create|alter|truncate|drop` (`class-wpdb.php:2308-2309`), altså `true` ved
succes. Ingen af tabellerne bygges med `dbDelta`, så dens whitespace-følsomhed er ikke i spil.

**Dispatch-gatenes typesikkerhed.** Admin-AJAX-gatens `match ( $_GET['x'] )` bruger streng
sammenligning, så et array falder til `default => null`. Thank-you-gatens `in_array( …, true )`
gør det samme. Ping-gatens `str_ends_with()` på en URI med query-streng rammer ikke, men det
degraderer korrekt til den normale bootstrap plus `woocommerce_api_wc_scanpay`-hooket.

**`WC_SCANPAY_URL`.** Alle forbrugere ligger i gateway-konstruktører, Blocks-support og
admin-enqueue — ingen af dem nås på de tre tidlige returstier.

**`supports[]` matcher implementeringen.** Kun kortgatewayen erklærer abonnementsfeatures;
MobilePay og Apple Pay arver basens `[ 'products' ]`. `'refunds'` optræder i ingen af dem,
hvilket stemmer med `can_refund_order(): false`.

**Blocks-payloaden lækker intet.** `get_payment_method_data()` kopierer præcis `title`,
`description`, `card_icons` og vilkårsstrengene ud af de tre option-arrays. Hverken `apikey`
eller `secret` — som bor i samme array — nævnes nogensinde, og arrayet spredes aldrig.

**Implementeringen af den write-once API-nøgle.** `validate_apikey_field()` dispatches før
`validate_{$type}_field`, tomt POST returnerer den lagrede værdi frem for at slette,
`is_apikey()` afviser en flerkolon- eller ikke-numerisk nøgle korrekt, og den lagrede nøgle
udsendes aldrig — den maskerede gren renderer slet intet `<input>`. Eneste omgåelse er
REST-stien i fund 7, og den er i dag lukket ved et tilfælde.

**Den klassiske vilkårscheckbox.** Ét prædikat driver både renderer og validator, ingen af dem er
scopet til en gateway, og `empty( $_POST['wcssp-terms'] )` er sikker mod `'0'` fordi en
WooCommerce-checkbox poster `value="1"`.
