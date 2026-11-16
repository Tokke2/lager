<?php
/**
 * Globalt skrivlås och flerfilstransaktioner.
 *
 * Design: EN skrivare i taget (flock på storage/locks/skriv.lock). Appens
 * datamängd gör låset till en icke-fråga, men det gör "allt eller inget"
 * enkelt att garantera utan databas.
 *
 * Transaktionen är "roll-forward": journalen skrivs EFTER att allt validerats
 * och innehåller det färdiga nya innehållet. Kraschar servern mitt i skrivningen
 * kan bin/aterhamta.php därför alltid slutföra exakt samma sak – aldrig något
 * halvt eller påhittat.
 */

declare(strict_types=1);

namespace TDL\Support;

final class Las
{
    /** @var resource|null */
    private static $handtag = null;
    private static int $djup = 0;

    public static function skriv(): void
    {
        if (self::$djup > 0) {
            self::$djup++;
            return;
        }
        $fil = TDL_LOCK . '/skriv.lock';
        $fh = fopen($fil, 'c+');
        if ($fh === false) {
            throw new AppFel('Kan inte öppna skrivlåset.', 500);
        }
        @chmod($fil, 0600);
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new AppFel('Kunde inte låsa datalagringen. Försök igen.', 503);
        }
        self::$handtag = $fh;
        self::$djup = 1;
    }

    public static function slapp(): void
    {
        if (self::$djup === 0) {
            return;
        }
        self::$djup--;
        if (self::$djup === 0 && is_resource(self::$handtag)) {
            flock(self::$handtag, LOCK_UN);
            fclose(self::$handtag);
            self::$handtag = null;
        }
    }

    public static function arLast(): bool
    {
        return self::$djup > 0;
    }

    public static function under(callable $fn): mixed
    {
        self::skriv();
        try {
            return $fn();
        } finally {
            self::slapp();
        }
    }

    /** Journalfiler som ligger kvar efter en avbruten skrivning. */
    public static function oppnaJournaler(): array
    {
        return array_values(glob(TDL_JOURNAL . '/*.json') ?: []);
    }
}

/**
 * En flerfilstransaktion.
 *
 *   $tx = new Transaktion('overforing', $konto);
 *   $artiklar = $tx->fil('artiklar');
 *   ... ändra i minnet ...
 *   $tx->sattFil('artiklar', $artiklar);
 *   $tx->historik(['typ' => 'overforing', ...]);
 *   $tx->spara();
 */
final class Transaktion
{
    /** @var array<string, array> */
    private array $filer = [];
    /** @var list<array> */
    private array $historikPoster = [];
    private string $txid;

    public function __construct(
        public readonly string $typ,
        public readonly ?array $anvandare = null,
    ) {
        $this->txid = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    public function txid(): string
    {
        return $this->txid;
    }

    public function fil(string $nyckel): array
    {
        if (!isset($this->filer[$nyckel])) {
            $this->filer[$nyckel] = Json::las($nyckel);
        }
        return $this->filer[$nyckel];
    }

    public function sattFil(string $nyckel, array $innehall): void
    {
        $this->filer[$nyckel] = $innehall;
    }

    public function historik(array $post): void
    {
        $this->historikPoster[] = $post;
    }

    /**
     * Genomför transaktionen. Kräver att Las::skriv() hålls.
     *
     * 1. journal (nytt innehåll + färdiga historikposter) skrivs atomiskt
     * 2. alla datafiler skrivs atomiskt
     * 3. historiken appendas (hoppas över om txid redan finns)
     * 4. journalen raderas
     */
    public function spara(): void
    {
        if (!Las::arLast()) {
            throw new \LogicException('Transaktion::spara() kräver att skrivlåset hålls.');
        }
        if ($this->filer === [] && $this->historikPoster === []) {
            return;
        }

        $nyaFiler = [];
        foreach ($this->filer as $nyckel => $innehall) {
            $innehall['version'] = $innehall['version'] ?? 1;
            $innehall['fil'] = $nyckel;
            $nyaFiler[$nyckel] = $innehall;
        }

        $journal = [
            'txid' => $this->txid,
            'typ' => $this->typ,
            'skapad' => nu(),
            'pid' => getmypid(),
            'nya_filer' => $nyaFiler,
            'historik_poster' => array_map(fn ($p) => $p, $this->historikPoster),
            'anvandare' => $this->anvandare === null ? null : [
                'id' => $this->anvandare['id'] ?? null,
                'namn' => $this->anvandare['namn'] ?? null,
            ],
        ];

        $journalSokvag = TDL_JOURNAL . '/' . $this->txid . '.json';
        Json::skrivAtomiskt($journalSokvag, tdl_json($journal));

        try {
            foreach ($nyaFiler as $nyckel => $innehall) {
                Json::skriv($nyckel, $innehall);
            }
            if ($this->historikPoster !== []) {
                $historik = Json::las('historik');
                if (!Historik::txidFinns($historik, $this->txid)) {
                    Historik::appenda($historik, $this->historikPoster, $this->anvandare, $this->txid);
                    Json::skriv('historik', $historik);
                }
            }
        } catch (\Throwable $e) {
            throw new AppFel(
                'Lagringen avbröts: ' . $e->getMessage() . '. Journalen ligger kvar — kör bin/aterhamta.php innan appen används igen.',
                500
            );
        }

        @unlink($journalSokvag);
    }
}
