<?php
/**
 * Gemensamma hjälpfunktioner för Tadega Lager.
 * Inga globala variabler, ingen output.
 */

declare(strict_types=1);

/** HTML-escape med rätt teckenkodning. */
function e(null|string|int|float $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Intern adress med basvägen framför: u('/login') → "/lager/login".
 *
 * Används i alla vyer i stället för att skriva href="/...". Appen kan då
 * ligga i en undermapp utan att en enda länk behöver ändras.
 */
function u(string $vag): string
{
    return \TDL\Support\Bas::url($vag);
}

/**
 * Adress till en statisk fil med innehållsversion.
 *
 * Appen installeras som PWA och service workern cachar skalet. Om adressen
 * är konstant (?v=1.0.0) kan en uppdaterad CSS/JS bli kvar i cachen hur
 * många gånger användaren än laddar om. Här används filens ändringstid som
 * version: när filen ändras ändras adressen, och då hämtas den nya filen
 * automatiskt – utan att någon behöver komma ihåg att bumpa en siffra.
 */
function tdl_tillgang(string $sokvag): string
{
    $fil = TDL_PUBLIC . '/' . ltrim($sokvag, '/');
    $version = is_file($fil) ? (string) filemtime($fil) : \TDL_VERSION;
    return u('/' . ltrim($sokvag, '/') . '?v=' . rawurlencode($version));
}

/** JSON-kodning med konsekventa flaggor (används även för ledger-hashar). */
function tdl_json(mixed $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/** UTC-tidsstämpel i ISO 8601. */
function nu(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** Snygg svensk tid från ISO-sträng. */
function sv_tid(?string $iso): string
{
    if (!$iso) {
        return '–';
    }
    try {
        $d = new DateTimeImmutable($iso);
        return $d->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i');
    } catch (Throwable) {
        return (string) $iso;
    }
}

/** Kort request-id för loggning (8 tecken räcker i sökning). */
function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(4));
    }
    return $id;
}

/** Läs POST-fält som sträng, trimmad. */
function post(string $nyckel, string $default = ''): string
{
    $v = $_POST[$nyckel] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Läs POST-fält som lista av strängar (t.ex. enhets-ID:n). */
function post_lista(string $nyckel): array
{
    $v = $_POST[$nyckel] ?? [];
    if (!is_array($v)) {
        return [];
    }
    $ut = [];
    foreach ($v as $rad) {
        if (is_string($rad)) {
            $rad = trim($rad);
            if ($rad !== '') {
                $ut[] = $rad;
            }
        }
    }
    return array_values(array_unique($ut));
}

/** Läs GET-fält som sträng. */
function get(string $nyckel, string $default = ''): string
{
    $v = $_GET[$nyckel] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Redirect och avsluta. Tar emot både absoluta adresser och sökvägar. */
function omdirigera(string $till): never
{
    $till = trim($till);
    if ($till === '') {
        $till = '/';
    }
    // Skydda mot rubrikinjektion och mot tomma/relativa värden som bara blir "/"
    $till = str_replace(["\r", "\n", "\0"], '', $till);
    if (!str_starts_with($till, '/') && !preg_match('#^https?://#i', $till)) {
        $till = '/' . $till;
    }
    // Basvägen: ligger appen i /lager måste Location bli /lager/login, inte
    // /login. Ett enda ställe räcker – alla 52 omdirigeringar går genom här.
    if (str_starts_with($till, '/')) {
        $till = \TDL\Support\Bas::url($till);
    }
    // Förhandsgranskningsläge: sessions-id:t nekas som cookie och måste följa
    // med i adressen ända fram till nästa sida.
    if (\TDL\Support\Auth::iRutaMiljo() && str_starts_with($till, '/')) {
        $till = \TDL\Http\Vyer::medSid($till, session_id());
    }
    header('Location: ' . $till, true, 303);
    exit;
}

/** Kort flash-meddelande (session) – visas nästa sida. */
function flash(string $typ, string $text): void
{
    $_SESSION['flash'][] = ['typ' => $typ, 'text' => $text];
}

/** Hämta och rensa flash. */
function ta_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/** Läs in hela JSON-filen eller kasta fel. */
function las_json_fil(string $sokvag): array
{
    if (!is_file($sokvag)) {
        throw new RuntimeException('Datafilen saknas: ' . basename($sokvag));
    }
    $rå = file_get_contents($sokvag);
    if ($rå === false) {
        throw new RuntimeException('Kan inte läsa: ' . basename($sokvag));
    }
    $d = json_decode($rå, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($d)) {
        throw new RuntimeException('Ogiltig struktur i ' . basename($sokvag));
    }
    return $d;
}
