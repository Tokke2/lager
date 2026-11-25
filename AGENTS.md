# Arbetsregler för Lager

Dessa regler gäller Lager: webbappen i `app/` och `public/`, PWA:n och
skrivbordsappen i `desktop/`. Läs dem tillsammans med sessionens aktuella
instruktioner. Projekt- eller kravnamn från andra appar gäller inte automatiskt
här; hitta inte på deras innebörd och ändra inte en annan checkout.

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
- Skydda Lager-specifik dataintegritet: saldo per arbetsställe, QR-enheters
  identitet och status, öppna lån, inventeringar och spårbar historik. Skrivande
  ändringar ska valideras på servern och använda appens lås och journaler där
  de behövs, utan delvis genomförda lagertransaktioner.
- Bevara den privata lagringen, säkerhetskopiornas återläsbarhet, behörighets-
  kontroller, sessionsskydd och CSRF. Skapa inte testdata i en driftinstallation.
  Kameran ska fortfarande ha manuell reservväg.
- `REQ-K4` har ingen känd definition i Lager. Koppla inte etiketten till ett
  annat krav och påstå inte att den är uppfylld; be om lydelsen om den blir
  relevant. Lager har ett separat historiskt kravunderlag i `03_kravmatris.md`,
  vars autentiseringsavsnitt är inaktuellt – namn och PIN-regeln ovan gäller.

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
- Separat profilskapande under inställningar är ett öppet önskemål. Lager har
  befintliga skrivarprofiler i `app/Support/Skrivare.php`, men användaren har
  inte specificerat om det är dessa som avses; fråga innan nya profiler byggs.
- Lager har hyllrutor i lagerkartan och lokalplanen, men ingen identifierad
  projektskapare från TTK-versionen. Anta inte att någon av dessa är samma
  funktion som det efterfrågade rutvalet; be om detaljer innan det byggs.

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
  klartecken. För Lager finns `tests/integrationstest.php`,
  `tests/tillgangstest.php` och `tests/klienttest.mjs`; välj tester efter
  ändringen och använd separat temporär lagring. Ändra inte testernas
  skyddsvillkor i källfilen för att passa en testmiljö.
- Testa gränssnitt vid relevanta skärmstorlekar och använd realistiska fel- och
  säkerhetsfall när ändringen berör dessa. Skydda riktig driftdata.
- Redovisa vad som testats, eventuella uteblivna tester och begränsningar. Säg
  inte att något är färdigt, säkert, uppdaterat eller snabbare utan stöd för det.
- Vid push: verifiera att releasens commit och Latest-status matchar grenens
  HEAD. Vid fel, rapportera det i stället för att tyst påstå framgång.
