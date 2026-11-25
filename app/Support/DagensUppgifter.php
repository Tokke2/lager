<?php
/** En översikt över arbete som redan finns i lagerdata, inte en separat att-göra-lista. */
declare(strict_types=1);

namespace TDL\Support;

final class DagensUppgifter
{
    /**
     * Brister räknas som artikel/arbetsställe-rader, inte unika artiklar.
     * Vid valt arbetsställe visas bara det ställets lagerarbete. Admin utan
     * valt ställe ser alla ställen. Backup är en administrativ uppgift.
     *
     * @return list<array{text:string, href:string}>
     */
    public static function bygg(array $konto, ?array $stalle, array $sessioner, array $brister, ?array $backup, array $utlan = []): array
    {
        $platsId = $stalle['id'] ?? null;
        $oppna = array_filter($sessioner, static fn ($s) =>
            ($s['status'] ?? '') === 'öppen' && ($platsId === null || ($s['plats_id'] ?? '') === $platsId)
        );
        $brister = array_filter($brister, static fn ($r) =>
            $platsId === null || ($r['plats']['id'] ?? '') === $platsId
        );
        $uppgifter = [];
        $antal = count($oppna);
        if ($antal > 0) {
            $uppgifter[] = [
                'text' => $antal . ($antal === 1 ? ' pågående inventering att fortsätta' : ' pågående inventeringar att fortsätta'),
                'href' => '/inventering',
            ];
        }
        $antal = count($brister);
        if ($antal > 0) {
            $uppgifter[] = [
                'text' => $antal . ($antal === 1 ? ' bristrad under miniminivå' : ' bristrader under miniminivå'),
                'href' => '/miniminiva',
            ];
        }
        $forfallna = array_filter($utlan, static fn ($lan) =>
            ($platsId === null || ($lan['plats_id'] ?? '') === $platsId)
            && ($lan['forfallodatum'] ?? '') !== '' && $lan['forfallodatum'] <= gmdate('Y-m-d')
        );
        $antal = count($forfallna);
        if ($antal > 0) {
            $uppgifter[] = [
                'text' => $antal . ($antal === 1 ? ' utlåning ska lämnas tillbaka' : ' utlåningar ska lämnas tillbaka'),
                'href' => '/utlan',
            ];
        }
        if (($konto['roll'] ?? '') === 'admin' && in_array($backup['lage'] ?? '', ['saknas', 'gammal'], true)) {
            $uppgifter[] = [
                'text' => ($backup['lage'] === 'saknas' ? 'Ingen backup finns – kontrollera backup' : 'Backupen är för gammal – kontrollera backup'),
                'href' => '/admin/system',
            ];
        }
        return $uppgifter;
    }
}
