<?php

declare(strict_types=1);

namespace TDL\Support;

/**
 * Code 39 – streckkod som komplement till QR.
 *
 * En del handscanners i lager läser bara streckkod, och en tryckt Code 39
 * under QR-koden kostar nästan ingen plats. Koden ritas som <i>-element med
 * relativ bredd, så den passar etikettens bredd utan att man behöver räkna om
 * punkter för varje skrivare.
 *
 * Varje tecken är 9 element (5 streck + 4 mellanrum), alltid 3 breda och
 * 6 smala. Start- och stopptecknet är *. Ett tecken blir alltså 16 moduler
 * brett inklusive ett mellanrum mellan tecknen.
 *
 * Vikten av modulbredden: en 8 tecken lång kod på en 62 mm-etikett ger
 * 0,26 mm modul, vilket ligger under Code 39:s rekommenderade minimum. Därför
 * räknar syns() fram om koden får plats läsbart, och hur många tecken som
 * behövs för en modul som går att skanna.
 */
final class Streckkod
{
    /** Rekommenderad minsta modulbredd (smalaste strecket) i mm. */
    public const MIN_MODUL = 0.25;

    /** Moduler per tecken inklusive mellanrum: 9 element à 1–3 moduler + 1. */
    private const MODULER_PER_TECKEN = 16;

    /** Tecken som Code 39 klarar av (utan utökning). */
    private const MONSTER = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '*' => 'nwnnwnwnn',
    ];

    /** Rensa ett artikelnummer till tecken Code 39 klarar. */
    public static function rensa(string $text, int $maxlangd = 16): string
    {
        // Versaler först – annars filtreras små bokstäver bort innan de hunnit bli stora
        $text = (string) preg_replace('/[^0-9A-Z\-\. ]/', '', strtoupper($text));

        return substr($text, 0, $maxlangd);
    }

    /**
     * Elementen som ska ritas, i ordning.
     *
     * @return list<array{streck: bool, enheter: int}>
     */
    public static function element(string $text, int $maxlangd = 16): array
    {
        $text = self::rensa($text, $maxlangd);
        if ($text === '') {
            return [];
        }

        $element = [];
        foreach (str_split('*' . $text . '*') as $tecken) {
            $monster = self::MONSTER[$tecken] ?? '';
            for ($i = 0, $n = strlen($monster); $i < $n; $i++) {
                $element[] = [
                    'streck' => $i % 2 === 0,
                    'enheter' => $monster[$i] === 'w' ? 3 : 1,
                ];
            }
            $element[] = ['streck' => false, 'enheter' => 1];
        }

        return $element;
    }

    /** Texten som står under streckkoden (utan stjärnorna). */
    public static function text(string $text, int $maxlangd = 16): string
    {
        return self::rensa($text, $maxlangd);
    }

    /** Antal moduler för en kod med $tecken tecken (inkl. start och stopp). */
    public static function moduler(int $tecken): int
    {
        return max(0, $tecken) * self::MODULER_PER_TECKEN;
    }

    /** Modulbredd i mm om koden ritas i $breddMm. */
    public static function modulbredd(string $text, float $breddMm): float
    {
        $moduler = self::moduler(mb_strlen(self::rensa($text)) + 2);
        if ($moduler === 0 || $breddMm <= 0) {
            return 0.0;
        }

        return $breddMm / $moduler;
    }

    /**
     * Får koden plats läsbart?
     *
     * @return array{ok:bool, modul:float, max_tecken:int, text:string}
     */
    public static function syns(string $text, float $breddMm): array
    {
        $text = self::rensa($text);
        $modul = self::modulbredd($text, $breddMm);
        // Hur många tecken som ryms med minsta tillåtna modul (start+stopp tar 2)
        $maxTecken = max(0, (int) floor($breddMm / (self::MIN_MODUL * self::MODULER_PER_TECKEN)) - 2);

        return [
            'ok' => $modul >= self::MIN_MODUL,
            'modul' => $modul,
            'max_tecken' => $maxTecken,
            'text' => $text,
        ];
    }

    /**
     * Kortare kod för smala etiketter, så att modulen blir läsbar.
     *
     * Klipper hellre vid en avdelare än mitt i ett ord: "DEMO-100" -> "100"
     * (inte "MO-100"). Streckkoden är bara en reserv – QR-koden bredvid är
     * alltid komplett, så en kortare kod förlorar ingen information.
     */
    public static function kortad(string $text, int $maxTecken): string
    {
        $text = self::rensa($text);
        if ($maxTecken <= 0 || $text === '') {
            return '';
        }
        if (mb_strlen($text) <= $maxTecken) {
            return $text;
        }

        // Sista biten efter avdelare – men bara om den säger något (minst 2 tecken)
        $delar = preg_split('/[-. ]+/', $text) ?: [];
        $sista = (string) end($delar);
        if (mb_strlen($sista) >= 2 && $sista !== $text && mb_strlen($sista) <= $maxTecken) {
            return $sista;
        }

        // Annars: hela slutet, men klipp inte mitt i en bokstavsflock i onödan
        return mb_substr($text, -$maxTecken);
    }
}
