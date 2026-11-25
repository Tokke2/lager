<?php
/** Kontroll av publika GitHub-releaser – ingen kod hämtas eller installeras. */
declare(strict_types=1);
namespace TDL\Support;

final class Uppdateringskontroll
{
    public const GREN = 'arena/2bbb9877-lager';
    private const API = 'https://api.github.com/repos/Tokke2/lager/';

    /**
     * Releasen måste vara publicerad, stabil, nyare OCH dess tagg måste
     * finnas i den tillåtna grenens historik. Divergerande taggar avvisas.
     */
    public static function godkand(array $release, array $jamforelse, string $nuvarande): bool
    {
        $tagg = $release['tag_name'] ?? '';
        return is_string($tagg)
            && (bool) preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $tagg)
            && empty($release['draft']) && empty($release['prerelease'])
            && version_compare(substr($tagg, 1), $nuvarande, '>')
            && in_array($jamforelse['status'] ?? '', ['behind', 'identical'], true);
    }

    /** Manuell kontroll: max fem relevanta releaser, aldrig automatiskt vid sidladdning. */
    public static function kontrollera(): ?array
    {
        $releaser = self::hamta('releases?per_page=20');
        if (!array_is_list($releaser)) throw new AppFel('GitHub svarade med ett oväntat format.', 502);
        $kandidater = [];
        foreach ($releaser as $r) {
            if (!is_array($r) || !is_string($r['tag_name'] ?? null)
                || !preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $r['tag_name'])
                || !empty($r['draft']) || !empty($r['prerelease'])
                || !version_compare(substr($r['tag_name'], 1), TDL_VERSION, '>')) continue;
            $kandidater[] = $r;
        }
        usort($kandidater, static fn ($a, $b) => version_compare(substr($b['tag_name'], 1), substr($a['tag_name'], 1)));
        foreach (array_slice($kandidater, 0, 5) as $r) {
            $jamforelse = self::hamta('compare/' . rawurlencode(self::GREN) . '...' . rawurlencode($r['tag_name']));
            if (self::godkand($r, $jamforelse, TDL_VERSION)) {
                return ['tagg' => $r['tag_name'], 'url' => 'https://github.com/Tokke2/lager/releases/tag/' . rawurlencode($r['tag_name'])];
            }
        }
        return null;
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
        try { $data = stream_get_contents($stream, 1048577); } finally { fclose($stream); }
        if (!is_string($data) || strlen($data) > 1048576) throw new AppFel('GitHubs svar var för stort.', 502);
        $json = json_decode($data, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) throw new AppFel('GitHubs svar kunde inte läsas.', 502);
        return $json;
    }
}
