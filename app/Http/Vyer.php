<?php
/**
 * Enkel vy-rendering: extraherar data till variabler och laddar layout + vy.
 */

declare(strict_types=1);

namespace TDL\Http;

final class Vyer
{
    public static function rendera(string $vy, array $data = []): void
    {
        // Neutral reservtitel – kundens namn sätts i adminpanelen och syns i
        // sidfoten. Produktnamnet "Tadega Lager" hör till paketet, inte till
        // kundens installation, och ska därför inte stå i gränssnittet.
        $data['titel'] = $data['titel'] ?? 'Lager';
        $data['konto'] = $data['konto'] ?? null;
        $data['aktiv'] = $data['aktiv'] ?? '';
        // Varumärket behövs i sidfoten och på utskrifterna
        $data['varumarke'] = $data['varumarke'] ?? \TDL\Support\Varumarke::alla();

        // I förhandsgranskningsläge (inbäddad ram) nekas cookies, så
        // sessions-id:t måste följa med i adressen. Öppnas en buffert så att
        // alla <form action> och interna länkar kan kompletteras på ett ställe.

        $vyFil = TDL_APP . '/views/' . $vy . '.php';
        if (!is_file($vyFil)) {
            throw new \RuntimeException('Vyn saknas: ' . $vy);
        }

        extract($data, EXTR_SKIP);
        $flash = ta_flash();

        require TDL_APP . '/views/layout_topp.php';
        require $vyFil;
        require TDL_APP . '/views/layout_botten.php';
    }

    /** Sätt exakt ett sessions-id i en intern adress (rensar gamla först). */
    public static function medSid(string $url, string $sid): string
    {
        $delar = explode('?', $url, 2);
        $parametrar = [];
        if (isset($delar[1]) && $delar[1] !== '') {
            foreach (explode('&', $delar[1]) as $p) {
                if ($p !== '' && !str_starts_with($p, 'tdlagersid=')) {
                    $parametrar[] = $p;
                }
            }
        }
        $parametrar[] = 'tdlagersid=' . rawurlencode($sid);

        return $delar[0] . '?' . implode('&', $parametrar);
    }

    /** Fristående sida (utskrift) utan layout. */
    public static function fristaende(string $vy, array $data = []): void
    {
        $vyFil = TDL_APP . '/views/' . $vy . '.php';
        if (!is_file($vyFil)) {
            throw new \RuntimeException('Vyn saknas: ' . $vy);
        }
        extract($data, EXTR_SKIP);
        require $vyFil;
    }
}
