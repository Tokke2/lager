<?php
/**
 * Session, inloggning och rollkontroll.
 *
 * Sessionen lagras server-side i storage/sessions (utanför webbroten).
 * Vid varje request kontrolleras att kontot fortfarande finns och är aktivt –
 * en avstängning eller PIN-ändring slår därför igenom direkt på nästa klick.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Auth
{
    public const IDLE_MINUTER = 60;
    public const MAX_TIMMAR = 24;

    /**
     * Kör vi i förhandsgranskning/utveckling?
     * Då används sessions-id i adressen i stället för cookie, eftersom
     * webbläsaren nekar tredjepartscookies i en inbäddad ram.
     */
    public static function iRutaMiljo(): bool
    {
        return \defined('TDL_MILJO') && \TDL_MILJO === 'utveckling';
    }

    /**
     * Sessions-id:t som ska in i formulärets action i förhandsgranskningsläge.
     * Tom sträng i skarp drift.
     */
    public static function sidFraga(): string
    {
        return self::iRutaMiljo() ? 'tdlagersid=' . rawurlencode(session_id()) : '';
    }

    public static function startaSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (self::IDLE_MINUTER * 60));
        session_save_path(TDL_SESSION);

        if (self::iRutaMiljo()) {
            // Utveckling/förhandsgranskning: appen visas i en ram på en annan
            // värd, och då nekar webbläsaren tredjepartscookies (SameSite=None
            // kräver dessutom Secure, som inte finns över http).
            // Lösning: skicka sessions-id:t i adressen i stället för i cookien.
            // Gäller ALDRIG i skarp drift – där sitter skyddet i cookien.
            //
            // PHP:s egen omskrivning (use_trans_sid) är AVSTÄNGD: den lägger
            // bara id:t på GET-formulär, inte på POST, och dubblerade det på
            // länkar. I stället lägger Bas::url() id:t på varje intern adress
            // – då följer det med både i länkar och i alla formulär.
            ini_set('session.use_cookies', '0');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.use_only_cookies', '0');
        } else {
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.cookie_secure', $https ? '1' : '0');
        }

        session_name('tdlagersid');
        session_start();
    }

    public static function inloggad(): ?array
    {
        $id = $_SESSION['konto_id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }
        $konto = Konto::aktivAnvandare($id);
        if ($konto === null) {
            self::loggaUt();
            return null;
        }
        // Bind sessionen till den PIN-hash som faktiskt verifierades vid
        // inloggning. Äldre sessioner utan bindning får logga in på nytt.
        // PIN-byte/återställning och avstängning byter hash och upphäver då
        // alla gamla sessioner på nästa begäran, även efter återaktivering.
        $fingeravtryck = $_SESSION['pin_fingeravtryck'] ?? null;
        $pinHash = $konto['pin_hash'] ?? null;
        if (!is_string($fingeravtryck) || !is_string($pinHash) || $pinHash === ''
            || !hash_equals($fingeravtryck, hash('sha256', $pinHash))) {
            self::loggaUt('Sessionen är inte längre giltig. Logga in igen.');
            return null;
        }
        $start = (int) ($_SESSION['loggad_in'] ?? 0);
        if ($start <= 0 || (time() - $start) > self::MAX_TIMMAR * 3600) {
            self::loggaUt('Sessionen har gått ut. Logga in igen.');
            return null;
        }
        // Idle-timeout
        $senast = (int) ($_SESSION['senast_aktiv'] ?? 0);
        if ($senast > 0 && (time() - $senast) > self::IDLE_MINUTER * 60) {
            self::loggaUt('Sessionen har gått ut. Logga in igen.');
            return null;
        }
        $_SESSION['senast_aktiv'] = time();
        return $konto;
    }

    public static function kravInloggning(): array
    {
        $konto = self::inloggad();
        if ($konto === null) {
            omdirigera('/login');
        }
        return $konto;
    }

    public static function kravRoll(string $roll): array
    {
        $konto = self::kravInloggning();
        if (($konto['roll'] ?? '') !== $roll) {
            Svar::nekad('Den här åtgärden kräver rollen ' . $roll . '.');
        }
        return $konto;
    }

    public static function loggaIn(array $konto): void
    {
        session_regenerate_id(true);
        $_SESSION['konto_id'] = $konto['id'];
        $_SESSION['pin_fingeravtryck'] = hash('sha256', (string) $konto['pin_hash']);
        $_SESSION['senast_aktiv'] = time();
        $_SESSION['loggad_in'] = time();
        unset($_SESSION['csrf']);
    }

    public static function loggaUt(?string $meddelande = null): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        if ($meddelande !== null) {
            // Skapa en ny anonym session för meddelandet efter utloggningen.
            // Det gamla id:t ska inte återanvändas, och webbläsaren behöver
            // en ny kaka för att flash-meddelandet ska överleva redirecten.
            session_id('');
            session_start();
            flash('info', $meddelande);
        }
    }
}
