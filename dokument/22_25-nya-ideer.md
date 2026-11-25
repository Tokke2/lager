# 25 nya idéer för Lager – hypoteser att undersöka

Dessa idéer presenterades i chatten. De är jämförda med tidigare förslagslistor
i `dokument/`, men deras status i Lager, efterfrågan och konkurrenternas utbud
är inte verifierade. De är inte beslutade implementationer. Alla skrivande
flöden måste bevara behörigheter, lås, dataintegritet och historik.

1. **Provmontering:** Koppla ihop en uppsättning artiklar för att kontrollera att delarna passar innan de tas ut, utan att påverka saldo.
2. **Alternativa kompatibla delar:** Visa manuellt godkända ersättare med skillnader i mått och egenskaper, utan automatiskt artikelbyte.
3. **Innehållsförteckning för verktygsväska:** Jämför förväntade delar med de delar som skannas vid återlämning.
4. **Uppsättningens saknade delar:** Visa vilka komponenter som hindrar en viss uppsättning från att bli komplett.
5. **Platskapacitet:** Ange maxvikt och volym per hylla och varna innan föreslagen placering överskrider gränsen.
6. **Samförvaring:** Varna om artiklar med manuellt angivna oförenliga lagringskrav placeras på samma plats.
7. **Hanteringsinstruktioner:** Visa skyddsutrustning och lyftanvisningar vid uttag av särskilt markerade artiklar.
8. **Måttkontroll vid placering:** Jämför artikelns och hyllans angivna mått innan en placering godkänns.
9. **Förväxlingsvarning:** Markera artiklar som personalen kopplat ihop som lätt förväxlade och visa skillnaden före uttag.
10. **Plomberad låda:** Registrera plomberingsnummer och verifiera att plomberingen är obruten vid utlämning och återkomst.
11. **Tillfällig avspärrning av hylla:** Markera en plats som otillgänglig under städning eller underhåll utan att ta bort dess saldo.
12. **Blandad placering:** Visa vilka olika artiklar som delar en hyllruta inför ommöblering.
13. **Artikelns dokumentpaket:** Knyt lokalt lagrade instruktioner och säkerhetsblad till artikeln med tydlig dokumentversion.
14. **Dokumentkvittens:** Låt en användare bekräfta att en viss version av en hanteringsinstruktion har lästs.
15. **Obligatoriska artikeluppgifter:** Låt administratören bestämma vilka fält som måste fyllas i per artikelkategori.
16. **Dubblettgranskning före skapande:** Visa potentiellt liknande artiklar innan en ny sparas, utan att slå ihop dem automatiskt.
17. **Kontroll av döda referenser:** Rapportera artiklar som pekar på borttagna eller inaktiva arbetsställen, hyllor eller kategorier.
18. **Etiketters hållbarhet per miljö:** Ge lokala råd om etikettmaterial utifrån användarens angivna temperatur, fukt och slitage.
19. **Utskriftsmarginalprov:** Skriv ett kalibreringsmönster så användaren kan justera marginaler för sin skrivare.
20. **Föreslagen stickprovskontroll:** Skapa ett slumpmässigt urval av artiklar med redovisad urvalsmetod för en kontroll.
21. **Blind dubbelräkning:** Låt två personer räkna oberoende utan att se varandras antal, och visa skillnaden vid avstämning.
22. **Leveransens synliga skador:** Registrera emballageskador och bilder separat från mottaget saldo innan leveransen godkänns.
23. **Öppet-kärl-status:** Markera att en behållare är öppnad och visa användarens angivna hanteringsdatum utan automatisk kassation.
24. **Export med dataminimering:** Låt användaren välja vilka personuppgifter som utelämnas ur en rapport och förhandsgranska resultatet.
25. **Läsbar utskrift vid färgbrist:** Provvisa rapporter i gråskala och markera information som annars bara skiljs åt med färg.
