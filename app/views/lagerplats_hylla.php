<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<?php $koord = ($zon['kod'] ?? '?') . '-' . (int) $hyllplats['rad'] . '-' . (int) $hyllplats['fack']; ?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0" class="mono"><?= e($koord) ?></h1>
    <div class="actions" style="margin:0">
        <a class="knapp liten" href="<?= e(u('/lagerkarta?zon=' . e($hyllplats['zon_id']))) ?>">Till kartan</a>
        <a class="knapp liten" href="<?= e(u('/lagerplatser/' . e($hyllplats['zon_id']))) ?>">Zonen</a>
    </div>
</div>
<p class="dampad">
    <?= e($zon['namn'] ?? '') ?> · rad <?= (int) $hyllplats['rad'] ?>, fack <?= (int) $hyllplats['fack'] ?> ·
    hyllapp <span class="mono"><?= e(\TDL\Support\Koder::visa($hyllplats['qr'])) ?></span>
</p>

<div class="kort">
    <h2><?= count($artiklar) ?> artiklar på hyllan</h2>
    <?php if ($artiklar === []): ?>
        <p class="dampad">Hyllan är tom. Artiklar hamnar här när du anger
            <span class="mono"><?= e($koord) ?></span> som plats – eller när du skannar hyllappen
            och lägger upp en ny artikel.</p>
        <div class="actions">
            <a class="knapp primar" href="<?= e(u('/artiklar/ny?zon=' . e($hyllplats['zon_id']) . '&rad=' . (int) $hyllplats['rad'] . '&fack=' . (int) $hyllplats['fack'])) ?>">Ny artikel på hyllan</a>
        </div>
    <?php else: ?>
        <div class="tabell-rull">
        <table>
            <tr><th>Artikel</th><th>Nummer</th><th class="tal">Saldo totalt</th><th class="tal">Miniminivå</th><th></th></tr>
            <?php foreach ($artiklar as $a): ?>
                <tr>
                    <td><a href="<?= e(u('/artiklar/' . e($a['id']))) ?>"><?= e($a['namn']) ?></a>
                        <?php if (empty($a['aktiv'])): ?><span class="tag">inaktiv</span><?php endif; ?>
                        <?php if (!empty($a['ar_tavla'])): ?><span class="tag varning">tavla</span><?php endif; ?>
                    </td>
                    <td class="mono dampad"><?= e($a['artikelnummer']) ?></td>
                    <td class="tal"><?= (int) \TDL\Domain\Lager::totalt(\TDL\Domain\Lager::saldoPerPlats($a)) ?> <?= e($a['enhet']) ?></td>
                    <td class="tal dampad"><?= (int) ($a['miniminiva'] ?? 0) > 0 ? (int) $a['miniminiva'] : '–' ?></td>
                    <td class="tal"><a class="knapp liten" href="<?= e(u('/artiklar/' . e($a['id']))) ?>">Öppna</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <div class="actions" style="margin-top:12px">
            <a class="knapp" href="<?= e(u('/artiklar/ny?zon=' . e($hyllplats['zon_id']) . '&rad=' . (int) $hyllplats['rad'] . '&fack=' . (int) $hyllplats['fack'])) ?>">Ny artikel på hyllan</a>
            <a class="knapp" href="<?= e(u('/inventering?zon=' . e($hyllplats['zon_id']))) ?>">Inventera zonen</a>
        </div>
    <?php endif; ?>
</div>
