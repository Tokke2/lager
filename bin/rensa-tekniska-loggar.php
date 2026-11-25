<?php
/** Kör dagligen med cron: php bin/rensa-tekniska-loggar.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $r = \TDL\Support\Loggstadning::rensa(TDL_LOGG);
    echo 'Raderade ' . $r['borttagna'] . ' roterade tekniska loggar; fel: ' . $r['fel'] . ".\n";
    exit($r['fel'] ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Loggstädning misslyckades: ' . $e->getMessage() . "\n"); exit(1);
}
