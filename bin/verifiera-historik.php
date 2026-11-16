<?php
/**
 * Verifierar historikens hash-kedja.
 *
 *   php bin/verifiera-historik.php
 *
 * Exitkod 0 = hela kedjan är intakt, 1 = avvikelse hittad.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Support\Historik;
use TDL\Support\Json;

$historik = Json::las('historik');
$fel = Historik::verifiera($historik);
$antal = count($historik['poster']);

echo "Tadega Lager – historikkontroll\n";
echo "Poster: $antal\n";

if ($fel === []) {
    echo "Kedjan är intakt.\n";
    exit(0);
}

echo "AVVIKELSER (" . count($fel) . "):\n";
foreach (array_slice($fel, 0, 50) as $rad) {
    echo '  – ' . $rad . "\n";
}
exit(1);
