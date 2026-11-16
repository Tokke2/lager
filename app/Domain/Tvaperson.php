<?php
/**
 * Tvåpersonersregel för stora uttag.
 *
 * Regeln kan slås på globalt (antal enheter eller värde i kronor) och per
 * artikel. Ett uttag som passerar gränsen får bara genomföras om en kollega
 * bekräftar det med sitt eget konto.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\Installningar;

final class Tvaperson
{
    /**
     * Returnerar en kort förklaring om uttaget kräver godkännande,
     * annars null.
     */
    public static function kraverGodkannande(array $artikel, array $plats, int $antal): ?string
    {
        if ($antal <= 0) {
            return null;
        }

        // Per artikel: egen gräns vinner
        $artikelGrans = (int) ($artikel['tva_person_antal'] ?? 0);
        if ($artikelGrans > 0 && $antal >= $artikelGrans) {
            return 'artikeln kräver kollegas bekräftelse vid ' . $artikelGrans . ' '
                . ($artikel['enhet'] ?? 'st') . ' eller mer';
        }

        $globalAntal = (int) Installningar::hamta('tva_person_grans');
        if ($globalAntal > 0 && $antal >= $globalAntal) {
            return 'uttag över ' . $globalAntal . ' enheter';
        }

        $varde = (float) str_replace(',', '.', (string) ($artikel['prisreferens'] ?? '0'));
        $gransVarde = (float) Installningar::hamta('tva_person_varde');
        if ($gransVarde > 0 && $varde > 0 && ($varde * $antal) >= $gransVarde) {
            return 'uttag värt ' . number_format($varde * $antal, 0, ',', ' ') . ' kr eller mer';
        }

        return null;
    }

    /** Behöver användaren fylla i en kollegas bekräftelse för det här uttaget? */
    public static function behovs(array $artikel, array $plats, int $antal): bool
    {
        return self::kraverGodkannande($artikel, $plats, $antal) !== null;
    }
}
