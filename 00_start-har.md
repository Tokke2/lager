# Tadega Lager – startdokument för ny chatt

> Historiskt planeringsunderlag: autentiseringsavsnitten är inaktuella. Aktuellt: [inloggning med PIN](dokument/09_inloggning-och-losenord.md).

**Datum:** 2026-11-12
**Status:** Appen är byggd och fungerar – webb (PHP 8.3+), installation, adminpanel,
artikel-QR med bilder, PWA och skrivbordsapp. 25/25 integrationstester passerar.
Se avsnittet **Läget 2026-11-12** längst ned för aktuell status.
(Texten nedan är det ursprungliga planeringsunderlaget och behålls som historik.)

---

## 1. Viktigt först: arbetskopian finns inte i den här sessionen

Arbetsytan är **tom**. Det finns inga portal-filer, inga include-filer med lagerreferenser, ingen lager-JSON och ingen mapp för den tänkta lagerappen.

Det betyder:

- Steg 1 i den föreslagna starttexten – *inventera vilka lagerfiler som faktiskt finns i arbetskopian* – **kan inte genomföras ännu**.
- Ingenting har ändrats i portalens data eller i det äldre `update_stock()`-flödet. Det finns inget att ändra i den här sessionen.
- De slutsatser som står i bakgrundsdokumentet (äldre lagerflöde finns som referens, ingen komplett app) är **inte verifierade av mig** – de är ditt underlag. Jag har markerat dem som "att verifiera" i kravmatrisen.

### Tre sätt att komma vidare – välj ett

| Alternativ | Vad du gör | Vad jag gör |
|---|---|---|
| **A. Ladda upp kopia** | Zippa arbetskopian (se saneringsråd i `01_inventeringschecklista.md`) och ladda upp här. | Inventerar på riktigt: filer, hashar, `update_stock()`, router, JSON, konflikter mot kravspec. |
| **B. Peka på repo/katalog** | Ge mig en URL till repo eller en kopia som går att läsa. | Samma som A, förutsatt att jag kan nå den. |
| **C. Kör skriptet själv** | Kör `verktyg/inventera-lagerfiler.sh` mot en kopia av arbetskopian och skicka `rapport.md`. | Analyserar rapporten och går vidare utan att filerna lämnar din miljö. |

Skriptet är strikt **read-only** mot det scannade trädet. Det skriver bara den rapportfil du anger.

---

## 2. Vad som är framtaget i den här sessionen

| Fil | Innehåll |
|---|---|
| `00_start-har.md` | Detta dokument: status, vägval, nästa steg. |
| `01_inventeringschecklista.md` | Exakt vad som ska inventeras, hur, och vad jag behöver få veta samtidigt. |
| `02_arkitektur-och-datamodell.md` | Föreslagen fristående struktur: kataloger, datamodell, skrivsäkerhet, auth, QR-format, backup. |
| `03_kravmatris.md` | Kravspecens områden rad för rad: status, risk, vilket test som verifierar. |
| `04_testplan.md` | Testplan på A–H-nivå, inklusive alla tolv godkännandetester och negativa fall. |
| `verktyg/inventera-lagerfiler.sh` | Read-only inventering av en arbetskopia (hash, nyckelord, router, `php -l`). |
| `verktyg/miljokontroll.php` | Read-only miljökontroll för webbhotellet (PHP-version, extensions, ini, rättigheter). |

### Verifierat i den här sessionen

- `inventera-lagerfiler.sh`: syntaxkontrollerat och testkört mot ett fingerat portalliknande träd (router, `update_stock()`, gammal lagervy, aggregerad `lager.json`, tom `lager_tom.json`, `.htaccess`). Rapport, nyckelordsträffar, JSON-analys och `--php-lint` fungerar. **Hasharna i det granskade trädet var identiska före och efter körning** – skriptet ändrar inget.
- `miljokontroll.php`: syntaxkontrollerat och kört med PHP 8.3.32; fångar bl.a. `flock`, atomisk `rename`, `fsync`, `password_hash`, `random_bytes`. Skrivet för att även gå att köra på PHP 7.4 så att det kan användas *innan* hotellets version är känd.
- Dokumenten `02`–`04` är förslag, inte implementerad kod. Inga lagerdata har rörts.

---

## 3. Kortversion av rekommendationerna

1. **Separat app, egen docroot.** `public/` som webbrot, allt annat utanför. Fallback om webbhotellet inte tillåter egen docroot: `storage/`, `app/`, `bin/` spärras i webbserverns konfiguration **och** med `.htaccess` (defense in depth). Detta måste verifieras i staging.
2. **En global skrivlås + write-ahead-journal.** Alla muterande operationer: lås → läs → validera allt → journal med before/after → atomisk `rename()` → ledger-append → journal bort. Vid krasch rullas ofullständig transaktion tillbaka via `bin/aterhamta.php`. Detta är det enda sättet att få "allt eller inget" över flera JSON-filer utan databas.
3. **Saldo i antal lagras, QR-saldo härleds.** Artikelns `tracking_mode` styr: `quantity` → sparat saldo per (artikel, plats); `qr_units` → saldo = antal aktiva enheter på platsen, aldrig dubbellagrat. Förhindrar drift mellan två sanningar.
4. **QR-koder: slumpade, kontrolltecken, grupperade.** Format `TDL-U-XXXXX-XXXXX-C` (Crockford Base32 + ISO 7064 MOD 37-2). Kort, läsbart, felsäkert vid manuell inmatning, ogissbart.
5. **Historik = append-only ledger med hash-kedja.** Varje post bär `prev_hash`; `bin/verifiera-historik.php` upptäcker manipulation. Ingen endpoint ändrar ledger.
6. **Avstämning kräver admin + uttrycklig bekräftelse.** Fas 1 följer äldre logik: saknade enheter bokförs som uttagna, okända enheter läggs inte till. Varje avstämning blir ledger-poster med `request_id`.
7. **Roller på serversidan, route för route.** Personal kan inte nå admin-endpoints ens med manipulerad request. Sessions ogiltigförklaras via `session_epoch` som höjs vid lösenordsbyte/avstängning.
8. **Backup och återläsning är en testpost, inte en rutin på pappret.** Manifest med SHA-256 per fil + antalskontroll (enhets-ID:n, saldon) + återläsningstest med testdata innan staging.

---

## 4. Öppna beslut (rekommendation inom parentes)

| # | Beslut | Rekommendation |
|---|---|---|
| 1 | Separat app vs. återaktivera portalfliken | Separat app enligt kravspecen. Ingen ändring i portalens router. |
| 2 | Domän, webbhotell/proxy, PHP-version | Egen subdomän, HTTPS obligatoriskt, PHP 8.2+ (helst 8.3). Kör `miljokontroll.php` på hotellet. |
| 3 | Arbetsställen + första admin | Örebro, Laxå, `SYSTEM_LEGACY` som startdata. Första admin via `bin/installera.php` (CLI, fungerar bara när kontofilen är tom). |
| 4 | QR-etikettformat och skrivare | Formatet är beslutat (se ovan); fysisk layout/printer är öppen. Generatorn gör A4-ark och kan senare få termisk 50×30 mm. |
| 5 | Data- och backuplagring | `storage/` utanför docroot, 0700/0600, backup 14 dagliga + 8 veckovisa, återläsningstest varje månad. |
| 6 | Migrering av äldre lagerfiler | Kopiera, checksumma, importera envägs med torrkörning först. Portalens filer lämnas orörda. Exakt mappning kräver filerna. |
| 7 | Får personal initiera inventering? | Ja. **Avstämning** (skriva av differens) endast admin i fas 1. |
| 8 | Ska "arbetspass" (platsval per skift) finnas i fas 1? | Nej, bara inventeringssessioner. Platsväljaren kan vara klientsidig kontext. Kravet "öppen session blockerar stängning" gäller inventeringssessioner. |

---

## 5. Nästa steg

1. **Du:** välj A, B eller C ovan (och svara på frågorna i chatten).
2. **Jag:** genomför inventeringen, fyller i `03_kravmatris.md` med verkligt utfall och justerar arkitekturen efter vad filerna visar.
3. **Du godkänner** arkitektur + testplan. Först därefter byggs ett körbart skelett (auth, lagringslager, artiklar/platser) – fortfarande utan att röra portalens data eller `update_stock()`.

> **Regel som gäller hela projektet:** ingen lagerdata flyttas, raderas eller skrivs om innan den är säkerhetskopierad och migreringen verifierats.


---

## Läget 2026-11-12 (uppdaterat)

### Klart och verifierat

| Del | Status | Bevis |
|---|---|---|
| Kärnflöden (inleverans, uttag, överföring, inventering, avstämning) | Klar | 25/25 integrationstester |
| Vitmärkning (namn, färger, SVG-logotyp, tema, ordval) | Klar | `/admin/varumarke` 200, SVG-sanering testad |
| PIN-inloggning + kontohantering i adminpanel | Klar | inloggning 303 → översikt |
| Arbetsställen som data (ingen hårdkodning) | Klar | `/platser` skapa/ändra/stäng |
| **Artikel-QR med bilder och snabb +/-** | **Klar** | publik sida 200, +2/−1 verifierat, bild utan signatur 404 |
| **PWA (installerbar, offline-skal, ingen datacache)** | **Klar** | `/site.webmanifest`, `/sw.js`, `/offline` 200 |
| Skrivbordsapp (Electron, systray, multi-OS) | Klar | startar, PHP-server, fönster → tray |
| Backup/återläsning inkl. bilder och QR-nyckel | Klar | zip 8 filer + 1 bild, återläsning verifierad |
| Dokumentation (lansering, drift, vitmärkning) | Klar | `dokument/05` + `dokument/06` |

### Nya filer sedan förra läget

- `app/Support/Bilder.php` – bilduppladdning med getimagesize-kontroll, nedskalning, max 12/artikel
- `app/Http/ArtikelQr.php` – signerade QR-länkar, publik artikelsida, +/- antal, bildservering
- `app/views/artikel_publik.php`, `artikel_publik_fel.php`, `artikel_etiketter.php`
- `public/assets/qr-render.js` – CSP-vänlig QR-rendering (ersätter inline-skript)
- `public/sw.js` – service worker som bara cachar skalet

### Nya rutter

```
GET  /artikel/<id>?n=…&s=…          publik artikelsida (signerad)
POST /artikel/<id>/antal            +/- antal (inloggning + CSRF)
POST /artikel/<id>/bild             bilduppladdning från mobil (inloggning)
GET  /bilder/<artikel>/<fil>?n&s    bild (inloggning eller signatur)
POST /artiklar/<id>/bilder          bilduppladdning i appen
POST /artiklar/<id>/bilder/ta-bort  ta bort bild
GET  /artiklar/<id>/etiketter       artikel-QR-etiketter (utskrift)
GET  /site.webmanifest              dynamisk manifest
GET  /sw.js, /offline               PWA
POST /admin/system/adress           publik adress för QR
POST /admin/system/qr-nyckel        spärra alla utskrivna QR
```

### Läget 2026-11-12 – inventeringen ombyggd

**38 av 38 prov gröna.**

| Vad | Resultat |
|---|---|
| Artiklar per session | 1 → **alla på arbetsstället** |
| Starta/räkna/avsluta för 40 artiklar | 40 sessioner → **1** |
| Räkna ett antal | 3 handlingar + sidladdning → **1 klick, inget omladdat** |
| Framsteg | bara efter omladdning → **live** |
| Avstämning | en artikel i taget (per session) → **alla avvikelser i en åtgärd** |
| Blockerad plats av glömd session | hela platsen → **fortfarande en per plats, men sessionen täcker allt** |

**Filer som skrevs om:** `app/Domain/Inventering.php`, `app/views/inventering.php`,
`app/views/inventeringar.php`, `app/Http/Sidor.php` (inventeringsdel),
`public/assets/app.js` (autospar + framsteg), `app/views/layout_botten.php`,
fyra vyer rensade från inline-skript.

### Kvar att göra

- [x] `desktop/package.json` – `extraResources` (public, app, bin, brand)
- [x] Linux-bygge kört: `desktop/release/linux-unpacked/tadega-lager`, startar och svarar 303 på `/login`
- [x] Brandpaletten är standard: `Varumarke::STANDARD` = #2F6BFF / #34D399 / #0D1420
- [x] `02_arkitektur-och-datamodell.md` uppdaterad (bilder, artikel-QR, PWA, desktop, rutter)
- [x] PWA-filer (manifest, sw.js, offline, ikoner) serveras före installationsspärren
- [x] Ikon-192.png tillagd (Android kräver 192 px)
- [x] `README.md` skapad i projektroten
- [x] **Etikettskrivare (rulle)** – `@page` per rullbredd (62×29 standard), QR i mm,
      felkorrigering L, Code 39-streckkod, namn i fetstil. 5 nya prov → 30/30
- [x] Förhandsvisningen visas i exakt tryckstorlek
- [x] **Inventering per arbetsställe** – en session räknar alla artiklar (A1). 5 nya prov (INV-01…05)
- [x] **Räkna med +/− direkt i listan** – autosparas utan sidladdning (A2)
- [x] **Framstegsöversikt** – "X av Y räknade" med stapel, uppdateras live (A3)
- [x] **`app.js` laddades aldrig** – layouten saknade skripttaggen. Allt JS var dött (SK-01)
- [x] **5 vyer hade inline-skript** som CSP:n blockerade (SK-02). Nu 0 inline-skript
- [x] Bakåtkompatibel läsning av gamla inventeringssessioner
- [ ] **Windows/macOS-bygge** – körs på respektive OS: `npm run bygg:win` / `npm run bygg:mac`
      (kan inte korskompileras pålitligt från Linux-sandlådan)
- [ ] Bygg om logotypen med kundens namn: `python3 brand/skapa-varumarke.py`
- [ ] Verifiera `.htaccess`-sökvägar på webbhotellet (särskilt `/bilder`, `/sw.js`, `/site.webmanifest`)
- [ ] PHP-binär buntas inte in i skrivbordsappen – kräver PHP 8.3 installerat på datorn
      (eller lägg `php/` i `extraResources` och peka `server.js` dit)
