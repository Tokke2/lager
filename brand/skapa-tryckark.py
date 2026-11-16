"""Bygger brand/tryck/marke-tryckark.svg – färdig leveransfil för tryckeri.

Arket (A3 liggande) visar märket i alla varianter med mått, färgprover och de
regler tryckeriet behöver. Märket innehåller **inga bokstäver och inget
företagsnamn** – det är fristående och kan användas för vilken installation
som helst, även efter vitmärkning.

All text på arket är banor (Montserrat, SIL OFL) och måtten anges i millimeter,
så filen kan öppnas direkt i Illustrator, Inkscape eller Affinity utan att
något typsnitt saknas.

Kör: python3 brand/skapa-tryckark.py   (kör skapa-varumarke.py först)
"""

import re
from pathlib import Path

from fontTools.misc.transform import Transform
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

ROT = Path(__file__).resolve().parent
UT = ROT / "tryck"
UT.mkdir(exist_ok=True)
FONTMAPP = ROT / "fonts"

BLA = "#2F6BFF"
CYK = "#22D3EE"
MÖRK = "#0D1420"
VIT = "#FFFFFF"
GRÖN = "#34D399"
GRÅ = "#6B7889"
LINJE = "#D8DEE7"

ARK_B, ARK_H = 420.0, 297.0


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
MED = Text(FONTMAPP / "Montserrat-Medium.ttf")


# --------------------------------------------------------------------- hjälpare
def las(namn: str) -> str:
    return (ROT / namn).read_text(encoding="utf-8")


def innehall(namn: str) -> str:
    kropp = re.sub(r"^.*?<svg[^>]*>", "", las(namn), flags=re.S)
    kropp = re.sub(r"</svg>\s*$", "", kropp, flags=re.S)
    return re.sub(r"<title>.*?</title>", "", kropp, flags=re.S).strip()


def mat(namn: str):
    m = re.search(r'viewBox="0 0 ([\d.]+) ([\d.]+)"', las(namn))
    return float(m.group(1)), float(m.group(2))


def plats(namn: str, x: float, y: float, bredd_mm=None, hojd_mm=None):
    b0, h0 = mat(namn)
    k = (bredd_mm / b0) if bredd_mm is not None else (hojd_mm / h0)
    return ('<g transform="translate(%.2f,%.2f) scale(%.5f)">%s</g>' % (x, y, k, innehall(namn)),
            b0 * k, h0 * k)


def rubrik(x: float, y: float, text: str, storlek: float = 3.2, farg: str = MÖRK) -> str:
    return '<path d="%s" fill="%s"/>' % (BOLD.bana(text, storlek, x, y, storlek * 0.06), farg)


def brodtext(x: float, y: float, text: str, storlek: float = 2.4, farg: str = GRÅ) -> str:
    return '<path d="%s" fill="%s"/>' % (MED.bana(text, storlek, x, y), farg)


def linje(x1, y1, x2, y2, farg=LINJE, bredd=0.25) -> str:
    return ('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="%.2f"/>'
            % (x1, y1, x2, y2, farg, bredd))


d = []
d.append('<rect width="%.1f" height="%.1f" fill="#FFFFFF"/>' % (ARK_B, ARK_H))

# ------------------------------------------------------------------ rubrik
d.append('<path d="%s" fill="%s"/>' % (BOLD.bana("MÄRKET", 16, 18, 30, 2.0), MÖRK))
d.append(brodtext(18, 39, "Fristående märke för lager och inventering. Inga bokstäver, inget företagsnamn, ingen firma.", 3.2, MÖRK))
d.append(brodtext(18, 45, "All text på arket är banor (Montserrat, SIL OFL). Måtten är i millimeter i naturlig storlek.", 2.5, GRÅ))
d.append(linje(18, 50, 402, 50))

# ------------------------------------------------------------------ 1 mörk botten
d.append(rubrik(18, 62, "1  Mörk botten – app, skärm och mörka profiler", 3.4))
d.append('<rect x="18" y="67" width="176" height="70" rx="3" fill="%s"/>' % MÖRK)
g, b, h = plats("ikon.svg", 30, 78, bredd_mm=48)
d.append(g)
g2, b2, h2 = plats("marke-ljus.svg", 96, 84, bredd_mm=32)
d.append(g2)
g3, b3, h3 = plats("marke-ljus.svg", 150, 90, bredd_mm=20)
d.append(g3)
d.append(brodtext(18, 144, "ikon.svg (mörk)  ·  marke-ljus.svg i 32 och 20 mm", 2.3))

# ------------------------------------------------------------------ 2 ljus botten
d.append(rubrik(214, 62, "2  Ljus botten – dokument, kartong, etikett", 3.4))
d.append('<rect x="214" y="67" width="176" height="70" rx="3" fill="#F5F7FB" stroke="%s" stroke-width="0.2"/>' % LINJE)
g, b, h = plats("ikon-ljus.svg", 226, 78, bredd_mm=48)
d.append(g)
g2, b2, h2 = plats("marke.svg", 292, 84, bredd_mm=32)
d.append(g2)
g3, b3, h3 = plats("marke.svg", 346, 90, bredd_mm=20)
d.append(g3)
d.append(brodtext(214, 144, "ikon-ljus.svg  ·  marke.svg i 32 och 20 mm", 2.3))

# ------------------------------------------------------------------ 3 enfärg
d.append(rubrik(18, 162, "3  Enfärg – stämpel, gravyr, etsning, brodyr", 3.4))
for i, (namn, mm) in enumerate([("marke-enfarg.svg", 44), ("ikon-monokrom.svg", 30)]):
    g, b, h = plats(namn, 18 + i * 62, 168, bredd_mm=mm)
    d.append(g)
d.append(brodtext(18, 220, "marke-enfarg.svg och ikon-monokrom.svg · 100 % svart (K)", 2.3))

# ------------------------------------------------------------------ 4 storlekar
d.append(rubrik(214, 162, "4  Minsta storlekar", 3.4))
sx = 214
for i, mm in enumerate([24, 16, 12, 8, 6]):
    g, b, h = plats("marke.svg", sx + i * 34, 168 + (24 - mm), bredd_mm=mm)
    d.append(g)
    d.append(brodtext(sx + i * 34, 202, "%d mm" % mm, 2.2))
d.append(brodtext(sx, 216, "Under 8 mm tappas hyllplanen – använd ikonen i stället.", 2.3))
d.append(brodtext(sx, 220, "Minsta tillåtna: 6 mm i tryck, 16 × 16 px på skärm.", 2.3))

# ------------------------------------------------------------------ 5 färger
y5 = 226
d.append(rubrik(18, y5, "5  Färger", 3.4))
färger = [
    ("#2F6BFF", "Primär blå", "C85 M55 Y0 K0"),
    ("#22D3EE", "Cyan, gradientens slut", "C62 M0 Y15 K0"),
    ("#0D1420", "Mörk yta", "C85 M65 Y45 K70"),
    ("#34D399", "Statusnod, grön", "C62 M0 Y50 K0"),
]
for i, (hex_, namn, cmyk) in enumerate(färger):
    x = 18 + i * 96
    d.append('<rect x="%.2f" y="%.2f" width="26" height="26" rx="2" fill="%s" stroke="%s" stroke-width="0.2"/>'
             % (x, y5 + 5, hex_, LINJE))
    d.append('<path d="%s" fill="%s"/>' % (BOLD.bana(namn, 2.5, x, y5 + 36, 0), MÖRK))
    d.append(brodtext(x, y5 + 40, hex_.upper(), 2.3, MÖRK))
    d.append(brodtext(x, y5 + 43, cmyk + " (vägledande)", 1.9))

# ------------------------------------------------------------------ regler
y6 = 286
d.append(linje(18, y6 - 8, 402, y6 - 8))
d.append(brodtext(18, y6, "Frizon: minst ¼ av märkets höjd runt om. Får inte roteras, sträckas eller läggas på rörig bild utan platta.", 2.5, MÖRK))
d.append(brodtext(18, y6 + 5, "Gradienten #2F6BFF → #22D3EE får ersättas med heldragen #2F6BFF vid enfärgstryck och brodyr.", 2.5, MÖRK))

svg = (
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.1f %.1f" width="%.1fmm" height="%.1fmm">\n'
    "  <title>Märket – tryckark A3</title>\n  %s\n</svg>\n"
    % (ARK_B, ARK_H, ARK_B, ARK_H, "\n  ".join(d))
)

(UT / "marke-tryckark.svg").write_text(svg, encoding="utf-8")
print("  tryck/marke-tryckark.svg  %7d tecken" % len(svg))
