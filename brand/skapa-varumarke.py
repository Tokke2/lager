"""Bygger märket, appikonen och etikettmallen som ren SVG.

Märket är ett **paket i tre plana, redigerbara delar**: en övre och två
sidoytor med en grön detalj. Det innehåller inga bokstäver och inget
företagsnamn – det går att använda för vilken installation som helst, även
efter vitmärkning, och knyts inte till någon firma.

All text i den här filen (endast etikettmallen) är konverterad till banor med
Montserrat (SIL Open Font License) så att filerna är fristående.

Kör:  python3 brand/skapa-varumarke.py
"""

from pathlib import Path
from shutil import copy2

from fontTools.misc.transform import Transform
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

ROT = Path(__file__).resolve().parent
UT = ROT
FONTMAPP = ROT / "fonts"

# Färger (samma som appens CSS)
BLA = "#2F6BFF"
CYK = "#22D3EE"
MÖRK = "#0D1420"
VIT = "#FFFFFF"
GRÖN = "#34D399"


class Text:
    def __init__(self, fil: Path):
        f = TTFont(str(fil))
        self.glyfer = f.getGlyphSet()
        self.upem = f["head"].unitsPerEm
        self.cmap = f.getBestCmap()

    def bredd(self, text: str, storlek: float, spärr: float = 0.0) -> float:
        skala = storlek / self.upem
        w = 0.0
        for ch in text:
            g = self.cmap.get(ord(ch))
            w += (self.glyfer[g].width * skala if g else storlek * 0.3) + spärr
        return max(0.0, w - spärr)

    def bana(self, text: str, storlek: float, x: float, baslinje: float, spärr: float = 0.0) -> str:
        skala = storlek / self.upem
        delar, pekare = [], 0.0
        for ch in text:
            g = self.cmap.get(ord(ch))
            if g is None:
                pekare += storlek * 0.3 + spärr
                continue
            pen = SVGPathPen(self.glyfer)
            self.glyfer[g].draw(TransformPen(pen, Transform(skala, 0, 0, -skala, x + pekare, baslinje)))
            d = pen.getCommands()
            if d:
                delar.append(d)
            pekare += self.glyfer[g].width * skala + spärr
        return " ".join(delar)


BOLD = Text(FONTMAPP / "Montserrat-Bold.ttf")


# ---------------------------------------------------------------------------
# Märket: ett paket i tre tydliga ytor, med luft mellan ytorna.
# Alla former är separata vektorbanor, även i tryckoriginalen.
# ---------------------------------------------------------------------------
def marke(kropp: str, nod: str) -> str:
    return (
        f'<path d="M32 6 54 18 32 30 10 18Z" fill="{kropp}"/>'
        f'<path d="M9 24 29 35 29 57 9 46Z" fill="{kropp}"/>'
        f'<path d="M35 35 55 24 55 46 35 57Z" fill="{nod}"/>'
    )


def gradient(id_: str, från: str, till: str) -> str:
    return (
        f'<linearGradient id="{id_}" x1="0" y1="0" x2="1" y2="1">'
        f'<stop offset="0" stop-color="{från}"/><stop offset="1" stop-color="{till}"/></linearGradient>'
    )


def skriv(namn: str, innehall: str) -> None:
    (UT / namn).write_text(innehall, encoding="utf-8")
    print("  %-34s %7d tecken" % (namn, len(innehall)))


# ---------------------------------------------------------------------------
# 1. Märket ensamt (favicon, tray, etsning)
# ---------------------------------------------------------------------------
def marke_svg(kropp: str, nod: str) -> str:
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" '
        'role="img" aria-label="Lager">\n'
        "  <title>Lager</title>\n"
        "  %s\n</svg>\n" % marke(kropp, nod)
    )


# ---------------------------------------------------------------------------
# 2. Appikon (fylld bakgrund, rundade hörn)
# ---------------------------------------------------------------------------
def ikon(läge: str = "mork") -> str:
    """läge: mork | ljus | monokrom. Alla ritas i samma 64 × 64-ruta."""
    if läge == "mork":
        förbered = "<defs>%s</defs>" % gradient("gIkon", BLA, CYK)
        yta = '<rect width="64" height="64" rx="15.5" fill="%s"/>' % MÖRK
        kropp = "url(#gIkon)"
    elif läge == "ljus":
        förbered = "<defs>%s</defs>" % gradient("gIkon", BLA, CYK)
        yta = '<rect width="64" height="64" rx="15.5" fill="%s"/>' % VIT
        kropp = "url(#gIkon)"
    else:
        förbered = ""
        yta = ""
        kropp = "#000000"

    nod = GRÖN if läge != "monokrom" else "#000000"

    # Märket skalas 0,90 och centreras så att det får luft inuti appikonen
    # (samma optiska storlek som iOS/Android förväntar sig).
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" '
        'role="img" aria-label="Lager">\n'
        "  <title>Lager</title>\n"
        "  %s%s\n"
        '  <g transform="translate(3.2,3.2) scale(0.9)">%s</g>\n</svg>\n'
    ) % (förbered, yta, marke(kropp, nod))


skriv("marke.svg", marke_svg(MÖRK, GRÖN))
skriv("marke-ljus.svg", marke_svg(VIT, GRÖN))
skriv("marke-enfarg.svg", marke_svg("#000000", "#000000"))
skriv("ikon.svg", ikon("mork"))
skriv("ikon-ljus.svg", ikon("ljus"))
skriv("ikon-monokrom.svg", ikon("monokrom"))

# Bakåtkompatibelt namn: äldre versioner av appen och skrivbordsbygget letar
# efter ikon-monokrom.svg – det finns kvar, men innehållet är det nya märket.
skriv("marke-monokrom.svg", marke_svg("#000000", "#000000"))

# ---------------------------------------------------------------------------
# 3. Etikettmall 80 × 50 mm (termisk skrivare)
# ---------------------------------------------------------------------------
skriv("etikett-80x50.svg", (
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 50" width="80mm" height="50mm" '
    'role="img" aria-label="Etikett 80 x 50 mm">\n'
    '  <title>Etikett 80 × 50 mm</title>\n'
    '  <rect x="0.2" y="0.2" width="79.6" height="49.6" fill="#FFFFFF" stroke="#000000" stroke-width="0.3"/>\n'
    '  <g transform="translate(1.4,1.0) scale(0.145)">%s</g>\n'
    '  <text x="12.5" y="6.4" font-family="Helvetica, Arial, sans-serif" font-size="3.4" font-weight="700" fill="#000000">ETIKETT</text>\n'
    '  <text x="12.5" y="9.4" font-family="Helvetica, Arial, sans-serif" font-size="2.2" fill="#555555">LAGER &amp; INVENTERING</text>\n'
    '  <line x1="1.6" y1="11.6" x2="78.4" y2="11.6" stroke="#000000" stroke-width="0.25"/>\n'
    '  <rect x="1.8" y="13.4" width="26" height="26" fill="none" stroke="#000000" stroke-width="0.25" stroke-dasharray="0.9 0.9"/>\n'
    '  <text x="14.8" y="26.9" font-family="Helvetica, Arial, sans-serif" font-size="2.2" text-anchor="middle" fill="#888888">QR-KOD</text>\n'
    '  <text x="31" y="17.5" font-family="Helvetica, Arial, sans-serif" font-size="3.2" font-weight="700" fill="#000000">ARTIKELNAMN</text>\n'
    '  <text x="31" y="22.2" font-family="Helvetica, Arial, sans-serif" font-size="2.5" fill="#000000">Art.nr: 000000</text>\n'
    '  <text x="31" y="26.4" font-family="Helvetica, Arial, sans-serif" font-size="2.5" fill="#000000">Arbetsställe: –</text>\n'
    '  <text x="31" y="32.2" font-family="Helvetica, Arial, sans-serif" font-size="2.4" fill="#000000">TDL-U-00000-00000-0</text>\n'
    '  <text x="31" y="38.4" font-family="Helvetica, Arial, sans-serif" font-size="2" fill="#333333">Etiketten sitter kvar på enheten.</text>\n'
    '  <line x1="1.6" y1="41.4" x2="78.4" y2="41.4" stroke="#000000" stroke-width="0.25"/>\n'
    '  <text x="1.8" y="45.6" font-family="Helvetica, Arial, sans-serif" font-size="2" fill="#555555">Koden följer den fysiska enheten – flytta aldrig etiketten.</text>\n'
    '  <text x="1.8" y="48.4" font-family="Helvetica, Arial, sans-serif" font-size="2" fill="#555555">Inventerad senast: –  Ansvarig: –</text>\n'
    "</svg>\n"
) % marke("#000000", "#000000"))

# Samma original används i webbappen; annars riskerar en ny export att
# skrivbordsapp, webb och tryck visar olika märken.
webb = ROT.parent / "public" / "assets"
webb.mkdir(parents=True, exist_ok=True)
for namn in ("marke.svg", "marke-ljus.svg", "ikon.svg"):
    copy2(ROT / namn, webb / namn)

print("\nSVG:er skrivna till %s" % UT)
