<?php
/**
 * CSRF-skydd och säkerhetsheaders.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Sakerhet
{
    /** CSRF-token per session. */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Kontrollera CSRF-token från POST-fältet _csrf. */
    public static function kravCsrf(): void
    {
        $skickad = $_POST['_csrf'] ?? '';
        if (!is_string($skickad) || !hash_equals(self::csrfToken(), $skickad)) {
            Svar::nekad('Ogiltig CSRF-token. Ladda om sidan och försök igen.');
        }
    }

    /** Säkerhetsheaders för alla sidor. */
    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');

        // Inbäddning: produktion nekar allt (X-Frame-Options: DENY). Endast i
        // lokal utveckling tillåts ramen, så att appen kan förhandsgranskas –
        // annars går den inte att testa live. Styrs av TDL_MILJO i bootstrap.
        if (\defined('TDL_MILJO') && \TDL_MILJO === 'utveckling') {
            // Tillåt inbäddning från vilken värd som helst under utveckling:
            // förhandsgranskningen kan komma från olika domäner (localhost,
            // *.e2b.app, *.arena.ai). Skyddet mot klickkapning gäller i skarp drift.
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors *");
            return;
        }

        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }
}
