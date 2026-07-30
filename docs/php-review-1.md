# PHP-review af `src/` — 16 åbne fund, efterprøvet mod `23fc08e`

Et uafhængigt gennemløb af alle PHP-filer i `src/`, delt i felter efter arkitekturens
flowgrænser. Hvert fund bærer citat, konkret fejlscenarie, et modargument, et `Alternativ` — en
anden vej til samme resultat — og en `Anbefaling` af hvilken af de to der bør vælges.
Anbefalingen falder på alternativet i 11 tilfælde og på den oprindelige rettelse i fire.

**Dokumentet er en arbejdsliste, ikke en rapport.** Kun åbne fund står her: et fund fjernes fra
filen når det er lukket i træet. Nummereringen er den oprindelige, og et hul i rækken er et
lukket fund, ikke et glemt — `git log -p -- docs/php-review-1.md` bærer historikken for de 28
der er væk.

Hvert af de 16 er efterprøvet linje for linje i kilden på `23fc08e`, og linjehenvisningerne
herunder er dem der gælder dér.

To fund står uden for formen. **Fund 5** er halvt lukket: dets kommentarhalvdel er rettet, mens
adfærdshullet er en produktbeslutning der ikke er truffet. **Fund 43** har hverken rettelse
eller alternativ — det er et hazard hvis udløser er en fejlkonfiguration, og har i stedet et
supplement der gør tilstanden synlig.

## Udgangspunkt

Alle fire lintere — `phpcs`, `lint:js`, `lint:style`, `tsc` — er rene på `23fc08e`. Det er hele
den maskinelle validering der findes for PHP her, så alt nedenfor er semantik ingen linter ser.
Der er ingen WordPress-installation i dette repo; **ingen påstand herunder — og ingen rettelse
skrevet ud fra dokumentet — er afprøvet på en kørende shop.**

Upstream er læst i de fulde træer, ikke i trimmede stubs: `.stubs/wordpress` (WP trunk),
`.stubs/woocommerce` (WC 11.1.0-dev) og `.stubs/woocommerce-subscriptions` (WCS 8.7.1), hvor
subscriptions-core ligger i `vendor/woocommerce/subscriptions-core/`. De tre er symlinks i
repo-roden, og det er dem der skal citeres: målene kan flytte sig, navnene gør ikke. Hver
upstream-påstand et fund hviler på er efterprøvet dér med linjehenvisning i fundet. Det gælder
især:

- `wc_format_decimal( $n, $dp )` kører `number_format( floatval( $n ), … )`
  (`wc-formatting-functions.php`) — grundlaget for at fund 18's oprindelige rettelse er en
  regression.
- `WC_Settings_API` læser egenskaben `$this->form_fields` **kun** i `get_form_fields()`
  (`abstract-wc-settings-api.php:67`); alle syv andre steder kalder metoden. Det er hvad gør
  fund 7's alternativ sikkert.
- `WC_Email::is_enabled()` anvender `woocommerce_email_enabled_{$id}` med ordren som andet
  argument (`class-wc-email.php:827`), og `send_notification()` gater på det (`:1140-1152`) —
  fund 5's alternativ. Samme fil viser at WC 11.1 hooker **enten** `queue_transactional_email`
  **eller** `send_transactional_email` afhængigt af `deferred_transactional_emails`
  (`class-wc-emails.php:130-148`), hvilket er hvad svækker fund 5's oprindelige rettelse.
- `wc_get_page_screen_id()` står på `wc-admin-functions.php:76-89` med
  `can_view_woocommerce_menu_item()`-grenen på `:82` — fund 38.

## Konventioner

- **Verificeret** — bekræftet mod kilden i dette repo eller i upstream-træet, med citat.
- **Udledt** — ræsonneret ud af koden, men afhænger af runtime-adfærd der ikke kan køres her.
- **Uverificeret** — kræver en kørende shop. Præsenteres aldrig som faktum.

Intet fund gentager noget fra guidens **Settled**-liste. Femminutters-keepalivet, den
låsefri charge-concurrency, den uscopede admin-AJAX-hemmelighed, den write-once API-nøgle og
migrationernes versionsstempling til sidst er behandlet som afgjort. Hvor et fund rører et
af de områder, angår det implementeringen, aldrig designet.

## Oversigt

De 16 åbne fund, sorteret efter **alvor** og derefter efter nummer. `Afsnit` peger på hvor
fundet står nedenfor, da rækkefølgen her ikke følger brødteksten.

| # | Fund | Alvor | Tillid | Afsnit |
|---|------|-------|--------|--------|
| 5 | Kunden får mail og downloads for ubetalte varer når capture fejler | høj | Verificeret | Ret nu |
| 15 | Med `set_time_limit()` slået fra virker alt uden for `upgrade.php` stadig ikke | høj | Verificeret | Bør rettes |
| 7 | WooCommerces REST API ser slet ingen gateway-indstillinger | middel | Verificeret | Bør rettes |
| 9 | Fejlet tabeloprettelse efter nøglegemning giver kritisk fejlside og en butik der aldrig synkroniserer | middel | Verificeret | Bør rettes |
| 10 | `wc_autocapture` læses med to modstridende fallbacks | middel | Udledt | Bør rettes |
| 18, 21, 23, 25–27, 29, 37–39 | Mindre ting — se nedenfor | lav | blandet | Mindre ting |
| 43 | `scanpay_meta`-rækken skrives før ordren er bekræftet som vores | — | Uverificeret | Hazard |

`Alvor` beskriver konsekvensen hvis fundet udløses, ikke hvor sandsynligt det er — `Tillid`
er den anden akse, og et "Udledt" fund med høj alvor er ikke det samme som et verificeret.
Fund 43 bærer ingen grad: det er en fejlkonfiguration uden foreslået rettelse.

Afsnittene nedenfor grupperer derimod efter **hvor hurtigt der bør handles**, og de to
rækkefølger afviger ét sted: fund 15 har høj alvor, men ligger i "Bør rettes", fordi udløseren
er en værtskonfiguration frem for en kodesti enhver butik går ad.

**"Ret nu" rummer kun ét fund, og det kræver en beslutning før kode.** Fund 5 er en udlevering
af varer der ikke er betalt for; det der skal afgøres er hvad en forbigående API-fejl skal
koste kunden. Det er samtidig det eneste tilbage med brugersynlig forkert adfærd på en normalt
opsat butik, verificeret mod upstream-kilden.

### Anbefalinger samlet

Hvert fund begrunder sit valg på stedet; dette er kun indgangen. `Valg` siger hvilken af de to
veje der anbefales, ikke hvor sikker den er.

| # | Anbefalet vej | Valg |
|---|---------------|------|
| 5 | `woocommerce_email_enabled_customer_completed_order`, ordre-scopet, frem for `remove_action()` på mailkøen. Adfærdsvalget står stadig åbent | alternativ |
| 7 | Giv `get_form_fields()` sin egen cache, så `form_fields` bliver REST's alene | alternativ |
| 9 | Fang lokalt og `delete_option( 'wc_scanpay_version' )`, så loaderen er retryen | alternativ |
| 10 | Én `wc_scanpay_autocapture()`-læser | alternativ |
| 15 | Tio inline-guards, plus flyt ping-stiens to kald under HMAC-kontrollen | rettelse |
| 18 | Ret translator-noten; rettelsen ville føre penge gennem en `float` | alternativ |
| 21 | `WC_SCANPAY_URI_PTIME`, som fundet foreslår | rettelse |
| 23 | `is_admin() && ! wp_doing_ajax()` | rettelse |
| 25 | Oversæt backend-beskeden ved kilden, ikke i `capture_or_hold()` | alternativ |
| 26 | `currency` i `SELECT`, som fundet foreslår | rettelse |
| 27 | Nul-total-gren i `capture()` før meta-opslaget, plus kommentarrettelsen | alternativ |
| 29 | `file_get_contents()`s `maxlen` frem for `stream_get_contents( fopen() )` | alternativ |
| 37 | `CREATE TABLE IF NOT EXISTS` | alternativ |
| 38 | Registrér `admin_page_wc-orders`-varianten ved siden af de to eksisterende | alternativ |
| 39 | `scanpay_seq` nu; `scanpay_meta`/`scanpay_subs` når Scanpay har svaret om id-rummet | alternativ |
| 43 | Ordrenote i ejerskabsgaten; rækkefølgen står | supplement |

**Tre af anbefalingerne er kommentarer, ikke kode.** Fund 18 (translator-noten),
fund 27's del (a) og fund 43's dokumentationsnote. De er de billigste på listen og hører i den
ende af arbejdet, ikke i slutningen af den.

## Afvist — gentag ikke

To forslag fra et parallelt gennemløb blev efterprøvet her mod kilden og afvist. De står så de
ikke rejses igen:

- *"Forældede signerede pings afvises i stedet for at kvitteres"* — koden håndterer allerede
  `$ping_seq === $seq` som et 200-heartbeat (`wc-scanpay-ping.php:202-209`). Kun *strengt*
  forældede pings får 400, og det er en ægte anomali værd at melde.
- *"Sync accepterer inkonsistente totaler"* — mekanismen er korrekt læst, men intet følger af
  den: `scanpay_meta.currency` har ingen læser overhovedet i pluginnet, alle fire totaler
  parres kun med WooCommerce-ordrens valuta, og capture-loftet (`authorized - captured`)
  udelukker allerede `refunded`. Forslagets anden halvdel — at kaste på `refunded > captured`
  — ville desuden lægge en ubevislig invariant på den sti hvis fejltilstand er permanent
  synkroniseringsstop for hele butikken.

---

## Ret nu

### 5. Kunden får mail og downloads for ubetalte varer når capture fejler

`src/woocommerce-scanpay.php:296-303` — **Verificeret**

**Halvdelen af fundet er lukket.** Kommentaren på kaldstedet lovede engang at capture-først
holder kunden fra at blive mailet "completed" og få downloads for ubetalte varer; den siger nu
at prioriteten køber rækkefølge og ikke forhindring. Tilbage står hullet selv, som er en
adfærdsændring og derfor kræver en beslutning.

Lytterne ligger alle på prioritet 10. Efterprøvet i WC-kilden — `queue_transactional_email`
(`class-wc-emails.php:119, :142`), `wc_downloadable_product_permissions`
(`wc-order-functions.php:494`), `wc_update_total_sales_counts` (`:1002`) og
`wc_update_coupon_usage_counts` (`:1079`).

At vores callback på prioritet 5 parkerer ordren `on-hold` stopper ikke `do_action()` — den
kører hele sin lyttekæde igennem uanset hvad en callback undervejs gør ved ordren. Og ingen af
de to konsekvenstunge lyttere gentjekker status:

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

Prioritet 5 køber altså *rækkefølge*, ikke *forhindring* — hvilket kommentaren nu også siger.

**Fejlscenarie.** Butik med `wc_autocapture = 'completed'` og et downloadbart produkt. Scanpays
API er utilgængeligt i det øjeblik købmanden markerer ordren Gennemført. Vores callback på
prioritet 5 fejler capture og sætter ordren `on-hold` med en note. `do_action` fortsætter til
prioritet 10: kunden får "Din ordre er gennemført"-mailen, downloadrettighederne tildeles, og
salgs- og kupontællere opdateres — for en ordre der aldrig blev indløst og nu står `on-hold`.
Købmanden ser en on-hold-ordre og aner ikke at varen allerede er udleveret.

**Modargument.** Prioritet 5 er stadig bedre end 10: ordrenoten og `on-hold`-statusen er på
plads *inden* mailen sendes, så købmandens revisionsspor er korrekt, og en administrator der
kigger på ordren ser den rigtige tilstand. Kommentarens sidste sætning — at WC's egen
PayPal-gateway capturer på 10 og vi ikke — er også korrekt og relevant.

**Rettelse.** Prioriteten kan ikke løse det; `do_action` kan ikke afbrydes. De efterfølgende
lyttere skal aktivt afkobles når capture fejler — f.eks. ved i fejlgrenen at fjerne
`wc_downloadable_product_permissions` og `WC_Emails::queue_transactional_email` fra det
igangværende `woocommerce_order_status_completed`, hvilket `remove_action()` godt kan midt i et
`do_action`-gennemløb.

Det er bevidst ikke gjort endnu, og bør besluttes frem for bare at blive skrevet. To ting taler
imod at gøre det uden videre. Det ændrer hvad en butik oplever ved en forbigående API-fejl: i
dag får kunden sin vare og købmanden en on-hold-ordre at rydde op i, bagefter får kunden
ingenting og en ordre der ser gennemført ud fra deres side. Og afkoblingen rammer bredt — WC's
mail-kø er ét callback for alle transaktionsmails, så den fjerner mere end "completed"-mailen i
det gennemløb. En smallere variant, der kun afkobler downloadrettighederne og lader mailen
falde, er formentlig det rigtige kompromis, men det er en produktbeslutning.

**`remove_action()` på mailen er desuden blevet skrøbelig.** Efterprøvet i WC 11.1: der er ikke
længere ét callback-navn at fjerne. `WC_Emails::init_transactional_emails()` hooker **enten**
`queue_transactional_email` **eller** `send_transactional_email` på hele listen af
statusovergange, afgjort af `deferred_transactional_emails`-featureflaget og
`woocommerce_defer_transactional_emails`-filteret (`class-wc-emails.php:130-148`). En rettelse der
navngiver det ene callback bliver en tavs no-op på halvdelen af butikkerne, og hvilken halvdel er
en indstilling købmanden kan skifte.

**Alternativ — undertryk den ene mail gennem WooCommerces eget per-mail-filter.**
`WC_Email::is_enabled()` anvender `woocommerce_email_enabled_{$id}` og får ordren som andet
argument (`class-wc-email.php:827`), og `send_notification()` — det kald
`WC_Email_Customer_Completed_Order::trigger()` ender i — returnerer tidligt når filteret svarer
falsk (`:1140-1152`). Filteret ligger altså *under* begge transporter og er uafhængigt af
featureflaget. WooCommerce bruger selv mønsteret (`PointOfSaleEmailHandler.php:44`). Ordre-scopet,
så en bulk-kørsel ikke taber mailen for de øvrige ordrer i samme request:

```php
// library/functions.php
/**
 * Withhold the "order completed" mail for one order in this request. The capture failed, so the
 * customer must not be told the order is done -- but do_action() cannot be stopped, and the mail
 * transport is two different callbacks depending on deferred_transactional_emails, so the filter
 * WC_Email::is_enabled() applies is the only place under both.
 */
function wc_scanpay_withhold_completed_email( int $oid ): void {
	static $blocked = [];
	if ( ! $blocked ) {
		add_filter(
			'woocommerce_email_enabled_customer_completed_order',
			function ( $enabled, $order ) use ( &$blocked ) {
				return ( $order instanceof WC_Order && isset( $blocked[ $order->get_id() ] ) ) ? false : $enabled;
			},
			10,
			2
		);
	}
	$blocked[ $oid ] = true;
}
```

og downloadrettighederne som fundet foreslår, men kun dem:

```php
remove_action( 'woocommerce_order_status_completed', 'wc_downloadable_product_permissions', 10 );
```

**Anbefaling: alternativet, men adfærdsvalget står stadig åbent.** Alternativet er den bedre
*mekanisme* uanset hvad der besluttes: det rammer præcis den ene mail frem for hele
transaktionskøen, virker på begge WC-transporter, og er den dokumenterede indgang frem for et
indgreb i hook-registret midt i et `do_action`. Det afgør ikke *om* mailen skal falde — det er
den produktbeslutning fundet beskriver, og den er uændret. Bemærk at den smalle variant
(downloads afkobles, mailen sendes) er inkonsistent på en anden måde: kunden får en
"gennemført"-mail og et download-link der ikke virker. Vælges alternativet, hører de to sammen.

**Reviewnote.** Mailfilteret er ordre-scopet, men det foreslåede `remove_action()` for
downloads er request-scopet. Når den første fejlede capture fjerner callbacket, mister
enhver senere ordre der bliver `completed` i samme request også sine downloadrettigheder
— netop bulk-/automationsformen som mailalternativet tager højde for. Callbacket skal
gendannes efter den aktuelle statusovergang, eller download-undertrykkelsen skal have sit
eget ordre-scopede værn; ellers flytter anbefalingen bare den brede bivirkning fra mail til
downloads.

---

## Bør rettes

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

Den nærliggende genvej — at tilføje en `validate_setting_apikey_field()` og lade dispatchen
finde den — **virker ikke**. De metoder er `WC_REST_Controller`s egne, ikke gatewayens:
`validate_setting_text_field()` og dens søskende er deklareret på controlleren, og V3's
`WC_REST_Payment_Gateways_Controller` overskriver `validate_setting_multiselect_field()` samme
sted. Bekræftet i
`.stubs/woocommerce/includes/rest-api/Controllers/Version2/class-wc-rest-payment-gateways-v2-controller.php:178-181`
og
`.stubs/woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-payment-gateways-controller.php:234`.
Vi ejer ingen af de klasser, så at fjerne feltet er den eneste håndtag der er.

**Rettelse.**

```php
public function init_form_fields(): void {
	$fields = $this->get_form_fields();
	unset( $fields['apikey'] );
	$this->form_fields = $fields;
}
```

og docblocken omskrevet til at sige at metoden kun kaldes af REST-controllerne, samt hvorfor
`apikey` mangler.

**Prisen, som bør stå i den docblock.** `$this->form_fields` er både det REST læser *og*
`get_form_fields()`s cache (`:74`), så tildelingen forgifter cachen for resten af requestet:
et senere `get_form_fields()` ser en ikke-tom `form_fields`, springer requiret over og
returnerer et sæt uden `apikey` — med `set_defaults` og
`woocommerce_settings_api_form_fields_scanpay` anvendt anden gang oveni. Det gør især
`process_admin_options()` blind for feltet, da dens `get_post_data()`-løkke itererer netop
`get_form_fields()`: nøglen ville hverken valideres eller gemmes. Det er ikke nåeligt i dag,
fordi REST-stien og admin-gemmestien aldrig deler request, men det er en afhængighed der ikke
er skrevet ned nogen steder, og som en fremtidig kalder af `init_form_fields()` ville falde i.
Den anden vej — at lade egenskaben bære hele sættet og i stedet afvise skrivningen — er lukket
af afsnittet ovenfor.

**Alternativ — giv `get_form_fields()` sin egen cache, så prisen forsvinder.** Hele
cache-forgiftningen kommer af at `$this->form_fields` gør to ting: den er REST-controllerens
vindue *og* vores memo. Efterprøvet i WC 11.1, at de to kan skilles risikofrit:
`WC_Settings_API` læser egenskaben **kun** i `get_form_fields()` (`abstract-wc-settings-api.php:67`),
og alle syv øvrige steder i klassen — `admin_options()`, `process_admin_options()`,
`get_option()`, `get_field_value()` — kalder metoden (`:87, :211, :285, :306, :340`). Egenskaben er
altså udelukkende REST's, så snart vi holder vores memo et andet sted:

```php
/** Memo for get_form_fields(). Not $this->form_fields: that property is what the REST
 * controllers read, and init_form_fields() below gives them a different set. */
private array $fields = [];

public function get_form_fields(): array {
	if ( ! $this->fields ) {
		$fields                           = require WC_SCANPAY_DIR . '/admin/settings/fields/' . $this->id . '.php';
		$fields['title']['default']       = $this->default_title();
		$fields['description']['default'] = $this->default_description();
		$this->fields                     = $fields;
	}
	return apply_filters(
		'woocommerce_settings_api_form_fields_' . $this->id,
		array_map( [ $this, 'set_defaults' ], $this->fields )
	);
}

/**
 * Only the REST controllers and the CLI call this: both run it and then read the
 * form_fields property directly, where WC_Settings_API itself never does. 'apikey' is
 * withheld because the controller dispatches its own validate_setting_text_field() for an
 * unknown type -- validate_apikey_field() is never consulted -- so a listed key would let
 * PUT replace a stored one and orphan scanpay_seq and scanpay_meta onto another shop.
 */
public function init_form_fields(): void {
	$fields = $this->get_form_fields();
	unset( $fields['apikey'] );
	$this->form_fields = $fields;
}
```

**Anbefaling: alternativet.** Det er tre linjer mere end rettelsen ovenfor og fjerner hele det
afsnit der beskriver prisen: `get_form_fields()` bliver upåvirket af at REST har kigget,
`process_admin_options()` kan ikke blive blind for `apikey`, og der er ingen udokumenteret
rækkefølgeafhængighed tilbage for en fremtidig kalder at falde i. Rettelsen ovenfor virker i dag,
men holder kun så længe REST-stien og admin-gemmestien aldrig deler request — en invariant intet
i koden håndhæver.

---

### 9. Fejlet tabeloprettelse efter nøglegemning giver kritisk fejlside og en butik der aldrig synkroniserer

`src/gateways/class-wc-gateway-scanpay-card.php:101-117` — **Verificeret** (kodesti) /
**Udledt** (runtime)

```php
if ( $new && $new !== $old ) {
	require WC_SCANPAY_DIR . '/install.php';
}
```

`install.php` kaster `Exception` fire steder (`:36`, `:60`, `:80`, `:99`) — tre fejlede
`CREATE TABLE` og seq-rækkens seed. Hver anden kalder indeslutter den: loaderen wrapper
`upgrade.php` i `try/catch` (`woocommerce-scanpay.php:277-285`), og reset-endpointet wrapper
den direkte (`wp-ajax-wc-scanpay-reset.php:139-145`). Netop dette kaldested gør det ikke, og
kæden over det — `WC_Settings_Payment_Gateways::save()` → `WC_Admin_Settings::save()` — har
intet `try/catch`. `install.php`s eget filhoved opregner de tre kaldere og angiver at den
kaster; det er kun her ingen tager imod.

**Fejlscenarie.** Købmanden indsætter en gyldig nøgle og gemmer. Nøglen gemmes og valideres
mod `seq(0)` — begge lykkes. `install.php` fejler derefter i at indsætte `scanpay_seq`-rækken
(DB-fejl, read-only replika, fuld tablespace). Undtagelsen slipper ud og wp-admin viser
WordPress' kritiske fejlside. Nøglen er allerede gemt, så ved hver senere gemning returnerer
`validate_apikey_field()` den lagrede værdi, `$new === $old`, og `install.php` requires aldrig
igen fra gatewayen; versionsgaten kører heller ikke `upgrade.php` igen, fordi versionen blev
stemplet af det første loader-gennemløb — der er ingen aktiveringshook, gaten *er*
installationsstien. Nettoresultat: en butik med en maskeret, fungerende nøgle, en grøn
indstillingsskærm og ingen seq-række — ping-handleren svarer "shop not configured" for altid.
Eneste udvej er reset-knappen, hvis tekst handler om at slette data.

**Modargument.** "Fail loud" er den erklærede politik, og et ufanget kast er højlydt. Men
guidens formulering er *"Primitives throw; one place per flow catches"* — og dette flow har
ingen fanger. Udslippet ødelægger desuden det retry kastet findes for at udløse. Den permanente
halvdel af skaden er til gengæld begrænset: `upgrade.php:52` requirer `install.php` først og ubetinget, så den næste
plugin-opdatering — enhver ændring af `WC_SCANPAY_VERSION` — genkører seed'et og reparerer den
manglende seq-række af sig selv. Butikken står altså stille til næste opdatering frem for for
evigt, hvilket flytter fundet fra "kun reset hjælper" til "tavs indtil noget andet sker".

**Rettelse.** Wrap requiret og rapportér gennem samme kanal som resten af metoden
(`scanpay_log()` + `WC_Admin_Settings::add_error()`). En retry-sti bør stadig oveni, da
`$new !== $old`-gaten forbliver falsk bagefter og næste version kan ligge måneder ude;
billigst er at re-require når butikkens seq-række mangler, frem for kun når nøglen skiftede.

**Alternativ — fang lokalt, og lad loaderen være retryen.** Rettelsen ovenfor bygger et andet
fejlhåndteringslag: en ny `try/catch` *og* en ny tilstandstest med sin egen DB-læsning på hver
gemning af indstillingerne. Men der findes allerede præcis én sti med en fanger, en throttle og et
retry — loaderens versionsgate — og den kan overtage jobbet med én linje, fordi den betingelse den
gater på er en option vi selv ejer:

```php
if ( $new && $new !== $old ) {
	try {
		require WC_SCANPAY_DIR . '/install.php';
	} catch ( Throwable $e ) {
		// Hand the retry to the loader gate rather than growing a second one here: it is the
		// install path, it catches, and its transient throttles a failing run to one attempt
		// per five minutes until it succeeds. Unstamping the version is what re-arms it.
		delete_option( 'wc_scanpay_version' );
		scanpay_log( 'error', 'Could not create the Scanpay tables: ' . $e->getMessage() );
		WC_Admin_Settings::add_error(
			__( 'Error: The Scanpay database tables could not be created. Please try again or contact support.', 'scanpay-for-woocommerce' )
		);
	}
}
```

Prisen er at næste request kører hele migrationslisten igen. Den er idempotent — `v2-0-0.php`
læser nyeste nøgle først, `v2-5-0.php` skriver kun en fraværende nøgle, `v3-0-0.php`
introspicerer skemaet — så det koster nogle forespørgsler, ikke korrekthed. Og redirect'et tilbage
til indstillingsskærmen *er* næste request, så reparationen sker med det samme frem for ved næste
plugin-opdatering.

**Anbefaling: alternativet.** Det lukker begge halvdele af fundet — ingen kritisk fejlside, og
ingen permanent tilstand — uden en ny tilstandstest, en ny hjælpefunktion eller en ekstra
forespørgsel på den normale gemning. Én kobling skal nævnes: et nulstillet versionsstempel er
præcis den betingelse ping-gaten svarer 503 på, så butikken synkroniserer ikke mens
install bliver ved at fejle. Det er den rigtige tilstand — der er ingen tabeller at synkronisere
til — og den er en forbedring på diagnostiksiden: i dag er udfaldet en grøn skærm og tavshed,
bagefter er det en fejllinje hvert femte minut.

---

### 10. `wc_autocapture` læses med to modstridende fallbacks

`src/woocommerce-scanpay.php:151`, `src/admin/orders.php:20`,
`src/admin/hooks/wp-ajax-wc-mark-order-status.php:95` mod
`src/admin/settings/fields/scanpay.php:73-82` — **Udledt**

Feltets default er `'completed'`. De to *producenter* følger den; de tre *forbrugere* gør
det modsatte:

```php
public/generate-payment-link.php:64:      $autocapture = (string) ( $settings['wc_autocapture'] ?? 'completed' );
library/class-wcs-scanpay-charge.php:264: $autocapture = $this->settings['wc_autocapture'] ?? 'completed';

// mod, tre steder:
if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
```

Betalings*oprettelses*-siden tolker en manglende nøgle som `'completed'` — "autocapture ikke
hos Scanpay, butikken capturer ved ordreafslutning" — mens hver *afslutnings*-side tolker den
som alt-andet-end-`'completed'` og derfor aldrig capturer.
`WC_Settings_API::get_option( 'wc_autocapture' )` ville også svare `'completed'`, så
afslutningssiderne er uenige med WooCommerces egen opfattelse af de gemte indstillinger.

**Divergensen er siden skrevet ned, ikke lukket.** `v2-5-0.php:28-30` navngiver den præcist:

```php
// The one migrated key that must be written rather than left to a default: an absent
// wc_autocapture reads as 'completed' in generate-payment-link.php but as off in the order
// screens.
```

Migrationen skriver derfor altid nøglen. Det er den rigtige lokale beslutning, men den
behandler symptomet: kommentaren dokumenterer to modstridende læsninger af samme option som
en tilstand koden skal navigere udenom, frem for som noget der bør bringes til at stemme.

**Fejlscenarie.** Indstillingsarrayet findes, men mangler nøglen. Vinduet er smalt:
`v2-0-0.php:55` sætter `capture_on_complete` (persisteret på `:59`) og `v2-5-0.php:31-33`
udleder `wc_autocapture` af den (persisteret på `:36`), og de to kører ryg mod ryg i samme
løkke (`upgrade.php:76-83`) uden noget imellem sig. Tilbage er kun en afbrydelse mellem de to
`update_option()`-kald: en PHP-fatal, et hårdt tidsudløb eller en deploy midt i gennemløbet. Fordi versionen stemples
til sidst, består tilstanden indtil næste request, mens `enabled` og `apikey` blev båret
uændret over, så butikken er live. I det vindue får en fysisk ordre `'autocapture' => false`
i sit betalingslink (kun autorisation), og at afslutte den læser `''`, springer capture over
og skriver hverken note eller logline. Købmanden ser en afsluttet, "betalt" ordre og sender
varer mod en autorisation der aldrig indløses.

**Modargument.** Efter enhver gemning af kortindstillingerne skriver
`process_admin_options()` samtlige formularfelter, så nøglen er til stede på en normalt
konfigureret butik, og migrationens retry lukker vinduet fem minutter senere. Vinduet er
smalt: et halvmigreret eller håndredigeret option, ikke normaltilstanden. Det er stadig en
divergens med pengekonsekvens og uden værn, og `''`-fallbacken er den eneste af de fire der
modsiger feltets default.

**Rettelse.** Lad de tre afslutningssider læse `?? 'completed'`. At ændre de to andre til
`''` er den forkerte retning: det ville tavst skifte en halvmigreret butik til
øjeblikkelig-capture-semantik.

**Alternativ — én læser, så divergensen ikke kan opstå igen.** Rettelsen ovenfor bringer fem
læsesteder til at stemme; den efterlader dem fem. Guiden praktiserer allerede det modsatte for
vilkårscheckboxen — ét prædikat bag både renderer og validator — og samme form findes for
`title`/`description`, hvor `get_form_fields()` injicerer defaulten frem for at lade feltfilen og
gatewayen stave den hver især. Nøglen hører til samme kategori:

```php
// library/functions.php
/**
 * The wc_autocapture setting: 'off' (manual), 'on' (autocaptured at Scanpay) or 'completed'
 * (captured when the order completes). The one reader, because an absent key used to read as
 * 'completed' where payments are created and as off where they are completed -- the same option
 * deciding two different things about the same order.
 */
function wc_scanpay_autocapture( array $settings ): string {
	return (string) ( $settings['wc_autocapture'] ?? 'completed' );
}
```

Kaldstederne beholder deres form, så de tre afslutningssider bevarer den `is_array()`-gren de har
af andre grunde:

```php
if ( ! is_array( $settings ) || 'completed' !== wc_scanpay_autocapture( $settings ) ) {
```

Fire af de fem filer requirer `library/functions.php` i dag; kun `woocommerce-scanpay.php` gør
ikke, og dens kaldsted (`:151`) requirer allerede capture-klassen tre linjer længere ned, som selv
requirer filen.

**Anbefaling: alternativet.** Fundet er ikke at tre steder står forkert — det er at samme option
kan læses to måder, og at `v2-5-0.php`s kommentar har måttet skrive tilstanden ned som noget koden
navigerer udenom. En delt læser fjerner muligheden frem for den aktuelle instans, og den er stedet
hvor defaulten kan begrundes én gang. Prisen er én funktion og én require-linje.

**Reviewnote.** Opgørelsen af requires er forkert: hverken
`public/generate-payment-link.php` eller `library/class-wcs-scanpay-charge.php` requirer
`library/functions.php` direkte. Den første får den i praksis via gatewayklassernes
load-rækkefølge; den anden kan få den via `WC()->payment_gateways()`, men
`woocommerce_scheduled_subscription_payment_scanpay` kan også fyres direkte af tredjepart,
som klassens egen kommentar forudser. Anbefalingen må derfor enten give de to moduler deres
egen eksplicitte dependency eller loade hjælperen centralt før hooket registreres; én ny
require-linje i `woocommerce-scanpay.php` er ikke i sig selv hele prisen.

---

### 15. På en vært der har slået `set_time_limit()` fra, virker alt uden for `upgrade.php` stadig ikke

`src/callback/wc-scanpay-ping.php:25-26`, `:364`, `src/uninstall.php:20`, `:81`, `:107`,
`src/admin/hooks/wp-bulk-actions.php:40`, `:50`,
`src/admin/ajax/wp-scanpay-fetch-meta.php:50`, `src/admin/ajax/wp-scanpay-fetch-sub.php:50` —
**Verificeret** (eksekveret)

**Ét kaldsted bærer værnet allerede.** `upgrade.php:25-32`, med en begrundelse der er fundets
egen:

```php
// Guarded because a host can disable set_time_limit(), and PHP 8 removes a disabled function
// from the function table: the call is an Error, not the warning it was. Unguarded it lands
// above every branch and above the version stamp, and the loader catches Throwable and keeps
// its transient -- so such a shop would fatal here every five minutes forever, never migrate
// and keep serving a 2.x schema. The host's own time budget is the fallback.
if ( function_exists( 'set_time_limit' ) ) {
```

De øvrige ti — hele ping-stien, afinstallationen, bulk-handlingerne og begge
admin-poll-endpoints — er uguardede: ni `set_time_limit()`-kald plus `ignore_user_abort()` på
`wc-scanpay-ping.php:25`, som er samme klasse. Værnet er altså anerkendt som nødvendigt i
træet; det er bare ikke sat de steder hvor konsekvensen er størst. `set_time_limit()` og `ignore_user_abort()` kan slås fra med `disable_functions`, og i
PHP 8 fjernes en deaktiveret funktion fra funktionstabellen. Afgjort empirisk på PHP 8.3.29:

```
$ php -d disable_functions=set_time_limit -r 'var_dump(function_exists("set_time_limit"));'
bool(false)
$ php -d disable_functions=set_time_limit -r 'set_time_limit(60);'
PHP Fatal error:  Uncaught Error: Call to undefined function set_time_limit()
```

Det er altså en `Error`, ikke en advarsel.

**Fejlscenarie.** Delt hosting med `disable_functions=set_time_limit`. Ping-filen kalder den på
**linje 26 — før HMAC-verifikationen** — så hvert eneste ping fataler med en 500 uden
kontrolleret svar. Femminutters-keepalivet reproducerer det, så butikken synkroniserer
aldrig. Migrationen kører igennem takket være guarden i `upgrade.php`, men det hjælper ikke:
butikken er migreret og modtager stadig ingen betalinger, fordi ingen ping når frem. Samtidig fataler `uninstall.php:20`
(afinstallation efterlader tabeller og API-nøgler), og bulk-handlinger og admin-pollingen
fejler også.

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

**Alternativ 1 — en global shim ét sted. Virker, og skal afvises.** Det oplagte svar på tio
guards er at guarde *definitionen* i stedet for kaldene, og PHP tillader det: en deaktiveret
funktion er væk fra funktionstabellen, så navnet kan genbruges. Eksekveret på PHP 8.3.29:

```
$ php -d disable_functions=set_time_limit -r \
    'if(!function_exists("set_time_limit")){function set_time_limit(int $s):bool{return false;}}
     set_time_limit(60); echo "ok\n";'
ok
```

Det skal alligevel ikke gøres. En global funktion med et PHP-internt navn gælder for hele
requestet, ikke for pluginnet: WordPress core, WooCommerce og hvert andet plugin på siden ville
derefter kalde vores stub. Det er en semantikændring for fremmed kode, betalt for at spare atten
linjer. Nævnt her, fordi det er den løsning en læser ellers finder selv.

**Alternativ 2 — en hjælper i `library/functions.php`.** Den dækker syv af de tio kaldsteder, men
ikke de tre i `uninstall.php`: WordPress inkluderer den fil uden pluginnets konstanter, klasser
eller hjælpere, og filens eget hoved gør det til en regel — *"which is why every name here is
spelled out"*. En hjælper der virker fire steder og en inline-guard tre steder er to mekanismer for
ét værn.

**Anbefaling: rettelsen ovenfor, plus én flytning.** Tio ensartede inline-guards slår en hjælper
med en undtagelse, netop fordi `uninstall.php`s isolation er bevidst. Men ping-stiens to kald bør
*flyttes* frem for kun at blive guardet: de ligger på `:25-26`, før HMAC-verifikationen på `:172`,
så de i dag udføres for en uautentificeret kalder. Flyttes de ned under den — til lige før
`$flock->acquire()` på `:245`, stadig før alt langvarigt — deler de én guard, én begrundelse, og et
uforfalsket ping rører dem ikke:

```php
// Below the HMAC check: a request that cannot prove itself must not reach either call. Guarded
// for the reason upgrade.php:25-32 states -- a host can disable both, and in PHP 8 that makes
// the call an Error rather than the warning it was. The host's own budget is the fallback.
if ( function_exists( 'ignore_user_abort' ) ) {
	ignore_user_abort( true ); // Keep draining after Scanpay gives up and disconnects.
}
if ( function_exists( 'set_time_limit' ) ) {
	set_time_limit( 60 );
}
```

`ignore_user_abort()` tåler flytningen: Scanpay giver op efter ~7 s, og HMAC-kontrollen ligger
millisekunder inde i requestet.

---

## Mindre ting

### 18. Det capturede beløb sendes uden valutaens decimaler

`src/library/class-wc-scanpay-capture.php:114-135` — **Verificeret**

`wc_scanpay_digformat()` dropper hele fraktionen når alle decimaler er nul (dokumenteret på
`math.php:57-58`). Da `$to_capture` altid kommer fra `wc_scanpay_submoney()`, mister ethvert
helt kronebeløb sine øre. Translator-kommentaren på `:133` siger at værdien ser ud som
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

**Den første halvdel af den rettelse er en regression.** Efterprøvet i WC 11.1:
`wc_format_decimal()` med et `$dp`-argument kører
`number_format( floatval( $number ), $dp, '.', '' )` (`wc-formatting-functions.php`). Den
konverterer altså gennem en `float` — præcis det guiden forbyder, og her på et beløb der lige er
kommet *ud* af det præcise decimallag. De to andre payloads bruger den med rette, fordi de
konverterer WooCommerce-floats *ind i* strengdomænet ved grænsen; her ville den konvertere den
anden vej.

**Alternativ — ret kommentaren, og pad i strengdomænet hvis API'et kræver det.**
Translator-noten skriver `"99.00 DKK"` for det almindeligste tilfælde `"99 DKK"`; den er
defekten, og den er én linje. Er svaret på det uverificerede spørgsmål derimod at Scanpay afviser
`"199 DKK"` for en 2-decimalers valuta, hører paddingen i `library/math.php`, som en helper ved
siden af `wc_scanpay_digformat()` — ciffer for ciffer, som alt andet i den fil.

**Anbefaling: alternativet.** Kommentaren er det der er forkert i dag; koden er kun inkonsistent.
Og fundet er lav alvor netop fordi ingen har vist at API'et afviser formen — så det billigste rigtige
skridt er at bringe noten i overensstemmelse med hvad koden sender, ikke at føre penge gennem en
float for at få noten til at passe. Spørg Scanpay om formen, før beløbet røres.

### 21. Betalingsreturssidens ~3,5 s poll kan afspilles ubegrænset

`src/public/wp-scanpay-thankyou.php:99-133` — **Udledt**

Ejerskabsgaten binder ventetiden til en gyldig ordrenøgle, men ikke til et *levende*
betalingsforsøg. `transaction_id` forbliver tom for evigt på en ordre der aldrig betales, så
samme URL koster fulde ~3,53 s workertid ved hvert kald, uden øvre grænse. En angriber lægger
en gæsteordre, forlader betalingsvinduet og beholder URL'en; N parallelle genafspilninger
binder N workere i 3,5 s hver. Eksponeringen er tilgængelighed alene, og forudsætningen (læg
en ordre) er en reel omkostning — derfor lav.

**Rettelse.** Bind ventetiden til et nyligt forsøg: ordren bærer allerede
`WC_SCANPAY_URI_PTIME`, og at læse den i samme forespørgsel og springe løkken over når den er
ældre end linkets 15-minutters levetid ville holde enhver ægte returnering hurtig.

**Alternativ — bind på ordrens oprettelsestid i stedet.** `PTIME` er ordremeta, så på HPOS koster
den et `LEFT JOIN` mod `wc_orders_meta`; `o.date_created_gmt` står i den tabel forespørgslen
allerede læser og er dermed gratis dér. Den er også strengere: `PTIME` skrives om ved hvert nyt
betalingslink, så en angriber med ordrenøglen kan fornye sit vindue ved at bede om et nyt link,
hvor oprettelsestiden ikke kan flyttes.

**Anbefaling: rettelsen ovenfor.** `PTIME` er den eneste af de to der følger det ventetiden findes
for — racet mellem redirect'et fra betalingsvinduet og sync — og oprettelsestiden taber netop den
legitime sti hvor en kunde betaler ordren senere fra "afvent betaling"-linket. At fornye vinduet
koster desuden angriberen et helt checkout-gennemløb per 15 minutter, som er dyrere end de 3,5 s
workertid det køber. Alternativets fordel er reel, men den prisen er den forkerte vej.

### 23. `get_title()` returnerer "Scanpay" under `admin-ajax.php`

`src/gateways/abstract-wc-gateway-scanpay-base.php:117-130` — **Udledt, spekulativt**

`is_admin()` er sand for hvert kald til `wp-admin/admin-ajax.php`, også `nopriv`-kald forfra.
WooCommerce registrerer `update_order_review` på både `wp_ajax_`, `wp_ajax_nopriv_` og
`wc_ajax_`, og handleren gen-renderer betalingslisten. På den transport ville alle tre rækker
gen-rendere som "Scanpay" i stedet for købmandens titler.

**Modargument.** WooCommerces egen checkout-JS bruger `wc_checkout_params.wc_ajax_url`
(`?wc-ajax=…`), hvor `is_admin()` er falsk, så en standardbutik rammer det aldrig.
`wp_ajax_*`-registreringerne er bagudkompatibilitet. Derfor spekulativt.

**Rettelse.** `is_admin() && ! wp_doing_ajax()`.

**Alternativ — luk fundet uden at røre koden.** Det er markeret *spekulativt* af en grund: WC's
egen checkout-JS bruger `wc_checkout_params.wc_ajax_url` (`?wc-ajax=…`), hvor `is_admin()` er falsk,
så en standardbutik rammer det aldrig. En sætning i docblocken om at `wp_ajax_*`-transporten ville
se admin-titlen er nok til at fundet er behandlet frem for overset.

**Anbefaling: rettelsen ovenfor.** Den er to ord og fjerner en betingelse man ellers skal ræsonnere
om — billigere end den kommentar der skulle forklare hvorfor den ikke er der. Bemærk at fundet ikke
rører branding-beslutningen selv, som er afgjort.

### 25. Rå backend-diagnostik havner i ordrenoten ved fejlet capture

`src/library/class-wc-scanpay-capture.php:181-195` — **Verificeret** (mekanisme)

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

**Alternativ — oversæt ved kilden, så der intet er at klassificere.** Rettelsen ovenfor kræver at
`capture_or_hold()` kan skelne de to slags beskeder fra hinanden, og den ser dem alle som én
`$e->getMessage()`. Men de maskingenererede har kun to kilder, og begge kan indpakkes hvor de
opstår: DB-læsningen (`:78`, `{$wpdb->last_error}`) og klientkaldet (`:119`, som bærer
`curl_strerror()`-tekst og op til 512 bytes backend-svar). Logges detaljen dér og kastes en
købmandsvendt tekst videre, når kun domænebeskederne noten:

```php
try {
	self::$client->capture( (int) $meta['id'], [ 'total' => $amount, 'index' => (int) $meta['nacts'] ] );
} catch ( Exception $e ) {
	// $diagnostic/$reason as woocommerce-scanpay.php:230 states it: the backend message is
	// internal, and this one is persisted on the order where the merchant reads it.
	scanpay_log( 'error', "Order #$oid: the capture request failed: " . trim( $e->getMessage() ) );
	throw new \RuntimeException( 'The capture request to Scanpay failed' );
}
```

De fire domænebeskeder — "Transaction has been voided", "No payment details found on order" og de
to nøglefejl i `init()` — passerer uændret, fordi de kastes lokalt.

**Anbefaling: alternativet.** Det er samme antal linjer og har ingen central liste over hvad der
er "sikkert nok til en ordrenote" at holde ajour, når en fremtidig `throw` føjes til. Det er
desuden præcis det mønster `WCS_Scanpay_Charge` allerede overholder for søsterflowet, og som
`woocommerce-scanpay.php:230` skriver som regel — så rettelsen bringer capture i overensstemmelse
med en politik der findes, frem for at opfinde en anden.

**Reviewnote.** Alternativet indpakker kun DB-opslaget og klientkaldet, men den ydre
`catch ( \Throwable )` kan også modtage rå fejl fra pengelagets validering og fra
WooCommerce-kald som `get_refunds()`. De vil fortsat blive skrevet ordret i ordrenoten.
Opgørelsen af de lokale domænebeskeder er desuden ufuldstændig:
`ShopID mismatch for order …` er en femte lokal `RuntimeException`. Der skal derfor være
en udtrykkelig købmandsvendt fejlkategori eller en sikker oversættelsesgrænse omkring hele
primitiven; de to foreslåede wrappers etablerer ikke alene den lovede invariant.

### 26. Valutaen kontrolleres ikke før capture

`src/library/class-wc-scanpay-capture.php:68-74`, `:118` — **Udledt**

Capture-beløbet denomineres i ordrens *nuværende* valuta, men autorisationen det indløser er
denomineret i `scanpay_meta.currency` — en kolonne der findes (`install.php:50`) og udfyldes
af sync (`:221`), men hverken læses her eller står i `SELECT`-listen. Sync validerer at de to
stemmer (`class-wc-scanpay-sync.php:262-265`), men kun inde i
`empty( $wco->get_transaction_id( 'edit' ) )`-grenen, altså præcis én gang. Scanpay afviser
formentlig en capture hvis valuta ikke matcher, hvilket gør udfaldet til et rent on-hold frem
for en forkert capture — derfor lav og udledt.

**Rettelse.** Tilføj `currency` til `SELECT` og sammenlign før payloaden bygges.

**Alternativ — lad sync være stedet der fanger det.** Sync validerer allerede at de to stemmer
(`class-wc-scanpay-sync.php:262-265`), men kun inde i
`empty( $wco->get_transaction_id( 'edit' ) )`-grenen. Løftes testen ud af den gren, kontrolleres
valutaen ved hver opdatering af rækken frem for kun ved den første, og capture behøver ingen ny
kolonne. Grenen `return`er (ikke `throw`er), så en uenighed stopper ikke synkroniseringen.

**Anbefaling: rettelsen ovenfor.** Alternativet flytter kontrollen længere væk fra handlingen: den
ville sige at rækken og ordren var enige ved *sidste sync*, ikke at de er enige nu, og vinduet
mellem sidste sync og en capture er netop hvor en valutaændring på ordren rammer. `currency` er
desuden én kolonne mere i en forespørgsel der allerede kører — der er ingen omkostning at spare.
Sync-siden kan gøres oveni, men den erstatter ikke.

### 27. Nul-beløbs-fornyelsesgrenen afslutter en ordre der derefter fejler capture

`src/library/class-wcs-scanpay-charge.php:178-195` — **Udledt**

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
`wp-bulk-actions.php:18` og `wc-scanpay-ping.php:39` allerede gør, og ret kommentaren.

**Alternativ — lad capture selv vide at der intet er at capture.** Rettelsen ovenfor afkobler et
hook fra ét kaldsted; bliver der et tredje, skal mønsteret kopieres igen (bulk-filen har det
allerede, request-scopet og uden gen-tilføjelse). Årsagen ligger et andet sted: `capture()` slår
betalingsrækken op *før* den ser på hvad ordren skylder, så en nul-total-ordre fejler på den
manglende række i stedet for at svare "intet at gøre". Fire linjer over meta-opslaget
(`class-wc-scanpay-capture.php:68`) retter det for alle veje:

```php
// Nothing to settle on a zero-total order -- a 100% discount, a proration credit, a fully
// refunded order completed by hand -- and no payment row is expected either. Ahead of the
// lookup, or its absence reads as an unsynced payment and parks the order on-hold with a note
// claiming a payment failure.
if ( wc_scanpay_cmpmoney( (string) $wco->get_total( 'edit' ), '0' ) <= 0 ) {
	scanpay_log( 'debug', "Skipping capture: order #$oid has nothing to settle" );
	return;
}
```

**Anbefaling: alternativet, og ret kommentaren uanset.** Fundets del (a) — at WCS ikke
"auto-completer" nul-beløbs-fornyelser men aldrig fyrer hooket
(`class-wc-subscriptions-payment-gateways.php:114-120`) — er en kommentarrettelse der skal skrives i
begge tilfælde. For adfærden retter alternativet årsagen ét sted frem for at afkoble symptomet fra
hvert kaldsted, og det dækker den bredere klasse: enhver sti der markerer en nul-total Scanpay-ordre
completed rammer i dag samme fejl. Den nuværende `$to_capture <= 0`-gren gør allerede præcis den
vurdering — den ligger blot efter opslaget der kaster.

### 29. Et ping uden `Content-Length` afvises og kommer aldrig videre

`src/callback/wc-scanpay-ping.php:151-165` — **Verificeret** (eksekveret)

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

**Præmissen er nu eksekveret.** Afgjort mod PHP 8.3.29's indbyggede server med et rigtigt POST,
frem for ræsonneret: et request med `Transfer-Encoding: chunked` ankommer med `CONTENT_LENGTH`
**helt fraværende**, mens body'en læses uden problemer.

```
$ curl -X POST -H 'Transfer-Encoding: chunked' --data-binary 'abc' …/inp.php
fgc=3 sgc=3 cl=ABSENT
$ curl -X POST --data-binary 'abc' …/inp.php          # kontrolgruppe
fgc=3 sgc=3 cl=3
```

`$cl` bliver altså `(int) '' === 0`, `$cl <= 0` fyrer, og pinget får 400 før HMAC'en kontrolleres —
med en fuldt læsbar body. Fundet er dermed verificeret, ikke udledt.

**Rettelse.** Læs først og begræns læsningen
(`stream_get_contents( fopen( 'php://input', 'r' ), 513 )`), og behold `$cl`-lighedskontrollen
kun når headeren findes.

**Alternativ — `file_get_contents()`s egen `maxlen`.** Samme semantik uden et filhåndtag, og filen
bliver ved den funktion den bruger i dag. Begge former er målt ovenfor (`fgc` og `sgc`), og de er
ækvivalente — også ved overskridelse: en 600-byte body giver præcis 513 for begge.

```php
// Read first: Content-Length is a hop-by-hop artefact of how the request reached PHP, absent on
// a chunked or HTTP/2 hop, and rejecting on it answers 400 to a body that is right there. 513
// bounds the read at one byte over the cap, so an oversized body is detectable without holding
// it. Verified against it when the header is present, which is what keeps an inaccurate one from
// passing.
$body = file_get_contents( 'php://input', false, null, 0, 513 );
if ( false === $body ) {
	wc_scanpay_respond( 'body read failed', 400 );
}
if ( strlen( $body ) > 512 ) {
	wc_scanpay_respond( 'payload too large', 413 );
}
$cl = $_SERVER['CONTENT_LENGTH'] ?? '';
if ( '' !== $cl && strlen( $body ) !== (int) $cl ) {
	wc_scanpay_respond( 'content-length mismatch', 400 );
}
```

**Anbefaling: alternativet.** Rent formvalg — samme rettelse, samme værn, én linje mindre og ingen
`fopen()`-håndtag at ræsonnere om levetiden af. Begge bevarer 512-byte-loftet og
lighedskontrollen når headeren findes, hvilket er hvad fundets modargument beder om.

### 37. `CREATE TABLE` kaster på en tabt kapløbssituation, selv når postbetingelsen er opfyldt

`src/install.php:24-38`, `:41-62`, `:68-82` — **Verificeret**

Hver af de tre tabeller følger mønsteret `SHOW TABLES LIKE` → `CREATE TABLE` →
`if ( true !== $res ) throw`. To samtidige installationer kan begge se tabellen mangle; den
ene opretter den, den anden får MySQL 1050 ("table already exists"), `$res` bliver `false`, og
den kaster — selv om den ønskede sluttilstand nu er opfyldt.

Filen håndterer den *anden* kapløbssituation korrekt og siger det udtrykkeligt:

```php
// Re-read rather than test the INSERT's return: two racing installs lose the
// duplicate-key race harmlessly, and it is the row's presence that matters, not who
// wrote it.
```

Seq-rækken genlæses altså frem for at stole på returværdien, mens tabeloprettelsen lige
ovenfor ikke gør det tilsvarende. Det er en indre inkonsistens.

**Modargument.** Kastet fanges af begge indesluttede kaldere (loaderen og reset-endpointet), og
`install.php` er idempotent, så næste forsøg lykkes. Kun kortgatewayens ukontrollerede
`require` — **fund 9** — gør et tabt kapløb til en kritisk fejlside. Derfor lav i sig selv.
`upgrade.php:52` requirer filen ubetinget, men vinduet er alligevel smalt: loaderens transient
gør et samtidigt gennemløb usandsynligt — usandsynligt, ikke umuligt, hvilket loaderens
kommentar selv siger — og de to øvrige kaldere er købmandsudløste.

**Rettelse.** Genlæs tabellen efter en fejlet `CREATE TABLE`. Findes det forventede skema nu,
så accepter det; ellers kast den oprindelige databasefejl.

**Alternativ — `CREATE TABLE IF NOT EXISTS`.** Kapløbet findes kun fordi filen spørger og derefter
opretter. Beder sætningen selv om betingelsen, forsvinder både vinduet og forudlæsningen: MySQL
svarer med en *note* (1050) frem for en fejl når tabellen findes, så `$wpdb->query()` returnerer
`true`, og `true !== $res` bliver den ægte fejlkontrol den ser ud som.

```php
$res = $wpdb->query(
	"CREATE TABLE IF NOT EXISTS $seq_tbl ( … ) CHARSET = latin1;"
);
if ( true !== $res ) { … }
```

Det fjerner tre `SHOW TABLES LIKE`-forespørgsler fra hver idempotent kørsel — filen kører fra
loaderen på hvert opgraderingsgennemløb — og med dem `esc_like`-begrundelsen for de tre mønstre.

**Anbefaling: alternativet.** Det er den enklere retning på hver akse: færre linjer, færre
forespørgsler, ingen ny fejlhåndtering, og kapløbet er lukket af serveren frem for af en
genlæsning vi selv skal skrive rigtigt. Det ændrer ikke skemakontrollen i nogen retning — `IF NOT
EXISTS` accepterer en tabel med afvigende kolonner, præcis som `SHOW TABLES LIKE`-testen gør i dag,
og skemaforskelle er `v3-0-0.php`s ansvar. Det gør heller ikke fund 9 mindre påkrævet: en `CREATE`
kan stadig fejle af rigtige grunde, og kortgatewayens `require` er stadig ufanget.

### 38. Admin-hooks rammer ikke HPOS-skærmen for en rolle uden `edit_others_shop_orders`

`src/admin/orders.php:29`, `:61`, `:181`, `src/admin/subscriptions.php:91` — **Verificeret**

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

(Bulk-halvdelen af filen *kender* allerede rollen — kommentaren på `:46-54` navngiver netop
"a role with `edit_shop_orders` but not `edit_others_shop_orders`" — så det er kun skærm-id'et
der er overset, ikke rollen.)

**Alternativ — registrér begge HPOS-varianter.** Filen registrerer i forvejen hvert hook to gange,
HPOS og legacy, og filhovedet gør det til reglen: *"Every hook is registered for both the HPOS and
the legacy post-based order list."* En tredje linje per hook følger den frem for at bryde den:

```php
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 ); // HPOS
// Same screen for a role without edit_others_shop_orders: wc_get_page_screen_id() derives the
// prefix from can_view_woocommerce_menu_item() (wc-admin-functions.php:82), so the menu-less
// variant is where such a user's order screen lives.
add_action( 'add_meta_boxes_admin_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 ); // HPOS, no menu
add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 ); // Legacy
```

**Anbefaling: alternativet.** `wc_get_page_screen_id()` er den rigtige API, men den koster en
`function_exists()`-guard, en `@since`-opklaring i stubs, og den returnerer `'shop_order'` — ikke et
`add_meta_boxes_*`-suffiks — når HPOS er slået fra, så legacy-linjen skal alligevel skrives i
hånden. Dobbeltregistreringen er guard-fri, læses som resten af filen, og de fire ekstra linjer er
billigere end den ene guard plus den forgrening. Bulk-halvdelen behøver ikke røres: HPOS'
`get_bulk_actions()` svarer alligevel `[]` uden `edit_others_shop_orders`, som fundet siger.

### 39. Scanpay-id'er og synkroniseringscursoren er 32-bit kolonner, og et overløb ville klippe tavst

`src/install.php:27-29`, `:45-48`, `:71-72` — **Verificeret** (mekanisme) / **Udledt** (horisont)

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

Egenskabsdeklarationen og linjenummeret er bekræftet i
`.stubs/wordpress/wp-includes/class-wpdb.php:644-651`.
Samme præmis vender den anden vej i `v3-0-0.php`: dér er det en manglende værdi til en
`NOT NULL`-kolonne uden `DEFAULT`, som bliver advarsel 1364 frem for en fejl.

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
`install.php`, og lad `rev` og `nacts` blive. Migrationsstien er nu billigere end da fundet
blev skrevet: én ny fil under `src/upgrade/` plus én linje i listen på `upgrade.php:71-75`, og
`v3-0-0.php` er skabelonen — samme `SHOW COLUMNS`-introspektion, samme gruppering af klausuler
i én `ALTER` per tabel. `MODIFY` er idempotent, så reglen om at stemple versionen sidst
holder. Reset-endpointets `$wcsp_schema`-liste (`:157-161`) tjekker kun kolonnenavne, ikke
typer, så den behøver ikke røres. Intet i PHP skal ændres, og ingen floor flytter sig —
`BIGINT` er ældre end MySQL 5.5.5-floor'en, og `docs/requirements.md` fører allerede
`ALTER TABLE`-rækken.

**Hvad rettelsen koster, og fundet ikke siger.** En `MODIFY` af en kolonnetype er ikke i den
INSTANT-familie `v3-0-0.php`s hoved beskriver: den algoritme dækker at tilføje og droppe kolonner,
ikke at brede en `INT` til `BIGINT`, som InnoDB udfører som en tabelkopi. På `scanpay_seq` er det
gratis — én række per butik — men `scanpay_meta` har en række per ordre pluginnet nogensinde har
synkroniseret. På en stor butik er det en kopi der kan overskride `upgrade.php`s 60-sekunders
budget. En migration der bliver ved at time out er præcis den tilstand `is_available()` nu
gater på, så butikken mister Scanpay-checkout indtil kopien lykkes: udfaldet er sikkert, ikke
billigt. Rettelsen for et overløb der kræver 2³² transaktioner kan altså koste checkout imens.

**Alternativ — udvid efter konsekvens, og få faktummet først.** De syv kolonner er ikke lige meget
værd, og fundet ved det selv: `seq` kræver ~4,3 milliarder ændringer på én butik, mens `id` og
`subid` kun er plausible *hvis* Scanpays id-rum er globalt frem for per butik — hvilket "ikke kan
afgøres ud fra dette repo". Det ene faktum kan man bede Scanpay om, og det afgør hele fundet. Indtil
da:

- **`scanpay_seq` nu** (`shopid`, `seq`, `ping`). Én række per butik, så kopien er gratis, og
  tabellen er den `install.php` alligevel seeder.
- **`scanpay_meta.id`, `.subid` og `scanpay_subs.subid` når svaret er "globalt".** Det er de tre
  hvor et overløb korrumperer frem for at spilde arbejde: ejerskabsgaten fryser rækken, og
  `scanpay_subs.subid` er PRIMARY KEY, hvor to id'er smelter sammen til én række.
- **`scanpay_meta.shopid` aldrig.** Den er butikkens eget id fra API-nøglen, ikke et Scanpay-serienummer.

**Anbefaling: alternativet.** Ikke fordi fundet er forkert — mekanismen er verificeret og
konsekvenserne er reelle — men fordi rækkefølgen er forkert. En tabelkopi på hver eksisterende butiks
største tabel er en større og mere sandsynlig risiko end et overløb ingen har vist er nåeligt, og de
to er ikke uafhængige: den ene kan udløse den anden. `scanpay_seq`-halvdelen er gratis og kan skrives
nu; resten venter på ét svar. Skrives den store `ALTER` alligevel, bør den have én `ALTER` og én
logline per tabel som `v3-0-0.php` gør, så et retry efter et timeout fortsætter ved næste tabel
frem for at starte forfra.

**Reviewnote.** Anbefalingen behandler samme værdi inkonsistent:
`scanpay_seq.shopid` foreslås udvidet nu, mens `scanpay_meta.shopid` aldrig skal udvides,
fordi den er butikkens id fra API-nøglen. Begge kolonner gemmer netop det id; hvis den
semantiske begrundelse udelukker den ene, udelukker den også den anden. En eventuel
forsigtighedsudvidelse alene fordi `scanpay_seq` er billig bør kaldes det, ikke begrundes
som et andet id-rum. Det bærende Go-citat kan heller ikke reproduceres:
`/code/snare/go-scanpay/api_types.go` ligger uden for repoet og findes ikke i det aktuelle
workspace, så afsnittet kan ikke samtidig kalde det belæg »her i træet«.

---

## Hazard uden foreslået rettelse

### 43. `scanpay_meta`-rækken skrives før ordren er bekræftet som vores

`src/library/class-wc-scanpay-sync.php:228-239` — **Uverificeret, spekulativt**

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

**Supplement — tilstanden er allerede detekteret, den er bare tavs på ordren.** Fundet skriver at
udfaldet er "kunden har betalt, ordren står pending, ingen note", og halvdelen af det kan lukkes
uden at røre rækkefølgen. Butik A's ægte transaktion for dens egen #500 rammer `upsert_meta()`s
ejerskabsgate, og den **logger** allerede:

```php
// class-wc-scanpay-sync.php:161-165
if ( null !== $owner && (int) $owner !== $trnid ) {
	scanpay_log( 'error', "$label: order #$oid already paid by transaction #" . (int) $owner . '; ignoring' );
	return false;
}
```

Signalet findes altså; det står blot i `wc-scanpay`-loggen, hvor ingen kigger uden en grund. En
ordrenote i samme gren ville sætte det på den ordre kunden betalte for, hvor købmanden ser den ved
den første kundehenvendelse. Det er fem linjer, ændrer ingen semantik — `return false` er stadig
svaret — og gør en delt-nøgle-fejlkonfiguration diagnosticerbar frem for tavs. Noten skal være
admin-only (`add_order_note( …, 0 )`) og bære en købmandsvendt tekst, ikke transaktions-id'et fra
den anden butik.

**Anbefaling: skriv noten, og lad rækkefølgen stå.** Fundet har ret i at den rigtige rettelse er
dokumentation — "én API-nøgle per butik" — og at alt andet er en designændring. Men et hazard uden
rettelse er stadig værd at gøre synligt, og gaten der opdager kollisionen er allerede på plads. Det
er den billigste halvdel af et fund der ellers ikke har nogen.

**Reviewnote.** En note »i samme gren« gentager ejerskabsproblemet: `upsert_meta()` har
kun det numeriske ordre-id, og `wc_get_order()` samt Scanpay-ejerskabstesten ligger efter
en vellykket upsert. Uden en særskilt `wc_scanpay_is_scanpay_order()`-gate kan noten derfor
lande på en lokal ordre fra en anden gateway, som blot deler nummer med den fremmede
transaktion. `add_order_note()` skal samtidig ligge i sin egen `try/catch`; ellers gør en
fejlet diagnostik den permanente kollision til et kast, som kan forhindre cursoren i at
rykke. Med de to værn kan supplementet være semantikneutralt; uden dem er det ikke de
lovede fem linjer.

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
boundary"-regel. Retningen er hele pointen: `wc_format_decimal( $n, $dp )` kører selv
`number_format( floatval( $n ), … )`, så samme kald *ud* af strengdomænet ville bryde reglen —
hvilket er hvorfor **fund 18**s anbefaling er at rette noten frem for beløbet.

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

**Reviewnote.** Sidste sætning er for absolut. Nøglen indeholder både subscriberens
revision og hele dage siden ordrens oprettelse, så samme fornyelsesordre får bevidst en ny
nøgle efter et døgn eller en kortopdatering. Den er stabil inden for samme
`(order, rev, day)`-bucket, ikke på tværs af alle retries af samme charge.

**HMAC-verifikationen** beregnes over de rå `php://input`-bytes, med `hash_equals()` i korrekt
argumentrækkefølge, og *før* `json_decode()`, før al DB-adgang og før låsen røres.

**Reviewnote.** »Før al DB-adgang« holder ikke: ping-filen kalder
`get_option( WC_SCANPAY_URI_SETTINGS )` før HMAC-kontrollen, og det kan være et
databaseopslag når optionen ikke allerede ligger i object cache. Det nødvendige og korrekte
udsagn er smallere: HMAC'en ligger før cursor-/synkroniseringstabellerne, før
`json_decode()` og før låsen.

**Ping-versionsgatens placering** (`wc-scanpay-ping.php:211-228`). Efterprøvet mod begge
fejlmåder den kunne have haft, og den har ingen af dem. Den sidder efter HMAC-kontrollen, så en
uautentificeret kalder kan ikke bruge 503'eren til at aflæse butikkens opgraderingstilstand; og
den sidder under heartbeat-grenen, så en butik uden noget at synkronisere stadig får sin `mtime`
skrevet og indstillingsskærmens "sidst synkroniseret" bliver ikke stående gammel mens
migrationen hænger. Den skriver intet på vej ud, hvilket er rigtigt: `scanpay_seq` er selv en
tabel en migration kan være ved at ændre. `upgrade.php` kører i ping-requestet, så 503'eren
betyder at migrationen fejlede frem for at den mangler et request. At den logger en `warning`
per ping — hvert femte minut, ved siden af loaderens egen
fejllinje — er diagnostik og ikke støj, så længe tilstanden er kortvarig.

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

**DDL-returværdier.** `install.php` tester `true !== $res` på `CREATE TABLE`, `v3-0-0.php:133`
tester `false === $res` på `ALTER`, og reset-endpointet `false ===` på `DROP`. Alle tre er
korrekte: `wpdb::query()` returnerer `$this->result` for `create|alter|truncate|drop`
(`class-wpdb.php:2308-2309`), altså `true` ved succes. Ingen af tabellerne bygges med
`dbDelta`, så dens whitespace-følsomhed er ikke i spil. Efterprøvet igen efter opdelingen:
`v3-0-0.php` læser desuden `$wpdb->last_error` efter hver `SHOW`, hvilket er den rigtige
kontrol for et `get_col()`/`get_results()`-kald, der svarer `[]` for både en tom og en fejlet
forespørgsel.

**Dispatch-gatenes typesikkerhed.** Admin-AJAX-gatens `match ( $_GET['x'] )` bruger streng
sammenligning, så et array falder til `default => null`. Thank-you-gatens `in_array( …, true )`
gør det samme. Ping-gaten har ingen egen URI-test — den registrerer bare hooket og lader
WooCommerce dispatche det — så der er intet tredje input at typesikre.

**`WC_SCANPAY_URL`.** Alle forbrugere ligger i gateway-konstruktører, Blocks-support og
admin-enqueue — ingen af dem nås på de tre tidlige returstier.

**`supports[]` matcher implementeringen.** Kort og Apple Pay erklærer abonnementsfeatures
kun med det fulde WCS-plugin; MobilePay bliver på basens `[ 'products' ]`. Sync
konsoliderer Apple Pay-abonnementet til kortgatewayen, som alene ejer de senere fornyelser.
`'refunds'` optræder i ingen af dem, hvilket stemmer med `can_refund_order(): false`.

**Blocks-payloaden lækker intet.** `get_payment_method_data()` kopierer gatewayinstansernes
normaliserede `title`, `description` og `supports`, kortgatewayens `card_icons` samt
vilkårsstrengene. Hverken `apikey` eller `secret` læses, og intet settings-array spredes.

**Implementeringen af den write-once API-nøgle.** `validate_apikey_field()` dispatches før
`validate_{$type}_field`, tomt POST returnerer den lagrede værdi frem for at slette,
`is_apikey()` afviser en flerkolon- eller ikke-numerisk nøgle korrekt, og den lagrede nøgle
udsendes aldrig — den maskerede gren renderer slet intet `<input>`. Eneste omgåelse er
REST-stien i fund 7, og den er i dag lukket ved et tilfælde.

**Den klassiske vilkårscheckbox.** Ét prædikat driver både renderer og validator, ingen af dem er
scopet til en gateway, og `empty( $_POST['wcssp-terms'] )` er sikker mod `'0'` fordi en
WooCommerce-checkbox poster `value="1"`.
