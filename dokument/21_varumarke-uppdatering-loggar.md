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
Kontrollera GitHub-releaser** är en manuell kontroll, inte en installerare.
Den frågar endast GitHubs publika API över TLS och visar en nyare stabil
release bara om dess tagg ligger i `arena/2bbb9877-lager`-grenens historik.
Den tittar på högst 20 senaste releaserna och jämför högst fem kandidater.
Versionsnumret måste hållas aktuellt i `app/bootstrap.php` vid varje release.
API-begränsning, avsaknad av utgående HTTPS eller TLS-problem visas som fel,
inte som en försäkran om att installationen är uppdaterad.

Kontrollen verifierar **inte** kryptografiskt signerade filer och hämtar eller
installerar ingen kod. En release ska granskas och testas av driftansvarig;
behåll `storage/` oförändrat och ta en separat verifierad backup före manuell
installation. Använd bara releaser från den tillåtna grenen. En säker inbyggd
installerare med avbrottssäker återställning och signerade paket saknas ännu.
Därför skapas ingen tillfällig uppdateringsbackup av kontrollen och den tar
inte bort någon backup. Ordinarie drifts- och manuella backuper raderas aldrig
av denna funktion.

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
