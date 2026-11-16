<?php
/**
 * Sidor för lagerplatser: zoner, hyllor, hyll-QR och lagerkartan.
 */

declare(strict_types=1);

namespace TDL\Http;

use TDL\Domain\Lager;
use TDL\Domain\Lagerplats;
use TDL\Support\AppFel;
use TDL\Support\Auth;
use TDL\Support\Sakerhet;
use TDL\Http\Vyer;

final class LagerplatsSidor
{
    /** Översikt: alla zoner med antal artiklar och knappar vidare. */
    public static function zoner(): void
    {
        $konto = Auth::kravInloggning();
        $zoner = Lagerplats::zoner();
        $antal = Lagerplats::antalPerZon();
        $hyllor = [];
        foreach (Lagerplats::hyllplatser() as $h) {
            $hyllor[$h['zon_id']] = ($hyllor[$h['zon_id']] ?? 0) + 1;
        }
        $rader = [];
        foreach ($zoner as $z) {
            $rader[] = [
                'zon' => $z,
                'antal' => $antal[$z['id']] ?? 0,
                'hyllor' => $hyllor[$z['id']] ?? 0,
                'rutor' => (int) $z['rader'] * (int) $z['fack'],
            ];
        }
        Vyer::rendera('lagerplats_zoner', [
            'titel' => 'Lagerplatser',
            'konto' => $konto,
            'aktiv' => 'lagerplats',
            'rader' => $rader,
            'utanPlats' => Lagerplats::utanPlats(),
            'arAdmin' => ($konto['roll'] ?? '') === 'admin',
        ]);
    }

    /** Formulär: ny zon. */
    public static function zonForm(): void
    {
        $konto = Auth::kravRoll('admin');
        Vyer::rendera('lagerplats_zon_form', [
            'titel' => 'Ny zon',
            'konto' => $konto,
            'aktiv' => 'lagerplats',
            'zon' => null,
        ]);
    }

    /** Sparar en ny zon och skapar hyllplatserna direkt. */
    public static function zonSkapa(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $zon = Lagerplats::skapaZon(
            post('namn'),
            post('kod'),
            (int) post('rader'),
            (int) post('fack'),
            $konto
        );
        try {
            $nya = Lagerplats::skapaHyllplatser($zon['id'], $konto);
            flash('ok', 'Zon ' . $zon['kod'] . ' – ' . $zon['namn'] . ' skapades med '
                . count($nya) . ' hyllplatser. Skriv ut lapparna och sätt på hyllorna.');
        } catch (AppFel $e) {
            flash('info', 'Zonen skapades, men hyllplatserna kunde inte skapas: ' . $e->getMessage());
        }
        omdirigera('/lagerplatser/' . $zon['id']);
    }

    /** En zon: rutnät, hyllplatser och utskrift. */
    public static function zon(string $id): void
    {
        $konto = Auth::kravInloggning();
        $zon = Lagerplats::zon($id);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte.', 404);
        }
        Vyer::rendera('lagerplats_zon', [
            'titel' => 'Zon ' . $zon['kod'] . ' – ' . $zon['namn'],
            'konto' => $konto,
            'aktiv' => 'lagerplats',
            'zon' => $zon,
            'hyllplatser' => Lagerplats::hyllplatser($zon['id']),
            'arAdmin' => ($konto['roll'] ?? '') === 'admin',
        ]);
    }

    /** Redigera en zon. */
    public static function zonRedigera(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        $zon = Lagerplats::zon($id);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte.', 404);
        }
        Vyer::rendera('lagerplats_zon_form', [
            'titel' => 'Redigera zon ' . $zon['kod'],
            'konto' => $konto,
            'aktiv' => 'lagerplats',
            'zon' => $zon,
        ]);
    }

    public static function zonAndra(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $zon = Lagerplats::andraZon($id, [
            'namn' => post('namn'),
            'kod' => post('kod'),
            'rader' => (int) post('rader'),
            'fack' => (int) post('fack'),
        ], $konto);
        flash('ok', 'Zonen sparades.');
        omdirigera('/lagerplatser/' . $zon['id']);
    }

    /** Skapar (saknade) hyllplatser för en zon. */
    public static function zonSkapaHyllor(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $nya = Lagerplats::skapaHyllplatser($id, $konto);
        flash('ok', count($nya) . ' hyllplatser skapades.');
        omdirigera('/lagerplatser/' . $id);
    }

    /** Utskriftsvänlig lista med hyllappar för en zon. */
    public static function hyllorSkrivUt(string $id): void
    {
        $konto = Auth::kravInloggning();
        $zon = Lagerplats::zon($id);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte.', 404);
        }
        Vyer::rendera('lagerplats_utskrift', [
            'titel' => 'Hyllappar zon ' . $zon['kod'],
            'konto' => $konto,
            'aktiv' => 'lagerplats',
            'zon' => $zon,
            'hyllplatser' => Lagerplats::hyllplatser($zon['id']),
        ]);
    }

    /** Lagerkartan: 2D och 3D. */
    public static function karta(): void
    {
        $konto = Auth::kravInloggning();
        $zoner = Lagerplats::zoner();
        $vald = get('zon');
        $zon = null;
        if ($vald !== '') {
            $zon = Lagerplats::zon($vald) ?? Lagerplats::zonViaKod($vald);
        }
        if ($zon === null && $zoner !== []) {
            $zon = $zoner[0];
        }
        if ($zon === null) {
            Vyer::rendera('lagerplats_karta_tom', [
                'titel' => 'Lagerkarta',
                'konto' => $konto,
                'aktiv' => 'lagerkarta',
                'zoner' => $zoner,
                'arAdmin' => ($konto['roll'] ?? '') === 'admin',
            ]);
            return;
        }
        Vyer::rendera('lagerplats_karta', [
            'titel' => 'Lagerkarta',
            'konto' => $konto,
            'aktiv' => 'lagerkarta',
            'zoner' => $zoner,
            'zon' => $zon,
            'karta' => Lagerplats::karta($zon['id']),
        ]);
    }

    /* ================================================================
     *  Lokalplan: våningar med zoner, byggda av användarens foton
     * ================================================================ */

    /** Planen: flikar för våningarna, ytan med zonerna, fotolistan. */
    public static function plan(): void
    {
        $konto = Auth::kravInloggning();
        $plan = \TDL\Domain\Lokalplan::plan();
        $vaningar = $plan['vaningar'] ?? [];

        // Vilken våning visas? ?vaning=V2, annars den första.
        $valdId = (string) get('vaning', '');
        $vald = null;
        foreach ($vaningar as $v) {
            if ($v['id'] === $valdId) {
                $vald = $v;
            }
        }
        if ($vald === null) {
            $vald = $vaningar[0] ?? null;
        }

        // Rutorna som uppslag för snabb åtkomst i vyn
        $rutor = [];
        if ($vald !== null) {
            foreach ($vald['zonrutor'] as $r) {
                $rutor[$r['zon_id']] = $r;
            }
        }
        // Fotona på den här våningen, kopplade till sina rutor
        $foton = [];
        foreach ($vald['bilder'] ?? [] as $b) {
            $b['ruta'] = $rutor[$b['zon_id']] ?? null;
            $foton[] = $b;
        }

        $zoner = Lagerplats::zoner();
        $antal = Lagerplats::antalPerZon();

        // Zoner som hör till andra våningar ska inte erbjudas här
        $placerade = \TDL\Domain\Lokalplan::placeradeZonIder();
        $egna = [];
        $lediga = [];
        foreach ($zoner as $z) {
            if ($vald !== null && ($placerade[$z['id']] ?? '') === $vald['id']) {
                $egna[] = $z;
            } elseif (!isset($placerade[$z['id']])) {
                $lediga[] = $z;
            }
        }

        Vyer::rendera('lokalplan', [
            'titel' => 'Lokalplan',
            'konto' => $konto,
            'aktiv' => 'lokalplan',
            'plan' => $plan,
            'vaningar' => $vaningar,
            'vald' => $vald,
            'rutor' => $rutor,
            'foton' => $foton,
            'egna' => $egna,
            'lediga' => $lediga,
            'antal' => $antal,
            'ytaBredd' => \TDL\Domain\Lokalplan::YTA_BREDD,
            'ytaHojd' => \TDL\Domain\Lokalplan::YTA_HOJD,
            'arAdmin' => ($konto['roll'] ?? '') === 'admin',
        ]);
    }

    /** Laddar upp en bild: antingen ett foto (blir zon) eller en planbild. */
    public static function planLaddaUpp(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();

        $vaningId = (string) post('vaning', '');
        // Saknas våning skapas den första automatiskt – då slipper man
        // ett extra steg första gången man lägger upp lokalen.
        if (\TDL\Domain\Lokalplan::plan() === null) {
            $ny = \TDL\Domain\Lokalplan::skapaVaning(null, $konto);
            $vaningId = $ny['id'];
        }
        if ($vaningId === '') {
            $vaningId = (string) (\TDL\Domain\Lokalplan::forstaVaning()['id'] ?? '');
        }

        $somZon = post('typ', 'planbild') === 'foto';
        $etikett = post('etikett', '');

        // Flera filer på en gång (mobilen kan välja flera foton)
        $filer = self::filerFranFalt('bild');
        if ($filer === []) {
            throw new AppFel('Välj minst en bild.');
        }
        $antal = 0;
        foreach ($filer as $fil) {
            \TDL\Domain\Lokalplan::sparaBild($vaningId, $fil, $somZon, $antal === 0 ? $etikett : null, $konto);
            $antal++;
        }
        flash('ok', $somZon
            ? $antal . ' foto tillagda som zoner. Dra och ändra så att skissen stämmer.'
            : 'Planbilden är uppladdad.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($vaningId));
    }

    /**
     * "Klart" – appen sätter ihop fotona till en skiss.
     * Användaren markerar att lokalen är färdigfotad, appen lägger rutorna.
     */
    public static function planKlart(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $vaningId = (string) post('vaning', '');
        \TDL\Domain\Lokalplan::fotograferaKlart($vaningId, $konto);
        flash('ok', 'Klart – fotona är ihopsatta till en skiss. Justera zonerna om det behövs.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($vaningId));
    }

    /** Sparar zonrutorna från dra-och-släpp-ytan. */
    public static function planSparaRutor(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $vaningId = (string) post('vaning', '');
        $rader = json_decode((string) post('rutor'), true);
        if (!is_array($rader)) {
            throw new AppFel('Kunde inte läsa zonernas placering. Försök igen.');
        }
        \TDL\Domain\Lokalplan::sparaRutor($vaningId, $rader, $konto);
        flash('ok', 'Lokalplanen sparades.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($vaningId));
    }

    /** Lägger till en våning (våning 2, 3 …). */
    public static function planNyVaning(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        if (\TDL\Domain\Lokalplan::plan() === null) {
            \TDL\Domain\Lokalplan::skapaVaning(null, $konto);
            omdirigera('/lokalplan');
        }
        $v = \TDL\Domain\Lokalplan::skapaVaning(post('namn', ''), $konto);
        flash('ok', 'Våningen "' . $v['namn'] . '" lades till. Ladda upp foton eller en planbild.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($v['id']));
    }

    /** Byter namn på en våning. */
    public static function planAndraVaning(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $vaningId = (string) post('vaning', '');
        \TDL\Domain\Lokalplan::andraVaning($vaningId, ['namn' => post('namn', '')], $konto);
        flash('ok', 'Namnet sparades.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($vaningId));
    }

    /** Tar bort en våning. */
    public static function planTaBortVaning(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $vaningId = (string) post('vaning', '');
        \TDL\Domain\Lokalplan::taBortVaning($vaningId, $konto);
        flash('ok', 'Våningen togs bort.');
        omdirigera('/lokalplan');
    }

    /** Placerar en befintlig zon på våningens skiss. */
    public static function planPlaceraZon(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $vaningId = (string) post('vaning', '');
        $zonId = (string) post('zon', '');
        \TDL\Domain\Lokalplan::placeraZon($vaningId, $zonId, $konto);
        flash('ok', 'Zonen lades på skissen. Dra den på plats och spara.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($vaningId));
    }

    /** Tar bort ett foto och dess zon. */
    public static function planTaBortFoto(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $vaningId = (string) post('vaning', '');
        \TDL\Domain\Lokalplan::taBortFoto($vaningId, (string) post('fil', ''), $konto);
        flash('ok', 'Fotot togs bort.');
        omdirigera('/lokalplan?vaning=' . rawurlencode($vaningId));
    }

    /**
     * Serverar en bild ur planen. Ligger utanför public/ och kräver
     * inloggning – bilderna visar lokalen och ska inte vara öppna.
     */
    public static function planBild(): void
    {
        Auth::kravInloggning();
        $fil = \TDL\Domain\Lokalplan::bildSokvag((string) get('fil', ''));
        if ($fil === null) {
            http_response_code(404);
            return;
        }
        $typ = match (strtolower(pathinfo($fil, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        header('Content-Type: ' . $typ);
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string) filesize($fil));
        readfile($fil);
    }

    /** Tar bort hela lokalplanen. */
    public static function planTaBort(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        \TDL\Domain\Lokalplan::taBort($konto);
        flash('ok', 'Hela lokalplanen togs bort.');
        omdirigera('/lokalplan');
    }

    /**
     * Plockar ut filerna ur ett filfält. Fältet kan innehålla en fil
     * (bild) eller flera (bild[]).
     */
    private static function filerFranFalt(string $namn): array
    {
        $falt = $_FILES[$namn] ?? null;
        if (!is_array($falt) || !isset($falt['name'])) {
            return [];
        }
        if (!is_array($falt['name'])) {
            return $falt['error'] === UPLOAD_ERR_OK ? [$falt] : [];
        }
        $ut = [];
        foreach ($falt['name'] as $i => $ettNamn) {
            if (($falt['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $ut[] = [
                'name' => $ettNamn,
                'type' => $falt['type'][$i] ?? '',
                'tmp_name' => $falt['tmp_name'][$i] ?? '',
                'error' => $falt['error'][$i],
                'size' => $falt['size'][$i] ?? 0,
            ];
        }
        return $ut;
    }

    /** En hylla: vad som står där, och snabb åtgärd. */
    public static function hylla(string $id): void
    {
        $konto = Auth::kravInloggning();
        $h = Lagerplats::hyllplats($id);
        if ($h === null) {
            throw new AppFel('Hyllplatsen finns inte.', 404);
        }
        $zon = Lagerplats::zon($h['zon_id']);
        Vyer::rendera('lagerplats_hylla', [
            'titel' => 'Hylla ' . ($zon['kod'] ?? '?') . '-' . $h['rad'] . '-' . $h['fack'],
            'konto' => $konto,
            'aktiv' => 'lagerkarta',
            'zon' => $zon,
            'hyllplats' => $h,
            'artiklar' => Lagerplats::artiklarPa($h['zon_id'], (int) $h['rad'], (int) $h['fack']),
            'arAdmin' => ($konto['roll'] ?? '') === 'admin',
        ]);
    }
}
