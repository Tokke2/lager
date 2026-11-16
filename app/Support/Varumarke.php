<?php
/**
 * Vitmärkning: företagets namn, logotyp, färger och ordval.
 *
 * Standardvärden ligger i koden. Kunden kan lägga över egna värden i
 * storage/varumarke.json (skapas av adminpanelen). Inget i koden behöver
 * ändras för att sälja appen under annat namn.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Varumarke
{
    public const STANDARD = [
        // Tomt med flit: kundens namn får aldrig vara förvalt. Det sätts
        // under Varumärke vid första inloggningen, och tills dess visar
        // appen "Lager" (RESERVNAMN). Se även Varumarke::v().
        'namn' => '',
        'kortnamn' => '',
        'undertext' => '',
        'farg_primar' => '#2F6BFF',
        'farg_accent' => '#34D399',
        'farg_bakgrund' => '#0D1420',
        'logotyp_fil' => '',
        'logotyp_ljus_fil' => '',
        'ikon_fil' => '',
        'tema' => 'mork',            // mork | ljus
        'term_arbetsstalle' => 'Arbetsställe',
        'term_inventering' => 'Inventering',
        'support_epost' => '',
        'webbplats' => '',
        'licens' => '',
        'licens_utgang' => '',
    ];

    private static ?array $cache = null;

    public static function fil(): string
    {
        return TDL_STORAGE . '/varumarke.json';
    }

    public static function alla(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $data = self::STANDARD;
        if (is_file(self::fil())) {
            $egna = json_decode((string) file_get_contents(self::fil()), true);
            if (is_array($egna)) {
                foreach ($egna as $nyckel => $varde) {
                    if (array_key_exists($nyckel, self::STANDARD) && is_scalar($varde)) {
                        $data[$nyckel] = (string) $varde;
                    }
                }
            }
        }
        return self::$cache = $data;
    }

    /**
     * Företagsnamnet. Tomt tills administratören anger det under
     * Varumärke – appen visar då "Lager" i stället för att hitta på ett namn.
     * Inget kundnamn får vara förvalt i releasen: varje kund sätter sitt eget.
     */
    public static function v(?string $nyckel = null): string
    {
        $alla = self::alla();
        if ($nyckel === null || $nyckel === 'namn') {
            $namn = trim($alla['namn'] ?? '');
            return $namn !== '' ? $namn : self::RESERVNAMN;
        }
        if ($nyckel === 'kortnamn') {
            $kort = trim($alla['kortnamn'] ?? '');
            return $kort !== '' ? $kort : self::RESERVNAMN;
        }
        return $alla[$nyckel] ?? '';
    }

    /** Vad som visas innan kunden har angett sitt namn. */
    public const RESERVNAMN = 'Lager';

    /** Har kunden angett ett eget företagsnamn? */
    public static function harEgetNamn(): bool
    {
        return trim(self::alla()['namn'] ?? '') !== '';
    }

    public static function logotypUrl(): string
    {
        $fil = self::v('logotyp_fil');
        // Basvägen med: appen kan ligga i /lager på webbhotellet.
        return \TDL\Support\Bas::url(
            $fil !== '' ? '/varumarke/logotyp.svg?v=' . substr(sha1($fil), 0, 8) : '/assets/ikon.svg'
        );
    }

    public static function ikonUrl(): string
    {
        $fil = self::v('ikon_fil');
        // Ikonen byts sällan men ligger ofta kvar i webbläsarens cache. Versionen
        // i frågan gör att en ny ikon slår igenom direkt efter ett varumärkesbyte.
        return \TDL\Support\Bas::url(
            $fil !== ''
                ? '/varumarke/ikon.svg?v=' . substr(sha1($fil), 0, 8)
                : '/assets/marke.svg?v=' . rawurlencode(TDL_VERSION)
        );
    }

    /**
     * Märket i toppmenyn som inline-SVG: symbolen + företagsnamnet.
     *
     * Symbolen är fristående (hylla med statusnod, inga bokstäver) och följer
     * varumärkets färger via CSS-variablerna. Namnet skrivs som text bredvid –
     * inte som en del av märket – så att det byts ut vid vitmärkning utan att
     * någon logotypfil behöver ritas om.
     */
    public static function menyLogotyp(string $namn): string
    {
        $namn = trim($namn) !== '' ? trim($namn) : 'Lager';
        $symbol = 24.0;
        $mellan = 9.0;
        $textbredd = max(34.0, mb_strlen($namn) * 8.6);
        $bredd = $symbol + $mellan + $textbredd;
        $hojd = 26.0;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="varumarke-svg" viewBox="0 0 %.0F %.0F" '
            . 'width="%.0F" height="%.0F" role="img" aria-label="%s">'
            . '<defs><linearGradient id="vm-g" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="var(--accent)"/>'
            . '<stop offset="1" stop-color="var(--accent2)"/></linearGradient></defs>'
            . '<g transform="translate(0,1) scale(%.5F)">'
            . '<rect x="10.5" y="10.5" width="43" height="43" rx="14" fill="none" '
            . 'stroke="url(#vm-g)" stroke-width="5.8"/>'
            . '<rect x="19" y="24" width="26" height="5.4" rx="2.7" fill="url(#vm-g)"/>'
            . '<rect x="19" y="34" width="17" height="5.4" rx="2.7" fill="url(#vm-g)"/>'
            . '<circle cx="45.5" cy="46" r="5.4" fill="var(--ok)"/>'
            . '</g>'
            . '<text x="%.1F" y="19" fill="var(--text)" font-size="15.5" font-weight="800" '
            . 'font-family="Montserrat, system-ui, sans-serif" letter-spacing="-.2">%s</text>'
            . '</svg>',
            $bredd, $hojd, $bredd, $hojd,
            htmlspecialchars($namn, ENT_QUOTES, 'UTF-8'),
            $symbol / 64.0, $symbol + $mellan,
            htmlspecialchars($namn, ENT_QUOTES, 'UTF-8')
        );
    }

    /** Skriv vitmärkningsinställningar. Kräver skrivlås och admin. */
    public static function spara(array $nya, array $av): void
    {
        if (($av['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer kan ändra varumärket.', 403);
        }
        Las::under(function () use ($nya, $av) {
            $nu = self::alla();
            foreach (self::STANDARD as $nyckel => $standard) {
                if (!array_key_exists($nyckel, $nya)) {
                    continue;
                }
                $varde = trim((string) $nya[$nyckel]);
                if (str_starts_with($nyckel, 'farg_')) {
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $varde)) {
                        throw new AppFel('Färgen ' . $nyckel . ' måste vara i formatet #RRGGBB.');
                    }
                }
                if ($nyckel === 'namn' && ($varde === '' || mb_strlen($varde) > 40)) {
                    throw new AppFel('Namnet måste vara 1–40 tecken.');
                }
                if ($nyckel === 'tema' && !in_array($varde, ['mork', 'ljus'], true)) {
                    throw new AppFel('Temat måste vara mork eller ljus.');
                }
                $nu[$nyckel] = $varde;
            }
            Json::skrivAtomiskt(self::fil(), tdl_json($nu));
            @chmod(self::fil(), 0600);
            self::$cache = null;

            $tx = new Transaktion('plats_andrad', $av);
            $tx->historik([
                'typ' => 'plats_andrad',
                'detaljer' => ['varumarke' => true, 'namn' => $nu['namn']],
            ]);
            $tx->spara();
        });
    }

    /**
     * Spara uppladdad SVG-logotyp. SVG kan innehålla skript – filen saneras
     * innan den används (vitlista, inga skript, inga externa referenser).
     */
    public static function sparaLogotyp(string $svg, string $typ, array $av): void
    {
        if (($av['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer kan byta logotyp.', 403);
        }
        $ren = SvgSanerare::sanera($svg);
        if ($ren === null) {
            throw new AppFel('Filen är inte en giltig SVG utan skript eller externa referenser.');
        }
        $katalog = TDL_STORAGE . '/varumarke';
        @mkdir($katalog, 0700, true);
        $mål = $katalog . '/' . ($typ === 'ikon' ? 'ikon.svg' : 'logotyp.svg');
        Json::skrivAtomiskt($mål, $ren);
        @chmod($mål, 0600);

        $nyckel = $typ === 'ikon' ? 'ikon_fil' : 'logotyp_fil';
        Las::under(function () use ($nyckel, $mål, $av) {
            $nu = self::alla();
            $nu[$nyckel] = $mål;
            Json::skrivAtomiskt(self::fil(), tdl_json($nu));
            @chmod(self::fil(), 0600);
            self::$cache = null;
            $tx = new Transaktion('plats_andrad', $av);
            $tx->historik(['typ' => 'plats_andrad', 'detaljer' => ['varumarke_logotyp' => $typ]]);
            $tx->spara();
        });
    }

    public static function aterstallLogotyp(string $typ, array $av): void
    {
        if (($av['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer kan återställa logotypen.', 403);
        }
        Las::under(function () use ($typ, $av) {
            $nu = self::alla();
            $nu[$typ === 'ikon' ? 'ikon_fil' : 'logotyp_fil'] = '';
            Json::skrivAtomiskt(self::fil(), tdl_json($nu));
            self::$cache = null;
            $tx = new Transaktion('plats_andrad', $av);
            $tx->historik(['typ' => 'plats_andrad', 'detaljer' => ['varumarke_aterstalld' => $typ]]);
            $tx->spara();
        });
    }
}
