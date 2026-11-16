<?php
/**
 * Nödskript: sätt nytt lösenord för en administratör.
 *
 * Används när den enda admin har glömt sitt lösenord och ingen annan admin
 * finns. Måste köras på servern (kommer åt storage/).
 *
 *   php bin/aterstall-losenord.php                    # lista admins
 *   php bin/aterstall-losenord.php --namn="Anna Admin"  # sätt nytt lösenord
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Endast från kommandoraden.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use TDL\Support\Konto;

$namn = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--namn' && isset($argv[$i + 1])) {
        $namn = $argv[$i + 1];
    } elseif (str_starts_with($arg, '--namn=')) {
        $namn = substr($arg, 7);
    }
}

$admins = array_values(array_filter(Konto::anvandare(), fn ($u) => ($u['roll'] ?? '') === 'admin'));

if ($namn === null) {
    echo "Administratörer:\n";
    foreach ($admins as $a) {
        $dagar = Konto::dagarKvarPaLosenord($a);
        $status = $dagar === null ? 'inget lösenord' : ($dagar <= 0 ? 'UTGÅNGET' : $dagar . ' dagar kvar');
        echo '  - ' . $a['namn'] . ' (' . $status . ")\n";
    }
    echo "\nSätt nytt lösenord: php bin/aterstall-losenord.php --namn=\"Namn\"\n";
    exit(0);
}

$konto = Konto::kontoViaNamn($namn);
if ($konto === null || ($konto['roll'] ?? '') !== 'admin') {
    fwrite(STDERR, "Hittar ingen administratör som heter \"$namn\".\n");
    exit(1);
}

// Slumpa ett starkt lösenord: bokstäver + siffror, 16 tecken
$bokstaver = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
$siffror = '23456789';
$losenord = '';
for ($i = 0; $i < 12; $i++) {
    $losenord .= $bokstaver[random_int(0, strlen($bokstaver) - 1)];
}
for ($i = 0; $i < 4; $i++) {
    $losenord .= $siffror[random_int(0, strlen($siffror) - 1)];
}
$losenord = str_shuffle($losenord);

Konto::andraLosenord($konto['id'], $losenord, '', $konto, true);

echo "Nytt lösenord för {$konto['namn']}:\n\n";
echo "    $losenord\n\n";
echo "Skriv upp det nu – det visas bara en gång. Logga in och byt det direkt.\n";
