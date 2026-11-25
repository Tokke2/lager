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
            'hus' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h5v-6h3v6h5V9.5"/>',
            'oversikt' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
            'in' => '<path d="M12 3v11m0 0-3.5-3.5M12 14l3.5-3.5"/><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/>',
            'ut' => '<path d="M12 15V4m0 0L8.5 7.5M12 4l3.5 3.5"/><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/>',
            'lista' => '<path d="m3.5 6 1 1 2-2m-3 7 1 1 2-2m-3 7 1 1 2-2M10 6h10M10 12h10M10 18h10"/>',
            'byt' => '<path d="M4 8h14m0 0-3-3m3 3-3 3M20 16H6m0 0 3-3m-3 3 3 3"/>',
            'skanna' => '<path d="M4 8V6a2 2 0 0 1 2-2h2m8 0h2a2 2 0 0 1 2 2v2m0 8v2a2 2 0 0 1-2 2h-2m-8 0H6a2 2 0 0 1-2-2v-2M3 12h18"/><path d="M8 9v6m3-6v6m3-6v6m3-6v6" opacity=".55"/>',
            'bock' => '<circle cx="12" cy="12" r="8.2"/><path d="M8.4 12.4 10.9 15l4.7-5.2"/>',
            'artiklar' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v8.5"/>',
            'lagerkarta' => '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M9 3.5v17m6-17v17M3.5 9h17m-17 6h17"/>',
            'lokalplan' => '<path d="m3.5 6.5 5.7-2.7 5.6 2.7 5.7-2.7v13.7l-5.7 2.7-5.6-2.7-5.7 2.7zM9.2 4v13.5m5.6-11v13.5"/>',
            'varning' => '<path d="M10.7 4.6a1.5 1.5 0 0 1 2.6 0l7.3 12.6a1.5 1.5 0 0 1-1.3 2.3H4.7a1.5 1.5 0 0 1-1.3-2.3zM12 9v4.5M12 16.5h.01"/>',
            'historik' => '<path d="M4 10a8 8 0 1 1 .3 5.7M4 4.5V10h5.5"/><path d="M12 7.5V12l3.5 2"/>',
            'arkiv' => '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9.5A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5V9M9.5 13h5"/>',
            'etikett' => '<path d="M3.8 11.7 11.5 4H19a1 1 0 0 1 1 1v7.5l-7.7 7.7a1.5 1.5 0 0 1-2.1 0l-6.4-6.4a1.5 1.5 0 0 1 0-2.1Z"/><circle cx="16.5" cy="7.5" r="1"/>',
            'nal' => '<path d="M19 10.5c0 5-7 10-7 10s-7-5-7-10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10.5" r="2.5"/>',
            'person' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
            'konton' => '<circle cx="9" cy="8" r="3"/><path d="M3 19a6 6 0 0 1 12 0M16 5.2a3 3 0 0 1 0 5.6M17.5 13.5a5.7 5.7 0 0 1 3.5 5.2"/>',
            'kugghjul' => '<path d="M9.7 3.5h4.6l.5 2a7 7 0 0 1 1.5.9l2-.6 2.3 4-1.5 1.4a7 7 0 0 1 0 1.6l1.5 1.4-2.3 4-2-.6a7 7 0 0 1-1.5.9l-.5 2H9.7l-.5-2a7 7 0 0 1-1.5-.9l-2 .6-2.3-4 1.5-1.4a7 7 0 0 1 0-1.6L3.4 9.8l2.3-4 2 .6a7 7 0 0 1 1.5-.9z"/><circle cx="12" cy="12" r="2.5"/>',
            'diamant' => '<path d="M12 3.5 14.6 7.8l4.9.7-3.5 3.5.8 4.9L12 14.6l-4.8 2.3.8-4.9L4.5 8.5l4.9-.7z"/>',
            'halsa' => '<path d="M12 3.5 19 6v5.5c0 4.1-2.6 7.2-7 9-4.4-1.8-7-4.9-7-9V6zM12 8.5v6m-3-3h6"/>',
            'nyckel' => '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H20m-3 0v3m3-3v2.5"/>',
            'platsetikett' => '<path d="M4 4.5h16v11H13l-3 3-1-3H4zM8 9h8M8 12h5"/>',
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
