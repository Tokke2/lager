<?php
/**
 * miljokontroll.php – read-only kontroll av webbhotellsmiljön inför Tadega Lager.
 *
 * Körs från kommandoraden på servern (rekommenderat):
 *
 *     php verktyg/miljokontroll.php
 *
 * eller via webbläsaren EFTER att appens kataloger skyddats. Skriptet skriver
 * inget, ändrar inget och visar inga hemligheter.
 *
 * Kompatibelt med PHP 7.4–8.x medvetet: det ska kunna köras innan man vet
 * vilken PHP-version webbhotellet erbjuder. Appen själv kräver 8.1+.
 *
 * Exitkod 0 = inga kritiska fel, 1 = minst ett kritiskt fel.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

const KRITISK = 'KRITISK';
const VARNING = 'VARNING';
const INFO    = 'INFO';

$rader   = [];
$kritiska = 0;

/**
 * @param string $niva   KRITISK | VARNING | INFO
 * @param string $omrade
 * @param string $text
 * @param string $varde
 */
function rad($niva, $omrade, $text, $varde = '')
{
    global $rader;
    $rader[] = [$niva, $omrade, $text, $varde];
}

function ext_ok($namn)
{
    return extension_loaded($namn);
}

/* ---------------------------------------------------------------- PHP */
$version = PHP_VERSION;
$niva = version_compare($version, '8.1.0', '>=') ? INFO : KRITISK;
rad($niva, 'PHP', 'PHP-version (appen kräver 8.1+)', $version);
if ($niva === KRITISK) {
    $kritiska++;
}
rad(INFO, 'PHP', 'SAPI', PHP_SAPI);
rad(INFO, 'PHP', 'memory_limit', (string) ini_get('memory_limit'));
rad(INFO, 'PHP', 'max_execution_time', (string) ini_get('max_execution_time'));
rad(INFO, 'PHP', 'date.timezone', (string) ini_get('date.timezone'));
rad(INFO, 'PHP', 'Tidszon (effektiv)', date_default_timezone_get());
rad(INFO, 'PHP', 'Tidszon UTC-offset', sprintf('%+d min', (int) (date('Z') / 60)));

/* ---------------------------------------------------- Nödvändiga moduler */
$krav = ['json', 'hash', 'session', 'random', 'mbstring', 'filter'];
foreach ($krav as $ext) {
    $ok = ext_ok($ext);
    rad($ok ? INFO : KRITISK, 'Extension', $ext . ' (krav)', $ok ? 'laddad' : 'SAKNAS');
    if (!$ok) {
        $kritiska++;
    }
}
$bra = ['openssl', 'zip', 'fileinfo', 'pdo', 'gd', 'intl'];
foreach ($bra as $ext) {
    $ok = ext_ok($ext);
    rad($ok ? INFO : VARNING, 'Extension', $ext . ' (valfri)', $ok ? 'laddad' : 'saknas');
}

/* ------------------------------------------------------ Sessionssäkerhet */
$sessionNycklar = [
    'session.use_strict_mode',
    'session.use_only_cookies',
    'session.cookie_httponly',
    'session.cookie_secure',
    'session.cookie_samesite',
    'session.gc_maxlifetime',
];
foreach ($sessionNycklar as $nyckel) {
    rad(INFO, 'Session', $nyckel, (string) ini_get($nyckel));
}
if (ini_get('session.use_strict_mode') !== '1') {
    rad(VARNING, 'Session', 'use_strict_mode=0 – appens bootstrap sätter 1, men notera hotellet', '');
}
if (ini_get('session.cookie_secure') !== '1') {
    rad(VARNING, 'Session', 'cookie_secure=0 – måste vara 1 i drift (HTTPS)', '');
}

/* -------------------------------------------------------- Filstorlekar */
rad(INFO, 'Filer', 'upload_max_filesize', (string) ini_get('upload_max_filesize'));
rad(INFO, 'Filer', 'post_max_size', (string) ini_get('post_max_size'));
rad(INFO, 'Filer', 'open_basedir', (string) (ini_get('open_basedir') ?: '(ingen)'));

/* ---------------------------------------------- Skrivtest i tempkatalogen */
$tmp = sys_get_temp_dir();
$skrivbar = is_dir($tmp) && is_writable($tmp);
rad($skrivbar ? INFO : KRITISK, 'Filer', 'sys_get_temp_dir() skrivbar', $tmp . ($skrivbar ? '' : ' – EJ SKRIVBAR'));
if (!$skrivbar) {
    $kritiska++;
}

if ($skrivbar) {
    // flock – avgörande för den globala skrivlåsningen
    $lf = @tempnam($tmp, 'tdl_lock_');
    if ($lf !== false) {
        $fh = @fopen($lf, 'c+');
        $flockOk = false;
        if ($fh !== false) {
            $flockOk = @flock($fh, LOCK_EX | LOCK_NB);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
        @unlink($lf);
        rad($flockOk ? INFO : KRITISK, 'Filer', 'flock() på temporär fil', $flockOk ? 'fungerar' : 'MISSLYCKAS');
        if (!$flockOk) {
            $kritiska++;
        }
    } else {
        rad(KRITISK, 'Filer', 'tempnam()', 'kan inte skapa temporär fil');
        $kritiska++;
    }

    // atomisk rename inom samma filsystem
    $a = @tempnam($tmp, 'tdl_ren_');
    if ($a !== false) {
        $b = $a . '_ny';
        @file_put_contents($a, 'x');
        $renOk = @rename($a, $b);
        rad($renOk ? INFO : VARNING, 'Filer', 'atomisk rename() i tempkatalog', $renOk ? 'fungerar' : 'MISSLYCKAS');
        @unlink($a);
        @unlink($b);
    }

    // fsync om det finns (PHP 8.1+)
    if (function_exists('fsync')) {
        rad(INFO, 'Filer', 'fsync()', 'finns (rekommenderas vid journal-skrivning)');
    } else {
        rad(VARNING, 'Filer', 'fsync()', 'saknas – journalen förlitar sig på rename() + kontrollsumma');
    }
}

/* ------------------------------------------------ Hash & slumpfunktioner */
if (function_exists('password_hash')) {
    rad(INFO, 'Säkerhet', 'password_hash', 'finns');
    if (function_exists('password_algos')) {
        rad(INFO, 'Säkerhet', 'password_algos', implode(',', password_algos()));
    } else {
        rad(INFO, 'Säkerhet', 'password_algos', 'saknas (PHP < 7.4) – använd PASSWORD_DEFAULT');
    }
} else {
    rad(KRITISK, 'Säkerhet', 'password_hash', 'SAKNAS');
    $kritiska++;
}

if (function_exists('random_bytes')) {
    try {
        $bytes = random_bytes(16);
        rad(strlen($bytes) === 16 ? INFO : KRITISK, 'Säkerhet', 'random_bytes', 'fungerar (' . strlen($bytes) . ' byte)');
    } catch (Exception $e) {
        rad(KRITISK, 'Säkerhet', 'random_bytes', 'MISSLYCKAS: ' . $e->getMessage());
        $kritiska++;
    }
} else {
    rad(KRITISK, 'Säkerhet', 'random_bytes', 'SAKNAS');
    $kritiska++;
}

if (function_exists('hash_equals')) {
    rad(INFO, 'Säkerhet', 'hash_equals', 'finns');
} else {
    rad(KRITISK, 'Säkerhet', 'hash_equals', 'SAKNAS');
    $kritiska++;
}

if (function_exists('in_array') && function_exists('hash')) {
    $algon = hash_algos();
    foreach (['sha256'] as $algo) {
        $har = in_array($algo, $algon, true);
        rad($har ? INFO : KRITISK, 'Säkerhet', 'hash-algoritm ' . $algo, $har ? 'finns' : 'SAKNAS');
        if (!$har) {
            $kritiska++;
        }
    }
}

/* -------------------------------------------------------- HTTPS och proxy */
$https = false;
if (PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    rad($https ? INFO : VARNING, 'Webb', 'HTTPS', $https ? 'ja' : 'nej – kameran kräver HTTPS');
    rad(INFO, 'Webb', 'SERVER_NAME', isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : '(okänt)');
    rad(INFO, 'Webb', 'Dokumentrot', isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '(okänd)');
    $hdr = function_exists('getallheaders') ? getallheaders() : [];
    rad(INFO, 'Webb', 'X-Forwarded-Proto (rå)', isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] : '(saknas)');
    rad(INFO, 'Webb', 'Antal request-headers', (string) (is_array($hdr) ? count($hdr) : 0));
} else {
    rad(INFO, 'Webb', 'HTTPS', 'kan inte avgöras från CLI – verifiera i webbläsare mot staging');
}

/* ------------------------------------------- Sökvägar och rättigheter (plan) */
$rot = dirname(__DIR__);
$planerade = [
    $rot . '/public',
    $rot . '/storage',
    $rot . '/storage/data',
    $rot . '/storage/locks',
    $rot . '/storage/tmp',
    $rot . '/storage/journal',
    $rot . '/storage/logg',
    $rot . '/storage/backup',
    $rot . '/bin',
];
foreach ($planerade as $katalog) {
    $finns = is_dir($katalog);
    $rattigheter = $finns ? substr(sprintf('%o', fileperms($katalog)), -4) : '–';
    $skriv = '';
    if ($finns) {
        $skriv = is_writable($katalog) ? 'skrivbar' : 'ej skrivbar';
    }
    $niva = INFO;
    if (!$finns && strpos($katalog, '/storage') !== false) {
        $niva = VARNING; // förväntat innan appen installerats
    }
    rad($niva, 'Sökväg', str_replace($rot, '.', $katalog), $finns ? "finns, $rattigheter, $skriv" : 'saknas');
}

/* ------------------------------------------------------------ Sammanställning */
$rubrik = 'Tadega Lager – miljökontroll';
$tid    = gmdate('Y-m-d H:i:s') . ' UTC';

if (PHP_SAPI === 'cli') {
    printf("%s (%s)\n%s\n", $rubrik, $tid, str_repeat('=', 104));
    $pad = function ($text, $bredd) {
        $langd = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        return $text . str_repeat(' ', max(0, $bredd - $langd));
    };
    foreach ($rader as $rad) {
        printf("[%s] %s %s %s\n", $pad($rad[0], 7), $pad($rad[1], 11), $pad($rad[2], 46), $rad[3]);
    }
    printf("%s\nKritiska fel: %d%s\n", str_repeat('-', 104), $kritiska, $kritiska === 0 ? '  (miljön kan användas)' : '  (åtgärda innan appen installeras)');
} else {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="sv"><meta charset="utf-8"><title>' . $rubrik . '</title>';
    echo '<style>body{font-family:system-ui,sans-serif;margin:24px;background:#0f172a;color:#e2e8f0}'
       . 'table{border-collapse:collapse}td,th{padding:6px 12px;border-bottom:1px solid #334155;text-align:left}'
       . '.KRITISK{color:#f87171;font-weight:700}.VARNING{color:#fbbf24}.INFO{color:#94a3b8}</style>';
    echo '<h1>' . $rubrik . '</h1><p>' . $tid . ' – kritiska fel: <strong>' . $kritiska . '</strong></p><table>';
    echo '<tr><th>Nivå</th><th>Område</th><th>Kontroll</th><th>Värde</th></tr>';
    foreach ($rader as $rad) {
        echo '<tr class="' . $rad[0] . '"><td>' . $rad[0] . '</td><td>'
           . htmlspecialchars($rad[1], ENT_QUOTES, 'UTF-8') . '</td><td>'
           . htmlspecialchars($rad[2], ENT_QUOTES, 'UTF-8') . '</td><td>'
           . htmlspecialchars($rad[3], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    echo '</table><p>Sidan skriver inget. Ta bort verktyget från webbroten när kontrollen är gjord.</p></html>';
}

exit($kritiska > 0 ? 1 : 0);
