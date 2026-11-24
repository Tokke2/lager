#!/usr/bin/env bash
# =============================================================================
#  inventera-lagerfiler.sh
#  Read-only inventering av en arbetskopia inför bygget av Tadega Lager.
#
#  Skriptet LÄSER det angivna trädet. Det skriver bara den rapportfil som
#  anges med --ut (default: <rot>/../lager-inventering-rapport.md).
#  Inget i det granskade trädet ändras, flyttas eller raderas.
#
#  Användning:
#     ./inventera-lagerfiler.sh <rotmapp> [--ut rapport.md] [--max N]
#                                [--php-lint | --ingen-lint] [--help]
#
#  Krav: bash 4+, find, sha256sum (eller shasum), grep.
# =============================================================================
set -Eeuo pipefail
export LC_ALL=C

PROG="$(basename "$0")"
ROT=""
UT=""
MAX_TRAFFAR=5
LINT="auto"

anvandning() {
  sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

fel() { printf 'FEL: %s\n' "$*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) anvandning ;;
    --ut) [[ $# -ge 2 ]] || fel "--ut kräver en sökväg"; UT="$2"; shift 2 ;;
    --max) [[ $# -ge 2 && "$2" =~ ^[0-9]+$ ]] || fel "--max kräver ett tal"; MAX_TRAFFAR="$2"; shift 2 ;;
    --php-lint) LINT="ja"; shift ;;
    --ingen-lint) LINT="nej"; shift ;;
    --) shift; break ;;
    -*) fel "okänd flagga: $1 (se --help)" ;;
    *) [[ -z "$ROT" ]] || fel "flera rotmappar angivna"; ROT="$1"; shift ;;
  esac
done

[[ -n "$ROT" ]] || { anvandning; }
[[ -d "$ROT" ]] || fel "rotmappen finns inte: $ROT"
ROT="$(cd "$ROT" && pwd -P)"
[[ -n "$UT" ]] || UT="$(dirname "$ROT")/lager-inventering-rapport.md"
UT_DIR="$(dirname "$UT")"
[[ -d "$UT_DIR" ]] || mkdir -p "$UT_DIR"

TID="$(date -u '+%Y-%m-%d %H:%M UTC')"
HOST="$(uname -srm 2>/dev/null || echo okänd)"

if command -v sha256sum >/dev/null 2>&1; then
  hash_fil() { sha256sum "$1" 2>/dev/null | awk '{print $1}'; }
elif command -v shasum >/dev/null 2>&1; then
  hash_fil() { shasum -a 256 "$1" 2>/dev/null | awk '{print $1}'; }
else
  hash_fil() { echo "n/a"; }
fi

# ---------------------------------------------------------------------------
# Hjälp: lista filer säkert (hanterar mellanslag) och relativt rot
# ---------------------------------------------------------------------------
rel() { printf '%s' "${1#"$ROT"/}"; }

finns() { [[ -e "$ROT/$1" ]] && echo "ja" || echo "**nej**"; }
filstorlek() { stat -c %s "$1" 2>/dev/null || stat -f %z "$1" 2>/dev/null || echo "?"; }
filmtid() {
  if stat -c %y "$1" >/dev/null 2>&1; then stat -c %y "$1" | cut -d. -f1
  else stat -f '%Sm' -t '%Y-%m-%d %H:%M:%S' "$1" 2>/dev/null || echo "?"; fi
}

PRUNE=( -name .git -o -name vendor -o -name node_modules -o -name '.arena' -o -path '*/storage/backup' -o -path '*/uploads' )

# ---------------------------------------------------------------------------
# 1. Kandidatfiler: allt som kan höra till lager, plus alla JSON
# ---------------------------------------------------------------------------
KANDIDATER=()
while IFS= read -r -d '' f; do KANDIDATER+=("$f"); done < <(
  find "$ROT" \( "${PRUNE[@]}" \) -prune -o -type f \( \
      \( -iname '*lager*' -o -iname '*stock*' -o -iname '*saldo*' \
         -o -iname '*artikel*' -o -iname '*inventer*' -o -iname '*qr*' \) \
      -o -iname '*.json' \
    \) -print0 2>/dev/null | sort -z
)

# ---------------------------------------------------------------------------
# 2. Nyckelordsträffar i PHP-filer (config/data utesluts)
# ---------------------------------------------------------------------------
NYCKELORD='update_stock|lager|saldo|inventer|artikel|arbetsställe|arbetsstalle|qr_|qrkod|backup'

TRAFFAR=""
while IFS= read -r -d '' f; do
  case "$f" in
    */config.php|*/settings.php|*/.env*|*.min.js) continue ;;
  esac
  # grep -a krävs inte; binärfiler filtreras bort via -I
  resultat="$(grep -nIaE "$NYCKELORD" "$f" 2>/dev/null | head -n "$MAX_TRAFFAR" || true)"
  if [[ -n "$resultat" ]]; then
    TRAFFAR+="$(printf '\n### %s\n\n```\n%s\n```\n\n' "$(rel "$f")" "$resultat")"
  fi
done < <(find "$ROT" \( "${PRUNE[@]}" \) -prune -o -type f -name '*.php' -print0 2>/dev/null | sort -z)

# ---------------------------------------------------------------------------
# 3. php -l
# ---------------------------------------------------------------------------
LINT_SEKTION=""
if [[ "$LINT" == "auto" ]]; then
  command -v php >/dev/null 2>&1 && LINT="ja" || LINT="nej"
fi
if [[ "$LINT" == "ja" ]] && command -v php >/dev/null 2>&1; then
  medan=""
  felantal=0
  while IFS= read -r -d '' f; do
    if ! out="$(php -l "$f" 2>&1)"; then
      felantal=$((felantal+1))
      medan+="- ❌ \`$(rel "$f")\` – ${out//$'\n'/ }"$'\n'
    fi
  done < <(find "$ROT" \( "${PRUNE[@]}" \) -prune -o -type f -name '*.php' -print0 2>/dev/null | sort -z)
  if [[ -n "$medan" ]]; then
    LINT_SEKTION="### Syntaxfel ($felantal)"$'\n\n'"$medan"
  else
    LINT_SEKTION="Inga syntaxfel hittades."$'\n'
  fi
else
  LINT_SEKTION="_Hoppad över (php saknas i PATH eller --ingen-lint)._"$'\n'
fi

# ---------------------------------------------------------------------------
# 4. Bygg rapport
# ---------------------------------------------------------------------------
{
  echo "# Lagerinventering – $(basename "$ROT")"
  echo
  echo "**Tid:** $TID  "
  echo "**Rot:** \`$ROT\`  "
  echo "**Värd:** $HOST  "
  echo "**Filer i kandidatlistan:** ${#KANDIDATER[@]}  "
  echo
  echo "> Rapporten är read-only. Inget i det granskade trädet har ändrats."
  echo

  echo "## 1. Nyckelord: förekomst i kod"
  echo
  if [[ -z "$TRAFFAR" ]]; then
    echo "Inga träffar på \`$NYCKELORD\` i PHP-filerna."
  else
    echo "$TRAFFAR"
  fi
  echo

  echo "## 2. Router och ingångsfiler"
  echo
  echo "| Fil | Finns | Nämner lager/stock |"
  echo "|---|---|---|"
  for kandidat in index.php router.php public/index.php app/router.php; do
    [[ -e "$ROT/$kandidat" ]] || { echo "| \`$kandidat\` | **nej** | – |"; continue; }
    if grep -qiaE 'lager|stock|inventer' "$ROT/$kandidat" 2>/dev/null; then n="ja"; else n="nej"; fi
    echo "| \`$kandidat\` | ja | $n |"
  done
  echo

  echo "## 3. JSON-filer"
  echo
  echo "| Fil | Storlek (B) | Poster (approx) | SHA-256 |"
  echo "|---|---|---|---|"
  tomma=0
  while IFS= read -r -d '' f; do
    storlek="$(filstorlek "$f")"
    poster="–"
    if command -v python3 >/dev/null 2>&1; then
      poster="$(python3 - "$f" <<'PY' 2>/dev/null || echo '?'
import json,sys,os
fh_size = os.path.getsize(sys.argv[1])
try:
    with open(sys.argv[1], 'r', encoding='utf-8') as fh:
        d = json.load(fh)
    if isinstance(d, list): print(len(d))
    elif isinstance(d, dict): print(len(d), "nycklar")
    else: print("skalär")
except Exception:
    print("tom fil" if fh_size == 0 else "ogiltig JSON")
PY
)"
    fi
    [[ "$storlek" =~ ^[0-9]+$ ]] && (( storlek < 16 )) && tomma=$((tomma+1))
    echo "| \`$(rel "$f")\` | $storlek | $poster | \`$(hash_fil "$f")\` |"
  done < <(find "$ROT" \( "${PRUNE[@]}" \) -prune -o -type f -name '*.json' -print0 2>/dev/null | sort -z)
  echo
  echo "Misstänkt tomma JSON-filer (<16 B): **$tomma**. En tom fil betyder inte att produktionsdata saknas."
  echo

  echo "## 4. Kandidatfiler i detalj"
  echo
  if (( ${#KANDIDATER[@]} == 0 )); then
    echo "Inga kandidatfiler hittades."
  else
    echo "| Fil | Typ | Storlek | Mtime | SHA-256 |"
    echo "|---|---|---|---|---|"
    for f in "${KANDIDATER[@]}"; do
      typ="${f##*.}"
      echo "| \`$(rel "$f")\` | $typ | $(filstorlek "$f") | $(filmtid "$f") | \`$(hash_fil "$f")\` |"
    done
  fi
  echo

  echo "## 5. Förväntade sökvägar"
  echo
  echo "| Sökväg | Finns |"
  echo "|---|---|"
  for p in lager lager-app apps/lager app/lager includes/functions.php \
           storage storage/data data \
           .htaccess public/.htaccess composer.json; do
    echo "| \`$p\` | $(finns "$p") |"
  done
  echo

  echo "## 6. PHP-syntaxkontroll"
  echo
  echo "$LINT_SEKTION"
  echo

  echo "## 7. Nästa steg"
  echo
  echo "Skicka den här filen. Den innehåller inga hemligheter – men kontrollera innan"
  echo "utskick att inga känsliga sökvägar eller kontextrader följt med i avsnitt 1."
  echo
  echo "**Påminnelse:** inga lagerdata flyttas eller skrivs om förrän de är säkerhetskopierade"
  echo "och migreringen verifierats."
} > "$UT"

printf 'Klart. Rapport: %s\n' "$UT"
printf 'Rader: %s\n' "$(wc -l < "$UT" | tr -d ' ')"
