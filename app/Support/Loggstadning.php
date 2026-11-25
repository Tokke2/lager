<?php
/** Endast roterade tekniska loggfiler. Inventeringsarkiv och historik berörs inte. */
declare(strict_types=1);

namespace TDL\Support;

final class Loggstadning
{
    public const ALDER_SEKUNDER = 7 * 86400;

    /**
     * Rensa välkända logrotate-namn direkt i den privata loggkatalogen.
     * Öppna loggar, okända namn, mappar och symboliska länkar lämnas alltid.
     * @return array{borttagna:int,fel:int}
     */
    public static function rensa(string $katalog, ?int $nu = null): array
    {
        $nu ??= time();
        $resultat = ['borttagna' => 0, 'fel' => 0];
        if (!is_dir($katalog) || is_link($katalog)) return $resultat;
        foreach (new \DirectoryIterator($katalog) as $fil) {
            $namn = $fil->getFilename();
            // Logrotate: app.log.1[.gz], app.log.20261124[.gz] eller
            // app.log-20261124[.gz]. Inga andra filtyper eller undermappar.
            if (!preg_match('/^[A-Za-z0-9_-]+\.log(?:\.[0-9]{1,14}|-[0-9]{8,14})(?:\.gz)?$/D', $namn)
                || $fil->isLink() || !$fil->isFile()) continue;
            if ($fil->getMTime() > $nu - self::ALDER_SEKUNDER) continue;
            if (@unlink($fil->getPathname())) $resultat['borttagna']++;
            else $resultat['fel']++;
        }
        return $resultat;
    }

    /** Kör högst en gång per 12 timmar när appen används; cron kan köras oftare. */
    public static function vidAnvandning(): void
    {
        $lasfil = TDL_LOCK . '/loggstadning.lock';
        if (is_link($lasfil)) return;
        $f = @fopen($lasfil, 'c+');
        if (!$f) return;
        try {
            if (!flock($f, LOCK_EX | LOCK_NB)) return;
            rewind($f);
            $senast = (int) stream_get_contents($f);
            if ($senast > 0 && $senast <= time() && time() - $senast < 12 * 3600) return;
            $r = self::rensa(TDL_LOGG);
            // Vid misslyckad radering kan nästa förfrågan försöka igen.
            if ($r['fel'] === 0) {
                ftruncate($f, 0); rewind($f); fwrite($f, (string) time()); fflush($f);
            }
        } catch (\Throwable $e) {
            // Underhåll får inte stoppa lagerarbetet om rättigheter saknas.
            error_log('Loggstädning misslyckades: ' . $e->getMessage());
        } finally {
            @flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
