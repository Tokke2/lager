<?php
/**
 * Hälsokontroll – en samlad bild av installationens skick.
 *
 * Läser bara, skriver aldrig. Används av:
 *   – menyikonen (bara antalet allvarliga fel, cachat i sessionen)
 *   – System → "Hälsokontroll" (hela listan med råd)
 *
 * Nivåer:
 *   fel      – något är trasigt nu: appen kan inte litas på
 *   varning  – något kommer att bli ett problem (gammal backup, tunt disk)
 *   info     – värt att veta, inget att göra
 *
 * Varje kontroll returnerar ['id', 'niva', 'rubrik', 'text', 'rad'].
 */
declare(strict_types=1);

namespace TDL\Support;

use TDL\Domain\Lager;

final class HalsoKontroll
{
    public const NIVA_FEL = 'fel';
    public const NIVA_VARNING = 'varning';
    public const NIVA_OK = 'ok';

    /** Hur länge menyikonen får återanvända senaste resultatet. */
    public const CACHE_SEKUNDER = 60;

    /**
     * Kör alla kontroller.
     * @return array{niva:string, fel:int, varningar:int, punkter:list<array>}
     */
    public static function kor(): array
    {
        $punkter = [];

        foreach ([
            'skrivlase' => 'las',
            'journaler' => 'journaler',
            'historik' => 'historik',
            'backup' => 'backup',
            'disk' => 'disk',
            'rättigheter' => 'rattigheter',
            'sessionskatalog' => 'sessioner',
            'installation' => 'installation',
            'prestanda' => 'prestanda',
        ] as $id => $metod) {
            $punkt = self::{'kontroll_' . str_replace('ä', 'a', $metod)}();
            $punkt['id'] = $id;
            $punkter[] = $punkt;
        }

        $fel = 0;
        $varningar = 0;
        foreach ($punkter as $p) {
            if ($p['niva'] === self::NIVA_FEL) {
                $fel++;
            } elseif ($p['niva'] === self::NIVA_VARNING) {
                $varningar++;
            }
        }
        $niva = $fel > 0 ? self::NIVA_FEL : ($varningar > 0 ? self::NIVA_VARNING : self::NIVA_OK);

        return ['niva' => $niva, 'fel' => $fel, 'varningar' => $varningar, 'punkter' => $punkter];
    }

    /**
     * Bara "är något fel?" – för menyikonen. Resultatet cachas i sessionen i
     * CACHE_SEKUNDER så att varje sidladdning inte läser hela historiken.
     */
    public static function snabb(): array
    {
        $nu = time();
        $cachad = $_SESSION['halso_cache'] ?? null;
        if (is_array($cachad) && ($nu - (int) ($cachad['tid'] ?? 0)) < self::CACHE_SEKUNDER) {
            return $cachad['svar'];
        }
        $svar = self::kor();
        $_SESSION['halso_cache'] = ['tid' => $nu, 'svar' => $svar];
        return $svar;
    }

    /** Töm cachen (anropas när admin sparat inställningar eller tagit backup). */
    public static function rensaCache(): void
    {
        unset($_SESSION['halso_cache']);
    }

    /* ================================================================
     *  Kontrollerna
     * ================================================================ */

    private static function kontroll_las(): array
    {
        $fil = TDL_LOCK . '/skriv.lock';
        if (!is_dir(TDL_LOCK)) {
            return self::punkt(self::NIVA_FEL, 'Låskatalogen saknas',
                'Katalogen ' . TDL_LOCK . ' finns inte. Skapa den och ge webbservern skrivrättighet.', '');
        }
        $fh = @fopen($fil, 'c+');
        if ($fh === false) {
            return self::punkt(self::NIVA_FEL, 'Kan inte öppna skrivlåset',
                'Appen kan inte spara något. Kontrollera rättigheterna på ' . $fil . '.', '');
        }
        $fick = @flock($fh, LOCK_EX | LOCK_NB);
        if ($fick) {
            @flock($fh, LOCK_UN);
        }
        fclose($fh);
        return $fick
            ? self::punkt(self::NIVA_OK, 'Skrivlåset fungerar', 'Lagringen kan låsas och skrivas som den ska.', '')
            : self::punkt(self::NIVA_VARNING, 'Lagringen är låst just nu',
                'En annan skrivning pågår. Är det inte en pågående åtgärd kan en process ha fastnat – '
                . 'kontrollera med <span class="mono">php bin/aterhamta.php</span>.', '');
    }

    private static function kontroll_journaler(): array
    {
        $journaler = Las::oppnaJournaler();
        if ($journaler === []) {
            return self::punkt(self::NIVA_OK, 'Inga öppna journaler',
                'Alla transaktioner har slutförts.', '');
        }
        $namn = array_map(fn ($j) => basename($j), $journaler);
        return self::punkt(self::NIVA_FEL, count($journaler) . ' öppen journal',
            'En skrivning avbröts mitt i. Kör <span class="mono">php bin/aterhamta.php</span> innan appen används: '
            . '<span class="mono">' . e(implode(', ', array_slice($namn, 0, 3))) . '</span>', '');
    }

    private static function kontroll_historik(): array
    {
        try {
            $historik = Json::las('historik');
        } catch (\Throwable $e) {
            return self::punkt(self::NIVA_FEL, 'Historiken kan inte läsas', e($e->getMessage()), '');
        }
        $kedjefel = Historik::verifiera($historik);
        $antal = count($historik['poster']);
        if ($kedjefel === []) {
            return self::punkt(self::NIVA_OK, 'Hashkedjan är intakt',
                number_format($antal, 0, ',', ' ') . ' poster, ingen avvikelse.', '');
        }
        return self::punkt(self::NIVA_FEL, 'Historiken har ' . count($kedjefel) . ' avvikelse(r)',
            'Kedjan stämmer inte: ' . e(implode(' · ', array_slice($kedjefel, 0, 3)))
            . '. Kör <span class="mono">php bin/verifiera-historik.php</span> och jämför med senaste backup.', '');
    }

    private static function kontroll_backup(): array
    {
        $l = Backupkontroll::lage();
        $grans = max(1, (int) Installningar::hamta('backup_max_dygn'));
        if ($l['lage'] === 'saknas') {
            return self::punkt(self::NIVA_VARNING, 'Ingen backup finns',
                'Ta en backup under System och schemalägg <span class="mono">bin/backup.php</span> dagligen via cron.', '');
        }
        $dagar = (int) floor((float) $l['dagar']);
        $text = 'Senaste: ' . (string) $l['senaste'] . ' UTC (' . (int) $l['antal'] . ' i arkivet). Gräns ' . $grans . ' dygn.';
        if ($l['lage'] === 'gammal') {
            return self::punkt(self::NIVA_VARNING, 'Backupen är ' . $dagar . ' dygn gammal', $text, '');
        }
        return self::punkt(self::NIVA_OK, 'Backupen är färsk', $text, '');
    }

    private static function kontroll_disk(): array
    {
        $ledigt = @disk_free_space(TDL_STORAGE);
        $totalt = @disk_total_space(TDL_STORAGE);
        if ($ledigt === false || $totalt === false || $totalt <= 0) {
            return self::punkt(self::NIVA_VARNING, 'Diskutrymmet kan inte mätas',
                'Servern svarar inte på disk_free_space. Kontrollera utrymmet manuellt.', '');
        }
        $procent = $ledigt / $totalt * 100;
        $text = 'Ledigt: ' . number_format($ledigt / 1048576, 0, ',', ' ') . ' MB av '
            . number_format($totalt / 1048576, 0, ',', ' ') . ' MB (' . round($procent, 1) . ' %).';
        if ($ledigt < 50 * 1048576) {
            return self::punkt(self::NIVA_FEL, 'Disken är nästan full', $text . ' Skrivningar kan misslyckas.', '');
        }
        if ($procent < 10) {
            return self::punkt(self::NIVA_VARNING, 'Lite diskutrymme kvar', $text, '');
        }
        return self::punkt(self::NIVA_OK, 'Diskutrymme finns', $text, '');
    }

    private static function kontroll_rattigheter(): array
    {
        $fel = [];
        foreach (array_keys(Json::FILER) as $nyckel) {
            $fil = Json::sokvag($nyckel);
            if (!is_file($fil)) {
                continue;
            }
            $ratt = substr(sprintf('%o', @fileperms($fil) ?: 0), -4);
            if ($ratt !== '0600') {
                $fel[] = basename($fil) . ' (' . $ratt . ')';
            }
        }
        if ($fel === []) {
            return self::punkt(self::NIVA_OK, 'Datafilerna är skyddade',
                'Alla datafiler har rättigheterna 0600 – bara webbservern kan läsa dem.', '');
        }
        return self::punkt(self::NIVA_VARNING, count($fel) . ' datafil(er) har öppna rättigheter',
            'Bör vara 0600: ' . e(implode(', ', array_slice($fel, 0, 5)))
            . '. Andra konton på servern kan annars läsa lagret.', '');
    }

    private static function kontroll_sessioner(): array
    {
        if (!is_dir(TDL_SESSION) || !is_writable(TDL_SESSION)) {
            return self::punkt(self::NIVA_FEL, 'Sessionskatalogen är inte skrivbar',
                'Inloggning fungerar inte. Kontrollera rättigheterna på ' . TDL_SESSION . '.', '');
        }
        $antal = count(glob(TDL_SESSION . '/sess_*') ?: []);
        return self::punkt(self::NIVA_OK, 'Sessioner fungerar',
            $antal . ' öppna sessioner i lagringen.', '');
    }

    private static function kontroll_installation(): array
    {
        if (!Lager::arInstallerad()) {
            return self::punkt(self::NIVA_FEL, 'Installationen är inte klar',
                'Datafilerna finns inte. Kör <span class="mono">php bin/installera.php</span>.', '');
        }
        $antal = count(Json::las('anvandare')['poster']);
        $aktivaAdmin = 0;
        foreach (Json::las('anvandare')['poster'] as $u) {
            if (($u['roll'] ?? '') === 'admin' && !empty($u['aktiv'])) {
                $aktivaAdmin++;
            }
        }
        if ($aktivaAdmin === 0) {
            return self::punkt(self::NIVA_FEL, 'Ingen aktiv administratör',
                'Ingen kan administrera appen. Kör <span class="mono">php bin/installera.php</span> eller återläs en backup.', '');
        }
        return self::punkt(self::NIVA_OK, 'Installationen är komplett',
            $antal . ' konto(n), varav ' . $aktivaAdmin . ' aktiv(a) administratör(er).', '');
    }

    /* ================================================================
     *  Hjälp
     * ================================================================ */

    private static function punkt(string $niva, string $rubrik, string $text, string $rad): array
    {
        return ['niva' => $niva, 'rubrik' => $rubrik, 'text' => $text, 'rad' => $rad];
    }

    /**
     * Prestandabudget: hur stor är baslasten som varje sida hämtar?
     *
     * app.css och app.js laddas på alla sidor; resten laddas bara där det
     * behövs (t.ex. QR-läsaren på skanna). Stora filer mäts okomprimerat
     * och som gzip, eftersom webbservern komprimerar dem. Budgeten är satt
     * med marginal – går en ändring över syns det här i stället för hos
     * användaren med långsam laddning i kassan.
     */
    private static function kontroll_prestanda(): array
    {
        $granser = ['css' => 70_000, 'js' => 70_000, 'gzip' => 45_000];
        $filer = [TDL_PUBLIC . '/assets/app.css', TDL_PUBLIC . '/assets/app.js'];
        $rå = 0;
        $gzip = 0;
        $saknas = [];
        foreach ($filer as $fil) {
            if (!is_file($fil)) {
                $saknas[] = basename($fil);
                continue;
            }
            $innehall = (string) file_get_contents($fil);
            $rå += strlen($innehall);
            $packat = @gzencode($innehall, 6);
            $gzip += $packat === false ? strlen($innehall) : strlen($packat);
        }
        if ($saknas !== []) {
            return self::punkt(self::NIVA_FEL, 'Tillgångsfilerna saknas',
                'Hittar inte ' . implode(', ', array_map(static fn ($f) => e($f), $saknas)) . ' i public/assets. Ladda upp dem igen.', '');
        }
        $kb = static fn (int $b): string => number_format($b / 1000, 1, ',', ' ') . ' kB';
        $over = $rå > ($granser['css'] + $granser['js']) || $gzip > $granser['gzip'];
        $text = 'CSS och JS som varje sida hämtar: ' . $kb($rå) . ' okomprimerat, '
            . $kb($gzip) . ' komprimerat. Budget: '
            . $kb($granser['css'] + $granser['js']) . ' / ' . $kb($granser['gzip']) . '.'
            . ' QR-läsaren (jsqr.js) laddas bara på skanna-sidan och räknas inte hit.';
        if ($over) {
            return self::punkt(self::NIVA_VARNING, 'Baslasten är över budget', $text
                . ' Kontrollera att inget nytt bibliotek laddas på alla sidor.', '');
        }
        return self::punkt(self::NIVA_OK, 'Baslasten håller sig inom budget', $text, '');
    }

    /** Kort text till menyn: tom sträng när allt är bra. */
    public static function menyText(array $svar): string
    {
        if (($svar['fel'] ?? 0) > 0) {
            return (string) $svar['fel'];
        }
        return '';
    }
}
