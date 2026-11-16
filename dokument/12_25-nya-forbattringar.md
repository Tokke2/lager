# 25 nya förbättringar – omgång 2

> **Byggt sedan listan skrevs:** förslag **1** (kategori och hylla), **3**
> (plocklista) och **20** (hälsokontroll) är genomförda och provade – se
> `dokument/13_genomfort-1-3-20.md`. De står kvar nedan som de skrevs, med
> status markerad, så att listan går att läsa som en helhet.

**Datum:** 2026-11-13
**Utgångspunkt:** appen har idag artiklar, platser, enheter med QR, inleverans,
uttag, överföring, inventering, arkiv, historik med hash-kedja, roller, backup,
vitmärkning och etikettutskrift för 13 rullformat.

De 25 förslagen i `08_25-forbattringar.md` är genomgångna och **inget av dem
upprepas här**. Det som följer är nästa lager: saker som blir värdefulla först
när appen används dagligen eller av flera personer samtidigt.

Varje förslag har **Problem → Förslag → Nytta → Omfattning** så att det går att
prioritera utan att läsa koden.

---

## A. Lagerarbete som appen ännu inte stödjer

### 1. Kategorier och hyllplacering på artikeln  ✅ **byggd**

**Problem:** idag finns bara namn och artikelnummer. Efter 200 artiklar går det
inte att svara på "var ligger skruvarna?" utan att söka på varje namn.
**Förslag:** två frivilliga fält på artikeln – **kategori** (med autokomplettering
från det man skrivit förut) och **hylla/placering** (fritext, t.ex. "B3-hyllan 4").
Fälten blir filter i artikellistan och sökbara. Hyllan skrivs ut på etiketten.
**Nytta:** kortare plocktid, färre "var står det?" i lagret.
**Omfattning:** liten – två fält, filter och en rad på etiketten.

### 2. Leverantör och återförsäljare per artikel

**Problem:** när det är slut måste man komma ihåg varifrån artikeln köptes.
**Förslag:** **leverantör** som enkel lista (namn, e-post, telefon, orderlänk) och
koppling från artikeln. Vid miniminivålarmet följer en färdig beställningsrad med
leverantörens adress och artikelnummer.
**Nytta:** påfyllning blir en knapptryckning i stället för en efterforskning.
**Omfattning:** medel – ny entitet plus koppling, men enkel CRUD.

### 3. Plocklista / uttagslista för flera artiklar samtidigt  ✅ **byggd**

**Problem:** en order med tio artiklar blir tio separata uttag.
**Förslag:** en **uttagslista**: lägg till flera artiklar med antal, se totalt antal
enheter och om allt finns på platsen, skriv ut listan i plockordning (sorterad på
hylla) och bokför allt i en transaktion. Allt-eller-inget gäller fortfarande.
**Nytta:** den vanligaste dagliga uppgiften blir en rörelse i stället för tio.
**Omfattning:** medel – återanvänder `Rorelser::uttag` men kräver ny vy.

### 4. Svinn och kassation som egen rörelse

**Problem:** en trasig eller utgången enhet måste idag bokföras som uttag, vilket
gör att statistiken blandar försäljning med svinn.
**Förslag:** rörelsen **kassation** med obligatorisk orsak (trasig, utgången,
förlorad, stulen). Egna rader i historiken, egen summering per månad.
**Nytta:** svinnet blir synligt och går att följa upp – ofta den största
besparingen i ett lager.
**Omfattning:** liten – samma mönster som uttag, en orsakskod mer.

### 5. Serienummer och garanti på enheten

**Problem:** för verktyg och maskiner räcker det inte att veta att enheten finns –
man behöver tillverkarens serienummer vid garantiärende.
**Förslag:** frivilligt serienummer på enheten, inskrivet vid inleverans eller
via skanning. Sökbart. Garantidatum räknas ut från inköpsdatum och garanti­tid
som sätts på artikeln.
**Nytta:** garantiärenden löses på plats i stället för i ett pärm.
**Omfattning:** liten till medel.

### 6. Mätarens avläsning (driftstimmar, kilometer)

**Problem:** för maskiner som servas på driftstimmar finns ingenstans att skriva
av läsningen.
**Förslag:** valfri **mätaravläsning** per enhet med datum, och en påminnelse när
nästa service närmar sig. Kan kopplas till enhets-QR:en – skanna, skriv av
mätaren, klart.
**Nytta:** service blir proaktiv i stället för akut.
**Omfattning:** medel.

### 7. Automatisk påfyllnadslista

**Problem:** miniminivålarmet säger *att* det är slut, inte *vad* som ska beställas.
**Förslag:** en lista som räknar ut **beställningsförslag**: artiklar under
miniminivå, föreslaget antal upp till en nivå man sätter per artikel, sorterat på
leverantör. Går att skriva ut eller kopiera till e-post.
**Nytta:** påfyllningen blir en rutin på fem minuter i veckan.
**Omfattning:** medel – bygger vidare på miniminivå och förslag 2.

---

## B. Flera personer samtidigt

### 8. Vem gjorde vad – aktivitetsvy per person

**Problem:** historiken är kronologisk. Frågan "vad gjorde Patrik i går?" kräver
att man bläddrar.
**Förslag:** filter i historiken på **person, typ av rörelse, plats och datum**,
plus en enkel sammanställning: antal rörelser och antal enheter per person och dag.
**Nytta:** uppföljning utan att exportera till kalkylark.
**Omfattning:** liten – data finns redan, det är filter och en summering.

### 9. Uppgifter/checklista för skiftet

**Problem:** morgonrutinen ("räkna kylen, kolla miniminivå, lås verktygsrummet")
lever utanför appen.
**Förslag:** en **skiftchecklista** som admin sätter upp en gång. Varje uppgift
kan vara en länk till en sida i appen, och bockas av per dag med namn och tid.
**Nytta:** rutiner blir spårbara och glöms inte bort vid semester och nyanställning.
**Omfattning:** medel.

### 10. Tillfällig behörighet med utgångsdatum

**Problem:** en sommarvikarie får idag samma roll som alla andra och behåller den
tills någon kommer ihåg att stänga av kontot.
**Förslag:** ett **giltighetstid** på kontot. Efter datumet spärras inloggningen
automatiskt, och admin får en påminnelse i förväg.
**Nytta:** säkerhet utan manuell uppföljning.
**Omfattning:** liten – ett datumfält plus kontroll vid inloggning.

### 11. Delegerad inventering per område

**Problem:** vid inventering måste alla räkna allt, eller så räknar man dubbelt.
**Förslag:** en inventering kan delas upp per **plats** eller **kategori**, och
varje person ser bara sin del. När alla delar är klara stängs inventeringen.
**Nytta:** paralleliteten blir möjlig; stora lager inventeras på en dag.
**Omfattning:** medel.

### 12. Notis när någon annan ändrar samma artikel

**Problem:** två personer på olika plattor kan justera samma artikel samtidigt.
Reglerna är allt-eller-inget, men den ena får ett fel utan förklaring.
**Förslag:** vid konflikt visas **vad som ändrades och av vem**, med knappen
"försök igen" som läser om saldot.
**Nytta:** färre "det gick inte" i lagret.
**Omfattning:** liten.

---

## C. Etiketter och fysisk miljö

### 13. Etikett för hyllkant med bara text

**Problem:** på en hyllkant vill man ofta bara ha artikelnamn och artikelnummer
– en QR som ändå inte syns på håll tar plats.
**Förslag:** en egen malltyp **"endast text"** med tre storlekar, som ett tillval
bredvid QR-mallarna.
**Nytta:** tydligare hyllkant, snabbare att läsa på avstånd.
**Omfattning:** liten.

### 14. Färgkod på etiketten

**Problem:** olika kategorier ser likadana ut.
**Förslag:** en valfri **färgfläck** (kategori) i etikettens ena hörn, för skrivare
som klarar färg, och ett **mönster** (streck/ruta) för svartvita termiska skrivare.
**Nytta:** sortering på avstånd utan att läsa.
**Omfattning:** liten.

### 15. Flera kopior av samma etikett i en utskrift

**Problem:** ska en artikel märkas på fem ställen skriver man ut fem gånger.
**Förslag:** **antal kopior** i utskriftsvyn, med fördelning på A4-arket.
**Nytta:** en utskrift i stället för fem.
**Omfattning:** liten.

### 16. Skyddsplast och slitage – påminnelse om omskrivning

**Problem:** etiketter i en verkstad slits. När en slits upptäcks är det ofta för
sent.
**Förslag:** ett **bytesdatum** per etikettutskrift, med en påminnelse i listan
"etiketter att byta" efter X månader.
**Nytta:** etiketterna förblir läsbara.
**Omfattning:** liten.

### 17. Anpassad etikett – spara egen mall

**Problem:** `Eget mått` finns, men måttet måste skrivas in varje gång.
**Förslag:** spara egna mått som **namngivna mallar** ("Vår hylletikett 70 × 30"),
tillgängliga för alla i installationen.
**Nytta:** inget mätande, inga felmått.
**Omfattning:** liten.

---

## D. Drift, kvalitet och data

### 18. Katalogisering av artikelbilder – flera bilder per etikett

**Problem:** etiketten kan visa en bild, men bara den första.
**Förslag:** välj **vilken bild** som ska tryckas, och en **bild på etiketten
för enhets-QR** (visar vad enheten är).
**Nytta:** bilden blir ett arbetsverktyg, inte bara dokumentation.
**Omfattning:** liten.

### 19. Export/import av hela installationen (migrering)

**Problem:** flytt till ny server idag kräver SSH och `bin/`-kommandon.
**Förslag:** **export/import** i adminpanelen: en zip med alla JSON-filer plus en
kontrollsumma, och en import som verifierar hash-kedjan innan den skriver något.
**Nytta:** kunden kan flytta sig själv; supporten slipper SSH.
**Omfattning:** medel – återanvänder backup-formatet.

### 20. Hälsokontroll med varning i menyn  ✅ **byggd**

**Problem:** problem (låst transaktion, gammal backup, full disk) upptäcks först
när något går fel.
**Förslag:** en **hälsokontroll** som körs vid inloggning och visar en diskret
varningsikon i menyn för admin: senaste backup, journalstatus, skrivrättigheter,
ledigt utrymme, hash-kedjans status.
**Nytta:** fel upptäcks i förväg i stället för vid återläsning.
**Omfattning:** liten till medel.

### 21. Versionshantering av datamodellen

**Problem:** JSON-filerna har `"version": 1`. Nästa gång ett fält läggs till
måste äldre installationer migreras – idag finns inget stöd för det.
**Förslag:** en **migreringskedja** som körs automatiskt vid start, med
`brand/`-oberoende kopior och en logg i historiken.
**Nytta:** uppgraderingar blir säkra för kunder som redan har data.
**Omfattning:** medel, men den som sparar mest lidande på sikt.

### 22. Prestandaindex för stora lager

**Problem:** `Lager::artiklar()` läser hela filen vid varje anrop. Vid 5 000
artiklar blir listan långsam.
**Förslag:** ett **sidofil-index** (artikel-id → plats och saldo) som skrivs vid
samma transaktion, plus lat inläsning i listvyn. Fallback till full läsning om
index saknas.
**Nytta:** appen skalar från 200 till 5 000 artiklar utan databas.
**Omfattning:** medel till stor – mät först med en stor testdatamängd.

---

## E. Gränssnitt och tillgänglighet

### 23. Riktig sökning överallt – tangentbordsgenväg

**Problem:** sökfältet finns i artikellistan, men inte globalt.
**Förslag:** **Ctrl/Cmd + K** öppnar en sökruta som söker på namn, artikelnummer,
enhetskod och hylla, och hoppar direkt till träffen. Fungerar utan mus.
**Nytta:** snabbare än att klicka sig fram, och ett krav för den som inte kan
använda mus.
**Omfattning:** liten.

### 24. Skärmläsarstöd och fokusordning

**Problem:** formulären är korrekt märkta, men statusändringar (flash-meddelanden,
saldon som uppdateras vid inventering) annonseras inte alltid.
**Förslag:** `aria-live` på de ställen där något ändras utan sidladdning, samt
genomgång av fokusordningen i inventeringsvyn. Testas med skärmläsare.
**Nytta:** appen blir användbar även för den som inte ser skärmen – och det är
ett krav i offentlig upphandling.
**Omfattning:** liten.

### 25. Nattläge som följer systemet

**Problem:** temat är mörkt eller ljust och sätts manuellt.
**Förslag:** ett tredje val **"följ systemet"** (`prefers-color-scheme`), så att
plattan i lagret blir läsbar i starkt ljus och skonsam på kvällen.
**Nytta:** bättre läsbarhet i verklig miljö utan inställningar.
**Omfattning:** liten.

---

## Ordning jag skulle rekommendera

| Prioritet | Förslag | Varför först |
|---|---|---|
| 1 | **19 Export/import**, **20 Hälsokontroll** | Utan dem blir varje kundmigrering och varje fel en supportsak |
| 2 | **1 Kategorier/hylla**, **3 Plocklista**, **4 Svinn** | Störst effekt på det dagliga arbetet |
| 3 | **10 Utgångsdatum på konto**, **8 Aktivitetsvy** | Säkerhet och uppföljning, små ingrepp |
| 4 | **13–17 Etikettförbättringar** | Låg risk, tydlig nytta, kan göras i omgångar |
| 5 | **22 Prestandaindex** | Först när någon faktiskt har tusentals artiklar |

---

## Vad jag inte föreslår – och varför

- **Egen mobilapp.** PWA:n gör jobbet och slipper App Store. Att underhålla tre
  appar för samma funktion är mer arbete än nytta för en lagerlokal.
- **Databas (MySQL/Postgres).** JSON med lås och hash-kedja räcker långt och gör
  backup och flytt trivial. Byt först när förslag 22 visar att det behövs.
- **AI-baserad bildigenkänning för inventering.** Spännande, men idag ett
  experiment – kameran i en lagerlokal har för dåliga förhållanden.
