<?php
declare(strict_types=1);

namespace TDL\Support;

/** Lokalt administrerade förklaringar kopplade till bestämda fält. */
final class Ordlista
{
    public const FALT = [
        'artikelnummer' => 'Artikelnummer',
        'enhet' => 'Enhet',
        'kategori' => 'Kategori',
        'miniminiva' => 'Miniminivå',
        'arbetsstalle' => 'Arbetsställe',
    ];

    public static function alla(): array
    {
        $v = Installningar::hamta('lokal_ordlista', []);
        return is_array($v) ? $v : [];
    }

    public static function hamta(string $falt): ?array
    {
        if (!isset(self::FALT[$falt])) return null;
        $ord = self::alla()[$falt] ?? null;
        if (!is_array($ord) || !is_string($ord['term'] ?? null) || !is_string($ord['forklaring'] ?? null)
            || $ord['term'] === '' || $ord['forklaring'] === '') return null;
        return $ord;
    }

    /** Validera alla rader före skrivning. Tomma par tas bort. */
    public static function validera(array $varden): array
    {
        $ut = [];
        foreach (self::FALT as $falt => $etikett) {
            $rad = $varden[$falt] ?? [];
            if (!is_array($rad) || !is_string($rad['term'] ?? '') || !is_string($rad['forklaring'] ?? '')) {
                throw new AppFel('Ogiltig ordlista för ' . $etikett . '.');
            }
            $term = trim($rad['term'] ?? '');
            $forklaring = trim($rad['forklaring'] ?? '');
            if ($term === '' && $forklaring === '') continue;
            if ($term === '' || $forklaring === '' || mb_strlen($term) > 80 || mb_strlen($forklaring) > 300
                || preg_match('/[\x00-\x1F\x7F]/', $term . $forklaring)) {
                throw new AppFel('Ange både term (högst 80 tecken) och förklaring (högst 300 tecken) för ' . $etikett . ', utan styrtecken.');
            }
            $ut[$falt] = ['term' => $term, 'forklaring' => $forklaring];
        }
        return $ut;
    }
}
