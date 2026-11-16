<?php
/**
 * Års- och månadsarkiv för inventeringar.
 *
 * En arkivpost är en fryst kopia av en avslutad inventering: vilka artiklar
 * som räknades, förväntat och räknat antal, avvikelser och hur avstämningen
 * gjordes. Posten ändras aldrig efter att den skapats – det är poängen.
 *
 * Lagring: storage/arkiv/<år>/<år>-<månad>.json
 *   { "ar": 2026, "manad": 11, "skapad": "...", "poster": [ ... ] }
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Arkiv
{
    public const KATALOG = TDL_STORAGE . '/arkiv';

    private static function fil(int $ar, int $manad): string
    {
        return self::KATALOG . '/' . sprintf('%04d', $ar) . '/' . sprintf('%04d-%02d', $ar, $manad) . '.json';
    }

    /** Läs en månads arkiv (tomt om det inte finns). */
    public static function lasManad(int $ar, int $manad): array
    {
        $fil = self::fil($ar, $manad);
        if (!is_file($fil)) {
            return ['ar' => $ar, 'manad' => $manad, 'skapad' => null, 'poster' => []];
        }
        $data = json_decode((string) file_get_contents($fil), true);
        return is_array($data) ? $data : ['ar' => $ar, 'manad' => $manad, 'skapad' => null, 'poster' => []];
    }

    /** Alla år som har arkiv. */
    public static function ar(): array
    {
        $ar = [];
        foreach (glob(self::KATALOG . '/*', GLOB_ONLYDIR) ?: [] as $mapp) {
            $n = (int) basename($mapp);
            if ($n > 2000) {
                $ar[] = $n;
            }
        }
        rsort($ar);
        return $ar;
    }

    /** Månader (1–12) som har arkiv för ett år. */
    public static function manader(int $ar): array
    {
        $manader = [];
        foreach (glob(self::KATALOG . '/' . sprintf('%04d', $ar) . '/*.json') ?: [] as $fil) {
            if (preg_match('/(\d{4})-(\d{2})\.json$/', $fil, $m)) {
                $manader[] = (int) $m[2];
            }
        }
        sort($manader);
        return $manader;
    }

    /** Antal arkiverade inventeringar totalt och per månad. */
    public static function oversikt(): array
    {
        $totalt = 0;
        $rader = [];
        foreach (self::ar() as $ar) {
            foreach (self::manader($ar) as $manad) {
                $m = self::lasManad($ar, $manad);
                $antal = count($m['poster']);
                $totalt += $antal;
                $rader[] = [
                    'ar' => $ar,
                    'manad' => $manad,
                    'antal' => $antal,
                    'skapad' => $m['skapad'] ?? null,
                ];
            }
        }
        return ['totalt' => $totalt, 'rader' => $rader];
    }

    /**
     * Arkivera en avslutad inventering.
     * Idempotent: samma sessions-id arkiveras bara en gång per månad.
     */
    public static function arkiveraSession(array $session, ?array $anvandare = null): bool
    {
        if (($session['status'] ?? '') !== 'avslutad') {
            throw new AppFel('Bara avslutade inventeringar kan arkiveras.');
        }
        $tid = $session['avslutad'] ?? $session['startad'] ?? nu();
        $ar = (int) substr((string) $tid, 0, 4);
        $manad = (int) substr((string) $tid, 5, 2);
        if ($ar < 2000 || $manad < 1 || $manad > 12) {
            $ar = (int) gmdate('Y');
            $manad = (int) gmdate('n');
        }

        $platser = [];
        foreach (Json::las('platser')['poster'] as $p) {
            $platser[$p['id']] = $p['namn'];
        }
        $artiklar = [];
        foreach (Json::las('artiklar')['poster'] as $a) {
            $artiklar[$a['id']] = $a;
        }

        $post = self::byggPost($session, $platser, $artiklar);

        Las::under(function () use ($ar, $manad, $post, $anvandare) {
            $fil = self::fil($ar, $manad);
            $mapp = dirname($fil);
            if (!is_dir($mapp) && !mkdir($mapp, 0775, true) && !is_dir($mapp)) {
                throw new AppFel('Kunde inte skapa arkivmappen.', 500);
            }
            $manadData = self::lasManad($ar, $manad);
            foreach ($manadData['poster'] as $befintlig) {
                if (($befintlig['session_id'] ?? '') === $post['session_id']) {
                    return; // redan arkiverad
                }
            }

            $tx = new Transaktion('arkiv_skapad', $anvandare ?? ['id' => 'SYSTEM', 'namn' => 'system', 'roll' => 'admin']);
            $manadData['poster'][] = $post;
            $manadData['ar'] = $ar;
            $manadData['manad'] = $manad;
            $manadData['skapad'] = $manadData['skapad'] ?? nu();

            $tmp = TDL_TMP . '/arkiv-' . bin2hex(random_bytes(6)) . '.json';
            $json = json_encode($manadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents($tmp, $json) === false) {
                throw new AppFel('Kunde inte skriva arkivfilen.', 500);
            }
            if (!rename($tmp, $fil)) {
                @unlink($tmp);
                throw new AppFel('Kunde inte spara arkivfilen.', 500);
            }

            $tx->historik([
                'typ' => 'arkiv_skapad',
                'plats_id' => $post['plats_id'],
                'plats_namn' => $post['plats_namn'],
                'detaljer' => [
                    'session_id' => $post['session_id'],
                    'ar' => $ar,
                    'manad' => $manad,
                    'artiklar' => $post['antal_artiklar'],
                    'avvikelser' => $post['antal_avvikelser'],
                ],
            ]);
            $tx->spara();
        });

        return true;
    }

    /** Fryst kopia av en session – det som sparas i arkivet. */
    public static function byggPost(array $session, array $platser, array $artiklar): array
    {
        $rader = [];
        $summaForvantat = 0;
        $summaRaknat = 0;
        $avvikelser = 0;

        foreach (($session['artiklar'] ?? []) as $artikelId => $rad) {
            $a = $artiklar[$artikelId] ?? null;
            $forvantat = (int) ($rad['forvantat'] ?? 0);
            $raknat = (int) ($rad['raknat'] ?? 0);
            $diff = $raknat - $forvantat;
            $summaForvantat += $forvantat;
            $summaRaknat += $raknat;
            if ($diff !== 0) {
                $avvikelser++;
            }
            $rader[] = [
                'artikel_id' => $artikelId,
                'artikelnummer' => $a['artikelnummer'] ?? '',
                'namn' => $a['namn'] ?? '(borttagen artikel)',
                'enhet' => $a['enhet'] ?? 'st',
                'typ' => $rad['typ'] ?? 'antal',
                'forvantat' => $forvantat,
                'raknat' => $raknat,
                'differens' => $diff,
                'raknat_tid' => $rad['raknat_uppdaterad'] ?? null,
            ];
        }

        return [
            'session_id' => $session['id'] ?? '',
            'plats_id' => $session['plats_id'] ?? '',
            'plats_namn' => $platser[$session['plats_id'] ?? ''] ?? '',
            'startad' => $session['startad'] ?? null,
            'avslutad' => $session['avslutad'] ?? null,
            'startad_av' => $session['startad_av_namn'] ?? '',
            'avslutad_av' => $session['avslutad_av_namn'] ?? '',
            'avstamning' => $session['avstamning'] ?? null,
            'antal_artiklar' => count($rader),
            'antal_avvikelser' => $avvikelser,
            'summa_forvantat' => $summaForvantat,
            'summa_raknat' => $summaRaknat,
            'rader' => $rader,
        ];
    }

    /**
     * Arkivera alla avslutade sessioner som ännu inte finns i arkivet.
     * Körs automatiskt när en inventering avslutas och kan köras manuellt.
     * @return int antal nyarkiverade
     */
    public static function arkiveraAlla(?array $anvandare = null): int
    {
        $antal = 0;
        foreach (Json::las('sessioner')['poster'] as $s) {
            if (($s['status'] ?? '') !== 'avslutad') {
                continue;
            }
            $tid = $s['avslutad'] ?? $s['startad'] ?? '';
            $ar = (int) substr((string) $tid, 0, 4);
            $manad = (int) substr((string) $tid, 5, 2);
            if ($ar < 2000) {
                continue;
            }
            $manadData = self::lasManad($ar, $manad);
            $finns = false;
            foreach ($manadData['poster'] as $p) {
                if (($p['session_id'] ?? '') === ($s['id'] ?? '')) {
                    $finns = true;
                    break;
                }
            }
            if (!$finns) {
                self::arkiveraSession($s, $anvandare);
                $antal++;
            }
        }
        return $antal;
    }

    /** Hämta en arkivpost på sessions-id. */
    public static function hitta(string $sessionId): ?array
    {
        foreach (self::ar() as $ar) {
            foreach (self::manader($ar) as $manad) {
                foreach (self::lasManad($ar, $manad)['poster'] as $p) {
                    if (($p['session_id'] ?? '') === $sessionId) {
                        return $p + ['ar' => $ar, 'manad' => $manad];
                    }
                }
            }
        }
        return null;
    }

    /** Sammanställning över ett helt år: alla månader, alla platser. */
    public static function arssammanstallning(int $ar): array
    {
        $manader = [];
        $platser = [];
        $totalt = 0;
        $avvikelser = 0;

        for ($m = 1; $m <= 12; $m++) {
            $data = self::lasManad($ar, $m);
            if ($data['poster'] === []) {
                $manader[$m] = null;
                continue;
            }
            $antal = count($data['poster']);
            $avv = 0;
            $artiklar = 0;
            foreach ($data['poster'] as $p) {
                $avv += (int) ($p['antal_avvikelser'] ?? 0);
                $artiklar += (int) ($p['antal_artiklar'] ?? 0);
                $plats = $p['plats_namn'] ?? '';
                if ($plats !== '') {
                    $platser[$plats] = ($platser[$plats] ?? 0) + (int) ($p['antal_artiklar'] ?? 0);
                }
            }
            $manader[$m] = ['sessioner' => $antal, 'avvikelser' => $avv, 'artiklar' => $artiklar];
            $totalt += $antal;
            $avvikelser += $avv;
        }

        arsort($platser);
        return [
            'ar' => $ar,
            'manader' => $manader,
            'totalt' => $totalt,
            'avvikelser' => $avvikelser,
            'platser' => $platser,
        ];
    }
}
