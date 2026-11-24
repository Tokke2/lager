"""Exporterar PNG-, ICO- och tray-ikoner från SVG-filerna i brand/.

Kör: python3 brand/exportera-ikoner.py
Kräver: cairosvg, Pillow.
"""

from pathlib import Path

import cairosvg
from PIL import Image

ROT = Path(__file__).resolve().parent
IKONER = ROT / "ikoner"
IKONER.mkdir(exist_ok=True)

# Storlekar som används av favicon, PWA, Electron/macOS/Windows/Linux
STORLEKAR = [16, 24, 32, 48, 64, 128, 256, 512, 1024]


def png(svg: Path, storlek: int, ut: Path) -> None:
    cairosvg.svg2png(url=str(svg), write_to=str(ut), output_width=storlek,
                     output_height=storlek, background_color=None)


print("Appikon (färg):")
for s in STORLEKAR:
    ut = IKONER / f"ikon-{s}.png"
    png(ROT / "ikon.svg", s, ut)
    print(f"  {ut.name}")

# ICO med flera storlekar (Windows)
bilder = [Image.open(IKONER / f"ikon-{s}.png").convert("RGBA") for s in [16, 24, 32, 48, 64, 128, 256]]
bilder[-1].save(IKONER / "ikon.ico", format="ICO",
                sizes=[(b.width, b.height) for b in bilder])
print("  ikon.ico (16–256 px)")

# macOS .icns kan byggas av electron-builder från 1024-png; här sparas även råmaterialet
(IKONER / "ikon-1024.png").replace(IKONER / "ikon-mac-1024.png")
print("  ikon-mac-1024.png")

# Favicon-specifik (märket, mer kontrast vid 16 px)
print("Märke / favicon:")
for s in [16, 32, 48]:
    png(ROT / "marke.svg", s, IKONER / f"marke-{s}.png")
    print(f"  marke-{s}.png")

# Systray: färgade och monokroma (macOS template-bilder kräver svart + alpha)
print("Systray:")
for s in [16, 24, 32, 48]:
    png(ROT / "ikon.svg", s, IKONER / f"tray-{s}.png")
    png(ROT / "ikon-monokrom.svg", s, IKONER / f"tray-monokrom-{s}.png")
    print(f"  tray-{s}.png, tray-monokrom-{s}.png")
# macOS template-namn: ...Template.png (+ @2x)
png(ROT / "ikon-monokrom.svg", 16, IKONER / "trayTemplate.png")
png(ROT / "ikon-monokrom.svg", 32, IKONER / "trayTemplate@2x.png")
print("  trayTemplate.png, trayTemplate@2x.png")

# Märket i stor PNG (för e-post, dokument och som förhandsvisning)
print("Märke-PNG:")
for namn, storlek, bak in [("ikon", 1200, None), ("ikon-ljus", 1200, None), ("marke", 1200, None)]:
    if not (ROT / f"{namn}.svg").is_file():
        continue
    cairosvg.svg2png(url=str(ROT / f"{namn}.svg"), write_to=str(IKONER / f"{namn}.png"),
                     output_width=storlek, background_color=bak)
    print(f"  {namn}.png ({storlek} px)")

print(f"\nKlart: {IKONER}")
