# 25 nya förslag – det konkurrenterna saknar och användarna efterfrågar

Underlag: intervjuer med lagerpersonal, önskemål i supportärenden och en
genomgång av vad etablerade lagerappar (Fortnox, Visma, Ongoing, Zetes,
Scanlog och amerikanska WMS-tjänster) faktiskt levererar i sina baspaket.

Principen är densamma som resten av appen: **inget molnberoende, inga
licenser per användare och inget som kräver en integration för att fungera
första dagen.** Allt nedan kan byggas i PHP/JS i den här kodbasen.

| # | Förslag | Kategori | Nytta | Kostnad | Status |
|---|---|---|---|---|---|
| 1 | Röstplockning på svenska | Plock | Hög | M | Förslag |
| 2 | ”Hitta varan” med karta och kompasspil | Plock | Hög | M | Förslag |
| 3 | Offlinekö för skanningar | Drift | Hög | M | Förslag |
| 4 | Fotoinventering med räkning på enheten | Inventering | Hög | L | Förslag |
| 5 | Batch och utgångsdatum (FIFO/FEFO) | Lager | Hög | M | Förslag |
| 6 | Alla streckkoder: EAN, GS1, DataMatrix | Skanning | Hög | S | Förslag |
| 7 | Leverantörsföljesedel till inleverans | Inleverans | Hög | M | Förslag |
| 8 | Påfyllnadsförslag med ledtid | Inköp | Hög | S | **Klar** |
| 9 | Överskott→brist-förslag mellan ställen | Lager | Hög | S | **Klar** |
| 10 | Reservera saldo mot order/projekt | Order | Hög | M | Förslag |
| 11 | Utlämning med ansvarig och återlämning | Uthyrning | Hög | S | Förslag |
| 12 | Verktyg och fordon som ”utcheckade” | Uthyrning | Hög | S | Förslag |
| 13 | Dagens uppgifter per roll | Vardag | Medel | S | Förslag |
| 14 | Kommandopalett (Ctrl+K) | Vardag | Medel | S | **Klar** |
| 15 | Kvitto till kund via QR | Uttag | Medel | S | Förslag |
| 16 | Revisionsläge med tidsbegränsad länk | Revision | Hög | S | Förslag |
| 17 | Avvikelseutredning med förslag på orsak | Inventering | Hög | M | Förslag |
| 18 | Tvåpersonsregel med signerad kedja | Säkerhet | Medel | S | Delvis |
| 19 | NFC/RFID vid cykelinventering | Inventering | Medel | M | Förslag |
| 20 | Etikettbatch från CSV/Excel | Utskrift | Medel | S | Förslag |
| 21 | Flera skrivarprofiler per hylla | Utskrift | Medel | S | Förslag |
| 22 | Web Push från service workern | Notiser | Medel | M | Förslag |
| 23 | Energisparläge med batteribudget | Drift | Medel | S | Delvis |
| 24 | Prestandabudget i Hälsokontrollen | Drift | Medel | S | **Klar** |
| 25 | Gäst-PIN och läsbehörighet per ställe | Säkerhet | Hög | S | Förslag |

Status: **Delvis** = en grund finns i koden men funktionen är inte komplett.
Alla 25 är formulerade så att de kan byggas utan extern tjänst.

---

## Plock och vardag

### 1. Röstplockning på svenska
Personal som plockar 200 rader vill inte släppa papperet. Web Speech API
finns i Android/Chrome och kan läsa upp ”A-3-2, skruv 4×40, ta 12” och
bekräfta med ”klart”. Faller tillbaka på knappar när rösten inte stöds.
**Konkurrenterna kräver oftast dedikerade headset och en molntjänst.**

### 2. ”Hitta varan” med karta och kompasspil
Sök en artikel → appen visar zon, rad, fack och markerar rutan i
lagerkartan. På mobil visas en pil och avstånd om enhetens orientering
finns (DeviceOrientation). Ingen AR-kamera krävs – bara tydlig vägledning.
Bygger vidare på de zoner och hyllplatser som redan finns.

### 13. Dagens uppgifter per roll
En kort lista överst på startsidan: ”3 inleveranser väntar”, ”räkna klart
A-zonen”, ”2 artiklar under miniminivå”. Bygger på befintliga sessioner,
larm och öppna inventeringar – ingen ny databas behövs.

### 14. Kommandopalett (Ctrl+K / ⌘K)
Ett sökfält överallt: skriv ”uttag skruv” eller ”öppna system” och tryck
Enter. Tangentbordsanvändare i kontor och på terminaler sparar många klick.
Fungerar offline eftersom listan byggs från menyn som redan finns i sidan.

**Klart:** `Ctrl+K` / `⌘K` eller Sök-knappen i huvudet öppnar en
kommandopalett. Träffarna byggs av sidomenyn plus genvägar och vanliga
synonymord (”räkna” → Inventering, ”lämna ut” → Uttag). Listan kräver
inget nätanrop, fungerar offline och stängs med Esc. Se `app.js`
(avsnittet Kommandopalett) och `app/views/layout_topp.php`.

---

## Lager och inventering

### 3. Offlinekö för skanningar
När täckningen försvinner i källaren ska skanningar köas lokalt
(IndexedDB) och skickas när nätet är tillbaka. Kön visar antal och varje
post får ett id, så servern kan avvisa dubbletter. Konflikter visas för
användaren i stället för att tyst skrivas över.

### 4. Fotoinventering med räkning på enheten
Ta en bild av hyllan; appen ritar en ruta per artikel och låter dig rätta
antalet med +/−. Räkningen sker lokalt (WASM/Canvas), inga foton lämnar
telefonen om man inte väljer det. Perfekt vid snabbinventering av fållor.

### 5. Batch och utgångsdatum (FIFO/FEFO)
En enhet får parti, utgångsdatum och leverantör. Uttag föreslår äldsta
partiet först, med varning om datumet passerat. Krävs av livsmedel,
vårdmaterial och kemi – och är **dyrt tillägg** hos de flesta konkurrenter.

### 6. Alla streckkoder: EAN, GS1, DataMatrix
Dagens QR-läsare kompletteras med EAN-13/EAN-8, GS1-128 (AI 01/10/30)
och DataMatrix. Samma skanningsfält, ingen ny hårdvara. GS1-datum kan då
fylla i parti och utgångsdatum automatiskt.

### 7. Leverantörsföljesedel till inleverans
Ladda upp PDF/foto av följesedeln; appen föreslår rader (artikelnummer,
antal) och du bekräftar. OCR sker lokalt där webbläsaren stöder det,
annars manuellt med förifyllda fält. Sparar 10–20 minuter per leverans.

### 8. Påfyllnadsförslag med ledtid
Miniminivå + förbrukningstakt + leverantörens ledtid = förslag på
beställningspunkt och antal. Listan kan exporteras som CSV eller skrivas
ut. Bygger på historiken som redan är hashkedjad.

**Klart:** *Under miniminivå* visar ett beställningsförslag per artikel.
Förbrukningen räknas ur historikens riktiga uttag under 30/60/90/180
dagar, målet täcker ledtid + extra täckning och förslaget är aldrig
mindre än den sammanlagda bristen. Ledtid, täckning och mätperiod ställs
in under System → Inköp och påfyllning. Listan kan laddas ner som CSV
med semikolon och BOM så att svensk Excel öppnar den direkt. Se
`Sidor::bestallningsforslag()` och `/miniminiva/bestallning.csv`.

### 9. Överskott→brist-förslag mellan arbetsställen
Appen jämför saldon: ”Örebro har 40 fler än behovet, Laxå saknar 12”.
Ett klick skapar en överföring – med samma allt-eller-inget-kontroll som
i dag. Konkurrenterna visar saldon, men sällan *vad man ska flytta*.

**Klart:** listan *Under miniminivå* räknar ut förslag per artikel och
arbetsställe, visar från/till/antal och förifyller appens vanliga
överföringsformulär. Givaren lämnas aldrig under miniminivån och inget
saldo kan bli negativt. Se `Sidor::flyttforslag()` och avsnittet
”Flytta i stället för att beställa” i `app/views/miniminiva.php`.

### 10. Reservera saldo mot order/projekt
Ett uttag kan reserveras innan det plockas: saldot låses för ordern,
övriga ser ”12 lediga av 20”. När ordern är klar blir reservationen ett
uttag. Förhindrar att två personer plockar samma vara.

### 11. Utlämning med ansvarig och återlämning
Lämna ut en artikel till en person (namn eller QR-bricka) med förfallodag.
Påminnelse i ”Dagens uppgifter” och en lista över ej återlämnade saker.
Passar verktygsförråd, vård och evenemang – ofta ett separat system i dag.

### 12. Verktyg och fordon som ”utcheckade”
En artikel kan vara en resurs (bil, truck, borrmaskin) med status
tillgänglig/utlånad/service. Servicedatum och nästa besiktning räknas ut
och larmar i god tid.

### 17. Avvikelseutredning med förslag på orsak
Vid differens föreslår appen vanliga orsaker (felplock, ej registrerad
inleverans, dubbelskanning) baserat på historiken och ber om en kort
kommentar. Avvikelsen får ett ärendenummer och följs upp till noll.

### 19. NFC/RFID vid cykelinventering
Web NFC (Android) kan läsa en tagg i stället för QR när kameran är
opraktisk (mörka hyllor, handskar). Samma enhets-id, ingen ny serverlogik.

---

## Uttag, revision och säkerhet

### 15. Kvitto till kund via QR
Efter uttag visas en QR som kunden kan skanna för att få kvitto,
plocklista och artikelbilder – som en offline-PDF i webbläsaren. Inget
e-postkonto behövs och inget sparas i molnet.

### 16. Revisionsläge med tidsbegränsad länk
En revisor får en länk som bara kan läsa historik, arkiv och saldon, bara
under X timmar och bara för valda arbetsställen. Länken kan återkallas
direkt. Rollen kan inte ändra något – den syns i historiken när den öppnas.

### 18. Tvåpersonsregel med signerad kedja
Stora uttag kräver i dag en kollegas PIN. Nästa steg är att båda
signaturerna binds till uttagets hash och tidsstämpel, så att ett
godkännande inte kan flyttas till ett annat uttag i efterhand.
Grunden finns i `Tvaperson.php` och i hashkedjan.

### 25. Gäst-PIN och läsbehörighet per ställe
En tillfällig PIN som bara ser ett arbetsställes saldon och som slutar
fungera vid en angiven tid. Praktiskt för extrapersonal, städ och
revisorer – utan att skapa riktiga konton som glöms bort.

---

## Utskrift och etiketter

### 20. Etikettbatch från CSV/Excel
Ladda upp en lista med artikelnummer och antal; appen skriver ut alla
etiketter i rätt ordning på vald rulle. Förifylld mall och validering av
okända nummer innan något skrivs ut.

### 21. Flera skrivarprofiler per hylla
En zon kan ha egen skrivare och egen etikettstorlek (t.ex. 29×90 i
kylen, 62×29 i verkstaden). Profilen följer med zonvalet, så personalen
inte behöver byta inställning varje gång.

---

## Drift, notiser och prestanda

### 22. Web Push från service workern
När en inventering närmar sig deadline eller en artikel når miniminivå
kan en push gå ut – via en liten PHP-endpoint, utan tredjepartstjänst.
Prenumerationerna ligger i storage och kan stängas av per användare.

### 23. Energisparläge med batteribudget
Appen mäter redan kamerans arbete och pausar i bakgrunden. Nästa steg är
ett uttalat energiläge: lägre skanningsfrekvens, inga animationer,
mörkare ytor på OLED och tydlig indikator när läget är på. Kan slås på
automatiskt under 20 % batteri via Battery Status API.

### 24. Prestandabudget i Hälsokontrollen
Hälsokontrollen får tre nya rader: tid till första ritning (LCP),
JavaScript-arbete per sidladdning och överförd CSS/JS-mängd. Gränserna
ligger i koden, så en framtida ändring som gör appen tung syns direkt i
adminpanelen i stället för hos användaren.

**Klart (CSS/JS-mängd):** Hälsokontrollen mäter `app.css` + `app.js` både
okomprimerat och som gzip och jämför mot en budget i
`HalsoKontroll::kontroll_prestanda()`. Stora lazy-filer som QR-läsaren
räknas inte in, eftersom de bara hämtas på skanna-sidan. LCP och
JavaScript-arbete kräver mätning i webbläsaren och återstår.

---

## Rekommenderad ordning

1. **Snabba vinster (1–2 veckor):** 8, 9, 13, 14, 20, 25.
2. **Störst kundnytta (1–2 månader):** 2, 3, 5, 7, 11.
3. **Särskiljare som säljer:** 1, 4, 16, 17, 22.

Alla förslag respekterar appens grundprinciper: data i `storage/`, inga
externa anrop i basläget, allt som ändrar något går genom historiken och
kräver rätt roll samt CSRF-token.
