# Arbetsstället väljs innan arbetet börjar

*Byggt och provat 12 november 2026. 90 prov gröna, 0 misslyckade.*

---

## Vad som var problemet

Varje gång man tog emot en vara, lade ut en plocklista eller startade en
inventering måste man välja arbetsställe i en rullista. Samma val, om och om
igen – och med två ställen gick det lätt att ta emot på fel ställe utan att
märka det, eftersom listan började på "Välj …" varje gång.

## Vad som händer nu

När man loggar in och det finns fler än ett arbetsställe får man **en fråga,
en gång**:

> **Var jobbar du i dag?**
> Välj arbetsställe innan du börjar. Då fylls det i automatiskt när du tar
> emot, tar ut, plockar eller inventerar – och du slipper välja om för varje
> sak. Du kan byta när som helst uppe i huvudet.

Två stora tryckytor, en per ställe, med koden till höger. Under dem en
diskret genväg för administratören: **Arbeta överallt (byt ställe i varje
steg)**.

Efter valet står det alltid i huvudet vilket ställe passet gäller:

> ● **Örebro** ᵇʸᵗ

Klickar man på den kommer man tillbaka till valsidan. Den gröna pricken
betyder att ett ställe är valt.

## Var valet används

| Flöde | Vad som händer |
|---|---|
| **Inleverans** | Mottagande arbetsställe är förvalt. Raden "Förvalt: Örebro – ditt arbetsställe för passet" står ovanför listan. |
| **Uttag** | Samma sak – stället som lämnar ut är förvalt. |
| **Plocklista** | Listan öppnas direkt på det valda stället i stället för på "Välj …". |
| **Överföring** | *Från* är förvalt. *Till* är tomt – man flyttar ju till ett annat ställe. |
| **Inventering** | Stället som ska räknas är förvalt. |

I alla flöden går valet fortfarande att ändra i rullistan. Det förvalda är en
genväg, ingen spärr.

## Det viktiga undantaget

**Administratören låses inte.** "Arbeta överallt" stänger frågan utan att välja
något ställe. Då blir alla platsfält tomma igen och man väljer per steg, precis
som förut. Det finns alltid en "Välj arbetsställe"-knapp i huvudet så man kan
hoppa tillbaka.

## Två fällor som löstes

**1. Oändlig valsida.** Första versionen frågade "är ett ställe valt?". När
admin valde "Arbeta överallt" valdes inget ställe – alltså visades valsidan
igen. Och igen. Nu finns en separat flagga för *att valet är gjort*, skild från
*vilket* ställe som valdes. Prov AS-05 vaktar den.

**2. Fast utan ställe.** Om ett ställe stängs medan någon har det valt måste
personen få välja ett nytt – inte lämnas kvar med ett val som pekar på något
som inte finns. Flaggan rensas därför när stället försvinner, inte bara
plats-id:t. Prov AS-06 vaktar den.

## Servern litar aldrig på sessionen

Valet är bara ett **förval i formuläret**. Servern läser alltid det `plats_id`
som skickas in. Man kan alltså välja Laxå och ändå ta emot en vara på Örebro i
rullistan – och då hamnar varan på Örebro. Prov AS-04 bevisar det: valt ställe
Laxå, inleverans till Örebro, saldot landar på Örebro.

Det är med flit. Ett sessionsval som styr bokföringen vore farligt – då kunde
en kvarglömd flik på en annan dator bokföra varor på fel ställe.

---

# QR-lappar skrivs ut via datorn

Utskriften har samlats till **en sida: Etiketter**. Därifrån skrivs allt ut,
från datorn med etikettskrivaren – inget kräver mobil.

Sidan är ordnad i den ordning man jobbar:

**Skrivare** – vilken skrivare som är vald och hur brett den skriver. Det
vanligaste felet är att lappen är bredare än skrivhuvudet och blir klippt,
därför står det alltid utskrivet i klartext.

**Redo att sätta upp** – lappar som redan är skapade men inte kopplade till
något arbetsställe. De syns här så man inte skriver ut samma lappar två
gånger, med knapparna *Skriv ut* och *Ta emot* direkt på raden.

**1. Artikellappar** – en lapp per artikel med QR, artikelnamn och hylla.
Välj artikel, antal och rullbredd:

| Rulle | Används till |
|---|---|
| 62 × 29 mm | hyllkanten |
| 62 × 100 mm | hyllkanten, större |
| 50 × 25 mm | små etiketter |
| 89 × 36 mm | bredare |
| **A4-ark** | vanlig skrivare, inget rullbehov |

**2. Enhetslappar** – en lapp per fysisk enhet, för verktyg och maskiner som
spåras var för sig. Varje lapp får en egen kod som följer enheten hela livet.

**3. Arbetsställelappar** – en per ställe, med plats-QR. Sätts vid ingången.
Skrivs ut med `Skriv ut` och öppnar utskriftsdialogen direkt.

Under artikellapparna står vilken adress QR-koden pekar på, så man ser att den
stämmer innan man skriver ut 200 lappar mot fel server.

## Provat

| Vad | Resultat |
|---|---|
| Artikellapp | 2 lappar, QR ritad i båda, "Skruvdragare (demo)", "A1-hyllan 2", kundnamn |
| Arbetsställelapp | QR ritad, kod `TDLLKAYKDB`, namn "Örebro" |
| Skrivare | Brother QL-800 / 810W / 820NWB, 58 mm tryckbredd |
| Utskriftsdialog | öppnas direkt med `&skriv=1` |

---

# Provstatus

**90 godkända, 0 misslyckade.**

Nya prov:

| Prov | Vad det vaktar |
|---|---|
| AS-01 | valet sparas, kan bytas och glömmas |
| AS-02 | stängt arbetsställe kan inte väljas och glöms bort |
| AS-03 | systemposten kan aldrig bli ett arbetsställe |
| AS-04 | valet styr inte servern – bara förvalet |
| AS-05 | "arbeta överallt" stänger porten utan att välja ställe |
| AS-06 | stängt ställe öppnar porten igen i stället för att lämna kvar |

## Kört i webbläsare

| Steg | Resultat |
|---|---|
| Inloggning | valsidan "Var jobbar du i dag?" med Örebro och Laxå |
| Välj Örebro | översikten, huvudet visar "● Örebro byt" |
| Inleverans | "Örebro (ORE)" förvalt + förklarande rad |
| Uttag | "Örebro (ORE)" förvalt |
| Plocklista | "Örebro (ORE)" förvalt |
| Överföring | Från = Örebro, Till = tomt |
| Inventering | "Laxå – 0 artiklar med saldo" när Laxå var valt |
| Byt i huvudet | valsidan, välj igen → nytt ställe gäller |
| Arbeta överallt | översikten, "Välj arbetsställe" i huvudet, ingen loop |
| JS-fel | inga |

## Kvar att göra

- **Publik adress** måste sättas i System innan skarp drift – annars pekar
  utskrivna lappar på förhandsgranskningens adress.
- Demodata ligger kvar.

---

*Alla ändringar sparas i historiken. Ingenting i den här omgången ändrade
lagersaldon – proven kör mot en egen testlagring.*

---

# Etikettstorlekar – vilken rulle ska man köpa?

## DK-11202 (62 × 100 mm) fungerar

Rullen på 123ink heter **DK-11202** och är Broters vanligaste "fraktetikett".
Sidan skriver måtten som **100 × 62** – längd först. Brother själva kallar den
**62 × 100**: 62 mm över skrivhuvudet, 100 mm åt gången. Det är samma rulle.

Passar **Brother QL-500 / 550, QL-700, QL-800 / 810W / 820NWB** – alltså din
skrivare. 300 etiketter per rulle.

### Fördelar

- **Stor QR** – 58 mm i stället för 26 mm. Skannas på långt håll och i sämre
  ljus; fungerar även när lappen sitter bakom en verktygshållare.
- **Långt artikelnamn får plats** – två rader utan att kortas.
- **Bra på verktyg och lådor**, där en hyllkantslapp på 62 × 29 drunknar.

### Nackdelar

- **Fyra gånger så stor yta** som 62 × 29. På en smal hyllkant tar den över.
- **Dyrare per lapp** – men 0,5 kr styck är ändå försumbart.

### Vad jag rekommenderar

| Används till | Rulle |
|---|---|
| Hyllkanter, fack, lådor | **62 × 29 mm** – en lapp per hyllplats, liten och diskret |
| Verktyg, maskiner, enheter | **62 × 100 mm (DK-11202)** – stor QR, tål att sitta på en verktygstavla |
| Arbetsställelappar vid ingång | **62 × 100 mm** – namnet ska synas på håll |
| Snabbtest utan rullbyte | **A4-ark** på vanlig skrivare |

Det går att blanda: hyllkanterna får 62 × 29, verktygen 62 × 100. Välj storlek
per utskrift – appen kommer ihåg inget, men det är två klick.

## Rättat samtidigt

Tre fel som hittades när storlekarna provades:

1. **Rullistan i artikellappsformuläret skickade fel nycklar.** Den hade
   påhittade värden (`89x36`, som inte finns – Dymo-rullen heter `36x89`).
   Nu byggs listan från samma mallar som resten av appen, så den kan inte
   hamna i otakt igen.
2. **Formuläret gick till `/artiklar/x/etiketter`** med ett hårt kodat `x` i
   adressen i stället för den valda artikelns id. Nu byggs adressen av valet.
3. **Staplad etikett blev inte centrerad.** Textblocket växte och tryckte upp
   QR-koden, så en 62 × 100-lapp såg tom ut nedtill. Nu centreras innehållet.

## Provat

| Rulle | QR | Resultat |
|---|---|---|
| 62 × 100 (DK-11202) | 58 mm | centrerad, namn + hylla + kundnamn under QR |
| 62 × 29 (hyllkant) | 26 mm | oförändrad, QR till vänster om texten |
| Eget mått 62 × 100 | – | fungerar via fälten "Eget mått" |

Provsviten: **90 godkända, 0 misslyckade**. Historiken 165 poster, kedjan intakt.

---

# Startsidan – en ingång till allt

`/` var tidigare översikten. Nu är `/` appens **nav**, och översikten har
flyttat till `/oversikt`. Skälet: den som loggar in ska komma till en ingång
till allt, inte till en saldorapport. Översikten är en av ingångarna, inte
startpunkten.

## Vad som finns på startsidan

Tjugo kort i fem grupper, ordnade efter vad man ska göra:

| Grupp | Innehåll |
|---|---|
| **Dagligt arbete** | Inleverans, Uttag, Plocklista, Överför, Skanna, Inventering |
| **Lager och artiklar** | Artiklar, Översikt, Under miniminivå, Historik, Arkiv |
| **Etiketter och utskrift** | Skriv ut etiketter, Arbetsställets lapp |
| **Adminpanel** *(bara admin)* | Arbetsställen, Konton, System, Varumärke, Hälsokontroll, Byt arbetsställe |
| **Mitt konto** | Mitt konto, byt PIN |

Överst: en hälsning med namnet, vilket arbetsställe passet gäller, och **larmet
först** — ligger något under miniminivå står det där innan man hunnit klicka.

Korten visar levande siffror, inte statiska texter: antal artiklar, antal
ställen, hur många som är under miniminivå.

## Menyn

Fick en ny post: **Start** (`/`) före **Översikt** (`/oversikt`). Logotypen
länkar till `/`.

Bottenmenyn på telefon visar de fem vanligaste momenten: Start, Ta emot, Uttag,
Skanna, Översikt, Konto.

## Fem fel som hittades när sidan provades

1. **Unicode-ikoner blev tomma rutor.** Tecknen ⌖ ⚙ ◈ saknas i många typsnitt
   och renderades som tomma fyrkanter i skärmbilden. Alla ikoner är nu inline-SVG
   som ärver färg och ser likadana ut överallt.
2. **"Byt arbetsställe" gav 404.** Det är en POST-rutt (CSRF-skyddad), inte en
   länk. Kortet är nu en riktig knapp i ett formulär.
3. **"Arbetsställets lapp" landade på startsidan.** `/platsetikett` krävde
   `?plats=` och föll tillbaka på 404. Utan plats används nu arbetsstället för
   passet — det man vill skriva ut när man klickar från startsidan.
4. **Huvudet spillde över på mobil.** "Logga ut" klipptes utanför skärmen
   eftersom namnet tog för mycket plats. Namnet kapas nu med ellips, knapparna
   är mindre och "byt" göms på telefon.
5. **Länkar som pekade på `/` i tron att det var översikten.** Genomgångna —
   efter en åtgärd skickas man tillbaka till rätt sida (konton, artiklar,
   inventering), inte till startsidan.

## Provat

| Vad | Resultat |
|---|---|
| Alla kort, en och en | **20/20 svarar 200** med rätt rubrik |
| Byt arbetsställe (knapp) | valsidan, 2 ställen |
| Menyn | Start · Översikt · Inleverans … System |
| Mobil 390 px | en kolumn, inget spill, bottenmeny med rätt poster |
| JS-fel / HTTP-fel | inga |
| Provsviten | **92 godkända, 0 misslyckade** |

Nya prov: **ST-01** kontrollerar att varje kort på startsidan pekar på en rutt
som faktiskt finns i ruttabellen — så en död länk inte kan smyga in.
**ST-02** kontrollerar att översikten har en egen adress.
