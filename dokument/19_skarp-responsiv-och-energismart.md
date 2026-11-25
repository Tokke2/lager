# Skärm, skärpa och resurser

Gränssnittet använder systemtypsnitt, SVG-ikoner och riktiga skärmstorlekar i
stället för att skala ett helt gränssnitt som en bild. Texten får använda
webbläsarens normala kantutjämning, och huvud och bottenmeny har opak
bakgrund utan bakgrundsoskärpa. Hjälptexter och numeriska översikter har mer
läsbara storlekar; tabeller anpassas även när fönstret krymps eller enheten
roteras. Utan JavaScript kan breda tabeller fortfarande scrollas.

Signaturytan använder skärmens pixeltäthet (högst tre gånger för att begränsa
minnet). När ytan ändrar storlek kopieras en redan ritad signatur; knappen
Rensa fungerar som tidigare. En högupplöst skärm ger skarpare ritning, men
appen kan inte öka en fysisk skärms DPI eller återskapa detaljer i en lågupplöst
originalbild. QR-koder och ikoner är vektorbaserade. Utskriftsinställningar
och bildoriginal avgör utskriftens faktiska skärpa.

Kamerans arbetsyta frigörs när kameran stoppas. Gamla EAN-svar från en tidigare
kamerastart ignoreras. Energisparläget förblir aktivt om antingen webbläsaren
begär databesparing eller batteriet är lågt och inte laddas. Webbläsarstöd för
batteriinformation, EAN-kamera och databesparing varierar. Ingen generell
förbättring av batteritid eller hastighet är uppmätt; resultaten beror på enhet,
nät och datamängd.

Testerna täcker bland annat startsida och utlåning i Chromium vid 320–2560 px,
200 % förstoring, DPI 1–2, byte från dator- till mobilbredd och signaturens
storleksbyte. Det är inte ett test av varje vy, skrivare eller fysisk enhet.
Serverns integrationstester körs på temporär data och det separata
`tests/tillgangstest.php` provar att filversion och förhandsgranskningssession
kan finnas samtidigt i en tillgångs-URL. `node tests/klienttest.mjs` testar
signatur och signalerna för energisparläge utan externa npm-paket.
