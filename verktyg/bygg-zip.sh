#!/usr/bin/env bash
#
# bygg-zip.sh – paketerar Tadega Lager för nedladdning.
#
# Körs efter varje ändring så att zipen alltid är aktuell:
#
#     ./verktyg/bygg-zip.sh
#
# Resultatet hamnar i workspace-roten som Tadega-Lager.zip.
#
# Det som INTE följer med:
#   – storage/backup/   (gamla säkerhetskopior, 2,8 MB – byggs om av bin/backup.php)
#   – storage/sessions/ (inloggningar, skapas om vid inloggning)
#   – storage/locks/, storage/tmp/  (tillfälliga filer)
#   – desktop/node_modules/ och desktop/release/  (byggartefakter)
#   – .git, __pycache__, .DS_Store
#
# Det som MÅSTE följa med:
#   – storage/data/          (artiklar, saldon, konton, historik)
#   – storage/artikelqr.json (QR-signeringsnyckeln – utan den dör utskrivna lappar)
#   – storage/bilder/        (uppladdade artikelbilder)
#
set -euo pipefail

PROJEKT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAL="$(dirname "$PROJEKT")/Tadega-Lager.zip"

cd "$PROJEKT"

echo "Packar $PROJEKT"
echo "  → $MAL"

# Ta bort gamla zipen först – annars packas den in i sig själv
rm -f "$MAL"

# Tillfällig katalog utanför projektet: rensa bort det som inte ska med.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/Tadega-Lager"

# tar finns på alla system (rsync gör det inte alltid – t.ex. i en container)
echo "  rensar bort backup, sessioner och lås ur kopian …"
tar -c -C "$PROJEKT" \
    --exclude='storage/data/*.json' \
    --exclude='storage/artikelqr.json' \
    --exclude='storage/varumarke.json' \
    --exclude='storage/bilder/*' \
    --exclude='storage/arkiv/*' \
    --exclude='storage/backup/*.zip' \
    --exclude='storage/sessions/*' \
    --exclude='storage/locks/*' \
    --exclude='storage/tmp/*' \
    --exclude='storage/logg/*.log' \
    --exclude='desktop/node_modules' \
    --exclude='desktop/release' \
    --exclude='.git' \
    --exclude='__pycache__' \
    --exclude='.DS_Store' \
    . | tar -x -C "$TMP/Tadega-Lager"

# Tomma kataloger som appen förväntar sig ska finnas kvar
mkdir -p "$TMP/Tadega-Lager/storage/backup" "$TMP/Tadega-Lager/storage/sessions" \
         "$TMP/Tadega-Lager/storage/locks" "$TMP/Tadega-Lager/storage/tmp" \
         "$TMP/Tadega-Lager/storage/logg" "$TMP/Tadega-Lager/storage/journal" \
         "$TMP/Tadega-Lager/storage/data"

# Releasen är en REN utgångspunkt: inga konton, ingen historik, inga artiklar,
# ingen demodata och inget kundnamn. Första besökaren möts av installationen
# och måste själv välja PIN – annars vore demokontona en bakdörr i varje
# nedladdad kopia (Anna Admin/4822 gick att logga in med direkt).
cat > "$TMP/Tadega-Lager/storage/README.txt" <<'FORKLARING'
Den här mappen skapas av appen – rör den inte i onödan.

Innehållet (artiklar, konton, historik) skrivs när du installerar appen
och när du använder den. Allt ligger utanför webbroten och kan säkerhets-
kopieras med  bin/backup.php.

Tom?  Då är appen inte installerad än. Öppna appens adress i webbläsaren
      och följ guiden – där väljer du namn och PIN-kod.
FORKLARING

# storage/ får inte nås via webben även om .htaccess skulle saknas
cat > "$TMP/Tadega-Lager/storage/.htaccess" <<'HTACCESS'
# Neka all direktåtkomst till datafilerna.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS


cd "$TMP"
zip -rq "$MAL" Tadega-Lager

STORLEK="$(du -h "$MAL" | cut -f1)"
ANTAL="$(unzip -l "$MAL" | awk 'END {print $2}')"

echo
echo "Klart."
echo "  Storlek: $STORLEK"
echo "  Filer:   $ANTAL"
echo
echo "Zipen ligger i workspace-roten och kan laddas ner direkt."
