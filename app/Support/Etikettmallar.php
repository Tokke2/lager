<?php
/**
 * Etikettmallar för rullskrivare.
 *
 * Måtten är de FYSISKA etiketterna. Hur mycket av bredden som går att skriva
 * beror på skrivaren – se app/Support/Skrivare.php:
 *
 *   Brother QL-500/550   60,9 mm tryckbredd (720 punkter à 300 dpi)
 *   Brother QL-800-serien 58 mm
 *   Dymo LabelWriter     56 mm
 *   Zebra 2 tum          56 mm
 *   Zebra 4 tum          104 mm
 *
 * Mallarna nedan är därför bara storlekar, inte skrivarberoende. Tryckbredden
 * räknas fram vid utskrift som min(etiketten, skrivhuvudet) så att högerkanten
 * aldrig klipps och en smal rulle förblir smal.
 *
 * Notationen är alltid **bredd × längd** (första talet går över skrivhuvudet).
 * Dymo kallar sin 99012-rulle "89 × 36", men 89 mm är längden – den skrivs
 * alltså som 36x89 här, annars får den inte plats i en 62 mm pappersbana.
 *
 * Egna mått kan anges i millimeter (t.ex. "62x40") för kontinuerlig tape som
 * klipps i valfri längd.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Etikettmallar
{
    /** Tryckbar bredd på QL-500/550 i mm (720 punkter à 300 dpi). */
    public const QL500_BREDD = 60.9;

    /**
     * Bredaste etikett som någon av de vanliga skrivarna klarar (Zebra 4 tum).
     * Den faktiska gränsen per skrivare finns i Skrivare::PROFILER.
     */
    public const RULLBREDD_MAX = 112.0;

    /**
     * Fasta mallar. Nyckeln är det värde som står i adressfältet (?rulle=…).
     *
     * @var array<string, array{namn:string, mm:string, kolumner:int, qr:string, dk:string, notis:string}>
     */
    public const MALLAR = [
        '62x100' => [
            'namn' => '62 × 100 mm – namnbricka (DK-1202)',
            'mm' => '62mm 100mm',
            'kolumner' => 1,
            'qr' => '40mm',
            'dk' => 'DK-1202',
            'notis' => 'Stor etikett med plats för bild och stor QR.',
        ],
        // Arbetsplatsens vanligaste rulle. Samma rulle som 62x100 ovan, men
        // namngiven som den säljs så att man känner igen den på etiketten:
        // "DK-11202, 300 etiketter, 62 mm bred". 123ink skriver måtten som
        // 100 × 62 (längd × bredd) – det är samma rulle.
        'dk11202' => [
            'namn' => '62 × 100 mm – DK-11202 (300/rulle)',
            'mm' => '62mm 100mm',
            'kolumner' => 1,
            'qr' => '48mm',
            'dk' => 'DK-11202',
            'notis' => 'Den vanligaste Brother-rullen. Stor QR – bra för hyllor och verktyg.',
        ],
        '29x90' => [
            'namn' => '29 × 90 mm – adressetikett (DK-1201)',
            'mm' => '29mm 90mm',
            'kolumner' => 1,
            'qr' => '22mm',
            'dk' => 'DK-1201',
            'notis' => 'Standardrullen som ofta följer med skrivaren.',
        ],
        '29x62' => [
            'namn' => '29 × 62 mm – liten adressetikett (DK-1209)',
            'mm' => '29mm 62mm',
            'kolumner' => 1,
            'qr' => '18mm',
            'dk' => 'DK-1209',
            'notis' => 'Liten etikett – artikelnamnet kan behöva kortas.',
        ],
        '62x29' => [
            'namn' => '62 × 29 mm – hyllkant (vanligast)',
            'mm' => '62mm 29mm',
            'kolumner' => 1,
            'qr' => '24mm',
            'dk' => 'Kontinuerlig 62 mm, kliks 29 mm',
            'notis' => 'Samma mått som vanliga hyllkantsetiketter.',
        ],
        '62x40' => [
            'namn' => '62 × 40 mm – hyllkant med extra plats',
            'mm' => '62mm 40mm',
            'kolumner' => 1,
            'qr' => '30mm',
            'dk' => 'Kontinuerlig 62 mm, kliks 40 mm',
            'notis' => 'Bra när artikelnamnet är långt.',
        ],
        '36x89' => [
            'namn' => '36 × 89 mm – Dymo stor adressetikett (99012)',
            'mm' => '36mm 89mm',
            'kolumner' => 1,
            'qr' => '24mm',
            'dk' => 'Dymo 99012',
            'notis' => 'Vanligaste Dymo-rullen. 36 mm över skrivhuvudet, 89 mm åt gången.',
        ],
        '28x89' => [
            'namn' => '28 × 89 mm – Dymo adressetikett (99010)',
            'mm' => '28mm 89mm',
            'kolumner' => 1,
            'qr' => '20mm',
            'dk' => 'Dymo 99010',
            'notis' => 'Smalare variant av 99012 – bra till hyllkanter.',
        ],
        '54x101' => [
            'namn' => '54 × 101 mm – Dymo fraktetikett (30256)',
            'mm' => '54mm 101mm',
            'kolumner' => 1,
            'qr' => '36mm',
            'dk' => 'Dymo 30256',
            'notis' => 'Största etiketten som ryms i en LabelWriter med 62 mm pappersbana.',
        ],
        '57x32' => [
            'namn' => '57 × 32 mm – Dymo FBA-etikett',
            'mm' => '57mm 32mm',
            'kolumner' => 1,
            'qr' => '24mm',
            'dk' => 'Dymo 11354',
            'notis' => 'Passar både Dymo och Brother QL-500 (60,9 mm tryckbredd).',
        ],
        '100x50' => [
            'namn' => '100 × 50 mm – Zebra hylletikett',
            'mm' => '100mm 50mm',
            'kolumner' => 1,
            'qr' => '42mm',
            'dk' => 'Zebra 4 tum',
            'notis' => 'Kräver 4-tumsskrivare. Textstorleken skalas upp automatiskt.',
        ],
        '101x152' => [
            'namn' => '101 × 152 mm – fraktetikett (4 × 6 tum)',
            'mm' => '101mm 152mm',
            'kolumner' => 1,
            'qr' => '70mm',
            'dk' => 'Zebra 4 tum',
            'notis' => 'Standard fraktetikett. Fungerar även på Dymo 4XL och 5XL.',
        ],
        '50x25' => [
            'namn' => '50 × 25 mm – liten etikett',
            'mm' => '50mm 25mm',
            'kolumner' => 1,
            'qr' => '15mm',
            'dk' => 'Kontinuerlig tape',
            'notis' => 'Liten lapp till lådor och fack. Kort artikelnamn rekommenderas.',
        ],
        'A4' => [
            'namn' => 'A4-ark – vanlig skrivare (3 kolumner)',
            'mm' => 'A4',
            'kolumner' => 3,
            'qr' => '26mm',
            'dk' => '–',
            'notis' => 'För vanlig skrivare, inte för rullskrivaren.',
        ],
    ];

    /** Standard när inget är valt. */
    public const STANDARD = '62x29';

    /**
     * Vad som trycks på etiketten.
     *
     *   namn – bara QR-koden och artikelnamnet (standard: lugnt och lättläst
     *          på en hyllkant, och namn + QR är det personalen faktiskt läser)
     *   allt – även artikelnummer, streckkod och enhetskod
     *
     * @var array<string,string>
     */
    public const LAYOUTS = [
        'namn' => 'QR + artikelnamn',
        'allt' => 'QR + namn + nummer + streckkod',
    ];

    /** Standardlayout. */
    public const LAYOUT = 'namn';

    /**
     * Tolka en rullnyckel. Stöder även egna mått som "62x40" (mm).
     * @return array{namn:string, mm:string, kolumner:int, qr:string, dk:string, notis:string, egen:bool, ql500_ok:bool}
     */
    public static function tolka(?string $nyckel): array
    {
        $nyckel = trim((string) $nyckel);
        if ($nyckel === '') {
            $nyckel = self::STANDARD;
        }

        // Egna mått: 62x40, 100x50 osv.
        if (preg_match('/^(\d{2,3})x(\d{2,4})$/i', $nyckel, $m)) {
            $bredd = (int) $m[1];
            $hojd = (int) $m[2];
            $nyckel = $bredd . 'x' . $hojd;
            if (isset(self::MALLAR[$nyckel])) {
                return self::medMatt(self::MALLAR[$nyckel], false);
            }
            // Håll måttet inom vad de vanligaste skrivarna klarar
            $profil = Skrivare::aktiv();
            if ($bredd > $profil['max_etikettbredd'] || $hojd > $profil['max_hojd'] || $hojd < 20 || $bredd < 20) {
                $bredd = (int) min($profil['max_etikettbredd'], max(20, $bredd));
                $hojd = (int) min($profil['max_hojd'], max(20, $hojd));
            }
            // QR får plats i höjden med marginal; minst 14 mm
            $qr = max(14, min(70, min($hojd - 8, $bredd - 20)));
            return [
                'namn' => sprintf('Egna mått %d × %d mm', $bredd, $hojd),
                'mm_bredd' => $bredd,
                'mm_hojd' => $hojd,
                'mm' => $bredd . 'mm ' . $hojd . 'mm',
                'kolumner' => 1,
                'qr' => $qr . 'mm',
                'dk' => 'Eget mått ' . $bredd . ' × ' . $hojd . ' mm',
                'notis' => 'Egen längd. Kontrollera att rullen passar i skrivaren.',
                'egen' => true,
                'ql500_ok' => $bredd <= 62 && $hojd <= 1000,
            ];
        }

        if (isset(self::MALLAR[$nyckel])) {
            return self::medMatt(self::MALLAR[$nyckel], false);
        }

        return self::medMatt(self::MALLAR[self::STANDARD], false);
    }

    /** Lägg till uträknad bredd/höjd i millimeter. */
    private static function medMatt(array $mall, bool $egen): array
    {
        if (strtoupper($mall['mm']) === 'A4') {
            $bredd = 210;
            $hojd = 297;
        } else {
            [$b, $h] = explode(' ', $mall['mm']);
            $bredd = (int) $b;
            $hojd = (int) $h;
        }
        return $mall + [
            'egen' => $egen,
            'ql500_ok' => $bredd <= 62 && $hojd <= 1000,
            'mm_bredd' => $bredd,
            'mm_hojd' => $hojd,
        ];
    }

    /** Alla valbara storlekar för en dropdown. */
    public static function valbara(): array
    {
        return self::MALLAR;
    }

    /**
     * Samma storlekar, grupperade efter skrivarfamilj så att listan går att
     * överblicka även när man har flera sorters skrivare.
     *
     * @return array<string, array<string, array>>
     */
    public static function grupper(): array
    {
        $grupper = [];
        foreach (self::MALLAR as $nyckel => $mall) {
            $dk = (string) ($mall['dk'] ?? '');
            if (strtoupper((string) $mall['mm']) === 'A4') {
                $grupp = 'Vanlig skrivare';
            } elseif (str_starts_with($dk, 'Dymo')) {
                $grupp = 'Dymo LabelWriter';
            } elseif (str_starts_with($dk, 'Zebra')) {
                $grupp = 'Zebra';
            } elseif (str_starts_with($dk, 'DK-')) {
                $grupp = 'Brother QL (DK-rullar)';
            } else {
                $grupp = 'Kontinuerlig tape – valfri längd';
            }
            $grupper[$grupp][$nyckel] = $mall;
        }

        // Vanligast först
        $ordning = ['Kontinuerlig tape – valfri längd', 'Brother QL (DK-rullar)', 'Dymo LabelWriter', 'Zebra', 'Vanlig skrivare'];
        $sorterad = [];
        foreach ($ordning as $g) {
            if (isset($grupper[$g])) {
                $sorterad[$g] = $grupper[$g];
                unset($grupper[$g]);
            }
        }

        return $sorterad + $grupper;
    }

    /** Läsbar etikettstorlek, t.ex. "62 × 29 mm". */
    public static function storlekstext(array $mall): string
    {
        if (strtoupper($mall['mm']) === 'A4') {
            return 'A4';
        }
        [$b, $h] = explode(' ', $mall['mm']);
        return str_replace(['mm'], '', $b) . ' × ' . str_replace(['mm'], '', $h) . ' mm';
    }
}
