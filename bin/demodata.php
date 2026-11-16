<?php
/**
 * Demodata för staging/test – kör ALDRIG i drift med riktiga data.
 *
 *   php bin/demodata.php --ja-jag-vet-att-detta-ar-test
 *
 * Skapar: en QR-artikel med 6 etiketter, inleverans av 4 till Örebro,
 * 8 st skruv inlevererat till Laxå och ett uttag – så att appens alla vyer
 * har innehåll att visa upp.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Domain\Lager;
use TDL\Domain\Rorelser;
use TDL\Support\AppFel;
use TDL\Support\Konto;

if (!in_array('--ja-jag-vet-att-detta-ar-test', $argv ?? [], true)) {
    fwrite(STDERR, "Detta skapar data. Kör med --ja-jag-vet-att-detta-ar-test om du är säker.\n");
    exit(1);
}

$konton = Konto::anvandare();
if ($konton === []) {
    fwrite(STDERR, "Inga konton finns. Kör bin/installera.php först.\n");
    exit(1);
}
$admin = null;
foreach ($konton as $k) {
    if ($k['roll'] === 'admin' && !empty($k['aktiv'])) {
        $admin = $k;
        break;
    }
}
if ($admin === null) {
    fwrite(STDERR, "Ingen aktiv administratör finns.\n");
    exit(1);
}

$platser = [];
foreach (Lager::platser(true) as $p) {
    if (empty($p['system'])) {
        $platser[$p['kod']] = $p;
    }
}
if (count($platser) < 2) {
    fwrite(STDERR, "Det behövs minst två aktiva arbetsställen.\n");
    exit(1);
}
$orebro = $platser['ORE'] ?? array_values($platser)[0];
$laxa = $platser['LAX'] ?? array_values($platser)[1];

echo "Demodata med " . $admin['namn'] . " som ansvarig\n";

try {
    $drill = Lager::skapaArtikel([
        'namn' => 'Skruvdragare (demo)',
        'artikelnummer' => 'DEMO-100',
        'enhet' => 'st',
        'prisreferens' => '2495',
        'miniminiva' => '1',
        'sparningssatt' => 'qr',
    ], $admin);
    echo 'Artikel: ' . $drill['namn'] . "\n";

    $etiketter = Rorelser::skapaEtiketter($drill['id'], 6, $admin);
    echo 'Etiketter: ' . count($etiketter) . "\n";

    $koder = array_map(fn ($e) => $e['id'], array_slice($etiketter, 0, 4));
    Rorelser::inleverans($drill['id'], $orebro['id'], 0, $koder, $admin, 'Demoinleverans');
    echo 'Inlevererat 4 till ' . $orebro['namn'] . "\n";

    Rorelser::overforing($drill['id'], $orebro['id'], $laxa['id'], 0, [$koder[0]], $admin, 'Demoöverföring');
    echo "Överfört 1 till " . $laxa['namn'] . " (samma enhets-ID)\n";

    $skruv = Lager::skapaArtikel([
        'namn' => 'Skruv 4x40 (demo)',
        'artikelnummer' => 'DEMO-101',
        'enhet' => 'st',
        'prisreferens' => '',
        'miniminiva' => '50',
        'sparningssatt' => 'antal',
    ], $admin);
    Rorelser::inleverans($skruv['id'], $laxa['id'], 500, [], $admin, 'Demoinleverans');
    Rorelser::uttag($skruv['id'], $laxa['id'], 120, [], $admin, 'Demouttag');
    echo "Skruv: 500 in, 120 ut på " . $laxa['namn'] . "\n";

    $session = \TDL\Domain\Inventering::startaInventering($orebro['id'], $drill['id'], $admin);
    \TDL\Domain\Inventering::skanna($session['id'], $koder[1], $admin);
    \TDL\Domain\Inventering::skanna($session['id'], $koder[2], $admin);
    echo "Demoinventering " . $session['id'] . ": 2 av 3 räknade (1 medvetet saknad)\n";
} catch (AppFel $e) {
    fwrite(STDERR, 'Fel: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Klart. Öppna appen och titta på översikten, artiklarna och inventeringen.\n";
