# 25 förslag som gör Tadega Lager bättre

Underlag 2026-11-12. Bygger på koden som finns, dina svar (inventeringen tar mest tid,
Brother QL/Dymo, egen logotyp) och det som faktiskt saknas.

Varje förslag anger **vad det löser**, **vad det kostar** och **om det är gjort**.
Sorterat efter vad som ger mest nytta först.

> **Arbetsställen (kontrollerat 2026-11-12):** admin kan lägga till hur många arbetsställen
> som helst – verifierat med sju stycken samtidigt. Platskoden utökades från 2–4 till **2–6
> tecken** så att fler platser får plats utan krock, och vyn visar nu antalet aktiva platser.
> Se även förslag **25** (import) om ni ska lägga upp många på en gång.

---

## A. Säkerhet och spårbarhet — det som skyddar verksamheten

### 1. Sessionstimeout med varning ⚠️ *borde vara först*

**Problem:** en inloggning varar tills man loggar ut. Står plattan framme på lagret är
den öppen för alla som går förbi.

**Förslag:** 30 minuters inaktivitet loggar ut automatiskt, med en varning 2 minuter innan
(”Du loggas ut om 2 minuter – tryck för att stanna kvar”).

**Kostnad:** liten. **Nytta:** hög. En stulen platta ska inte ge tillgång till lagret.

---

### 2. Historiken går att exportera

**Problem:** historiken finns bara i appen. Vid revision eller fråga från revisorn måste
man klicka igenom skärmen.

**Förslag:** **Historik → Exportera** ger CSV eller Excel med datum, artikel, plats, antal,
vem och kommentar. Plus en knapp ”Verifiera kedjan” som visar grönt/rött direkt.

**Kostnad:** liten. **Nytta:** hög – det är ofta det första en revisor frågar efter.

---

### 3. Larm när miniminivån nås

**Problem:** `miniminivå` finns på varje artikel men används bara som en siffra i listan.
Ingen får veta när det tar slut.

**Förslag:** översikten visar **”5 artiklar under miniminivå”** med direktlänk.
Valfri sammanställning per e-post en gång om dagen (kräver att webbhotellet tillåter `mail()`).

**Kostnad:** liten–måttlig. **Nytta:** hög – det är hela poängen med en miniminivå.

---

### 4. Tvåpersonersregel för stora uttag

**Problem:** en person kan ta ut 500 enheter utan att någon ser det förrän vid inventeringen.

**Förslag:** artiklar över ett visst värde (eller antal) kräver att en kollega bekräftar
uttaget med sin PIN-kod. Slås på per artikel eller globalt.

**Kostnad:** måttlig. **Nytta:** hög vid stöld/svinn i verksamheten.

---

### 5. Automatisk backup med kontroll

**Problem:** `bin/backup.php` finns men måste köras manuellt. Ingen märker om den slutar fungera.

**Förslag:** schemaläggning via cron på webbhotellet (instruktion finns i drift­dokumentet)
plus en **kontrollrad på översikten**: ”Senaste backup: i går 03:00 ✓” eller rött om den är äldre än 2 dygn.

**Kostnad:** liten. **Nytta:** hög – en backup man inte vet om den finns är ingen backup.

---

## B. Dagligt arbete — det man gör varje dag

### 6. Snabbuttag från artikelkortet

**Problem:** ska man ta ut 3 st måste man gå till Uttag, välja artikel, välja plats, skriva antal.

**Förslag:** **−1 / +1** direkt på artikelkortet, med platsen förvald från senaste gången.

**Kostnad:** liten. **Nytta:** hög.

---

### 7. Streckkodsläsning överallt

**Problem:** kamera­skanningen läser QR. Handscannern skickar ofta streckkod (EAN/Code 39).

**Förslag:** samma fält accepterar både QR, Code 39 och EAN-13, och slår upp artikeln på
artikelnummer om koden matchar ett sådant.

**Kostnad:** måttlig. **Nytta:** hög om ni har handscanners.

---

### 8. Batch-utskrift av etiketter

**Problem:** etiketter skrivs ut en artikel i taget.

**Förslag:** markera 20 artiklar i artikellistan → **Skriv ut etiketter** → ett ark med alla.
Välj antal per artikel.

**Kostnad:** måttlig. **Nytta:** hög vid uppstart och omflyttning.

---

### 9. Flera artiklar per A4-ark

**Problem:** A4-läget fyller bara en artikel per utskrift.

**Förslag:** fyll hela arket med blandade artiklar, 3×8 etiketter.

**Kostnad:** måttlig. **Nytta:** medelhög.

---

### 10. Minnas senaste val

**Problem:** varje uttag börjar med tomma fält. Man väljer samma plats och samma artikel igen.

**Förslag:** appen minns senaste arbetsställe per användare (cookie/session) och förväljer det.

**Kostnad:** liten. **Nytta:** medelhög.

---

### 11. Kvitto efter varje rörelse

**Problem:** efter ett uttag står det bara ”klart”. Man vet inte säkert vad som bokfördes.

**Förslag:** visa **”Uttag: 12 st Skruv 4x40 från Örebro. Nytt saldo 238.”** med möjlighet
att skriva ut som kvitto (termisk 58 mm).

**Kostnad:** liten. **Nytta:** medelhög.

---

### 12. Ångra senaste rörelse (inom 5 minuter)

**Problem:** skrev man fel antal måste man göra en motrörelse, vilket skräpar i historiken.

**Förslag:** en **Ångra**-knapp i 5 minuter som bokför en motrörelse med hänvisning till originalet.

**Kostnad:** måttlig. **Nytta:** medelhög.

---

## C. Etiketter och skrivare (Brother QL / Dymo)

### 13. Rullnummer i stället för mått

**Problem:** man måste mäta rullen. Det står ett nummer på den: DK-11209.

**Förslag:** välj **DK-11209 (29 × 62 mm)** i listan – måttet visas bredvid.

| Rulle | Mått |
|---|---|
| DK-11209 | 29 × 62 mm |
| DK-11208 | 38 × 90 mm |
| DK-11204 | 17 × 54 mm |
| DK-11201 | 29 × 90 mm |
| DK-11202 | 62 × 100 mm |

**Kostnad:** liten. **Nytta:** hög – tar bort gissandet vid varje utskrift.

---

### 14. Välj QR eller streckkod som dominant

**Problem:** på små rullar (17 × 54 mm) räcker inte utrymmet för båda.

**Förslag:** växla **”QR störst”** (man skannar med mobilen) eller **”Streckkod störst”**
(man har handscanner).

**Kostnad:** liten. **Nytta:** medelhög.

---

### 15. Etikett med artikelbild

**Problem:** på lådor långt bort är en bild lättare än text.

**Förslag:** valfri liten bild (15 × 15 mm) på etiketten, tagen från artikelns första bild.

**Kostnad:** måttlig. **Nytta:** medelhög i butik, låg i lager.

---

### 16. Skriv ut etikett direkt efter ny artikel

**Problem:** man skapar en artikel och måste sedan gå till Etiketter för att få en lapp.

**Förslag:** kryssruta **”Skriv ut etikett direkt”** i formuläret för ny artikel.

**Kostnad:** liten. **Nytta:** medelhög.

---

## D. Mobilen och PWA

### 17. Riktig offline-beredskap för skanning

**Problem:** service workern cachar bara skalet. Utan nät går det inte att skanna.

**Förslag:** scannade koder köas lokalt (IndexedDB) och skickas när nätet kommer tillbaka,
med tydlig markering **”3 skanningar väntar på att skickas”**.

**Kostnad:** stor. **Nytta:** hög i källare och betonglager, låg annars.

---

### 18. Större träffytor i hela appen

**Problem:** 48 px är bra i inventeringen, men knappar på andra sidor är mindre.

**Förslag:** minst 44 px överallt på pekskärm (`@media (pointer: coarse)`).

**Kostnad:** liten. **Nytta:** medelhög.

---

### 19. Behåll inloggningen i 30 dagar på egen enhet

**Problem:** PIN-kod varje morgon på samma platta.

**Förslag:** kryssruta **”Kom ihåg den här enheten”** som ger en långlivad men återkallelig
inloggning (kan tas bort under Konton → Aktiva enheter).

**Kostnad:** måttlig. **Nytta:** medelhög. Krockar delvis med förslag 1 – lös dem tillsammans.

---

### 20. Skanna i mörker

**Problem:** kameran behöver ljus.

**Förslag:** knapp för **ficklampa** (`torch` i MediaStream) medan kameran är igång.

**Kostnad:** liten. **Nytta:** medelhög i lager och källare.

---

## E. Rapporter och uppföljning

### 21. Rapport: vad rör sig och vad ligger still

**Problem:** man ser saldon men inte beteende.

**Förslag:** **Rapporter** med tre vyer: mest rörliga artiklar (30 dagar), artiklar utan
rörelse (90 dagar), och uttag per person.

**Kostnad:** måttlig. **Nytta:** medelhög – bra underlag för inköp.

---

### 22. Värdet på lagret

**Problem:** `prisreferens` finns men summeras aldrig.

**Förslag:** **Lagervärde: 142 380 kr** på översikten, per arbetsställe och totalt.

**Kostnad:** liten. **Nytta:** medelhög.

---

### 23. Utskrift av inventeringsprotokoll

**Problem:** efter en inventering finns resultatet bara på skärmen.

**Förslag:** **Skriv ut protokoll** – PDF med datum, plats, vem som räknat, alla differenser
och signaturen från historiken. Bra att sätta i pärmen.

**Kostnad:** liten. **Nytta:** medelhög vid revision.

---

## F. Administration och drift

### 24. Ikonerna följer företagsnamnet

**Problem:** appikonerna är ritade med ”Tadega” inbakat. Byter man namn står det kvar
på hemskärmen.

**Förslag:** rita om 192/256/512 px automatiskt med företagets initialer och färger när
namnet sparas. Laddar man upp egen logotyp används den i stället.

**Kostnad:** liten. **Nytta:** hög – det är det första kunden ser.

---

### 25. Importera artiklar från CSV/Excel

**Problem:** att lägga upp 300 artiklar för hand tar dagar.

**Förslag:** ladda upp ett kalkylark med kolumnerna *artikelnummer, namn, enhet, miniminivå,
prisreferens* → förhandsgranskning med fel markerade → importera. Finns redan
`migrera-aldre-lager.php` som grund.

**Kostnad:** måttlig–stor. **Nytta:** mycket hög vid uppstart av en ny kund.

---

## Sammanfattning

| Prio | Förslag | Kostnad | Nytta |
|---|---|---|---|
| 1 | **1** Sessionstimeout | liten | hög |
| 2 | **24** Ikoner följer namnet | liten | hög |
| 3 | **13** Rullnummer Brother | liten | hög |
| 4 | **2** Historiken exporterbar | liten | hög |
| 5 | **5** Backup med kontroll | liten | hög |
| 6 | **3** Larm vid miniminivå | liten | hög |
| 7 | **6** Snabbuttag på artikelkortet | liten | hög |
| 8 | **25** CSV-import | måttlig–stor | mycket hög |
| 9 | **8** Batch-utskrift | måttlig | hög |
| 10 | **7** Streckkodsläsning | måttlig | hög |

**Förslag 1, 2, 3, 5, 6, 13 och 24 är alla små jobb med stor nytta — de kan göras på en dag
tillsammans.** Övriga kräver mer och bör prioriteras efter vad som faktiskt skaver.

Vill du att jag börjar med de sju små?

---

## Status 2026-11-12

| Område | Läge |
|---|---|
| Flera arbetsställen | ✅ Klart – admin kan lägga till hur många som helst, platskod 2–6 tecken |
| Inloggning med namn och PIN | ✅ Klart – se [09_inloggning-och-losenord.md](09_inloggning-och-losenord.md) |
| Förslag 3 – miniminivålarm | ✅ Klart – se [10_larm-sakerhet-och-arkiv.md](10_larm-sakerhet-och-arkiv.md) |
| Förslag 4 – tvåpersonersregel | ✅ Klart – samma dokument |
| Förslag 5 – backup med kontroll | ✅ Klart – samma dokument |
| Års/månadsarkiv för inventeringar | ✅ Klart – samma dokument |
| Förslag 1, 2, 6, 7, 8, 11, 13, 14, 16–24 | 🚧 Kvar att bygga |
| Förslag 9, 10, 12, 15, 25 | ⛔ Bortvalda av användaren |
