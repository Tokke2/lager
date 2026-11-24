# Förbättringsförslag – Tadega Lager

Underlag 2026-11-12, efter genomgång av koden och dina svar:
**inventeringen är det som tar mest tid**, ni har **Brother QL/Dymo** och vill **ladda upp egen logotyp**.

Varje förslag anger vad det löser, vad det kostar och om det är gjort eller ej.

> **Status 2026-11-12:** A1, A2 och A3 är byggda och testade (38/38 prov gröna).
> Inventeringen gäller nu hela arbetsstället i en session, räknas med +/− direkt i listan
> och har en framstegsöversikt som uppdateras live. Se `02_arkitektur-och-datamodell.md` §4.3.

---

## A. Inventeringen – tre saker som gör den långsam idag

### A1. En inventering gäller bara **en artikel** ✅ **ÅTGÄRDAD 2026-11-12**

**Idag:** `Inventering::startaInventering()` tar `plats_id` **och** `artikel_id`. Sessionen låser sig till en
artikel. Ska du räkna en hylla med 40 artiklar blir det 40 sessioner – 40 gånger "starta, räkna,
avsluta, stäm av".

**Värre:** `oppenSessionForPlats()` nekar en ny inventering om det redan finns en öppen på
**samma arbetsställe** – även om den gäller en helt annan artikel. En glömd öppen session
blockerar alltså hela arbetsstället tills någon stänger den.

**Förslag:** en session per **arbetsställe**, med flera artiklar i samma räkning.

```
Inventering INV-7K2M  ·  Huvudlagret  ·  öppen
──────────────────────────────────────────────
Räknat 12 artiklar · 3 med avvikelse
[ Skanna ]  [ Räkna klart ]
```

- En skanning identifierar artikeln automatiskt (QR per enhet → enheten vet sin artikel;
  artikel-QR → artikeln).
- Antalsartiklar får ett räknat antal per artikel i samma session.
- Avstämningen visar **alla** avvikelser på en gång, inte en artikel i taget.
- Förväntade saldon fryses vid start, precis som idag – men per artikel.

**Kostnad:** större ändring i `Domain\Inventering` (sessionen får en lista i stället för ett
`artikel_id`) plus nya vyer. Historiken påverkas inte – varje avstämning blir fortfarande
egna ledger-poster per artikel.
**Nytta:** från 40 sessioner till 1. Det här är den enskilt största tidsbesparingen.

---

### A2. Tvåfältsinmatning för antal – i stället för en knapptryckning ✅ **ÅTGÄRDAD**

**Idag:** för en antalsartikel måste man markera artikeln, skriva antalet, klicka **Spara antal**,
vänta på sidladdning, och sedan upprepa för nästa. Tre handlingar per artikel, plus väntetid.

**Förslag:** räkna direkt i listan.

```
Skruvdragare 18V        förväntat 12    [ − ]  12  [ + ]   ✓ sparat
Hammare 500 g           förväntat  8    [ − ]   7  [ + ]   ✓ sparat
Spik 50 mm              förväntat 240   [ − ] 240  [ + ]
```

- **+1 / −1** räknar upp från förväntat saldo – man behöver bara trycka när det avviker.
- Stark avvikelse markeras direkt i listan (röd = färre, gul = fler).
- Autosparat per rad, ingen separat "spara"-knapp och ingen sidladdning.

**Kostnad:** måttlig – en ny vy plus en POST-rutt per rad.
**Nytta:** från 3 handlingar till 0–2 per artikel.

---

### A3. Ingen vy för "var är vi nu" ✅ **ÅTGÄRDAD**

**Idag:** sessionen visar förväntat/räknat/differens för **en** artikel. Man ser inte hur långt
räkningen kommit totalt, eller vilka artiklar som återstår.

**Förslag:** en översikt över sessionen med tre grupper:

| Grupp | Innehåll |
|---|---|
| **Kvar att räkna** | artiklar med saldo > 0 som ingen har räknat |
| **Räknade utan avvikelse** | grön bock, kollapsad lista |
| **Avvikelser** | skillnad, och vad avvikelsen beror på om någon angett orsak |

**Nytta:** man ser när det är klart, och kan dela upp räkningen mellan flera personer.

---

## B. Brother QL / Dymo – etiketterna

Du har en Brother QL eller Dymo. De skriver via **vanlig drivrutin** (inte ZPL), så dagens
webbläsarutskrift fungerar – men tre saker behöver justeras:

### B1. Rullbredderna ska matcha Brothers egna mått

Brother QL använder dessa vanliga rullar:

| Rulle | Etikettmått | Status i appen |
|---|---|---|
| DK-11209 | 29 × 62 mm | ✅ finns (`62x100` är fel – se nedan) |
| DK-11208 | 38 × 90 mm | ✅ finns som `89x36` |
| DK-11204 | 17 × 54 mm | ❌ läggs till |
| DK-11201 | 29 × 90 mm | ❌ läggs till |
| DK-11202 | 62 × 100 mm | ✅ finns |

**Att göra:** byta namnvalen från mått till **rullnummer** (DK-11209), eftersom det står tryckt
på rullen och är lättare att välja än att mäta. Måtten visas vid sidan om.

### B2. Dymo-variant med mindre QR

Dymo LabelWriter har ofta 89 × 36 mm eller 54 × 25 mm. På de minsta blir QR:en 20 mm – i
underkant. Då är **streckkoden viktigare än QR:en** och bör ritas större.

**Förslag:** en inställning per utskrift: *QR störst* (hyllkant, man skannar med mobilen) eller
*streckkod störst* (handscanner).

### B3. Skriv ut direkt utan dialog (valfritt)

Brother och Dymo har egna program. Vill man hoppa över webbläsarens utskriftsdialog krävs en
lokal hjälpapp – det är ett större projekt och rekommenderas inte först.

---

## C. Företagsnamn i adminpanelen

**Status: finns redan, men är otydligt.** Fältet heter *"Företags-/appnamn"* och ligger under
**Varumärke**. Namnet slår igenom på:

| Plats | Verifierat |
|---|---|
| Sidhuvud | ✅ |
| Inloggningssida | ✅ |
| Webbläsartitel | ✅ |
| PWA-manifest (appens namn på hemskärmen) | ✅ |
| Etiketten (företagsrad) | ✅ |
| Publika artikelsidan | ✅ |

### C1. Tydligare etikett och hjälptext

Byt namn på fältet till **"Företagsnamn"**, och visa direkt under fältet var namnet syns.
Lägg till en förhandsvisning av etiketten så man ser resultatet innan man sparar.

### C2. Logotypen byggs inte om när namnet ändras ⚠️

**Problem:** appikonerna (192/256/512 px) och standardlogotypen ritas med namnet **"Tadega"**
inbakat i bilden. Byter man företagsnamn står det fortfarande *Tadega* på appikonen.

**Förslag:** generera ikonerna från företagsnamnet när det sparas – initialerna i en ruta med
 företagets färger. Då följer ikonen med automatiskt.

Vill man hellre ladda upp en egen logotyp (som du valde) fungerar det redan idag via
**Varumärke → Ladda upp logotyp**. Då används den i stället, och ikongenereringen hoppas över.

---

## D. Mindre saker som är värda att fixa

| # | Vad | Varför | Kostnad |
|---|---|---|---|
| D1 | **Mörkt/ljust tema per användare** | Man står i lagerlokal med starkt ljus – mörkt tema är svårt att läsa utomhus/under stark belysning | Liten |
| D2 | **Streckkodsläge på skannern** | En del handscanners skickar Enter automatiskt, andra inte. Ett fält som tar emot båda sparar klick | Liten |
| D3 | **Snabbsök på artikelnummer** i alla listor | Man skriver `731...` och hoppar direkt till artikeln | Liten |
| D4 | **Utskrift av hyllkantsetiketter i batch** | Välj 12 artiklar → skriv ut 12 etiketter på en gång, i stället för en artikel i taget | Måttlig |
| D5 | **Flera artiklar per etikettark** | På A4: fylla hela arket med blandade artiklar | Måttlig |

---

## Rekommenderad ordning

| # | Vad | Status |
|---|---|---|
| 1 | A1 – inventering per arbetsställe | ✅ klar |
| 2 | A2 – räkna med +/− i listan | ✅ klar |
| 3 | A3 – framstegsöversikt | ✅ klar |
| 4 | **C2 – ikoner följer företagsnamnet** | kvar – litet, men fel namn står på hemskärmen |
| 5 | **B1 – rullnummer för Brother** | kvar – gör utskriften begriplig |
| 6 | D4 – batch-utskrift av etiketter | kvar |

### Vad som byggdes (2026-11-12)

| Ändring | Fil |
|---|---|
| En session per arbetsställe, `artiklar` som objekt | `app/Domain/Inventering.php` (skriven om) |
| Bakåtkompatibel läsning av gamla sessioner | `Inventering::arGammal/artikelIds/rad` |
| Ny inventeringsvy med tabell och +/− | `app/views/inventering.php` (skriven om) |
| Ny startvy med framsteg per plats | `app/views/inventeringar.php` (skriven om) |
| Autosparning utan sidladdning | `public/assets/app.js` (`data-autoskicka`) |
| Levande framstegslista | `public/assets/app.js` (`ritaFramsteg`) |
| Nya rutter `lagg-till` och `ta-bort` | `public/index.php` |

### Två fel som hittades på vägen

1. **`app.js` laddades aldrig.** Layouten saknade `<script src="/assets/app.js">` helt.
   Allt JavaScript var dött: bekräftelsedialoger, autoutskrift och kamera. Lade till skriptet
   och skrev provet **SK-01** så att det inte kan hända igen.
2. **Fem vyer hade inline-skript** (`overforing`, `uttag`, `skanna`, `platsetikett`,
   `inleverans`) som CSP:n blockerade tyst. Funktionerna flyttades till `app.js` med
   självstart. Provet **SK-02** letar nu efter inline-skript i alla vyer.
