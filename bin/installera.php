<?php
/**
 * Engångsinstallation av Tadega Lager.
 *
 *   php bin/installera.php --namn="Anna" --pin=4821 --foretag="Nordiska Bygg AB"
 *   php bin/installera.php --namn="Anna" --pin=4821 --losenord="…" --med-demodata
 *
 * Administratörens lösenord anges med --losenord. Utelämnas det skapas ett
 * slumpat engångslösenord som skrivs ut en enda gång i terminalen – aldrig
 * sparat i klartext och aldrig förvalt i koden.
 *
 * Namn och PIN väljs här – de är inte tillfälliga och behöver inte bytas
 * efter första inloggningen. Företagsnamnet sätts med --foretag (eller
 * senare under Mer → Varumärke).
 *
 * Skapar:
 *   – datafilerna (tomma om de saknas – befintliga rörs aldrig)
 *   – första administratören (fungerar bara när inga konton finns)
 *   – startplatserna Örebro, Laxå och systemposten SYSTEM_LEGACY
 *   – med --med-demodata: ett par exempelartiklar för test i staging
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Domain\Lager;
use TDL\Support\AppFel;
use TDL\Support\Konto;
use TDL\Support\Varumarke;
use TDL\Support\Koder;
use TDL\Support\Las;
use TDL\Support\Transaktion;

$argvOpt = $argv ?? [];
$namn = null;
$pin = null;
$losenord = null;
$foretag = null;
$medDemo = false;

foreach (array_slice($argvOpt, 1) as $arg) {
    if (str_starts_with($arg, '--namn=')) {
        $namn = substr($arg, 7);
    } elseif (str_starts_with($arg, '--pin=')) {
        $pin = substr($arg, 6);
    } elseif (str_starts_with($arg, '--losenord=')) {
        $losenord = substr($arg, 11);
    } elseif (str_starts_with($arg, '--foretag=')) {
        $foretag = trim(substr($arg, 10));
    } elseif ($arg === '--med-demodata') {
        $medDemo = true;
    } elseif ($arg === '--hjalp' || $arg === '-h') {
        echo "Användning: php bin/installera.php --namn=\"Anna\" --pin=4821 "
            . "[--foretag=\"Firma AB\"] [--losenord=\"…\"] [--med-demodata]\n";
        exit(0);
    }
}

echo "Tadega Lager – installation\n===========================\n";

$skapade = Lager::initieraFiler();
echo $skapade === []
    ? "Datafiler fanns redan – inga skapades (befintliga rörs aldrig).\n"
    : 'Skapade datafiler: ' . implode(', ', $skapade) . "\n";

if (Konto::behoverForstaAdmin()) {
    if ($namn === null || $pin === null) {
        echo "Inga konton finns. Ange --namn och --pin för att skapa första administratören.\n";
        exit(1);
    }
    try {
        // PIN-koden anges här av administratören själv – ingen tillfällig kod.
        $konto = Konto::skapaForstaAdmin($namn, $pin, false);
        $forstaAdmin = $konto;
        echo 'Första administratören skapad: ' . $konto['namn'] . ' (' . $konto['id'] . ")\n";
        echo "Dela aldrig PIN-koden i klartext. Den kan bytas under Mitt konto.\n";

        // Admin måste ha ett lösenord utöver PIN-koden. Utan det går det
        // inte att logga in alls – därför sätts det redan här.
        $genererat = false;
        $valt = $losenord;
        if ($valt === null || trim($valt) === '') {
            // 20 tecken, bokstäver + siffror, skapas i stunden och skrivs
            // bara ut en gång. Inget lösenord finns i källkoden.
            $valt = 'Tdl-' . bin2hex(random_bytes(6)) . '-' . random_int(1000, 9999);
            $genererat = true;
        }
        try {
            Konto::andraLosenord($konto['id'], $valt, '', $konto, true);
            if ($genererat) {
                echo "\nEngångslösenord för $konto[namn]: $valt\n";
                echo "Skriv ner det nu – det visas inte igen. Byt till ett eget lösenord\n";
                echo "under Mitt konto → Byt lösenord vid första inloggningen.\n\n";
            }
        } catch (AppFel $e) {
            echo 'Kunde inte sätta lösenord: ' . $e->getMessage() . "\n";
            echo "Kör om med --losenord=\"...\" (minst " . \TDL\Support\Konto::LÖSENORD_MIN . " tecken, bokstäver och siffror).\n";
        }
        if ($foretag !== null && trim($foretag) !== '') {
            Varumarke::spara(['namn' => trim($foretag), 'kortnamn' => trim($foretag)], $konto);
            echo 'Företagsnamn satt: ' . trim($foretag) . "\n";
        } else {
            echo "Företagsnamn: sätts under Mer → Varumärke (eller --foretag=\"Namn\").\n";
        }
    } catch (AppFel $e) {
        echo 'Fel: ' . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "Konton finns redan – första administratören hoppas över.\n";
}

// Startplatser – skapas bara om platslistan är tom.
Las::under(function () use ($medDemo) {
    $platser = \TDL\Support\Json::las('platser');
    if ($platser['poster'] !== []) {
        echo "Arbetsställen finns redan – inga startplatser skapades.\n";
        return;
    }
    $nya = [
        ['Örebro', 'ORE', false],
        ['Laxå', 'LAX', false],
        ['Äldre saldo (ofördelat)', 'SYS', true],
    ];
    foreach ($nya as [$namnPlats, $kod, $system]) {
        $plats = [
            'id' => Koder::interntId('P', 4),
            'namn' => $namnPlats,
            'kod' => $kod,
            'qr' => Koder::nyKod('L'),
            'system' => $system,
            'aktiv' => true,
            'skapad' => nu(),
        ];
        $platser['poster'][] = $plats;
        echo 'Arbetsställe: ' . $namnPlats . ' (' . $plats['id'] . '), arbetsplats-QR ' . Koder::visa($plats['qr']) . "\n";
    }
    $tx = new Transaktion('plats_skapad', null);
    $tx->sattFil('platser', $platser);
    foreach ($platser['poster'] as $p) {
        $tx->historik([
            'typ' => 'plats_skapad',
            'plats_id' => $p['id'],
            'plats_namn' => $p['namn'],
            'detaljer' => ['qr' => $p['qr'], 'system' => $p['system'], 'installation' => true],
        ]);
    }
    if ($medDemo) {
        $artiklar = \TDL\Support\Json::las('artiklar');
        if ($artiklar['poster'] === []) {
            $demo = [
                ['Skruvdragare', 'DEMO-001', 'st', 'qr', 1],
                ['Skruv 4x40', 'DEMO-002', 'st', 'antal', 100],
            ];
            foreach ($demo as [$dNamn, $dNr, $dEnhet, $dSpar, $dMin]) {
                $a = [
                    'id' => Koder::interntId('A', 6),
                    'artikelnummer' => $dNr,
                    'namn' => $dNamn,
                    'enhet' => $dEnhet,
                    'prisreferens' => null,
                    'miniminiva' => $dMin,
                    'sparningssatt' => $dSpar,
                    'saldo' => [],
                    'aktiv' => true,
                    'skapad' => nu(),
                ];
                $artiklar['poster'][] = $a;
                echo 'Demoartikel: ' . $dNamn . "\n";
            }
            $tx->sattFil('artiklar', $artiklar);
        }
    }
    $tx->spara();
});

echo "\nKlart. Öppna appen i webbläsaren och logga in.\n";
echo "Kontrollera att storage/ inte går att nå via URL (test SÄK-01).\n";
