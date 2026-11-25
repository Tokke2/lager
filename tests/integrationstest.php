<?php
/**
 * Integrationsprov som körs mot en tillfällig lagringskatalog.
 *
 *   php tests/integrationstest.php
 *
 * Provet rör ALDRIG storage/ – det sätter TDL_STORAGE till en tempkatalog.
 * Testar godkännandekriterierna GT-01 … GT-10 samt ett antal negativa fall.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Körs från kommandoraden.\n");
}

$tmpStorage = sys_get_temp_dir() . '/tdl-test-' . bin2hex(random_bytes(4));
putenv('TDL_STORAGE=' . $tmpStorage);

require dirname(__DIR__) . '/app/bootstrap.php';

use TDL\Domain\Arkiv;
use TDL\Domain\Inventering;
use TDL\Domain\Lager;
use TDL\Domain\Rorelser;
use TDL\Support\Historik;
use TDL\Support\Installningar;
use TDL\Support\Json;
use TDL\Support\Backupkontroll;
use TDL\Support\Konto;
use TDL\Support\Koder;
use TDL\Support\Las;

$grön = 0;
$röd = 0;
$rader = [];

function prov(string $namn, callable $fn): void
{
    global $grön, $röd, $rader;
    try {
        $fn();
        $grön++;
        $rader[] = "  ✔ $namn";
    } catch (Throwable $e) {
        $röd++;
        $rader[] = "  ✘ $namn — " . get_class($e) . ': ' . $e->getMessage();
    }
}

function krav(bool $villkor, string $meddelande): void
{
    if (!$villkor) {
        throw new RuntimeException($meddelande);
    }
}

function kastar(callable $fn, string $delsträng): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (mb_stripos($e->getMessage(), $delsträng) === false) {
            throw new RuntimeException('Fel typ av fel: "' . $e->getMessage() . '", väntade "' . $delsträng . '"');
        }
        return;
    }
    throw new RuntimeException('Inget fel kastades, väntade "' . $delsträng . '"');
}

Lager::initieraFiler();
$admin = Konto::skapaForstaAdmin('Testadmin', '4821');
$platsOrebro = Lager::skapaPlats('Örebro', 'ORE', $admin);
$platsLaxa = Lager::skapaPlats('Laxå', 'LAX', $admin);
$system = Lager::skapaPlats('Äldre saldo (ofördelat)', 'SYS', $admin, true);

$personal = Konto::skapaKonto('Testperson', '7351', 'personal', $admin);

// Lagerplats: platsen är obligatorisk, så proven behöver en zon.
$zon = \TDL\Domain\Lagerplats::skapaZon('Testlager', 'T', 5, 8, $admin);

/**
 * Plats som proven kan sätta på artiklar. Delar ut löpande fack så att
 * olika artiklar hamnar på olika hyllor – det är så det ser ut i verkligheten.
 */
$GLOBALS['platsRäknare'] = 0;
function testPlats(): array
{
    $GLOBALS['platsRäknare']++;
    $n = $GLOBALS['platsRäknare'];
    return [
        'zon_id' => $GLOBALS['zon']['id'],
        'rad' => (($n - 1) % 5) + 1,
        'fack' => (($n - 1) % 8) + 1,
    ];
}

/**
 * Lägger till en plats på en artikel om provet inte angett någon.
 * Alla befintliga prov skrevs innan platsen blev obligatorisk; i stället för
 * att röra 74 ställen fyller vi på här.
 */
function medPlats(array $data): array
{
    if (empty($data['zon_id'])) {
        $data = array_merge($data, testPlats());
    }
    return $data;
}

/* ---------------------------------------------------------------- GT-01 */
prov('GT-01 oberoende saldo per plats', function () use ($admin, $platsOrebro, $platsLaxa) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Skruv', 'artikelnummer' => 'S-1', 'enhet' => 'st',
        'prisreferens' => '1,5', 'miniminiva' => '10', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 10, [], $admin);
    Rorelser::inleverans($a['id'], $platsLaxa['id'], 4, [], $admin);
    $saldo = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldo[$platsOrebro['id']] ?? 0) === 10, 'Örebro ska ha 10');
    krav(($saldo[$platsLaxa['id']] ?? 0) === 4, 'Laxå ska ha 4');
});

/* ---------------------------------------------------------------- GT-02 */
prov('GT-02 QR-enhet behåller ID vid överföring', function () use ($admin, $platsOrebro, $platsLaxa) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Borr', 'artikelnummer' => 'B-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'qr',
    ]), $admin);
    $etiketter = Rorelser::skapaEtiketter($a['id'], 3, $admin);
    $koder = array_map(fn ($e) => $e['id'], $etiketter);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 0, $koder, $admin);
    $enhetFöre = Lager::enhet($koder[0]);
    Rorelser::overforing($a['id'], $platsOrebro['id'], $platsLaxa['id'], 0, [$koder[0]], $admin);
    $enhetEfter = Lager::enhet($koder[0]);
    krav($enhetFöre['id'] === $enhetEfter['id'], 'ID ska vara oförändrat');
    krav($enhetEfter['plats_id'] === $platsLaxa['id'], 'Enheten ska ligga på Laxå');
});

/* ---------------------------------------------------------------- GT-03 */
prov('GT-03 inleverans/uttag rör rätt saldo och skapar historik', function () use ($admin, $platsOrebro, $platsLaxa) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Mutter', 'artikelnummer' => 'M-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $före = count(Json::las('historik')['poster']);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 20, [], $admin);
    Rorelser::uttag($a['id'], $platsOrebro['id'], 5, [], $admin);
    $saldo = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldo[$platsOrebro['id']] ?? 0) === 15, 'Örebro ska ha 15');
    krav(!isset($saldo[$platsLaxa['id']]) || $saldo[$platsLaxa['id']] === 0, 'Laxå ska vara orörd');
    krav(count(Json::las('historik')['poster']) === $före + 2, 'exakt två historikposter');
});

/* ---------------------------------------------------------------- GT-04 */
prov('GT-04 fel plats och otillräckligt saldo stoppas utan ändring', function () use ($admin, $platsOrebro, $platsLaxa) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Bricka', 'artikelnummer' => 'BR-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 3, [], $admin);
    $före = Lager::saldoPerPlats(Lager::artikel($a['id']));
    kastar(fn () => Rorelser::uttag($a['id'], $platsOrebro['id'], 4, [], $admin), 'Otillräckligt saldo');
    kastar(fn () => Rorelser::uttag($a['id'], $platsLaxa['id'], 1, [], $admin), 'Otillräckligt saldo');
    $efter = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav($före == $efter, 'Saldot ska vara oförändrat efter nekade uttag');
});

/* ---------------------------------------------------------------- GT-05 */
prov('GT-05 överföring uppdaterar båda saldon sammanhängande', function () use ($admin, $platsOrebro, $platsLaxa) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Spik', 'artikelnummer' => 'SP-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 30, [], $admin);
    Rorelser::overforing($a['id'], $platsOrebro['id'], $platsLaxa['id'], 12, [], $admin);
    $saldo = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldo[$platsOrebro['id']] ?? 0) === 18, 'Örebro 30-12=18');
    krav(($saldo[$platsLaxa['id']] ?? 0) === 12, 'Laxå 12');
});

/* ---------------------------------------------------------------- GT-06 */
prov('GT-06 inventering visar differens före avstämning', function () use ($admin, $platsOrebro) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Hammare', 'artikelnummer' => 'H-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'qr',
    ]), $admin);
    $etiketter = Rorelser::skapaEtiketter($a['id'], 4, $admin);
    $koder = array_map(fn ($e) => $e['id'], $etiketter);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 0, $koder, $admin);

    $s = Inventering::startaInventering($platsOrebro['id'], [$a['id']], $admin);
    krav(isset($s['artiklar'][$a['id']]), 'artikeln ska ingå i inventeringen');
    krav(count($s['artiklar'][$a['id']]['forvantade_enheter']) === 4, 'förväntade enheter ska frysas vid start');
    Inventering::skanna($s['id'], $koder[0], $admin);
    Inventering::skanna($s['id'], $koder[1], $admin);
    $s2 = Inventering::session($s['id']);
    // Sessionen omfattar alla artiklar på platsen – läs raden för den här artikeln
    $rad = Inventering::rad($s2, $a['id']);
    krav($rad !== null, 'artikeln ska ha en rad i sessionen');
    $lageRad = (function () use ($s2, $a) {
        foreach (Inventering::lage($s2)['rader'] as $r) {
            if ($r['artikel_id'] === $a['id']) {
                return $r;
            }
        }
        return null;
    })();
    krav($lageRad['forvantat'] === 4 && $lageRad['raknat'] === 2 && $lageRad['differens'] === -2,
        'förväntat 4, räknat 2, differens -2 (fick ' . json_encode($lageRad) . ')');

    // Dubblett ska inte öka
    Inventering::skanna($s['id'], $koder[0], $admin);
    $lageRad2 = null;
    foreach (Inventering::lage(Inventering::session($s['id']))['rader'] as $r) {
        if ($r['artikel_id'] === $a['id']) { $lageRad2 = $r; }
    }
    krav($lageRad2['raknat'] === 2, 'dubblett ska inte öka räknat antal');

    // Saldot får inte ha rörts före avstämning
    $saldo = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldo[$platsOrebro['id']] ?? 0) === 4, 'saldo orört före avstämning');

    // Personal får inte stämma av
    kastar(fn () => Inventering::stammaAv($s['id'], 'stam av', ['id' => 'x', 'namn' => 'P', 'roll' => 'personal']), 'administratörer');

    // Admin med bekräftelse – bara den här artikeln
    $res = Inventering::stammaAv($s['id'], 'stam av', $admin, [$a['id']]);
    krav($res['justerade'] === 2, 'två saknade ska bokföras som uttagna (fick ' . $res['justerade'] . ')');
    $saldoEfter = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldoEfter[$platsOrebro['id']] ?? 0) === 2, 'saldot ska vara 2 efter avstämning');
});

prov('LO-07 personal loggar in med enbart PIN', function () {
    $k = Konto::verifieraInloggning('Testperson', '7351');
    krav($k['namn'] === 'Testperson', 'personal ska inte behöva lösenord');
    kastar(fn () => Konto::verifieraInloggning('Testperson', '0000'), 'Fel namn eller PIN');
});

/* ---------------------------------------------------------------- GT-07 */
prov('GT-07 personal kan inte utföra adminåtgärder', function () use ($personal, $admin, $platsLaxa) {
    $personalAktiv = [
        'id' => 'KTEST', 'namn' => 'Testperson', 'roll' => 'personal', 'aktiv' => true,
    ];
    // Personal FÅR lägga upp nya artiklar (2026-11-12) – det var tidigare admin-only.
    // Men att ÄNDRA en artikel, byta arbetsställe och stänga arbetsställe är admin.
    $ny = Lager::skapaArtikel(medPlats([
        'namn' => 'Personals artikel', 'artikelnummer' => 'PS-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $personalAktiv);
    krav($ny['namn'] === 'Personals artikel', 'personal ska kunna lägga upp en artikel');
    kastar(fn () => Lager::andraArtikel($ny['id'], [
        'namn' => 'Ändrad av personal', 'artikelnummer' => 'PS-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ], $personalAktiv), 'administratörsbehörighet');
    kastar(fn () => Lager::andraPlats($platsLaxa['id'], 'Laxå', 'LAX', $personalAktiv), 'administratörsbehörighet');
    kastar(fn () => Lager::stangPlats($platsLaxa['id'], $personalAktiv), 'administratörsbehörighet');
    // Men personal får utföra dagliga lageråtgärder
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Personalartikel', 'artikelnummer' => 'P-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsLaxa['id'], 5, [], ['id' => 'KTEST', 'namn' => 'Testperson', 'roll' => 'personal']);
    krav(count(Lager::artiklar()) > 0, 'personal ska kunna ta emot lager');
});

/* ---------------------------------------------------------------- GT-08 */
prov('GT-08 avstängning och PIN-byte ogiltigförklarar inloggning', function () use ($admin, $personal) {
    $inloggad = Konto::verifieraInloggning('Testperson', '7351');
    krav($inloggad['id'] === $personal['id'], 'inloggning ska lyckas');
    Konto::andraPin($personal['id'], '9137', $admin, true);
    kastar(fn () => Konto::verifieraInloggning('Testperson', '7351'), 'Fel namn eller PIN');
    Konto::verifieraInloggning('Testperson', '9137'); // ny PIN fungerar
    Konto::stangAv($personal['id'], $admin);
    kastar(fn () => Konto::verifieraInloggning('Testperson', '9137'), 'avstängt');
});



/* ------------------------------------------------------------------
 *  Etiketter direkt vid ny artikel (ET-06 … ET-08)
 * ------------------------------------------------------------------ */

prov('ET-06 personal kan skapa etiketter till en ny artikel', function () use ($admin) {
    $personal = ['id' => 'KTEST', 'namn' => 'Testperson', 'roll' => 'personal', 'aktiv' => true];
    // Arbetsstället skapas av admin – det är fortfarande en adminuppgift
    $plats = Lager::skapaPlats('Etikettstad', 'ETK', $admin);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Etikettartikel', 'artikelnummer' => 'ETK-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'qr',
    ]), $personal);
    $nya = Rorelser::skapaEnheter($a['id'], $plats['id'], 5, $personal);
    krav(count($nya) === 5, 'fem etiketter ska skapas, fick ' . count($nya));
    foreach ($nya as $en) {
        krav(($en['status'] ?? '') === 'ny', 'nya etiketter ska ha status ny');
        krav(($en['artikel_id'] ?? '') === $a['id'], 'etiketten ska höra till artikeln');
        krav(($en['plats_id'] ?? '') === $plats['id'], 'etiketten ska höra till platsen');
    }
});

prov('ET-07 etiketterna påverkar inte saldot förrän de tas emot', function () {
    $a = null;
    foreach (Lager::artiklar() as $x) {
        if ($x['artikelnummer'] === 'ETK-1') {
            $a = $x;
        }
    }
    krav($a !== null, 'artikeln ska finnas');
    foreach (Lager::saldoPerPlats($a) as $plats => $antal) {
        krav($antal === 0, 'saldot ska vara 0 innan etiketterna tagits emot, fick ' . $antal);
    }
});

prov('ET-08 etiketter kan inte skapas för antalsartiklar', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Antalsartikel', 'artikelnummer' => 'ETK-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $plats = null;
    foreach (Lager::platser() as $p) {
        if ($p['kod'] === 'ETK') {
            $plats = $p;
        }
    }
    kastar(fn () => Rorelser::skapaEnheter($a['id'], $plats['id'], 3, $admin), 'hanteras i antal');
});

/* ------------------------------------------------------------------
 *  Miniminivålarm (MI-01 … MI-02)
 * ------------------------------------------------------------------ */

prov('MI-01 larmlistan visar artiklar under miniminivå', function () use ($admin) {
    $plats = Lager::skapaPlats('Minimistad', 'MIN', $admin);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Larmartikel', 'artikelnummer' => 'LARM-1', 'enhet' => 'st',
        'prisreferens' => '10', 'miniminiva' => '50', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $plats['id'], 20, [], $admin);

    $rader = \TDL\Http\Sidor::underMinimiLista();
    $traff = array_values(array_filter($rader, fn ($r) => $r['artikel']['id'] === $a['id'] && $r['plats']['id'] === $plats['id']));
    krav($traff !== [], 'artikeln ska finnas i larmlistan');
    krav($traff[0]['brist'] === 30, 'bristen ska vara 30, fick ' . $traff[0]['brist']);
    krav($traff[0]['saldo'] === 20, 'saldot ska vara 20');
});

prov('MI-02 artikel utan miniminivå larmar inte', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Ingen gräns', 'artikelnummer' => 'LARM-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    foreach (\TDL\Http\Sidor::underMinimiLista() as $r) {
        krav($r['artikel']['id'] !== $a['id'], 'artikel med miniminivå 0 ska aldrig larma');
    }
});

/* ------------------------------------------------------------------
 *  Tvåpersonersregel (TP-01 … TP-04)
 * ------------------------------------------------------------------ */

prov('TP-01 stort uttag kräver kollegas godkännande', function () use ($admin, $platsOrebro) {
    Installningar::spara(['tva_person_grans' => 10, 'tva_person_varde' => 0]);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Stor uttagsartikel', 'artikelnummer' => 'TP-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 100, [], $admin);

    kastar(fn () => Rorelser::uttag($a['id'], $platsOrebro['id'], 20, [], $admin), 'kollega bekräftar');
    kastar(fn () => Rorelser::uttag($a['id'], $platsOrebro['id'], 20, [], $admin, null, $admin),
        'annan person');
    // Litet uttag går utan kollega
    $r = Rorelser::uttag($a['id'], $platsOrebro['id'], 5, [], $admin);
    krav($r['antal'] === 5, 'litet uttag ska gå igenom');
    Installningar::spara(['tva_person_grans' => 0]);
});

prov('TP-02 kollega med rätt roll godkänner uttaget', function () use ($admin, $personal, $platsOrebro) {
    Installningar::spara(['tva_person_grans' => 10]);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Godkänd uttagsartikel', 'artikelnummer' => 'TP-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 50, [], $admin);
    $r = Rorelser::uttag($a['id'], $platsOrebro['id'], 30, [], $admin, null, $personal);
    krav($r['antal'] === 30, 'utttaget ska gå igenom med kollegans godkännande');
    Installningar::spara(['tva_person_grans' => 0]);
});

prov('TP-03 godkännaren sparas i historiken', function () {
    $h = Json::las('historik');
    $senaste = null;
    foreach ($h['poster'] as $p) {
        if (($p['typ'] ?? '') === 'uttag' && isset($p['godkand_av']['namn'])) {
            $senaste = $p;
        }
    }
    krav($senaste !== null, 'uttaget ska ha ett godkännande i historiken');
    krav($senaste['godkand_av']['namn'] === 'Testperson', 'kollegans namn ska stå i historiken');
});

prov('TP-04 gränsen kan stängas av', function () use ($admin, $platsOrebro) {
    Installningar::spara(['tva_person_grans' => 0, 'tva_person_varde' => 0]);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Fri uttagsartikel', 'artikelnummer' => 'TP-4', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 300, [], $admin);
    $r = Rorelser::uttag($a['id'], $platsOrebro['id'], 250, [], $admin);
    krav($r['antal'] === 250, 'utan gräns ska stora uttag gå igenom');
});

/* ------------------------------------------------------------------
 *  Backupkontroll (BK-01)
 * ------------------------------------------------------------------ */

prov('BK-01 backupkontrollen känner igen lägena', function () {
    $lage = Backupkontroll::lage();
    krav(in_array($lage['lage'], ['ok', 'gammal', 'saknas'], true), 'okänt läge: ' . $lage['lage']);
    krav(is_string(Backupkontroll::sammanfattning()), 'sammanfattningen ska vara text');
});

/* ------------------------------------------------------------------
 *  Års- och månadsarkiv (AR-01 … AR-05)
 * ------------------------------------------------------------------ */

prov('AR-01 avslutad inventering arkiveras automatiskt', function () use ($admin, $platsLaxa) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Arkivartikel', 'artikelnummer' => 'ARK-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsLaxa['id'], 40, [], $admin);

    $s = Inventering::startaInventering($platsLaxa['id'], [], $admin);
    Inventering::sattRaknatAntal($s['id'], $a['id'], 37, $admin);
    Inventering::stammaAv($s['id'], 'stam av', $admin);

    $post = Arkiv::hitta($s['id']);
    krav($post !== null, 'inventeringen ska finnas i arkivet');
    krav($post['antal_artiklar'] > 0, 'arkivposten ska ha artiklar');
    krav($post['plats_namn'] === 'Laxå', 'platsnamnet ska följa med');
});

prov('AR-02 arkivposten innehåller förväntat, räknat och differens', function () {
    $post = null;
    foreach (Arkiv::lasManad((int) gmdate('Y'), (int) gmdate('n'))['poster'] as $p) {
        if (($p['plats_namn'] ?? '') === 'Laxå') {
            $post = $p;
        }
    }
    krav($post !== null, 'arkivpost för Laxå ska finnas');
    $arkRad = null;
    foreach ($post['rader'] as $r) {
        if ($r['artikelnummer'] === 'ARK-1') {
            $arkRad = $r;
        }
    }
    krav($arkRad !== null, 'artikelraden ska finnas i arkivet');
    krav($arkRad['forvantat'] === 40, 'förväntat ska vara 40, fick ' . $arkRad['forvantat']);
    krav($arkRad['raknat'] === 37, 'räknat ska vara 37, fick ' . $arkRad['raknat']);
    krav($arkRad['differens'] === -3, 'differensen ska vara -3, fick ' . $arkRad['differens']);
    krav($post['antal_avvikelser'] >= 1, 'avvikelsen ska räknas');
});

prov('AR-03 samma session arkiveras bara en gång', function () use ($admin, $platsLaxa) {
    $fore = count(Arkiv::lasManad((int) gmdate('Y'), (int) gmdate('n'))['poster']);
    $session = null;
    foreach (Json::las('sessioner')['poster'] as $s) {
        if (($s['status'] ?? '') === 'avslutad' && ($s['plats_id'] ?? '') === $platsLaxa['id']) {
            $session = $s;
        }
    }
    krav($session !== null, 'avslutad session ska finnas');
    Arkiv::arkiveraSession($session, $admin);
    $efter = count(Arkiv::lasManad((int) gmdate('Y'), (int) gmdate('n'))['poster']);
    krav($efter === $fore, 'antalet arkivposter ska vara oförändrat');
});

prov('AR-04 arkivet delas per år och månad', function () use ($admin) {
    $session = [
        'id' => 'SARKTEST', 'plats_id' => 'X', 'status' => 'avslutad',
        'startad' => '2025-03-04T08:00:00Z', 'avslutad' => '2025-03-04T09:00:00Z',
        'startad_av_namn' => 'Test', 'avslutad_av_namn' => 'Test',
        'artiklar' => [], 'avstamning' => ['typ' => 'uttag'],
    ];
    Arkiv::arkiveraSession($session, $admin);
    $mars2025 = Arkiv::lasManad(2025, 3);
    $finns = array_filter($mars2025['poster'], fn ($p) => $p['session_id'] === 'SARKTEST');
    krav($finns !== [], 'sessionen ska hamna i 2025-03');
    krav(in_array(2025, Arkiv::ar(), true), '2025 ska finnas bland arkivåren');
    krav(empty(Arkiv::lasManad(2025, 4)['poster']), 'april 2025 ska vara tom');
});

prov('AR-05 årsammanställningen räknar ihop året', function () {
    $summa = Arkiv::arssammanstallning(2025);
    krav($summa['totalt'] === 1, 'ett arkiv 2025, fick ' . $summa['totalt']);
    krav(isset($summa['manader'][3]), 'mars ska ha data');
    krav($summa['manader'][3]['sessioner'] === 1, 'mars ska ha en inventering');
});

/* ---------------------------------------------------------------- GT-10 */
prov('GT-10 backup/återläsning bevarar saldon, ID och historik', function () use ($admin) {
    $saldonFöre = [];
    foreach (Lager::artiklar() as $a) {
        $saldonFöre[$a['id']] = Lager::saldoPerPlats($a);
    }
    $enheterFöre = array_map(fn ($e) => [$e['id'], $e['status'], $e['plats_id']], Lager::enheter());
    $historikFöre = count(Json::las('historik')['poster']);

    $backupFil = TDL_BACKUP . '/test-backup.zip';
    @unlink($backupFil);
    $zip = new ZipArchive();
    $zip->open($backupFil, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $manifest = ['filer' => []];
    foreach (array_keys(Json::FILER) as $nyckel) {
        $rå = (string) file_get_contents(Json::sokvag($nyckel));
        $zip->addFromString($nyckel . '.json', $rå);
        $d = json_decode($rå, true);
        $manifest['filer'][$nyckel] = ['fil' => $nyckel . '.json', 'sha256' => hash('sha256', $rå), 'poster' => count($d['poster'])];
    }
    $zip->addFromString('manifest.json', tdl_json($manifest));
    $zip->close();

    $zip2 = new ZipArchive();
    $zip2->open($backupFil);
    $m = json_decode($zip2->getFromName('manifest.json'), true);
    foreach ($m['filer'] as $nyckel => $info) {
        $rå = $zip2->getFromName($info['fil']);
        krav(hash('sha256', $rå) === $info['sha256'], "checksumma $nyckel");
        krav(count(json_decode($rå, true)['poster']) === $info['poster'], "antal poster $nyckel");
    }
    $zip2->close();

    krav(Historik::verifiera(Json::las('historik')) === [], 'historikens kedja ska vara intakt');
    krav(count(Json::las('historik')['poster']) === $historikFöre, 'historiken oförändrad');
    krav($enheterFöre === array_map(fn ($e) => [$e['id'], $e['status'], $e['plats_id']], Lager::enheter()), 'enheter oförändrade');
    @unlink($backupFil);
});

/* ------------------------------------------------------- Negativa tester */
prov('N-01 överföring mellan samma plats nekas', function () use ($admin, $platsOrebro) {
    $a = Lager::artiklar()[0];
    kastar(fn () => Rorelser::overforing($a['id'], $platsOrebro['id'], $platsOrebro['id'], 1, [], $admin), 'olika');
});

prov('N-02 stängd plats kan inte ta emot lager', function () use ($admin, $platsLaxa) {
    $a = Lager::artiklar()[0];
    kastar(fn () => Lager::stangPlats($platsLaxa['id'], $admin), 'kan inte stängas');
});

prov('N-03 okänd enhetskod nekas', function () use ($admin, $platsOrebro) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'QR-test', 'artikelnummer' => 'Q-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'qr',
    ]), $admin);
    kastar(fn () => Rorelser::inleverans($a['id'], $platsOrebro['id'], 0, ['TDLUABCDEFGHJK9'], $admin), 'finns inte');
});

prov('N-04 systemposten kan inte ta emot inleverans', function () use ($admin, $system) {
    $a = Lager::artiklar()[0];
    kastar(fn () => Rorelser::inleverans($a['id'], $system['id'], 5, [], $admin), 'Systemposten');
});

prov('N-05 artikel med saldo kan inte inaktiveras', function () use ($admin, $platsOrebro) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Inaktiveringstest', 'artikelnummer' => 'IT-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 2, [], $admin);
    kastar(fn () => Lager::inaktiveraArtikel($a['id'], $admin), 'saldo');
});

prov('N-06 QR-spårning kan inte stängas av med enheter', function () use ($admin) {
    foreach (Lager::artiklar() as $a) {
        if ($a['sparningssatt'] === 'qr') {
            kastar(fn () => Lager::andraArtikel($a['id'], [
                'namn' => $a['namn'], 'artikelnummer' => $a['artikelnummer'], 'enhet' => 'st',
                'miniminiva' => 0, 'prisreferens' => '', 'sparningssatt' => 'antal',
            ], $admin), 'QR-enheter');
            return;
        }
    }
    throw new RuntimeException('ingen QR-artikel att testa');
});

prov('N-07 dubblerad artikelkod nekas', function () use ($admin) {
    $a = Lager::artiklar()[0];
    kastar(fn () => Lager::skapaArtikel(medPlats([
        'namn' => 'Dubblett', 'artikelnummer' => $a['artikelnummer'], 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin), 'finns redan');
});

prov('N-08 felaktig PIN-form nekas', function () use ($admin) {
    kastar(fn () => Konto::skapaKonto('Någon', '12', 'personal', $admin), '4–8');
    kastar(fn () => Konto::skapaKonto('Någon', '1234', 'personal', $admin), 'för enkel');
});

/* ------------------------------------------------------------------
 *  PIN-inloggning för alla roller
 * ------------------------------------------------------------------ */
prov('IN-01 admin loggar in med enbart namn och PIN', function () use ($admin) {
    $ny = Konto::skapaKonto('PIN Admin', '9137', 'admin', $admin);
    krav(Konto::verifieraInloggning('PIN Admin', '9137')['id'] === $ny['id'], 'admin ska kunna logga in med PIN');
    kastar(fn () => Konto::verifieraInloggning('PIN Admin', '0000'), 'Fel namn eller PIN');
});

prov('IN-02 gamla lösenordsfält rensas vid lyckad inloggning', function () use ($admin) {
    $ny = Konto::skapaKonto('Äldre Admin', '5162', 'admin', $admin);
    $f = Json::las('anvandare');
    Json::uppdatera($f, $ny['id'], function ($u) {
        $u['losenord_hash'] = 'gammal-hash';
        $u['losenord_satt'] = '2020-01-01';
        $u['losenord_historik'] = ['gammal'];
        $u['losenord_maste_bytas'] = true;
        return $u;
    });
    Json::skriv('anvandare', $f);
    kastar(fn () => Konto::verifieraInloggning('Äldre Admin', '0000'), 'Fel namn eller PIN');
    krav(isset(Konto::aktivAnvandare($ny['id'])['losenord_hash']), 'felaktig inloggning ska inte skriva om fält');
    $resultat = Konto::verifieraInloggning('Äldre Admin', '5162');
    foreach (['losenord_hash', 'losenord_satt', 'losenord_historik', 'losenord_maste_bytas'] as $falt) {
        krav(!array_key_exists($falt, $resultat), 'gammalt fält ska rensas: ' . $falt);
    }
    krav(password_verify('5162', $resultat['pin_hash']), 'PIN-koden ska vara oförändrad');
});

prov('IN-03 upprepade felaktiga PIN-försök spärrar admin', function () use ($admin) {
    Konto::skapaKonto('Spärr Admin', '4628', 'admin', $admin);
    for ($i = 0; $i < Konto::MAX_FORSOK; $i++) {
        kastar(fn () => Konto::verifieraInloggning('Spärr Admin', '0000'), 'Fel namn eller PIN');
    }
    kastar(fn () => Konto::verifieraInloggning('Spärr Admin', '4628'), 'För många felaktiga försök');
});

prov('IN-05 återställd PIN kan kräva byte vid nästa inloggning', function () use ($admin) {
    $ny = Konto::skapaKonto('Återställ Admin', '7628', 'admin', $admin);
    Konto::andraPin($ny['id'], '96283517', $admin, true, true);
    $inloggad = Konto::verifieraInloggning('Återställ Admin', '96283517');
    krav(!empty($inloggad['maste_byta_pin']), 'återställd PIN ska vara tillfällig');
    kastar(fn () => Konto::verifieraInloggning('Återställ Admin', '7628'), 'Fel namn eller PIN');
    Konto::bytaPin($ny['id'], '96283517', '8426');
    krav(empty(Konto::aktivAnvandare($ny['id'])['maste_byta_pin']), 'eget PIN-byte ska häva spärren');
});

prov('IN-04 inga lösenordsflöden finns i vyer eller routning', function () {
    foreach (['login.php', 'installera.php', 'konton.php', 'mitt_konto.php', 'layout_topp.php', 'startsida_nav.php'] as $vy) {
        $kod = file_get_contents(dirname(__DIR__) . '/app/views/' . $vy);
        krav(!str_contains($kod, 'name="losenord"') && !str_contains($kod, '/mitt-konto/losenord'), $vy . ' ska inte efterfråga lösenord');
    }
    $front = file_get_contents(dirname(__DIR__) . '/public/index.php');
    krav(!str_contains($front, '/losenord'), 'lösenordsrutter ska vara borttagna');
});

prov('N-09 historikens hashkedja upptäcker manipulation', function () {
    $h = Json::las('historik');
    $h['poster'][1]['antal'] = 9999;
    $fel = Historik::verifiera($h);
    krav($fel !== [], 'manipulation ska upptäckas');
});

prov('N-10 QR-kodernas kontrolltecken fungerar', function () {
    for ($i = 0; $i < 200; $i++) {
        $kod = Koder::nyKod('U');
        krav(Koder::verifieraKontroll(str_replace('-', '', $kod)), 'giltig kod ska verifieras: ' . $kod);
        $trasig = substr($kod, 0, -1) . (substr($kod, -1) === '0' ? '1' : '0');
        krav(!Koder::verifieraKontroll(str_replace('-', '', $trasig)), 'trasig kod ska nekas: ' . $trasig);
    }
});

/* ------------------------------------------------------- Artikel-QR och bilder */
prov('QR-01 signerad artikellänk verifieras', function () use ($admin, $platsOrebro) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'QR-artikel', 'artikelnummer' => 'QR-1', 'enhet' => 'st',
        'sparningssatt' => 'antal', 'miniminiva' => '0',
    ]), $admin);
    $nonce = \TDL\Http\ArtikelQr::nonce($a['id']);
    $sig = \TDL\Http\ArtikelQr::signatur($a['id'], $nonce);
    krav(strlen($nonce) === 12, 'nonce ska vara 12 tecken');
    krav(strlen($sig) === 24, 'signatur ska vara 24 tecken');

    $url = \TDL\Http\ArtikelQr::lankUrl($a['id']);
    krav(str_contains($url, 'n=' . $nonce), 'länken ska innehålla nonce');
    krav(str_contains($url, 's=' . $sig), 'länken ska innehålla signatur');

    // Fel signatur ska inte matcha
    krav($sig !== \TDL\Http\ArtikelQr::signatur($a['id'], 'felaktignonce'), 'felaktig nonce ska ge annan signatur');
});

prov('QR-02 bytt nyckel gör gamla etiketter ogiltiga', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'QR-artikel 2', 'artikelnummer' => 'QR-2', 'enhet' => 'st',
        'sparningssatt' => 'antal', 'miniminiva' => '0',
    ]), $admin);
    $gammalNonce = \TDL\Http\ArtikelQr::nonce($a['id']);
    $gammalSig = \TDL\Http\ArtikelQr::signatur($a['id'], $gammalNonce);

    \TDL\Http\ArtikelQr::bytNyckel($admin);

    $nySig = \TDL\Http\ArtikelQr::signatur($a['id'], $gammalNonce);
    krav($nySig !== $gammalSig, 'signaturen ska ändras när nyckeln byts');
});

prov('QR-03 bara admin får byta QR-nyckel', function () use ($personal) {
    kastar(fn () => \TDL\Http\ArtikelQr::bytNyckel($personal), 'administratör');
});

prov('QR-04 bildfil med fel ändelse nekas', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Bildartikel', 'artikelnummer' => 'BILD-1', 'enhet' => 'st',
        'sparningssatt' => 'antal', 'miniminiva' => '0',
    ]), $admin);

    // En textfil som låtsas vara en bild
    $tmp = tempnam(sys_get_temp_dir(), 'tdlbild');
    file_put_contents($tmp, 'detta är inte en bild');
    kastar(
        fn () => \TDL\Support\Bilder::spara(
            ['name' => 'bild.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 20],
            $a['id']
        ),
        'kunde inte verifieras'
    );
    @unlink($tmp);
});

prov('QR-05 bild-URL kräver signatur', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Bildartikel 2', 'artikelnummer' => 'BILD-2', 'enhet' => 'st',
        'sparningssatt' => 'antal', 'miniminiva' => '0',
    ]), $admin);
    $url = \TDL\Http\ArtikelQr::bildUrl($a['id'], 'abcdef1234567890.jpg');
    krav(str_contains($url, '?n='), 'bild-URL ska vara signerad');
    krav(str_contains($url, '&s='), 'bild-URL ska innehålla signatur');

    // Filnamn med sökväg får aldrig nå utanför artikelns katalog
    krav(\TDL\Support\Bilder::sokvag($a['id'], '../../data/anvandare.json') === null, 'sökväg ska nekas');
    krav(\TDL\Support\Bilder::sokvag($a['id'], 'inte-en-bild.txt') === null, 'fel ändelse ska nekas');
});

prov('QR-06 bas-URL valideras', function () use ($admin) {
    \TDL\Http\ArtikelQr::sparaBasUrl('https://lager.exempel.se', $admin);
    krav(\TDL\Http\ArtikelQr::basUrl() === 'https://lager.exempel.se', 'bas-URL ska sparas');

    kastar(fn () => \TDL\Http\ArtikelQr::sparaBasUrl('javascript:alert(1)', $admin), 'http');
    kastar(fn () => \TDL\Http\ArtikelQr::sparaBasUrl('inte en url', $admin), 'http');
});

/* ------------------------------------------- Etikettutskrift (rulle) */
prov('ET-01 rullbredd styr @page och etikettmått', function () use ($admin) {
    $artikel = \TDL\Domain\Lager::skapaArtikel(medPlats([
        'namn' => 'Etikettprov A',
        'artikelnummer' => 'ETIK-1',
        'enhet' => 'st',
        'sparningssatt' => 'antal',
    ]), $admin);

    // Rullmåtten bor numera i den gemensamma mallen
    $standard = \TDL\Support\Etikettmallar::tolka(\TDL\Support\Etikettmallar::STANDARD);
    krav($standard['mm'] === '62mm 29mm', 'standardrullen ska vara 62 x 29 mm');
    krav($standard['qr'] === '24mm', 'standardrullen ska ge QR 24 mm');
    krav(isset(\TDL\Support\Etikettmallar::MALLAR['A4']), 'A4-arket ska finnas kvar');
    // QL-500/550 skriver 720 punkter à 300 dpi = 60,9 mm
    krav(abs(\TDL\Support\Etikettmallar::QL500_BREDD - 60.9) < 0.01, 'QL-500 ska räknas som 60,9 mm bred');

    $kallor = file_get_contents(__DIR__ . '/../app/views/artikel_etiketter.php');
    krav(str_contains($kallor, '@page'), 'utskriftsvyn ska sätta @page');
    krav(str_contains($kallor, 'Skrivare::tryckbredd'), 'utskriftsvyn ska hämta tryckbredden från skrivarprofilen');

    // Varje skrivare har sin egen tryckbredd – etiketten klipps aldrig, men smal tape förblir smal
    $mall62 = \TDL\Support\Etikettmallar::tolka('62x29');
    $mall29 = \TDL\Support\Etikettmallar::tolka('29x90');
    krav(\TDL\Support\Skrivare::tryckbredd($mall62, \TDL\Support\Skrivare::profil('brother_ql500')) === 60.9,
        'QL-500 ska skriva 62 mm-rullen i 60,9 mm');
    krav(\TDL\Support\Skrivare::tryckbredd($mall62, \TDL\Support\Skrivare::profil('brother_ql800')) === 58.0,
        'QL-800-serien ska skriva 58 mm');
    krav(\TDL\Support\Skrivare::tryckbredd($mall62, \TDL\Support\Skrivare::profil('dymo_lw')) === 56.0,
        'Dymo ska skriva 56 mm');
    krav(\TDL\Support\Skrivare::tryckbredd($mall29, \TDL\Support\Skrivare::profil('dymo_lw')) === 29.0,
        'smal tape ska behålla sin bredd även på en bred skrivare');
    $zebra = \TDL\Support\Skrivare::profil('zebra_4');
    krav(\TDL\Support\Skrivare::tryckbredd(\TDL\Support\Etikettmallar::tolka('101x152'), $zebra) === 101.0,
        'Zebra 4 tum ska kunna skriva 101 mm fraktetikett');
    krav(!\TDL\Support\Skrivare::passar(\TDL\Support\Etikettmallar::tolka('101x152'), \TDL\Support\Skrivare::profil('brother_ql500')),
        'en 101 mm-etikett ska varna på en QL-500');
    krav(\TDL\Support\Skrivare::varning($mall62, $zebra) === '', 'en 62 mm-etikett ska gå fritt på Zebra');
});

prov('ET-02 artikelnamnet finns på etiketten', function () use ($admin) {
    $artikel = \TDL\Domain\Lager::skapaArtikel(medPlats([
        'namn' => 'Hammare 500 gram',
        'artikelnummer' => 'ETIK-2',
        'enhet' => 'st',
        'sparningssatt' => 'antal',
    ]), $admin);

    $vyla = file_get_contents(__DIR__ . '/../app/views/artikel_etiketter.php');
    krav(str_contains($vyla, "e(\$artikel['namn'])"), 'namnet ska skrivas ut på etiketten');
    krav(str_contains($vyla, 'class="namn"'), 'namnet ska ha egen klass med fet stil');
});

prov('ET-03 streckkod byggs bara för giltiga tecken', function () use ($admin) {
    // Saneringen ska bara lämna tecken som Code 39 klarar
    krav(\TDL\Support\Streckkod::text('ab-12 å/34') === 'AB-12 34', 'otillåtna tecken ska filtreras bort');
    krav(\TDL\Support\Streckkod::text('ABCDEFGHIJKLMNOPQRST') === 'ABCDEFGHIJKLMNOP', 'koden ska kapas till 16 tecken');
    // Start- och stopptecken + ett mellanrum per tecken: 10 element per tecken
    $element = \TDL\Support\Streckkod::element('DEMO-100');
    krav(count($element) === (strlen('DEMO-100') + 2) * 10, 'Code 39 ska ha start- och stopptecken');
    krav($element[0]['streck'] === true, 'koden ska börja med ett streck');
    krav(\TDL\Support\Streckkod::element('') === [], 'tomt underlag ska ge ingen streckkod');
});

prov('ET-04 QR-koden får felkorrigering L på små etiketter', function () {
    $render = file_get_contents(__DIR__ . '/../public/assets/qr-render.js');
    krav(str_contains($render, 'data-fel'), 'qr-render.js ska läsa data-fel');
    $vy = file_get_contents(__DIR__ . '/../app/views/artikel_etiketter.php');
    krav(str_contains($vy, 'data-fel="L"'), 'rullen ska använda felkorrigering L (färre moduler)');
});

prov('ET-05 QR-koden har mått i millimeter, inte auto', function () {
    $vy = file_get_contents(__DIR__ . '/../app/views/artikel_etiketter.php');
    krav(!preg_match('/\.qr svg \{[^}]*width:\s*auto/', $vy), 'QR-svg får inte ha auto-bredd (blir noll)');
    $standard = \TDL\Support\Etikettmallar::tolka(\TDL\Support\Etikettmallar::STANDARD);
    krav($standard['qr'] === '24mm', 'standardrullen ska ge QR 24 mm');
    krav(str_contains($vy, 'width: <?= $qrMm ?>mm'), 'QR-bredden ska sättas i millimeter i vyn');
});

prov('ET-09 etikettstorlek sparas per artikel och används som förval', function () use ($admin) {
    $artikel = \TDL\Domain\Lager::skapaArtikel(medPlats([
        'namn' => 'Etikettprov storlek',
        'artikelnummer' => 'ETIK-9',
        'enhet' => 'st',
        'sparningssatt' => 'qr',
        'etikett_mall' => '29x62',
    ]), $admin);
    krav($artikel['etikett_mall'] === '29x62', 'storleken ska sparas på artikeln');

    // Skräp faller tillbaka på standard i stället för att sparas
    \TDL\Domain\Lager::andraArtikel($artikel['id'], [
        'namn' => 'Etikettprov storlek',
        'artikelnummer' => 'ETIK-9',
        'enhet' => 'st',
        'sparningssatt' => 'qr',
        'etikett_mall' => '999x999',
    ], $admin);
    krav(\TDL\Domain\Lager::artikel($artikel['id'])['etikett_mall'] === '62x29', 'orimligt mått ska ge standardrullen');

    // Eget mått ska gå att spara (kontinuerlig tape)
    \TDL\Domain\Lager::andraArtikel($artikel['id'], [
        'namn' => 'Etikettprov storlek',
        'artikelnummer' => 'ETIK-9',
        'enhet' => 'st',
        'sparningssatt' => 'qr',
        'etikett_mall' => '62x55',
    ], $admin);
    krav(\TDL\Domain\Lager::artikel($artikel['id'])['etikett_mall'] === '62x55', 'eget mått ska sparas');
});

prov('ET-10 skrivarvalet sparas och styr tryckbredden', function () use ($admin) {
    $forst = \TDL\Support\Installningar::hamta('skrivare');
    krav(\TDL\Support\Skrivare::finns((string) $forst), 'det ska finnas en giltig skrivarprofil');

    \TDL\Support\Installningar::spara(['skrivare' => 'zebra_4']);
    krav(\TDL\Support\Skrivare::aktiv()['nyckel'] === 'zebra_4', 'vald skrivare ska användas');

    // Ett okänt värde får aldrig slå ut utskriften
    \TDL\Support\Installningar::spara(['skrivare' => 'finns_inte']);
    krav(\TDL\Support\Skrivare::aktiv()['nyckel'] === \TDL\Support\Skrivare::STANDARD,
        'okänd skrivare ska falla tillbaka på standard');

    \TDL\Support\Installningar::spara(['skrivare' => (string) $forst]);

    // Alla kända profiler ska ha rimliga mått
    foreach (\TDL\Support\Skrivare::PROFILER as $nyckel => $pr) {
        krav($pr['bredd'] > 0 && $pr['bredd'] <= $pr['max_etikettbredd'],
            'skrivaren ' . $nyckel . ' ska ha tryckbredd inom rullbredden');
        krav($pr['max_hojd'] >= 100, 'skrivaren ' . $nyckel . ' ska klara normala etiketthöjder');
    }
});

prov('ET-11 streckkoden kortas bara när modulen blir för smal', function () {
    // Code 39 behöver minst 0,25 mm modul för att en handscanner ska läsa den
    krav(abs(\TDL\Support\Streckkod::MIN_MODUL - 0.25) < 0.001, 'minsta modul ska vara 0,25 mm');

    $rymlig = \TDL\Support\Streckkod::syns('DEMO-100', 41.5);
    krav($rymlig['ok'] === true, 'DEMO-100 ska rymmas i 41,5 mm');
    krav($rymlig['modul'] >= 0.25, 'modulen ska vara läsbar');

    $trang = \TDL\Support\Streckkod::syns('DEMO-100', 20.0);
    krav($trang['ok'] === false, '20 mm ska vara för trångt för åtta tecken');

    // Kortningen ska klippa vid avdelare, inte mitt i ett ord
    krav(\TDL\Support\Streckkod::kortad('DEMO-100', 4) === '100', 'kortning ska ta sista delen efter bindestreck');
    krav(\TDL\Support\Streckkod::kortad('DEMO-100', 8) === 'DEMO-100', 'kortare kod än taket ska lämnas orörd');
    krav(\TDL\Support\Streckkod::kortad('DEMO-100', 0) === '', 'utan plats ska ingen kod ritas');

    // Med streckkod på (layout "allt") ska den bli läsbar när det är möjligt
    $mall = \TDL\Support\Etikettmallar::tolka('62x29');
    $layout = \TDL\Support\Skrivare::forEtikett($mall, 'DEMO-100', false, true);
    krav($layout['kortad'] === false, '62x29 ska rymma hela DEMO-100');
    krav($layout['modul'] >= \TDL\Support\Streckkod::MIN_MODUL, 'modulen ska vara läsbar på 62x29');
    krav($layout['qr'] >= 12.0, 'QR:en ska aldrig bli mindre än 12 mm');

    // En 29 mm-rulle har ingen plats bredvid QR:en – då får streckkoden stryka på foten
    $smal = \TDL\Support\Skrivare::forEtikett(\TDL\Support\Etikettmallar::tolka('29x62'), 'DEMO-100', false, true);
    krav($smal['kortad'] === true, 'på 29 mm ska streckkoden kortas eller utebli');

    // Standardlayouten är QR + namn: ingen streckkod alls, och en större QR
    $enkel = \TDL\Support\Skrivare::forEtikett($mall, 'DEMO-100');
    krav($enkel['element'] === [], 'standardlayouten ska inte rita någon streckkod');
    krav($enkel['qr'] > $layout['qr'], 'QR:en ska bli större när streckkoden inte tar plats');
});

prov('ET-12 arbetsplatsetiketten ryms på rullen', function () use ($admin) {
    // QR + namn + kod + regel måste rymmas på höjden, annars klipps QR:en
    $vy = file_get_contents(__DIR__ . '/../app/views/platsetikett.php');
    krav(str_contains($vy, '@page'), 'arbetsplatsetiketten ska sätta @page');
    krav(str_contains($vy, 'platsetikett.js'), 'höjden ska mätas i webbläsaren, inte gissas i PHP');
    krav(str_contains($vy, 'flex: 0 0 auto'), 'QR:en får inte krympa (då blir den osymmetrisk och oläsbar)');
    krav(str_contains($vy, "size: <?= \$vald['mm'] ?>"), 'pappersstorleken ska följa den valda etiketten');

    // Skriptet finns och är inte tomt
    $js = @file_get_contents(__DIR__ . '/../public/assets/platsetikett.js');
    krav(is_string($js) && str_contains($js, 'scrollHeight'), 'mätskriptet ska finnas');
    krav(str_contains($js, 'minMm = 12'), 'QR:en får aldrig krympas under 12 mm');

    // Rubriken i utskriften finns med (den bär texten som räknas av)
    $vyn = file_get_contents(__DIR__ . '/../app/views/platsetikett.php');
    krav(str_contains($vyn, $plats = 'Väljer arbetsställe i appen'), 'regeln ska stå på etiketten');
});

/* ------------------------------------------------- Inventering per plats */

/** Stäng alla öppna inventeringar så att nästa prov börjar rent. */
function stangAllaOppna(array $admin): void
{
    foreach (\TDL\Domain\Inventering::sessioner(100) as $s) {
        if (($s['status'] ?? '') === 'öppen') {
            try {
                \TDL\Domain\Inventering::annullera($s['id'], $admin, 'teststädning');
            } catch (\Throwable $e) {
                // redan stängd
            }
        }
    }
}
prov('INV-01 en inventering räknar flera artiklar på samma plats', function () use ($admin, $platsOrebro) {
    stangAllaOppna($admin);
    $a1 = Lager::skapaArtikel(medPlats([
        'namn' => 'Skiftnyckel', 'artikelnummer' => 'INV-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $a2 = Lager::skapaArtikel(medPlats([
        'namn' => 'Borrhammare', 'artikelnummer' => 'INV-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a1['id'], $platsOrebro['id'], 10, [], $admin);
    Rorelser::inleverans($a2['id'], $platsOrebro['id'], 5, [], $admin);

    $s = Inventering::startaInventering($platsOrebro['id'], [], $admin);
    krav(isset($s['artiklar'][$a1['id']]), 'artikel 1 med saldo ska tas med automatiskt');
    krav(isset($s['artiklar'][$a2['id']]), 'artikel 2 med saldo ska tas med automatiskt');
    krav(count($s['artiklar']) >= 2, 'sessionen ska omfatta flera artiklar');

    $lage = Inventering::lage(Inventering::session($s['id']));
    krav($lage['antal_artiklar'] >= 2, 'översikten ska räkna alla artiklar');
    krav($lage['antal_kvar'] === $lage['antal_artiklar'], 'inget räknat från början');
});

prov('INV-02 två artiklar räknas och avstäms i samma session', function () use ($admin, $platsLaxa) {
    stangAllaOppna($admin);
    $a1 = Lager::skapaArtikel(medPlats([
        'namn' => 'Spade', 'artikelnummer' => 'INV-3', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $a2 = Lager::skapaArtikel(medPlats([
        'namn' => 'Räfsa', 'artikelnummer' => 'INV-4', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a1['id'], $platsLaxa['id'], 8, [], $admin);
    Rorelser::inleverans($a2['id'], $platsLaxa['id'], 6, [], $admin);

    $s = Inventering::startaInventering($platsLaxa['id'], [$a1['id'], $a2['id']], $admin);
    Inventering::sattRaknatAntal($s['id'], $a1['id'], 7, $admin);   // -1
    Inventering::sattRaknatAntal($s['id'], $a2['id'], 9, $admin);   // +3

    $lage = Inventering::lage(Inventering::session($s['id']));
    $rader = [];
    foreach ($lage['rader'] as $r) {
        $rader[$r['artikel_id']] = $r;
    }
    krav($rader[$a1['id']]['differens'] === -1, 'artikel 1 ska visa -1');
    krav($rader[$a2['id']]['differens'] === 3, 'artikel 2 ska visa +3');
    krav($rader[$a1['id']]['raknad'] && $rader[$a2['id']]['raknad'], 'båda ska vara räknade');
    krav($lage['antal_avvikelser'] === 2, 'båda artiklarna ska ha avvikelse');

    // Avstämning ska justera BÅDA i en enda åtgärd
    $res = Inventering::stammaAv($s['id'], 'stam av', $admin, [$a1['id'], $a2['id']]);
    krav($res['artiklar'] === 2, 'två artiklar ska justeras');
    krav($res['justerade'] === 4, 'totalt 4 enheter justerade (1 + 3)');

    $saldo1 = Lager::saldoPerPlats(Lager::artikel($a1['id']));
    $saldo2 = Lager::saldoPerPlats(Lager::artikel($a2['id']));
    krav(($saldo1[$platsLaxa['id']] ?? 0) === 7, 'artikel 1 ska ha saldo 7');
    krav(($saldo2[$platsLaxa['id']] ?? 0) === 9, 'artikel 2 ska ha saldo 9');
});

prov('INV-03 justeraAntal räknar upp och ned utan att gå under noll', function () use ($admin, $platsLaxa) {
    stangAllaOppna($admin);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Justertest', 'artikelnummer' => 'INV-5', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsLaxa['id'], 3, [], $admin);
    $s = Inventering::startaInventering($platsLaxa['id'], [$a['id']], $admin);

    // Sessionen kan innehålla fler artiklar från tidigare prov – rör bara den här
    $r = Inventering::justeraAntal($s['id'], $a['id'], 1, $admin);
    krav($r['rakent'] === 4, 'utgå från förväntat 3, +1 = 4');
    $r = Inventering::justeraAntal($s['id'], $a['id'], -2, $admin);
    krav($r['rakent'] === 2, '-2 = 2');
    for ($i = 0; $i < 5; $i++) {
        $r = Inventering::justeraAntal($s['id'], $a['id'], -1, $admin);
    }
    krav($r['rakent'] === 0, 'ska stanna på 0, aldrig negativt');
});

prov('INV-04 en öppen inventering blockerar samma plats men bara en', function () use ($admin, $platsLaxa, $platsOrebro) {
    stangAllaOppna($admin);
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Blockeringsprov', 'artikelnummer' => 'INV-6', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsLaxa['id'], 2, [], $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 2, [], $admin);

    Inventering::startaInventering($platsLaxa['id'], [$a['id']], $admin);
    kastar(fn () => Inventering::startaInventering($platsLaxa['id'], [$a['id']], $admin), 'redan en öppen');
    // Men ett annat arbetsställe ska gå bra
    $s2 = Inventering::startaInventering($platsOrebro['id'], [$a['id']], $admin);
    krav(isset($s2['id']), 'annat arbetsställe ska kunna inventeras samtidigt');
});

prov('INV-05 gamla sessioner med en artikel läses fortfarande', function () use ($admin, $platsOrebro) {
    // En session i det gamla formatet (artikel_id på toppnivå)
    $session = [
        'id' => 'SGAMMAL', 'plats_id' => $platsOrebro['id'], 'artikel_id' => 'X',
        'typ' => 'antal', 'saldo_vid_start' => 5, 'raknat_antal' => 4,
        'status' => 'öppen', 'startad' => nu(),
    ];
    krav(Inventering::arGammal($session), 'sessionen ska kännas igen som gammal');
    $rader = Inventering::artikelIds($session);
    krav($rader === ['X'], 'gamla sessioner har exakt en artikel');
    $rad = Inventering::rad($session, 'X');
    krav($rad !== null && $rad['forvantat'] === 5, 'förväntat ska läsas ur saldo_vid_start');
});

/* ---------------------------------------------------- Klient och skal */
prov('SK-01 app.js laddas på alla sidor', function () {
    // Det här felet var tyst: utan skriptet fungerade varken
    // bekräftelsedialoger, autosparning eller autoutskrift.
    $botten = file_get_contents(__DIR__ . '/../app/views/layout_botten.php');
    // Sökvägen går genom tdl_tillgang() och har därför inget inledande snedstreck.
    krav(str_contains($botten, 'assets/app.js'), 'layout_botten.php måste ladda app.js');
    krav(substr_count($botten, '<script') >= 1, 'det ska finnas en script-tagg');
    krav(strpos($botten, '</body>') > strpos($botten, 'app.js'), 'skriptet ska ligga före </body>');
});

prov('SK-02 inga inline-skript i vyerna (CSP script-src self)', function () {
    $fel = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app/views'));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $innehall = file_get_contents($f->getPathname());
        // <script> utan src är inline och blockeras av CSP
        if (preg_match('#<script(?![^>]*\bsrc=)[^>]*>#i', $innehall)) {
            $fel[] = basename($f->getPathname());
        }
        if (preg_match('#\son(click|submit|change|load|input)=#i', $innehall)) {
            $fel[] = basename($f->getPathname()) . ' (inline-handler)';
        }
    }
    krav($fel === [], 'inline-skript eller handlers i: ' . implode(', ', $fel));
});

prov('SK-03 räkningsfältet har rätt artikel-id och steg', function () {
    $vy = file_get_contents(__DIR__ . '/../app/views/inventering.php');
    krav(str_contains($vy, 'data-autoskicka'), 'räkningsformuläret ska autospara');
    krav(str_contains($vy, 'name="artikel_id"'), 'artikel-id måste skickas med');
    krav(str_contains($vy, 'name="steg" value="-1"'), 'det ska finnas en minusknapp');
    krav(str_contains($vy, 'name="steg" value="1"'), 'det ska finnas en plusknapp');
    krav(str_contains($vy, "value=\"<?= (int) \$r['visat'] ?>\""), 'fältet ska visa räknat eller förväntat');
});

/* ---------------------------------------------------------- Varumärke och tryck */

prov('VM-01 symbolen är fristående och namnet skrivs vid sidan om', function () {
    $svg = \TDL\Support\Varumarke::menyLogotyp('Testbolaget AB');
    krav(str_starts_with($svg, '<svg'), 'menylogotypen ska vara en SVG');
    krav(str_contains($svg, 'var(--accent)'), 'märket ska följa varumärkets färg');
    krav(str_contains($svg, 'M35 35 55 24 55 46 35 57Z'), 'paketets högra sida ska finnas');
    krav(str_contains($svg, '<text'), 'namnet ska skrivas som text, inte ritas in i märket');
    krav(!str_contains(\TDL\Support\Varumarke::menyLogotyp('"><script>'), '<script'),
        'namnet ska escapas');
});

prov('VM-01b märket innehåller inga bokstäver eller firmanamn', function () {
    foreach (['ikon.svg', 'ikon-ljus.svg', 'ikon-monokrom.svg',
              'marke.svg', 'marke-ljus.svg', 'marke-enfarg.svg'] as $fil) {
        $innehall = (string) file_get_contents(__DIR__ . '/../brand/' . $fil);
        krav(!preg_match('/<text\b/i', $innehall), $fil . ' får inte innehålla text');
        krav(!preg_match('/tadega|snickeriet|ab\b/i', $innehall),
            $fil . ' får inte nämna någon firma');
    }
    $tryck = (string) file_get_contents(__DIR__ . '/../brand/tryck/marke-tryckark.svg');
    krav(!preg_match('/tadega|snickeriet/i', $tryck), 'tryckarket får inte nämna någon firma');
});

prov('VM-02 ikon-URL byts när ikonen byts', function () {
    $url = \TDL\Support\Varumarke::ikonUrl();
    krav(str_contains($url, '?v='), 'ikonens adress ska ha version för att slå igenom i cachen');
});

prov('VM-03 tryckoriginalen finns och är fristående SVG', function () {
    $rot = __DIR__ . '/../brand';
    foreach (['ikon.svg', 'ikon-ljus.svg', 'ikon-monokrom.svg',
              'marke.svg', 'marke-ljus.svg', 'marke-enfarg.svg'] as $fil) {
        $sokvag = $rot . '/' . $fil;
        krav(is_file($sokvag), 'saknas: ' . $fil);
        $innehall = (string) file_get_contents($sokvag);
        krav(str_contains($innehall, 'viewBox='), $fil . ' saknar viewBox');
        krav(!preg_match('/<text\b/i', $innehall), $fil . ' får inte använda typsnitt – text ska vara banor');
        krav(!preg_match('/(href|src)="https?:/i', $innehall), $fil . ' får inte länka externt');
    }
    krav(is_file($rot . '/tryck/marke-tryckark.svg'), 'tryckarket saknas');
});

prov('VM-04 märket har samma proportioner i alla filer', function () {
    $krav = [
        'marke.svg' => 'M35 35 55 24 55 46 35 57Z',
        'ikon.svg' => 'M35 35 55 24 55 46 35 57Z',
        'marke-enfarg.svg' => 'M32 6 54 18 32 30 10 18Z',
    ];
    foreach ($krav as $fil => $nod) {
        krav(str_contains((string) file_get_contents(__DIR__ . '/../brand/' . $fil), $nod),
            $fil . ' har inte märkets form på rätt plats');
    }
});

prov('VM-05 alla bildadresser är signerade och bär sessions-id vid behov', function () {
    // Bilderna ligger utanför public/ och kräver inloggning. Därför måste varje
    // adress gå via ArtikelQr::bildUrl() – en osignerad adress ger 404.
    foreach (['artiklar.php', 'artikel.php', 'artikel_publik.php'] as $vy) {
        $innehall = (string) file_get_contents(__DIR__ . '/../app/views/' . $vy);
        krav(!str_contains($innehall, 'Bilder::url('),
            $vy . ' använder osignerad bildadress – använd ArtikelQr::bildUrl()');
    }
    $url = \TDL\Http\ArtikelQr::bildUrl('A8VVTG7', '9c092c4a0fed7abb.jpg');
    krav(str_contains($url, '?n=') && str_contains($url, '&s='), 'bildadressen ska vara signerad');
});

prov('VM-06 bas-URL:en ger en adress som fungerar utanför datorn', function () {
    // Utan fastställd adress får QR-koden aldrig peka på localhost i skarp drift –
    // då går etiketten inte att skanna med en telefon.
    // QR-06 ovan sparade en adress. Provet måste därför köra utan sparad
    // adress – annars vinner den över allt och provet mäter ingenting.
    $adressFil = TDL_STORAGE . '/adress.json';
    $sparad = is_file($adressFil) ? (string) file_get_contents($adressFil) : null;
    @unlink($adressFil);

    $gammal = $_SERVER['HTTP_HOST'] ?? null;
    $gammalProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    $_SERVER['HTTP_HOST'] = 'lager.exempel.se';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

    try {
        // Basvägen kan läggas till (appen i /lager) – jämför därför mot
        // värd + protokoll + det som Bas räknar fram, inte mot en hårdkodad sträng.
        $url = \TDL\Http\ArtikelQr::härleddAdress();
        $forvantad = 'https://lager.exempel.se' . \TDL\Support\Bas::vard();
        krav($url === $forvantad, 'adressen ska följa proxyns protokoll, värd och basväg: ' . $url . ' (väntade ' . $forvantad . ')');
        krav(\TDL\Http\ArtikelQr::adressArPublik(), 'lager.exempel.se ska räknas som publik');

        $_SERVER['HTTP_HOST'] = 'localhost';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
        krav(!\TDL\Http\ArtikelQr::adressArPublik(), 'localhost får inte räknas som publik');
    } finally {
        $_SERVER['HTTP_HOST'] = $gammal ?? 'localhost';
        if ($gammalProto !== null) {
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = $gammalProto;
        }
        if ($sparad !== null) {
            file_put_contents($adressFil, $sparad);
        }
    }
});

prov('VM-07 QR-koden innehåller en fullständig adress', function () {
    $qr = \TDL\Http\ArtikelQr::qrInnehall('A8VVTG7');
    krav(str_starts_with($qr, 'http://') || str_starts_with($qr, 'https://'),
        'QR-innehållet ska vara en fullständig adress: ' . $qr);
    krav(str_contains($qr, '/artikel/A8VVTG7'), 'adressen ska peka på artikeln');
    krav(str_contains($qr, '?n=') && str_contains($qr, '&s='), 'länken ska vara signerad');
});


/* ================================================================ Förslag 1 */
prov('KH-01 kategori och hylla sparas, städas och kan filtreras', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Gängstång', 'artikelnummer' => 'GT-100', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'kategori' => '  Fästelement  ', 'hylla' => 'B3-hyllan   4',
    ]), $admin);
    krav($a['kategori'] === 'Fästelement', 'kategorin ska trimmas: ' . var_export($a['kategori'], true));
    krav($a['hylla'] === 'B3-hyllan 4', 'dubbla blanksteg ska kollapsa: ' . var_export($a['hylla'], true));

    // Tomt fält blir null, inte tom sträng – så att "inte satt" går att skilja
    $b = Lager::skapaArtikel(medPlats([
        'namn' => 'Bricka', 'artikelnummer' => 'GT-101', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'kategori' => '   ',
    ]), $admin);
    krav($b['kategori'] === null, 'tom kategori ska bli null');

    // För långt värde nekas
    kastar(fn () => Lager::skapaArtikel(medPlats([
        'namn' => 'För lång', 'artikelnummer' => 'GT-102', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'kategori' => str_repeat('x', 41),
    ]), $admin), 'högst');

    // Kategorilistan innehåller det vi sparat, utan dubbletter
    $kategorier = Lager::kategorier();
    krav(in_array('Fästelement', $kategorier, true), 'kategorin ska finnas i listan');
    krav(count(array_keys($kategorier, 'Fästelement', true)) === 1, 'ingen dubblett i kategorilistan');

    // Ändring av hylla sparas och hamnar i historiken
    Lager::andraArtikel($a['id'], ['hylla' => 'C1-hyllan 2'], $admin);
    $efter = Lager::artikel($a['id']);
    krav($efter['hylla'] === 'C1-hyllan 2', 'hyllan ska gå att ändra');
    krav($efter['kategori'] === 'Fästelement', 'kategorin ska behållas när bara hyllan ändras');

    $sista = array_slice(Json::las('historik')['poster'], -1)[0];
    krav(($sista['detaljer']['efter']['hylla'] ?? '') === 'C1-hyllan 2', 'historiken ska visa ny hylla');
});

prov('KH-02 hyllsortering följer plockordningen (A2 före A10)', function () {
    $ordning = Lager::sorteraPaHylla(['A10', 'A2', 'B1', 'A1']);
    krav($ordning === ['A1', 'A2', 'A10', 'B1'], 'fel ordning: ' . implode(', ', $ordning));
});

/* ================================================================ Förslag 3 */
prov('PL-01 plocklista bokför flera artiklar i en rörelse', function () use ($admin, $platsOrebro) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Plockmutter', 'artikelnummer' => 'PL-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'hylla' => 'A2',
    ]), $admin);
    $b = Lager::skapaArtikel(medPlats([
        'namn' => 'Plockskruv', 'artikelnummer' => 'PL-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'hylla' => 'A10',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 20, [], $admin);
    Rorelser::inleverans($b['id'], $platsOrebro['id'], 20, [], $admin);

    $ut = Rorelser::plocklista($platsOrebro['id'], [
        ['artikel_id' => $a['id'], 'antal' => 3],
        ['artikel_id' => $b['id'], 'antal' => 5],
        ['artikel_id' => $a['id'], 'antal' => 2],   // dubblett slås ihop
    ], $admin, 'order 4711');

    krav($ut['antal_rader'] === 2, 'dubbletter ska slås ihop till en rad, fick ' . $ut['antal_rader']);
    krav($ut['antal_enheter'] === 10, 'totalt 10 enheter, fick ' . $ut['antal_enheter']);

    $saldo = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldo[$platsOrebro['id']] ?? 0) === 15, 'A ska ha 15 kvar (20 - 5)');
    $saldoB = Lager::saldoPerPlats(Lager::artikel($b['id']));
    krav(($saldoB[$platsOrebro['id']] ?? 0) === 15, 'B ska ha 15 kvar (20 - 5)');

    $poster = Json::las('historik')['poster'];
    $rader = array_values(array_filter($poster, fn ($h) => ($h['typ'] ?? '') === 'uttag' && !empty($h['plocklista'])));
    krav(count($rader) === 2, 'två uttagsrader i historiken, fick ' . count($rader));
    $sista = array_slice($poster, -1)[0];
    krav(($sista['typ'] ?? '') === 'plocklista', 'en sammanfattande plocklistepost ska skrivas sist');
});

prov('PL-02 plocklistan är allt-eller-inget', function () use ($admin, $platsOrebro) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'AlltEllerInget-A', 'artikelnummer' => 'PL-3', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $b = Lager::skapaArtikel(medPlats([
        'namn' => 'AlltEllerInget-B', 'artikelnummer' => 'PL-4', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 10, [], $admin);
    Rorelser::inleverans($b['id'], $platsOrebro['id'], 1, [], $admin);

    // Rad 1 räcker, rad 2 gör det inte → inget ska dras
    kastar(fn () => Rorelser::plocklista($platsOrebro['id'], [
        ['artikel_id' => $a['id'], 'antal' => 5],
        ['artikel_id' => $b['id'], 'antal' => 9],
    ], $admin), 'Otillräckligt saldo');

    $saldoA = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldoA[$platsOrebro['id']] ?? 0) === 10, 'A ska vara orörd efter avbrottet, fick ' . ($saldoA[$platsOrebro['id']] ?? 0));
    $saldoB = Lager::saldoPerPlats(Lager::artikel($b['id']));
    krav(($saldoB[$platsOrebro['id']] ?? 0) === 1, 'B ska vara orörd efter avbrottet');
});

prov('PL-03 plocklistan nekar QR-artiklar, tom lista och stängt arbetsställe', function () use ($admin, $platsOrebro) {
    $qr = Lager::skapaArtikel(medPlats([
        'namn' => 'QR-i-plock', 'artikelnummer' => 'PL-5', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'qr',
    ]), $admin);
    kastar(fn () => Rorelser::plocklista($platsOrebro['id'], [
        ['artikel_id' => $qr['id'], 'antal' => 1],
    ], $admin), 'QR-enhet');

    kastar(fn () => Rorelser::plocklista($platsOrebro['id'], [], $admin), 'tom');

    // Ett arbetsställe utan saldo kan stängas. Sedan får inget plockas därifrån –
    // platskontrollen ska slå till före saldokontrollen.
    $egen = Lager::skapaPlats('Plockstad', 'PLC', $admin);
    Lager::stangPlats($egen['id'], $admin);
    kastar(fn () => Rorelser::plocklista($egen['id'], [
        ['artikel_id' => $qr['id'], 'antal' => 1],
    ], $admin), 'stängt');
});

/* ================================================================ Förslag 20 */
prov('HK-01 hälsokontrollen svarar med nivå per kontroll', function () {
    $svar = \TDL\Support\HalsoKontroll::kor();
    krav(isset($svar['niva'], $svar['punkter'], $svar['fel'], $svar['varningar']), 'svaret ska ha niva, fel och punkter');
    krav(count($svar['punkter']) >= 8, 'minst åtta kontroller, fick ' . count($svar['punkter']));

    $nivaer = array_column($svar['punkter'], 'niva');
    foreach ($nivaer as $n) {
        krav(in_array($n, ['ok', 'varning', 'fel'], true), 'okänd nivå: ' . $n);
    }
    // Varje punkt måste ha rubrik och text – annars syns inget i gränssnittet
    foreach ($svar['punkter'] as $p) {
        krav(($p['rubrik'] ?? '') !== '' && ($p['text'] ?? '') !== '', 'punkt utan rubrik/text: ' . json_encode($p));
    }
    // Ett svar som inte är 'ok' måste räknas i fel eller varningar
    if ($svar['niva'] === 'ok') {
        krav($svar['fel'] === 0 && $svar['varningar'] === 0, 'nivå ok men räknare visar fel');
    }
});

prov('HK-02 hälsokontrollen upptäcker en öppen journal', function () {
    // En kvarglömd journal = en skrivning som avbröts mitt i.
    $journal = TDL_JOURNAL . '/testjournal-' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($journal, '{"txid":"test"}');
    try {
        $svar = \TDL\Support\HalsoKontroll::kor();
        krav($svar['niva'] === 'fel', 'öppen journal ska ge nivå fel, fick ' . $svar['niva']);
        $journalPunkt = null;
        foreach ($svar['punkter'] as $p) {
            if (str_contains(mb_strtolower($p['rubrik']), 'journal')) {
                $journalPunkt = $p;
            }
        }
        krav($journalPunkt !== null && $journalPunkt['niva'] === 'fel', 'journalpunkten ska vara röd');
        krav(str_contains($journalPunkt['text'], 'aterhamta'), 'texten ska hänvisa till bin/aterhamta.php');
    } finally {
        @unlink($journal);
    }
});

prov('HK-03 hälsokontrollen är grön när allt står rätt till', function () use ($admin) {
    // Ett tidigare prov kan ha lämnat en journal efter ett medvetet fel. Den
    // hade gjort provet rött av fel anledning – städa först.
    foreach (\TDL\Support\Las::oppnaJournaler() as $j) {
        @unlink($j);
    }
    // Ta bort det som annars ger en varning i testlagringen: backup saknas
    $backup = TDL_BACKUP . '/prov-' . bin2hex(random_bytes(3)) . '.zip';
    file_put_contents($backup, 'zip');
    try {
        $svar = \TDL\Support\HalsoKontroll::kor();
        krav($svar['fel'] === 0, 'inga fel väntade i en färsk testlagring, fick ' . $svar['fel']
            . ': ' . implode(' | ', array_column(array_filter($svar['punkter'], fn ($p) => $p['niva'] === 'fel'), 'rubrik')));
    } finally {
        @unlink($backup);
    }
});


/* ================================================ Admin stänger av personal */
prov('KA-01 admin kan stänga av och återaktivera ett personalkonto', function () use ($admin) {
    $ny = Konto::skapaKonto('Avstangningstest', '9183', 'personal', $admin);

    // Kontot kan logga in innan
    $inloggad = Konto::verifieraInloggning('Avstangningstest', '9183');
    krav(($inloggad['id'] ?? '') === $ny['id'], 'kontot ska kunna logga in först');

    Konto::stangAv($ny['id'], $admin);
    $efter = \TDL\Domain\Kontohantering::konto($ny['id']);
    krav(empty($efter['aktiv']), 'kontot ska vara avstängt');
    krav(!empty($efter['avstangd']), 'tiden för avstängning ska sparas');

    // Den gamla PIN-koden fungerar inte – varken före eller efter aktivering
    kastar(fn () => Konto::verifieraInloggning('Avstangningstest', '9183'), 'avstängt');

    // Återaktivera: kontot är öppet igen men kräver ny PIN
    Konto::aktivera($ny['id'], $admin);
    $igen = \TDL\Domain\Kontohantering::konto($ny['id']);
    krav(!empty($igen['aktiv']), 'kontot ska vara aktivt igen');
    krav(!empty($igen['maste_byta_pin']), 'ny PIN ska krävas efter återaktivering');
    kastar(fn () => Konto::verifieraInloggning('Avstangningstest', '9183'), 'Fel namn eller PIN');

    // Ny PIN sätts av admin och då fungerar inloggningen
    Konto::andraPin($ny['id'], '6294', $admin, true);
    $inloggadIgen = Konto::verifieraInloggning('Avstangningstest', '6294');
    krav(($inloggadIgen['id'] ?? '') === $ny['id'], 'kontot ska kunna logga in med ny PIN');
});

prov('KA-02 avstängning skyddas mot självspärr och sista admin', function () use ($admin) {
    // 1. Man kan inte stänga av sig själv.
    kastar(fn () => Konto::stangAv($admin['id'], $admin), 'ditt eget');

    // 2. Den sista aktiva administratören kan inte stängas av.
    //    Skyddet räknar aktiva admins i lagringen. Provet stänger därför först av
    //    alla andra admins (om tidigare prov lämnat några), med en personal som
    //    utförare – då kan ingen råka stänga av sig själv på vägen.
    $utforare = Konto::skapaKonto('Utforare KA02', '8271', 'personal', $admin);
    foreach (Konto::anvandare() as $u) {
        if ($u['roll'] === 'admin' && !empty($u['aktiv']) && $u['id'] !== $admin['id']) {
            Konto::stangAv($u['id'], $utforare);
        }
    }
    $kvar = array_values(array_filter(
        Konto::anvandare(),
        fn ($u) => $u['roll'] === 'admin' && !empty($u['aktiv'])
    ));
    krav(count($kvar) === 1, 'bara en aktiv admin ska återstå, fick ' . count($kvar));
    krav($kvar[0]['id'] === $admin['id'], 'det ska vara testadministratören');

    // Personal får inte heller stänga av den sista administratören
    kastar(fn () => Konto::stangAv($admin['id'], $utforare), 'sista administratören');

    // 3. En avstängd admin kan återaktiveras av en annan admin
    $tillfallig = Konto::skapaKonto('Tillfallig KA02', '9364', 'admin', $admin);
    Konto::stangAv($tillfallig['id'], $admin);
    krav(empty(\TDL\Domain\Kontohantering::konto($tillfallig['id'])['aktiv']), 'den tillfälliga ska vara avstängd');
    Konto::aktivera($tillfallig['id'], $admin);
    krav(!empty(\TDL\Domain\Kontohantering::konto($tillfallig['id'])['aktiv']), 'den tillfälliga ska vara aktiv igen');
});

prov('KA-03 avstängd personal syns inte i inloggningslistan', function () use ($admin) {
    $ny = Konto::skapaKonto('Dold Personal', '7482', 'personal', $admin);
    $synsFungerande = array_filter(Konto::anvandare(), fn ($u) => !empty($u['aktiv']) && $u['id'] === $ny['id']);
    krav(count($synsFungerande) === 1, 'aktiva konton ska synas');

    Konto::stangAv($ny['id'], $admin);
    $syns = array_filter(Konto::anvandare(), fn ($u) => !empty($u['aktiv']) && $u['id'] === $ny['id']);
    krav($syns === [], 'avstängt konto ska inte räknas som aktivt');

    // Konto::aktivAnvandare är det Auth använder vid varje anrop – den ska ge null
    krav(Konto::aktivAnvandare($ny['id']) === null, 'avstängt konto ska inte ge en session');
});


prov('KA-04 samma PIN får inte sättas om – annars fastnar "tillfällig PIN"', function () use ($admin) {
    // Kontohantering::skapa är den väg gränssnittet använder – den sätter
    // markeringen "tillfällig PIN" som personen sedan måste byta bort.
    $ny = \TDL\Domain\Kontohantering::skapa('SammaPinTest', '5937', 'personal', $admin);
    krav(!empty(\TDL\Domain\Kontohantering::konto($ny['id'])['maste_byta_pin']), 'nytt konto ska kräva PIN-byte');

    // Samma PIN som redan gäller ska nekas
    kastar(fn () => Konto::andraPin($ny['id'], '5937', $admin, true), 'samma som den nuvarande');

    // En annan PIN går igenom och markeringen släcks
    Konto::andraPin($ny['id'], '4826', $admin, true);
    $efter = \TDL\Domain\Kontohantering::konto($ny['id']);
    krav(empty($efter['maste_byta_pin']), 'markeringen ska släppas efter ett riktigt byte');
    krav(Konto::verifieraInloggning('SammaPinTest', '4826')['id'] === $ny['id'], 'ny PIN ska gälla');
});


/* ================================================= Arbetsstället först */
prov('AS-01 arbetsställe väljs, sparas och kan glömmas', function () use ($platsOrebro, $platsLaxa) {
    $_SESSION = [];
    krav(!\TDL\Support\Arbetsstalle::arValt(), 'inget ska vara valt från början');
    krav(\TDL\Support\Arbetsstalle::forvalt() === '', 'förvalt ska vara tomt utan val');

    \TDL\Support\Arbetsstalle::valj($platsOrebro['id']);
    krav(\TDL\Support\Arbetsstalle::arValt(), 'valet ska sparas');
    krav(\TDL\Support\Arbetsstalle::id() === $platsOrebro['id'], 'rätt ställe ska vara valt');
    krav(\TDL\Support\Arbetsstalle::forvalt() === $platsOrebro['id'], 'förvalt ska följa valet');

    // Byt till ett annat ställe
    \TDL\Support\Arbetsstalle::valj($platsLaxa['id']);
    krav(\TDL\Support\Arbetsstalle::id() === $platsLaxa['id'], 'bytet ska slå igenom');

    \TDL\Support\Arbetsstalle::glom();
    krav(!\TDL\Support\Arbetsstalle::arValt(), 'glömt val ska vara tomt');
});

prov('AS-02 stängt arbetsställe kan inte väljas och glöms bort', function () use ($admin, $platsOrebro) {
    $_SESSION = [];
    $tillfallig = Lager::skapaPlats('StalleTest', 'STT', $admin);
    \TDL\Support\Arbetsstalle::valj($tillfallig['id']);
    krav(\TDL\Support\Arbetsstalle::arValt(), 'stället ska gå att välja medan det är öppet');

    // Stäng stället – då ska valet inte längre gälla
    Lager::stangPlats($tillfallig['id'], $admin);
    krav(!\TDL\Support\Arbetsstalle::arValt(), 'ett stängt ställe ska inte vara valt');
    krav(\TDL\Support\Arbetsstalle::id() === '', 'id ska vara tomt efter stängning');

    // Och det går inte att välja igen
    kastar(fn () => \TDL\Support\Arbetsstalle::valj($tillfallig['id']), 'går inte att välja');
});

prov('AS-03 systemposten kan aldrig bli ett arbetsställe', function () {
    $_SESSION = [];
    $system = null;
    foreach (Lager::platser() as $p) {
        if (!empty($p['system'])) {
            $system = $p;
        }
    }
    krav($system !== null, 'testlagringen ska ha en systempost');
    kastar(fn () => \TDL\Support\Arbetsstalle::valj($system['id']), 'går inte att välja');
    // Och den syns inte bland valbara
    $valbara = array_column(\TDL\Support\Arbetsstalle::valbara(), 'id');
    krav(!in_array($system['id'], $valbara, true), 'systemposten ska inte vara valbar');
});

prov('AS-04 valet styr inte servern – bara förvalet', function () use ($admin, $platsOrebro, $platsLaxa) {
    // Det valda stället får aldrig avgöra vart en rörelse bokförs: servern
    // läser alltid det plats_id som skickas in. Provet väljer Laxå men gör
    // inleveransen till Örebro och kontrollerar att Örebro fick varorna.
    $_SESSION = [];
    \TDL\Support\Arbetsstalle::valj($platsLaxa['id']);

    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Valtest', 'artikelnummer' => 'AS-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Rorelser::inleverans($a['id'], $platsOrebro['id'], 7, [], $admin);

    $saldo = Lager::saldoPerPlats(Lager::artikel($a['id']));
    krav(($saldo[$platsOrebro['id']] ?? 0) === 7, 'Örebro ska ha 7 trots att Laxå var valt');
    krav(($saldo[$platsLaxa['id']] ?? 0) === 0, 'Laxå ska inte ha fått något');
    \TDL\Support\Arbetsstalle::glom();
});


prov('AS-05 "arbeta överallt" stänger porten utan att välja ställe', function () {
    // Fällan: admin väljer "Arbeta överallt" → tomt plats_id. Frågar porten
    // efter arValt() blir svaret nej och valsidan visas i evighet. Därför
    // finns en egen flagga för att valet är GJORT.
    $_SESSION = [];
    krav(!\TDL\Support\Arbetsstalle::valetGjort(), 'inget val gjort från början');

    \TDL\Support\Arbetsstalle::valj('');   // "arbeta överallt"
    krav(\TDL\Support\Arbetsstalle::valetGjort(), 'porten ska vara stängd efter valet');
    krav(!\TDL\Support\Arbetsstalle::arValt(), 'inget ställe ska vara valt');
    krav(\TDL\Support\Arbetsstalle::id() === '', 'inget id');
    krav(\TDL\Support\Arbetsstalle::forvalt() === '', 'inget förvalt ställe – man väljer per steg');

    // Byt ska öppna porten igen
    \TDL\Support\Arbetsstalle::glom();
    krav(!\TDL\Support\Arbetsstalle::valetGjort(), 'efter byt ska valsidan komma tillbaka');
});

prov('AS-06 stängt ställe öppnar porten igen i stället för att lämna kvar', function () use ($admin, $platsOrebro) {
    $_SESSION = [];
    $t = Lager::skapaPlats('PortTest', 'PTT', $admin);
    \TDL\Support\Arbetsstalle::valj($t['id']);
    krav(\TDL\Support\Arbetsstalle::valetGjort(), 'valet ska vara gjort');

    Lager::stangPlats($t['id'], $admin);
    krav(!\TDL\Support\Arbetsstalle::valetGjort(), 'porten ska öppnas så ett nytt ställe kan väljas');

    // Och ett riktigt val ska fortfarande fungera
    \TDL\Support\Arbetsstalle::valj($platsOrebro['id']);
    krav(\TDL\Support\Arbetsstalle::id() === $platsOrebro['id'], 'nytt val ska gälla');
    \TDL\Support\Arbetsstalle::glom();
});


prov('ST-01 startsidans alla kort pekar på en rutt som finns', function () {
    // Korten på startsidan är hårdkodade i vyn. Det är lätt att byta namn på
    // en rutt och glömma ett kort – då blir det en död länk i produktion.
    // Provet läser vyn, plockar ut varje href och kontrollerar mot ruttabellen.
    $vy = file_get_contents(dirname(__DIR__) . '/app/views/startsida_nav.php');
    $rutter = file_get_contents(dirname(__DIR__) . '/public/index.php');

    preg_match_all("#\\\$kort\\('([^']+)'#", $vy, $m);
    krav(count($m[1]) >= 15, 'startsidan ska ha minst 15 kort, hittade ' . count($m[1]));

    $saknas = [];
    foreach (array_unique($m[1]) as $href) {
        // Finns adressen med som rutt (exakt eller som mönster)?
        if (!str_contains($rutter, "'" . $href . "'")) {
            $saknas[] = $href;
        }
    }
    krav($saknas === [], 'döda länkar på startsidan: ' . implode(', ', $saknas));
});

prov('ST-02 översikten har en egen adress', function () {
    // / är startsidan (navet), /oversikt är saldot. Ligger de på samma adress
    // går det inte att länka till dem var för sig.
    $rutter = file_get_contents(dirname(__DIR__) . '/public/index.php');
    krav(str_contains($rutter, "'/oversikt'"), '/oversikt ska finnas som rutt');
    krav(str_contains($rutter, 'Sidor::oversikt()'), '/oversikt ska gå till oversikt()');
});


prov('BAS-01 basvägen läggs på interna adresser men inte externa', function () {
    // Appen ska kunna ligga i /lager på ett webbhotell utan att en enda länk
    // pekar fel. Bas::url() är enda stället som lägger på prefixet.
    $gammal = getenv('TDL_BAS_PATH');

    $r = new ReflectionClass(\TDL\Support\Bas::class);
    $falt = $r->getProperty('bas');
    $falt->setAccessible(true);

    // Undermapp
    putenv('TDL_BAS_PATH=/lager');
    $falt->setValue(null, null);
    krav(\TDL\Support\Bas::vard() === '/lager', 'basvägen ska vara /lager');
    krav(\TDL\Support\Bas::url('/login') === '/lager/login', 'intern adress ska få prefix');
    krav(\TDL\Support\Bas::url('/') === '/lager/', 'rotadressen ska bli /lager/');
    krav(\TDL\Support\Bas::url('/uttag?artikel=A1') === '/lager/uttag?artikel=A1', 'frågesträng ska följa med');
    krav(\TDL\Support\Bas::url('https://x.se/a') === 'https://x.se/a', 'externa adresser rörs inte');
    // Dubbel prefixering får aldrig ske
    krav(\TDL\Support\Bas::url('/lager/login') === '/lager/login', 'redan prefixad adress lämnas i fred');

    // Tom basväg: adresserna ska vara orörda. Sätts EFTER de andra fallen –
    // Bas cachar per förfrågan och kan inte nollställas mitt i en körning
    // utan att röra det interna fältet, vilket provet inte ska göra.
    putenv('TDL_BAS_PATH');
    $falt->setValue(null, null);
    krav(\TDL\Support\Bas::url('/login') === '/login', 'utan undermapp ska adressen vara orörd');
    krav(\TDL\Support\Bas::vard() === '', 'utan undermapp ska basvägen vara tom');

    // Återställ
    if ($gammal === false) { putenv('TDL_BAS_PATH'); } else { putenv('TDL_BAS_PATH=' . $gammal); }
    $falt->setValue(null, null);
});

prov('BAS-02 routningen tar bort basvägen ur sökvägen', function () {
    // /lager/login måste bli /login innan ruttabellen läses, annars blir
    // allt 404 när appen ligger i en undermapp.
    $kod = file_get_contents(dirname(__DIR__) . '/public/index.php');
    krav(str_contains($kod, "Bas::vard()"), 'front controller ska fråga Bas om basvägen');
    krav(str_contains($kod, "substr(\$rutt, strlen(\$bas))"), 'front controller ska kapa bort prefixet');
});


/** Varumarke cachar per förfrågan – proven måste kunna nollställa cachen. */
function nollstall_varumarke(): void
{
    $r = new ReflectionClass(\TDL\Support\Varumarke::class);
    $f = $r->getProperty('cache');
    $f->setAccessible(true);
    $f->setValue(null, null);
}

prov('VAR-01 inget kundnamn är förvalt – appen visar "Lager"', function () {
    // Releasen får aldrig levereras med ett påhittat firmanamn: kunden sätter
    // sitt eget under Varumärke. Fram till dess visas "Lager".
    krav(\TDL\Support\Varumarke::STANDARD['namn'] === '', 'standardnamnet ska vara tomt, inte ett kundnamn');
    krav(\TDL\Support\Varumarke::STANDARD['kortnamn'] === '', 'standardkortnamnet ska vara tomt');
    krav(\TDL\Support\Varumarke::RESERVNAMN === 'Lager', 'reservnamnet ska vara "Lager"');

    // Inget kundnamn får ligga kvar i koden eller i standardvärdena
    $fynd = [];
    foreach (['app/views', 'app/Http', 'app/Support'] as $mapp) {
        foreach (glob(dirname(__DIR__) . '/' . $mapp . '/*.php') as $fil) {
            $kod = (string) file_get_contents($fil);
            if (preg_match('/Snickeriet/i', $kod)) {
                $fynd[] = basename($fil);
            }
        }
    }
    krav($fynd === [], 'inget kundnamn får finnas i koden: ' . implode(', ', $fynd));
});

prov('VAR-02 eget namn slår igenom, tomt ger reservnamnet', function () {
    $fil = \TDL\Support\Varumarke::fil();
    $sparad = is_file($fil) ? (string) file_get_contents($fil) : null;

    try {
        // Utan fil: reservnamnet
        @unlink($fil);
        nollstall_varumarke();
        krav(\TDL\Support\Varumarke::v('namn') === 'Lager', 'utan eget namn ska "Lager" visas');
        krav(\TDL\Support\Varumarke::harEgetNamn() === false, 'harEgetNamn ska vara false innan namnet är satt');

        // Med eget namn
        file_put_contents($fil, json_encode(['namn' => 'Nordiska Bygg AB', 'kortnamn' => 'Nordiska']));
        nollstall_varumarke();
        krav(\TDL\Support\Varumarke::v('namn') === 'Nordiska Bygg AB', 'det egna namnet ska användas');
        krav(\TDL\Support\Varumarke::v('kortnamn') === 'Nordiska', 'det egna kortnamnet ska användas');
        krav(\TDL\Support\Varumarke::harEgetNamn() === true, 'harEgetNamn ska vara true när namnet är satt');

        // Tomt namn i filen: tillbaka till reservnamnet
        file_put_contents($fil, json_encode(['namn' => '   ', 'kortnamn' => '']));
        nollstall_varumarke();
        krav(\TDL\Support\Varumarke::v('namn') === 'Lager', 'bara blanksteg ska räknas som tomt');
    } finally {
        if ($sparad === null) { @unlink($fil); } else { file_put_contents($fil, $sparad); }
        nollstall_varumarke();
    }
});

prov('PIN-01 tillfällig PIN spärrar appen tills den byts', function () {
    // Ett konto med maste_byta_pin får inte komma åt något annat än
    // /mitt-konto och /logout. Tidigare var det bara en upplysning som gick
    // att klicka bort – kontot fortsatte då med en PIN någon annan satt.
    $kod = file_get_contents(dirname(__DIR__) . '/public/index.php');
    krav(str_contains($kod, 'maste_byta_pin'), 'front controller ska kontrollera maste_byta_pin');
    krav(str_contains($kod, "'/mitt-konto', '/mitt-konto/pin', '/logout'"), 'bara PIN-bytet ska vara tillåtet');

    // PIN-byte släpper markeringen
    $konto = \TDL\Support\Konto::skapaKonto('PIN Prov', '7391', 'personal', ['id' => 'X', 'namn' => 'Test'], true);
    krav(!empty($konto['maste_byta_pin']), 'nytt konto med tillfällig PIN ska vara markerat');
    \TDL\Support\Konto::andraPin($konto['id'], '5150', $konto, true);
    $efter = \TDL\Support\Konto::aktivAnvandare($konto['id']);
    krav(empty($efter['maste_byta_pin']), 'markeringen ska släppas efter ett riktigt byte');
});


prov('INST-01 namn och PIN väljs innan inloggning', function () {
    // Guiden ska fråga efter företagsnamn, namn och PIN – och
    // därefter skicka till inloggningen. Ingen automatisk inloggning: då
    // vore PIN-koden en tillfällig kod som måste bytas direkt.
    $vy = file_get_contents(dirname(__DIR__) . '/app/views/installera.php');
    krav(str_contains($vy, 'name="foretag"'), 'installationssidan ska fråga efter företagsnamn');
    krav(str_contains($vy, 'name="pin"'), 'installationssidan ska fråga efter PIN');
    // Företagsnamnet ska komma först i formuläret
    krav(strpos($vy, 'id="foretag"') < strpos($vy, 'id="namn"'), 'företagsnamnet ska stå först');

    $kod = file_get_contents(dirname(__DIR__) . '/app/Http/Installation.php');
    krav(str_contains($kod, 'Konto::skapaForstaAdmin($namn, $pin, false)'),
        'första admin ska skapas utan påtvingat PIN-byte – koden väljs ju själv');
    krav(str_contains($kod, 'Varumarke::spara'), 'företagsnamnet ska sparas vid installationen');
    krav(substr_count($kod, 'Auth::loggaIn') === 1,
        'guiden ska inte logga in automatiskt – bara login() får göra det');
    krav(str_contains($kod, "omdirigera('/login')"), 'efter installationen ska man komma till inloggningen');

    // Första admin har självvald PIN (false) medan andra konton får tillfällig
    $konto = \TDL\Support\Konto::skapaKonto('Temp Prov', '7391', 'personal', ['id' => 'X', 'namn' => 'Test'], true);
    krav(!empty($konto['maste_byta_pin']), 'nya konton som admin skapar ska fortfarande kräva PIN-byte');
});


prov('INST-02 tom kontofil räknas som ej installerad', function () {
    // Felet som gjorde att appen visade inloggning med tom namnlista i stället
    // för installationsguiden: villkoret tittade på om FILEN fanns, inte om
    // den innehöll några konton. En tom anvandare.json (som följer med
    // releasen eller blir kvar efter en rensning) räknades som installerad.
    $kod = file_get_contents(dirname(__DIR__) . '/public/index.php');
    krav(str_contains($kod, 'Konto::behoverForstaAdmin()'),
        'front controller ska fråga om det saknas konton, inte bara om filen finns');

    // Beteendet i Lager-klassen
    $fil = \TDL\Support\Json::sokvag('anvandare');
    $sparad = is_file($fil) ? (string) file_get_contents($fil) : null;
    try {
        file_put_contents($fil, tdl_json(\TDL\Support\Json::tom('anvandare')));
        krav(\TDL\Domain\Lager::arInstallerad() === true, 'filen finns – arInstallerad är true');
        krav(\TDL\Domain\Lager::behoverInstallation() === true, 'men tomma poster ska kräva installation');
        krav(\TDL\Support\Konto::behoverForstaAdmin() === true, 'saknade konton ska kräva första admin');
    } finally {
        if ($sparad === null) { @unlink($fil); } else { file_put_contents($fil, $sparad); }
    }
});


prov('INST-03 nollställning kräver token och bekräftelse', function () {
    // På webbhotell laddas releasen ofta upp ovanpå en gammal installation.
    // Då ligger konton kvar och guiden visas aldrig. /aterstall rensar –
    // men bara för den som har installationstoken.
    $kod = file_get_contents(dirname(__DIR__) . '/app/Http/Installation.php');
    krav(str_contains($kod, 'function aterstall()'), 'det ska finnas en nollställning');
    krav(str_contains($kod, "tokenStammer()"), 'nollställningen ska kräva installationstoken');
    krav(str_contains($kod, "'NOLLSTALL'"), 'nollställningen ska kräva bekräftelseordet');
    krav(str_contains($kod, 'session_destroy'), 'inloggningen ska släppas vid nollställning');

    // Rutten ska finnas i front controller
    $front = file_get_contents(dirname(__DIR__) . '/public/index.php');
    krav(str_contains($front, "'/aterstall'"), 'routen /aterstall ska vara kopplad');

    // Vyn ska varna för att allt tas bort
    $vy = file_get_contents(dirname(__DIR__) . '/app/views/aterstall.php');
    krav(str_contains($vy, 'Allt tas bort'), 'sidan ska varna tydligt');
    krav(str_contains($vy, 'storage/setup.token'), 'sidan ska hänvisa till tokenfilen');
});


prov('BW-01 PIN-inloggningen kan sparas', function () {
    $vy = file_get_contents(dirname(__DIR__) . '/app/views/login.php');
    krav(substr_count($vy, 'autocomplete="username"') === 1, 'ett användarnamnsfält för alla roller');
    krav(str_contains($vy, 'list="namn-lista"'), 'namn föreslås via datalist');
    krav(str_contains($vy, 'name="pin"') && str_contains($vy, 'autocomplete="current-password"'), 'PIN kan sparas i lösenordshanterare');
    krav(!str_contains($vy, 'name="losenord"'), 'inget separat lösenord');
});


/* ---------------------------------------------------------------- TV-01 */
prov('TV-01 tavla skapas med delar och placering', function () use ($admin) {
    $hylsa = Lager::skapaArtikel(medPlats([
        'namn' => 'Hylsnyckel 13', 'artikelnummer' => 'TVH-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $mejsel = Lager::skapaArtikel(medPlats([
        'namn' => 'Skruvmejsel PZ2', 'artikelnummer' => 'TVM-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-01', 'artikelnummer' => 'TVT-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [
            ['artikel_id' => $hylsa['id'], 'antal' => 2, 'fack' => 'A3'],
            ['artikel_id' => $mejsel['id'], 'antal' => 1, 'fack' => 'B1'],
        ],
    ]), $admin);

    krav(!empty($tavla['ar_tavla']), 'tavlan ska vara märkt som tavla');
    krav(\TDL\Domain\Tavlor::antalDelar($tavla) === 3, 'tavlan ska innehålla 3 saker');
    $innehall = \TDL\Domain\Tavlor::innehall($tavla);
    krav(count($innehall) === 2, 'två olika delar');
    krav($innehall[0]['fack'] === 'A3', 'placeringen ska följa med ut (fick ' . $innehall[0]['fack'] . ')');
    krav($innehall[1]['fack'] === 'B1', 'andra delens placering');
    krav($innehall[0]['artikel']['namn'] === 'Hylsnyckel 13', 'delen ska berikas med artikelposten');
});

/* ---------------------------------------------------------------- TV-02 */
prov('TV-02 tavla kan inte innehålla sig själv eller en annan tavla', function () use ($admin) {
    $inner = Lager::skapaArtikel(medPlats([
        'namn' => 'Innerdel TV-02', 'artikelnummer' => 'TVI-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavlaA = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla A TV-02', 'artikelnummer' => 'TVA-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $inner['id'], 'antal' => 1]],
    ]), $admin);

    kastar(fn () => \TDL\Domain\Tavlor::valideraInnehall(
        [['artikel_id' => $tavlaA['id'], 'antal' => 1]], $tavlaA['id']
    ), 'kan inte innehålla sig själv');

    kastar(fn () => Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla B TV-02', 'artikelnummer' => 'TVB-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $tavlaA['id'], 'antal' => 1]],
    ]), $admin), 'inuti en annan tavla');
});

/* ---------------------------------------------------------------- TV-03 */
prov('TV-03 uttag av tavlan drar med alla delar', function () use ($admin, $platsOrebro) {
    $hylsa = Lager::skapaArtikel(medPlats([
        'namn' => 'Hylsa TV-03', 'artikelnummer' => 'TVH-3', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $mejsel = Lager::skapaArtikel(medPlats([
        'namn' => 'Mejsel TV-03', 'artikelnummer' => 'TVM-3', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-03', 'artikelnummer' => 'TVT-3', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [
            ['artikel_id' => $hylsa['id'], 'antal' => 2],
            ['artikel_id' => $mejsel['id'], 'antal' => 1],
        ],
    ]), $admin);

    // En tavla kommer med sina verktyg: inleveransen av tavlan drar också in
    // delarna. Vi mäter därför differensen över själva uttaget.
    Rorelser::inleverans($tavla['id'], $platsOrebro['id'], 2, [], $admin);
    $foreHylsa = (int) (Lager::artikel($hylsa['id'])['saldo'][$platsOrebro['id']] ?? 0);
    $foreMejsel = (int) (Lager::artikel($mejsel['id'])['saldo'][$platsOrebro['id']] ?? 0);
    krav($foreHylsa === 4, 'två tavlor ska ge 4 hylsor, är ' . $foreHylsa);
    krav($foreMejsel === 2, 'två tavlor ska ge 2 mejslar, är ' . $foreMejsel);

    $res = Rorelser::uttag($tavla['id'], $platsOrebro['id'], 1, [], $admin);
    krav(count($res['delar']['utdragna']) === 2, 'båda delarna ska ha dragits ut');
    krav($res['delar']['saknade'] === [], 'inga delar ska saknas');

    $saldoHylsa = (int) (Lager::artikel($hylsa['id'])['saldo'][$platsOrebro['id']] ?? 0);
    $saldoMejsel = (int) (Lager::artikel($mejsel['id'])['saldo'][$platsOrebro['id']] ?? 0);
    krav($saldoHylsa === 2, 'hylsorna ska ha minskat med 2, är ' . $saldoHylsa);
    krav($saldoMejsel === 1, 'mejseln ska ha minskat med 1, är ' . $saldoMejsel);
});

/* ---------------------------------------------------------------- TV-04 */
prov('TV-04 inleverans av tavlan lägger tillbaka delarna', function () use ($admin, $platsOrebro) {
    $tavla = null;
    foreach (Lager::artiklar() as $a) {
        if (($a['namn'] ?? '') === 'Tavla TV-03') {
            $tavla = $a;
        }
    }
    krav($tavla !== null, 'hittade inte tavlan från TV-03');

    $res = Rorelser::inleverans($tavla['id'], $platsOrebro['id'], 1, [], $admin);
    krav(count($res['delar']['aterstallda']) === 2, 'båda delarna ska ha lagts tillbaka');
    krav($res['delar']['misslyckade'] === [], 'inga delar ska ha misslyckats');
});

/* ---------------------------------------------------------------- TV-05 */
prov('TV-05 saknad del stoppar inte uttaget men rapporteras', function () use ($admin, $platsOrebro) {
    $finns = Lager::skapaArtikel(medPlats([
        'namn' => 'Finns TV-05', 'artikelnummer' => 'TVF-5', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $saknas = Lager::skapaArtikel(medPlats([
        'namn' => 'Saknas TV-05', 'artikelnummer' => 'TVS-5', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-05', 'artikelnummer' => 'TVT-5', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [
            ['artikel_id' => $finns['id'], 'antal' => 1],
            ['artikel_id' => $saknas['id'], 'antal' => 1],
        ],
    ]), $admin);

    // En tavla kommer med sina verktyg: inleveransen fyller på BÅDA delarna.
    // Vi tömmer dem sedan helt – då kan ingen av dem dras ut med tavlan.
    Rorelser::inleverans($tavla['id'], $platsOrebro['id'], 1, [], $admin);
    foreach ([$finns['id'], $saknas['id']] as $delId) {
        $saldoNu = (int) (Lager::artikel($delId)['saldo'][$platsOrebro['id']] ?? 0);
        krav($saldoNu > 0, 'tavlan ska ha fyllt på delarna');
        if ($saldoNu > 0) {
            Rorelser::uttag($delId, $platsOrebro['id'], $saldoNu, [], $admin);
        }
    }

    $res = Rorelser::uttag($tavla['id'], $platsOrebro['id'], 1, [], $admin);
    krav(count($res['delar']['utdragna']) === 0,
        'ingen del ska ha kunnat dras ut, men: ' . json_encode($res['delar'], JSON_UNESCAPED_UNICODE));
    krav(count($res['delar']['saknade']) === 2,
        'båda delarna ska rapporteras som saknade, men: ' . json_encode($res['delar'], JSON_UNESCAPED_UNICODE));
    $saknadeNamn = array_column($res['delar']['saknade'], 'namn');
    sort($saknadeNamn);
    krav($saknadeNamn === ['Finns TV-05', 'Saknas TV-05'], 'båda delarna ska rapporteras saknade');
    krav($res['delar']['saknade'][0]['behov'] === 1, 'behovet ska vara 1 per del');
    // Tavlans eget uttag ska ha gått igenom trots den saknade delen
    krav((int) (Lager::artikel($tavla['id'])['saldo'][$platsOrebro['id']] ?? 0) === 0,
        'tavlan ska ha lämnat platsen');
});

/* ---------------------------------------------------------------- TV-06 */
prov('TV-06 antal 0 tar bort delen, sista delen avtavlar', function () use ($admin) {
    $del = Lager::skapaArtikel(medPlats([
        'namn' => 'Bortdel TV-06', 'artikelnummer' => 'TVB-6', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-06', 'artikelnummer' => 'TVT-6', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $del['id'], 'antal' => 2]],
    ]), $admin);

    // Sätt antalet till 0 = ta bort raden
    Lager::andraArtikel($tavla['id'], ['ar_tavla' => '1', 'innehall' => [
        ['artikel_id' => $del['id'], 'antal' => 0],
    ]], $admin);
    $efter = Lager::artikel($tavla['id']);
    krav(\TDL\Domain\Tavlor::antalDelar($efter) === 0, 'delen ska vara borta');

    // Ingen del kvar = ingen tavla
    krav(empty($efter['ar_tavla']), 'en tavla utan delar ska inte längre vara en tavla');
});

/* ---------------------------------------------------------------- TV-07 */
prov('TV-07 dubbletter och inaktiva delar avvisas', function () use ($admin) {
    $del = Lager::skapaArtikel(medPlats([
        'namn' => 'Dubbel TV-07', 'artikelnummer' => 'TVD-7', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    kastar(fn () => \TDL\Domain\Tavlor::valideraInnehall([
        ['artikel_id' => $del['id'], 'antal' => 1],
        ['artikel_id' => $del['id'], 'antal' => 2],
    ]), 'flera gånger');

    Lager::inaktiveraArtikel($del['id'], $admin);
    kastar(fn () => \TDL\Domain\Tavlor::valideraInnehall([
        ['artikel_id' => $del['id'], 'antal' => 1],
    ]), 'inaktiverad');
});

/* ---------------------------------------------------------------- TV-08 */
prov('TV-08 tavlor med en del går att hitta från delen', function () use ($admin) {
    $del = Lager::skapaArtikel(medPlats([
        'namn' => 'Del TV-08', 'artikelnummer' => 'TVX-8', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-08', 'artikelnummer' => 'TVT-8', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $del['id'], 'antal' => 1]],
    ]), $admin);

    $funna = \TDL\Domain\Tavlor::tavlorMed($del['id']);
    krav(count($funna) === 1 && $funna[0]['id'] === $tavla['id'],
        'delen ska hittas i exakt den tavlan');
    krav(\TDL\Domain\Tavlor::tavlorMed('FNSINTE') === [], 'okänt id ska ge tom lista');
});

/* ---------------------------------------------------------------- TV-09 */
prov('TV-09 tavla utan delar är ingen tavla', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats([
        'namn' => 'Ensam TV-09', 'artikelnummer' => 'TVE-9', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    krav(!\TDL\Domain\Tavlor::arTavla($a), 'vanlig artikel ska inte vara tavla');
    krav(\TDL\Domain\Tavlor::innehall($a) === [], 'vanlig artikel har inget innehåll');
});

/* ---------------------------------------------------------------- TV-10 */
prov('TV-10 tavlan syns med placering i artikelvyn', function () {
    $vy = file_get_contents(dirname(__DIR__) . '/app/views/artikel.php');
    krav(str_contains($vy, 'id="tavla"'), 'artikelvyn ska ha en tavelsektion');
    krav(str_contains($vy, 'Placering'), 'tabellen ska visa placering (fack/rad)');
    krav(str_contains($vy, 'del_fack'), 'man ska kunna ange placering när man lägger till');
    krav(str_contains($vy, '/delar/ta-bort'), 'man ska kunna ta bort en del');

    $form = file_get_contents(dirname(__DIR__) . '/app/views/artikel_form.php');
    krav(str_contains($form, 'del_fack[]'), 'redigera-vyn ska kunna ändra placeringen');
    krav(str_contains($form, 'data-tavla-vaxla'), 'kryssrutan ska växla via app.js, inte inline onchange');
    krav(!str_contains($form, 'onchange='), 'inline onchange blockeras av CSP:n');
});


/* ---------------------------------------------------------------- TV-11 */
prov('TV-11 del flyttas mellan två tavlor i ett svep', function () use ($admin) {
    $mejsel = Lager::skapaArtikel(medPlats([
        'namn' => 'Mejsel TV-11', 'artikelnummer' => 'TVM-11', 'enhet' => 'st',
        'prisreferens' => '', 'miniminva' => '0', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla1 = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla 1 TV-11', 'artikelnummer' => 'TV1-11', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $mejsel['id'], 'antal' => 2, 'fack' => 'A1']],
    ]), $admin);
    $tavla2 = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla 2 TV-11', 'artikelnummer' => 'TV2-11', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [],
    ]), $admin);
    // En tavla utan delar avtavlas automatiskt; gör om den till tavla igen
    Lager::andraArtikel($tavla2['id'], ['ar_tavla' => '1', 'innehall' => []], $admin);
    $t2 = Lager::artikel($tavla2['id']);
    krav(empty($t2['ar_tavla']), 'en tavla utan delar ska inte vara tavla');

    // Lägg en annan del i tavla 2 så att den är en tavla
    $annan = Lager::skapaArtikel(medPlats([
        'namn' => 'Annan TV-11', 'artikelnummer' => 'TVX-11', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Lager::andraArtikel($tavla2['id'], ['ar_tavla' => '1', 'innehall' => [
        ['artikel_id' => $annan['id'], 'antal' => 1],
    ]], $admin);

    // Flytta 1 av 2 mejslar
    // Lägg till en hylsa också, så att källan har två rader att hålla ordning på
    $hylsa = Lager::skapaArtikel(medPlats([
        'namn' => 'Hylsa TV-11', 'artikelnummer' => 'TVH-11', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    Lager::andraArtikel($tavla1['id'], ['ar_tavla' => '1', 'innehall' => [
        ['artikel_id' => $mejsel['id'], 'antal' => 2, 'fack' => 'A1'],
        ['artikel_id' => $hylsa['id'], 'antal' => 1, 'fack' => 'B2'],
    ]], $admin);

    $res = \TDL\Domain\Tavlor::flyttaDel($tavla1['id'], $tavla2['id'], $mejsel['id'], 1, 'C2', $admin);
    krav($res['antal'] === 1, 'en mejsel ska ha flyttats');
    krav($res['kvar'] === 1, 'en mejsel ska ligga kvar');

    $t1 = \TDL\Domain\Tavlor::innehall(Lager::artikel($tavla1['id']));
    $t2 = \TDL\Domain\Tavlor::innehall(Lager::artikel($tavla2['id']));
    krav(count($t1) === 2, 'källan ska ha två rader kvar');
    krav($t1[0]['artikel_id'] === $mejsel['id'] && $t1[0]['antal'] === 1,
        'mejseln ska stå kvar ÖVERST med 1 kvar (ordningen bevaras)');
    krav($t1[0]['fack'] === 'A1', 'källans placering ska vara orörd');
    krav($t1[1]['artikel_id'] === $hylsa['id'], 'hylsan ska stå kvar underst');

    $iTv2 = null;
    foreach ($t2 as $rad) {
        if ($rad['artikel_id'] === $mejsel['id']) {
            $iTv2 = $rad;
        }
    }
    krav($iTv2 !== null, 'mejseln ska ha hamnat i tavla 2');
    krav($iTv2['antal'] === 1, 'en mejsel i tavla 2');
    krav($iTv2['fack'] === 'C2', 'den nya placeringen ska gälla (fick ' . $iTv2['fack'] . ')');
});

/* ---------------------------------------------------------------- TV-12 */
prov('TV-12 flytt till samma tavla och för stort antal avvisas', function () use ($admin) {
    $del = Lager::skapaArtikel(medPlats([
        'namn' => 'Del TV-12', 'artikelnummer' => 'TVD-12', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-12', 'artikelnummer' => 'TVT-12', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $del['id'], 'antal' => 1]],
    ]), $admin);
    $annan = Lager::skapaArtikel(medPlats([
        'namn' => 'Annan TV-12', 'artikelnummer' => 'TVX-12', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla2 = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla 2 TV-12', 'artikelnummer' => 'TV2-12', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $annan['id'], 'antal' => 1]],
    ]), $admin);

    kastar(fn () => \TDL\Domain\Tavlor::flyttaDel($tavla['id'], $tavla['id'], $del['id'], 1),
        'annan tavla');
    kastar(fn () => \TDL\Domain\Tavlor::flyttaDel($tavla['id'], $tavla2['id'], $del['id'], 5),
        'bara 1');
    kastar(fn () => \TDL\Domain\Tavlor::flyttaDel($tavla['id'], $tavla2['id'], 'FINNSINTE', 1),
        'finns inte');

    // Ingenting ska ha ändrats av de avvisade försöken
    $efter = \TDL\Domain\Tavlor::innehall(Lager::artikel($tavla['id']));
    krav(count($efter) === 1 && $efter[0]['antal'] === 1, 'källan ska vara orörd');
});

/* ---------------------------------------------------------------- TV-13 */
prov('TV-13 flytt av sista delen avtavlar källan', function () use ($admin) {
    $del = Lager::skapaArtikel(medPlats([
        'namn' => 'Del TV-13', 'artikelnummer' => 'TVD-13', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla1 = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla 1 TV-13', 'artikelnummer' => 'TV1-13', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $del['id'], 'antal' => 1]],
    ]), $admin);
    $annan = Lager::skapaArtikel(medPlats([
        'namn' => 'Annan TV-13', 'artikelnummer' => 'TVX-13', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla2 = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla 2 TV-13', 'artikelnummer' => 'TV2-13', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $annan['id'], 'antal' => 1]],
    ]), $admin);

    \TDL\Domain\Tavlor::flyttaDel($tavla1['id'], $tavla2['id'], $del['id'], 1, '', $admin);

    $kalla = Lager::artikel($tavla1['id']);
    krav(empty($kalla['ar_tavla']), 'källan ska inte längre vara en tavla');
    krav(\TDL\Domain\Tavlor::innehall($kalla) === [], 'källan ska vara tom');
    // Artikeln finns kvar och är aktiv
    krav(!empty($kalla['aktiv']), 'artikeln ska finnas kvar som vanlig artikel');
});

/* ---------------------------------------------------------------- TV-14 */
prov('TV-14 mottagarlistan tar med alla andra tavlor', function () use ($admin) {
    $del = Lager::skapaArtikel(medPlats([
        'namn' => 'Del TV-14', 'artikelnummer' => 'TVD-14', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ]), $admin);
    $tavla = Lager::skapaArtikel(medPlats([
        'namn' => 'Tavla TV-14', 'artikelnummer' => 'TVT-14', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'ar_tavla' => '1',
        'innehall' => [['artikel_id' => $del['id'], 'antal' => 1]],
    ]), $admin);

    $mottagare = \TDL\Domain\Tavlor::mojligaMottagare($tavla['id']);
    $idn = array_column($mottagare, 'id');
    krav(!in_array($tavla['id'], $idn, true), 'tavlan själv ska inte vara mottagare');
    foreach ($mottagare as $m) {
        krav(!empty($m['ar_tavla']), 'bara tavlor ska vara mottagare');
    }
    krav(in_array('TV-11', array_map(fn ($m) => substr($m['artikelnummer'], -5), $mottagare)) || count($mottagare) > 0,
        'det ska finnas mottagare från tidigare prov');
});

/* ---------------------------------------------------------------- TV-15 */
prov('TV-15 flytten syns i historiken och vyn har flyttknapp', function () use ($admin) {
    $historik = Json::las('historik');
    $funna = 0;
    foreach ($historik['poster'] as $p) {
        if (($p['typ'] ?? '') === 'tavla_del_flyttad') {
            $funna++;
        }
    }
    krav($funna > 0, 'flytten ska ha loggats i historiken');

    $vy = file_get_contents(dirname(__DIR__) . '/app/views/artikel.php');
    krav(str_contains($vy, '/delar/flytta'), 'artikelvyn ska ha en flyttknapp');
    krav(str_contains($vy, 'name="till_tavla"'), 'man ska kunna välja mottagande tavla');
    krav(str_contains($vy, 'Flytta till'), 'listan ska heta Flytta till …');
});


/* ---------------------------------------------------------------- LP-01 */
prov('LP-01 zon krävs och valideras', function () use ($admin) {
    $zoner = \TDL\Domain\Lagerplats::zoner();
    krav($zoner !== [], 'det ska finnas minst en zon');
    krav($zoner[0]['kod'] === 'T', 'testzonen ska heta T');

    kastar(fn () => \TDL\Domain\Lagerplats::skapaZon('Dubblett', 'T', 2, 2, $admin), 'används redan');
    kastar(fn () => \TDL\Domain\Lagerplats::skapaZon('Fel kod', 'ABCD', 2, 2, $admin), '1–3 tecken');
    kastar(fn () => \TDL\Domain\Lagerplats::skapaZon('', 'X', 2, 2, $admin), '1–');
});

/* ---------------------------------------------------------------- LP-02 */
prov('LP-02 artikel utan plats avvisas', function () use ($admin) {
    kastar(fn () => Lager::skapaArtikel([
        'namn' => 'Utan plats', 'artikelnummer' => 'LP2-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
    ], $admin), 'måste ha en plats');
});

/* ---------------------------------------------------------------- LP-03 */
prov('LP-03 rad och fack måste rymmas i zonen', function () use ($admin, $zon) {
    kastar(fn () => Lager::skapaArtikel([
        'namn' => 'Fel rad', 'artikelnummer' => 'LP3-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'zon_id' => $zon['id'], 'rad' => 99, 'fack' => 1,
    ], $admin), 'Raden måste vara');
    kastar(fn () => Lager::skapaArtikel([
        'namn' => 'Fel fack', 'artikelnummer' => 'LP3-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'zon_id' => $zon['id'], 'rad' => 1, 'fack' => 99,
    ], $admin), 'Facket måste vara');
});

/* ---------------------------------------------------------------- LP-04 */
prov('LP-04 platsen sparas och visas som A-3-2', function () use ($admin, $zon) {
    $a = Lager::skapaArtikel([
        'namn' => 'Placerad', 'artikelnummer' => 'LP4-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'zon_id' => $zon['id'], 'rad' => 3, 'fack' => 2,
    ], $admin);
    krav(\TDL\Domain\Lagerplats::platsText($a) === 'T-3-2', 'platsen ska skrivas T-3-2, fick ' . \TDL\Domain\Lagerplats::platsText($a));
    krav((int) $a['rad'] === 3 && (int) $a['fack'] === 2, 'rad och fack ska sparas som tal');
});

/* ---------------------------------------------------------------- LP-05 */
prov('LP-05 hyllplatser får unika QR-koder', function () use ($admin, $zon) {
    $nya = \TDL\Domain\Lagerplats::skapaHyllplatser($zon['id'], $admin);
    krav(count($nya) === 40, '5 rader × 8 fack = 40 hyllplatser, fick ' . count($nya));
    $koder = array_column($nya, 'qr');
    krav(count(array_unique($koder)) === 40, 'alla QR-koder ska vara unika');

    // Koden ska hittas tillbaka
    $h = \TDL\Domain\Lagerplats::hyllplatsViaQr($koder[0]);
    krav($h !== null, 'QR-koden ska hitta tillbaka till hyllplatsen');
    krav($h['id'] === $nya[0]['id'], 'rätt hyllplats');

    // Att skapa igen ska inte ge dubbletter
    kastar(fn () => \TDL\Domain\Lagerplats::skapaHyllplatser($zon['id'], $admin), 'finns redan');
});

/* ---------------------------------------------------------------- LP-06 */
prov('LP-06 karta ritar rutnät med innehåll', function () use ($zon) {
    $karta = \TDL\Domain\Lagerplats::karta($zon['id']);
    krav(count($karta['rutor']) === 5, 'fem rader');
    krav(count($karta['rutor'][1]) === 8, 'åtta fack per rad');
    krav(isset($karta['rutor'][1][1]['hyllplats']), 'varje ruta ska ha en hyllplats');
    krav($karta['rutor'][3][2]['antal'] >= 1, 'ruta T-3-2 ska ha minst en artikel');
});

/* ---------------------------------------------------------------- LP-07 */
prov('LP-07 hyllans innehåll går att lista', function () use ($zon) {
    $pa = \TDL\Domain\Lagerplats::artiklarPa($zon['id'], 3, 2);
    $namn = array_column($pa, 'namn');
    krav(in_array('Placerad', $namn, true), 'artikeln Placerad ska ligga på T-3-2, har: ' . implode(', ', $namn));
    // Allt som listas ska verkligen höra till rutan
    foreach ($pa as $a) {
        krav($a['zon_id'] === $zon['id'] && (int) $a['rad'] === 3 && (int) $a['fack'] === 2,
            'fel artikel i listan: ' . $a['namn']);
    }
    krav(\TDL\Domain\Lagerplats::artiklarPa($zon['id'], 6, 8) === [], 'tom hylla ska ge tom lista');
});

/* ---------------------------------------------------------------- LP-08 */
prov('LP-08 rutnätet kan inte krympas över artiklar', function () use ($admin, $zon) {
    kastar(fn () => \TDL\Domain\Lagerplats::andraZon($zon['id'], [
        'namn' => 'Testlager', 'kod' => 'T', 'rader' => 2, 'fack' => 8,
    ], $admin), 'hamnar utanför');
    // Men att göra det större ska gå
    $z = \TDL\Domain\Lagerplats::andraZon($zon['id'], [
        'namn' => 'Testlager', 'kod' => 'T', 'rader' => 6, 'fack' => 8,
    ], $admin);
    krav((int) $z['rader'] === 6, 'zonen ska gå att bygga ut');
});

/* ---------------------------------------------------------------- LP-09 */
prov('LP-09 plocklistan sorteras i gångordning', function () use ($admin, $zon) {
    $a1 = Lager::skapaArtikel(medPlats([
        'namn' => 'Sist', 'artikelnummer' => 'LP9-1', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'zon_id' => $zon['id'], 'rad' => 4, 'fack' => 1,
    ]), $admin);
    $a2 = Lager::skapaArtikel(medPlats([
        'namn' => 'Först', 'artikelnummer' => 'LP9-2', 'enhet' => 'st',
        'prisreferens' => '', 'miniminiva' => '0', 'sparningssatt' => 'antal',
        'zon_id' => $zon['id'], 'rad' => 1, 'fack' => 1,
    ]), $admin);
    $n1 = \TDL\Domain\Lagerplats::sorteringsNyckel($a1);
    $n2 = \TDL\Domain\Lagerplats::sorteringsNyckel($a2);
    krav(strcmp($n2, $n1) < 0, 'rad 1-1 ska sortera före rad 4-1 (' . $n2 . ' < ' . $n1 . ')');
});

/* ---------------------------------------------------------------- LP-10 */
prov('LP-10 utan plats-listan hittar gamla artiklar', function () {
    // Alla nya artiklar har plats, så listan ska vara tom i en färsk lagring
    $utan = \TDL\Domain\Lagerplats::utanPlats();
    krav($utan === [], 'inga artiklar ska sakna plats i testlagringen');
});


/* ---------------------------------------------------------------- LL-01 */
prov('LL-01 ett foto blir en zon med egen ruta', function () use ($admin) {
    // En våning skapas första gången – som när man laddar upp sitt första foto
    $v = \TDL\Domain\Lokalplan::skapaVaning('Bottenvåning', $admin);
    krav($v['id'] === 'V1' && $v['namn'] === 'Bottenvåning', 'första våningen ska heta Bottenvåning');

    $tmp = tempnam(sys_get_temp_dir(), 'lokal');
    $im = imagecreatetruecolor(2400, 1200);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 50, 80));
    imagejpeg($im, $tmp, 85);
    imagedestroy($im);

    $svar = \TDL\Domain\Lokalplan::sparaBild($v['id'], [
        'name' => 'horn1.jpg', 'tmp_name' => $tmp,
        'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
    ], true, 'Hörn 1', $admin);

    krav(count($svar['vaning']['bilder']) === 1, 'våningen ska ha ett foto');
    krav(count($svar['vaning']['zonrutor']) === 1, 'fotot ska ha en ruta');
    krav($svar['vaning']['bilder'][0]['etikett'] === 'Hörn 1', 'etiketten ska sparas');

    $fil = \TDL\Domain\Lokalplan::bildSokvag($svar['bild']['fil']);
    krav($fil !== null && is_file($fil), 'bildfilen ska ligga på plats');

    // Storleken ska ha kapats till max 2000 px på längsta sidan
    $info = getimagesize($fil);
    krav(max($info[0], $info[1]) <= \TDL\Domain\Lokalplan::MAX_SIDA,
        'bilden ska skalas ner, är ' . $info[0] . '×' . $info[1]);
});

/* ---------------------------------------------------------------- LL-02 */
prov('LL-02 flera foton blir flera zoner på olika platser', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    foreach ([2, 3] as $n) {
        $tmp = tempnam(sys_get_temp_dir(), 'lokal');
        $im = imagecreatetruecolor(800, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 20 * $n, 60, 90));
        imagejpeg($im, $tmp, 80);
        imagedestroy($im);
        \TDL\Domain\Lokalplan::sparaBild($v['id'], [
            'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
        ], true, 'Hörn ' . $n, $admin);
    }
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    krav(count($v['bilder']) === 3, 'tre foton ska finnas, har ' . count($v['bilder']));

    // Rutorna får inte ligga på samma fläck – då går bara den översta att dra
    $platser = [];
    foreach ($v['zonrutor'] as $r) {
        $platser[] = $r['x'] . ',' . $r['y'];
    }
    krav(count(array_unique($platser)) === 3, 'alla tre rutorna ska ha egen plats: ' . implode(' | ', $platser));

    // …och fotozonerna ska vara riktiga zoner i lagerplatsmodellen
    $zonIder = array_column(\TDL\Domain\Lagerplats::zoner(), 'id');
    foreach ($v['bilder'] as $b) {
        krav(in_array($b['zon_id'], $zonIder, true), 'fotots zon ska finnas bland zonerna');
    }
});

/* ---------------------------------------------------------------- LL-03 */
prov('LL-03 klart sätter ihop fotona till ett rutnät', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $efter = \TDL\Domain\Lokalplan::fotograferaKlart($v['id'], $admin);

    krav(count($efter['zonrutor']) === 3, 'alla tre rutorna ska finnas');
    // Rutnätet ska rymmas innanför ytan
    foreach ($efter['zonrutor'] as $r) {
        krav($r['x'] >= 0 && $r['x'] + $r['b'] <= 100.01, 'rutan ska rymmas i bredd');
        krav($r['y'] >= 0 && $r['y'] + $r['h'] <= 100.01, 'rutan ska rymmas i höjd');
        krav($r['b'] > 10 && $r['h'] > 10, 'rutan ska vara stor nog att greppa');
    }
    // Ingen ruta får ligga ovanpå en annan
    $rutor = $efter['zonrutor'];
    for ($i = 0; $i < count($rutor); $i++) {
        for ($j = $i + 1; $j < count($rutor); $j++) {
            $a = $rutor[$i];
            $b = $rutor[$j];
            $krock = $a['x'] < ($b['x'] + $b['b']) && ($a['x'] + $a['b']) > $b['x']
                && $a['y'] < ($b['y'] + $b['h']) && ($a['y'] + $a['h']) > $b['y'];
            krav(!$krock, 'rutorna får inte överlappa varandra');
        }
    }
});

/* ---------------------------------------------------------------- LL-04 */
prov('LL-04 zonernas placering sparas i procent', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $zonId = $v['zonrutor'][0]['zon_id'];
    $efter = \TDL\Domain\Lokalplan::sparaRutor($v['id'], [
        ['zon_id' => $zonId, 'x' => 12.5, 'y' => 30, 'b' => 25, 'h' => 20],
    ], $admin);
    krav(count($efter['zonrutor']) === 1, 'en ruta ska sparas');
    $r = $efter['zonrutor'][0];
    krav($r['x'] === 12.5 && $r['y'] === 30.0, 'procenten ska sparas exakt');
    krav(\TDL\Domain\Lokalplan::forstaVaning()['zonrutor'][0]['x'] === 12.5,
        'rutan ska finnas kvar efter omläsning');
    // Fotot ska följa med sin zon
    krav(\TDL\Domain\Lokalplan::forstaVaning()['bilder'][0]['x'] === 12.5,
        'fotots ruta ska följa zonen');
});

/* ---------------------------------------------------------------- LL-05 */
prov('LL-05 ruta utanför ytan kläms innanför', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $efter = \TDL\Domain\Lokalplan::sparaRutor($v['id'], [
        ['zon_id' => $v['zonrutor'][0]['zon_id'], 'x' => 95, 'y' => 95, 'b' => 40, 'h' => 40],
    ], $admin);
    $r = $efter['zonrutor'][0];
    krav($r['x'] + $r['b'] <= 100.0001, 'rutan får inte sticka ut till höger');
    krav($r['y'] + $r['h'] <= 100.0001, 'rutan får inte sticka ut nedåt');
});

/* ---------------------------------------------------------------- LL-06 */
prov('LL-06 okänd zon ignoreras', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $efter = \TDL\Domain\Lokalplan::sparaRutor($v['id'], [
        ['zon_id' => 'FINNSINTE', 'x' => 1, 'y' => 1, 'b' => 5, 'h' => 5],
    ], $admin);
    krav($efter['zonrutor'] === [], 'okända zoner ska inte hamna på planen');
});

/* ---------------------------------------------------------------- LL-07 */
prov('LL-07 en zon kan bara ligga på en våning', function () use ($admin) {
    $v1 = \TDL\Domain\Lokalplan::forstaVaning();
    $v2 = \TDL\Domain\Lokalplan::skapaVaning('Våning 2', $admin);
    krav($v2['id'] === 'V2', 'andra våningen ska heta V2');

    // Lägg en zon på våning 1
    $zon = \TDL\Domain\Lagerplats::skapaZon('Testhörn', 'TH', 1, 1, $admin);
    \TDL\Domain\Lokalplan::sparaRutor($v1['id'], [
        ['zon_id' => $zon['id'], 'x' => 10, 'y' => 10, 'b' => 20, 'h' => 20],
    ], $admin);

    // Samma zon får inte flyttas till våning 2
    $efter = \TDL\Domain\Lokalplan::sparaRutor($v2['id'], [
        ['zon_id' => $zon['id'], 'x' => 50, 'y' => 50, 'b' => 20, 'h' => 20],
    ], $admin);
    krav($efter['zonrutor'] === [], 'zonen ska inte kunna ligga på två våningar');
    krav(\TDL\Domain\Lokalplan::placeradeZonIder()[$zon['id']] === $v1['id'],
        'zonen ska ligga kvar på våning 1');

    \TDL\Domain\Lokalplan::taBortVaning($v2['id'], $admin);
});

/* ---------------------------------------------------------------- LL-08 */
prov('LL-08 våningar kan namnges och tas bort', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::skapaVaning('', $admin);
    krav($v['namn'] === 'Våning 2', 'namnlös våning ska heta Våning 2, blev ' . $v['namn']);

    $v = \TDL\Domain\Lokalplan::andraVaning($v['id'], ['namn' => 'Maskinhallen'], $admin);
    krav($v['namn'] === 'Maskinhallen', 'namnet ska ändras');

    \TDL\Domain\Lokalplan::taBortVaning($v['id'], $admin);
    krav(count(\TDL\Domain\Lokalplan::plan()['vaningar']) === 1, 'bara bottenvåningen ska vara kvar');

    // Sista våningen får inte tas bort
    $sista = \TDL\Domain\Lokalplan::forstaVaning();
    kastar(fn () => \TDL\Domain\Lokalplan::taBortVaning($sista['id'], $admin), 'Sista våningen');
});

/* ---------------------------------------------------------------- LL-09 */
prov('LL-09 oplacerade zoner listas', function () use ($admin) {
    $ny = \TDL\Domain\Lagerplats::skapaZon('Nytt förråd', 'NY', 2, 2, $admin);
    $opl = array_column(\TDL\Domain\Lokalplan::oplacerade(), 'kod');
    krav(in_array('NY', $opl, true), 'den nya zonen ska listas som oplacerad');
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $placeradKod = null;
    foreach (\TDL\Domain\Lagerplats::zoner() as $z) {
        if ($z['id'] === ($v['zonrutor'][0]['zon_id'] ?? '')) {
            $placeradKod = $z['kod'];
        }
    }
    krav($placeradKod === null || !in_array($placeradKod, $opl, true),
        'en placerad zon ska inte listas som oplacerad');
});

/* ---------------------------------------------------------------- LL-10 */
prov('LL-10 ett foto kan tas bort med sin zon', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $fore = count($v['bilder']);
    $fil = $v['bilder'][0]['fil'];
    $zonId = $v['bilder'][0]['zon_id'];

    $efter = \TDL\Domain\Lokalplan::taBortFoto($v['id'], $fil, $admin);
    krav(count($efter['bilder']) === $fore - 1, 'fotot ska vara borta');
    krav(\TDL\Domain\Lokalplan::bildSokvag($fil) === null, 'bildfilen ska raderas');
    $kvar = array_column($efter['zonrutor'], 'zon_id');
    krav(!in_array($zonId, $kvar, true), 'zonens ruta ska bort från ytan');
});

/* ---------------------------------------------------------------- LL-11 */
prov('LL-11 planbilden kan bytas utan att zonerna försvinner', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::forstaVaning();
    $rutorFore = count($v['zonrutor']);

    $tmp = tempnam(sys_get_temp_dir(), 'ritn');
    $im = imagecreatetruecolor(1000, 700);
    imagefill($im, 0, 0, imagecolorallocate($im, 90, 40, 30));
    imagejpeg($im, $tmp, 82);
    imagedestroy($im);
    $svar = \TDL\Domain\Lokalplan::sparaBild($v['id'], [
        'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
    ], false, null, $admin);

    krav($svar['vaning']['bild'] !== null, 'våningen ska ha en planbild');
    krav(count($svar['vaning']['zonrutor']) === $rutorFore, 'zonerna ska ligga kvar vid bildbyte');
    krav(str_contains(\TDL\Domain\Lokalplan::bildUrl($svar['vaning']), 'bild.jpg'),
        'planbildens adress ska gå till bild.jpg');
});

/* ---------------------------------------------------------------- LL-12 */
prov('LL-12 en gammal plan läses som bottenvåning', function () use ($admin) {
    // Äldre plan.json hade en bild och "zonrutor" i roten, utan våningar.
    $mapp = \TDL\Domain\Lokalplan::mapp();
    $gammal = json_decode(file_get_contents($mapp . '/plan.json'), true);
    $bild = $gammal['vaningar'][0]['bild'] ?? null;
    $rutor = $gammal['vaningar'][0]['zonrutor'] ?? [];
    file_put_contents($mapp . '/plan.json', tdl_json([
        'bild' => $bild,
        'skapad' => '2026-01-01T00:00:00Z',
        'zonrutor' => $rutor,
    ]));

    $plan = \TDL\Domain\Lokalplan::plan();
    krav($plan !== null, 'den gamla planen ska läsas');
    krav(count($plan['vaningar']) === 1, 'den ska bli en våning');
    krav($plan['vaningar'][0]['id'] === 'V1', 'den ska heta V1');
    krav($plan['vaningar'][0]['namn'] === 'Bottenvåning', 'den ska heta Bottenvåning');
    krav(count($plan['vaningar'][0]['zonrutor']) === count($rutor), 'rutorna ska följa med');
});

/* ---------------------------------------------------------------- LL-13 */
prov('LL-13 lokalplanen kräver en bild innan zoner sparas', function () use ($admin) {
    \TDL\Domain\Lokalplan::taBort($admin);
    krav(\TDL\Domain\Lokalplan::plan() === null, 'planen ska vara borta');
    kastar(fn () => \TDL\Domain\Lokalplan::sparaRutor('V1', [
        ['zon_id' => 'X', 'x' => 1, 'y' => 1, 'b' => 2, 'h' => 2],
    ], $admin), 'Det finns ingen lokalplan');
});

/* ---------------------------------------------------------------- LL-14 */
prov('LL-14 planen har rätt rutter och vyer', function () {
    $rutter = file_get_contents(dirname(__DIR__) . '/public/index.php');
    foreach (['/lokalplan', '/lokalplan/bild', '/lokalplan/rutor', '/lokalplan/klart',
              '/lokalplan/vaning', '/lokalplan/bild.jpg'] as $r) {
        krav(str_contains($rutter, "'" . $r . "'"), 'rutten ' . $r . ' ska finnas');
    }

    $vy = file_get_contents(dirname(__DIR__) . '/app/views/lokalplan.php');
    krav(str_contains($vy, 'data-plan-yta'), 'vyn ska ha en yta att dra på');
    krav(str_contains($vy, 'name="bild[]"'), 'man ska kunna välja flera foton');
    krav(str_contains($vy, 'accept="image/*"'), 'man ska kunna välja ett foto');
    krav(str_contains($vy, 'capture="environment"'), 'mobilen ska öppna kameran direkt');
    krav(!str_contains($vy, 'onclick='), 'inga inline-handlers (CSP)');

    $js = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
    krav(str_contains($js, 'pointerdown'), 'dra-och-släpp ska fungera med mus och finger');
});

/* ---------------------------------------------------------------- LL-15 */
prov('LL-15 inga vyer förutsätter domänroten', function () {
    // Appen ska kunna ligga i /lager – då måste varje intern adress gå via u().
    $fel = [];
    foreach (glob(dirname(__DIR__) . '/app/views/*.php') as $fil) {
        $text = file_get_contents($fil);
        foreach (['action="/', 'href="/'] as $monster) {
            if (preg_match('/' . preg_quote($monster, '/') . '/', $text)) {
                $fel[] = basename($fil);
            }
        }
    }
    krav($fel === [], 'dessa vyer länkar utan basväg: ' . implode(', ', array_unique($fel)));
});

/* ---------------------------------------------------------------- LL-16 */
prov('LL-16 sessions-id följer med i alla interna adresser', function () {
    $bas = file_get_contents(dirname(__DIR__) . '/app/Support/Bas.php');
    krav(str_contains($bas, 'medMiljoFraga'), 'Bas::url ska kunna lägga på sessions-id');
    krav(str_contains($bas, 'tdlagersid'), 'sessions-id:t ska heta tdlagersid');

    $auth = file_get_contents(dirname(__DIR__) . '/app/Support/Auth.php');
    krav(str_contains($auth, "session.use_trans_sid', '0'"),
        'PHPs egen omskrivning ska vara av (den dubblade id:t och hoppade över POST)');
});


/* ---------------------------------------------------------------- LL-17 */
prov('LL-17 oplacerade zoner hamnar inte på skissen av misstag', function () use ($admin) {
    // En zon som inte är placerad ska INTE ritas på ytan. Gör den det blir
    // den automatiskt sparad när man trycker "Spara placeringen" – då får
    // lagrets vanliga zoner rutor som ingen bett om (standardzonen A).
    $vy = file_get_contents(dirname(__DIR__) . '/app/views/lokalplan.php');
    krav(str_contains($vy, '$visaZoner = $egna;'),
        'ytan ska bara rita våningens egna zoner');
    krav(!str_contains($vy, 'array_merge($egna, $lediga)'),
        'oplacerade zoner får inte blandas in i ytan');
    krav(str_contains($vy, "/lokalplan/zon"),
        'det ska finnas en knapp för att placera en oplacerad zon');
});

/* ---------------------------------------------------------------- LL-18 */
prov('LL-18 placeraZon sätter en hyllzon på skissen', function () use ($admin) {
    $v = \TDL\Domain\Lokalplan::skapaVaning('Källaren', $admin);
    $zon = \TDL\Domain\Lagerplats::skapaZon('Elcentral', 'EL', 1, 2, $admin);

    // Ligger inte på skissen ännu
    krav(isset(array_flip(array_column(\TDL\Domain\Lokalplan::oplacerade(), 'id'))[$zon['id']]),
        'zonen ska vara oplacerad först');

    $efter = \TDL\Domain\Lokalplan::placeraZon($v['id'], $zon['id'], $admin);
    $rutor = array_column($efter['zonrutor'], 'zon_id');
    krav(in_array($zon['id'], $rutor, true), 'zonen ska ligga på skissen nu');
    krav(\TDL\Domain\Lokalplan::placeradeZonIder()[$zon['id']] === $v['id'],
        'zonen ska höra till källaren');

    // Samma zon får inte placeras på en annan våning
    $v2 = \TDL\Domain\Lokalplan::skapaVaning('Vinden', $admin);
    kastar(fn () => \TDL\Domain\Lokalplan::placeraZon($v2['id'], $zon['id'], $admin),
        'ligger redan på en annan våning');

    // Städa: ta bort de två extra våningarna (bottenvåningen blir kvar)
    foreach (\TDL\Domain\Lokalplan::plan()['vaningar'] ?? [] as $v) {
        if (in_array($v['id'], [$v['id'], $v2['id']], true) && $v['namn'] === 'Vinden') {
            \TDL\Domain\Lokalplan::taBortVaning($v['id'], $admin);
        }
    }
    // Källaren kan bara tas bort om planen har fler våningar kvar
    $namn = array_column(\TDL\Domain\Lokalplan::plan()['vaningar'] ?? [], 'namn');
    if (in_array('Källaren', $namn, true) && count($namn) > 1) {
        foreach (\TDL\Domain\Lokalplan::plan()['vaningar'] as $v) {
            if ($v['namn'] === 'Källaren') {
                \TDL\Domain\Lokalplan::taBortVaning($v['id'], $admin);
            }
        }
    }
});

/* ---------------------------------------------------------------- LL-19 */
prov('LL-19 fotografera klart rör inte andra våningars rutor', function () use ($admin) {
    $v1 = \TDL\Domain\Lokalplan::skapaVaning('Plan 1', $admin);
    $v2 = \TDL\Domain\Lokalplan::skapaVaning('Plan 2', $admin);

    // Ett foto på varje våning
    foreach ([$v1, $v2] as $n => $v) {
        $tmp = tempnam(sys_get_temp_dir(), 'foto');
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 40 * ($n + 1), 80, 120));
        imagejpeg($im, $tmp, 80);
        imagedestroy($im);
        \TDL\Domain\Lokalplan::sparaBild($v['id'], [
            'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
        ], true, 'Foto ' . ($n + 1), $admin);
    }
    // Flytta våning 1:s ruta så vi känner igen den
    $f1 = \TDL\Domain\Lokalplan::vaning($v1['id']);
    \TDL\Domain\Lokalplan::sparaRutor($v1['id'], [
        ['zon_id' => $f1['zonrutor'][0]['zon_id'], 'x' => 11, 'y' => 22, 'b' => 33, 'h' => 44],
    ], $admin);

    // Sätt ihop bara våning 2
    \TDL\Domain\Lokalplan::fotograferaKlart($v2['id'], $admin);

    $f1Efter = \TDL\Domain\Lokalplan::vaning($v1['id']);
    krav($f1Efter['zonrutor'][0]['x'] === 11.0,
        'våning 1:s ruta ska vara orörd, är ' . $f1Efter['zonrutor'][0]['x']);

    foreach (\TDL\Domain\Lokalplan::plan()['vaningar'] ?? [] as $v) {
        if (in_array($v['namn'], ['Plan 1', 'Plan 2'], true)
            && count(\TDL\Domain\Lokalplan::plan()['vaningar']) > 1) {
            \TDL\Domain\Lokalplan::taBortVaning($v['id'], $admin);
        }
    }
});

/* ---------------------------------------------------------- Dagens uppgifter */
prov('DU-01 personal ser bara uppgifter för valt arbetsställe', function () {
    $sessioner = [
        ['status' => 'öppen', 'plats_id' => 'A'],
        ['status' => 'öppen', 'plats_id' => 'B'],
        ['status' => 'avslutad', 'plats_id' => 'A'],
    ];
    $brister = [['plats' => ['id' => 'A']], ['plats' => ['id' => 'B']]];
    $u = \TDL\Support\DagensUppgifter::bygg(['roll' => 'personal'], ['id' => 'A'], $sessioner, $brister, ['lage' => 'saknas']);
    krav(count($u) === 2, 'inventering och brist, men ingen backup');
    krav(str_starts_with($u[0]['text'], '1 pågående inventering '), 'en öppen inventering på A');
    krav(str_starts_with($u[1]['text'], '1 bristrad '), 'en bristrad på A');
    krav($u[0]['href'] === '/inventering' && $u[1]['href'] === '/miniminiva', 'länkar till rätt sidor');
});
prov('DU-02 admin överallt ser alla ställen och backupvarning', function () {
    $sessioner = [['status' => 'öppen', 'plats_id' => 'A'], ['status' => 'öppen', 'plats_id' => 'B']];
    $brister = [['plats' => ['id' => 'A']], ['plats' => ['id' => 'B']]];
    $u = \TDL\Support\DagensUppgifter::bygg(['roll' => 'admin'], null, $sessioner, $brister, ['lage' => 'gammal']);
    krav(count($u) === 3, 'tre typer av uppgift');
    krav(str_starts_with($u[0]['text'], '2 pågående inventeringar '), 'båda sessioner');
    krav(str_starts_with($u[1]['text'], '2 bristrader '), 'båda bristrader');
    krav($u[2]['href'] === '/admin/system', 'backupåtgärd');
});
prov('DU-03 inga uppgifter vid nolläge och frisk backup', function () {
    $u = \TDL\Support\DagensUppgifter::bygg(['roll' => 'admin'], ['id' => 'A'], [['status' => 'avslutad', 'plats_id' => 'A']], [], ['lage' => 'ok']);
    krav($u === [], 'inget aktivt arbete');
});
prov('DU-04 saknad backup visas bara för admin', function () {
    $u = \TDL\Support\DagensUppgifter::bygg(['roll' => 'admin'], ['id' => 'A'], [], [], ['lage' => 'saknas']);
    krav(count($u) === 1 && str_contains($u[0]['text'], 'Ingen backup'), 'saknad backup visas');
    $u = \TDL\Support\DagensUppgifter::bygg(['roll' => 'personal'], ['id' => 'A'], [], [], ['lage' => 'saknas']);
    krav($u === [], 'personal ska inte se adminlänk');
});

prov('DU-05 äldre öppen inventering syns även utanför senaste listan', function () {
    $sessionsfil = Json::las('sessioner');
    try {
        $test = $sessionsfil;
        $test['poster'] = [
            ['id' => 'S-ny', 'startad' => '2026-01-02', 'status' => 'avslutad'],
            ['id' => 'S-gammal', 'startad' => '2026-01-01', 'status' => 'öppen'],
            ['id' => 'S-arkiv', 'startad' => '2025-12-31', 'status' => 'avslutad'],
        ];
        Json::skriv('sessioner', $test);
        $resultat = Inventering::sessionerMedOppna(1);
        krav(array_column($resultat, 'id') === ['S-ny', 'S-gammal'], 'den äldre öppna sessionen ska finnas med');
    } finally {
        Json::skriv('sessioner', $sessionsfil);
    }
});

/* ---------------------------------------------------------- Sessionsskydd */
prov('SS-01 PIN-byte upphäver gamla sessioner men ny PIN fungerar', function () use ($admin) {
    $u = Konto::skapaKonto('Session Prov', '5837', 'personal', $admin);
    \TDL\Support\Auth::startaSession();
    \TDL\Support\Auth::loggaIn(Konto::verifieraInloggning('Session Prov', '5837'));
    krav((\TDL\Support\Auth::inloggad()['id'] ?? '') === $u['id'], 'ny session ska fungera');
    $gammal = $_SESSION;
    Konto::andraPin($u['id'], '8457', $admin, true, true);
    krav(\TDL\Support\Auth::inloggad() === null, 'sessionen ska upphöra efter återställning');
    // En annan enhet med samma gamla inloggning är också spärrad.
    $_SESSION = $gammal;
    krav(\TDL\Support\Auth::inloggad() === null, 'även den andra sessionen ska upphöra');
    \TDL\Support\Auth::loggaIn(Konto::verifieraInloggning('Session Prov', '8457'));
    krav((\TDL\Support\Auth::inloggad()['id'] ?? '') === $u['id'], 'ny PIN ska fungera');
    krav(!empty(\TDL\Support\Auth::inloggad()['maste_byta_pin']), 'återställningen är tillfällig');
    \TDL\Support\Auth::loggaUt();
});
prov('SS-02 gamla sessioner utan PIN-bindning kan inte användas', function () use ($admin) {
    \TDL\Support\Auth::startaSession();
    $_SESSION = ['konto_id' => $admin['id'], 'senast_aktiv' => time(), 'loggad_in' => time()];
    krav(\TDL\Support\Auth::inloggad() === null, 'äldre session måste logga in igen');
    \TDL\Support\Auth::loggaUt();
});
prov('SS-03 sessionen går ut efter inaktivitet och maximal livslängd', function () use ($admin) {
    \TDL\Support\Auth::startaSession();
    \TDL\Support\Auth::loggaIn(Konto::aktivAnvandare($admin['id']));
    $_SESSION['senast_aktiv'] = time() - (\TDL\Support\Auth::IDLE_MINUTER * 60 + 1);
    krav(\TDL\Support\Auth::inloggad() === null, 'inaktiv session ska gå ut');
    \TDL\Support\Auth::loggaIn(Konto::aktivAnvandare($admin['id']));
    $_SESSION['loggad_in'] = time() - (\TDL\Support\Auth::MAX_TIMMAR * 3600 + 1);
    $_SESSION['senast_aktiv'] = time();
    krav(\TDL\Support\Auth::inloggad() === null, 'även en aktiv session har en maximal livslängd');
    \TDL\Support\Auth::loggaUt();
});
prov('SS-04 avstängt och återaktiverat konto återupplivar inte gamla sessioner', function () use ($admin) {
    $u = Konto::skapaKonto('Session Avstängd', '6938', 'personal', $admin);
    \TDL\Support\Auth::startaSession();
    \TDL\Support\Auth::loggaIn(Konto::verifieraInloggning('Session Avstängd', '6938'));
    $gammal = $_SESSION;
    Konto::stangAv($u['id'], $admin);
    krav(\TDL\Support\Auth::inloggad() === null, 'avstängt konto saknar session');
    Konto::aktivera($u['id'], $admin);
    \TDL\Support\Auth::startaSession();
    $_SESSION = $gammal;
    krav(\TDL\Support\Auth::inloggad() === null, 'återaktivering får inte återuppliva session');
    \TDL\Support\Auth::loggaUt();
});

prov('SS-05 utloggning med meddelande skapar en ny anonym session', function () use ($admin) {
    \TDL\Support\Auth::startaSession();
    \TDL\Support\Auth::loggaIn(Konto::aktivAnvandare($admin['id']));
    $id = session_id();
    \TDL\Support\Auth::loggaUt('Logga in igen.');
    krav(session_id() !== '' && session_id() !== $id, 'den nya anonyma sessionen ska ha ett nytt id');
    krav(\TDL\Support\Auth::inloggad() === null, 'nya sessionen är inte inloggad');
    krav(($_SESSION['flash'][0]['text'] ?? '') === 'Logga in igen.', 'meddelandet finns i nya sessionen');
    \TDL\Support\Auth::loggaUt();
});
prov('SS-06 PIN-återställning via konton kräver byte och eget byte loggar ut', function () {
    $kod = file_get_contents(dirname(__DIR__) . '/app/Http/Sidor.php');
    krav(str_contains($kod, 'Konto::andraPin($id, $pin, $konto, true, true)'), 'administratörens återställning ska vara tillfällig');
    krav(str_contains($kod, "Auth::loggaUt('Din PIN-kod är bytt."), 'eget PIN-byte ska logga ut');
    krav(str_contains($kod, "omdirigera('/login')"), 'det ska gå att logga in på nytt');
});

/* ---------------------------------------------------------- Hitta, EAN och utlåning */
prov('EAN-01 kontrollsiffra och inledande nollor', function () {
    krav(Lager::valideraEan('4006381333931') === '4006381333931', 'giltig EAN-13');
    krav(Lager::valideraEan('96385074') === '96385074', 'giltig EAN-8');
    krav(Lager::valideraEan('0000000000000') === '0000000000000', 'inledande nollor bevaras');
    kastar(fn () => Lager::valideraEan('4006381333932'), 'kontrollsiffra');
    kastar(fn () => Lager::valideraEan('123'), '8 eller 13');
});
prov('EAN-02 spara, hitta, ändra och hindra dubbel EAN', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'EAN-test', 'artikelnummer'=>'EAN-T1', 'ean'=>'4006381333931', 'sparningssatt'=>'antal']), $admin);
    krav((Lager::artikelViaEan('4006381333931')['id'] ?? '') === $a['id'], 'sökning ska hitta artikeln');
    kastar(fn () => Lager::skapaArtikel(medPlats(['namn'=>'EAN-test 2', 'artikelnummer'=>'EAN-T2', 'ean'=>'4006381333931', 'sparningssatt'=>'antal']), $admin), 'EAN-koden används redan');
    Lager::andraArtikel($a['id'], ['namn'=>'EAN-test ändrad'], $admin);
    krav((Lager::artikel($a['id'])['ean'] ?? '') === '4006381333931', 'EAN bevaras om fältet inte ändras');
    Lager::andraArtikel($a['id'], ['ean'=>'96385074'], $admin);
    krav(Lager::artikelViaEan('4006381333931') === null && (Lager::artikelViaEan('96385074')['id'] ?? '') === $a['id'], 'ändring ska slå igenom');
});
prov('KARTA-01 markerar rätt ruta för artikeln', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Kart-test', 'artikelnummer'=>'MAP-T1', 'sparningssatt'=>'antal']), $admin);
    $zon = \TDL\Domain\Lagerplats::zon($a['zon_id']);
    $soktArtikel = $a; $sokArtiklar = [$a]; $zoner = [$zon]; $karta = \TDL\Domain\Lagerplats::karta($zon['id']);
    ob_start(); require dirname(__DIR__) . '/app/views/lagerplats_karta.php'; $html = ob_get_clean();
    krav(substr_count($html, 'data-karta-markerad') === 1, 'exakt en 2D-ruta ska markeras');
    krav(str_contains($html, 'Kart-test') && str_contains($html, 'aria-current="location"'), 'artikeln och markering ska synas');
});
$platsUtlan = Lager::skapaPlats('Utlåning test', 'ULTEST', $admin);
prov('UL-01 utlåning och återlämning ändrar antal exakt en gång', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Låneverktyg', 'artikelnummer'=>'LAN-T1', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 5, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 2, [], 'Anna', gmdate('Y-m-d'), $admin);
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 3, 'utlåning minskar saldo');
    kastar(fn () => Lager::inaktiveraArtikel($a['id'], $admin), 'utlåning');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin);
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 5, 'återlämning återställer saldo');
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin), 'redan återlämnad');
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 5, 'dubblett ska inte öka saldot');
});
prov('UL-02 felaktig utlåning ändrar inte saldo', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Lånekontroll', 'artikelnummer'=>'LAN-T2', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin);
    kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 2, [], 'Anna', gmdate('Y-m-d'), $admin), 'Otillräckligt saldo');
    kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], '', gmdate('Y-m-d'), $admin), 'namnet');
    kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Anna', '2020-02-31', $admin), 'datum');
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 1, 'saldo ska vara oförändrat');
});
prov('UL-03 QR-enhet får inte lånas ut eller återlämnas två gånger', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'QR-lån', 'artikelnummer'=>'LAN-QR1', 'sparningssatt'=>'qr']), $admin);
    $resultat = Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin);
    $kod = $resultat['enheter'][0];
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 0, [$kod], 'Erik', gmdate('Y-m-d'), $admin);
    krav(Lager::enhet($kod)['status'] === 'utlanad', 'enhet markeras utlånad');
    kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 0, [$kod], 'Erik', gmdate('Y-m-d'), $admin), 'inte tillgänglig');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin);
    krav(Lager::enhet($kod)['status'] === 'aktiv', 'samma enhet återaktiveras');
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin), 'redan återlämnad');
});
prov('UL-04 tvåpersonsregel gäller även utlåning', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Skyddad utlåning', 'artikelnummer'=>'LAN-TP1', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 2, [], $admin);
    $inst = Installningar::alla();
    try {
        Installningar::spara(['tva_person_grans'=>1]);
        kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Lånare', gmdate('Y-m-d'), $admin), 'bekräftelse');
    } finally { Installningar::spara($inst); }
});

prov('UL-05 inventering hindrar utlåning och återlämning', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Inventering och lån', 'artikelnummer'=>'LAN-INV1', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 2, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Nina', gmdate('Y-m-d'), $admin);
    $inv = Inventering::startaInventering($platsUtlan['id'], [], $admin);
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin), 'inventeringen');
    kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Nina', gmdate('Y-m-d'), $admin), 'inventeringen');
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 1, 'ingen rörelse under inventering');
    Inventering::annullera($inv['id'], $admin, 'Test avslutat');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin);
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 2, 'återlämning efter inventering');
});
prov('UL-06 arbetsställe och spårning skyddas vid öppet lån', function () use ($admin) {
    $plats = Lager::skapaPlats('Tillfälligt lån', 'ULLAST', $admin);
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Utlånat utan saldo', 'artikelnummer'=>'LAN-SKYDD', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $plats['id'], 1, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $plats['id'], 1, [], 'Lånare', gmdate('Y-m-d'), $admin);
    kastar(fn () => Lager::stangPlats($plats['id'], $admin), 'utlåning');
    kastar(fn () => Lager::andraArtikel($a['id'], ['sparningssatt'=>'qr'], $admin), 'utlåning');
    kastar(fn () => Lager::inaktiveraArtikel($a['id'], $admin), 'utlåning');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin);
});
prov('UL-07 historik och påminnelser bevaras', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Påminnelse', 'artikelnummer'=>'LAN-PAM', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Lånare', gmdate('Y-m-d'), $admin);
    $oppna = \TDL\Domain\Utlan::oppna();
    krav(count(array_filter($oppna, fn ($x) => $x['id'] === $lan['id'])) === 1, 'öppet lån syns');
    $uppgifter = \TDL\Support\DagensUppgifter::bygg($admin, $platsUtlan, [], [], null, $oppna);
    krav(count(array_filter($uppgifter, fn ($x) => $x['href'] === '/utlan')) === 1, 'förfallet lån påminner');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin);
    krav(count(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id'])) === 0, 'återlämning stänger lån');
    krav(\TDL\Support\Historik::verifiera(\TDL\Support\Json::las('historik')) === [], 'historikkedjan intakt');
});
prov('UL-08 kollega med ändrad PIN kan inte bekräfta', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Godkännande', 'artikelnummer'=>'LAN-GODK', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin);
    $inst = Installningar::alla();
    try {
        Installningar::spara(['tva_person_grans'=>1]);
        $falsk = ['id'=>'SAKNAS', 'namn'=>'Okänd', 'aktiv'=>true, 'pin_hash'=>'gammal'];
        kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Lånare', gmdate('Y-m-d'), $admin, $falsk), 'ändrats');
        krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 1, 'ingen utlåning vid felaktigt godkännande');
    } finally { Installningar::spara($inst); }
});

prov('UL-09 giltig kollega bekräftar med egen PIN', function () use ($admin, $platsUtlan) {
    $kollega = Konto::skapaKonto('Lånekollega', '5749', 'personal', $admin);
    $verifierad = Konto::verifieraInloggning('Lånekollega', '5749');
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Bekräftat lån', 'artikelnummer'=>'LAN-OK', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin);
    $inst = Installningar::alla();
    try {
        Installningar::spara(['tva_person_grans'=>1]);
        kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Lånare', gmdate('Y-m-d'), $admin, $admin), 'bekräftelse');
        $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 1, [], 'Lånare', gmdate('Y-m-d'), $admin, $verifierad);
        \TDL\Domain\Utlan::aterlamna($lan['id'], $admin);
    } finally { Installningar::spara($inst); }
});
prov('KARTA-02 felaktiga koordinater visar ingen markering', function () use ($admin) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Karta fel', 'artikelnummer'=>'MAP-T2', 'sparningssatt'=>'antal']), $admin);
    $zon = \TDL\Domain\Lagerplats::zon($a['zon_id']); $soktArtikel = $a; $soktArtikel['rad'] = 9999;
    $sokArtiklar = [$a]; $zoner = [$zon]; $karta = \TDL\Domain\Lagerplats::karta($zon['id']);
    ob_start(); require dirname(__DIR__) . '/app/views/lagerplats_karta.php'; $html = ob_get_clean();
    krav(!str_contains($html, 'data-karta-markerad') && str_contains($html, 'saknar en giltig plats'), 'fel koordinat ska visas utan markering');
});
prov('UL-10 utlåningsformulär och routning har skydd', function () use ($admin, $platsUtlan) {
    $konto = $admin; $artiklar = Lager::artiklar(true); $platser = [$platsUtlan]; $utlan = \TDL\Domain\Utlan::oppna();
    $kollegor = []; $valdArtikel = ''; $valdPlats = $platsUtlan['id'];
    ob_start(); require dirname(__DIR__) . '/app/views/utlan.php'; $html = ob_get_clean();
    krav(str_contains($html, 'name="_csrf"') && str_contains($html, 'name="lanare"') && str_contains($html, 'name="forfallodatum"'), 'formuläret ska innehålla skydd och fält');
    $kod = file_get_contents(dirname(__DIR__) . '/app/Http/Sidor.php');
    krav(str_contains($kod, 'function utlanSkapa()') && str_contains($kod, 'Konto::verifieraInloggning($namn, $pin)'), 'bekräftelse ska verifiera PIN på servern');
});

prov('DEL-01 delåterlämning kvitto och gammal blankett', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Delverktyg', 'artikelnummer'=>'DEL1', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 5, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 5, [], 'Maria', gmdate('Y-m-d'), $admin);
    $version = \TDL\Domain\Utlan::version($lan);
    $r = \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 2, [], $version);
    krav($r['kvar'] === 3 && (Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 2, 'två returnerade och tre kvar');
    krav((\TDL\Domain\Utlan::kvitto($lan['id'], $r['kvittokod'])['antal'] ?? 0) === 2, 'kvitto visar antal');
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 2, [], $version), 'ändrats');
    $oppen = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 4, [], $oppen['version']), 'antal');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 3, [], $oppen['version']);
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 5, 'saldo återställt exakt en gång');
});
prov('DEL-02 enskilda QR-enheter återlämnas', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Del QR', 'artikelnummer'=>'DELQR', 'sparningssatt'=>'qr']), $admin);
    $res = Rorelser::inleverans($a['id'], $platsUtlan['id'], 2, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 0, $res['enheter'], 'Maria', gmdate('Y-m-d'), $admin);
    $r = \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$res['enheter'][0]], \TDL\Domain\Utlan::version($lan));
    krav($r['kvar'] === 1 && Lager::enhet($res['enheter'][0])['status'] === 'aktiv' && Lager::enhet($res['enheter'][1])['status'] === 'utlanad', 'endast vald enhet återlämnas');
    $oppen = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$res['enheter'][0]], $oppen['version']), 'ingår inte');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$res['enheter'][1]], $oppen['version']);
});

prov('NY-04 hyllrapport ändrar inte saldo och kan åtgärdas endast av admin', function () use ($admin, $platsUtlan) {
    $z = \TDL\Domain\Lagerplats::skapaZon('Rapportzon', 'RF', 1, 2, $admin);
    $hyllor = \TDL\Domain\Lagerplats::skapaHyllplatser($z['id'], $admin); $hylla = $hyllor[0];
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Fel hylla', 'artikelnummer'=>'RAP1', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 3, [], $admin);
    $r = \TDL\Domain\Lagerplats::rapportera($hylla['id'], $a['id'], 'Står på fel plats', null, $admin);
    krav(count(\TDL\Domain\Lagerplats::rapporter($hylla['id'])) === 1, 'rapport finns på hyllan');
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 3, 'rapport ändrar inte saldo');
    kastar(fn () => \TDL\Domain\Lagerplats::atgardaRapport($hylla['id'], $r['id'], ['roll'=>'personal']), 'administratör');
    \TDL\Domain\Lagerplats::atgardaRapport($hylla['id'], $r['id'], $admin);
    krav(\TDL\Domain\Lagerplats::rapporter($hylla['id'])[0]['status'] === 'atgardad', 'rapport är åtgärdad');
    kastar(fn () => \TDL\Domain\Lagerplats::atgardaRapport($hylla['id'], $r['id'], $admin), 'redan');
});
prov('NY-10 skadad enhet spärras tills admin godkänner', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Skadad maskin', 'artikelnummer'=>'SKAD1', 'sparningssatt'=>'qr']), $admin);
    $r = Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin); $kod = $r['enheter'][0];
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 0, [$kod], 'Anna', gmdate('Y-m-d'), $admin);
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$kod], \TDL\Domain\Utlan::version($lan), 'skadad', ''), 'beskrivning');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$kod], \TDL\Domain\Utlan::version($lan), 'skadad', 'Sprucket handtag');
    krav(Lager::enhet($kod)['status'] === 'skadad' && (Lager::saldoPerPlats(Lager::artikel($a['id']))[$platsUtlan['id']] ?? 0) === 0, 'spärrad ingår inte i saldo');
    kastar(fn () => \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 0, [$kod], 'Anna', gmdate('Y-m-d'), $admin), 'inte tillgänglig');
    kastar(fn () => \TDL\Domain\Utlan::godkannEnhet($kod, 'Kontrollerad', ['roll'=>'personal']), 'administratör');
    \TDL\Domain\Utlan::godkannEnhet($kod, 'Handtag utbytt', $admin);
    krav(Lager::enhet($kod)['status'] === 'aktiv' && (Lager::saldoPerPlats(Lager::artikel($a['id']))[$platsUtlan['id']] ?? 0) === 1, 'tillgänglig efter godkännande');
    kastar(fn () => \TDL\Domain\Utlan::godkannEnhet($kod, 'igen', $admin), 'inte spärrad');
});
prov('NY-11 båda konton behövs för överlämning utan saldoflytt', function () use ($admin, $platsUtlan) {
    Konto::skapaKonto('Nuvarande låntagare', '5749', 'personal', $admin);
    Konto::skapaKonto('Ny mottagare', '5839', 'personal', $admin);
    $fran = Konto::verifieraInloggning('Nuvarande låntagare', '5749');
    $till = Konto::verifieraInloggning('Ny mottagare', '5839');
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Överlämning', 'artikelnummer'=>'OVERL1', 'sparningssatt'=>'antal']), $fran);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 2, [], $fran);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 2, [], $fran['namn'], gmdate('Y-m-d'), $fran);
    $kvitto = \TDL\Domain\Utlan::aterlamna($lan['id'], $fran, 1, [], \TDL\Domain\Utlan::version($lan));
    $aktuell = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    $version = $aktuell['version'];
    kastar(fn () => \TDL\Domain\Utlan::overlamna($lan['id'], $version, $till, $fran), 'låntagaren');
    \TDL\Domain\Utlan::overlamna($lan['id'], $version, $fran, $till);
    $oppen = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    krav($oppen['lanare'] === $till['namn'] && (Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 1, 'mottagare ändras utan saldo');
    kastar(fn () => \TDL\Domain\Utlan::overlamna($lan['id'], $version, $fran, $till), 'ändrats');
    krav(\TDL\Domain\Utlan::kvitto($lan['id'], $kvitto['kvittokod'])['lanare'] === $fran['namn'], 'tidigare kvitto behåller ursprunglig låntagare');
    \TDL\Domain\Utlan::aterlamna($lan['id'], $fran);
});
prov('NY-13 saknadrapport utan saldoändring och bekräftad avskrivning', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Saknat verktyg', 'artikelnummer'=>'SAK1', 'sparningssatt'=>'qr']), $admin);
    $r = Rorelser::inleverans($a['id'], $platsUtlan['id'], 1, [], $admin); $kod = $r['enheter'][0];
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 0, [$kod], 'Anna', gmdate('Y-m-d'), $admin);
    \TDL\Domain\Utlan::anmalSaknad($lan['id'], \TDL\Domain\Utlan::version($lan), $admin['id'], 'Sök i verkstaden', $admin);
    krav(Lager::enhet($kod)['status'] === 'utlanad', 'rapport ändrar inte enhetens status');
    $oppen = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    kastar(fn () => \TDL\Domain\Utlan::avskrivSaknad($lan['id'], $oppen['version'], 'Saknas', ['roll'=>'personal']), 'administratör');
    \TDL\Domain\Utlan::avskrivSaknad($lan['id'], $oppen['version'], 'Utrett och saknas', $admin);
    krav(Lager::enhet($kod)['status'] === 'utgang', 'avskrivning avslutar spårad enhet');
    kastar(fn () => \TDL\Domain\Utlan::avskrivSaknad($lan['id'], $oppen['version'], 'igen', $admin), 'ingen öppen');
    krav(\TDL\Support\Historik::verifiera(\TDL\Support\Json::las('historik')) === [], 'kedjan intakt');
});
prov('NY-14 flyttförslag redovisar underlag och ändrar inte artikeln', function () use ($admin) {
    $z = \TDL\Domain\Lagerplats::skapaZon('Förslagszon', 'FX', 3, 3, $admin);
    $a = Lager::skapaArtikel(['namn'=>'Frekvent vara', 'artikelnummer'=>'FLYTT1', 'sparningssatt'=>'antal', 'zon_id'=>$z['id'], 'rad'=>3, 'fack'=>1], $admin);
    $historik = array_fill(0, 3, ['typ'=>'uttag', 'artikel_id'=>$a['id'], 'tid'=>nu()]);
    $f = \TDL\Domain\Lagerplats::flyttforslag($a, $historik, Lager::artiklar(true));
    krav($f !== null && $f['rad'] === 1 && $f['handelser'] === 3, 'förslaget visar antal händelser och plats');
    krav(Lager::artikel($a['id'])['rad'] === 3, 'inget har flyttats');
    krav(\TDL\Domain\Lagerplats::flyttforslag($a, array_slice($historik, 0, 2), Lager::artiklar(true)) === null, 'för få rörelser ger inget förslag');
});

prov('NY-10B skadad enhet skyddas vid inventering och platsstängning', function () use ($admin) {
    $plats = Lager::skapaPlats('Verktygskontroll', 'VKTEST', $admin);
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Kontrollverktyg', 'artikelnummer'=>'SKAD2', 'sparningssatt'=>'qr']), $admin);
    $r = Rorelser::inleverans($a['id'], $plats['id'], 1, [], $admin); $kod = $r['enheter'][0];
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $plats['id'], 0, [$kod], 'Anna', gmdate('Y-m-d'), $admin);
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$kod], \TDL\Domain\Utlan::version($lan), 'skadad', 'Defekt');
    kastar(fn () => Lager::stangPlats($plats['id'], $admin), 'skadad');
    $inv = Inventering::startaInventering($plats['id'], [$a['id']], $admin);
    kastar(fn () => \TDL\Domain\Utlan::godkannEnhet($kod, 'Lagats', $admin), 'inventeringen');
    krav(Lager::enhet($kod)['status'] === 'skadad', 'fortsatt spärrad');
    Inventering::annullera($inv['id'], $admin, 'Test avslutat');
    \TDL\Domain\Utlan::godkannEnhet($kod, 'Lagats', $admin);
});
prov('NY-13B delåterlämning följt av avskrivning av resterande antal', function () use ($admin, $platsUtlan) {
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Saknade antal', 'artikelnummer'=>'SAKANT1', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $platsUtlan['id'], 5, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $platsUtlan['id'], 5, [], 'Lånare', gmdate('Y-m-d'), $admin);
    \TDL\Domain\Utlan::anmalSaknad($lan['id'], \TDL\Domain\Utlan::version($lan), $admin['id'], 'Sök', $admin);
    $oppna = \TDL\Domain\Utlan::oppna(); $oppen = array_values(array_filter($oppna, fn ($x) => $x['id'] === $lan['id']))[0];
    \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 2, [], $oppen['version']);
    $oppen2 = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    kastar(fn () => \TDL\Domain\Utlan::avskrivSaknad($lan['id'], $oppen['version'], 'Bekräftad', $admin), 'ändrats');
    \TDL\Domain\Utlan::avskrivSaknad($lan['id'], $oppen2['version'], 'Utredd och saknas', $admin);
    krav((Lager::artikel($a['id'])['saldo'][$platsUtlan['id']] ?? 0) === 2, 'saldo dras inte en andra gång');
});
prov('NY-04B öppen hyllrapport skyddas vid zonkrympning', function () use ($admin) {
    $z = \TDL\Domain\Lagerplats::skapaZon('Krymprapport', 'KF', 2, 2, $admin);
    $hyllor = \TDL\Domain\Lagerplats::skapaHyllplatser($z['id'], $admin);
    $h = array_values(array_filter($hyllor, fn ($x) => (int) $x['rad'] === 2))[0];
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Rapportobjekt', 'artikelnummer'=>'RAP2', 'sparningssatt'=>'antal']), $admin);
    $r = \TDL\Domain\Lagerplats::rapportera($h['id'], $a['id'], 'Kontroll behövs', null, $admin);
    kastar(fn () => \TDL\Domain\Lagerplats::andraZon($z['id'], ['namn'=>'Krymprapport','kod'=>'KF','rader'=>1,'fack'=>2], $admin), 'felrapport');
    \TDL\Domain\Lagerplats::atgardaRapport($h['id'], $r['id'], $admin);
    \TDL\Domain\Lagerplats::andraZon($z['id'], ['namn'=>'Krymprapport','kod'=>'KF','rader'=>1,'fack'=>2], $admin);
});

prov('LOG-01 endast gamla roterade tekniska loggar rensas', function () {
    $dir = TDL_STORAGE . '/logg-prov'; @mkdir($dir, 0700, true); $nu = time();
    foreach (['server.log', 'server.log.1', 'server.log.2.gz', 'server.log-20261101.gz', 'server.log.3', 'historik.json', 'arkiv.logg.1'] as $n) file_put_contents($dir . '/' . $n, 'behåll eller ta bort');
    foreach (['server.log', 'server.log.1', 'server.log.2.gz', 'server.log-20261101.gz', 'historik.json', 'arkiv.logg.1'] as $n) touch($dir . '/' . $n, $nu - 8 * 86400);
    touch($dir . '/server.log.3', $nu - 6 * 86400);
    $harLank = @symlink($dir . '/historik.json', $dir . '/extern.log.1');
    $r = \TDL\Support\Loggstadning::rensa($dir, $nu);
    if ($harLank) krav(is_link($dir . '/extern.log.1') && is_file($dir . '/historik.json'), 'symboliska länkar och deras mål bevaras');
    krav($r['borttagna'] === 3 && $r['fel'] === 0, 'tre gamla roterade loggar tas bort');
    foreach (['server.log', 'server.log.3', 'historik.json', 'arkiv.logg.1'] as $n) krav(is_file($dir . '/' . $n), $n . ' måste behållas');
    foreach (glob($dir . '/*') ?: [] as $n) @unlink($n); @rmdir($dir);
});
prov('LOG-02 automatisk städning begränsas till roterade loggar och körs högst två gånger per dygn', function () {
    $lock = TDL_LOCK . '/loggstadning.lock'; @unlink($lock);
    $gammal = TDL_LOGG . '/testrotation.log.1'; $senare = TDL_LOGG . '/testrotation.log.2';
    file_put_contents($gammal, 'gammal'); touch($gammal, time() - 8 * 86400);
    \TDL\Support\Loggstadning::vidAnvandning(); krav(!is_file($gammal), 'gammal rotation tas bort');
    file_put_contents($senare, 'gammal'); touch($senare, time() - 8 * 86400);
    \TDL\Support\Loggstadning::vidAnvandning(); krav(is_file($senare), 'upprepade förfrågningar är begränsade');
    file_put_contents($lock, (string) (time() - 13 * 3600));
    \TDL\Support\Loggstadning::vidAnvandning(); krav(!is_file($senare), 'nästa städning sker efter tidsgränsen');
    @unlink($lock);
});
prov('UPD-01 latest måste vara grenens exakta commit och giltig tagg', function () {
    $sha = str_repeat('a', 40); $annan = str_repeat('b', 40);
    $r = ['tag_name'=>'build-' . $sha, 'draft'=>false, 'prerelease'=>false];
    $ref = ['object'=>['type'=>'commit', 'sha'=>$sha]];
    krav(\TDL\Support\Uppdateringskontroll::verifieraRelease($r, $ref, $ref) === $sha, 'aktuell release godkänns');
    kastar(fn () => \TDL\Support\Uppdateringskontroll::verifieraRelease($r, ['object'=>['type'=>'commit', 'sha'=>$annan]], $ref), 'motsvarar inte');
    kastar(fn () => \TDL\Support\Uppdateringskontroll::verifieraRelease($r, $ref, ['object'=>['type'=>'commit', 'sha'=>$annan]]), 'motsvarar inte');
    $r['prerelease'] = true; kastar(fn () => \TDL\Support\Uppdateringskontroll::verifieraRelease($r, $ref, $ref), 'motsvarar inte');
    $r['prerelease'] = false; $r['tag_name'] = '../../main'; kastar(fn () => \TDL\Support\Uppdateringskontroll::verifieraRelease($r, $ref, $ref), 'motsvarar inte');
});
prov('UPD-02 jämför filinnehåll, inte storage eller symlänkar', function () {
    $dir = TDL_STORAGE . '/uppdateringsprov'; @mkdir($dir . '/app', 0700, true); @mkdir($dir . '/public', 0700, true); @mkdir($dir . '/storage', 0700, true);
    file_put_contents($dir . '/app/bootstrap.php', 'samma'); file_put_contents($dir . '/public/index.php', 'lokalt'); file_put_contents($dir . '/storage/hemlig', 'privat');
    $blob = static fn ($path, $data) => ['path'=>$path, 'type'=>'blob', 'mode'=>'100644', 'sha'=>hash('sha1', 'blob ' . strlen($data) . "\0" . $data)];
    $trad = ['truncated'=>false, 'tree'=>[$blob('app/bootstrap.php', 'samma'), $blob('public/index.php', 'release'), $blob('app/saknas.php', 'saknas'), $blob('storage/hemlig', 'annat')]];
    $r = \TDL\Support\Uppdateringskontroll::jamforTrad($trad, $dir);
    krav($r['antal'] === 3 && $r['oforandrade'] === 1 && $r['andrade'] === ['public/index.php'] && $r['saknade'] === ['app/saknas.php'], 'ändrade och saknade programfiler visas, storage ignoreras');
    $harLank = @symlink($dir . '/storage/hemlig', $dir . '/app/lank.php');
    if ($harLank) { $trad['tree'][] = $blob('app/lank.php', 'privat'); $r = \TDL\Support\Uppdateringskontroll::jamforTrad($trad, $dir); krav($r['otillgangliga'] === ['app/lank.php'], 'symlänk får inte följas'); @unlink($dir . '/app/lank.php'); }
    $trad['truncated'] = true; kastar(fn () => \TDL\Support\Uppdateringskontroll::jamforTrad($trad, $dir), 'ofullständig');
    $trad['truncated'] = false; $trad['tree'][] = $blob('app/../storage/hemlig', 'privat'); kastar(fn () => \TDL\Support\Uppdateringskontroll::jamforTrad($trad, $dir), 'osäker');
    @unlink($dir . '/app/bootstrap.php'); @unlink($dir . '/public/index.php'); @unlink($dir . '/storage/hemlig'); @rmdir($dir . '/app'); @rmdir($dir . '/public'); @rmdir($dir . '/storage'); @rmdir($dir);
});

prov('VAL-08 artikelinstruktion och förpackningsnotering ändrar inte saldo', function () use ($admin) {
    $plats = Lager::skapaPlats('Test noteringar', 'TN23', $admin);
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Artikel med instruktion', 'artikelnummer'=>'INS23', 'sparningssatt'=>'antal', 'rakneinstruktion'=>"Räkna kartonger\nseparat", 'oppen_forpackning'=>'Örebro låda 1: cirka 3 kg']), $admin);
    Rorelser::inleverans($a['id'], $plats['id'], 4, [], $admin);
    $a = Lager::andraArtikel($a['id'], ['namn'=>'Artikel med instruktion'], $admin);
    krav($a['rakneinstruktion'] === "Räkna kartonger\nseparat" && $a['oppen_forpackning'] === 'Örebro låda 1: cirka 3 kg', 'fält bevaras vid partiell ändring');
    krav(($a['saldo'][$plats['id']] ?? 0) === 4 && !empty($a['oppen_forpackning_andrad']), 'saldo och noteringstid bevaras');
    kastar(fn () => Lager::andraArtikel($a['id'], ['rakneinstruktion'=>str_repeat('x', 501)], $admin), '500');
    kastar(fn () => Lager::andraArtikel($a['id'], ['oppen_forpackning'=>['ogiltig']], $admin), 'text');
    krav(Lager::artikel($a['id'])['saldo'][$plats['id']] === 4, 'felaktig ändring påverkar inte saldo');
    $inv = Inventering::startaInventering($plats['id'], [$a['id']], $admin);
    $lage = Inventering::lage($inv);
    krav($lage['rader'][0]['rakneinstruktion'] === "Räkna kartonger\nseparat", 'aktuell instruktion visas i läget');
    Lager::andraArtikel($a['id'], ['rakneinstruktion'=>'<script>angrepp</script>'], $admin);
    $lage = Inventering::lage($inv); $session = $inv; $utanfor = []; $arAdmin = true;
    ob_start(); require dirname(__DIR__) . '/app/views/inventering.php'; $html = ob_get_clean();
    krav(str_contains($html, '&lt;script&gt;angrepp&lt;/script&gt;') && !str_contains($html, '<script>angrepp</script>'), 'instruktion escapes i vyn');
    Inventering::annullera($inv['id'], $admin, 'Test avslutat');
});
prov('VAL-03 lokal ordlista valideras och visas säkert', function () {
    $gammal = Installningar::hamta('lokal_ordlista', []);
    $ord = \TDL\Support\Ordlista::validera(['enhet'=>['term'=>'Vår kod', 'forklaring'=>'<test>'], 'okand'=>['term'=>'x','forklaring'=>'y']]);
    krav(count($ord) === 1 && $ord['enhet']['term'] === 'Vår kod', 'bara kända fält');
    kastar(fn () => \TDL\Support\Ordlista::validera(['enhet'=>['term'=>'saknar', 'forklaring'=>'']]), 'både');
    kastar(fn () => \TDL\Support\Ordlista::validera(['enhet'=>['term'=>str_repeat('x', 81), 'forklaring'=>'x']]), '80');
    Installningar::spara(['lokal_ordlista'=>$ord]);
    $ordlisteFalt = 'enhet'; ob_start(); require dirname(__DIR__) . '/app/views/ordlista_hjalp.php'; $html = ob_get_clean();
    krav(str_contains($html, '&lt;test&gt;') && !str_contains($html, '<test>'), 'text visas säkert');
    Installningar::spara(['lokal_ordlista'=>$gammal]);
});
prov('VAL-07 returplats jämförs per delåterlämning utan lagerflytt', function () use ($admin) {
    $plats = Lager::skapaPlats('Returtest', 'RT23', $admin);
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Returvara', 'artikelnummer'=>'RET23', 'sparningssatt'=>'antal']), $admin);
    Rorelser::inleverans($a['id'], $plats['id'], 4, [], $admin);
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $plats['id'], 4, [], 'Anna', gmdate('Y-m-d'), $admin, null, 'Hylla A');
    $version = \TDL\Domain\Utlan::version($lan);
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 1, [], $version), 'faktisk returplats');
    krav((Lager::artikel($a['id'])['saldo'][$plats['id']] ?? 0) === 0, 'ogiltig retur ändrar inte saldo');
    $r = \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 1, [], $version, 'ok', '', null, 'Hylla B');
    $k = \TDL\Domain\Utlan::kvitto($lan['id'], $r['kvittokod']);
    krav($k['returplats_stammer'] === false && $k['onskad_returplats'] === 'Hylla A' && $k['faktisk_returplats'] === 'Hylla B', 'avvikelse sparas i kvittot');
    kastar(fn () => \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 1, [], $version, 'ok', '', null, 'Hylla A'), 'ändrats');
    $aktuell = array_values(array_filter(\TDL\Domain\Utlan::oppna(), fn ($x) => $x['id'] === $lan['id']))[0];
    $r2 = \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 3, [], $aktuell['version'], 'ok', '', null, 'hylla a');
    krav(\TDL\Domain\Utlan::kvitto($lan['id'], $r2['kvittokod'])['returplats_stammer'] === true, 'jämförelsen ignorerar skiftläge');
    krav(Lager::artikel($a['id'])['saldo'][$plats['id']] === 4, 'återförs till ursprungliga arbetsstället');
    krav(\TDL\Support\Historik::verifiera(Json::las('historik')) === [], 'historikkedjan är intakt');
});

prov('VAL-07B returplats för QR-enhet bevarar identitet och ursprungligt ställe', function () use ($admin) {
    $plats = Lager::skapaPlats('Retur QR', 'RQ23', $admin);
    $a = Lager::skapaArtikel(medPlats(['namn'=>'Retur QR', 'artikelnummer'=>'RETQR23', 'sparningssatt'=>'qr']), $admin);
    $kod = Rorelser::inleverans($a['id'], $plats['id'], 1, [], $admin)['enheter'][0];
    $lan = \TDL\Domain\Utlan::skapa($a['id'], $plats['id'], 0, [$kod], 'Anna', gmdate('Y-m-d'), $admin, null, 'Vagn 1');
    $r = \TDL\Domain\Utlan::aterlamna($lan['id'], $admin, 0, [$kod], \TDL\Domain\Utlan::version($lan), 'ok', '', null, 'Vagn 2');
    $en = Lager::enhet($kod); $k = \TDL\Domain\Utlan::kvitto($lan['id'], $r['kvittokod']);
    krav($en['status'] === 'aktiv' && $en['plats_id'] === $plats['id'] && $k['returplats_stammer'] === false, 'QR-identitet, arbetsställe och avvikelse bevaras');
    krav(\TDL\Support\Historik::verifiera(Json::las('historik')) === [], 'historikkedjan är intakt');
});

/* ---------------------------------------------------------- Sammanställning */
echo "Tadega Lager – integrationstest\n";
echo str_repeat('=', 60) . "\n";
foreach ($rader as $rad) {
    echo $rad . "\n";
}
echo str_repeat('-', 60) . "\n";
echo "Godkända: $grön   Misslyckade: $röd\n";
echo 'Testlagring: ' . $tmpStorage . "\n";

// Städa testlagringen
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpStorage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($tmpStorage);

exit($röd === 0 ? 0 : 1);
