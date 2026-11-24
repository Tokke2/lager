<?php
/**
 * Tadega Lager – front controller.
 * Enda filen som webbhotellet behöver exponera (public/ är webbrot).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Support\Auth;
use TDL\Support\Sakerhet;
use TDL\Support\Svar;
use TDL\Support\AppFel;
use TDL\Domain\Lager;

Sakerhet::headers();

// PHP:s inbyggda server (skrivbordsappen) skickar ALLT genom index.php.
// Låt befintliga statiska filer i public/ serveras direkt här.
$sokvag = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server') {
    $fil = realpath(__DIR__ . $sokvag);
    if ($fil !== false && str_starts_with($fil, realpath(__DIR__)) && is_file($fil)) {
        $typer = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'webmanifest' => 'application/manifest+json',
        ];
        $andelse = strtolower(pathinfo($fil, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($typer[$andelse] ?? 'application/octet-stream'));

        // Versionsmärkta filer (?v=) kan cachas i evighet – byts filen ut
        // ändras versionen. Service workern får aldrig lång cache: då hade
        // en ny version av appen kunnat fastna hos användaren.
        $arVersionerad = isset($_GET['v']);
        if ($andelse === 'js' && basename($fil) === 'sw.js') {
            header('Cache-Control: no-cache');
        } elseif ($arVersionerad) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: public, max-age=86400');
        }

        // Textfiler komprimeras när klienten accepterar det. Sparar 70–80 %
        // av överföringen för CSS/JS och gör första sidan märkbart snabbare
        // på dålig täckning. (Apache/nginx gör samma sak i skarp drift.)
        if (in_array($andelse, ['css', 'js', 'svg', 'webmanifest'], true)
            && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')
            && filesize($fil) > 1024
            && function_exists('gzencode')) {
            $komprimerad = gzencode((string) file_get_contents($fil), 6);
            if ($komprimerad !== false) {
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
                header('Content-Length: ' . strlen($komprimerad));
                echo $komprimerad;
                exit;
            }
        }

        readfile($fil);
        exit;
    }
}

Auth::startaSession();

$rutt = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Ligger appen i en undermapp (/lager) måste den delen bort innan routningen:
// annars blir "/lager/login" ett okänt "/lager/login" i stället för "/login".
$bas = \TDL\Support\Bas::vard();
if ($bas !== '' && $rutt !== '/') {
    if ($rutt === $bas) {
        $rutt = '/';
    } elseif (str_starts_with($rutt, $bas . '/')) {
        $rutt = substr($rutt, strlen($bas));
    }
}
$metod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Kör inte med en avbruten transaktion i journalen.
if (\TDL\Support\Las::oppnaJournaler() !== []) {
    http_response_code(503);
    echo 'Lagringen är i återhämtningsläge. Kör bin/aterhamta.php på servern.';
    exit;
}

// PWA-filerna måste gå att hämta även innan appen är installerad –
// annars kan webbläsaren inte erbjuda "Lägg till på hemskärmen".
if ($metod === 'GET' && in_array($rutt, ['/site.webmanifest', '/sw.js', '/offline', '/assets/ikoner/ikon-256.png', '/assets/ikoner/ikon-512.png'], true)) {
    match ($rutt) {
        '/site.webmanifest' => \TDL\Http\Sidor::manifest(),
        '/sw.js' => \TDL\Http\Sidor::serviceWorker(),
        '/offline' => \TDL\Http\Sidor::offline(),
        default => null,
    };
}

// Admin med utgånget lösenord måste byta det innan något annat kan göras.
if (Lager::arInstallerad()) {
    $inloggad = \TDL\Support\Auth::inloggad();

    // Tillfällig PIN (första inloggningen, nytt konto eller återaktivering)
    // måste bytas innan appen går att använda. Tidigare visades bara en
    // upplysning – den gick att klicka bort, och då fortsatte kontot med en
    // PIN som någon annan hade satt. Nu är det en spärr.
    if ($inloggad !== null
        && !empty($inloggad['maste_byta_pin'])
        && !in_array($rutt, ['/mitt-konto', '/mitt-konto/pin', '/logout'], true)) {
        flash('info', 'Välj en egen PIN-kod innan du fortsätter.');
        omdirigera('/mitt-konto');
    }

    if ($inloggad !== null
        && \TDL\Support\Konto::losenordUtgatt($inloggad)
        && !in_array($rutt, ['/mitt-konto/losenord', '/logout'], true)) {
        omdirigera('/mitt-konto/losenord');
    }
}

// Första gången: skapa datafiler om de saknas och skicka till installationen.
//
// Villkoret måste vara "inga konton", inte "ingen fil": en tom anvandare.json
// (som följer med releasen, eller som blir kvar efter en rensning) räknades
// annars som installerad. Då visades inloggningssidan med tom namnlista i
// stället för guiden där admin väljer namn och PIN – och appen gick inte att
// komma in i alls.
if (!Lager::arInstallerad() || \TDL\Support\Konto::behoverForstaAdmin()) {
    Lager::initieraFiler();
    if ($rutt !== '/installera') {
        omdirigera('/installera');
    }
}

try {
    match (true) {
        $rutt === '/' => \TDL\Http\Sidor::startsida(),
        $rutt === '/oversikt' => \TDL\Http\Sidor::oversikt(),
        $rutt === '/arbetsstalle' && $metod === 'POST' => \TDL\Http\Sidor::arbetsstalleValj(),
        $rutt === '/arbetsstalle/byt' && $metod === 'POST' => \TDL\Http\Sidor::arbetsstalleByt(),
        $rutt === '/installera' && $metod === 'GET' => \TDL\Http\Installation::form(),
        $rutt === '/installera' && $metod === 'POST' => \TDL\Http\Installation::skapa(),
        $rutt === '/aterstall' && $metod === 'GET' => \TDL\Http\Installation::aterstallForm(),
        $rutt === '/aterstall' && $metod === 'POST' => \TDL\Http\Installation::aterstall(),
        $rutt === '/login' && $metod === 'GET' => \TDL\Http\Installation::loginForm(),
        $rutt === '/login' && $metod === 'POST' => \TDL\Http\Installation::login(),
        $rutt === '/logout' && $metod === 'POST' => \TDL\Http\Installation::logout(),
        $rutt === '/logout' => \TDL\Http\Installation::logout(),

        // Artiklar och saldo
        $rutt === '/artiklar' && $metod === 'GET' => \TDL\Http\Sidor::artiklar(),
        $rutt === '/artiklar/ny' && $metod === 'GET' => \TDL\Http\Sidor::artikelForm(),
        $rutt === '/artiklar/ny' && $metod === 'POST' => \TDL\Http\Sidor::artikelSkapa(),
        preg_match('#^/artiklar/([A-Z0-9]+)$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\Sidor::artikel($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/redigera$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\Sidor::artikelForm($m[1]),
        // Verktygstavla: lägg till och ta bort delar
        preg_match('#^/artiklar/([A-Z0-9]+)/delar$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::tavlaLaggTill($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/delar/ta-bort$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::tavlaTaBort($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/delar/flytta$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::tavlaFlytta($m[1]),
        // Lagerplatser: zoner, hyllor, hyll-QR och karta
        $rutt === '/lagerplatser' && $metod === 'GET' => \TDL\Http\LagerplatsSidor::zoner(),
        $rutt === '/lagerplatser/ny' && $metod === 'GET' => \TDL\Http\LagerplatsSidor::zonForm(),
        $rutt === '/lagerplatser/ny' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::zonSkapa(),
        preg_match('#^/lagerplatser/([A-Z0-9]+)$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\LagerplatsSidor::zon($m[1]),
        preg_match('#^/lagerplatser/([A-Z0-9]+)/redigera$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\LagerplatsSidor::zonRedigera($m[1]),
        preg_match('#^/lagerplatser/([A-Z0-9]+)/redigera$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\LagerplatsSidor::zonAndra($m[1]),
        preg_match('#^/lagerplatser/([A-Z0-9]+)/hyllor$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\LagerplatsSidor::zonSkapaHyllor($m[1]),
        preg_match('#^/lagerplatser/([A-Z0-9]+)/skriv-ut$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\LagerplatsSidor::hyllorSkrivUt($m[1]),
        $rutt === '/lagerkarta' && $metod === 'GET' => \TDL\Http\LagerplatsSidor::karta(),
        $rutt === '/lokalplan' && $metod === 'GET' => \TDL\Http\LagerplatsSidor::plan(),
        $rutt === '/lokalplan/bild' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planLaddaUpp(),
        $rutt === '/lokalplan/rutor' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planSparaRutor(),
        $rutt === '/lokalplan/ta-bort' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planTaBort(),
        $rutt === '/lokalplan/klart' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planKlart(),
        $rutt === '/lokalplan/vaning' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planNyVaning(),
        $rutt === '/lokalplan/vaning/andra' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planAndraVaning(),
        $rutt === '/lokalplan/vaning/ta-bort' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planTaBortVaning(),
        $rutt === '/lokalplan/foto/ta-bort' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planTaBortFoto(),
        $rutt === '/lokalplan/zon' && $metod === 'POST' => \TDL\Http\LagerplatsSidor::planPlaceraZon(),
        $rutt === '/lokalplan/bild.jpg' && $metod === 'GET' => \TDL\Http\LagerplatsSidor::planBild(),
        preg_match('#^/hylla/([A-Z0-9]+)$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\LagerplatsSidor::hylla($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/redigera$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::artikelAndra($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/inaktivera$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::artikelInaktivera($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/etiketter$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\Sidor::artikelEtiketter($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/bilder$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\ArtikelQr::laddaUppIntern($m[1]),
        preg_match('#^/artiklar/([A-Z0-9]+)/bilder/ta-bort$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\ArtikelQr::taBortBild($m[1]),
        preg_match('#^/bilder/([A-Z0-9]+)/([a-z0-9.]+)$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\ArtikelQr::bild($m[1], $m[2]),
        preg_match('#^/artikel/([A-Z0-9]+)$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\ArtikelQr::visa($m[1]),
        preg_match('#^/artikel/([A-Z0-9]+)/antal$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\ArtikelQr::justeraAntal($m[1]),
        preg_match('#^/artikel/([A-Z0-9]+)/bild$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\ArtikelQr::laddaUppPublik($m[1]),

        // Rörelser
        $rutt === '/inleverans' && $metod === 'GET' => \TDL\Http\Sidor::inleveransForm(),
        $rutt === '/inleverans' && $metod === 'POST' => \TDL\Http\Sidor::inleverans(),
        $rutt === '/uttag' && $metod === 'GET' => \TDL\Http\Sidor::uttagForm(),
        $rutt === '/uttag' && $metod === 'POST' => \TDL\Http\Sidor::uttag(),
        $rutt === '/plocklista' && $metod === 'GET' => \TDL\Http\Sidor::plocklistaForm(),
        $rutt === '/plocklista' && $metod === 'POST' => \TDL\Http\Sidor::plocklista(),
        $rutt === '/overforing' && $metod === 'GET' => \TDL\Http\Sidor::overforingForm(),
        $rutt === '/overforing' && $metod === 'POST' => \TDL\Http\Sidor::overforing(),
        $rutt === '/etiketter' && $metod === 'GET' => \TDL\Http\Sidor::etiketterForm(),
        $rutt === '/etiketter' && $metod === 'POST' => \TDL\Http\Sidor::etiketter(),
        $rutt === '/etiketter/utskrift' && $metod === 'GET' => \TDL\Http\Sidor::etiketterUtskrift(),
        $rutt === '/platsetikett' && $metod === 'GET' => \TDL\Http\Sidor::platsetikett(),

        // Skanning
        $rutt === '/skanna' && $metod === 'GET' => \TDL\Http\Sidor::skanna(),

        // Inventering
        $rutt === '/inventering' && $metod === 'GET' => \TDL\Http\Sidor::inventeringar(),
        $rutt === '/inventering/start' && $metod === 'POST' => \TDL\Http\Sidor::inventeringStart(),
        preg_match('#^/inventering/([A-Z0-9]+)$#', $rutt, $m) && $metod === 'GET' => \TDL\Http\Sidor::inventering($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/skanna$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringSkanna($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/antal$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringAntal($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/lagg-till$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringLaggTill($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/ta-bort$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringTaBort($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/avstamning$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringAvstamning($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/avsluta$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringAvsluta($m[1]),
        preg_match('#^/inventering/([A-Z0-9]+)/annullera$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::inventeringAnnullera($m[1]),

        // Historik
        $rutt === '/historik' && $metod === 'GET' => \TDL\Http\Sidor::historik(),

        // Platser
        $rutt === '/platser' && $metod === 'GET' => \TDL\Http\Sidor::platser(),
        $rutt === '/platser/ny' && $metod === 'POST' => \TDL\Http\Sidor::platsSkapa(),
        preg_match('#^/platser/([A-Z0-9]+)/redigera$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::platsAndra($m[1]),
        preg_match('#^/platser/([A-Z0-9]+)/stang$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::platsStang($m[1]),

        // Adminpanel
        $rutt === '/admin/varumarke' && $metod === 'GET' => \TDL\Http\Admin::varumarke(),
        $rutt === '/admin/varumarke' && $metod === 'POST' => \TDL\Http\Admin::varumarkeSpara(),
        $rutt === '/admin/varumarke/logotyp' && $metod === 'POST' => \TDL\Http\Admin::varumarkeLogotyp(),
        $rutt === '/admin/varumarke/aterstall' && $metod === 'POST' => \TDL\Http\Admin::varumarkeAterstall(),
        $rutt === '/admin/system' && $metod === 'GET' => \TDL\Http\Admin::system(),
        $rutt === '/admin/halsa' && $metod === 'GET' => \TDL\Http\Admin::halsa(),
        $rutt === '/admin/system/backup' && $metod === 'POST' => \TDL\Http\Admin::systemBackup(),
        $rutt === '/admin/system/installningar' && $metod === 'POST' => \TDL\Http\Admin::systemInstallningar(),
        $rutt === '/offline' && $metod === 'GET' => \TDL\Http\Sidor::offline(),
        $rutt === '/sw.js' && $metod === 'GET' => \TDL\Http\Sidor::serviceWorker(),
        $rutt === '/site.webmanifest' && $metod === 'GET' => \TDL\Http\Sidor::manifest(),
        $rutt === '/admin/system/adress' && $metod === 'POST' => \TDL\Http\Admin::systemAdress(),
        $rutt === '/admin/system/qr-nyckel' && $metod === 'POST' => \TDL\Http\Admin::systemQrNyckel(),

        // Konton och innehåll
        $rutt === '/konton' && $metod === 'GET' => \TDL\Http\Sidor::konton(),
        $rutt === '/konton' && $metod === 'POST' => \TDL\Http\Sidor::kontoSkapa(),
        preg_match('#^/konton/([A-Z0-9]+)/roll$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::kontoRoll($m[1]),
        preg_match('#^/konton/([A-Z0-9]+)/pin$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::kontoPin($m[1]),
        preg_match('#^/konton/([A-Z0-9]+)/losenord$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::kontoLosenord($m[1]),
        preg_match('#^/konton/([A-Z0-9]+)/stang$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::kontoStang($m[1]),
        preg_match('#^/konton/([A-Z0-9]+)/aktivera$#', $rutt, $m) && $metod === 'POST' => \TDL\Http\Sidor::kontoAktivera($m[1]),
        $rutt === '/mitt-konto' && $metod === 'GET' => \TDL\Http\Sidor::mittKonto(),
        $rutt === '/miniminiva' => \TDL\Http\Sidor::miniminiva(),
        $rutt === '/miniminiva/bestallning.csv' && $metod === 'GET' => \TDL\Http\Sidor::bestallningCsv(),
        $rutt === '/arkiv' => \TDL\Http\Sidor::arkiv(),
        $rutt === '/arkiv/bygg' && $metod === 'POST' => \TDL\Http\Sidor::arkivBygg(),
        $rutt === '/mitt-konto/pin' && $metod === 'POST' => \TDL\Http\Sidor::mittPin(),
        $rutt === '/mitt-konto/losenord' && $metod === 'GET' => \TDL\Http\Installation::losenordForm(),
        $rutt === '/mitt-konto/losenord' && $metod === 'POST' => \TDL\Http\Installation::losenordSpara(),

        $rutt === '/varumarke/logotyp.svg' => \TDL\Http\Admin::logotypFil('logotyp'),
        $rutt === '/varumarke/ikon.svg' => \TDL\Http\Admin::logotypFil('ikon'),
        // Tryckoriginalen följer med appen – de behöver aldrig laddas ner separat.
        $rutt === '/varumarke/tryck' => \TDL\Http\Admin::tryckOriginal(),

        default => Svar::ejFunnet('Sidan finns inte. <a href="/">Till startsidan</a>'),
    };
} catch (AppFel $e) {
    $kod = $e->httpKod ?? 400;
    if ($kod === 403) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><p>' . e($e->getMessage()) . '</p><p><a href="/">Tillbaka</a></p>';
        exit;
    }
    flash('fel', $e->getMessage());
    $tillbaka = $_SERVER['HTTP_REFERER'] ?? '/';
    // Endast egna sidor som returmål
    $varden = parse_url($tillbaka, PHP_URL_HOST);
    $tillbaka = ($varden === null || $varden === ($_SERVER['HTTP_HOST'] ?? '')) ? $tillbaka : '/';
    omdirigera($tillbaka);
} catch (\Throwable $e) {
    error_log('Tadega Lager [' . request_id() . ']: ' . $e->getMessage());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><h1>Ett tekniskt fel inträffade</h1><p>Referens: '
        . e(request_id()) . '</p><p><a href="/">Tillbaka</a></p>';
}
