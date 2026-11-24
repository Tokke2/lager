<?php
/**
 * Installation och inloggning.
 *
 * /installera  – engångsflöde för första administratören. Fungerar bara så
 *                länge användarfilen saknar konton. Kräver tokens från
 *                setup.token (skapas av bin/installera.php eller visas av
 *                den här sidan när ingen admin finns och appen körs lokalt).
 * /login       – välj namn, ange PIN.
 */

declare(strict_types=1);

namespace TDL\Http;

use TDL\Domain\Lager;
use TDL\Support\AppFel;
use TDL\Support\Auth;
use TDL\Support\Konto;
use TDL\Support\Sakerhet;
use TDL\Support\Json;
use TDL\Support\Varumarke;

final class Installation
{
    public static function form(): void
    {
        if (!Konto::behoverForstaAdmin()) {
            omdirigera('/login');
        }
        $tokenInfo = self::tokenlage();
        // Skrivbordsappen körs lokalt på användarens egen dator: token behöver inte kopieras.
        $tokenInfo['lokal'] = getenv('TDL_SKRIVBORD') === '1';
        Vyer::rendera('installera', [
            'titel' => 'Installera',
            'konto' => null,
            'token' => $tokenInfo,
        ]);
    }

    /**
     * Har installationstoken skickats med? Token skrivs av installatören
     * (bin/installera.php) eller skapas automatiskt i storage/setup.token.
     * Den visas ALDRIG på sidan – annars vore den ingen spärr: vem som helst
     * som hittade /installera först hade kunnat ta över appen.
     */
    private static function tokenStammer(): bool
    {
        if (getenv('TDL_SKRIVBORD') === '1') {
            return true;
        }
        $fil = TDL_STORAGE . '/setup.token';
        $token = is_file($fil) ? trim((string) file_get_contents($fil)) : '';
        if ($token === '') {
            return false;
        }
        $angivet = trim((string) post('token'));
        return $angivet !== '' && hash_equals($token, $angivet);
    }

    /** Skapar eller läser engångstoken för installationen. */
    private static function tokenlage(): array
    {
        $fil = TDL_STORAGE . '/setup.token';
        if (is_file($fil)) {
            $token = trim((string) file_get_contents($fil));
            $skapad = filemtime($fil) ?: time();
            return ['token' => $token, 'skapad' => $skapad, 'fil' => true];
        }
        // Ingen tokenfil: skapa en (endast när appen inte har konton).
        $token = bin2hex(random_bytes(16));
        file_put_contents($fil, $token);
        @chmod($fil, 0600);
        return ['token' => $token, 'skapad' => time(), 'fil' => true];
    }

    public static function skapa(): void
    {
        Sakerhet::kravCsrf();
        if (!Konto::behoverForstaAdmin()) {
            throw new AppFel('Installationen är redan genomförd.');
        }
        $tokenFil = TDL_STORAGE . '/setup.token';
        if (!self::tokenStammer()) {
            throw new AppFel(
                'Installationstoken stämmer inte. Den står i storage/setup.token på servern – '
                . 'eller skriv den själv med: php bin/installera.php --namn="Ditt namn" --pin=1234'
            );
        }
        $namn = post('namn');
        $pin = post('pin');
        if ($pin !== post('pin2')) {
            throw new AppFel('PIN-koderna stämmer inte överens.');
        }

        // Företagsnamnet sätts här – innan appen är installerad och innan
        // någon loggat in. Det är kundens eget namn och får aldrig vara
        // förvalt i releasen.
        $foretag = trim((string) post('foretag'));
        if ($foretag === '') {
            throw new AppFel('Ange företagets namn.');
        }
        if (mb_strlen($foretag) > 40) {
            throw new AppFel('Företagsnamnet får vara högst 40 tecken.');
        }

        // Datafilerna måste finnas innan kontot kan skrivas.
        Lager::initieraFiler();

        $losenord = post('losenord');
        if ($losenord !== post('losenord2')) {
            throw new AppFel('Lösenorden stämmer inte överens.');
        }
        Konto::valideraLosenord($losenord);

        // false: administratören valde PIN-koden själv i guiden – den är inte
        // tillfällig och ska inte behöva bytas direkt efter inloggning.
        $konto = Konto::skapaForstaAdmin($namn, $pin, false);
        Konto::andraLosenord($konto['id'], $losenord, '', $konto, true);

        // Namnet sparas efter kontot: Varumarke::spara kräver en admin i
        // historiken, och den finns först nu.
        Varumarke::spara(['namn' => $foretag, 'kortnamn' => $foretag], $konto);

        // En standardzon så att artiklar kan få en plats direkt. Utan en zon
        // går det inte att lägga upp något alls (platsen är obligatorisk).
        // Zonen kan bytas namn på och byggas ut under Lagerplats.
        \TDL\Domain\Lagerplats::skapaZon('Lager', 'A', 5, 8, $konto);

        @unlink($tokenFil);
        // Ingen automatisk inloggning: administratören ska logga in själv med
        // den PIN och det lösenord som just valdes. Då är flödet detsamma
        // varje gång, och PIN-koden är den man själv satte – inte en
        // tillfällig som måste bytas direkt.
        flash('ok', 'Klart! ' . $foretag . ' är installerat. Logga in med din PIN-kod och ditt lösenord.');
        omdirigera('/login');
    }

    /**
     * Nollställ en gammal installation så att guiden kan köras igen.
     *
     * Behövs på webbhotell där releasen laddas upp ovanpå en tidigare
     * installation: då ligger konton och kundnamn kvar i storage/ och appen
     * visar inloggning i stället för guiden. Sidan kräver installationstoken,
     * så bara den som har åtkomst till servern kan köra den.
     */
    public static function aterstallForm(): void
    {
        if (Konto::behoverForstaAdmin()) {
            omdirigera('/installera');
        }
        Vyer::rendera('aterstall', [
            'titel' => 'Nollställ installation',
            'konto' => null,
            'antalKonton' => count(Konto::anvandare()),
        ]);
    }

    public static function aterstall(): void
    {
        Sakerhet::kravCsrf();
        if (!self::tokenStammer()) {
            throw new AppFel('Installationstoken stämmer inte. Den står i storage/setup.token.');
        }
        if (trim((string) post('bekrafta')) !== 'NOLLSTALL') {
            throw new AppFel('Skriv NOLLSTALL i bekräftelsefältet för att bekräfta.');
        }

        // Allt som hör till kundens data tas bort. Filerna skapas om tomma
        // direkt efteråt, så appen är körbar men utan innehåll.
        $bort = [
            TDL_STORAGE . '/varumarke.json',
            TDL_STORAGE . '/artikelqr.json',
            TDL_STORAGE . '/setup.token',
        ];
        foreach (\TDL\Support\Json::FILER as $fil) {
            $bort[] = TDL_DATA . '/' . $fil;
        }
        foreach ($bort as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        // Uppladdade bilder och arkiv tillhör också den gamla installationen
        foreach ([TDL_STORAGE . '/bilder', TDL_STORAGE . '/arkiv'] as $mapp) {
            if (is_dir($mapp)) {
                self::tommapp($mapp);
            }
        }
        // Inloggningarna måste bort – annars är man kvar som gammal admin
        @session_destroy();
        Lager::initieraFiler();
        flash('ok', 'Installationen är nollställd. Ange företagets namn och din PIN-kod.');
        omdirigera('/installera');
    }

    /** Tömmer en mapp utan att ta bort den. */
    private static function tommapp(string $mapp): void
    {
        $objekt = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($mapp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($objekt as $o) {
            if ($o->isDir()) {
                @rmdir($o->getPathname());
            } else {
                @unlink($o->getPathname());
            }
        }
    }

    public static function loginForm(): void
    {
        if (Auth::inloggad() !== null) {
            omdirigera('/');
        }
        $aktiva = array_values(array_filter(Konto::anvandare(), fn ($u) => !empty($u['aktiv'])));
        usort($aktiva, fn ($a, $b) => strcmp($a['namn'], $b['namn']));
        Vyer::rendera('login', [
            'titel' => 'Logga in',
            'konto' => null,
            'aktiva' => $aktiva,
        ]);
    }

    public static function login(): void
    {
        Sakerhet::kravCsrf();
        $namn = post('namn');
        $pin = post('pin');
        $losenord = post('losenord');
        if ($namn === '' || $pin === '') {
            throw new AppFel('Välj namn och ange PIN-kod.');
        }
        $konto = Konto::verifieraInloggning($namn, $pin, $losenord);
        Auth::loggaIn($konto);

        // Admin: lösenordet måste bytas var 20:e dag
        if (Konto::losenordUtgatt($konto)) {
            // Bytessidan förklarar själv läget – ingen extra flash behövs.
            omdirigera('/mitt-konto/losenord');
        }
        if (Konto::losenordSnartUtgatt($konto)) {
            $dagar = Konto::dagarKvarPaLosenord($konto);
            flash('info', 'Ditt lösenord går ut om ' . max(0, (int) $dagar) . ' dag(ar). Byt det under Mitt konto.');
        }
        if (!empty($konto['maste_byta_pin'])) {
            flash('info', 'Ditt konto har en tillfällig PIN-kod. Byt den under Mitt konto.');
        }
        omdirigera('/');
    }

    /** Sida för att sätta nytt lösenord (admin). */
    public static function losenordForm(): void
    {
        $konto = Auth::kravInloggning();
        if (($konto['roll'] ?? '') !== 'admin') {
            throw new AppFel('Lösenord används bara av administratörer.', 403);
        }
        Vyer::rendera('losenord', [
            'titel' => 'Byt lösenord',
            'konto' => $konto,
            'aktiv' => 'mer',
            'masteByta' => Konto::losenordUtgatt($konto),
            'dagarKvar' => Konto::dagarKvarPaLosenord($konto),
        ]);
    }

    public static function losenordSpara(): void
    {
        $konto = Auth::kravInloggning();
        Sakerhet::kravCsrf();
        if (($konto['roll'] ?? '') !== 'admin') {
            throw new AppFel('Lösenord används bara av administratörer.', 403);
        }
        $nytt = post('nytt');
        if ($nytt !== post('nytt2')) {
            flash('fel', 'De nya lösenorden stämmer inte överens.');
            omdirigera('/mitt-konto/losenord');
        }
        try {
            // false = bytet görs av kontot självt, alltså måste nuvarande lösenord
            // anges och verifieras. admin-flaggan är bara för administratörens
            // återställning av någon annans lösenord.
            Konto::andraLosenord($konto['id'], $nytt, post('nuvarande'), $konto, false);
        } catch (AppFel $e) {
            flash('fel', $e->getMessage());
            omdirigera('/mitt-konto/losenord');
        }
        flash('ok', 'Lösenordet är bytt. Nästa byte behövs om ' . Konto::LÖSENORD_DAGAR . ' dagar.');
        omdirigera('/');
    }

    public static function logout(): void
    {
        Auth::loggaUt();
        omdirigera('/login');
    }
}
