# Tadega Lager

Lager- och inventeringsapp med **saldo per arbetsställe**, **QR per fysisk enhet**,
**artikel-QR med bilder** och **oorterlig historik**. Byggd i PHP 8.3+ med JSON-lagring –
ingen databas, ingen extern tjänst. Kan vitmärkas för andra företag.

Fungerar som **webbapp** på webbhotell, som **PWA på mobilen** och som
**skrivbordsapp (Windows/macOS/Linux)** med systemfält.

---

## Snabbstart (lokal test)

```bash
# Kräver PHP 8.3+ med json, mbstring, openssl, zip
php bin/installera.php --namn="Anna Admin" --pin=4821
php -S 0.0.0.0:8080 -t public public/index.php
# Öppna http://127.0.0.1:8080
```

Testdata (valfritt): `php bin/demodata.php --ja-jag-vet-att-detta-ar-test`

Märke (fristående – inga bokstäver, inget företagsnamn):

```bash
python3 brand/skapa-varumarke.py    # märke, appikon och etikettmall (SVG)
python3 brand/exportera-ikoner.py   # PNG/ICO/tray (kräver cairosvg, Pillow)
python3 brand/skapa-tryckark.py     # A3-ark för tryckeri
```

Tryckoriginalen följer med varje release och kan laddas ner i appen under
**Admin → Varumärke → Tryckoriginal**.

Skrivbordsapp:

```bash
cd desktop && npm install && npm start
```

---

## Vad som ingår

| Område | Innehåll |
|---|---|
| **Startsida** | `/` är appens nav – tjugo kort till allt: dagligt arbete, lager, etiketter, adminpanel och konto. Översikten med saldon ligger på `/oversikt` |
| **Lager** | Inleverans, uttag, överföring – allt-eller-inget med saldokontroll per arbetsställe |
| **QR per enhet** | En etikett per fysisk enhet; enheten behåller sitt ID genom hela livscykeln |
| **Direktutskrift** | "Etikett"-knappen i artikellistan öppnar utskriften direkt (`?skriv=1`) |
| **Varumärke** | Fristående märke (inga bokstäver, ingen firma) som ren SVG; A3-tryckark följer varje release och laddas ner i appen |
| **Etikettskrivare** | Brother QL-500/550 (60,9 mm), QL-800 (58 mm), Dymo (56 mm), Zebra 2/4 tum (56/104 mm) eller A4 – väljs i System |
| **Artikel-QR** | En QR per artikel som öppnar artikelns sida med bilder och snabb +/− av antal |
| **Bilder** | Upp till 12 bilder per artikel, JPG/PNG/WebP, skalas ner automatiskt |
| **Inventering** | En session per arbetsställe täcker alla artiklar. Räkna med +/− direkt i listan (autosparat), skanna enhetskoder, se framsteg live. Avstämning kräver admin |
| **Historik** | Hashkedja (SHA-256) – manipulation upptäcks med `bin/verifiera-historik.php` |
| **Roller** | Admin och lagerpersonal, behörighetskontroll på serversidan |
| **Inloggning** | Alla roller: namn och PIN (4–8 siffror). Sessioner, CSRF, kontouttåg i adminpanel |
| **Vitmärkning** | Namn, färger, tema, ordval och SVG-logotyp per kund – inget hårdkodat |
| **PWA** | Installerbar på Android/iOS, offline-sida, cachar aldrig saldon |
| **Skrivbordsapp** | Electron med lokalt PHP, systray, data i användarens datakatalog |
| **Backup** | zip med manifest och SHA-256, bilder och QR-nyckel ingår; återläsning verifierad |

---

## Katalogstruktur

```
index.php        reservingång om webbhotellet inte kan peka dokumentroten på public/
public/          webbrot (endast denna katalog exponeras)
  index.php      front controller med ruttabell
  assets/        CSS, JS, ikoner
  sw.js          service worker
app/
  Support/       Json, Las, Historik, Sakerhet, Koder, Konto, Auth,
                 Varumarke, SvgSanerare, Bilder
  Domain/        Lager, Rorelser, Inventering, Kontohantering
  Http/          Sidor, Admin, Installation, ArtikelQr, Vyer
  views/         alla sidmallar
bin/             installations- och driftskript
tests/           integrationstest (92 prov)
brand/           logotyp i SVG/PNG för tryck och appikoner
desktop/         Electron-app
dokument/        lansering, vitmärkning, drift
storage/         data, lås, journal, logg, backup, bilder (privat)
```

---

## Vanliga kommandon

```bash
./verktyg/bygg-zip.sh                             # paketera för nedladdning
php bin/installera.php --namn="Namn" --pin=NNNN   # första admin
php bin/demodata.php --ja-jag-vet-att-detta-ar-test
php bin/verifiera-historik.php                    # kontrollera hashkedjan
php bin/backup.php                                # zip med manifest
php bin/aterlas.php --fil=... --till-testkatalog  # prova återläsning
php bin/aterhamta.php                             # efter avbruten skrivning
php tests/integrationstest.php                    # integrationsprov
php tests/tillgangstest.php                       # versionsadress i förhandsvisning
node tests/klienttest.mjs                         # signatur och energisparläge
```

---

## Dokumentation

| Fil | Innehåll |
|---|---|
| `00_start-har.md` | Status och vägval |
| `01_inventeringschecklista.md` | Underlag för inventering |
| `02_arkitektur-och-datamodell.md` | Struktur, modeller, rutter |
| `03_kravmatris.md` | Krav mot lösning |
| `04_testplan.md` | Testfall |
| `dokument/05_lansering-och-vitmarkning.md` | Sälja till andra företag, bygga appen, tryckkrav |
| `dokument/06_drift-webbhotell.md` | Drift på WebSupport, säkerhetstester, felsökning |
| `dokument/08_25-forbattringar.md` | 25 förbättringsförslag med kostnad/nytta och status |
| `dokument/09_inloggning-och-losenord.md` | Namn och PIN för alla roller |
| `dokument/11_json-eller-sql.md` | Varför JSON räcker, och vad en SQL-flytt skulle kosta |
| `dokument/07_forbattringsforslag.md` | Första förslagsrundan – inventeringen, etiketter, logotyp |
| `dokument/10_larm-sakerhet-och-arkiv.md` | Miniminivålarm, tvåpersonersregel, backupkontroll och års/månadsarkiv |
| `dokument/12_25-nya-forbattringar.md` | 25 nya förslag med status (1, 3, 20 byggda) |
| `dokument/13_genomfort-1-3-20.md` | Vad som byggdes av förslag 1, 3 och 20 |
| `dokument/14_arbetsstalle-och-utskrift.md` | Arbetsställevalet, utskriftscentralen och etikettstorlekar |
| `dokument/17_karta-ean-utlan.md` | Artikel på kartan, EAN-skanning och utlåning |
| `dokument/18_25-ideer-efterfragade-behov.md` | 25 nya idéer, prioritering och källor |
| `dokument/19_skarp-responsiv-och-energismart.md` | Skärpan, responsivitet och resurshantering |

---

## Paketera för nedladdning

```bash
./verktyg/bygg-zip.sh
```

Bygger `Tadega-Lager.zip` i mappen ovanför projektet. Körs efter varje ändring
så att zipen alltid är aktuell.

Skriptet lämnar **inte** med gamla säkerhetskopior, sessionsfiler, lås eller
`node_modules` — men det behåller allt som behövs: `storage/data/` med saldon
och historik, `storage/artikelqr.json` (QR-nyckeln) och `storage/bilder/`.

Kontrollera alltid efter en byggning:

```bash
cd /tmp && unzip -q ~/Tadega-Lager.zip && cd Tadega-Lager
php tests/integrationstest.php   # ska ge alla gröna
```

## Säkerhet i korthet

- `public/` är enda exponerade katalogen; `storage/` ligger utanför webbroten
- Alla ändringar kräver inloggning, rätt roll och CSRF-token
- Artikel-QR-länkar är signerade (HMAC-SHA256); nyckeln kan bytas för att spärra gamla koder
- Uppladdade bilder kontrolleras med `getimagesize()`, inte filändelse
- CSP `script-src 'self'` – inga inline-skript
- Historiken är oföränderlig via hashkedja
