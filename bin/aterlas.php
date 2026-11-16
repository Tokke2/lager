<?php
/**
 * Återläsning av backup MED verifiering.
 *
 *   php bin/aterlas.php --fil=storage/backup/tadega-lager-20261112-101500.zip --till-testkatalog
 *   php bin/aterlas.php --fil=… --skriv-till-drift   (kräver dessutom --ja-jag-vet-vad-jag-gor)
 *
 * Verifierar mot manifestet INNAN något skrivs:
 *   – alla filer finns i zippen
 *   – SHA-256 stämmer
 *   – antal poster stämmer
 *   – historikens hash-kedja är intakt
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Support\Historik;
use TDL\Support\Json;
use TDL\Support\Las;

$opt = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}

$fil = $opt['fil'] ?? null;
if (!$fil || !is_file($fil)) {
    fwrite(STDERR, "Ange --fil=storage/backup/<backup>.zip\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($fil) !== true) {
    fwrite(STDERR, "Kan inte öppna zippen.\n");
    exit(1);
}

$manifestRå = $zip->getFromName('manifest.json');
if ($manifestRå === false) {
    fwrite(STDERR, "Zippen saknar manifest.json – vägrar återläsa.\n");
    exit(1);
}
$manifest = json_decode($manifestRå, true);
if (!is_array($manifest) || empty($manifest['filer'])) {
    fwrite(STDERR, "manifest.json är oläsbart.\n");
    exit(1);
}

$problem = [];
$innehall = [];
$binarfiler = [];   // artikelbilder (bilder/...) och konfiguration (konfig/...)
$arkivet = [];      // arkiv/<år>/<år>-<månad>.json
$installningar = null;
foreach ($manifest['filer'] as $nyckel => $info) {
    $rå = $zip->getFromName($info['fil']);
    if ($rå === false) {
        $problem[] = $info['fil'] . ' saknas i zippen';
        continue;
    }
    if (hash('sha256', $rå) !== $info['sha256']) {
        $problem[] = $info['fil'] . ': SHA-256 stämmer inte';
        continue;
    }

    // Arkivfiler har annan struktur (ar/manad/poster) och hanteras separat
    if (str_starts_with($nyckel, 'arkiv/')) {
        $arkivet[$info['fil']] = $rå;
        continue;
    }
    if ($nyckel === 'installningar') {
        $installningar = $rå;
        continue;
    }

    // Binarfiler och konfiguration verifieras bara med checksumman
    if (str_starts_with($nyckel, 'bild:') || str_starts_with($nyckel, 'konfig:')) {
        $binarfiler[$info['fil']] = $rå;
        continue;
    }

    $d = json_decode($rå, true);
    if (!is_array($d) || !isset($d['poster'])) {
        $problem[] = $info['fil'] . ': ogiltig struktur';
        continue;
    }
    if ($info['poster'] !== null && count($d['poster']) !== $info['poster']) {
        $problem[] = $info['fil'] . ': antal poster ' . count($d['poster']) . ' ≠ ' . $info['poster'];
        continue;
    }
    $innehall[$nyckel] = $d;
}
$zip->close();

if (isset($innehall['historik'])) {
    $fel = Historik::verifiera($innehall['historik']);
    if ($fel !== []) {
        $problem[] = 'historik: kedjan är bruten (' . count($fel) . ' avvikelser)';
    }
}

if ($problem !== []) {
    echo "BACKUPEN ÄR INTE GODKÄND:\n";
    foreach ($problem as $p) {
        echo '  – ' . $p . "\n";
    }
    exit(1);
}

foreach (array_keys($arkivet) as $rel) {
    echo '  ' . $rel . "\n";
}
if ($installningar !== null) {
    echo "  installningar.json\n";
}
echo "Manifest och checksummor OK. " . count($innehall) . " datafiler verifierade";
if ($binarfiler !== []) {
    echo ', ' . count($binarfiler) . ' bild-/konfigurationsfiler';
}
echo ".\n";
foreach ($innehall as $nyckel => $d) {
    echo '  ' . $nyckel . '.json: ' . count($d['poster']) . " poster\n";
}

if (isset($opt['till-testkatalog'])) {
    $mål = TDL_STORAGE . '/aterlasning-' . gmdate('Ymd-His');
    @mkdir($mål, 0700, true);
    foreach ($innehall as $nyckel => $d) {
        file_put_contents($mål . '/' . $nyckel . '.json', tdl_json($d));
    }
    foreach ($binarfiler as $rel => $rå) {
        $mal = $mål . '/' . $rel;
        @mkdir(dirname($mal), 0700, true);
        file_put_contents($mal, $rå);
    }
    foreach ($arkivet as $rel => $rå) {
        if (!preg_match('#^arkiv/(\d{4})/(\d{4})-(\d{2})\.json$#', $rel)) {
            continue;
        }
        $mal = $mål . '/' . $rel;
        @mkdir(dirname($mal), 0700, true);
        file_put_contents($mal, $rå);
    }
    if ($installningar !== null) {
        file_put_contents($mål . '/installningar.json', $installningar);
    }
    echo "Återläst till $mål (driftdata orörd). Jämför gärna med en separat kontroll.\n";
    exit(0);
}

if (isset($opt['skriv-till-drift'])) {
    if (!isset($opt['ja-jag-vet-vad-jag-gor'])) {
        echo "VÄGRAR: skrivning till drift kräver både --skriv-till-drift och --ja-jag-vet-vad-jag-gor.\n";
        exit(1);
    }
    // Säkerhetskopia INNAN återläsning.
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/backup.php'), $kod);
    if ($kod !== 0) {
        echo "Säkerhetskopiering misslyckades – avbryter.\n";
        exit(1);
    }
    Las::under(function () use ($innehall) {
        foreach ($innehall as $nyckel => $d) {
            Json::skriv($nyckel, $d);
        }
    });

    // Artikelbilder och konfiguration skrivs efter datafilerna
    $antalFiler = 0;
    foreach ($binarfiler as $rel => $rå) {
        // Endast kända undermappar – skydda mot sökvägar i zippen
        if (!preg_match('#^(bilder/[A-Z0-9]+/[a-z0-9]+\.(jpg|png|gif|webp)|konfig/(varumarke|artikelqr|adress)\.json)$#', $rel)) {
            echo 'Hoppar över okänd fil i zippen: ' . $rel . "\n";
            continue;
        }
        $mal = TDL_STORAGE . '/' . $rel;
        @mkdir(dirname($mal), 0700, true);
        file_put_contents($mal, $rå);
        @chmod($mal, 0600);
        $antalFiler++;
    }
    if ($antalFiler > 0) {
        echo $antalFiler . " bild-/konfigurationsfiler återställda.\n";
    }

    // Års- och månadsarkiv
    $antalArkiv = 0;
    foreach ($arkivet as $rel => $rå) {
        if (!preg_match('#^arkiv/(\d{4})/(\d{4})-(\d{2})\.json$#', $rel, $m)) {
            echo 'Hoppar över okänd arkivfil: ' . $rel . "\n";
            continue;
        }
        $mal = TDL_STORAGE . '/' . $rel;
        @mkdir(dirname($mal), 0700, true);
        file_put_contents($mal, $rå);
        @chmod($mal, 0600);
        $antalArkiv++;
    }
    if ($antalArkiv > 0) {
        echo $antalArkiv . " arkivmånader återställda.\n";
    }

    // Inställningar
    if ($installningar !== null) {
        file_put_contents(TDL_DATA . '/installningar.json', $installningar);
        @chmod(TDL_DATA . '/installningar.json', 0600);
        echo "Inställningarna återställda.\n";
    }
    echo "Återläsning till drift klar. Kör bin/verifiera-historik.php.\n";
    exit(0);
}

echo "Torrkörning klar. Ange --till-testkatalog eller --skriv-till-drift för att återläsa.\n";
exit(0);
