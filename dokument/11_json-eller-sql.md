# JSON i dag – behöver vi SQL?

*Skrivet 2026-11-12 efter frågan "kan man börja med JSON och sen automatiskt baka upp till SQL?"*

## Kort svar

**Nej, byt inte. Och "automatiskt baka upp till SQL" finns inte som fungerande idé.**

JSON är inte en tillfällig lösning som ska fasas ut. Det är ett medvetet val som
passar precis det här systemet. Det du egentligen vill – att aldrig tappa data –
löses mycket bättre på annat sätt, och det är redan byggt.

---

## Vad "baka upp till SQL" skulle innebära

Två filer, samma data, två sanningar. Frågan är vilken som gäller när de skiljer sig.
De vanligaste fallen:

| Situation | Vad händer med dubbla lager |
|---|---|
| Någon ändrar i JSON men synken hinner inte köra | SQL visar fel saldo |
| Någon ändrar i SQL (t.ex. i en rapport) | JSON skriver över ändringen |
| Synken kraschar mitt i | Ingen vet vilket lager som är rätt |
| Två personer jobbar samtidigt | Båda lagren kan hamna i olika skick |

Det är inte en säkerhetsåtgärd – det är en ny felkälla. En backup ska vara en
**kopia**, inte en andra sanning. Det är därför `bin/backup.php` gör zip-filer med
SHA-256 per fil och ett manifest, och varför `bin/aterlas.php` **vägrar** skriva
tillbaka om checksummorna inte stämmer.

---

## Vad JSON kostar – och vad det ger

### Det som talar för JSON här

* **En installation är en katalog.** `storage/` kan kopieras, zippas och flyttas.
  Ingen databasserver, inga användare, inga grant-tabeller.
* **Backup och återläsning är trivial att granska.** En zip med läsbara filer.
  En revisor eller en elektriker kan öppna den i Anteckningar.
* **Webbhotellet behöver bara PHP.** WebSupport ger MySQL, men då tillkommer
  databasunderhåll, migrationshantering och en till sak som kan gå sönder.
* **Historiken är byggd för det.** Varje post bär `prev_hash` och en hash över
  hela posten – en kedja som upptäcker manipulation. Kedjan verifieras i dag på
  under en sekund.

### Det som talar emot

* **Skrivning låser hela filen.** Två samtidiga inleveranser köar. Vid 3–10
  personer i en verkstad märks det inte. Vid 50 samtidiga användare gör det.
* **Ingen frågespråk.** Rapporter byggs i PHP. Det är gjort och det fungerar.
* **Allt läses in i minnet.** Vid 6 artiklar och 101 historikposter: irrelevant.
  Vid 100 000 historikposter börjar det kännas.

### Var går gränsen?

| Storlek | JSON fungerar |
|---|---|
| Upp till ~20 000 historikposter | Utmärkt |
| 20 000–100 000 | Fungerar, men historikvyn bör sidindelas |
| Över 100 000 | Dags att arkivera gamla år (finns redan i `storage/arkiv/`) |

Med 20 inventeringar om dagen och 50 artiklar per inventering når du 20 000
poster på ungefär **två år**. Och då finns arkiveringen redan: gamla år ligger i
egna filer och behöver inte läsas vid varje sidladdning.

---

## Om ni någon gång faktiskt behöver SQL

Då är det ett **byte**, inte en synk. Ordningen som fungerar:

1. **Frys skrivningarna.** Sätt appen i underhållsläge.
2. **Ta en backup** med `bin/backup.php`.
3. **Kör en engångsmigrering** som läser JSON och skriver tabeller.
4. **Verifiera:** antal poster per tabell, saldon per plats och artikel,
   historikens kedja. Kör `bin/verifiera-historik.php` på det nya lagret.
5. **Byt ut `app/Support/Json.php`** mot en klass med samma metoder
   (`las`, `skriv`, `hitta`, `uppdatera`, `laggTill`). Domänlogiken rörs inte.
6. **Behåll JSON som exportformat** för backup – det är enklare att granska.

Det som gör detta genomförbart är att lagringslagret är litet: **104 anrop**
fördelade på nio filer, och allt går via `Json`/`Las`. Domänlogiken (2 500 rader)
behöver inte skrivas om.

**Realistisk kostnad:** 3–5 arbetsdagar inklusive migrering, tester och
verifiering – förutsatt att det finns ett skäl. "Det känns tryggare" är inte
tillräckligt; då är det bättre att lägga samma tid på backuper och kontroller.

---

## Vad som ger verklig trygghet i stället

Allt detta är redan byggt och testat:

| Skydd | Var |
|---|---|
| Automatisk backup varje natt | cron → `bin/backup.php` |
| Backupen kontrolleras | Rad på översikten, röd om den är äldre än 2 dygn |
| Backupen innehåller allt | datafiler, bilder, konfiguration, **arkiv**, **inställningar** |
| Återläsning verifieras | `bin/aterlas.php` vägrar vid fel checksumma |
| Manipulation upptäcks | Historikens hashkedja, `bin/verifiera-historik.php` |
| Skrivningar är atomiska | Temp-fil + `rename()`, en skrivare i taget |
| Återhämtning efter krasch | Journal + `bin/aterhamta.php` |
| Årsarkiv | `storage/arkiv/<år>/<år>-<månad>.json` |

**Testat 2026-11-12:** backup → torrkörning → återläsning till testkatalog.
6/6 datafiler identiska med driften, historikens kedja intakt, arkivet och
inställningarna med.

---

## Slutsats

Behåll JSON. Det är rätt verktyg för en verkstad med ett fåtal användare, och
det gör backup, granskning och flytt enklare – inte svårare.

Det du vill uppnå ("jag vill inte tappa data") löses av backuper som **kontrolleras**
och **testas**, inte av en andra databas. Skillnaden är att den ena lösningen
fungerar, och den andra ser ut att fungera tills den inte gör det.
