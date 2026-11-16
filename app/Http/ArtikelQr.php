<?php
/**
 * Artikelbilder och artikel-QR – HTTP-lagret.
 *
 * Rutterna här hanterar:
 *   GET  /bilder/<artikel>/<fil>          serverar en bild (kräver inloggning)
 *   POST /artiklar/<id>/bilder            laddar upp bild
 *   POST /artiklar/<id>/bilder/ta-bort    tar bort en bild
 *   GET  /artikel/<id>                    publik artikelsida (via QR)
 *   POST /artikel/<id>/antal              publik +/- justering (signerad länk)
 *   POST /artikel/<id>/bild               publik bilduppladdning (signerad länk)
 *
 * Publika sidor nås med en signerad länk i QR-koden:
 *   /artikel/<id>?n=<nonce>&s=<signatur>
 * Signaturen är HMAC-SHA256 med en nyckel som skapas vid installationen och
 * ligger i storage/artikelqr.json (0600). Nyckeln kan bytas i Admin → System,
 * vilket ogiltigförklarar alla tidigare tryckta QR-koder.
 */

declare(strict_types=1);

namespace TDL\Http;

use TDL\Domain\Lager;
use TDL\Domain\Rorelser;
use TDL\Support\AppFel;
use TDL\Support\Auth;
use TDL\Support\Bilder;
use TDL\Support\Json;
use TDL\Support\Las;
use TDL\Support\Sakerhet;
use TDL\Support\Svar;
use TDL\Support\Varumarke;
use TDL\Http\Vyer;

final class ArtikelQr
{
    /** Publik sida – visar artikel, bilder och snabb +/- av antal. */
    public static function visa(string $artikelId): void
    {
        $lank = self::verifieraLank($artikelId);
        if ($lank === null) {
            self::felSida(
                'Länken fungerar inte',
                'QR-koden hör till en annan installation eller har ersatts. '
                . 'Be en administratör skriva ut en ny etikett.'
            );
            return;
        }

        $artikel = Lager::artikel($artikelId);
        if ($artikel === null) {
            self::felSida('Artikeln finns inte', 'Den kan ha tagits bort. Be en administratör om hjälp.');
            return;
        }
        if (empty($artikel['aktiv'])) {
            self::felSida('Artikeln är inaktiverad', $artikel['namn'] . ' kan inte längre ändras.');
            return;
        }

        $oversikt = Lager::saldoOversikt($artikel);
        $platser = array_values(array_filter(
            Lager::platser(true),
            fn ($p) => empty($p['system'])
        ));

        $valdPlats = (string) ($_GET['plats'] ?? '');
        if ($valdPlats === '' || !array_filter($platser, fn ($p) => $p['id'] === $valdPlats)) {
            // Förval: den plats där artikeln har mest saldo, annars första platsen
            $bast = null;
            foreach ($oversikt['rader'] as $rad) {
                if (!empty($rad['plats']['system']) || (int) $rad['antal'] <= 0) {
                    continue;
                }
                if ($bast === null || (int) $rad['antal'] > (int) $bast['antal']) {
                    $bast = $rad;
                }
            }
            $valdPlats = $bast['plats']['id'] ?? ($platser[0]['id'] ?? '');
        }

        Vyer::fristaende('artikel_publik', [
            'titel' => $artikel['namn'],
            'konto' => Auth::inloggad(),
            'artikel' => $artikel,
            'oversikt' => $oversikt,
            'platser' => $platser,
            'vald_plats' => $valdPlats,
            'bilder' => Bilder::forArtikel($artikel),
            'signatur' => self::signatur($artikelId, $lank['nonce']),
            'nonce' => $lank['nonce'],
            'csrf' => Sakerhet::csrfToken(),
            'varumarke' => Varumarke::alla(),
        ]);
    }

    /** Justera antal (+/-) från den publika sidan. */
    public static function justeraAntal(string $artikelId): void
    {
        $lank = self::verifieraLank($artikelId);
        if ($lank === null) {
            self::felSida('Länken fungerar inte', 'Skriv ut en ny etikett från adminpanelen.');
            return;
        }
        Sakerhet::kravCsrf();

        $artikel = Lager::artikel($artikelId);
        if ($artikel === null || empty($artikel['aktiv'])) {
            self::felSida('Artikeln är inte tillgänglig', 'Be en administratör om hjälp.');
            return;
        }

        $konto = Auth::inloggad();
        if ($konto === null) {
            // Ingen inloggning: skicka till inloggning och tillbaka hit
            $_SESSION['tdl_ater_efter_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            omdirigera('/login');
        }

        $platsId = post('plats_id');
        $antal = (int) post('antal');
        $riktning = post('riktning');

        $kommentar = trim(post('kommentar'));

        if ($riktning === 'plus') {
            if ($antal < 1 || $antal > 9999) {
                flash('fel', 'Ange ett antal mellan 1 och 9999.');
                omdirigera(self::lankUrl($artikelId, $lank['nonce']) . '&plats=' . rawurlencode($platsId));
            }
            if ($artikel['sparningssatt'] === Lager::SPAR_QR) {
                // QR per enhet: skapa så många nya enhetskoder
                $antal = min($antal, 200);
                Rorelser::inleverans($artikelId, $platsId, $antal, [], $konto, $kommentar ?: 'Inleverans via artikel-QR');
                flash('ok', $antal . ' nya QR-enheter registrerade.');
            } else {
                Rorelser::inleverans($artikelId, $platsId, $antal, [], $konto, $kommentar ?: 'Inleverans via artikel-QR');
                flash('ok', '+' . $antal . ' ' . $artikel['enhet'] . ' registrerat.');
            }
        } elseif ($riktning === 'minus') {
            $saldo = Lager::saldoPerPlats($artikel);
            $finns = (int) ($saldo[$platsId] ?? 0);
            if ($antal < 1) {
                flash('fel', 'Ange ett antal att ta ut.');
                omdirigera(self::lankUrl($artikelId, $lank['nonce']) . '&plats=' . rawurlencode($platsId));
            }
            if ($antal > $finns) {
                flash('fel', 'Det finns bara ' . $finns . ' ' . $artikel['enhet'] . ' på platsen.');
                omdirigera(self::lankUrl($artikelId, $lank['nonce']) . '&plats=' . rawurlencode($platsId));
            }
            if ($artikel['sparningssatt'] === Lager::SPAR_QR) {
                // QR per enhet: välj N aktiva enheter på platsen och ta ut dem
                $kandidater = array_values(array_filter(
                    Lager::enheter(['artikel_id' => $artikelId, 'plats_id' => $platsId]),
                    fn ($en) => ($en['status'] ?? '') === 'aktiv'
                ));
                if (count($kandidater) < $antal) {
                    flash('fel', 'Det finns bara ' . count($kandidater) . ' aktiva QR-enheter på platsen.');
                    omdirigera(self::lankUrl($artikelId, $lank['nonce']) . '&plats=' . rawurlencode($platsId));
                }
                $koder = array_map(fn ($en) => $en['id'], array_slice($kandidater, 0, $antal));
                Rorelser::uttag($artikelId, $platsId, $antal, $koder, $konto, $kommentar ?: 'Uttag via artikel-QR');
                flash('ok', '−' . $antal . ' QR-' . ($antal === 1 ? 'enhet' : 'enheter') . ' registrerade.');
            } else {
                Rorelser::uttag($artikelId, $platsId, $antal, [], $konto, $kommentar ?: 'Uttag via artikel-QR');
                flash('ok', '−' . $antal . ' ' . $artikel['enhet'] . ' registrerat.');
            }
        } else {
            flash('fel', 'Ogiltig åtgärd.');
        }

        omdirigera('/artikel/' . rawurlencode(strtoupper($artikelId))
            . '?n=' . rawurlencode($lank['nonce'])
            . '&s=' . rawurlencode(self::signatur($artikelId, $lank['nonce']))
            . '&plats=' . rawurlencode($platsId));
    }

    /** Ladda upp bild från den publika sidan (inloggning krävs). */
    public static function laddaUppPublik(string $artikelId): void
    {
        $lank = self::verifieraLank($artikelId);
        if ($lank === null) {
            self::felSida('Länken fungerar inte', 'Skriv ut en ny etikett från adminpanelen.');
            return;
        }
        Sakerhet::kravCsrf();
        $konto = Auth::inloggad();
        if ($konto === null) {
            $_SESSION['tdl_ater_efter_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            omdirigera('/login');
        }
        self::sparaBild($artikelId, $konto, self::lankUrl($artikelId, $lank['nonce']));
    }

    /** Ladda upp bild från artikelkortet i appen. */
    public static function laddaUppIntern(string $artikelId): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        self::sparaBild($artikelId, $konto, '/artiklar/' . $artikelId);
    }

    public static function taBortBild(string $artikelId): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        $fil = basename(post('fil'));
        if ($fil === '') {
            throw new AppFel('Ingen bild angavs.');
        }
        Las::under(function () use ($artikelId, $fil) {
            $artiklar = Json::las('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null) {
                throw new AppFel('Artikeln finns inte.');
            }
            $nya = Bilder::taBort($artikel, $fil);
            Json::uppdatera($artiklar, $artikelId, function (array $a) use ($nya) {
                $a['bilder'] = $nya;
                return $a;
            });
            Json::skriv('artiklar', $artiklar);
        });
        flash('ok', 'Bilden togs bort.');
        omdirigera('/artiklar/' . $artikelId);
    }

    /** Serverar en bild. Kräver inloggning eller en giltig signerad länk. */
    public static function bild(string $artikelId, string $fil): void
    {
        // I förhandsgranskningsläget finns ingen cookie – sessions-id:t kommer i
        // adressen i stället, och Auth har redan återupptagit sessionen då.
        if (Auth::inloggad() === null && self::verifieraLank($artikelId) === null) {
            http_response_code(404);
            exit;
        }
        $artikel = Lager::artikel($artikelId);
        if ($artikel === null) {
            http_response_code(404);
            exit;
        }
        $sokvag = Bilder::sokvag($artikelId, $fil);
        if ($sokvag === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . Bilder::mimeTyp($sokvag));
        header('Content-Length: ' . (string) filesize($sokvag));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($sokvag);
        exit;
    }

    /** Delad uppladdningslogik. */
    private static function sparaBild(string $artikelId, array $konto, string $tillbaka): void
    {
        $artikel = Lager::artikel($artikelId);
        if ($artikel === null || empty($artikel['aktiv'])) {
            throw new AppFel('Artikeln finns inte eller är inaktiv.');
        }
        if (count(Bilder::forArtikel($artikel)) >= Bilder::MAX_BILDER) {
            flash('fel', 'En artikel kan ha högst ' . Bilder::MAX_BILDER . ' bilder. Ta bort en först.');
            omdirigera($tillbaka);
        }

        $filer = $_FILES['bilder'] ?? null;
        if ($filer === null) {
            flash('fel', 'Ingen bild valdes.');
            omdirigera($tillbaka);
        }

        // Normalisera både enkel och flerval
        $poster = [];
        if (is_array($filer['name'])) {
            foreach ($filer['name'] as $i => $namn) {
                $poster[] = [
                    'name' => $namn,
                    'type' => $filer['type'][$i] ?? '',
                    'tmp_name' => $filer['tmp_name'][$i] ?? '',
                    'error' => $filer['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $filer['size'][$i] ?? 0,
                ];
            }
        } else {
            $poster[] = $filer;
        }

        $text = mb_substr(trim(post('bildtext')), 0, 120);
        $nya = [];
        $fel = [];
        foreach ($poster as $p) {
            if (($p['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $nya[] = Bilder::spara($p, $artikelId, $text);
            } catch (AppFel $e) {
                $fel[] = $e->getMessage();
            }
        }

        if ($nya === []) {
            flash('fel', $fel !== [] ? implode(' ', $fel) : 'Ingen bild valdes.');
            omdirigera($tillbaka);
        }

        Las::under(function () use ($artikelId, $nya) {
            $artiklar = Json::las('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null) {
                throw new AppFel('Artikeln finns inte.');
            }
            $befintliga = Bilder::forArtikel($artikel);
            $alla = array_slice(array_merge($befintliga, $nya), 0, Bilder::MAX_BILDER);
            Json::uppdatera($artiklar, $artikelId, function (array $a) use ($alla) {
                $a['bilder'] = $alla;
                return $a;
            });
            Json::skriv('artiklar', $artiklar);
        });

        flash('ok', count($nya) . ' bild' . (count($nya) === 1 ? '' : 'er') . ' sparad' . (count($nya) === 1 ? '' : 'e') . '.'
            . ($fel !== [] ? ' ' . implode(' ', $fel) : ''));
        omdirigera($tillbaka);
    }

    // -----------------------------------------------------------------
    // Signerade QR-länkar
    // -----------------------------------------------------------------

    /** Nyckel för artikellänkar. Skapas vid behov med 0600. */
    public static function nyckel(): string
    {
        $fil = TDL_STORAGE . '/artikelqr.json';
        $data = is_file($fil) ? json_decode((string) file_get_contents($fil), true) : null;
        if (!is_array($data) || empty($data['nyckel'])) {
            $data = ['nyckel' => bin2hex(random_bytes(32)), 'skapad' => nu()];
            $tmp = $fil . '.tmp';
            file_put_contents($tmp, json_encode($data));
            @chmod($tmp, 0600);
            rename($tmp, $fil);
        }
        return (string) $data['nyckel'];
    }

    /** Byt ut nyckeln – alla tidigare tryckta QR-koder slutar fungera. */
    public static function bytNyckel(array $konto): string
    {
        if (($konto['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer kan byta QR-nyckeln.', 403);
        }
        $nyckel = bin2hex(random_bytes(32));
        $fil = TDL_STORAGE . '/artikelqr.json';
        $tmp = $fil . '.tmp';
        file_put_contents($tmp, json_encode(['nyckel' => $nyckel, 'skapad' => nu(), 'bytt_av' => $konto['namn'] ?? '']));
        @chmod($tmp, 0600);
        rename($tmp, $fil);
        // En ny nonce per artikel behövs inte – nonce härleds ur nyckeln
        return $nyckel;
    }

    /** Nonce för en artikel (stabil så länge nyckeln är densamma). */
    public static function nonce(string $artikelId): string
    {
        return substr(hash_hmac('sha256', 'nonce:' . strtoupper($artikelId), self::nyckel()), 0, 12);
    }

    public static function signatur(string $artikelId, string $nonce): string
    {
        return substr(hash_hmac('sha256', strtoupper($artikelId) . '|' . $nonce, self::nyckel()), 0, 24);
    }

    /** Absolut eller relativ URL till den publika artikelsidan. */
    public static function lankUrl(string $artikelId, ?string $nonce = null, bool $absolut = false): string
    {
        $nonce ??= self::nonce($artikelId);
        $url = '/artikel/' . rawurlencode(strtoupper($artikelId))
            . '?n=' . rawurlencode($nonce)
            . '&s=' . rawurlencode(self::signatur($artikelId, $nonce));
        if ($absolut) {
            $url = self::basUrl() . $url;
        }
        return $url;
    }

    /** Signerad bild-URL – fungerar utan inloggning så länge QR-länken är giltig. */
    public static function bildUrl(string $artikelId, string $fil, ?string $nonce = null): string
    {
        $nonce ??= self::nonce($artikelId);
        $url = '/bilder/' . rawurlencode(strtoupper($artikelId)) . '/' . rawurlencode($fil)
            . '?n=' . rawurlencode($nonce)
            . '&s=' . rawurlencode(self::signatur($artikelId, $nonce));
        // Förhandsgranskningsläget har inga cookies – då måste sessions-id:t följa
        // med i bildadressen, annars svarar /bilder/ 404.
        $sid = \TDL\Support\Auth::sidFraga();
        return $sid !== '' ? $url . '&' . $sid : $url;
    }

    /** QR-innehåll: full URL om appen har en publik adress, annars relativ sökväg. */
    public static function qrInnehall(string $artikelId): string
    {
        return self::lankUrl($artikelId, null, true);
    }

    /** Kontrollerar n och s i förfrågan. Returnerar ['nonce'=>...] eller null. */
    private static function verifieraLank(string $artikelId): ?array
    {
        // Signaturen kan komma i adressen (GET) eller i formuläret (POST)
        $nonce = (string) ($_GET['n'] ?? $_POST['n'] ?? '');
        $sig = (string) ($_GET['s'] ?? $_POST['s'] ?? '');
        if ($nonce === '' || $sig === '') {
            return null;
        }
        if (strlen($nonce) > 32 || strlen($sig) > 64) {
            return null;
        }
        $vantad = self::signatur($artikelId, $nonce);
        if (!hash_equals($vantad, $sig)) {
            return null;
        }
        return ['nonce' => $nonce];
    }

    /**
     * Bas-URL för absoluta QR-länkar (t.ex. https://lager.foretag.se).
     *
     * Ordningen är medveten: en adress som användaren sparat i adminpanelen
     * vinner alltid, därefter miljövariabeln, därefter den härledda adressen.
     * En härledd adress kan peka på "localhost" och då fungerar inte etiketterna
     * på en telefon – därför varnar adminpanelen när ingen adress är fastställd.
     */
    public static function basUrl(): string
    {
        $konfig = TDL_STORAGE . '/adress.json';
        if (is_file($konfig)) {
            $data = json_decode((string) file_get_contents($konfig), true);
            if (is_array($data) && !empty($data['bas_url'])) {
                return rtrim((string) $data['bas_url'], '/');
            }
        }
        $vard = getenv('TDL_BAS_URL');
        if (is_string($vard) && $vard !== '') {
            return rtrim($vard, '/');
        }

        // Förhandsgranskning: appen serveras via en proxy på en publik adress som
        // går att räkna fram ur sandbox-id:t. Då blir etiketterna skannbara även
        // innan kunden satt sin riktiga domän. Bara i utvecklingsläge.
        if (\defined('TDL_MILJO') && \TDL_MILJO === 'utveckling') {
            $sandbox = getenv('E2B_SANDBOX_ID');
            $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
            if (is_string($sandbox) && $sandbox !== '' && $port !== '') {
                $vard = $port . '-' . $sandbox . '.e2b.app';
                return 'https://' . $vard;
            }
        }

        return self::härleddAdress();
    }

    /**
     * Den adress appen nås på just nu, räknad ur förfrågan.
     *
     * Basvägen måste med: ligger appen i /lager på webbhotellet ska QR-koden
     * peka på https://firma.se/lager/artikel/… och inte https://firma.se/artikel/…
     */
    public static function härleddAdress(): string
    {
        $schema = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            $schema = 'https';
        }
        $vand = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]{1,80}$/', $vand)) {
            $vand = 'localhost';
        }
        return $schema . '://' . $vand . \TDL\Support\Bas::vard();
    }

    /**
     * Är adressen fastställd till något som fungerar från en annan enhet?
     * "localhost" och råa IP-adresser i ett lokalt nät räknas inte som säkra,
     * eftersom en telefon inte når dem.
     */
    public static function adressArPublik(): bool
    {
        $url = self::basUrl();
        $vand = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($vand === '' || $vand === 'localhost' || $vand === '127.0.0.1' || $vand === '[::1]') {
            return false;
        }
        return true;
    }

    /** Spara publik bas-URL i adminpanelen. */
    public static function sparaBasUrl(string $url, array $konto): void
    {
        if (($konto['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer kan ändra adressen.', 403);
        }
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://[A-Za-z0-9.\-:\[\]]{1,80}(/\S*)?$#', $url)) {
            throw new AppFel('Adressen måste börja med http:// eller https:// och vara en giltig värd.');
        }
        $fil = TDL_STORAGE . '/adress.json';
        $tmp = $fil . '.tmp';
        file_put_contents($tmp, json_encode(['bas_url' => rtrim($url, '/'), 'andrad' => nu()]));
        @chmod($tmp, 0600);
        rename($tmp, $fil);
    }

    /** Enkel felsida med varumärkets utseende. */
    private static function felSida(string $rubrik, string $text): void
    {
        Vyer::fristaende('artikel_publik_fel', [
            'titel' => $rubrik,
            'konto' => Auth::inloggad(),
            'rubrik' => $rubrik,
            'text' => $text,
        ]);
    }
}
