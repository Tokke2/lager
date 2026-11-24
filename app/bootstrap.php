<?php
/**
 * Tadega Lager – bootstrap
 *
 * Laddas av public/index.php och av CLI-skripten i bin/.
 * Sätter alla sökvägar, felhantering och autoloading. Skriver inget själv.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Tadega Lager kräver PHP 8.1 eller senare. Den här servern kör ' . PHP_VERSION . '.');
}

define('TDL_ROT', dirname(__DIR__));
define('TDL_APP', TDL_ROT . '/app');
define('TDL_PUBLIC', TDL_ROT . '/public');
// TDL_STORAGE kan pekas om via miljövariabel (används av testkörningen).
define('TDL_STORAGE', getenv('TDL_STORAGE') ?: TDL_ROT . '/storage');
define('TDL_DATA', TDL_STORAGE . '/data');
define('TDL_LOCK', TDL_STORAGE . '/locks');
define('TDL_TMP', TDL_STORAGE . '/tmp');
define('TDL_JOURNAL', TDL_STORAGE . '/journal');
define('TDL_LOGG', TDL_STORAGE . '/logg');
define('TDL_BACKUP', TDL_STORAGE . '/backup');
define('TDL_SESSION', TDL_STORAGE . '/sessions');
define('TDL_VERSION', '1.1.0');

// Miljö: "produktion" (standard) eller "utveckling". I utvecklingsläge tillåts
// appen visas i en förhandsgranskningsram (se Support/Sakerhet::headers), så
// att den går att prova live. Sätts med miljövariabeln TDL_MILJO=utveckling
// eller automatiskt när servern körs med PHP:s inbyggda webbserver.
$tdlMiljo = getenv('TDL_MILJO');
if ($tdlMiljo === false || $tdlMiljo === '') {
    $tdlMiljo = PHP_SAPI === 'cli-server' ? 'utveckling' : 'produktion';
}
define('TDL_MILJO', $tdlMiljo);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $klass): void {
    $prefix = 'TDL\\';
    if (strncmp($klass, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = substr($klass, strlen($prefix));
    $fil = TDL_APP . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($fil)) {
        require $fil;
    }
});

require TDL_APP . '/functions.php';

// Lagringskatalogerna måste finnas innan appen kan göra något.
foreach ([TDL_STORAGE, TDL_DATA, TDL_LOCK, TDL_TMP, TDL_JOURNAL, TDL_LOGG, TDL_BACKUP, TDL_SESSION] as $katalog) {
    if (!is_dir($katalog)) {
        @mkdir($katalog, 0700, true);
    }
}
