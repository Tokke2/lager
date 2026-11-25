# Lagerkarta, EAN och utlåning

## Hitta en artikel

Välj **Visa på lagerkartan** på artikeln eller välj artikeln på lagerkartan. Rätt zon öppnas och rad och fack markeras i både 2D- och 3D-vyn. Saknas giltig lagerplats visas ett meddelande. Lagerplatsen beskriver artikelns hylla, inte saldo på ett visst arbetsställe.

## EAN-streckkoder

Ange valfri EAN-8 eller EAN-13 på artikeln, inklusive eventuella inledande nollor. Kontrollsiffran kontrolleras och en EAN får bara tillhöra en artikel. På **Skanna** kan koden skrivas eller läsas av med en streckkodsläsare. Kameraavläsning av EAN kräver en webbläsare med stöd för BarcodeDetector och EAN-format; annars används manuell inmatning. QR-avläsning fungerar som tidigare. Skanning öppnar sökresultatet och ändrar inte lagersaldot.

## Låna ut och återlämna

Öppna **Utlåning**, välj artikel och arbetsställe, ange låntagare, antal och sista återlämningsdatum. För QR-spårade artiklar anges de individuella enhetskoderna istället för antal. Verktygstavlor stöds inte i detta flöde. Samma tvåpersonsregel som för uttag gäller; en annan kollega måste vid behov bekräfta med namn och PIN. PIN-koden sparas inte.

Utlånat antal är inte tillgängligt saldo och QR-enheter markeras utlånade. Pågående utlåningar visas med förfallodatum och på startsidan visas en påminnelse när de ska tillbaka. Återlämna hela utlåningen med knappen i listan; delåterlämning stöds inte. Den förs tillbaka till det ursprungliga arbetsstället och händelserna sparas i historiken. En pågående utlåning hindrar att artikeln eller arbetsstället stängs. Avsluta en pågående inventering på arbetsstället före utlåning eller återlämning.

Utlåningsposterna sparas i artikelfilen och QR-status i enhetsfilen, och följer med vanliga säkerhetskopior. Återläsning av en äldre backup återställer data till backupens tidpunkt och kan därmed rulla tillbaka senare utlåningar och återlämningar. Jämför alltid med aktuella utlåningar och fysisk utrustning före återläsning och registrera händelser som tillkommit efter backupen på nytt.
