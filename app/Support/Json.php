<?php
/**
 * JSON-lagring: atomiska filbyten, det enda stället som rör datafilerna.
 *
 * Skrivmönster:
 *   1. Servern tar ett globalt flock (en skrivare i taget).
 *   2. Läs, validera och ändra i minnet.
 *   3. Skriv till storage/tmp, fsync, rename() över målet.
 *   Läsare ser därför alltid en hel gammal eller hel ny fil.
 *
 * Journal/logik för flerfilstransaktioner ligger i Transaktion.php.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Json
{
    /** Alla filer appen känner till. */
    public const FILER = [
        'artiklar' => 'artiklar.json',
        'platser' => 'platser.json',
        'enheter' => 'enheter.json',
        'anvandare' => 'anvandare.json',
        'historik' => 'historik.json',
        'sessioner' => 'sessioner.json',
        'zoner' => 'zoner.json',
        'hyllplatser' => 'hyllplatser.json',
    ];

    public static function sokvag(string $nyckel): string
    {
        if (!isset(self::FILER[$nyckel])) {
            throw new \InvalidArgumentException('Okänd datafil: ' . $nyckel);
        }
        return TDL_DATA . '/' . self::FILER[$nyckel];
    }

    /**
     * Standardinnehåll om filen inte finns.
     * @return array{version:int, poster:list<array>}
     */
    public static function tom(string $nyckel): array
    {
        return ['version' => 1, 'fil' => $nyckel, 'poster' => []];
    }

    /**
     * Läs hela filen. Filen skapas ALDRIG av läsningen.
     * @return array{version:int, fil:string, poster:list<array<string,mixed>>}
     */
    public static function las(string $nyckel): array
    {
        $sokvag = self::sokvag($nyckel);
        if (!is_file($sokvag)) {
            throw new AppFel('Datafilen saknas (' . self::FILER[$nyckel] . '). Är installationen körd?', 500);
        }
        $rå = file_get_contents($sokvag);
        if ($rå === false) {
            throw new AppFel('Kan inte läsa datafilen ' . self::FILER[$nyckel], 500);
        }
        $d = json_decode($rå, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($d) || !isset($d['poster']) || !is_array($d['poster'])) {
            throw new AppFel('Datafilen ' . self::FILER[$nyckel] . ' har en oväntad struktur.', 500);
        }
        return $d;
    }

    /** Skriv en fil atomiskt. Kräver att ett skrivlås hålls av anroparen. */
    public static function skriv(string $nyckel, array $innehall): void
    {
        $innehall['version'] = $innehall['version'] ?? 1;
        $innehall['fil'] = $nyckel;
        $sokvag = self::sokvag($nyckel);
        self::skrivAtomiskt($sokvag, tdl_json($innehall));
    }

    /** Atomisk skrivning: tmp-fil i samma katalog, fsync, rename. */
    public static function skrivAtomiskt(string $sokvag, string $data): void
    {
        $katalog = dirname($sokvag);
        $tmp = TDL_TMP . '/skriv-' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = fopen($tmp, 'xb');
        if ($fh === false) {
            throw new AppFel('Kan inte skapa temporär fil i storage/tmp.', 500);
        }
        try {
            if (fwrite($fh, $data) !== strlen($data)) {
                throw new AppFel('Ofullständig skrivning till temporär fil.', 500);
            }
            fflush($fh);
            if (function_exists('fsync')) {
                fsync($fh);
            }
        } finally {
            fclose($fh);
        }
        // rename() inom samma filsystem är atomärt.
        if (!rename($tmp, $sokvag)) {
            @unlink($tmp);
            throw new AppFel('Kunde inte ersätta ' . basename($sokvag) . ' säkert.', 500);
        }
        @chmod($sokvag, 0600);
    }

    /** sha256 av en fil (för journal och backup-manifest). */
    public static function hashFil(string $sokvag): ?string
    {
        if (!is_file($sokvag)) {
            return null;
        }
        return hash_file('sha256', $sokvag) ?: null;
    }

    /** Hitta post på id, eller null. */
    public static function hitta(array $fil, string $id): ?array
    {
        foreach ($fil['poster'] as $i => $post) {
            if (($post['id'] ?? '') === $id) {
                return $fil['poster'][$i];
            }
        }
        return null;
    }

    /** Uppdatera post på id. Returnerar true om den fanns. */
    public static function uppdatera(array &$fil, string $id, callable $andring): bool
    {
        foreach ($fil['poster'] as $i => $post) {
            if (($post['id'] ?? '') === $id) {
                $ny = $andring($post);
                $ny['id'] = $id;
                $fil['poster'][$i] = $ny;
                return true;
            }
        }
        return false;
    }

    /** Lägg till post. */
    public static function laggTill(array &$fil, array $post): void
    {
        $fil['poster'][] = $post;
    }
}
