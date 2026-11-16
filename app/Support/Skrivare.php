<?php

declare(strict_types=1);

namespace TDL\Support;

/**
 * Skrivarprofiler för etikettutskrift.
 *
 * Appen skriver ut via webbläsarens utskriftsdialog, så den behöver inte prata
 * med skrivaren själv – men den måste känna till hur brett skrivhuvudet är.
 * Annars klipps högerkanten av, eller så hamnar texten utanför en smal rulle.
 *
 * Siffrorna är tillverkarnas angivna tryckbredd (inte rullens bredd):
 *
 *   Brother QL-500/550   720 punkter à 300 dpi  = 60,9 mm   (rulle 62 mm)
 *   Brother QL-800-serien                       = 58 mm     (rulle 62 mm)
 *   Dymo LabelWriter 450/550                    = 56 mm     (rulle 62 mm)
 *   Zebra 2 tum (ZD410 m.fl.)                   = 56 mm
 *   Zebra 4 tum (ZD230, ZD421 m.fl.)            = 104 mm    (media 112 mm)
 *   Vanlig skrivare                             = A4
 *
 * Skrivs bredare än så klipps etiketten – därför är tryckbredden alltid
 * min(etikettens bredd, skrivarens tryckbredd).
 */
final class Skrivare
{
    /**
     * @var array<string, array{namn:string, kort:string, bredd:float, max_etikettbredd:float,
     *      max_hojd:float, dpi:int, exempel:string, notis:string}>
     */
    public const PROFILER = [
        'brother_ql500' => [
            'namn' => 'Brother QL-500 / QL-550',
            'kort' => 'QL-500',
            'bredd' => 60.9,
            'max_etikettbredd' => 62.0,
            'max_hojd' => 1000.0,
            'dpi' => 300,
            'exempel' => 'DK-1201 29×90, DK-1202 62×100, DK-1209 29×62, kontinuerlig 62 mm',
            'notis' => '720 punkter à 300 dpi = 60,9 mm. Färdiga DK-rullar är högst 59 mm höga; längre etiketter går på kontinuerlig tape.',
        ],
        'brother_ql800' => [
            'namn' => 'Brother QL-800 / 810W / 820NWB',
            'kort' => 'QL-800',
            'bredd' => 58.0,
            'max_etikettbredd' => 62.0,
            'max_hojd' => 1000.0,
            'dpi' => 300,
            'exempel' => 'DK-1201, DK-1202, DK-22205 kontinuerlig 62 mm',
            'notis' => 'Samma DK-rullar som QL-500 men något smalare tryckhuvud (58 mm).',
        ],
        'dymo_lw' => [
            'namn' => 'Dymo LabelWriter 450 / 550',
            'kort' => 'Dymo LW',
            'bredd' => 56.0,
            'max_etikettbredd' => 62.0,
            'max_hojd' => 1000.0,
            'dpi' => 300,
            'exempel' => '99012 89×36, 99010 89×28, 30256 54×101, 11354 57×32',
            'notis' => 'Dymo anger 56 mm tryckbredd på en 62 mm rulle. 550-serien känner bara igen äkta Dymo-rullar.',
        ],
        'zebra_2' => [
            'namn' => 'Zebra 2 tum (ZD410 m.fl.)',
            'kort' => 'Zebra 2"',
            'bredd' => 56.0,
            'max_etikettbredd' => 60.0,
            'max_hojd' => 991.0,
            'dpi' => 203,
            'exempel' => '50×25, 54×34, kontinuerlig 2 tum',
            'notis' => '2,20 tum tryckbredd vid 203 dpi.',
        ],
        'zebra_4' => [
            'namn' => 'Zebra 4 tum (ZD230, ZD421 m.fl.)',
            'kort' => 'Zebra 4"',
            'bredd' => 104.0,
            'max_etikettbredd' => 112.0,
            'max_hojd' => 991.0,
            'dpi' => 203,
            'exempel' => '101×152 (4×6 tum) fraktetikett, 100×50 hylla',
            'notis' => '4,09 tum tryckbredd vid 203 dpi, media upp till 112 mm. ZD421 i 300 dpi klarar 108 mm.',
        ],
        'a4' => [
            'namn' => 'Vanlig skrivare (A4-ark)',
            'kort' => 'A4',
            'bredd' => 190.0,
            'max_etikettbredd' => 210.0,
            'max_hojd' => 297.0,
            'dpi' => 600,
            'exempel' => 'A4-ark med 3 kolumner',
            'notis' => 'Skriv ut på vanligt papper och tejpa. Bra för att testa innan rullen är på plats.',
        ],
    ];

    public const STANDARD = 'brother_ql500';

    /** Profilen som är vald i System, annars QL-500. */
    public static function aktiv(): array
    {
        $nyckel = (string) Installningar::hamta('skrivare', self::STANDARD);
        return self::profil($nyckel);
    }

    /** @return array{namn:string, kort:string, bredd:float, max_etikettbredd:float, max_hojd:float, dpi:int, exempel:string, notis:string} */
    public static function profil(?string $nyckel = null): array
    {
        $nyckel = (string) $nyckel;
        if (!isset(self::PROFILER[$nyckel])) {
            $nyckel = self::STANDARD;
        }
        $p = self::PROFILER[$nyckel];
        $p['nyckel'] = $nyckel;
        return $p;
    }

    public static function finns(string $nyckel): bool
    {
        return isset(self::PROFILER[$nyckel]);
    }

    /**
     * Bredden som faktiskt går att skriva på – aldrig mer än skrivhuvudet.
     * En 29 mm-rulle ska förstås förbli 29 mm bred.
     */
    public static function tryckbredd(array $mall, ?array $profil = null): float
    {
        $profil = $profil ?? self::aktiv();
        $bredd = (float) ($mall['mm_bredd'] ?? 62);

        return round(min($bredd, (float) $profil['bredd']), 1);
    }

    /** Får etiketten plats på skrivaren? */
    public static function passar(array $mall, ?array $profil = null): bool
    {
        $profil = $profil ?? self::aktiv();
        $bredd = (float) ($mall['mm_bredd'] ?? 0);
        $hojd = (float) ($mall['mm_hojd'] ?? 0);

        return $bredd <= (float) $profil['max_etikettbredd'] + 0.01
            && $hojd <= (float) $profil['max_hojd'] + 0.01;
    }

    /** Kort förklaring när etiketten inte passar skrivaren, annars tom sträng. */
    public static function varning(array $mall, ?array $profil = null): string
    {
        $profil = $profil ?? self::aktiv();
        if (self::passar($mall, $profil)) {
            return '';
        }
        $bredd = (float) ($mall['mm_bredd'] ?? 0);
        if ($bredd > (float) $profil['max_etikettbredd']) {
            return sprintf(
                '%s tar etiketter upp till %s mm breda, och skriver %s mm av dem. Den här etiketten är %s mm – högerkanten klipps. Välj en smalare etikett, eller byt skrivare i System.',
                $profil['namn'],
                self::tal((float) $profil['max_etikettbredd']),
                self::tal((float) $profil['bredd']),
                self::tal($bredd)
            );
        }

        return sprintf(
            '%s klarar etiketter upp till %s mm höga.',
            $profil['namn'],
            self::tal((float) $profil['max_hojd'])
        );
    }

    /**
     * Räkna ut etikettens layout.
     *
     * Standard är bara QR + artikelnamn – det är vad personalen läser på
     * hyllan, och då blir både QR och text större. Med $visaAllt = true läggs
     * artikelnummer, streckkod och enhetskod till.
     *
     * Namnet kan sitta bredvid QR:en (hoger), under den eller över den.
     * "auto" väljer bredvid på breda etiketter och under på smala/höga.
     *
     * @return array{qr:float, placering:string, streckbredd:float, kod:string,
     *               element:list<array{streck:bool,enheter:int}>, modul:float,
     *               kortad:bool, full:string, namn_pt:float}
     */
    public static function forEtikett(
        array $mall,
        string $nummer,
        bool $arArk = false,
        bool $visaAllt = false,
        string $placering = 'auto'
    ): array {
        $bredd = (float) ($mall['mm_bredd'] ?? 62);
        $hojd = (float) ($mall['mm_hojd'] ?? 29);

        // Marginaler: etiketten har 1–1,5 mm luft runt om
        $inreBredd = max(8.0, $bredd - 4.0);
        $inreHojd = max(6.0, $hojd - 3.0);

        // Namnets storlek följer höjden (samma formel som i vyn)
        $bred = $bredd >= 90;
        $namnPt = $arArk
            ? 8.5
            : round(min($bred ? 18 : 11, max(6.5, ($bred ? 0.26 : 0.22) * $hojd)), 1);
        // Två rader namn + lite luft, räknat i mm (1 pt = 0,353 mm)
        $namnMm = $namnPt * 1.14 * 0.353 * 2 + 1.0;

        // Placering: auto = bredvid om etiketten är bredare än den är hög
        if (!in_array($placering, ['hoger', 'under', 'over'], true)) {
            $placering = ($inreBredd >= $inreHojd * 1.45) ? 'hoger' : 'under';
        }

        $full = Streckkod::text($nummer);
        $streckbredd = 0.0;
        $info = Streckkod::syns($full, 0.0);

        if ($arArk) {
            $qr = 26.0;
        } elseif ($visaAllt) {
            // Streckkoden behöver plats bredvid QR:en – offra QR-storlek först
            $tak = max(12.0, min(
                (float) rtrim((string) ($mall['qr'] ?? '24mm'), 'mm'),
                round($hojd * 0.55, 1),
                round($bredd * 0.45, 1)
            ));
            $qr = $tak;
            for ($prov = $tak; $prov >= 12.0; $prov -= 1.0) {
                $breddProv = ($bredd - $prov - 5.0) * 0.92;
                if ($breddProv < 8.0) {
                    continue;
                }
                $kandidat = Streckkod::syns($full, $breddProv);
                if ($kandidat['ok']) {
                    $qr = $prov;
                    $streckbredd = max(8.0, $breddProv);
                    $info = $kandidat;
                    break;
                }
            }
            if ($streckbredd <= 0.0) {
                $streckbredd = max(10.0, ($bredd - $qr - 5.0) * 0.92);
                $info = Streckkod::syns($full, $streckbredd);
            }
            $placering = 'hoger';
        } else {
            // Bara QR + namn: QR:en kan ta nästan hela höjden
            if ($placering === 'hoger') {
                $qr = min($inreHojd, max(10.0, $inreBredd - 22.0), 60.0);
            } else {
                $qr = min($inreBredd, max(10.0, $inreHojd - $namnMm), 60.0);
            }
            $qr = max(10.0, round($qr, 1));
        }

        $kod = $info['ok'] ? $full : Streckkod::kortad($full, $info['max_tecken']);

        return [
            'qr' => $qr,
            'placering' => $placering,
            'streckbredd' => $streckbredd,
            'kod' => $kod,
            'element' => $visaAllt ? Streckkod::element($kod) : [],
            'modul' => Streckkod::modulbredd($kod, $streckbredd),
            'kortad' => $kod !== $full,
            'full' => $full,
            'namn_pt' => $namnPt,
        ];
    }

    /** "60,9" för text i gränssnittet (svenskt komma). */
    public static function tal(float $varde): string
    {
        return rtrim(rtrim(number_format($varde, 1, ',', ''), '0'), ',');
    }

    /**
     * "60.9" för CSS – komma är ogiltigt i CSS-värden och får hela regeln
     * att kastas, vilket gör att etiketten skrivs i full sidbredd.
     */
    public static function mm(float $varde): string
    {
        return rtrim(rtrim(number_format($varde, 1, '.', ''), '0'), '.');
    }
}
