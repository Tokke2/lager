# Arkitektur- och datamodell – Tadega Lager

> Historiskt planeringsunderlag: autentiseringsavsnitten är inaktuella. Aktuellt: [inloggning med PIN](dokument/09_inloggning-och-losenord.md).

**Status 2026-11-12: byggt och verifierat** (25/25 integrationstester). Detta dokument beskriver
den arkitektur som faktiskt körs, inte ett förslag. Avsnitt markerade *planerat* är ännu inte byggda.

---

## 1. Principer

1. **Egen app, egen webbrot.** Ingen del av portalens kod eller data används vid körning, bara som referens vid migrering.
2. **Ingen databas.** JSON i privat lagring, med lås, journal och atomiska filbyten (avsnitt 7). Ersätts enkelt av SQL om volymen kräver det – domänlogiken är separerad från lagringen.
3. **Allt eller inget.** Varje muterande operation validerar *allt* före första skrivning och kan rullas tillbaka via write-ahead-journal.
4. **En sanning per saldo.** `quantity`-artiklar har lagrat saldo; `qr_units`-artiklar *härleder* saldo ur enheternas status. Aldrig båda.
5. **Historiken rörs inte.** Ledger är append-only med hash-kedja; inget API ändrar eller raderar poster.
6. **Serversidans behörighet är sanningen.** Gränssnittet får dölja knappar, men varje endpoint kontrollerar rollen själv.

---

## 2. Katalogstruktur

```
Tadega-Lager/
├── public/                      # ENDA katalogen som webbhotellet exponerar
│   ├── index.php                # front controller med ruttabell
│   ├── .htaccess                # pretty URLs, nekar allt utom index.php & assets
│   └── assets/
│       ├── app.css              # all stil, temavariabler från varumärket
│       ├── app.js               # delegerade händelser (CSP: inga inline-handlers)
│       ├── qrcode.js            # lokal QR-generator, ingen CDN
│       ├── qr-render.js         # renderar .qr[data-kod]/[data-url] + auto-utskrift
│       ├── jsqr.js              # avkodning i webbläsaren (kamera)
│       └── ikoner/              # 192/256/512 px appikoner
├── sw.js                        # service worker (cache: tdl-v1, endast skal)

├── app/
│   ├── bootstrap.php            # TDL_*-sökvägar, autoload TDL\, felhantering
│   ├── functions.php            # e(), get(), post(), omdirigera(), flash(), sv_tid()
│   ├── Support/
│   │   ├── Json.php             # atomiskt läs/skriv, revision
│   │   ├── Las.php              # fillås + write-ahead-journal
│   │   ├── Historik.php         # append-only ledger med SHA-256-kedja
│   │   ├── Sakerhet.php         # CSRF, Origin-kontroll, normalisering
│   │   ├── Koder.php            # QR-koder, Crockford Base32 + kontrolltecken
│   │   ├── Konto.php  Auth.php  # konton, PIN, sessioner
│   │   ├── Varumarke.php        # vitmärkning: namn, färger, logotyp
│   │   ├── Bilder.php           # uppladdning, validering, nedskalning
│   │   └── SvgSanerare.php      # vitlistar SVG-element/attribut
│   ├── Domain/                  # Lager, Rorelser, Inventering, Kontohantering
│   ├── Http/
│   │   ├── Vyer.php             # renderingshjälp
│   │   ├── Sidor.php            # sidor + manifest, sw.js, offline
│   │   ├── Installation.php     # första installationen (guide)
│   │   ├── ArtikelQr.php        # signerade artikel-QR + bild-URL:er
│   │   └── Admin.php            # varumärke, system, adress, QR-nyckel
│   └── views/                   # PHP-mallar, alltid med e()
├── storage/                     # UTANFÖR webbroten
│   ├── data/                    # JSON-filer (artiklar, platser, enheter, …)
│   ├── konfig/                  # varumarke.json, artikelqr.json, adress.json
│   ├── bilder/<ARTIKEL-ID>/     # uppladdade artikelbilder
│   ├── las/  tmp/  journal/     # lås, temporärer, write-ahead
│   ├── logg/                    # applikationslogg
│   └── backup/                  # zip + manifest med SHA-256
├── bin/                         # CLI, aldrig nåbar via HTTP
│   ├── installera.php  demodata.php  backup.php
│   ├── aterlas.php  aterhamta.php  verifiera-historik.php
├── tests/integrationstest.php   # 25 prov (GT, N, QR)
├── desktop/                     # Electron-app (Windows/macOS/Linux)
│   ├── main.js  server.js  preload.js
│   └── release/                 # byggda paket
├── brand/                       # logotyp SVG/PNG, skapa-varumarke.py
├── verktyg/                     # miljökontroll
└── dokument/                    # 05 lansering, 06 drift
```

**Skyddslager om webbhotellet inte tillåter egen docroot:**

1. Primärt: docroot = `public/`.
2. Sekundärt: `.htaccess` (Apache 2.4) som nekar `storage|app|bin|tests|dokument|verktyg` samt filer med ändelsen `.json`.
3. Tertiärt: webbhotellets panel/proxy-regel om sådan finns.
4. Sist: filnamn utan ledtrådar och 0600-rättigheter – men det är *inte* ett skydd, bara minskad exponering.

Skyddet verifieras med test SÄK-01–SÄK-06 i `04_testplan.md` innan staging godkänns.

---

## 3. Datamodell (JSON-filer)

Alla filer har `schema_version`, `meta.revision` (räknas upp vid varje skrivning) och `meta.updated_at`. Poster har `id`, `created_at`, `updated_at`.

### 3.1 `artiklar.json` → Article

| Fält | Typ | Regel |
|---|---|---|
| `id` | `ART-…` | internt, oföränderligt |
| `artikelnummer` | sträng | unikt, skiftlägesokänsligt |
| `namn` | sträng | 1–120 tecken |
| `enhet` | sträng | `st`, `m`, `kg`, `liter` … valfri lista + fritext |
| `prisreferens` | tal/null | SEK, ≥ 0, bara referens |
| `miniminivå` | tal | ≥ 0, jämförs mot **plats-saldot**, inte totalen |
| `tracking_mode` | enum | `quantity` \| `qr_units` – låst när historik/enheter finns |
| `aktiv` | bool | i stället för radering när historik finns |
| `saldo` | obj | `{ "<plats-id>": antal }` – **endast** `quantity` |
| `etikett_mall` | sträng | utskriftsstorlek för artikelns etiketter, t.ex. `62x29` eller eget `62x55` – se `app/Support/Etikettmallar.php` |
| `bilder` | lista | `[{ fil, text, skapad }]` – max 12, filnamn slumpat, se 3.7 |
| `foreign_keys` | – | `qr_units`-artiklars saldo **beräknas**, lagras aldrig |

Regler:

- `tracking_mode` får bytas från `quantity` → `qr_units` bara om saldot är 0 på alla platser; aldrig tillbaka om enheter finns.
- Radering: tillåts bara om artikeln saknar saldo, saknar enheter och saknar ledger-poster. Annars `aktiv: false` med ledger-post `artikel_inaktiverad`.
- Minsta tillåtna saldo är 0 – negativt saldo nekas i alla operationer.
- `etikett_mall` är en **utskriftsinställning**, inte lagerdata: den påverkar aldrig saldo eller historik. Kända rullar valideras mot `Etikettmallar::MALLAR`, egna mått mot de vanligaste skrivarnas gränser (20–112 mm brett, 20–1000 mm högt); allt annat faller tillbaka på `62x29`.

### 3.1b Skrivarprofil (inte en datatyp, utan en inställning)

Utskriften går via webbläsarens utskriftsdialog. Vilken skrivare som är vald
avgör hur brett det går att skriva:

| Fält | Typ | Regel |
|---|---|---|
| `bredd` | tal | skrivhuvudets tryckbredd i mm – etiketten klipps aldrig bredare |
| `max_etikettbredd` | tal | bredaste etikett som rullen/skrivaren tar |
| `max_hojd` | tal | längsta etikett; längre går inte att skriva ut |

Profilerna bor i `app/Support/Skrivare.php` (Brother QL-500/550 60,9 mm, QL-800
58 mm, Dymo 56 mm, Zebra 2 tum 56 mm, Zebra 4 tum 104 mm, A4). Valet sparas i
`installningar.json` under `skrivare` och **ärver QL-500** om värdet är okänt –
utskriften kan aldrig slås ut av en felaktig inställning.

Tryckbredden räknas alltid som `min(etiketten, skrivarens bredd)`, så att en
29 mm-rulle förblir 29 mm bred och en 62 mm-etikett skrivs 60,9 mm på en QL-500.

### 3.2 `platser.json` → Location

| Fält | Typ | Regel |
|---|---|---|
| `id` | `LOC-…` | beständigt |
| `namn` | sträng | unikt |
| `kod` | sträng | kort, t.ex. `ORE`, `LAX` |
| `qr` | sträng | arbetsplats-QR, se format i avsnitt 6 |
| `systempost` | bool | sant endast för `SYSTEM_LEGACY` (äldre ofördelat saldo) |
| `aktiv` | bool | stängning = `aktiv: false` |

Stängning nekas om platsen har saldo > 0, enheter med status `active`/`pending` eller en öppen inventeringssession.

### 3.3 `enheter.json` → Unit (QR per fysisk enhet)

| Fält | Typ | Regel |
|---|---|---|
| `id` | sträng | **oföränderlig**, följer enheten vid flytt |
| `artikel_id` | `ART-…` | måste finnas och ha `tracking_mode: qr_units` |
| `status` | enum | `pending` \| `active` \| `retired` \| `missing` |
| `plats_id` | `LOC-…`/null | null endast för `pending` |
| `etikettetikett` | – | skrivs ut från `id`; ingen separat sanning |
| `senast_skannad` | tid | uppdateras vid lyckad skanning |

- Enhetens `plats_id` flyttas **inte** av arbetsplats-QR – bara av uttrycklig registrering/överföring.
- `retired` kan inte återaktiveras; ny etikett krävs.
- Tappad enhet markeras `missing` och kräver avstämning för att stängas.

### 3.4 `historik.json` → ledger (append-only)

En post per händelse, i kronologisk ordning:

```
seq, id, tid, ansvarig_anvandare, typ, artikel_id, artikelnummer,
artikelnamn (snapshot), plats_id, platsnamn (snapshot),
fran_plats_id, till_plats_id, antal, enhets_id[], session_id,
request_id, detaljer{}, prev_hash, hash
```

`hash = sha256(prev_hash | kanonisk_json(post utan hash))`. Första posten har `prev_hash = "GENESIS"`.

Tillåtna `typ` (exakt de som koden skriver): `inleverans`, `uttag`, `overforing`,
`inventering_start`, `inventering_avslut`, `avstamning_uttag`, `etikett_skapad`,
`enhet_aktiverad`, `enhet_utgang`, `artikel_skapad`, `artikel_andrad`, `artikel_inaktiverad`,
`plats_skapad`, `plats_andrad`, `plats_stangd`, `konto_skapad`, `konto_andrad`, samt
bildhändelserna från `ArtikelQr`. Åtgärder som avbryts loggas som `annullerad`.

Ingen endpoint uppdaterar eller raderar ledgerrader. `bin/verifiera-historik.php` kontrollerar hela kedjan.

### 3.5 `sessioner.json` → InventorySession

**En session = ett arbetsställe, flera artiklar** (ändrat 2026-11-12; tidigare en artikel per session).

| Fält | Typ | Regel |
|---|---|---|
| `id` | `S…` | internt |
| `plats_id` | `P…` | arbetsstället som inventeras – bara en öppen session per plats |
| `status` | enum | `öppen` \| `avslutad` \| `annullerad` |
| `startad`, `startad_av_namn` | tid/sträng | vem som startade |
| `avslutad`, `avslutad_av_namn` | tid/sträng | sätts vid avslut |
| `avstamning` | obj | `{ typ: uttag\|ingen\|annullerad, justerade, artiklar, motivering }` |
| `artiklar` | obj | **nycklat på artikel-id**, se nedan |

Varje rad i `artiklar`:

| Fält | Typ | Regel |
|---|---|---|
| `typ` | enum | `qr` \| `antal` – styrs av artikelns `sparningssatt` |
| `forvantat` | tal | **fryst** vid start (saldo eller antal aktiva enheter) |
| `forvantade_enheter` | lista | endast `qr`: enhetskoderna som förväntades |
| `raknade_enheter` | lista | endast `qr`: skannade enhetskoder |
| `okanda_enheter` | lista | skannade men ej förväntade (räknas aldrig in) |
| `raknat` | tal | endast `antal`: räknat antal |
| `raknat_uppdaterad` | tid | sätts första gången raden räknas – avgör om raden är "räknad" |

**Bakåtkompatibilitet:** sessioner med `artikel_id` på toppnivå (gamla formatet) läses
fortfarande via `Inventering::arGammal()`, `artikelIds()` och `rad()`. De kan räknas klart
och avslutas men inte utökas med fler artiklar.

**Varför ändringen:** en hylla med 40 artiklar krävde tidigare 40 sessioner, och en glömd
öppen session blockerade hela arbetsstället. Nu räknas hela arbetsstället i en session.

### 3.6 `konton.json` → User

`id`, `anvandarnamn`, `namn`, `roll` (`admin`/`personal`), `losenord_hash` (passord_hash, PASSWORD_DEFAULT), `maste_byta_losenord`, `aktiv`, `session_epoch`, `created_at`, `senast_inloggad`.

- Inloggning: session bär `konto_id` + `session_epoch`. Vid byte av lösenord/roll/avstängning höjs `session_epoch` → alla äldre sessioner nekas direkt.
- Låsning efter upprepade misslyckade försök per användarnamn + IP (enkel räknare i `logg/`).

---

### 3.7 `storage/bilder/<ARTIKEL-ID>/` → artikelbilder

Bilderna ligger som vanliga filer utanför webbroten. JSON-filen innehåller bara metadata.

| Fält | Typ | Regel |
|---|---|---|
| `fil` | sträng | `<16 hex>.jpg` – slumpat, aldrig användarens filnamn |
| `text` | sträng | valfri bildtext, 0–160 tecken |
| `skapad` | ISO-tid | när bilden laddades upp |

Regler (`Support\Bilder`):

- Max **12** bilder per artikel, max **8 MB** per uppladdning.
- Filtypen avgörs med `getimagesize()`, aldrig av filändelsen. Endast JPG/PNG/WebP.
- Bilden skalas ned till **1600 px** på längsta sidan och sparas som JPEG (kvalitet 82).
- Filnamnet genereras med `random_bytes` – ingen användardata hamnar i sökvägen.
- Borttagning tar bort både metadataposten och filen, i den ordningen, inuti samma lås.

**Åtkomst:** bilderna serveras aldrig statiskt. Rutten `GET /bilder/{artikel-id}/{fil}`
kräver antingen inloggad session **eller** en giltig signatur (`?n=&s=`, se 6.1).
Signerade URL:er skapas med `ArtikelQr::bildUrl()`.

### 3.8 Konfigurationsfiler i `storage/konfig/`

| Fil | Innehåll | Skapas av |
|---|---|---|
| `varumarke.json` | kundens namn, färger, tema, ordval, logotyp | `/admin/varumarke` (annars `Varumarke::STANDARD`) |
| `artikelqr.json` | hemlig HMAC-nyckel för artikel-QR + bild-URL:er | automatiskt vid första QR-utskriften |
| `adress.json` | bas-URL som trycks in i QR-koderna | `/admin/system/adress` |

Alla tre ingår i backupen med prefixet `konfig:` i manifestet.

## 4. Route-karta

### 4.1 Publika rutter (ingen inloggning)

| Route | Metod | Vad den gör |
|---|---|---|
| `/artikel/{id}?n=&s=` | GET | Artikelns publika sida: bilder, saldo, +/− av antal. Kräver giltig signatur – annars "Länken fungerar inte" |
| `/artikel/{id}/antal` | POST | Justera antal (inleverans/uttag) från den publika sidan. Kräver signatur **och** inloggning |
| `/artikel/{id}/bild` | POST | Ladda upp bild från mobilen. Kräver signatur och inloggning |
| `/bilder/{id}/{fil}?n=&s=` | GET | Signerad bild-URL, annars 404 |
| `/site.webmanifest` | GET | PWA-manifest (fungerar även före installation) |
| `/sw.js`, `/offline` | GET | Service worker och offlinesida (även före installation) |
| `/installera`, `/login`, `/logout` | GET/POST | Första installation och inloggning |

### 4.2 Inloggade rutter

| Route | Metod | Roll | Ledger |
|---|---|---|---|
| `/` (översikt) | GET | alla | – |
| `/artiklar`, `/artiklar/{id}` | GET | alla | – |
| `/artiklar/ny`, `/artiklar/{id}/redigera` | GET/POST | admin | artikel_skapad/andrad |
| `/artiklar/{id}/inaktivera` | POST | admin | artikel_inaktiverad |
| `/artiklar/{id}/etiketter` | GET | alla | – (utskriftsark) |
| `/artiklar/{id}/bilder` | POST | alla | bild_uppladdad |
| `/artiklar/{id}/bilder/ta-bort` | POST | alla | bild_borttagen |
| `/inleverans`, `/uttag`, `/overforing` | GET/POST | alla | inleverans/uttag/overforing |
| `/skanna` | GET | alla | – |
| `/etiketter`, `/etiketter/utskrift`, `/platsetikett` | GET/POST | alla | etikett_skapad |
| `/inventering` | GET | alla | – |
| `/inventering/start` | POST | alla | inventering_start |
| `/inventering/{id}` | GET | alla | – |
| `/inventering/{id}/skanna` | POST | alla | – (endast session) |
| `/inventering/{id}/antal` | POST | alla | – (endast session) |
| `/inventering/{id}/lagg-till` | POST | alla | – |
| `/inventering/{id}/ta-bort` | POST | alla | – |
| `/inventering/{id}/avstamning` | POST | **admin** | avstamning_uttag (per artikel) |
| `/inventering/{id}/avsluta` | POST | alla | inventering_avslut |
| `/inventering/{id}/annullera` | POST | **admin** | inventering_avslut |
| `/historik` | GET | alla | – |
| `/platser`, `/platser/ny`, `/platser/{id}/redigera`, `/platser/{id}/stang` | GET/POST | admin (skriv) | plats_* |
| `/konton`, `/konton/{id}/{roll,pin,stang,aktivera}`, `/mitt-konto` | GET/POST | admin (eget konto: alla) | konto_* |
| `/admin/varumarke`, `/admin/varumarke/logotyp`, `/admin/varumarke/aterstall` | GET/POST | admin | – |
| `/admin/system` | GET | admin | – |
| `/admin/system/backup` | POST | admin | – |
| `/admin/system/adress` | POST | admin | – |
| `/admin/system/qr-nyckel` | POST | admin | – |
| `/varumarke/logotyp.svg`, `/varumarke/ikon.svg` | GET | – | – |

Alla POST: CSRF-token, `Origin`/`Referer`-kontroll, serversidevalidering och rollkontroll
**före** åtgärden. Den publika sidan kräver dessutom giltig signatur i både `$_GET` och `$_POST`.

---

### 4.3 Inventeringsflödet (ombyggt 2026-11-12)

```
Arbetsställe  →  Starta  →  [alla artiklar med saldo tas med automatiskt]
                                    ↓
                     Räkna: +/− i listan (antalsartiklar)
                            skanna enhetskod (QR-artiklar)
                                    ↓
                     Avvikelser visas per artikel
                                    ↓
              Avstämning (admin)  eller  Avsluta utan avstämning
```

| Steg | Detalj |
|---|---|
| **Start** | Alla aktiva artiklar med saldo > 0 på platsen, plus QR-artiklar med aktiva enheter där. En artikel per plats kan läggas till manuellt efter start. |
| **Räkna antal** | Räknaren utgår från **förväntat** antal – man justerar nedåt/uppåt med +/−. Autosparas per rad utan sidladdning (`data-autoskicka` + `fetch`). |
| **Räkna QR** | Ett fält tar emot både enhetskoder och artikel-id:n. Enheten identifierar sin artikel automatiskt, så man behöver inte välja artikel först. |
| **Dubblett** | Samma enhetskod två gånger räknas bara en gång. |
| **Framsteg** | "X av Y artiklar räknade" med stapel, uppdateras live i webbläsaren. |
| **Avstämning** | Kräver admin och att man skriver `stam av`. En historikpost **per artikel** med avvikelse. Antalsartiklar får saldot satt till räknat; saknade QR-enheter markeras `saknad`. |
| **Avsluta utan avstämning** | Differensen sparas som dokumenterad avvikelse, inget saldo ändras. Kräver motivering. |

**Vad som INTE ändras automatiskt:** okända enheter läggs aldrig till, och en differens
rättas aldrig tyst. Det var ett uttryckligt krav och gäller fortfarande.

---

## 5. Validering – regler som gäller i alla lageroperationer

1. Artikel finns, är `aktiv`, och dess `tracking_mode` matchar operationen.
2. Plats finns och är `aktiv` (systemposten får inte användas för nya inleveranser/uttag – bara migrering och avstämning).
3. Antal är heltal > 0 (eller decimal där enheten tillåter, med explicit avrundningsregel).
4. Uttag/överföring kräver tillräckligt saldo **på just den platsen** – totalen över platser får aldrig användas som täckning.
5. Alla enhets-ID:n i en operation: finns, rätt artikel, rätt status, rätt plats, och inte dubbelt i samma request.
6. Ingenting skrivs förrän hela batchen validerats – delpost sparas aldrig.

---

## 6. QR-format

### 6.1 Artikel-QR (URL-baserad)

En etikett per artikel (eller per fysisk enhet) bär en **URL**, inte en kod. Mobilkameran
behöver ingen app – den öppnar webbläsaren direkt.

```
https://lager.kund.se/artikel/A8VVTG7?n=<12 hex>&s=<24 hex>
```

| Del | Innehåll |
|---|---|
| `{id}` | artikelns interna ID (Crockford Base32, versaler) |
| `n` | nonce – 12 hextecken, slumpas per utskrift |
| `s` | HMAC-SHA256(nyckel, id + "\n" + nonce), trunkerad till 24 hextecken |

- Nyckeln ligger i `storage/konfig/artikelqr.json` (0600) och genereras vid första behovet.
- `ArtikelQr::lankUrl($id)` bygger URL:en, `::verifieraLank()` kontrollerar den vid varje anrop.
- **Signatur kontrolleras alltid** – en gissad eller manipulerad URL ger sidan "Länken fungerar inte".
- Bas-URL:en hämtas från `storage/konfig/adress.json` (sätts i `/admin/system/adress`);
  saknas den används `APP_URL` eller requestens host. **Tryckta koder måste peka på rätt domän.**
- `ArtikelQr::bytNyckel()` (admin) genererar ny nyckel och **ogiltigförklarar alla tryckta koder** –
  används om etiketter kommer på avvägar eller vid domänbyte.

**Bild-URL:er** signeras på samma sätt: `/bilder/{id}/{fil}?n=…&s=…`, byggs av `ArtikelQr::bildUrl()`.
En bild utan giltig signatur ger 404, även om filen finns.

### 6.2 Enhets- och platskoder (textbaserade)

**Enhet:** `TDL-U-XXXXX-XXXXX-C`
**Arbetsplats:** `TDL-L-XXXXX-C`
**Inventeringssession (planerat, ej byggt):** `TDL-S-XXXXX-C`

- Alfabet: Crockford Base32 utan `I`, `L`, `O`, `U` (undviker förväxling vid manuell inmatning).
- `C` = kontrolltecken, ISO 7064 MOD 37-2. Manuellt inmatad felkod avvisas direkt med tydligt fel.
- Slumpas med `random_bytes`, minst 50 bitars entropi.
- Inmatning normaliseras: versaler, bindestreck och mellanslag tas bort före kontroll.
- Etikettens QR innehåller hela koden som text; appen accepterar både skanning och manuell inmatning.
- En arbetsplats-QR **väljer plats i gränssnittet**. Den bokför ingen förflyttning. Det står även tryckt på etiketten.

### 6.3 Rendering i webbläsaren

QR-koden ritas på klientsidan av `assets/qrcode.js`, styrd av `assets/qr-render.js`:

```html
<span class="qr" data-kod="TDL-U-XXXXX-XXXXX-C"></span>
<span class="qr" data-url="/artikel/A8VVTG7?n=…&s=…" data-storlek="160"></span>
```

- `qr-render.js` hittar alla `.qr` med `data-kod` eller `data-url`, ritar SVG och sätter `data-ritad`.
- Attributen `data-storlek` (px) och `data-cell` (modulstorlek) styr utseendet.
- Sidor med `<body data-skriv-ut>` skriver ut automatiskt 500 ms efter `load`.
- **CSP är `script-src 'self'`** – inga inline-skript, inga `onclick`. Allt går via `data-*`
  och delegerade händelser i `assets/app.js`.
- Kameraskanning använder `assets/jsqr.js` och laddas först efter knapptryck.

---

## 7. Skrivsäkerhet utan databas

### 7.1 En skrivare i taget

`flock(storage/locks/global.lock, LOCK_EX)` runt varje muterande operation, även backup. Läsning kan ske utan lås eftersom filer alltid byts atomiskt (läsaren ser antingen hel gammal eller hel ny fil).

### 7.2 Write-ahead-journal + atomiskt byte

```
1. Läs alla berörda JSON-filer.
2. Validera hela operationen (avsnitt 5).
3. Skriv storage/journal/<txid>.json:
   { txid, pid, tid, status:"pagaende",
     filer: { "artiklar.json": {revision, sha256_before},
              "enheter.json":  {...}, ... },
     skriv: [ {fil, nytt_innehall} ... ] }
4. För varje fil: lagra i storage/tmp -> fsync -> rename() över målet.
5. Append ledger-poster (egen rename, sist).
6. Markera journalen "klar" och radera den.
```

- **Krasch före steg 4:** journalen finns men målen är orörda → `aterhamta.php` raderar journalen.
- **Krasch mitt i steg 4:** journalen beskriver både gamla hash och nya innehåll → `aterhamta.php` skriver tillbaka *before*-versionerna från en säkerhetskopia som läggs i journalen, eller – enklare och säkrare – **spelar fram** operationen genom att skriva alla nya innehåll igen (idempotent). Vi väljer framåtspel eftersom nya innehåll redan är validerat och journalen är komplett.
- **Krasch efter steg 4 före ledger:** samma framåtspel lägger till ledgerposterna – men de får `recovered: true` med txid så att dubbletter kan upptäckas. Ledger får aldrig appendas två gånger för samma txid: kontrollera `txid` innan append.
- `bin/aterhamta.php` körs vid start om journalmappen är tom – appen vägrar starta i skrivläge med öppen journal (fail closed).

### 7.3 Revisionsnummer

Varje fil har `meta.revision`. Läsaren jämför revision innan skrivning; avvikelse → hela operationen görs om. Detta skyddar även om någon kringgår låset (t.ex. manuellt redigerad fil).

---

## 8. Autentisering och roller

- Session: `HttpOnly`, `Secure`, `SameSite=Lax`, unikt namn (`tadega_lager_sid`), `session.use_strict_mode=1`, regenerering vid inloggning.
- Idle-timeout 60 min, absolut livslängd 12 h, kan konfigureras.
- Rollkontroll i middleware per route; kontrollern kontrollerar dessutom ägarskap där det är relevant (t.ex. avstämning = admin).
- CSRF: per-session-token, jämförs med `hash_equals`, krävs på alla POST.
- Lösenord: `password_hash` (bcrypt/argon), minst 10 tecken, kontroll mot enkel lista, aldrig loggade.
- Första admin: `bin/installera.php` fungerar bara när `konton.json` saknar aktiva konton; skriver till ledgern och nekar därefter.

---

## 9. Etiketter

- **Enhetsetikett:** kod + artikelnummer + namn (kortat) + tryckt text "Flytta inte etiketten – koden följer enheten".
- **Arbetsplatsetikett:** platsnamn + kod + "Denna kod väljer plats i appen. Den flyttar inget lager."
- **Artikel-QR-etikett:** en etikett per artikel som klistras på **hyllkanten eller lådan**.
  Etiketten visar artikelnamnet störst (det läses på håll), artikelnumret, streckkoden och QR:en.
  Skannas med mobilkameran → artikelns publika sida med bilder och +/− antal.
- **Etikettskrivare (rulle):** `@page { size: <rullmått>; margin: 0 }` styr den fysiska
  utskriften. Rullbredden väljs i vyn (`?rulle=`) – förvalet är **62 × 29 mm**:

  | Val | QR | Används till |
  |---|---|---|
  | `62x29` (standard) | 24 mm | vanligaste hyllkantsrullen |
  | `62x100` | 42 mm | större lådor |
  | `50x25` | 20 mm | mindre rulle |
  | `89x36` | 30 mm | bredare rulle |
  | `A4` | 26 mm | vanlig skrivare, 3 kolumner |

- **QR:en får alltid mått i millimeter.** En SVG med `width: auto` kollapsar till noll bredd i
  utskrift – därför sätts storleken per rullbredd i vyn.
- **Felkorrigering L** på rullarna: färre moduler (37 i stället för 45) ger 0,65 mm rutor på 24 mm,
  vilket är 5 skrivarpunkter vid 203 dpi. Skanningsbart men inte slösaktigt.
- **Code 39-streckkod** som komplement för handscanners: artikelnumret om det bara innehåller
  `0-9 A-Z - . mellanslag`, annars utelämnas streckkoden. Streckbredden anges relativt så koden
  alltid passar etikettens bredd. Siffrorna trycks under streckkoden.
- **Ingen dubbel text:** artikelnumret visas bara en gång – under streckkoden när den finns.
- Generator: HTML, QR ritad på klientsidan (`qrcode.js` + `qr-render.js`).
  `<body data-skriv-ut>` startar utskriftsdialogen automatiskt 500 ms efter `load`.
- Väntande etiketter: artiklar kan skriva ut `pending`-enheter som aktiveras vid inleverans.

---

## 10. Loggning

JSON-rader i `storage/logg/` med: `tid` (ISO 8601), `request_id`, `konto_id`, `roll`, `handelse` (från fast lista), `route`, `resultat`, `ip` (hashad om integritetskrav finns). **Aldrig** lösenord, tokens, fullständiga kund-/personuppgifter, eller hela JSON-filer.

---

## 11. Backup och återläsning

- `bin/backup.php`: lås → zip av `storage/data` (alla JSON) + `storage/bilder/` (rekursivt) +
  `storage/konfig/{varumarke,artikelqr,adress}.json` → manifest med `fil`, `storlek`, `sha256`
  och antal poster → tidsstämplat filnamn → rotation.
- Manifestet använder prefix: vanliga datafiler utan prefix, bilder som `bild:<relativ sökväg>`,
  konfigfiler som `konfig:<namn>`. Vid återläsning verifieras alla med SHA-256; bild- och
  konfigposter hoppar över `poster`-kontrollen (de är inte JSON med postlistor).
- `bin/aterlas.php`: verifiera **hela** manifestet mot zippen innan något skrivs, återläs till tom
  testkatalog (`--till-testkatalog`), kör `bin/verifiera-historik.php` och kontroll av antal
  enheter/saldon. Först därefter `--skriv-till-drift`, som vitlistar varje sökväg med regex.
- **Bilden av QR-nyckeln ingår i backupen.** Utan den blir alla tryckta artikel-QR ogiltiga efter
  en återläsning – därför är `artikelqr.json` en obligatorisk del, inte ett tillval.
- Backup av ledger är obligatorisk – historiken är affärsdata.

---

## 11b. PWA på mobilen

Appen är en **progressiv webbapp** och fungerar därför på alla mobilplattformar utan appbutik.

| Del | Implementation |
|---|---|
| Manifest | `GET /site.webmanifest`, genererat dynamiskt med kundens namn och färger |
| Ikoner | 192, 256 och 512 px PNG i `public/assets/ikoner/` |
| Start | `start_url: "/?kalla=pwa"`, `display: "standalone"`, `theme_color` från varumärket |
| Service worker | `public/sw.js`, cache `tdl-v1` – cachar **endast skal** (CSS, JS, ikoner, offlinesida) |
| Data | Går **aldrig** via cachen – saldon måste alltid vara färska |
| Offline | `/offline` visas när nätet saknas; inga köade ändringar (medvetet – inga tysta dubbelbokningar) |
| HTTPS | Krävs för service worker; på `http://` fungerar appen men utan offline-cache |

Manifest, `sw.js` och `/offline` serveras **före installationsspärren**, annars kan webbläsaren
inte erbjuda "Lägg till på hemskärmen" innan appen är installerad.

---

## 11c. Skrivbordsappen (Electron)

| Del | Implementation |
|---|---|
| `desktop/main.js` | Skapar fönstret, startar den lokala PHP-servern, systemfält, autostart |
| `desktop/server.js` | Startar `php -S` på en ledig port (18421+), proxar till ett fast portnummer |
| `desktop/preload.js` | Minimal brygga – inga Node-API:er i sidan |
| Data | `~/.config/Tadega Lager/storage/` – användarens egen katalog, utanför programmet |
| Logg | `~/.config/Tadega Lager/tadega-lager.log` |
| Paketering | `npx electron-builder --linux dir` (motsvarande för win/mac) |

Samma PHP-kod körs i alla tre miljöerna – webb, PWA och skrivbord. Ingen separat kodväg.

---

## 12. Kvarvarande risker

| Risk | Åtgärd |
|---|---|
| Webbhotellet exponerar `storage/` | Verifieras i staging (SÄK-01–06). Flytta till katalog utanför docroot eller byt hotell annars. |
| Flera samtidiga skrivare | Globalt lås + revisionskontroll. Fungerar på delad lagring där `flock` fungerar; verifieras i miljökontrollen. |
| `flock` stöds inte (t.ex. NFS) | Måste upptäckas i `miljokontroll.php`; annars byt lagring. |
| Manuellt redigerade JSON-filer | Revisionsnummer + hash i ledgern upptäcker ändringar. |
| Tid/datum på servern fel | Alla tider i UTC, `tid` skrivs av servern; kontroll i miljökontrollen. |
