# PHP-review af `src/` — sammenfatning

Kort resumé. Det fulde review med mekanisme, fejlscenarie, efterprøvning og
rettelsesforslag for hvert fund står i [`RESULTS.md`](RESULTS.md).

**Omfang:** alle 35 PHP-filer i `src/` (5.953 linjer) ved commit `095fa64` plus det
uncommitterede arbejdstræ. 15 fund og 13 mindre punkter. Ingen kode ændret.

---

## Metode

Seks parallelle reviewere med hver sit felt og fuld dækning uden overlap: ping/sync,
gateways/checkout, admin, library, bootstrap/livscyklus — og én på en anden akse:
invarianter der spænder over filgrænser, hvor fil-for-fil-reviews typisk fejler.

Hvert fund er derefter efterprøvet mod upstream-kilden i `.stubs/`, ikke overtaget på
tro. Fire fund overlevede ikke den kontrol i den rapporterede form og er enten nedgraderet
til mindre punkter eller omformuleret.

---

## De alvorligste fund

**1. `upgrade.php:158` kiler hele opgraderingen fast permanent.**
`wcs_get_subscription()` kaldes på `plugins_loaded`, men `WC()->order_factory` tildeles
først på `init`:0 — fatal `Error`. Loaderen fanger den, transienten bevares bevidst, og
versionen stemples sidst, så porten åbner igen hvert 5. minut i det uendelige.
Migrationerne `< 2.5.0` og `< 3.0.0` nås aldrig, hvilket slår autocapture tavst fra og
under strict SQL mode kan standse al betalingsregistrering.

Fundet står i skarp komplementaritet til den kendte, stadig åbne finding om samme gren:
uden WCS springes migrationen permanent over, med WCS og data fataler den evigt. Grenen
fuldfører kun korrekt, når der intet er at migrere.

**2. Thankyou-gaten er en uautentificeret "sluk pluginnet"-kontakt.**
`woocommerce-scanpay.php:109` dispatcher på parametre alene, aldrig på requestens art. Et
Store API-checkout POSTet til `?scanpay_thankyou=0&scanpay_type=wc&key=` slår resten af
bootstrappet fra — og dermed Blocks-valideringen af abonnementsvilkår, som docblocken
netop kalder streng. Ping-gaten har samme form.

**3. MobilePay og Apple Pay kan oprette abonnements-subscribere.**
WCS' gateway-filter springer eksplicit order-pay-endpointet over, og
`wc_scanpay_process_payment()` kender ikke sin afsender — abonnementsstatus afgøres
udelukkende af ordrens meta. Kun kort-gatewayen erklærer `subscriptions`.

**4. REST- og CLI-stien omgår al nøglevalidering.** `init_form_fields()` er tom, men
WooCommerces REST-controllere læser `$gateway->form_fields` direkte. Posted settings
kasseres tavst, og `enabled` skrives med `update_option()` uden om `process_admin_options()`
— altså uden `validate_apikey_field()`, nøglevalidering og force-disable.

---

## To ting værd at fremhæve

**Kommentarer der lover noget koden ikke leverer.** I dette projekt er kommentarerne
test-suiten, så en drevet kommentar er en brudt test. To af dem blev fundet uafhængigt af
to reviewere ad forskellige veje:

- Prioritet 5 på `woocommerce_order_status_completed` forhindrer ikke, at kunden mailes
  "gennemført" og får downloads når capture fejler — `do_action` gennemløber hele listen,
  og ingen af WooCommerces lyttere genlæser status.
- "Det næste ping afstemmer ordren automatisk" holder ikke for nogen ordre, hvis betaling
  er synkroniseret: sync gater på tom `transaction_id`, og den er sat længe før.

**Gårsdagens rettelse er ufuldstændig.** De to fund der rammer den uncommitterede
`save_or_report()`-helper: `set_status(…, true)` fyrer `woocommerce_order_edit_status`
synkront *uden for* try-blokken, og `WC_Abstract_Order::save()` sluger `Exception` selv —
så returværdien er `true` også når skrivningen fejlede, og `continue`-vagten dækker kun
`Error`-delmængden.

---

## Validering

- `php -l` på alle 35 filer — rene (PHP 8.3.29).
- `pnpm phpcs` — ren.
- **`math.php` verificeret ved kørsel:** 267.101 par-checks mod bcmath, nul afvigelser,
  plus 300.000 iterationer med al PHP-diagnostik forfremmet til exception — nul notices.
- **Capture-aritmetikken:** 200.000 replays mod bcmath, nul afvigelser.
- **Curl-klienten drevet mod en rigtig HTTP-server:** TLS fail-closed, redirects følges
  ikke, API-nøglen kan ikke lække eller injicere, header-injektion i `X-Cardholder-IP`
  afvist.
- Konventionerne holder undtagelsesfrit: alle 35 filer har `declare(strict_types=1)` og
  `ABSPATH`-guard, og alle 83 oversatte strenge bruger ét tekstdomæne.

**Intet er afprøvet på en kørende shop.** Alle fund hviler på kildelæsning af `src/` og af
upstream i `.stubs/` (WC 11.1.0-dev, WCS 8.7.1, WP 7.1-beta3), mens pluginnet understøtter
WC 3.6 / WP 6.3.
