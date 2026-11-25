# Arbetsregler för Lager

Dessa regler gäller kommande arbete i detta repository. Läs dem tillsammans med
sessionens aktuella instruktioner och kontrollera aktuellt grenläge innan arbete.
När en regel hänvisar till ett annat projekt eller ett krav som inte finns här:
hitta inte på innehållet och ändra inte en annan checkout – be användaren om rätt
fil eller förklaring.

## Hårda regler: gren, merge och release

- Arbeta endast på `arena/2bbb9877-lager`. Byt aldrig till, skapa aldrig och
  pusha aldrig någon annan gren; arbeta aldrig mot `main`, särskilt inte vid
  appuppdateringar. Den aktuella grenen får inte byta namn.
- Det ska bara finnas en arbetsgren. Kontrollera lokala och fjärranslutna
  grenar samt unikt arbete innan någon gren tas bort. Skapa inte nya grenar.
- **Merga aldrig** grenar eller pull requests. Avstå också från rebase- eller
  squash-merge. Om en begäran förutsätter merge: stoppa och fråga användaren.
- Kontrollera aktuell gren och status före ändringar, commit, push, release och
  appuppdatering. Om grenen inte är den tillåtna: stoppa, byt inte själv.
- Varje kodändring som pushas ska ha en release för exakt samma commit som den
  tillåtna grenens HEAD, markerad **Latest**. Kontrollera resultatet på GitHub
  och rapportera fel. `.github/workflows/release.yml` automatiserar publiceringen;
  en misslyckad eller avstängd körning uppfyller inte regeln.
- Uppdateringskontrollen ska jämföra installerade programfiler med den senaste
  godkända releasen på den tillåtna grenen. Kunddata i `storage/` ska inte
  jämföras eller skrivas över. Jämförelse av Git-hashar är inte en signatur.

## Säkerhet, data och kompatibilitet

- Alla roller använder endast namn och PIN för inloggning. Återinför aldrig
  separata lösenord, lösenordsinställning, återställning eller rotation.
- Om en framtida uppdateringsinstallerare lyckas och framgång har verifierats,
  ta bort endast den tillfälliga backup som skapades för den uppdateringen.
  Bevara ordinarie manuella och schemalagda backuper. Vid fel eller osäkert
  resultat ska återställningsunderlaget inte raderas. Det finns ännu ingen
  automatisk installerare; filkontrollen skapar eller raderar inga backuper.
- Rensa roterade tekniska loggar efter sju dygn enligt
  `dokument/21_varumarke-uppdatering-loggar.md`. Radera inte inventeringsarkiv,
  revisionshistorik eller aktiva loggar med denna regel.
- Inga tysta beroende- eller gränssnittsbrott: bevara befintliga API:er,
  användarflöden, filformat, lagrad data, roller och befintliga integrationer.
  Om en ändring måste bryta kompatibilitet, redovisa den uttryckligen, fråga
  vid behov och tillhandahåll en säker migrations- och återställningsväg.
- `REQ-K4` ska respekteras när dess innehåll är känt. Definitionen finns inte
  i denna checkout; be om originalkravet eller korrekt projekt innan du tolkar
  eller implementerar det. Anta inte att det redan är uppfyllt.

## Gränssnitt, prestanda och varumärke

- Anpassa layout, kolumner, text, bilder och ikoner till olika skärmstorlekar,
  zoomnivåer och hög DPI. Prioritera läsbarhet, god kontrast, skärpa och att
  allt väsentligt är synligt utan onödig scroll. Testa relevanta storlekar.
- Där det är relevant för en skrivbordsapp: anpassa startfönstrets höjd och
  bredd till skärmen utan att innehåll hamnar utanför dess arbetsyta.
- Förbättra minneshantering, hastighet och energianvändning utan att kompromissa
  med korrekthet eller säkerhet. Påstå inte uppmätta förbättringar utan mätning.
- Håll standardlogotyp och ikoner konsekventa i appen. Bevara redigerbara
  vektororiginal för tryck och respektera kundens egen vitmärkning.
- Önskemålen om profilskapande under inställningar och val av ruta i en
  projektskapare från en tidigare TTK-version ska inte antas vara genomförda
  eller höra till Lager om relevant funktion och originalkrav saknas. Be om
  rätt projekt och detaljer innan arbete som beror på dem.

## Förslag och uppföljning

- Efter tio chattmeddelanden sedan föregående lista eller överenskommen start:
  presentera tio nya förslag. Återanvänd inte tidigare förslag; granska tidigare
  listor i `dokument/` och tillgänglig samtalshistorik. Underbygg påståenden om
  användarbehov och påstå inte att konkurrenter saknar något utan belägg.
- En ny chatt kan sakna räknaren och tidigare listor. Om antalet eller tidigare
  förslag inte går att fastställa, säg det och be användaren hjälpa till; låtsas
  inte ha minne av sådant som inte finns. Skicka inte oombedda meddelanden.
- Tidigare efterfrågades även 25 nya förslag; befintliga underlag finns i
  `dokument/18_25-ideer-efterfragade-behov.md`. Se dem som idéer och belagda
  behov, inte som bevis för att konkurrenter saknar varje funktion.

## Kontroll före klart

- Granska diff och kör relevanta automatiska tester samt syntaxkontroller före
  klartecken. Testa gränssnitt vid relevanta skärmstorlekar och använd realistiska
  fel- och säkerhetsfall när ändringen berör dessa. Skydda riktig driftdata.
- Redovisa vad som testats, eventuella uteblivna tester och begränsningar. Säg
  inte att något är färdigt, säkert, uppdaterat eller snabbare utan stöd för det.
- Vid push: verifiera att releasens commit och Latest-status matchar grenens
  HEAD. Vid fel, rapportera det i stället för att tyst påstå framgång.
