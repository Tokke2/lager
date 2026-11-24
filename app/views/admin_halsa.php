<?php
/**
 * Hälsokontroll – hela listan med nivå och råd.
 *
 * Läser bara. Varje rad kommer från HalsoKontroll::kor() och har en nivå,
 * en rubrik och en förklaring med vad man gör åt saken.
 *
 * @var array $halso
 */
$ikon = ['ok' => 'bock', 'varning' => 'varning', 'fel' => 'kryss'];
$ordning = ['fel' => 0, 'varning' => 1, 'ok' => 2];
$punkter = $halso['punkter'];
usort($punkter, fn ($a, $b) => ($ordning[$a['niva']] ?? 9) <=> ($ordning[$b['niva']] ?? 9));
?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0">Hälsokontroll</h1>
    <a class="knapp" href="<?= e(u('/admin/system')) ?>">Till System</a>
</div>

<?php if ($halso['niva'] === 'ok'): ?>
    <div class="flash ok" role="status">
        <strong>Allt är som det ska.</strong>
        <?= count($halso['punkter']) ?> kontroller kördes utan anmärkning.
    </div>
<?php elseif ($halso['niva'] === 'varning'): ?>
    <div class="flash varning" role="status">
        <strong><?= (int) $halso['varningar'] ?> <?= (int) $halso['varningar'] === 1 ? 'sak' : 'saker' ?> att se över.</strong>
        Inget är trasigt just nu – men det kan bli det om det får ligga.
    </div>
<?php else: ?>
    <div class="flash fel" role="status">
        <strong><?= (int) $halso['fel'] ?> fel måste åtgärdas.</strong>
        Appen kan inte litas på förrän de är lösta. Börja med raderna högst upp.
    </div>
<?php endif; ?>

<p class="dampad">
    Kontrollerna läser bara – de ändrar ingenting. Samma kontroll körs var 60:e sekund
    för varningspricken på System i menyn. Sedan sidan laddades:
    <?= e(sv_tid(nu())) ?>.
</p>

<div class="halso-lista">
    <?php foreach ($punkter as $p): ?>
        <div class="halso-rad <?= e($p['niva']) ?>">
            <span class="ikon"><?= tdl_ikon($ikon[$p['niva']] ?? 'info') ?></span>
            <div>
                <strong><?= e($p['rubrik']) ?></strong>
                <span class="dampad"><?= $p['text'] /* HTML från kontrollerna – byggs internt */ ?></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="kort" style="margin-top:18px">
    <h2>Om en kontroll slår larm</h2>
    <ul>
        <li><strong>Öppen journal:</strong> en skrivning avbröts mitt i. Kör
            <span class="mono">php bin/aterhamta.php</span> – den slutför exakt det som
            skulle sparas, aldrig något påhittat.</li>
        <li><strong>Hashkedjan:</strong> jämför med senaste backup innan något annat görs.
            Kör <span class="mono">php bin/verifiera-historik.php</span> för hela listan.</li>
        <li><strong>Backup för gammal:</strong> kontrollera cron-jobbet på webbhotellet
            (se <span class="mono">dokument/06_drift-webbhotell.md</span>).</li>
        <li><strong>Rättigheter:</strong> datafilerna ska vara 0600. Kör
            <span class="mono">chmod 600 storage/data/*.json</span>.</li>
    </ul>
</div>
