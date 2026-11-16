<?php
/**
 * HTTP-svar för JSON, text och felkoder.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Svar
{
    public static function json(mixed $data, int $kod = 200): void
    {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        echo tdl_json($data);
        exit;
    }

    public static function text(string $text, int $kod = 200): void
    {
        http_response_code($kod);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
        exit;
    }

    public static function ejFunnet(string $text = 'Sidan finns inte.'): void
    {
        http_response_code(404);
        echo $text;
        exit;
    }

    public static function nekad(string $text = 'Åtkomst nekad.'): void
    {
        http_response_code(403);
        echo $text;
        exit;
    }
}
