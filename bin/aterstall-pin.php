<?php
/** Återställ en aktiv administratörs PIN från serverns kommandorad. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Endast från kommandoraden.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use TDL\Support\Konto;

$namn = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--namn=')) {
        $namn = substr($arg, 7);
    }
}
if ($namn === null || trim($namn) === '') {
    fwrite(STDERR, "Användning: php bin/aterstall-pin.php --namn=\"Namn\"\n");
    exit(1);
}
$konto = Konto::kontoViaNamn($namn);
if ($konto === null || ($konto['roll'] ?? '') !== 'admin' || empty($konto['aktiv'])) {
    fwrite(STDERR, "Ingen aktiv administratör med det namnet finns.\n");
    exit(1);
}
do {
    $pin = (string) random_int(10000000, 99999999);
} while (preg_match('/^(.)\1+$/', $pin) || password_verify($pin, (string) $konto['pin_hash']));
Konto::andraPin($konto['id'], $pin, $konto, true, true);
echo "Tillfällig PIN för {$konto['namn']}: $pin\n";
echo "Visas bara nu. Logga in och välj en egen PIN under Mitt konto.\n";
