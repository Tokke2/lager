<?php
/** Jämför installerade programfiler mot den tillåtna grenens senaste release. */
declare(strict_types=1);
namespace TDL\Support;

final class Uppdateringskontroll
{
    public const GREN = 'arena/2bbb9877-lager';
    private const API = 'https://api.github.com/repos/Tokke2/lager/';
    private const MAX_FIL = 32 * 1024 * 1024;

    /** Endast den senaste releasen, på exakt den tillåtna grenens aktuella commit. */
    public static function verifieraRelease(array $release, array $gren, array $tagg): string
    {
        $namn = $release['tag_name'] ?? '';
        if (!is_string($namn) || !preg_match('/^build-([a-f0-9]{40})$/D', $namn, $m)
            || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false
            || ($gren['object']['type'] ?? '') !== 'commit'
            || ($tagg['object']['type'] ?? '') !== 'commit'
            || !hash_equals($m[1], (string) ($tagg['object']['sha'] ?? ''))
            || !hash_equals($m[1], (string) ($gren['object']['sha'] ?? ''))) {
            throw new AppFel('Senaste releasen motsvarar inte den tillåtna grenens aktuella commit. Uppdatera inte från den.', 409);
        }
        return $m[1];
    }

    /** Kontrollera release, tagg och gren oberoende; jämför därefter Git-blobbar. */
    public static function kontrollera(): array
    {
        $release = self::hamta('releases/latest');
        $namn = $release['tag_name'] ?? '';
        if (!is_string($namn) || !preg_match('/^build-[a-f0-9]{40}$/D', $namn)) {
            throw new AppFel('Senaste releasen har inte ett giltigt grenbundet byggnummer. Uppdatera inte från den.', 409);
        }
        $gren = self::hamta('git/ref/heads/' . rawurlencode(self::GREN));
        $tagg = self::hamta('git/ref/tags/' . rawurlencode($namn));
        $sha = self::verifieraRelease($release, $gren, $tagg);
        $trad = self::hamta('git/trees/' . $sha . '?recursive=1');
        $jamforelse = self::jamforTrad($trad, TDL_ROT);
        return ['tagg' => $namn, 'commit' => $sha,
            'url' => 'https://github.com/Tokke2/lager/releases/tag/' . rawurlencode($namn)] + $jamforelse;
    }

    /**
     * Jämför enbart releasens versionshanterade programfiler, aldrig storage/.
     * GitHub anger Git SHA-1 (blob-header + innehåll), inte en signatur.
     * Extra lokala filer och filrättigheter ingår inte i jämförelsen.
     * @return array{antal:int,oforandrade:int,andrade:array,saknade:array,otillgangliga:array}
     */
    public static function jamforTrad(array $trad, string $rot): array
    {
        if (($trad['truncated'] ?? true) !== false || !is_array($trad['tree'] ?? null)) throw new AppFel('GitHubs fillista är ofullständig. Ingen jämförelse gjordes.', 502);
        $resultat = ['antal' => 0, 'oforandrade' => 0, 'andrade' => [], 'saknade' => [], 'otillgangliga' => []];
        $sedda = []; $totalStorlek = 0;
        foreach ($trad['tree'] as $post) {
            $path = $post['path'] ?? null;
            if (!is_string($path)) throw new AppFel('Ogiltig fillista från GitHub.', 502);
            if (!preg_match('#^(?:index\.php|(?:app|public|bin|brand)/.+)$#D', $path)) continue;
            if (($post['type'] ?? '') === 'tree') continue;
            if (($post['type'] ?? '') !== 'blob' || !in_array($post['mode'] ?? '', ['100644', '100755'], true)
                || !preg_match('#^[A-Za-z0-9_.@/-]+$#D', $path) || str_contains($path, '//')
                || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)
                || !preg_match('/^[a-f0-9]{40}$/D', (string) ($post['sha'] ?? '')) || isset($sedda[$path])) {
                throw new AppFel('Ogiltig eller osäker programfil i GitHubs fillista.', 502);
            }
            $sedda[$path] = true; $resultat['antal']++;
            $fil = rtrim($rot, '/') . '/' . $path;
            $delar = explode('/', $path); $kontroll = rtrim($rot, '/'); $lank = false;
            foreach ($delar as $del) { $kontroll .= '/' . $del; if (is_link($kontroll)) { $lank = true; break; } }
            if ($lank) { $resultat['otillgangliga'][] = $path; continue; }
            if (!file_exists($fil)) { $resultat['saknade'][] = $path; continue; }
            if (!is_file($fil) || !is_readable($fil)) { $resultat['otillgangliga'][] = $path; continue; }
            $storlek = @filesize($fil);
            if (!is_int($storlek) || $storlek > self::MAX_FIL || $totalStorlek + $storlek > 128 * 1024 * 1024) { $resultat['otillgangliga'][] = $path; continue; }
            $totalStorlek += $storlek;
            $f = @fopen($fil, 'rb');
            if ($f === false) { $resultat['otillgangliga'][] = $path; continue; }
            try {
                $hash = hash_init('sha1'); hash_update($hash, 'blob ' . $storlek . "\0");
                $last = hash_update_stream($hash, $f, $storlek); $extra = fread($f, 1); $summa = hash_final($hash);
            } finally { fclose($f); }
            if ($last !== $storlek || $extra !== '') { $resultat['otillgangliga'][] = $path; continue; }
            if (hash_equals($post['sha'], $summa)) $resultat['oforandrade']++;
            else $resultat['andrade'][] = $path;
        }
        if ($resultat['antal'] === 0 || !isset($sedda['app/bootstrap.php'], $sedda['public/index.php'])) throw new AppFel('Releasens fillista saknar nödvändiga appfiler.', 502);
        foreach (['andrade', 'saknade', 'otillgangliga'] as $nyckel) sort($resultat[$nyckel], SORT_STRING);
        return $resultat;
    }

    private static function hamta(string $vag): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) throw new AppFel('Servern tillåter inte HTTPS-kontroll. Öppna GitHub manuellt.', 503);
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 8, 'follow_location' => 0, 'max_redirects' => 0,
                'header' => "Accept: application/vnd.github+json\r\nUser-Agent: Lager-uppdateringskontroll\r\nX-GitHub-Api-Version: 2022-11-28\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $stream = @fopen(self::API . $vag, 'rb', false, $ctx);
        if ($stream === false) throw new AppFel('Kunde inte kontakta GitHub säkert. Kontrollera anslutning, TLS och GitHubs hastighetsgräns.', 503);
        try { $data = stream_get_contents($stream, 2097153); } finally { fclose($stream); }
        if (!is_string($data) || strlen($data) > 2097152) throw new AppFel('GitHubs svar var för stort.', 502);
        $json = json_decode($data, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) throw new AppFel('GitHubs svar kunde inte läsas.', 502);
        return $json;
    }
}
