<?php
/**
 * Backup med manifest.
 *
 *   php bin/backup.php
 *
 * Skapar storage/backup/tadega-lager-YYYYmmdd-HHMMSS.zip med datafiler,
 * artikelbilder, konfiguration (varumärke, QR-nyckel), manifest.json
 * (SHA-256 per fil + antal poster) och en README.
 * Rotation: behåller 14 dagliga och 8 veckovisa backuper.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Support\Json;
use TDL\Support\Las;

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive saknas. Installera php-zip på servern.\n");
    exit(1);
}

$tid = gmdate('Ymd-His');
$fil = TDL_BACKUP . '/tadega-lager-' . $tid . '.zip';

$manifest = ['skapad' => nu(), 'version' => TDL_VERSION, 'filer' => []];

Las::skriv();
try {
    $zip = new ZipArchive();
    if ($zip->open($fil, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Kunde inte skapa ' . $fil);
    }
    foreach (array_keys(Json::FILER) as $nyckel) {
        $sokvag = Json::sokvag($nyckel);
        if (!is_file($sokvag)) {
            continue;
        }
        $rå = (string) file_get_contents($sokvag);
        $zip->addFromString($nyckel . '.json', $rå);
        $d = json_decode($rå, true);
        $manifest['filer'][$nyckel] = [
            'fil' => $nyckel . '.json',
            'storlek' => strlen($rå),
            'sha256' => hash('sha256', $rå),
            'poster' => is_array($d) && isset($d['poster']) ? count($d['poster']) : null,
        ];
    }
    // Artikelbilder (kan vara många men små efter nedskalning)
    $bildRot = TDL_STORAGE . '/bilder';
    $antalBilder = 0;
    if (is_dir($bildRot)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bildRot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $bild) {
            if (!$bild->isFile()) {
                continue;
            }
            $rel = 'bilder/' . str_replace('\\', '/', substr($bild->getPathname(), strlen($bildRot) + 1));
            $rå = (string) file_get_contents($bild->getPathname());
            $zip->addFromString($rel, $rå);
            $manifest['filer']['bild:' . $rel] = [
                'fil' => $rel,
                'storlek' => strlen($rå),
                'sha256' => hash('sha256', $rå),
                'poster' => null,
            ];
            $antalBilder++;
        }
    }

    // Varumärke och QR-nyckel (behövs för att etiketter ska fortsätta fungera)
    // Inställningar (larm, tvåpersonersregel, backupgräns)
    $installningarFil = TDL_DATA . '/installningar.json';
    if (is_file($installningarFil)) {
        $rå = (string) file_get_contents($installningarFil);
        $zip->addFromString('installningar.json', $rå);
        $manifest['filer']['installningar'] = [
            'fil' => 'installningar.json',
            'storlek' => strlen($rå),
            'sha256' => hash('sha256', $rå),
            'poster' => null,
        ];
    }

    // Års- och månadsarkiv för inventeringar (en fil per månad)
    $arkivRot = TDL_STORAGE . '/arkiv';
    $antalArkiv = 0;
    if (is_dir($arkivRot)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($arkivRot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $filInfo) {
            if (!$filInfo->isFile() || $filInfo->getExtension() !== 'json') {
                continue;
            }
            $rel = 'arkiv/' . substr($filInfo->getPathname(), strlen($arkivRot) + 1);
            $rå = (string) file_get_contents($filInfo->getPathname());
            $zip->addFromString($rel, $rå);
            $d = json_decode($rå, true);
            $manifest['filer'][$rel] = [
                'fil' => $rel,
                'storlek' => strlen($rå),
                'sha256' => hash('sha256', $rå),
                'poster' => is_array($d) && isset($d['poster']) ? count($d['poster']) : null,
            ];
            $antalArkiv++;
        }
    }
    $manifest['antal_arkivfiler'] = $antalArkiv;

    foreach (['varumarke.json', 'artikelqr.json', 'adress.json'] as $extra) {
        $sokvag = TDL_STORAGE . '/' . $extra;
        if (!is_file($sokvag)) {
            continue;
        }
        $rå = (string) file_get_contents($sokvag);
        // QR-nyckeln får följa med i backupen – den gör annars alla etiketter ogiltiga
        $zip->addFromString('konfig/' . $extra, $rå);
        $manifest['filer']['konfig:' . $extra] = [
            'fil' => 'konfig/' . $extra,
            'storlek' => strlen($rå),
            'sha256' => hash('sha256', $rå),
            'poster' => null,
        ];
    }

    // Tryckoriginalen (logotyp, ikon, tryckark) hör till installationens varumärke.
    // De ligger i appen vid leverans, men en kund som bytt färger eller namn vill
    // kunna få tillbaka exakt det original som gällde vid backupen.
    $tryckRot = TDL_ROT . '/brand';
    $antalTryck = 0;
    if (is_dir($tryckRot)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tryckRot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $filInfo) {
            if (!$filInfo->isFile()) {
                continue;
            }
            // SVG (original) + .ico räcker: PNG-versionerna genereras om från
            // SVG med brand/exportera-ikoner.py. Det håller backupen liten.
            $andelse = strtolower($filInfo->getExtension());
            if (!in_array($andelse, ['svg', 'ico'], true)) {
                continue;
            }
            // Hoppa över appens redan kopierade ikoner – de finns i public/assets
            $rel = 'varumarke/' . substr($filInfo->getPathname(), strlen($tryckRot) + 1);
            $rå = (string) file_get_contents($filInfo->getPathname());
            $zip->addFromString($rel, $rå);
            $manifest['filer']['varumarke:' . $rel] = [
                'fil' => $rel,
                'storlek' => strlen($rå),
                'sha256' => hash('sha256', $rå),
                'poster' => null,
            ];
            $antalTryck++;
        }
    }
    $manifest['antal_varumarkesfiler'] = $antalTryck;

    $zip->addFromString('manifest.json', tdl_json($manifest) . "\n");
    $zip->addFromString("LASMIG.txt", "Tadega Lager backup " . $tid . " UTC\n"
        . "Innehåller datafiler, artikelbilder (bilder/), konfiguration (konfig/),\n"
        . "varumärkesfiler (varumarke/: logotyp, ikon och tryckoriginal)\n"
        . "och manifest.json med SHA-256 per fil.\n"
        . "Återläsning: php bin/aterlas.php --fil=" . basename($fil) . " --till-testkatalog\n"
        . "Prova återläsning med jämna mellanrum – se dokument/05_drift.md.\n");
    $zip->close();
} finally {
    Las::slapp();
}

@chmod($fil, 0600);
printf("Backup klar: %s (%d filer, %d artikelbilder)\n", $fil, count($manifest['filer']), $antalBilder);

// Rotation
$alla = glob(TDL_BACKUP . '/tadega-lager-*.zip') ?: [];
sort($alla);
$behall = 14 + 8;
if (count($alla) > $behall) {
    foreach (array_slice($alla, 0, count($alla) - $behall) as $gammal) {
        @unlink($gammal);
        echo 'Roterade bort ' . basename($gammal) . "\n";
    }
}
