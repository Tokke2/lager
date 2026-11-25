<?php
/** Kör separat: php tests/tillgangstest.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Körs från kommandoraden.\n");
putenv('TDL_MILJO=utveckling');
putenv('TDL_STORAGE=' . sys_get_temp_dir() . '/tdl-tillgang-test-' . bin2hex(random_bytes(4)));
require dirname(__DIR__) . '/app/bootstrap.php';
session_id('testsession1234567890');
$url = tdl_tillgang('assets/app.js');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
if (substr_count($url, '?') !== 1 || ($query['tdlagersid'] ?? '') !== session_id()
    || ($query['v'] ?? '') !== (string) filemtime(TDL_PUBLIC . '/assets/app.js')) {
    fwrite(STDERR, "Fel versions- eller sessionsparameter: $url\n");
    exit(1);
}
echo "OK: versions- och sessionsparametrar fungerar samtidigt.\n";
