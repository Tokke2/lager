<?php
/**
 * Basväg – var appen ligger.
 *
 * Appen kan installeras på tre sätt:
 *
 *   1. Egen domän, dokumentrot = appens public/
 *        https://lager.firma.se/            → basväg "/"
 *
 *   2. Egen domän, dokumentrot = projektets rot (index.php i roten tar över)
 *        https://lager.firma.se/            → basväg "/"
 *
 *   3. Undermapp på en webbhotellskonto, t.ex.
 *        /home/kund/public_html/lager/
 *        https://firma.se/lager/            → basväg "/lager"
 *
 * I fall 3 fungerar inte absoluta länkar som href="/login" – de pekar på
 * firma.se/login i stället för firma.se/lager/login. Därför räknas basvägen
 * fram ur förfrågan och läggs framför varje intern adress.
 *
 * Basvägen räknas ut, den konfigureras inte: flyttar man mappen fungerar det
 * ändå, utan att någon behöver ändra en inställning.
 */
declare(strict_types=1);

namespace TDL\Support;

final class Bas
{
    /** Cachas per förfrågan – den ändras aldrig under en request. */
    private static ?string $bas = null;

    /**
     * Basvägen med inledande snedstreck och UTAN avslutande: "/" eller "/lager".
     * Aldrig tom sträng – då blir länkar "login" i stället för "/login".
     */
    public static function vard(): string
    {
        if (self::$bas !== null) {
            return self::$bas;
        }

        $bas = '';

        // 1. Explicit satt i miljön – vinner alltid. Behövs när appen ligger
        //    bakom en proxy som skriver om sökvägen (t.ex. /lager → /).
        $frånMiljö = getenv('TDL_BAS_PATH');
        if (is_string($frånMiljö) && $frånMiljö !== '') {
            $bas = $frånMiljö;
        } else {
            $bas = self::rakna();
        }

        // Normalisera: inledande snedstreck, inget avslutande, inga ".."
        $bas = '/' . trim($bas, '/');
        if ($bas === '/') {
            $bas = '';
        }
        if (str_contains($bas, '..')) {
            $bas = '';
        }

        return self::$bas = $bas;
    }

    /** Räknar fram basvägen ur förfrågan och scriptets placering. */
    private static function rakna(): string
    {
        // Den inbyggda servern (och skrivbordsappen) kör allt genom en enda
        // front controller – där finns ingen undermapp att ta hänsyn till.
        if (PHP_SAPI === 'cli-server') {
            return '';
        }

        // Kommandoraden (bin/-skripten, proven) har ingen webbadress alls.
        // SCRIPT_NAME är då scriptets sökväg på disk, t.ex. /tmp/prov.php –
        // läser vi den får vi "/tmp" som basväg och alla länkar blir "/tmp/…".
        if (PHP_SAPI === 'cli') {
            return '';
        }

        // Apache: SCRIPT_NAME är den fil som faktiskt kördes, t.ex.
        //   /lager/public/index.php   → appens rot ligger två steg upp
        //   /lager/index.php          → ett steg upp
        //   /index.php                → ingen undermapp
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && str_ends_with($script, '.php')) {
            $katalog = str_replace('\\', '/', dirname($script));
            if ($katalog === '.' || $katalog === '/') {
                return '';
            }
            // Peka på appens rot: /lager/public → /lager, /lager → /lager
            if (str_ends_with($katalog, '/public')) {
                return substr($katalog, 0, -7);
            }
            return $katalog;
        }

        // Reserv: jämför REQUEST_URI med vad som faktiskt finns på disk.
        // Fungerar hos hotell där SCRIPT_NAME är opålitligt.
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($uri !== '' && is_file(TDL_ROT . $uri)) {
            return dirname($uri) === '/' ? '' : dirname($uri);
        }

        return '';
    }

    /**
     * Gör en intern adress absolut med basvägen: "/login" → "/lager/login".
     * Adresser som redan börjar med basvägen lämnas i fred, och externa
     * adresser (http://…) rörs inte.
     *
     * I förhandsgranskningsläge (PHP:s inbyggda server, TDL_MILJO=utveckling)
     * läggs dessutom sessions-id:t på adressen. PHP:s use_trans_sid gör det
     * bara automatiskt på GET-formulär – POST-formulär tappade annars
     * sessionen och skickade användaren till inloggningen. Här blir det rätt
     * för alla länkar och formulär, oavsett metod, och utan att varje vy
     * behöver komma ihåg det.
     */
    public static function url(string $vbg): string
    {
        if ($vbg === '') {
            return self::vard() === '' ? '/' : self::vard();
        }
        // Externa adresser, data-URI:er och protokollrelativa lämnas
        if (preg_match('#^(https?:)?//#i', $vbg) || str_starts_with($vbg, 'data:')) {
            return $vbg;
        }
        return self::medMiljoFraga(self::medBas($vbg));
    }

    /** Basvägen framför en intern adress. */
    private static function medBas(string $vbg): string
    {
        $bas = self::vard();
        if ($bas === '') {
            return $vbg;
        }
        // Redan prefixad? Lämna i fred (undviker /lager/lager/login)
        if ($vbg === $bas || str_starts_with($vbg, $bas . '/') || str_starts_with($vbg, $bas . '?')) {
            return $vbg;
        }
        if (!str_starts_with($vbg, '/')) {
            return $bas . '/' . $vbg;
        }
        return $bas . $vbg;
    }

    /**
     * Sessions-id:t på adressen i förhandsgranskningsläge, annars orörd.
     * Används bara av den inbyggda webbservern under utveckling – i skarp
     * drift sitter skyddet i cookien och adressen lämnas ren.
     */
    private static function medMiljoFraga(string $adress): string
    {
        if (!\TDL\Support\Auth::iRutaMiljo()) {
            return $adress;
        }
        $id = session_id();
        if ($id === '' || str_contains($adress, 'tdlagersid=')) {
            return $adress;
        }
        // Flera ? i samma adress (t.ex. "/x?v=1?tdlagersid=…") blir feltolkad
        // frågesträng – håll kvar bara den första och gör resten till &.
        $forsta = strpos($adress, '?');
        if ($forsta !== false) {
            $fore = substr($adress, 0, $forsta);
            $fraga = str_replace('?', '&', substr($adress, $forsta + 1));
            return $fore . '?' . $fraga . '&tdlagersid=' . rawurlencode($id);
        }
        return $adress . '?tdlagersid=' . rawurlencode($id);
    }
}
