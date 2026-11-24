<?php
/**
 * Ikonbibliotek – en SVG-symbol per ikon, ritad i 24×24-rutnät.
 *
 * Varför inte unicode-tecken (⌂ ▦ ⚙):
 *  - tecknen finns inte i alla typsnitt och blev tomma rutor på vissa telefoner
 *  - de har olika optisk storlek och linjetjocklek, så raden såg ojämn ut
 *  - de kan inte ärva färg och skalas suddigt på högupplösta skärmar
 *
 * En enda sprite i sidan (<svg class="ikon-sprite">) + <use href="#i-namn">
 * ger skarpa vektorer i alla storlekar, en gemensam linjetjocklek och noll
 * extra nätverksanrop. Symbolerna ärver currentColor och stroke-inställningen
 * från den <svg> som använder dem.
 */

declare(strict_types=1);

if (!function_exists('tdl_ikoner')) {
    /**
     * Alla symboler. Nyckeln är ikonens namn (används som #i-<namn>).
     *
     * @return array<string, string>
     */
    function tdl_ikoner(): array
    {
        static $ikoner = null;
        if ($ikoner !== null) {
            return $ikoner;
        }

        $ikoner = [
            // Navigering
            'hus' => '<path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4.5v-6h-5v6H5a1 1 0 0 1-1-1z"/>',
            'oversikt' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
            'in' => '<path d="M12 4v10m0 0-4-4m4 4 4-4"/><path d="M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2"/>',
            'ut' => '<path d="M12 15V5m0 0L8 9m4-4 4 4"/><path d="M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2"/>',
            'lista' => '<path d="M4 6h2m0 0L4.5 7.5 6.5 5M4 12h2m0 0-1.5 1.5L6.5 11M4 18h2m0 0-1.5 1.5L6.5 17"/><path d="M10 6h10M10 12h10M10 18h10"/>',
            'byt' => '<path d="M4 8h13m0 0-3-3m3 3-3 3"/><path d="M20 16H7m0 0 3-3m-3 3 3 3"/>',
            'skanna' => '<path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2"/><path d="M4 12h16"/>',
            'bock' => '<circle cx="12" cy="12" r="8.2"/><path d="M8.4 12.4 10.9 15l4.7-5.2"/>',
            'artiklar' => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10v16H5.5A1.5 1.5 0 0 1 4 18.5z"/><path d="M10 4h4.5A1.5 1.5 0 0 1 16 5.5v13a1.5 1.5 0 0 1-1.5 1.5H10"/>',
            'lagerkarta' => '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M3.5 9h17M9 3.5v17"/>',
            'lokalplan' => '<path d="M4 6.5 9.5 4l5 2.5L20 4v13.5L14.5 20l-5-2.5L4 20z"/><path d="M9.5 4v13.5M14.5 6.5V20"/>',
            'varning' => '<path d="M12 4.5 21 19H3z"/><path d="M12 10v4"/><circle cx="12" cy="16.6" r=".7" fill="currentColor" stroke="none"/>',
            'historik' => '<path d="M3.8 12a8.2 8.2 0 1 0 2.4-5.8"/><path d="M3.5 4.5V9H8"/><path d="M12 8v4.4l3 1.8"/>',
            'arkiv' => '<rect x="3.5" y="5" width="17" height="4.5" rx="1"/><path d="M5 9.5V18a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/><path d="M10 13h4"/>',
            'etikett' => '<path d="M11.6 3.5H19a1 1 0 0 1 1 1v7.4a1 1 0 0 1-.3.7l-7.6 7.6a1 1 0 0 1-1.4 0l-7.4-7.4a1 1 0 0 1 0-1.4l7.6-7.6a1 1 0 0 1 .7-.3z"/><circle cx="16" cy="8" r="1.3"/>',
            'nal' => '<path d="M12 21s6-5.2 6-10a6 6 0 1 0-12 0c0 4.8 6 10 6 10z"/><circle cx="12" cy="11" r="2.2"/>',
            'person' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
            'konton' => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5a6 6 0 0 1 12 0"/><path d="M16 5.6a3.2 3.2 0 0 1 0 5.8M17.5 14a5.6 5.6 0 0 1 3.5 5.5"/>',
            'kugghjul' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M20.5 12h-2.2M5.7 12H3.5M18 6l-1.6 1.6M7.6 16.4 6 18M18 18l-1.6-1.6M7.6 7.6 6 6"/>',
            'diamant' => '<path d="M12 3.5 14.6 7.8l4.9.7-3.5 3.5.8 4.9L12 14.6l-4.8 2.3.8-4.9L4.5 8.5l4.9-.7z"/>',
            'halsa' => '<path d="M12 3.5l7 2.6v6c0 4-3 7-7 8.4-4-1.4-7-4.4-7-8.4v-6z"/><path d="M12 9v5M9.5 11.5h5"/>',
            'nyckel' => '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H20m-3 0v3m3-3v2.5"/>',
            'platsetikett' => '<path d="M4 5.5h16v10H13l-3 3.5L9 15.5H4z"/><path d="M8 10.5h8"/>',
            // Gränssnitt
            'meny' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'stang' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'utlogg' => '<path d="M15 8V5.5a1.5 1.5 0 0 0-1.5-1.5h-7A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20h7a1.5 1.5 0 0 0 1.5-1.5V16"/><path d="M10 12h10m0 0-3-3m3 3-3 3"/>',
            'sok' => '<circle cx="11" cy="11" r="6.2"/><path d="M15.6 15.6 20 20"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'minus' => '<path d="M5 12h14"/>',
            'pil-hoger' => '<path d="M5 12h14m0 0-5-5m5 5-5 5"/>',
            'pil-ner' => '<path d="M12 5v14m0 0 5-5m-5 5-5-5"/>',
            'klocka' => '<circle cx="12" cy="12" r="8.2"/><path d="M12 7.5v4.8l3.2 1.9"/>',
            'kalender' => '<rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v4M16 3.5v4"/>',
            'info' => '<circle cx="12" cy="12" r="8.2"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".8" fill="currentColor" stroke="none"/>',
            'kryss' => '<circle cx="12" cy="12" r="8.2"/><path d="M9 9l6 6M15 9l-6 6"/>',
        ];

        return $ikoner;
    }
}

if (!function_exists('tdl_ikon_sprite')) {
    /** Hela spriten – skrivs en gång per sida, dold för både öga och skärmläsare. */
    function tdl_ikon_sprite(): string
    {
        $ut = '<svg xmlns="http://www.w3.org/2000/svg" class="ikon-sprite" aria-hidden="true" focusable="false">';
        foreach (tdl_ikoner() as $namn => $banor) {
            $ut .= '<symbol id="i-' . $namn . '" viewBox="0 0 24 24">' . $banor . '</symbol>';
        }
        return $ut . '</svg>';
    }
}

if (!function_exists('tdl_ikon')) {
    /**
     * En ikon i sidan. Ärver textfärgen (currentColor) och skalas skarpt.
     * $klass kan användas för storlek, t.ex. "ikon-stor".
     */
    function tdl_ikon(string $namn, string $klass = ''): string
    {
        $namn = preg_replace('/[^a-z0-9-]/', '', $namn) ?: 'hus';
        if (!isset(tdl_ikoner()[$namn])) {
            $namn = 'hus';
        }
        $klass = trim('ikon ' . $klass);
        return '<svg class="' . e($klass) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<use href="#i-' . $namn . '" xlink:href="#i-' . $namn . '"></use></svg>';
    }
}
