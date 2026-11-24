<?php
/**
 * Sidhanterare. All behörighet kontrolleras här och i domänlagret –
 * aldrig enbart i gränssnittet.
 */

declare(strict_types=1);

namespace TDL\Http;

use TDL\Domain\Arkiv;
use TDL\Domain\Inventering;
use TDL\Domain\Kontohantering;
use TDL\Domain\Lager;
use TDL\Domain\Rorelser;
use TDL\Support\AppFel;
use TDL\Support\Auth;
use TDL\Support\Arbetsstalle;
use TDL\Support\Backupkontroll;
use TDL\Support\Historik;
use TDL\Support\Koder;
use TDL\Support\Konto;
use TDL\Support\Installningar;
use TDL\Support\Json;
use TDL\Support\Sakerhet;
use TDL\Support\Svar;

final class Sidor
{

    /* ================================================================
     *  Arbetsställe – valet innan arbetet börjar
     * ================================================================ */

    /** Spara valt arbetsställe i sessionen och gå vidare. */
    public static function arbetsstalleValj(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();

        $platsId = post('plats_id');
        if ($platsId === '') {
            // "Arbeta överallt" är en administratörsgenväg
            if (($konto['roll'] ?? '') !== 'admin') {
                throw new AppFel('Välj ett arbetsställe för att fortsätta.');
            }
            // Inte glom() – den rensar även "valet gjort" och då återvänder
            // valsidan i evighet. valj('') sätter flaggan utan plats-id.
            Arbetsstalle::valj('');
            flash('info', 'Du arbetar utan fast arbetsställe. Välj ställe i varje steg.');
        } else {
            Arbetsstalle::valj($platsId);
            $plats = Arbetsstalle::aktivt();
            flash('ok', 'Arbetsställe: ' . ($plats['namn'] ?? '') . '. Det fylls i automatiskt i formulären.');
        }

        $nasta = post('nasta');
        if ($nasta === '' || !str_starts_with($nasta, '/')) {
            $nasta = '/';
        }
        omdirigera($nasta);
    }

    /** Byt arbetsställe när som helst (från huvudet). */
    public static function arbetsstalleByt(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        Arbetsstalle::glom();
        flash('info', 'Välj nytt arbetsställe.');
        omdirigera('/');
    }

    /* ================================================================
     *  Startsida
     * ================================================================ */

    /**
     * Startsidan – appens nav.
     *
     * En ingång till allt: lagret, artiklarna, utskrifter och adminpanelen.
     * Den som jobbar ska aldrig behöva leta i menyn för att komma till
     * inleverans – ett klick ska räcka.
     *
     * Översikten (saldo per ställe, larm, inventeringar) ligger på /oversikt
     * och nås från första kortet här.
     */
    public static function startsida(): void
    {
        $konto = Auth::kravInloggning();

        // ---- Arbetsstället väljs innan arbetet börjar -------------------
        // Porten ligger här och inte på översikten, eftersom startsidan är
        // det första man möter efter inloggning.
        $valbara = Arbetsstalle::valbara();
        if ($valbara !== [] && !Arbetsstalle::valetGjort()) {
            Vyer::rendera('valj_arbetsstalle', [
                'titel' => 'Välj arbetsställe',
                'konto' => $konto,
                'aktiv' => 'start',
                'platser' => $valbara,
                'nasta' => get('nasta'),
            ]);
            return;
        }

        $platser = array_values(array_filter(Lager::platser(), fn ($p) => empty($p['system'])));
        $artiklar = Lager::artiklar(true);
        $sessioner = Inventering::sessioner(PHP_INT_MAX);
        $oppna = array_values(array_filter($sessioner, fn ($s) => ($s['status'] ?? '') === 'öppen'));
        $enheter = Lager::enheter();
        $brister = self::underMinimiLista($artiklar, $platser, $enheter);
        $underMin = count($brister);
        $aktivtStalle = Arbetsstalle::aktivt();
        $uppgifter = \TDL\Support\DagensUppgifter::bygg(
            $konto, $aktivtStalle, $oppna, $brister,
            ($konto['roll'] ?? '') === 'admin' ? Backupkontroll::lage() : null
        );

        Vyer::rendera('startsida_nav', [
            'titel' => 'Start',
            'konto' => $konto,
            'aktiv' => 'start',
            'antalArtiklar' => count($artiklar),
            'antalPlatser' => count($platser),
            'antalEnheter' => count($enheter),
            'underMin' => $underMin,
            'aktivtStalle' => $aktivtStalle,
            'uppgifter' => $uppgifter,
        ]);
    }

    /** Översikten: saldo per arbetsställe, larm och pågående arbete. */
    public static function oversikt(): void
    {
        $konto = Auth::kravInloggning();

        // ---- Arbetsstället väljs innan arbetet börjar -------------------
        // Den som jobbar i lagret står på ett ställe under sitt pass. Valet
        // sparas i sessionen och fyller i sig självt i alla formulär. Finns
        // flera arbetsställen och inget är valt visas valsidan i stället för
        // översikten – så att ingen börjar arbeta "någonstans".
        $valbara = Arbetsstalle::valbara();
        if ($valbara !== [] && !Arbetsstalle::valetGjort()) {
            Vyer::rendera('valj_arbetsstalle', [
                'titel' => 'Välj arbetsställe',
                'konto' => $konto,
                'aktiv' => 'start',
                'platser' => $valbara,
                'nasta' => get('nasta'),
            ]);
            return;
        }

        $platser = Lager::platser();
        $artiklar = Lager::artiklar(true);
        $enheter = Lager::enheter();
        $sessioner = Inventering::sessioner(20);
        $oppna = array_values(array_filter($sessioner, fn ($s) => ($s['status'] ?? '') === 'öppen'));

        $perPlats = [];
        foreach ($platser as $p) {
            $perPlats[$p['id']] = ['plats' => $p, 'antal_artiklar' => 0, 'enheter' => 0, 'under_min' => 0];
        }
        foreach ($artiklar as $a) {
            $saldo = Lager::saldoPerPlats($a, $enheter);
            foreach ($platser as $p) {
                $n = $saldo[$p['id']] ?? 0;
                if ($n > 0) {
                    $perPlats[$p['id']]['antal_artiklar']++;
                }
                if ($a['miniminiva'] > 0 && $n < $a['miniminiva']) {
                    $perPlats[$p['id']]['under_min']++;
                }
            }
        }
        foreach ($enheter as $en) {
            if (($en['status'] ?? '') === 'aktiv' && isset($perPlats[$en['plats_id']])) {
                $perPlats[$en['plats_id']]['enheter']++;
            }
        }

        $underMinimi = self::underMinimiLista($artiklar, $platser, $enheter);

        Vyer::rendera('startsida', [
            'titel' => 'Översikt',
            'konto' => $konto,
            'aktiv' => 'oversikt',
            'perPlats' => $perPlats,
            'antalArtiklar' => count($artiklar),
            'oppnaSessioner' => $oppna,
            'sessioner' => array_slice($sessioner, 0, 5),
            'underMinimi' => $underMinimi,
            'backupLage' => Backupkontroll::lage(),
            'aktivtStalle' => Arbetsstalle::aktivt(),
        ]);
    }

    /**
     * Artiklar som ligger under miniminivå på minst ett arbetsställe.
     * Varje rad: artikel, plats, saldo, miniminivå, brist.
     */
    public static function underMinimiLista(?array $artiklar = null, ?array $platser = null, ?array $enheter = null): array
    {
        $artiklar ??= Lager::artiklar(true);
        $platser ??= Lager::platser();
        $enheter ??= Lager::enheter();
        $platsNamn = [];
        foreach ($platser as $p) {
            $platsNamn[$p['id']] = $p;
        }
        $rader = [];
        foreach ($artiklar as $a) {
            $min = (int) ($a['miniminiva'] ?? 0);
            if ($min <= 0) {
                continue;
            }
            $saldo = Lager::saldoPerPlats($a, $enheter);
            foreach ($platser as $p) {
                if (!empty($p['system'])) {
                    continue;
                }
                $n = (int) ($saldo[$p['id']] ?? 0);
                if ($n < $min) {
                    $rader[] = [
                        'artikel' => $a,
                        'plats' => $p,
                        'saldo' => $n,
                        'miniminiva' => $min,
                        'brist' => $min - $n,
                    ];
                }
            }
        }
        usort($rader, fn ($x, $y) => $y['brist'] <=> $x['brist']);
        return $rader;
    }

    /** Sida: allt under miniminivå. */
    /**
     * Överskott → brist: var kan en brist fyllas utan att beställa?
     *
     * För varje artikel jämförs saldot på alla öppna arbetsställen med
     * miniminivån. Ställen över nivån är givare, ställen under är mottagare.
     * Förslaget tar aldrig mer än givaren har över miniminivån och aldrig
     * mer än mottagaren saknar – alltså kan det genomföras direkt med
     * appens vanliga överföring, utan att något saldo blir negativt.
     *
     * @return list<array{artikel: array, fran: array, till: array, antal: int}>
     */
    private static function flyttforslag(): array
    {
        $platser = array_values(array_filter(
            Lager::platser(),
            fn ($p) => empty($p['system']) && !empty($p['aktiv'])
        ));
        if (count($platser) < 2) {
            return [];
        }
        $enheter = Lager::enheter();
        $forslag = [];
        foreach (Lager::artiklar(true) as $a) {
            $min = (int) ($a['miniminiva'] ?? 0);
            if ($min <= 0) {
                continue;
            }
            $saldo = Lager::saldoPerPlats($a, $enheter);
            $givare = [];
            $mottagare = [];
            foreach ($platser as $p) {
                $n = (int) ($saldo[$p['id']] ?? 0);
                if ($n > $min) {
                    $givare[] = ['plats' => $p, 'kvar' => $n - $min];
                } elseif ($n < $min) {
                    $mottagare[] = ['plats' => $p, 'behov' => $min - $n];
                }
            }
            if ($givare === [] || $mottagare === []) {
                continue;
            }
            usort($givare, fn ($x, $y) => $y['kvar'] <=> $x['kvar']);
            usort($mottagare, fn ($x, $y) => $y['behov'] <=> $x['behov']);
            foreach ($mottagare as $m) {
                while ($m['behov'] > 0 && $givare !== []) {
                    $antal = min($m['behov'], $givare[0]['kvar']);
                    if ($antal > 0) {
                        $forslag[] = [
                            'artikel' => $a,
                            'fran' => $givare[0]['plats'],
                            'till' => $m['plats'],
                            'antal' => $antal,
                        ];
                        $m['behov'] -= $antal;
                        $givare[0]['kvar'] -= $antal;
                    }
                    if ($givare[0]['kvar'] <= 0) {
                        array_shift($givare);
                    }
                }
            }
        }
        return $forslag;
    }

    /**
     * Beställningsförslag: miniminivå + förbrukning + ledtid.
     *
     * Förbrukningen räknas ur historiken (bara riktiga uttag, inte
     * avstämningar) under de senaste N dagarna. Målet är att täcka
     * ledtiden plus en vald täckningstid. Förslaget är aldrig mindre än
     * den sammanlagda bristen, så listan kan användas direkt vid inköp.
     *
     * @return array{rader: list<array>, ledtid: int, tackning: int, fonster: int}
     */
    private static function bestallningsforslag(): array
    {
        $ledtid = max(0, min(120, (int) Installningar::hamta('inkop_ledtid', 7)));
        $tackning = max(0, min(180, (int) Installningar::hamta('inkop_tackning', 30)));
        $fonster = (int) Installningar::hamta('inkop_forbrukning_dagar', 90);
        if (!in_array($fonster, [30, 60, 90, 180], true)) {
            $fonster = 90;
        }

        // Historiken kan vara stor. Samma svar återanvänds i 60 sekunder;
        // ett nytt uttag syns alltså inom en minut, vilket är gott nog för
        // en inköpslista och sparar en full genomläsning per sidladdning.
        $cache = $_SESSION['bestallning_cache'] ?? null;
        if (session_status() === PHP_SESSION_ACTIVE && is_array($cache)
            && ($cache['ledtid'] ?? null) === $ledtid
            && ($cache['tackning'] ?? null) === $tackning
            && ($cache['fonster'] ?? null) === $fonster
            && (time() - (int) ($cache['tid'] ?? 0)) < 60
            && is_array($cache['svar'] ?? null)) {
            return $cache['svar'];
        }

        $start = gmdate('Y-m-d\TH:i:s\Z', time() - $fonster * 86400);
        $forbrukning = [];
        try {
            foreach (Json::las('historik')['poster'] as $post) {
                if (($post['typ'] ?? '') !== 'uttag' || (string) ($post['tid'] ?? '') < $start) {
                    continue;
                }
                $id = (string) ($post['artikel_id'] ?? '');
                if ($id !== '') {
                    $forbrukning[$id] = ($forbrukning[$id] ?? 0) + max(0, (int) ($post['antal'] ?? 0));
                }
            }
        } catch (\Throwable $e) {
            // Utan historik blir förbrukningen noll – bristen räknas ändå.
            $forbrukning = [];
        }

        $platser = array_values(array_filter(
            Lager::platser(),
            fn ($p) => empty($p['system']) && !empty($p['aktiv'])
        ));
        $enheter = Lager::enheter();
        $rader = [];
        foreach (Lager::artiklar(true) as $a) {
            $min = (int) ($a['miniminiva'] ?? 0);
            if ($min <= 0) {
                continue;
            }
            $saldo = Lager::saldoPerPlats($a, $enheter);
            $totalt = 0;
            $brist = 0;
            foreach ($platser as $p) {
                $n = (int) ($saldo[$p['id']] ?? 0);
                $totalt += $n;
                if ($n < $min) {
                    $brist += $min - $n;
                }
            }
            if ($brist <= 0) {
                continue;
            }
            $bruk = (int) ($forbrukning[$a['id']] ?? 0);
            $takt = $bruk / $fonster;
            $mal = (int) ceil($takt * ($ledtid + $tackning));
            $forslag = max($brist, $mal - $totalt, 0);
            if ($forslag <= 0) {
                continue;
            }
            $rader[] = [
                'artikel' => $a,
                'totalt' => $totalt,
                'brist' => $brist,
                'bruk' => $bruk,
                'takt' => $takt,
                'mal' => $mal,
                'forslag' => $forslag,
                'racker' => $takt > 0 ? (int) floor($totalt / $takt) : null,
            ];
        }
        usort($rader, static function ($x, $y) {
            $a = $x['racker'] ?? PHP_INT_MAX;
            $b = $y['racker'] ?? PHP_INT_MAX;
            return $a <=> $b ?: strcmp((string) $x['artikel']['namn'], (string) $y['artikel']['namn']);
        });
        return ['rader' => $rader, 'ledtid' => $ledtid, 'tackning' => $tackning, 'fonster' => $fonster];
    }

    /** Beställningsförslagen som CSV – öppnas direkt i Excel. */
    public static function bestallningCsv(): void
    {
        Auth::kravInloggning();
        $b = self::bestallningsforslag();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bestallningsforslag-' . gmdate('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo "\xEF\xBB\xBF";
        $ut = fopen('php://output', 'w');
        if ($ut === false) {
            return;
        }
        // Semikolon och BOM gör att Excel i svensk miljö tolkar filen rätt.
        fputcsv($ut, ['Artikel', 'Artikelnummer', 'Totalt saldo', 'Förbrukning per dag', 'Räcker (dagar)', 'Förslag', 'Enhet'], ';');
        foreach ($b['rader'] as $r) {
            fputcsv($ut, [
                $r['artikel']['namn'],
                $r['artikel']['artikelnummer'],
                $r['totalt'],
                number_format($r['takt'], 2, ',', ''),
                $r['racker'] === null ? '' : $r['racker'],
                $r['forslag'],
                $r['artikel']['enhet'],
            ], ';');
        }
        fclose($ut);
        exit;
    }

    public static function miniminiva(): void
    {
        $konto = Auth::kravInloggning();
        $rader = self::underMinimiLista();
        Vyer::rendera('miniminiva', [
            'titel' => 'Under miniminivå',
            'konto' => $konto,
            'aktiv' => 'start',
            'rader' => $rader,
            'flyttningar' => self::flyttforslag(),
            'bestallning' => self::bestallningsforslag(),
            'larmPa' => (bool) Installningar::hamta('miniminiva_larm'),
        ]);
    }

    /* ================================================================
     *  Arkiv (år och månad)
     * ================================================================ */

    public static function arkiv(): void
    {
        $konto = Auth::kravInloggning();
        $ar = (int) (get('ar') ?: gmdate('Y'));
        $manad = (int) (get('manad') ?: 0);
        $sessionId = get('session');

        $oversikt = Arkiv::oversikt();
        $allaAr = Arkiv::ar();
        if ($allaAr === []) {
            $allaAr = [(int) gmdate('Y')];
        }
        if (!in_array($ar, $allaAr, true)) {
            $ar = $allaAr[0];
        }

        $manadsdata = $manad >= 1 && $manad <= 12 ? Arkiv::lasManad($ar, $manad) : null;
        $valdPost = $sessionId !== '' ? Arkiv::hitta($sessionId) : null;

        Vyer::rendera('arkiv', [
            'titel' => 'Arkiv',
            'konto' => $konto,
            'aktiv' => 'arkiv',
            'ar' => $ar,
            'manad' => $manad,
            'allaAr' => $allaAr,
            'oversikt' => $oversikt,
            'manadsdata' => $manadsdata,
            'arssammanstallning' => Arkiv::arssammanstallning($ar),
            'valdPost' => $valdPost,
            'manadsnamn' => [1=>'januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'],
        ]);
    }

    /** Arkivera alla avslutade inventeringar som saknas i arkivet. */
    public static function arkivBygg(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $antal = Arkiv::arkiveraAlla($konto);
        flash('ok', $antal === 0
            ? 'Allt var redan arkiverat.'
            : $antal . ' inventering(ar) arkiverades.');
        omdirigera('/arkiv');
    }

    /* ================================================================
     *  Artiklar
     * ================================================================ */

    public static function artiklar(): void
    {
        $konto = Auth::kravInloggning();
        $q = get('q');
        $kategori = get('kategori');
        $hylla = get('hylla');
        $endastUnderMin = get('under_min') === '1';

        $alla = Lager::artiklar();
        if ($q !== '') {
            $alla = array_values(array_filter($alla, function ($a) use ($q) {
                return mb_stripos($a['namn'], $q) !== false
                    || mb_stripos($a['artikelnummer'], $q) !== false
                    || mb_stripos((string) ($a['kategori'] ?? ''), $q) !== false
                    || mb_stripos((string) ($a['hylla'] ?? ''), $q) !== false;
            }));
        }
        if ($kategori !== '') {
            $alla = array_values(array_filter($alla, fn ($a) => mb_strtolower((string) ($a['kategori'] ?? '')) === mb_strtolower($kategori)));
        }
        if ($hylla !== '') {
            $alla = array_values(array_filter($alla, fn ($a) => mb_strtolower((string) ($a['hylla'] ?? '')) === mb_strtolower($hylla)));
        }

        // Hyllsortering när man filtrerar på hylla: då är plockordningen rätt ordning.
        if ($hylla !== '') {
            usort($alla, fn ($a, $b) => strnatcasecmp((string) $a['namn'], (string) $b['namn']));
        } else {
            usort($alla, fn ($a, $b) => strcmp($a['namn'], $b['namn']));
        }

        $enheter = Lager::enheter();
        $rader = [];
        foreach ($alla as $a) {
            $saldo = Lager::saldoPerPlats($a, $enheter);
            $total = Lager::totalt($saldo);
            if ($endastUnderMin && !(!empty($a['miniminiva']) && $total < (int) $a['miniminiva'])) {
                continue;
            }
            $rader[] = ['artikel' => $a, 'total' => $total, 'saldo' => $saldo];
        }

        Vyer::rendera('artiklar', [
            'titel' => 'Artiklar',
            'konto' => $konto,
            'aktiv' => 'artiklar',
            'rader' => $rader,
            'q' => $q,
            'kategori' => $kategori,
            'hylla' => $hylla,
            'underMin' => $endastUnderMin,
            'kategorier' => Lager::kategorier(),
            'hyllor' => Lager::hyllor(),
        ]);
    }

    public static function artikel(string $id): void
    {
        $konto = Auth::kravInloggning();
        $artikel = Lager::artikel($id);
        if ($artikel === null) {
            throw new AppFel('Artikeln finns inte.', 404);
        }
        $oversikt = Lager::saldoOversikt($artikel);
        $enheter = $artikel['sparningssatt'] === Lager::SPAR_QR
            ? Lager::enheter(['artikel_id' => $id])
            : [];
        $historik = array_values(array_filter(Json::las('historik')['poster'], fn ($h) => ($h['artikel_id'] ?? '') === $id));
        $historik = array_reverse($historik);
        // Verktygstavla: innehållet och vilka artiklar som kan läggas till
        $delar = [];
        $valjbara = [];
        if (\TDL\Domain\Tavlor::arTavla($artikel)) {
            $delar = \TDL\Domain\Tavlor::innehall($artikel);
            $redanMed = array_column($delar, 'artikel_id');
            foreach (Lager::artiklar(true) as $a) {
                if ($a['id'] === $id || in_array($a['id'], $redanMed, true)) {
                    continue;
                }
                // En tavla kan inte ligga inuti en annan tavla
                if (!empty($a['ar_tavla'])) {
                    continue;
                }
                $valjbara[] = $a;
            }
            usort($valjbara, fn ($a, $b) => strcmp($a['namn'], $b['namn']));
        }

        Vyer::rendera('artikel', [
            'titel' => $artikel['namn'],
            'konto' => $konto,
            'aktiv' => 'artiklar',
            'artikel' => $artikel,
            'bilder' => \TDL\Support\Bilder::forArtikel($artikel),
            'oversikt' => $oversikt,
            'enheter' => $enheter,
            'historik' => array_slice($historik, 0, 40),
            'delar' => $delar,
            'valjbara' => $valjbara,
            // Tavlor man kan flytta en del till (alla andra tavlor)
            'mottagare' => \TDL\Domain\Tavlor::mojligaMottagare($artikel['id']),
            'arbetsstalle' => \TDL\Support\Arbetsstalle::aktivt(),
        ]);
    }

    public static function artikelForm(?string $id = null): void
    {
        $konto = Auth::kravInloggning();
        // Personal får skapa nya artiklar. Att ÄNDRA en befintlig artikel
        // (pris, artikelnummer, sparningssätt) är fortfarande admin.
        if ($id !== null) {
            $konto = Auth::kravRoll('admin');
        }
        $artikel = $id === null ? null : Lager::artikel($id);
        if ($id !== null && $artikel === null) {
            throw new AppFel('Artikeln finns inte.', 404);
        }
        Vyer::rendera('artikel_form', [
            'titel' => $artikel === null ? 'Ny artikel' : 'Redigera ' . $artikel['namn'],
            'konto' => $konto,
            'aktiv' => 'artiklar',
            'artikel' => $artikel,
            'platser' => $artikel === null ? array_values(array_filter(Lager::platser(), fn ($p) => empty($p['system']))) : [],
            'kategorier' => Lager::kategorier(),
            'hyllor' => Lager::hyllor(),
            // Lagerplats: zon/rad/fack. Förval från hyllappen (?zon=&rad=&fack=).
            'zoner' => \TDL\Domain\Lagerplats::zoner(),
            'forvalZon' => (string) get('zon'),
            'forvalRad' => (int) get('rad'),
            'forvalFack' => (int) get('fack'),
            // Verktygstavla: bara admin får skapa/ändra innehållet
            'farHanteraTavla' => ($konto['roll'] ?? '') === 'admin',
            'taveldelar' => $artikel === null ? [] : \TDL\Domain\Tavlor::innehall($artikel),
            'allaArtiklar' => array_values(array_filter(
                Lager::artiklar(true),
                fn ($a) => empty($a['ar_tavla']) && ($artikel === null || $a['id'] !== $artikel['id'])
            )),
            'arTavla' => $artikel !== null && \TDL\Domain\Tavlor::arTavla($artikel),
        ]);
    }

    public static function artikelSkapa(): void
    {
        // Personal får lägga upp nya artiklar – det är de som ser vad som fattas.
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();

        // Personal får lägga upp artikeln (namn, enhet, sparningssätt) men
        // INTE bestämma artikelnummer eller pris – det är en adminuppgift
        // som styr ekonomi och etiketter. Servern sätter dem, inte formuläret.
        $data = $_POST;
        if (($konto['roll'] ?? '') !== 'admin') {
            $data['artikelnummer'] = self::foreslaArtikelnummer(post('namn'));
            $data['prisreferens'] = '';
        } elseif (trim(post('artikelnummer')) === '') {
            // Admin fick tidigare ett fel om numret lämnades tomt. Fältet är
            // markerat som obligatoriskt, men ett tomt fält ska inte kosta en
            // hel inmatning – föreslå samma lediga nummer som för personal.
            $data['artikelnummer'] = self::foreslaArtikelnummer(post('namn'));
        }
        // Verktygstavla: delarna läses ur del_artikel[] / del_antal[].
        // Bara admin får göra en artikel till en tavla.
        if (($konto['roll'] ?? '') === 'admin' && post('ar_tavla') === '1') {
            $data['ar_tavla'] = true;
            $data['innehall'] = \TDL\Domain\Tavlor::raderFranFormular();
        }

        $artikel = Lager::skapaArtikel($data, $konto);

        // Skapa etiketter direkt om man valde det
        $antalEtiketter = (int) post('antal_etiketter', '0');
        $platsEtikett = post('etikett_plats');
        $nya = [];
        if ($antalEtiketter > 0 && $platsEtikett !== '' && $artikel['sparningssatt'] === Lager::SPAR_QR) {
            $nya = Rorelser::skapaEnheter($artikel['id'], $platsEtikett, $antalEtiketter, $konto);
            flash('ok', $artikel['namn'] . ' skapades med ' . count($nya) . ' etiketter. Skriv ut dem nu.');
            omdirigera('/etiketter/utskrift?artikel=' . urlencode($artikel['id'])
                . '&koder=' . urlencode(implode(',', array_column($nya, 'id'))));
        }

        flash('ok', 'Artikeln ' . $artikel['namn'] . ' skapades.');
        omdirigera('/artiklar/' . $artikel['id'] . '?ny=1');
    }

    /** Föreslå ett ledigt artikelnummer när personal lägger upp en artikel. */
    private static function foreslaArtikelnummer(string $namn): string
    {
        $bas = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $namn) ?: 'ART', 0, 3));
        if ($bas === '') {
            $bas = 'ART';
        }
        $befintliga = [];
        foreach (Lager::artiklar() as $a) {
            $befintliga[mb_strtoupper($a['artikelnummer'])] = true;
        }
        for ($i = 1; $i < 1000; $i++) {
            $kandidat = $bas . '-' . $i;
            if (!isset($befintliga[mb_strtoupper($kandidat)])) {
                return $kandidat;
            }
        }
        return $bas . '-' . bin2hex(random_bytes(2));
    }

    public static function artikelAndra(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $data = $_POST;
        // Formuläret skickar alltid med tavlefälten, så innehållet kan
        // redigeras i samma svep som resten av artikeln.
        if (post('ar_tavla') === '1' || post('hade_innehall') === '1') {
            $data['ar_tavla'] = post('ar_tavla') === '1';
            $data['innehall'] = \TDL\Domain\Tavlor::raderFranFormular();
        }
        Lager::andraArtikel($id, $data, $konto);
        flash('ok', 'Artikeln uppdaterades.');
        omdirigera('/artiklar/' . $id);
    }

    public static function artikelInaktivera(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Lager::inaktiveraArtikel($id, $konto);
        flash('ok', 'Artikeln inaktiverades. Historiken finns kvar.');
        omdirigera('/artiklar');
    }

    /* ================================================================
     *  Rörelser
     * ================================================================ */

    private static function formData(): array
    {
        return [
            'artiklar' => Lager::artiklar(true),
            'platser' => array_values(array_filter(Lager::platser(true), fn ($p) => empty($p['system']))),
        ];
    }

    public static function inleveransForm(): void
    {
        $konto = Auth::kravInloggning();
        $data = self::formData();
        $data['titel'] = 'Inleverans';
        $data['konto'] = $konto;
        $data['aktiv'] = 'inleverans';
        $data['valdArtikel'] = get('artikel');
        // ?plats= vinner (t.ex. från en QR-skannad platslapp), annars det valda
        // arbetsstället från passet. Servern läser ändå alltid det som skickas in.
        $data['valdPlats'] = get('plats') !== '' ? get('plats') : Arbetsstalle::forvalt();
        Vyer::rendera('inleverans', $data);
    }

    public static function inleverans(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $artikelId = post('artikel_id');
        $platsId = post('plats_id');
        $antal = (int) post('antal', '0');
        $koder = post_lista('enhetskoder');
        $kommentar = post('kommentar') ?: null;
        $resultat = Rorelser::inleverans($artikelId, $platsId, $antal, $koder, $konto, $kommentar);
        flash('ok', 'Inleverans registrerad: ' . $resultat['antal'] . ' styck. Historiken är uppdaterad.');
        omdirigera('/artiklar/' . $artikelId);
    }

    public static function uttagForm(): void
    {
        $konto = Auth::kravInloggning();
        $data = self::formData();
        $data['titel'] = 'Uttag';
        $data['konto'] = $konto;
        $data['aktiv'] = 'uttag';
        $data['valdPlats'] = Arbetsstalle::forvalt();
        $data['tvaPerson'] = [
            'antal' => (int) Installningar::hamta('tva_person_grans'),
            'varde' => (int) Installningar::hamta('tva_person_varde'),
        ];
        // Kollegor som får godkänna (alla andra aktiva konton)
        $data['kollegor'] = array_values(array_filter(
            Konto::anvandare(),
            fn ($u) => !empty($u['aktiv']) && $u['id'] !== $konto['id']
        ));
        Vyer::rendera('uttag', $data);
    }

    public static function uttag(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $artikelId = post('artikel_id');
        $platsId = post('plats_id');
        $antal = (int) post('antal', '0');
        $koder = post_lista('enhetskoder');

        // Tvåpersonersregel: kollegans namn + PIN kontrolleras här
        $godkannare = null;
        $godkannarNamn = post('godkannare');
        if ($godkannarNamn !== '') {
            $godkannare = Konto::verifieraInloggning($godkannarNamn, post('godkannare_pin'));
        }

        $resultat = Rorelser::uttag($artikelId, $platsId, $antal, $koder, $konto, post('kommentar') ?: null, $godkannare);
        $msg = 'Uttag registrerat: ' . $resultat['antal'] . ' styck.';
        if ($godkannare !== null) {
            $msg .= ' Bekräftat av ' . $godkannare['namn'] . '.';
        }
        $msg .= self::delarTillText($resultat['delar'] ?? [], 'uttag');
        flash('ok', $msg);
        omdirigera('/artiklar/' . $artikelId);
    }

    /**
     * Beskriver vad som hände med en tavlas delar, för flash-meddelandet.
     * Vid uttag: vilka som följde med och vilka som saknades.
     * Vid inleverans: vilka som kom tillbaka.
     */
    private static function delarTillText(array $delar, string $riktning): string
    {
        if ($delar === []) {
            return '';
        }
        $ut = '';
        if ($riktning === 'uttag') {
            $med = $delar['utdragna'] ?? [];
            if ($med !== []) {
                $ut .= ' Delarna följde med: ' . implode(', ', array_map(
                    fn ($d) => $d['namn'] . ' ×' . $d['antal'], $med
                )) . '.';
            }
            foreach ($delar['saknade'] ?? [] as $sak) {
                $ut .= ' OBS: ' . $sak['namn'] . ' saknades på platsen (behövdes '
                    . $sak['behov'] . ', fanns ' . $sak['fanns'] . ').';
            }
        } else {
            $tillbaka = $delar['aterstallda'] ?? [];
            if ($tillbaka !== []) {
                $ut .= ' Delarna kom tillbaka: ' . implode(', ', array_map(
                    fn ($d) => $d['namn'] . ' ×' . $d['antal'], $tillbaka
                )) . '.';
            }
            foreach ($delar['misslyckade'] ?? [] as $fel) {
                $ut .= ' OBS: ' . $fel['namn'] . ' kunde inte läggas tillbaka (' . $fel['fel'] . ').';
            }
        }
        return $ut;
    }

    /* ================================================================
     *  Verktygstavla – lägg till och ta bort delar
     * ================================================================ */

    /** Lägger till en del i en tavla. */
    public static function tavlaLaggTill(string $artikelId): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $delId = post('del_id');
        if ($delId === '') {
            flash('fel', 'Välj vilken artikel som ska läggas till.');
            omdirigera('/artiklar/' . $artikelId);
        }
        $tavla = Lager::artikel($artikelId);
        if ($tavla === null) {
            throw new AppFel('Artikeln finns inte.', 404);
        }
        if (empty($tavla['ar_tavla'])) {
            throw new AppFel('Artikeln är ingen verktygstavla.');
        }
        $antal = max(1, (int) post('del_antal'));
        $fack = mb_substr(trim(post('del_fack')), 0, 40);

        // Befintligt innehåll + den nya raden
        $rader = [];
        foreach ($tavla['innehall'] ?? [] as $r) {
            $rader[] = ['artikel_id' => $r['artikel_id'], 'antal' => (int) $r['antal'], 'fack' => (string) ($r['fack'] ?? '')];
        }
        $redan = false;
        foreach ($rader as $i => $r) {
            if ($r['artikel_id'] === $delId) {
                // Redan i tavlan: öka antalet i stället för att lägga till en dubblett
                $rader[$i]['antal'] += $antal;
                $redan = true;
            }
        }
        if (!$redan) {
            $rader[] = ['artikel_id' => $delId, 'antal' => $antal, 'fack' => $fack];
        }

        Lager::andraArtikel($artikelId, ['ar_tavla' => true, 'innehall' => $rader], $konto);
        $del = Lager::artikel($delId);
        flash('ok', ($del['namn'] ?? 'Delen') . ' ×' . $antal . ' lades till i tavlan.');
        omdirigera('/artiklar/' . $artikelId);
    }

    /** Flyttar en del från den här tavlan till en annan, i ett svep. */
    public static function tavlaFlytta(string $artikelId): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();

        $tillId = post('till_tavla');
        $delId = post('del_id');
        if ($tillId === '' || $delId === '') {
            flash('fel', 'Välj både del och mottagande tavla.');
            omdirigera('/artiklar/' . $artikelId);
        }
        $antal = max(1, (int) post('del_antal'));
        $fack = mb_substr(trim(post('del_fack')), 0, 40);

        $res = \TDL\Domain\Tavlor::flyttaDel($artikelId, $tillId, $delId, $antal, $fack, $konto);

        $text = $res['del'] . ' ×' . $res['antal'] . ' flyttades från ' . $res['fran'] . ' till ' . $res['till'] . '.';
        if ($res['kvar'] > 0) {
            $text .= ' ' . $res['kvar'] . ' ' . $res['enhet'] . ' ligger kvar i ' . $res['fran'] . '.';
        }
        flash('ok', $text);
        omdirigera('/artiklar/' . $artikelId);
    }

    /** Tar bort en del ur en tavla. */
    public static function tavlaTaBort(string $artikelId): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $delId = post('del_id');
        $tavla = Lager::artikel($artikelId);
        if ($tavla === null) {
            throw new AppFel('Artikeln finns inte.', 404);
        }
        if (empty($tavla['ar_tavla'])) {
            throw new AppFel('Artikeln är ingen verktygstavla.');
        }
        $rader = [];
        $bort = null;
        foreach ($tavla['innehall'] ?? [] as $r) {
            if ($r['artikel_id'] === $delId) {
                $bort = $r;
                continue;
            }
            $rader[] = ['artikel_id' => $r['artikel_id'], 'antal' => (int) $r['antal'], 'fack' => (string) ($r['fack'] ?? '')];
        }
        if ($bort === null) {
            flash('fel', 'Delen finns inte i tavlan.');
            omdirigera('/artiklar/' . $artikelId);
        }

        // Tar man bort sista delen är det ingen tavla längre
        $data = ['innehall' => $rader, 'ar_tavla' => $rader !== []];
        Lager::andraArtikel($artikelId, $data, $konto);
        $del = Lager::artikel($delId);
        flash('ok', ($del['namn'] ?? 'Delen') . ' togs bort ur tavlan. Artikeln finns kvar.');
        omdirigera('/artiklar/' . $artikelId);
    }

    /* ================================================================
     *  Plocklista – flera uttag i en rörelse
     * ================================================================ */

    public static function plocklistaForm(): void
    {
        $konto = Auth::kravInloggning();

        $platser = array_values(array_filter(Lager::platser(true), fn ($p) => empty($p['system'])));
        $valdPlats = get('plats');
        if ($valdPlats !== '' && !in_array($valdPlats, array_column($platser, 'id'), true)) {
            $valdPlats = '';
        }
        if ($valdPlats === '') {
            // Arbetsstället från passets början
            $valdPlats = Arbetsstalle::forvalt();
        }
        if ($valdPlats === '' && count($platser) === 1) {
            $valdPlats = $platser[0]['id'];
        }

        // Bara antalsartiklar kan plockas i lista – QR-enheter måste pekas ut en och en.
        $saldoPlats = [];
        $artiklar = [];
        foreach (Lager::artiklar(true) as $a) {
            if (($a['sparningssatt'] ?? '') !== Lager::SPAR_ANTAL) {
                continue;
            }
            $artiklar[] = $a;
            $saldoPlats[$a['id']] = $valdPlats === '' ? 0 : (int) ($a['saldo'][$valdPlats] ?? 0);
        }
        usort($artiklar, fn ($a, $b) => strnatcasecmp((string) ($a['hylla'] ?? '~'), (string) ($b['hylla'] ?? '~')) ?: strcmp($a['namn'], $b['namn']));

        // Förifyllnad: först ett utkast från ett avbrutet försök, sedan ?artikel=A…
        $rader = [];
        $kommentar = get('kommentar');
        $utkast = $_SESSION['plocklista_utkast'] ?? null;
        unset($_SESSION['plocklista_utkast']);
        if (is_array($utkast)) {
            if ($valdPlats === '' && ($utkast['plats'] ?? '') !== '') {
                $valdPlats = (string) $utkast['plats'];
                // saldot måste räknas om för den platsen
                $saldoPlats = [];
                foreach ($artiklar as $a) {
                    $saldoPlats[$a['id']] = (int) ($a['saldo'][$valdPlats] ?? 0);
                }
            }
            foreach (($utkast['rader'] ?? []) as $r) {
                $r['saldo'] = $saldoPlats[$r['artikel_id']] ?? 0;
                $rader[] = $r;
            }
            $kommentar = (string) ($utkast['kommentar'] ?? $kommentar);
        }
        $forvald = get('artikel');
        if ($rader === [] && $forvald !== '') {
            foreach ($artiklar as $a) {
                if ($a['id'] === $forvald) {
                    $rader[] = [
                        'artikel_id' => $a['id'],
                        'namn' => $a['namn'],
                        'artikelnummer' => $a['artikelnummer'],
                        'enhet' => $a['enhet'],
                        'hylla' => $a['hylla'] ?? '',
                        'plats' => \TDL\Domain\Lagerplats::platsText($a),
                        'plats_nyckel' => \TDL\Domain\Lagerplats::sorteringsNyckel($a),
                        'antal' => max(1, (int) get('antal', '1')),
                        'saldo' => $saldoPlats[$a['id']],
                    ];
                    break;
                }
            }
        }

        Vyer::rendera('plocklista', [
            'titel' => 'Plocklista',
            'konto' => $konto,
            'aktiv' => 'plocklista',
            'artiklar' => $artiklar,
            'platser' => $platser,
            'rader' => $rader,
            'saldoPlats' => $saldoPlats,
            'valdPlats' => $valdPlats,
            'kommentar' => $kommentar,
        ]);
    }

    public static function plocklista(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();

        $platsId = post('plats_id');
        $kommentar = post('kommentar') ?: null;
        $raderIn = $_POST['rader'] ?? [];
        $rader = [];
        if (is_array($raderIn)) {
            foreach ($raderIn as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rader[] = [
                    'artikel_id' => trim((string) ($r['artikel_id'] ?? '')),
                    'antal' => (int) ($r['antal'] ?? 0),
                ];
            }
        }

        try {
            $resultat = Rorelser::plocklista($platsId, $rader, $konto, $kommentar);
        } catch (AppFel $fel) {
            // Behåll listan så att användaren kan rätta raden – tappa den inte.
            $tillbaka = [];
            $alla = [];
            foreach (Lager::artiklar() as $a) {
                $alla[$a['id']] = $a;
            }
            foreach ($rader as $r) {
                $a = $alla[$r['artikel_id']] ?? null;
                if ($a === null) {
                    continue;
                }
                $tillbaka[] = [
                    'artikel_id' => $a['id'],
                    'namn' => $a['namn'],
                    'artikelnummer' => $a['artikelnummer'],
                    'enhet' => $a['enhet'],
                    'hylla' => $a['hylla'] ?? '',
                    'antal' => $r['antal'],
                    'saldo' => (int) ($a['saldo'][$platsId] ?? 0),
                ];
            }
            $_SESSION['plocklista_utkast'] = ['plats' => $platsId, 'rader' => $tillbaka, 'kommentar' => (string) $kommentar];
            flash('fel', $fel->getMessage());
            omdirigera('/plocklista');
        }

        flash('ok', 'Plocklista bokförd: ' . $resultat['antal_rader'] . ' artikelrader, '
            . $resultat['antal_enheter'] . ' enheter totalt.');
        omdirigera('/historik?typ=plocklista');
    }

    public static function overforingForm(): void
    {
        $konto = Auth::kravInloggning();
        $data = self::formData();
        $data['titel'] = 'Överföring';
        $data['konto'] = $konto;
        $data['aktiv'] = 'overforing';
        // Förvalda värden när man kommer från ett flyttförslag på
        // miniminivåsidan. Allt valideras igen av Rorelser::overforing.
        $fran = get('fran');
        $data['valdFran'] = $fran !== '' ? $fran : Arbetsstalle::forvalt();
        $data['valdArtikel'] = get('artikel');
        $data['valdTill'] = get('till');
        $data['valdAntal'] = max(0, (int) get('antal'));
        Vyer::rendera('overforing', $data);
    }

    public static function overforing(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $artikelId = post('artikel_id');
        $fran = post('fran_plats_id');
        $till = post('till_plats_id');
        $antal = (int) post('antal', '0');
        $koder = post_lista('enhetskoder');
        $resultat = Rorelser::overforing($artikelId, $fran, $till, $antal, $koder, $konto, post('kommentar') ?: null);
        flash('ok', 'Överföring genomförd: ' . $resultat['antal'] . ' styck. Enhets-ID:n är oförändrade.');
        omdirigera('/artiklar/' . $artikelId);
    }

    /* ================================================================
     *  Etiketter
     * ================================================================ */

    public static function etiketterForm(): void
    {
        $konto = Auth::kravInloggning();
        $artiklar = array_values(array_filter(Lager::artiklar(true), fn ($a) => $a['sparningssatt'] === Lager::SPAR_QR));

        // Etiketter som är skapade men aldrig tagits emot – de väntar på ett
        // arbetsställe. Visas så att man ser vad som redan är utskrivet och
        // slipper skriva ut samma lappar igen.
        $vantande = [];
        foreach (Lager::enheter() as $en) {
            if (($en['status'] ?? '') === 'vantande') {
                $vantande[$en['artikel_id']] = ($vantande[$en['artikel_id']] ?? 0) + 1;
            }
        }
        $vantandeRader = [];
        foreach (Lager::artiklar(true) as $a) {
            $n = (int) ($vantande[$a['id']] ?? 0);
            if ($n > 0) {
                $vantandeRader[] = ['artikel' => $a, 'antal' => $n];
            }
        }
        usort($vantandeRader, fn ($x, $y) => strcmp($x['artikel']['namn'], $y['artikel']['namn']));

        Vyer::rendera('etiketter', [
            'titel' => 'Etiketter',
            'konto' => $konto,
            'aktiv' => 'etiketter',
            'artiklar' => $artiklar,
            'allaArtiklar' => Lager::artiklar(true),
            'valdArtikel' => get('artikel'),
            'valdTyp' => get('typ'),
            'basUrl' => \TDL\Http\ArtikelQr::basUrl(),
            'vantande' => $vantandeRader,
            'aktivtStalle' => Arbetsstalle::aktivt(),
            'skrivare' => \TDL\Support\Skrivare::aktiv(),
        ]);
    }

    public static function etiketter(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $artikelId = post('artikel_id');
        $antal = (int) post('antal', '0');
        $nya = Rorelser::skapaEtiketter($artikelId, $antal, $konto);
        flash('ok', $antal . ' etiketter skapades som väntande. Skriv ut dem nu.');
        omdirigera('/etiketter/utskrift?artikel=' . urlencode($artikelId) . '&koder=' . urlencode(implode(',', array_map(fn ($n) => $n['id'], $nya))));
    }

    /** PWA-manifest (dynamiskt, följer varumärket). */
    public static function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        $namn = \TDL\Support\Varumarke::v('namn');
        $harEgen = \TDL\Support\Varumarke::v('ikon_fil') !== '';
        $ikoner = [];
        if ($harEgen) {
            $ikoner[] = ['src' => '/varumarke/ikon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
        }
        $ikoner[] = ['src' => '/assets/ikoner/ikon-192.png', 'sizes' => '192x192', 'type' => 'image/png'];
        $ikoner[] = ['src' => '/assets/ikoner/ikon-256.png', 'sizes' => '256x256', 'type' => 'image/png'];
        $ikoner[] = ['src' => '/assets/ikoner/ikon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
        echo json_encode([
            'name' => $namn,
            'short_name' => \TDL\Support\Varumarke::v('kortnamn') ?: mb_substr($namn, 0, 12),
            'description' => 'Lager och inventering med QR. Saldo per arbetsställe, etiketter och historik.',
            'lang' => 'sv-SE',
            'start_url' => '/?kalla=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => \TDL\Support\Varumarke::v('farg_bakgrund'),
            'theme_color' => \TDL\Support\Varumarke::v('farg_bakgrund'),
            'categories' => ['business', 'productivity'],
            'icons' => $ikoner,
            'shortcuts' => [
                ['name' => 'Skanna', 'url' => '/skanna'],
                ['name' => 'Inleverans', 'url' => '/inleverans'],
                ['name' => 'Uttag', 'url' => '/uttag'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Service worker – serveras från appens rot så att den kan styra hela appen. */
    public static function serviceWorker(): void
    {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        readfile(TDL_PUBLIC . '/sw.js');
        exit;
    }

    /** Offline-sida (cachas av service workern). */
    public static function offline(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $namn = e(\TDL\Support\Varumarke::v('namn'));
        echo '<!doctype html><html lang="sv"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Ingen anslutning – ' . $namn . '</title>'
            . '<link rel="stylesheet" href="/assets/app.css"><style>:root{--accent:#2F6BFF;--ok:#34D399;--bak:#0D1420}</style>'
            . '</head><body><main class="innehall"><div class="kort" style="max-width:460px;margin:14vh auto 0;text-align:center">'
            . '<h1>Ingen anslutning</h1>'
            . '<p>Appen kan inte nå servern just nu. Kontrollera nätverket och försök igen.</p>'
            . '<p class="dampad">Inga uppgifter har ändrats – inget sparas utan anslutning.</p>'
            . '<div class="actions" style="justify-content:center"><a class="knapp primar" href="/">Försök igen</a></div>'
            . '</div></main></body></html>';
        exit;
    }

    /** Artikel-QR: etiketter som öppnar artikelns sida. */
    public static function artikelEtiketter(string $artikelId = ''): void
    {
        $konto = Auth::kravInloggning();
        if ($artikelId === '') {
            $artikelId = get('artikel');
        }
        $antal = max(1, min(60, (int) get('antal', '12')));
        $artikel = Lager::artikel($artikelId);
        if ($artikel === null) {
            throw new AppFel('Artikeln finns inte.', 404);
        }
        $forstaBild = $artikel['bilder'][0]['fil'] ?? null;
        $bildUrl = $forstaBild !== null
            ? \TDL\Http\ArtikelQr::bildUrl($artikelId, $forstaBild)
            : null;
        // Etikettstorlek: eget mått i adressfältet vinner, annars artikelns
        // sparade storlek, annars standard.
        $rulle = get('rulle');
        if ($rulle === '' || $rulle === '0') {
            $bredd = (int) get('bredd');
            $hojd = (int) get('hojd');
            if ($bredd > 0 && $hojd > 0) {
                $rulle = $bredd . 'x' . $hojd;
            } else {
                $rulle = (string) ($artikel['etikett_mall'] ?? \TDL\Support\Etikettmallar::STANDARD);
            }
        }

        // De enheter som faktiskt finns för artikeln – koden skrivs ut under
        // streckkoden så att den kan matas in manuellt om etiketten är sliten.
        $enhetsKoder = [];
        foreach (Lager::enheter() as $en) {
            if (($en['artikel_id'] ?? '') === $artikelId) {
                $enhetsKoder[] = \TDL\Support\Koder::visa($en['id']);
            }
        }
        $enhetsKoder = array_values(array_unique($enhetsKoder));

        Vyer::fristaende('artikel_etiketter', [
            'artikel' => $artikel,
            'enhetsKoder' => $enhetsKoder,
            'antal' => $antal,
            'url' => \TDL\Http\ArtikelQr::lankUrl($artikelId, null, true),
            'bildUrl' => $bildUrl,
            'rulle' => $rulle,
            'konto' => $konto,
            'varumarke' => \TDL\Support\Varumarke::alla(),
        ]);
    }

    public static function etiketterUtskrift(): void
    {
        $konto = Auth::kravInloggning();
        $artikelId = get('artikel');
        $koder = array_values(array_filter(array_map('trim', explode(',', get('koder')))));
        $artikel = Lager::artikel($artikelId);
        if ($artikel === null) {
            throw new AppFel('Artikeln finns inte.', 404);
        }
        $rader = [];
        foreach ($koder as $kod) {
            $en = Lager::enhet($kod);
            if ($en !== null) {
                $rader[] = $en;
            }
        }
        if ($rader === []) {
            throw new AppFel('Inga etiketter att skriva ut.');
        }

        // Samma storleksregler här som på artikelns etikettsida: eget mått i
        // adressfältet vinner, annars artikelns sparade storlek, annars standard.
        $rulle = get('rulle');
        if ($rulle === '' || $rulle === '0') {
            $bredd = (int) get('bredd');
            $hojd = (int) get('hojd');
            $rulle = ($bredd > 0 && $hojd > 0)
                ? $bredd . 'x' . $hojd
                : (string) ($artikel['etikett_mall'] ?? \TDL\Support\Etikettmallar::STANDARD);
        }

        // Enhetskoden i läsbar form under streckkoden – då kan man skriva in
        // koden manuellt om etiketten är sliten och QR:en inte går att läsa.
        $koder = [];
        foreach ($rader as $en) {
            $koder[$en['id']] = \TDL\Support\Koder::visa($en['id']);
        }

        Vyer::fristaende('etiketter_utskrift', [
            'artikel' => $artikel,
            'rader' => $rader,
            'rulle' => $rulle,
            'koder' => $koder,
            'konto' => $konto,
            'varumarke' => \TDL\Support\Varumarke::alla(),
        ]);
    }

    public static function platsetikett(): void
    {
        $konto = Auth::kravInloggning();
        $platsId = get('plats');
        $plats = Lager::plats($platsId);
        if ($plats === null && get('kod') !== '') {
            // Delad länk: slå upp på platsens QR-kod
            foreach (Lager::platser(true) as $p) {
                if (\TDL\Support\Koder::normalisera($p['qr']) === \TDL\Support\Koder::normalisera(get('kod'))) {
                    $plats = $p;
                    break;
                }
            }
        }
        if ($plats === null) {
            // Ingen plats angiven: använd arbetsstället för passet. Det är
            // det man vill skriva ut när man klickar från startsidan.
            $plats = Arbetsstalle::aktivt();
        }
        if ($plats === null) {
            // Inget valt heller – skicka till valsidan i stället för 404.
            omdirigera('/?nasta=' . urlencode('/platsetikett'));
        }
        // Samma storleksregler som artiklarna: eget mått i adressfältet vinner,
        // annars standardrullen. ?kod= finns så att länken kan delas.
        $rulle = get('rulle');
        if ($rulle === '' || $rulle === '0') {
            $bredd = (int) get('bredd');
            $hojd = (int) get('hojd');
            $rulle = ($bredd > 0 && $hojd > 0)
                ? $bredd . 'x' . $hojd
                : \TDL\Support\Etikettmallar::STANDARD;
        }

        Vyer::fristaende('platsetikett', [
            'plats' => $plats,
            'rulle' => $rulle,
            'konto' => $konto,
            'varumarke' => \TDL\Support\Varumarke::alla(),
        ]);
    }

    /* ================================================================
     *  Skanning (allmän sida)
     * ================================================================ */

    public static function skanna(): void
    {
        $konto = Auth::kravInloggning();
        Vyer::rendera('skanna', [
            'titel' => 'Skanna',
            'konto' => $konto,
            'aktiv' => 'skanna',
        ]);
    }

    /* ================================================================
     *  Inventering
     * ================================================================ */

    public static function inventeringar(): void
    {
        $konto = Auth::kravInloggning();
        $platser = array_values(array_filter(Lager::platser(true), fn ($p) => empty($p['system'])));
        // Antal artiklar med saldo per plats – visas i listan
        $antalPerPlats = [];
        foreach ($platser as $p) {
            $antal = 0;
            foreach (Lager::artiklar(true) as $a) {
                if ((int) ($a['saldo'][$p['id']] ?? 0) > 0) {
                    $antal++;
                }
            }
            $antalPerPlats[$p['id']] = $antal;
        }
        Vyer::rendera('inventeringar', [
            'titel' => 'Inventeringar',
            'konto' => $konto,
            'aktiv' => 'inventering',
            'sessioner' => Inventering::sessionerMedOppna(100),
            'artiklar' => Lager::artiklar(true),
            'platser' => $platser,
            'antalPerPlats' => $antalPerPlats,
            'forvalt' => Arbetsstalle::forvalt(),
            'arAdmin' => ($konto['roll'] ?? '') === 'admin',
        ]);
    }

    public static function inventeringStart(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $extra = array_values(array_filter((array) ($_POST['artiklar'] ?? [])));
        $session = Inventering::startaInventering(post('plats_id'), $extra, $konto);
        flash('ok', 'Inventering startad på alla artiklar med saldo. Förväntade antal är frysta – räkna och jämför.');
        omdirigera('/inventering/' . $session['id']);
    }

    public static function inventering(string $id): void
    {
        $konto = Auth::kravInloggning();
        $session = Inventering::session($id);
        if ($session === null) {
            throw new AppFel('Inventeringen finns inte.', 404);
        }
        $plats = Lager::plats($session['plats_id']);
        $lage = Inventering::lage($session);
        // Artiklar som finns på platsen men inte i sessionen – kan läggas till
        $utanför = [];
        foreach (Lager::artiklar(true) as $a) {
            if (isset($session['artiklar'][$a['id']])) {
                continue;
            }
            $saldo = (int) ($a['saldo'][$session['plats_id']] ?? 0);
            if ($saldo > 0 || $a['sparningssatt'] === Lager::SPAR_QR) {
                $utanför[$a['id']] = $a;
            }
        }
        Vyer::rendera('inventering', [
            'titel' => 'Inventering ' . $id,
            'konto' => $konto,
            'aktiv' => 'inventering',
            'session' => $session,
            'plats' => $plats,
            'lage' => $lage,
            'utanfor' => $utanför,
            'arAdmin' => ($konto['roll'] ?? '') === 'admin',
        ]);
    }

    public static function inventeringSkanna(string $id): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $resultat = Inventering::skanna($id, post('enhetskod'), $konto);
        flash($resultat['resultat'] === 'raknad' ? 'ok' : 'info', $resultat['meddelande']);
        omdirigera('/inventering/' . $id);
    }

    public static function inventeringAntal(string $id): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $artikelId = post('artikel_id');
        $steg = (string) post('steg', '');
        if ($steg !== '' && post('antal', '') === '') {
            // Endast steg (t.ex. från en klient utan JS): räkna från nuvarande värde
            $resultat = Inventering::justeraAntal($id, $artikelId, (int) $steg, $konto);
        } else {
            $antal = (int) post('antal', '0');
            if ($steg !== '') {
                $antal = max(0, $antal + (int) $steg);
            }
            $resultat = Inventering::sattRaknatAntal($id, $artikelId, $antal, $konto);
        }
        flash('ok', 'Räknat antal sparat: ' . $resultat['rakent'] . '. Differens: ' . $resultat['differens'] . '.');
        omdirigera('/inventering/' . $id);
    }

    public static function inventeringLaggTill(string $id): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        Inventering::laggTillArtikel($id, post('artikel_id'), $konto);
        flash('ok', 'Artikeln lades till i inventeringen.');
        omdirigera('/inventering/' . $id);
    }

    public static function inventeringTaBort(string $id): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        Inventering::taBortArtikel($id, post('artikel_id'), $konto);
        flash('ok', 'Artikeln togs bort ur inventeringen.');
        omdirigera('/inventering/' . $id);
    }

    public static function inventeringAvstamning(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $valda = array_values(array_filter((array) ($_POST['artiklar'] ?? [])));
        $resultat = Inventering::stammaAv($id, post('bekraftelse'), $konto, $valda === [] ? null : $valda);
        flash('ok', 'Avstämning genomförd: ' . $resultat['artiklar'] . ' artikel(er), '
            . $resultat['justerade'] . ' enheter justerade. Allt finns i historiken.');
        omdirigera('/inventering/' . $id);
    }

    public static function inventeringAvsluta(string $id): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        Inventering::avslutaUtanAvstamning($id, $konto, post('motivering'));
        flash('ok', 'Inventeringen avslutades utan avstämning. Differensen finns kvar i historiken.');
        omdirigera('/inventering/' . $id);
    }

    public static function inventeringAnnullera(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Inventering::annullera($id, $konto, post('motivering'));
        flash('ok', 'Inventeringen annullerades.');
        omdirigera('/inventering/' . $id);
    }

    /* ================================================================
     *  Historik
     * ================================================================ */

    public static function historik(): void
    {
        $konto = Auth::kravInloggning();
        $alla = Json::las('historik')['poster'];
        $typ = get('typ');
        $artikel = get('artikel');
        if ($typ !== '') {
            $alla = array_values(array_filter($alla, fn ($h) => ($h['typ'] ?? '') === $typ));
        }
        if ($artikel !== '') {
            $alla = array_values(array_filter($alla, fn ($h) => ($h['artikel_id'] ?? '') === $artikel));
        }
        $rader = array_reverse($alla);
        $sida = max(1, (int) get('sida', '1'));
        $perSida = 50;
        $antalSidor = max(1, (int) ceil(count($rader) / $perSida));
        Vyer::rendera('historik', [
            'titel' => 'Historik',
            'konto' => $konto,
            'aktiv' => 'historik',
            'rader' => array_slice($rader, ($sida - 1) * $perSida, $perSida),
            'typ' => $typ,
            'artikelFilter' => $artikel,
            'sida' => $sida,
            'antalSidor' => $antalSidor,
            'typer' => Historik::TYPER,
            'artiklar' => Lager::artiklar(),
        ]);
    }

    /* ================================================================
     *  Platser
     * ================================================================ */

    public static function platser(): void
    {
        $konto = Auth::kravInloggning();
        $platser = Lager::platser();
        $info = [];
        foreach ($platser as $p) {
            $saldoRader = [];
            $enheter = 0;
            foreach (Lager::artiklar(true) as $a) {
                $s = Lager::saldoPerPlats($a);
                if (($s[$p['id']] ?? 0) !== 0) {
                    $saldoRader[] = ['artikel' => $a, 'antal' => $s[$p['id']]];
                }
            }
            foreach (Lager::enheter(['plats_id' => $p['id']]) as $en) {
                if (($en['status'] ?? '') === 'aktiv') {
                    $enheter++;
                }
            }
            $info[] = [
                'plats' => $p,
                'rader' => $saldoRader,
                'enheter' => $enheter,
                'oppenSession' => Inventering::oppenSessionForPlats($p['id']),
            ];
        }
        Vyer::rendera('platser', [
            'titel' => 'Arbetsställen',
            'konto' => $konto,
            'aktiv' => 'platser',
            'info' => $info,
        ]);
    }

    public static function platsSkapa(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Lager::skapaPlats(post('namn'), post('kod'), $konto);
        flash('ok', 'Arbetsstället skapades. Skriv ut och sätt upp arbetsplats-QR:en.');
        omdirigera('/platser');
    }

    public static function platsAndra(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Lager::andraPlats($id, post('namn'), post('kod'), $konto);
        flash('ok', 'Arbetsstället uppdaterades.');
        omdirigera('/platser');
    }

    public static function platsStang(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Lager::stangPlats($id, $konto);
        flash('ok', 'Arbetsstället stängdes. Ingen data raderades.');
        omdirigera('/platser');
    }

    /* ================================================================
     *  Konton
     * ================================================================ */

    public static function konton(): void
    {
        $konto = Auth::kravRoll('admin');
        Vyer::rendera('konton', [
            'titel' => 'Konton',
            'konto' => $konto,
            'aktiv' => 'konton',
            'konton' => Kontohantering::alla(),
        ]);
    }

    public static function kontoSkapa(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $namn = post('namn');
        $roll = post('roll');
        $pin = post('pin');
        if ($pin === '') {
            $pin = Kontohantering::slumpaPin();
        }
        $nytt = Kontohantering::skapa($namn, $pin, $roll, $konto);
        // PIN-koden visas i flash EN gång – finns inte i klartext i lagringen.
        flash('ok', 'Konto skapat för ' . $nytt['namn'] . '. PIN-kod: ' . $pin . ' (visas bara nu – skriv upp eller lämna till personen).');
        omdirigera('/konton');
    }

    public static function kontoRoll(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Kontohantering::bytRoll($id, post('roll'), $konto);
        flash('ok', 'Rollen uppdaterades.');
        omdirigera('/konton');
    }

    public static function kontoPin(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $pin = post('pin');
        if ($pin === '') {
            $pin = Kontohantering::slumpaPin();
        }
        Konto::andraPin($id, $pin, $konto, true, true);
        flash('ok', 'Tillfällig PIN-kod satt: ' . $pin . ' (visas bara nu). Personen måste välja en egen PIN vid inloggning.');
        omdirigera('/konton');
    }

    public static function kontoStang(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Konto::stangAv($id, $konto);
        flash('ok', 'Kontot stängdes av och kan inte längre logga in.');
        omdirigera('/konton');
    }

    public static function kontoAktivera(string $id): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Konto::aktivera($id, $konto);
        flash('varning', 'Kontot är aktivt igen, men den gamla PIN-koden fungerar inte längre. '
            . 'Sätt en ny PIN med knappen "Ny PIN" och lämna den till personen.');
        omdirigera('/konton');
    }

    public static function mittKonto(): void
    {
        $konto = Auth::kravInloggning();
        Vyer::rendera('mitt_konto', [
            'titel' => 'Mitt konto',
            'konto' => $konto,
            'aktiv' => 'mitt_konto',
        ]);
    }

    public static function mittPin(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        if (post('pin') !== post('pin2')) {
            throw new AppFel('De nya PIN-koderna stämmer inte överens.');
        }
        Konto::bytaPin($konto['id'], post('nuvarande_pin'), post('pin'));
        Auth::loggaUt('Din PIN-kod är bytt. Logga in med den nya PIN-koden.');
        omdirigera('/login');
    }
}
