<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0">Lagerplatser</h1>
    <?php if ($arAdmin): ?>
        <a class="knapp primar" href="<?= e(u('/lagerplatser/ny')) ?>">Ny zon</a>
    <?php endif; ?>
</div>
<p class="dampad">En zon är ett område i lagret – en verkstad, ett förråd, en vägg. Inom zonen
    anger du rad och fack, så blir varje artikel hittbar på tre nivåer: <span class="mono">A-3-2</span>.
    Varje hylla får en egen QR-lapp som du skannar med appen för att se vad som står där.</p>

<?php if ($rader === []): ?>
    <div class="kort">
        <h2>Inga zoner ännu</h2>
        <p class="dampad">Utan en zon går det inte att lägga upp artiklar – platsen är obligatorisk.
            Börja med att skapa en zon för det område du har mest i, t.ex. <strong>A – Verkstad</strong>.</p>
        <?php if ($arAdmin): ?>
            <div class="actions"><a class="knapp primar" href="<?= e(u('/lagerplatser/ny')) ?>">Skapa första zonen</a></div>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="tabell-rull">
<table>
    <tr><th>Zon</th><th>Rutnät</th><th class="tal">Artiklar</th><th class="tal">Hyllplatser</th><th></th></tr>
    <?php foreach ($rader as $r): $z = $r['zon']; ?>
        <tr>
            <td>
                <strong class="mono"><?= e($z['kod']) ?></strong> · <?= e($z['namn']) ?>
                <?php if ($r['hyllor'] === 0): ?><span class="tag varning">inga hyllappar</span><?php endif; ?>
            </td>
            <td class="dampad"><?= (int) $z['rader'] ?> rader × <?= (int) $z['fack'] ?> fack
                <span class="falt-hjalp">(<?= (int) $r['rutor'] ?> platser)</span></td>
            <td class="tal"><?= (int) $r['antal'] ?></td>
            <td class="tal"><?= (int) $r['hyllor'] ?> / <?= (int) $r['rutor'] ?></td>
            <td class="tal">
                <div class="actions" style="justify-content:flex-end;margin:0;gap:6px">
                    <a class="knapp liten" href="<?= e(u('/lagerkarta?zon=' . e($z['id']))) ?>">Karta</a>
                    <a class="knapp liten" href="<?= e(u('/lagerplatser/' . e($z['id']))) ?>">Hyllor</a>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php if ($utanPlats !== []): ?>
<div class="kort" style="margin-top:16px">
    <h2>Plats saknas (<?= count($utanPlats) ?>)</h2>
    <p class="dampad">De här artiklarna skapades innan platsen blev obligatorisk. Ge dem en plats
        så syns de på kartan också.</p>
    <div class="tabell-rull">
    <table>
        <tr><th>Artikel</th><th>Nummer</th><th></th></tr>
        <?php foreach (array_slice($utanPlats, 0, 40) as $a): ?>
            <tr>
                <td><?= e($a['namn']) ?></td>
                <td class="mono dampad"><?= e($a['artikelnummer']) ?></td>
                <td class="tal"><a class="knapp liten" href="<?= e(u('/artiklar/' . e($a['id']) . '/redigera')) ?>">Sätt plats</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php if (count($utanPlats) > 40): ?>
        <p class="falt-hjalp">Visar 40 av <?= count($utanPlats) ?>.</p>
    <?php endif; ?>
</div>
<?php endif; ?>
