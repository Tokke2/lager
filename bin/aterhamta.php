<?php
/**
 * Återhämtning efter avbruten transaktion.
 *
 *   php bin/aterhamta.php          # visa läget
 *   php bin/aterhamta.php --kor    # slutför eller rullar tillbaka
 *
 * Journalen skrivs EFTER att allt validerats och innehåller det färdiga nya
 * innehållet. Återhämtningen kan därför alltid spela fram exakt den tänkta
 * transaktionen – aldrig något halvt eller påhittat. Historikposter med samma
 * txid skrivs aldrig två gånger.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Support\Historik;
use TDL\Support\Json;
use TDL\Support\Las;

$kor = in_array('--kor', $argv ?? [], true);

$journaler = Las::oppnaJournaler();
if ($journaler === []) {
    echo "Inga öppna journaler. Lagringen är ren.\n";
    exit(0);
}

echo count($journaler) . " öppen journal(er):\n";
foreach ($journaler as $fil) {
    echo '  – ' . basename($fil) . "\n";
}
if (!$kor) {
    echo "\nKör med --kor för att slutföra transaktionerna.\n";
    exit(1);
}

Las::skriv();
$fel = 0;
foreach ($journaler as $fil) {
    $j = json_decode((string) file_get_contents($fil), true);
    if (!is_array($j) || empty($j['txid'])) {
        echo 'HOPPAR: ' . basename($fil) . " är oläsbar. Granska manuellt.\n";
        $fel++;
        continue;
    }
    echo 'Slutför ' . $j['txid'] . ' (' . ($j['typ'] ?? '?') . ") …\n";
    try {
        foreach (($j['nya_filer'] ?? []) as $nyckel => $innehall) {
            Json::skriv($nyckel, $innehall);
            echo '  skrev ' . $nyckel . '.json' . "\n";
        }
        $poster = $j['historik_poster'] ?? [];
        if ($poster !== []) {
            $historik = Json::las('historik');
            if (!Historik::txidFinns($historik, $j['txid'])) {
                Historik::appenda($historik, $poster, $j['anvandare'] ?? null, $j['txid']);
                Json::skriv('historik', $historik);
                echo '  lade till ' . count($poster) . " historikpost(er)\n";
            } else {
                echo "  historikposterna fanns redan – hoppar över\n";
            }
        }
        unlink($fil);
        echo "  klar\n";
    } catch (Throwable $e) {
        echo '  FEL: ' . $e->getMessage() . "\n";
        $fel++;
    }
}
Las::slapp();

exit($fel > 0 ? 1 : 0);
