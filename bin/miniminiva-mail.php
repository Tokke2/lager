<?php
/**
 * E-postsammanställning: artiklar under miniminivå.
 *
 *   php bin/miniminiva-mail.php            # skickar om larmet är påslaget
 *   php bin/miniminiva-mail.php --visa     # skriver ut texten i terminalen
 *
 * Lägg i cron på webbhotellet, t.ex. 06:30 varje vardag:
 *   30 6 * * 1-5  php /hem/snickeriet/bin/miniminiva-mail.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Http\Sidor;
use TDL\Support\Installningar;

$visa = in_array('--visa', $argv, true);

$rader = Sidor::underMinimiLista();
$mottagare = trim((string) Installningar::hamta('miniminiva_mail'));
$paSlaget = (bool) Installningar::hamta('miniminiva_per_mail');

if ($rader === []) {
    if ($visa) {
        echo "Allt är över miniminivå – ingen sammanställning behövs.\n";
    }
    exit(0);
}

$namn = \TDL\Support\Varumarke::alla()['namn'] ?? 'Tadega Lager';
$datum = date('Y-m-d');

$rader = array_slice($rader, 0, 40);
$text = "Lagerstatus: artiklar under miniminivå\n";
$text .= str_repeat('=', 44) . "\n\n";
$text .= "Kontrollerat: $datum\n\n";

foreach ($rader as $r) {
    $text .= sprintf(
        "%-28s %-12s %5d %-3s  (min %d, brist %d)\n",
        mb_substr($r['artikel']['namn'], 0, 28),
        $r['plats']['namn'],
        $r['saldo'],
        $r['artikel']['enhet'],
        $r['miniminiva'],
        $r['brist']
    );
}

$text .= "\n" . count($rader) . " rader behöver fyllas på.\n";

if ($visa) {
    echo $text;
    if (!$paSlaget) {
        echo "\n[e-post avstängd – slå på under /system]\n";
    }
    exit(0);
}

if (!$paSlaget) {
    exit(0);
}
if ($mottagare === '' || !filter_var($mottagare, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Ingen giltig e-postadress inställd under /system.\n");
    exit(1);
}

$amne = mb_encode_mimeheader("$namn – " . count($rader) . ' artiklar under miniminivå', 'UTF-8');
$huvud = [
    'From' => 'lager@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'Content-Type' => 'text/plain; charset=UTF-8',
    'MIME-Version' => '1.0',
];

if (mail($mottagare, $amne, $text, $huvud)) {
    echo "Skickat till $mottagare (" . count($rader) . " rader).\n";
    exit(0);
}
fwrite(STDERR, "Kunde inte skicka. Tillåter webbhotellet mail()?\n");
exit(1);
