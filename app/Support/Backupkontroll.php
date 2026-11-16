<?php
/**
 * Kontroll av att automatiska backuper verkligen blir av.
 *
 * Läser senaste .zip i storage/backup och jämför med gränsen i
 * inställningarna. Skiljer på "ingen backup alls", "för gammal" och "ok".
 */

declare(strict_types=1);

namespace TDL\Support;

final class Backupkontroll
{
    /**
     * @return array{lage:string, senaste:?string, dagar:?float, fil:?string, antal:int}
     *   lage: 'ok' | 'gammal' | 'saknas'
     */
    public static function lage(): array
    {
        $filer = glob(TDL_BACKUP . '/*.zip') ?: [];
        if ($filer === []) {
            return ['lage' => 'saknas', 'senaste' => null, 'dagar' => null, 'fil' => null, 'antal' => 0];
        }
        usort($filer, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $senasteFil = $filer[0];
        $tid = (int) filemtime($senasteFil);
        $dagar = (time() - $tid) / 86400;
        $grans = max(1, (int) Installningar::hamta('backup_max_dygn'));
        return [
            'lage' => $dagar > $grans ? 'gammal' : 'ok',
            'senaste' => gmdate('Y-m-d H:i', $tid),
            'dagar' => $dagar,
            'fil' => basename($senasteFil),
            'antal' => count($filer),
        ];
    }

    /** Kort text till översikten. */
    public static function sammanfattning(): string
    {
        $l = self::lage();
        if ($l['lage'] === 'saknas') {
            return 'Ingen backup har tagits än.';
        }
        $dagar = (int) floor((float) $l['dagar']);
        if ($l['lage'] === 'gammal') {
            return 'Senaste backup är ' . $dagar . ' dygn gammal.';
        }
        if ($dagar === 0) {
            return 'Senaste backup togs i dag ' . substr((string) $l['senaste'], 11, 5) . '.';
        }
        return 'Senaste backup är ' . $dagar . ' dygn gammal.';
    }
}
