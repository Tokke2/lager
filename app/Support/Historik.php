<?php
/**
 * Append-only historik med hash-kedja.
 *
 * Varje post bär prev_hash (föregående posts hash, eller "GENESIS") och sin
 * egen hash över (prev_hash | kanonisk JSON av posten utan "hash").
 * Ingen funktion i appen ändrar eller raderar en post. bin/verifiera-historik.php
 * kontrollerar hela kedjan.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Historik
{
    public const GENESIS = 'GENESIS';

    /** Tillåtna händelsenamn (fast lista – okända avvisas). */
    public const TYPER = [
        'installation',
        'migrering_import',
        'konto_skapad', 'konto_andrad', 'konto_avstangd', 'losenord_andrat', 'inloggning_nekad',
        'plats_skapad', 'plats_andrad', 'plats_stangd',
        'artikel_skapad', 'artikel_andrad', 'artikel_inaktiverad',
        'tavla_del_flyttad',
        'zon_skapad', 'zon_andrad', 'hyllplatser_skapade',
        'lokalplan_bild', 'lokalplan_rutor', 'lokalplan_ruta_bort', 'lokalplan_bort',
        'lokalplan_vaning_skapad', 'lokalplan_vaning_andrad', 'lokalplan_vaning_bort',
        'lokalplan_foto', 'lokalplan_foto_bort', 'lokalplan_klart',
        'etikett_skapad', 'etikett_annullerad',
        'inleverans', 'uttag', 'overforing', 'plocklista',
        'enhet_aktiverad', 'enhet_utgang',
        'inventering_start', 'inventering_avslut',
        'avstamning_uttag', 'avstamning_aktiv',
        'installningar_andrade', 'backup_skyddad', 'arkiv_skapad',
    ];

    /** En post utan hash beräknas härifrån. */
    private static function hashAv(array $post): string
    {
        unset($post['hash']);
        return hash('sha256', ($post['prev_hash'] ?? self::GENESIS) . '|' . tdl_json($post));
    }

    /** Finns redan en post med txid? (idempotens vid återhämtning) */
    public static function txidFinns(array $historikFil, string $txid): bool
    {
        foreach ($historikFil['poster'] as $post) {
            if (($post['txid'] ?? '') === $txid) {
                return true;
            }
        }
        return false;
    }

    public static function sistaHash(array $historikFil): string
    {
        $n = count($historikFil['poster']);
        return $n === 0 ? self::GENESIS : (string) $historikFil['poster'][$n - 1]['hash'];
    }

    /**
     * Append:a poster till en historikfil (i minnet). Skrivningen görs av anroparen.
     * @param list<array> $poster
     */
    public static function appenda(array &$historikFil, array $poster, ?array $anvandare, string $txid): void
    {
        $prev = self::sistaHash($historikFil);
        $seq = count($historikFil['poster']);
        foreach ($poster as $post) {
            if (!in_array($post['typ'] ?? '', self::TYPER, true)) {
                throw new \InvalidArgumentException('Otillåten historiktyp: ' . ($post['typ'] ?? '(tom)'));
            }
            $seq++;
            $post['seq'] = $seq;
            $post['id'] = sprintf('H-%07d', $seq);
            $post['tid'] = $post['tid'] ?? nu();
            $post['ansvarig_id'] = $post['ansvarig_id'] ?? ($anvandare['id'] ?? null);
            $post['ansvarig_namn'] = $post['ansvarig_namn'] ?? ($anvandare['namn'] ?? 'system');
            $post['txid'] = $txid;
            $post['request_id'] = ($post['request_id'] ?? null) ?? (PHP_SAPI === 'cli' ? 'cli' : request_id());
            $post['prev_hash'] = $prev;
            $post['hash'] = self::hashAv($post);
            $prev = $post['hash'];
            $historikFil['poster'][] = $post;
        }
    }

    /**
     * Verifiera hela kedjan. Returnerar lista med fel (tom = frisk).
     * @return list<string>
     */
    public static function verifiera(array $historikFil): array
    {
        $fel = [];
        $prev = self::GENESIS;
        foreach ($historikFil['poster'] as $i => $post) {
            $nr = $post['seq'] ?? ($i + 1);
            if (($post['prev_hash'] ?? '') !== $prev) {
                $fel[] = "Post H-" . sprintf('%07d', $nr) . ": prev_hash stämmer inte med föregående post.";
            }
            if (($post['hash'] ?? '') !== self::hashAv($post)) {
                $fel[] = "Post H-" . sprintf('%07d', $nr) . ": innehållet har ändrats efter skrivning (hash stämmer inte).";
            }
            $prev = (string) ($post['hash'] ?? '');
        }
        return $fel;
    }
}
