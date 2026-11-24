<?php
/**
 * Inställningar som hör till driften, inte till en enskild artikel.
 *
 * Lagras i storage/data/installningar.json (en enda post, id "INST").
 * Saknas filen används standardvärdena nedan – appen kraschar aldrig på
 * att inställningarna inte är satta än.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Installningar
{
    public const STANDARD = [
        'miniminiva_larm' => 1,        // visa larm på översikten
        'miniminiva_per_mail' => 0,    // skicka sammanställning per e-post
        'miniminiva_mail' => '',       // mottagare
        'tva_person_grans' => 0,       // antal enheter som kräver kollegas PIN (0 = av)
        'tva_person_varde' => 0,       // eller värde i kronor (0 = av)
        'inkop_ledtid' => 7,           // leverantörens ledtid i dagar
        'inkop_tackning' => 30,        // extra dagar att täcka vid beställning
        'inkop_forbrukning_dagar' => 90, // hur långt bakåt förbrukningen mäts
        'backup_max_dygn' => 2,        // rött om senaste backup är äldre
        'skrivare' => 'brother_ql500', // etikettskrivare, se Support/Skrivare.php
        'arkiv_ar' => '',              // valt år i arkivvyn
        'arkiv_manad' => '',           // vald månad i arkivvyn
    ];

    /** @return array<string,mixed> */
    public static function alla(): array
    {
        $fil = TDL_DATA . '/installningar.json';
        if (!is_file($fil)) {
            return self::STANDARD;
        }
        $rå = file_get_contents($fil);
        if ($rå === false) {
            return self::STANDARD;
        }
        $data = json_decode($rå, true);
        if (!is_array($data)) {
            return self::STANDARD;
        }
        return array_merge(self::STANDARD, is_array($data['varden'] ?? null) ? $data['varden'] : $data);
    }

    public static function hamta(string $nyckel, mixed $standard = null): mixed
    {
        $alla = self::alla();
        return $alla[$nyckel] ?? ($standard ?? (self::STANDARD[$nyckel] ?? null));
    }

    /** Spara en eller flera inställningar. */
    public static function spara(array $varden): void
    {
        Las::under(function () use ($varden) {
            $fil = TDL_DATA . '/installningar.json';
            $tx = new Transaktion('installningar_andrade', ['id' => 'SYSTEM', 'namn' => 'System', 'roll' => 'admin']);
            $nuvarande = self::alla();
            $nya = array_merge($nuvarande, $varden);
            $data = ['version' => 1, 'fil' => 'installningar', 'varden' => $nya];
            $tmp = TDL_TMP . '/installningar-' . bin2hex(random_bytes(6)) . '.json';
            if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
                throw new AppFel('Kunde inte spara inställningarna.', 500);
            }
            if (!rename($tmp, $fil)) {
                @unlink($tmp);
                throw new AppFel('Kunde inte spara inställningarna.', 500);
            }
            $tx->historik([
                'typ' => 'installningar_andrade',
                'detaljer' => ['nycklar' => array_keys($varden)],
            ]);
            $tx->spara();
        });
    }
}
