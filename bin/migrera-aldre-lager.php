<?php
/**
 * Migrering av äldre aggregerat lager (t.ex. portalens lager.json).
 *
 *   php bin/migrera-aldre-lager.php --kalla=/sökväg/till/lager.json --torrkorning
 *   php bin/migrera-aldre-lager.php --kalla=… --importera --ja-jag-har-backup --ja-jag-vet-vad-jag-gor
 *
 * Regler:
 *   – Källfilen läses ENDAST. Den ändras aldrig.
 *   – Torrkörning är standard: inget skrivs.
 *   – Aggregerat saldo utan känd plats hamnar på systemposten SYSTEM_LEGACY
 *     och varje rad får en historikpost "migrering_import".
 *   – Ingen artikel skapas med samma artikelnummer två gånger.
 *   – Kräver uttryckliga flaggor för att skriva.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Domain\Lager;
use TDL\Support\Json;
use TDL\Support\Koder;
use TDL\Support\Las;
use TDL\Support\Transaktion;

$opt = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}

$kalla = $opt['kalla'] ?? null;
if (!$kalla || !is_file($kalla)) {
    fwrite(STDERR, "Ange --kalla=<sökväg till äldre lager.json>\n");
    exit(1);
}

$rå = (string) file_get_contents($kalla);
$data = json_decode($rå, true);
if (!is_array($data)) {
    fwrite(STDERR, "Källfilen är inte giltig JSON. Migreringen avbryts.\n");
    exit(1);
}

// Stöd två vanliga format: {"artikelnamn": antal} eller [{"namn":…, "antal":…}]
$rader = [];
if (array_is_list($data)) {
    foreach ($data as $post) {
        if (is_array($post)) {
            $namn = (string) ($post['namn'] ?? $post['artikel'] ?? $post['artikelnamn'] ?? '');
            $antal = (int) ($post['antal'] ?? $post['saldo'] ?? 0);
            if ($namn !== '') {
                $rader[] = ['namn' => $namn, 'antal' => $antal, 'nummer' => (string) ($post['artikelnummer'] ?? $post['nummer'] ?? '')];
            }
        }
    }
} else {
    foreach ($data as $namn => $varde) {
        if (is_numeric($varde)) {
            $rader[] = ['namn' => (string) $namn, 'antal' => (int) $varde, 'nummer' => ''];
        }
    }
}

if ($rader === []) {
    fwrite(STDERR, "Kunde inte tolka några rader i källfilen.\n");
    exit(1);
}

echo "Källa: $kalla\n";
echo 'SHA-256: ' . hash_file('sha256', $kalla) . "\n";
echo count($rader) . " rader funna.\n\n";

// Matcha mot befintliga artiklar
$befintliga = Lager::artiklar();
$planRad = [];
foreach ($rader as $rad) {
    $match = null;
    foreach ($befintliga as $a) {
        if ($rad['nummer'] !== '' && mb_strtolower($a['artikelnummer']) === mb_strtolower($rad['nummer'])) {
            $match = $a;
            break;
        }
        if (mb_strtolower($a['namn']) === mb_strtolower($rad['namn'])) {
            $match = $a;
            break;
        }
    }
    $planRad[] = $rad + ['match' => $match];
    printf("  %-30s %6d  %s\n", mb_substr($rad['namn'], 0, 30), $rad['antal'],
        $match === null ? 'SKAPAS SOM NY ARTIKEL (antal) på SYSTEM_LEGACY' : 'matchar ' . $match['artikelnummer']);
}

if (isset($opt['torrkorning']) || !isset($opt['importera'])) {
    echo "\nDetta är en TORRKÖRNING. Inget har skrivits.\n";
    echo "Importera med: --importera --ja-jag-har-backup --ja-jag-vet-vad-jag-gor\n";
    exit(0);
}

if (!isset($opt['ja-jag-har-backup']) || !isset($opt['ja-jag-vet-vad-jag-gor'])) {
    fwrite(STDERR, "Import kräver --ja-jag-har-backup och --ja-jag-vet-vad-jag-gor.\n");
    exit(1);
}

Las::under(function () use ($planRad, $kalla) {
    $tx = new Transaktion('migrering_import', null);
    $artiklar = $tx->fil('artiklar');
    $platser = $tx->fil('platser');
    $system = null;
    foreach ($platser['poster'] as $p) {
        if (!empty($p['system'])) {
            $system = $p;
            break;
        }
    }
    if ($system === null) {
        throw new RuntimeException('Systemposten för äldre saldo saknas. Kör installationen först.');
    }
    $importerade = 0;
    foreach ($planRad as $rad) {
        $artikel = $rad['match'];
        if ($artikel === null) {
            $artikel = [
                'id' => Koder::interntId('A', 6),
                'artikelnummer' => $rad['nummer'] !== '' ? $rad['nummer'] : 'MIG-' . strtoupper(Koder::slump(5)),
                'namn' => $rad['namn'],
                'enhet' => 'st',
                'prisreferens' => null,
                'miniminiva' => 0,
                'sparningssatt' => 'antal',
                'saldo' => [$system['id'] => 0],
                'aktiv' => true,
                'skapad' => nu(),
                'migrerad' => true,
            ];
            Json::laggTill($artiklar, $artikel);
        }
        if ($artikel['sparningssatt'] !== 'antal') {
            throw new RuntimeException('Artikeln ' . $artikel['namn'] . ' är QR-baserad och kan inte ta aggregerat saldo. Hantera manuellt.');
        }
        Json::uppdatera($artiklar, $artikel['id'], function ($a) use ($rad, $system) {
            $a['saldo'][$system['id']] = (int) ($a['saldo'][$system['id']] ?? 0) + (int) $rad['antal'];
            return $a;
        });
        $importerade++;
        $tx->historik([
            'typ' => 'migrering_import',
            'artikel_id' => $artikel['id'],
            'artikelnummer' => $artikel['artikelnummer'],
            'artikelnamn' => $artikel['namn'],
            'plats_id' => $system['id'],
            'plats_namn' => $system['namn'],
            'antal' => (int) $rad['antal'],
            'detaljer' => ['kalla' => basename($kalla), 'kalla_sha256' => hash_file('sha256', $kalla)],
        ]);
    }
    $tx->sattFil('artiklar', $artiklar);
    $tx->spara();
    echo "Importerade $importerade rader till " . $system['namn'] . ".\n";
    echo "Kör bin/verifiera-historik.php och jämför summorna tvåvägs mot källan.\n";
});
