<?php
/**
 * AppFel – förväntade fel som visas för användaren.
 * Kastas av domänlagret när en åtgärd inte får genomföras.
 */

declare(strict_types=1);

namespace TDL\Support;

class AppFel extends \RuntimeException
{
    public function __construct(string $meddelande, public readonly int $httpKod = 400)
    {
        parent::__construct($meddelande);
    }
}
