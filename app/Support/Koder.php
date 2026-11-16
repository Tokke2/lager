<?php
/**
 * Koder för QR-etiketter och interna ID:n.
 *
 * Lagrad form (utan bindestreck):  TDLU + 10 tecken + kontrolltecken
 *                                  TDLL + 5 tecken + kontrolltecken
 * Visningsform:                    TDL-U-XXXXX-XXXXX-C  /  TDL-L-XXXXX-C
 *
 * Alfabet: Crockford Base32 (0-9 A-Z utan I, L, O, U) + ISO 7064 MOD 37-2
 * som kontrolltecken. Vid manuell inmatning normaliseras O→0, I/L→1, U→V –
 * men ENDAST i kodens kropp, aldrig i typ-prefixet TDLU/TDLL.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Koder
{
    private const ALFABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // 32 tecken
    private const BAS36 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; // 36 tecken

    public static function slump(int $langd): string
    {
        $ut = '';
        for ($i = 0; $i < $langd; $i++) {
            $ut .= self::ALFABET[random_int(0, 31)];
        }
        return $ut;
    }

    /** ISO 7064 MOD 37-2. Returnerar 0–36, eller -1 vid ogiltigt tecken. */
    public static function kontrolltecken(string $kropp): int
    {
        for ($c = 0; $c <= 36; $c++) {
            $test = $kropp . ($c === 36 ? '*' : self::BAS36[$c]);
            $p = 36;
            foreach (str_split($test) as $tecken) {
                $v = strpos(self::BAS36, $tecken);
                if ($v === false) {
                    return -1;
                }
                $p = (($p + $v) * 2) % 37;
            }
            if ($p === 1) {
                return $c;
            }
        }
        return -1;
    }

    /** Verifiera en fullständig kod (utan bindestreck). */
    public static function verifieraKontroll(string $full): bool
    {
        $p = 36;
        foreach (str_split($full) as $tecken) {
            $v = $tecken === '*' ? 36 : strpos(self::BAS36, $tecken);
            if ($v === false) {
                return false;
            }
            $p = (($p + $v) * 2) % 37;
        }
        return $p === 1;
    }

    /**
     * Skapa en kod utan bindestreck.
     * '*' är ett giltigt kontrolltecken men svårt att skriva av manuellt,
     * så kroppen dras om tills kontrolltecknet blir 0–35.
     */
    public static function nyKod(string $typ): string
    {
        if (!in_array($typ, ['U', 'L', 'S'], true)) {
            throw new \InvalidArgumentException('Okänd kodtyp: ' . $typ);
        }
        $langd = $typ === 'U' ? 10 : 5;
        do {
            $kropp = 'TDL' . $typ . self::slump($langd);
            $c = self::kontrolltecken($kropp);
        } while ($c < 0 || $c === 36);
        return $kropp . self::BAS36[$c];
    }

    /**
     * Normalisera manuell inmatning: versaler, bort med mellanslag och
     * bindestreck, samt O→0, I/L→1, U→V i kodens kropp (inte i TDLU/TDLL).
     */
    public static function utanBindestreck(string $kod): string
    {
        $kod = strtoupper(trim($kod));
        $kod = preg_replace('/[\s\-]+/', '', $kod) ?? '';
        $prefix = '';
        if (preg_match('/^TDL[ULS]/', $kod)) {
            $prefix = substr($kod, 0, 4);
            $kod = substr($kod, 4);
        }
        $kod = strtr($kod, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
        return $prefix . $kod;
    }

    /** Bakåtkompatibelt namn. */
    public static function normalisera(string $kod): string
    {
        return self::utanBindestreck($kod);
    }

    public static function arEnhetskod(string $kod): bool
    {
        return (bool) preg_match('/^TDLU[0-9A-Z]{10}[0-9A-Z*]$/', self::utanBindestreck($kod));
    }

    public static function arPlatskod(string $kod): bool
    {
        return (bool) preg_match('/^TDLL[0-9A-Z]{5}[0-9A-Z*]$/', self::utanBindestreck($kod));
    }

    /** Läsbart visningsformat med bindestreck. */
    public static function visa(?string $kod): string
    {
        if (!$kod) {
            return '–';
        }
        $u = self::utanBindestreck($kod);
        if (strlen($u) === 15 && str_starts_with($u, 'TDLU')) {
            return 'TDL-U-' . substr($u, 4, 5) . '-' . substr($u, 9, 5) . '-' . substr($u, 14, 1);
        }
        if (strlen($u) === 10 && str_starts_with($u, 'TDLL')) {
            return 'TDL-L-' . substr($u, 4, 5) . '-' . substr($u, 9, 1);
        }
        return $kod;
    }

    /** Internt ID med prefix, t.ex. A7K2M9. */
    public static function interntId(string $prefix, int $langd = 6): string
    {
        return $prefix . self::slump($langd);
    }
}
