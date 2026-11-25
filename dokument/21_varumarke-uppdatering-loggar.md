# Varumärke, uppdateringskontroll och loggar

## Nytt märke och ikoner

Standardmärket är ett paket uppbyggt av tre separata, redigerbara SVG-banor.
Samma geometri används för meny, inloggning, publik artikelsida, PWA, favicon,
tryck och skrivbordsapp. Kundens uppladdade egen logotyp respekteras fortsatt.
Appens navigationsikoner är vektorer och följer textfärgen. Under **Admin →
Varumärke → Tryckoriginal** finns nedladdning med redigerbara SVG-original,
enfärgsvariant, tryckark och PNG/ICO. Originalen kan öppnas i Inkscape,
Illustrator och liknande; prova färg och små storlekar med tryckeriet.

## Uppdatering

Det fanns ingen installerad webbuppdaterare i projektet. **Systemstatus →
Jämför med senaste releasen** är en manuell filkontroll, inte en installerare.
GitHub-releasen måste vara markerad **Latest**, ha taggen `build-<commit>` och
peka på exakt samma commit som `arena/2bbb9877-lager` just nu. Om releasen
saknas, ligger efter eller pekar på en annan gren avbryts kontrollen med fel.

För varje versionshanterad programfil i `app/`, `public/`, `bin/`, `brand/`
och `index.php` jämförs GitHubs Git-blobhash med filens innehåll på servern.
Ändrade, saknade och otillgängliga filer visas. Extra lokala filer och
filrättigheter kontrolleras inte; `storage/` läses eller ändras inte. GitHub
använder Git SHA-1 för blobbarna: detta visar filskillnader men är **inte** en
kryptografisk signatur eller garanti för paketets ursprung. Kontroll kräver
utgående HTTPS, TLS och tillgänglig GitHub-API-kvot. Fel redovisas som fel,
inte som att allt stämmer.

Vid varje push till den tillåtna grenen ska arbetsflödet
`.github/workflows/release.yml` skapa en release för exakt den committen,
med `lager.zip` och SHA-256-kontrollsumma, och markera den som **Latest**.
Paketet byggs med `git archive` från committen och innehåller ingen `storage/`
eller annan oversionshanterad driftdata. Arbetsflödet kräver GitHub Actions
med skrivbehörighet till repositoryts innehåll. Om körningen misslyckas eller
är avstängd måste ansvarig rätta till och köra om den; då är inte regeln
uppfylld förrän releasen kontrollerats. Gamla releaser tas inte bort.

Kontrollen hämtar eller installerar ingen kod. En release ska granskas och
testas av driftansvarig; behåll `storage/` och ta en separat verifierad backup
före manuell installation. En säker inbyggd installerare med avbrottssäker
återställning och signerade paket saknas ännu. Därför skapas ingen tillfällig
uppdateringsbackup och kontrollen tar inte bort någon backup. Ordinarie
backuper raderas aldrig av denna funktion.

## Tekniska loggar

Roterade filer direkt i `storage/logg/` med namn `namn.log.1`,
`namn.log.20261101`, `namn.log-20261101` och motsvarande `.gz` tas bort när
filens ändringstid är minst sju dygn gammal. Aktiva `.log`, okända namn,
symboliska länkar och undermappar lämnas. Detta omfattar inte den spårbara
historiken eller inventeringsarkivet. Städningen försöks högst var tolfte timme
när webbappen används. För mer regelbunden städning även när appen är inaktiv,
schemalägg t.ex. dagligen:

```
0 3 * * * /usr/bin/php /sokvag/till/lager/bin/rensa-tekniska-loggar.php
```

Cron eller logrotate måste självt rotera aktiva loggar om sådana produceras;
appen tar inte bort eller roterar aktiva loggar. Kör schemaläggningen även
på webbhotell där ingen besöker appen under längre tid.
