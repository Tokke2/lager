<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0">Zon <?= e($zon['kod']) ?> – <?= e($zon['namn']) ?></h1>
    <div class="actions" style="margin:0">
        <a class="knapp" href="<?= e(u('/lagerkarta?zon=' . e($zon['id']))) ?>">Lagerkarta</a>
        <?php if ($arAdmin): ?>
            <a class="knapp" href="<?= e(u('/lagerplatser/' . e($zon['id']) . '/redigera')) ?>">Redigera zon</a>
        <?php endif; ?>
    </div>
</div>
<p class="dampad"><?= (int) $zon['rader'] ?> rader × <?= (int) $zon['fack'] ?> fack.
    Varje hylla har en egen QR-lapp – skriv ut dem och sätt på plats.</p>

<div class="actions">
    <?php if ($arAdmin): ?>
        <form method="post" action="<?= e(u('/lagerplatser/' . e($zon['id']) . '/hyllor')) ?>" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit">Skapa saknade hyllplatser</button>
        </form>
    <?php endif; ?>
    <?php if ($hyllplatser !== []): ?>
        <a class="knapp primar" href="<?= e(u('/lagerplatser/' . e($zon['id']) . '/skriv-ut')) ?>" target="_blank" rel="noopener">
            Skriv ut <?= count($hyllplatser) ?> hyllappar
        </a>
    <?php endif; ?>
</div>

<?php if ($hyllplatser === []): ?>
    <div class="kort">
        <h2>Inga hyllplatser ännu</h2>
        <p class="dampad">Hyllplatserna är de fysiska hyllorna i zonen. Skapa dem först,
            sedan kan du skriva ut en lapp per hylla.</p>
    </div>
<?php else: ?>
<?php
// Gruppera per rad så att listan följer gångordningen
$perRad = [];
foreach ($hyllplatser as $h) {
    $perRad[(int) $h['rad']][] = $h;
}
ksort($perRad);
?>
<div class="kort">
    <h2>Hyllplatser (<?= count($hyllplatser) ?>)</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>Rad</th><th>Fack</th><th>Plats</th><th>QR-kod</th><th></th></tr>
        <?php foreach ($perRad as $rad => $iRaden): ?>
            <?php foreach ($iRaden as $i => $h): ?>
                <tr>
                    <td><?= $i === 0 ? '<strong>' . (int) $rad . '</strong>' : '' ?></td>
                    <td><?= (int) $h['fack'] ?></td>
                    <td><span class="mono"><?= e($zon['kod']) ?>-<?= (int) $h['rad'] ?>-<?= (int) $h['fack'] ?></span></td>
                    <td class="mono dampad"><?= e(\TDL\Support\Koder::visa($h['qr'])) ?></td>
                    <td class="tal"><a class="knapp liten" href="<?= e(u('/hylla/' . e($h['id']))) ?>">Öppna</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>
