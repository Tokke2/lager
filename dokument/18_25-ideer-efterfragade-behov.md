# 25 nya idéer – behov att undersöka

Detta är idéunderlag. Förslag 4, 5, 8, 9, 10, 11, 13 och 14 har nu en första implementation; se dokument/20_valda-forbattringar.md för begränsningar. Övriga är förslag. De utgår från problem
som beskrivs i öppna användarrecensioner: långsamhet och sökning, rapporter
som behöver sammanställas, begränsningar i mobilflöden och behov av smidig
skanning. Källorna belägger behoven på en övergripande nivå, inte att varje
enskilt förslag efterfrågats eller att något saknas hos alla konkurrenter.
Exempelvis finns redan offlinearbete, utlåning och skanning hos andra.
Särskiljningen nedan är därför en **hypotes om kombination och utförande**
som behöver valideras med egna användare.

| Nr | Idé | Nytta och särskiljande utförande | Insats |
|---:|---|---|:---:|
| 1 | Synonymer och gamla artikelnummer | Hitta artikeln med lokala smeknamn, gamla nummer och leverantörsnummer; visa varför den matchade. | M |
| 2 | Sök efter utseende | Sök bland egna bilder med manuella visuella kännetecken, utan att skicka bilder till en extern AI-tjänst. | M |
| 3 | Hyllans senast kända bild | Visa ett datummärkt foto på platsen tillsammans med artikelns position; flagga om bilden kan vara gammal. | M |
| 4 | Fel hylla-rapport | En knapp på hyllan rapporterar felplacerad vara med bild och kommentar, utan att automatiskt ändra saldo. | M |
| 5 | Skanningshjälp i mörker | Visa ficklampsknapp där kameran stöder det, tydlig fokusvägledning och manuell reservväg. | M |
| 6 | Kontroll av tryckbar kod | Provvy räknar modulstorlek mot vald skrivare och varnar för för liten QR före utskrift. | M |
| 7 | Utskriftskö med testetikett | Samla olika etiketter och skriv först en testlapp med kontroll av bredd och skanning. | M |
| 8 | Lokal inventeringsövning | Kör en träningsinventering med fiktiva data utan att påverka riktig historik eller saldo. | M |
| 9 | Delåterlämning med kvitto | Återlämna delar av ett lån eller bestämda QR-enheter; visa vad som fortfarande är utlånat. | M |
| 10 | Verktygets hälsokort | Vid återlämning registreras skick och foto; en skadad enhet spärras för nytt lån tills den godkänts. | L |
| 11 | Säker överlämning mellan låntagare | Båda bekräftar vem som tar över ett verktyg, utan att det först ser ut att ligga på hyllan. | L |
| 12 | Utlåningskalender med kapacitetskonflikt | Visa framtida behov och upptäck när bokningar krockar; håll bokning separat från fysiskt saldo. | L |
| 13 | Saknat verktyg med åtgärdsplan | Markera saknat med ansvarig uppföljning och bekräftad avskrivning, utan att radera historiken. | M |
| 14 | Flyttförslag med förklaring | Föreslå att en vara byter hylla utifrån frekvens och närhet; visa underlaget och kräv godkännande. | L |
| 15 | Avvikelsekarta | Samla tidigare inventeringsavvikelser på kartan och visa trender per plats utan att exponera mer persondata än nödvändigt. | M |
| 16 | Räkna om förpackningar | Ta emot i kartonger och räkna i styck med tydlig, versionshanterad omräkning och förhandsvisning. | M |
| 17 | Hållbarhets- och returflöde | Följ returförpackningar och återbrukbart material separat från vanliga saldon. | M |
| 18 | Mätbara osäkra saldon | Visa hur gammal senaste verifierade räkningen är och vilka rörelser som skett sedan dess – inte en påhittad procentsiffra. | M |
| 19 | Jämför två tidpunkter | Bygg en sammanhållen rapport över saldoändringar och orsaker, med export och länkar till historikposter. | L |
| 20 | Granskad massändring | Visa före/efter och valideringsfel rad för rad innan ändringar av artikeldata bokförs. | L |
| 21 | Återställningsguide med konsekvenslista | Jämför backupens tidpunkt med aktuell data, varna för öppna lån och öva först i testkatalog. | L |
| 22 | Passöverlämning | Samla öppna inventeringar, utlåningar och olösta avvikelser i en signerbar sammanfattning för nästa pass. | M |
| 23 | Kö för åtgärder som kräver beslut | Samla inkomna felrapporter och godkännanden per roll; visa orsak och ansvarig utan att skapa dubbla uppgifter. | L |
| 24 | Prestandaprov med anonym data | Kör lokalt test med genererade artiklar och redovisa sidtid, datastorlek och ungefärlig minnesanvändning. | M |
| 25 | Tillgänglighetsläge för handskar och solljus | Låt användaren välja större reglage, hög kontrast och textetiketter; bevara val per enhet. | M |

Insats: S = liten, M = medel, L = stor. Uppskattningarna är grova och
förutsätter förstudie, säkerhetstester och test på riktiga enheter.

## Källor och begränsningar

- [Capterra: Inventory Management Software](https://www.capterra.com/inventory-management-software/) sammanställer bland annat återkommande synpunkter på sökning, prestanda, mobil användning och rapportering.
- [Capterra: inFlow Inventory-recensioner](https://www.capterra.com/p/78431/inFlow-Inventory/reviews/) innehåller exempel på önskemål om reservationer och sammanställda rapporter samt kritik av mobil skanning.
- [Capterra: Zoho Inventory](https://www.capterra.com/p/146241/Zoho-Inventory/) innehåller användarkommentarer om rapportanpassning, mobilfunktioner och lageravstämning.
- [Capterra: Asset Panda](https://www.capterra.com/p/142562/Asset-Panda/) beskriver befintlig utlåning och underhåll hos en konkurrent samt rapporterade stabilitetsproblem.
- [Reddit: What features do you look for in a simple inventory management app?](https://www.reddit.com/r/InventoryManagement/comments/1qmie9a/what_features_do_you_look_for_in_a_simple/) är en anekdotisk diskussion om enkelhet, mobil skanning och rapporter; den är inte representativ forskning.

Nästa steg är att be några verkliga lageranvändare rangordna idéerna och
prova prototyper. Kontrollerad offline-synk, reservationer och delåterlämning
kräver särskild hantering av samtidighet och konflikter.
