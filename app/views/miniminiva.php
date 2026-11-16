<?php /** @var array $rader @var bool $larmPa */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Under miniminivå</h1>
<p class="dampad">
    Artiklar där saldot på ett arbetsställe är lägre än artikelns miniminivå.
    Sorterat efter största brist först – det är där det tar slut snabbast.
</p>

<?php if ($rader === []): ?>
    <div class="flash ok">
        <strong>Allt är över miniminivå.</strong>
        Inget behöver fyllas på just nu.
    </div>
<?php else: ?>
    <div class="kort">
        <p>
            <span class="tal" style="font-size:2rem;font-weight:700"><?= count($rader) ?></span>
            <span class="dampad"><?= count($rader) === 1 ? 'rad behöver' : 'rader behöver' ?> fyllas på</span>
        </p>

        <div class="tabell-rull">
        <table class="kort-tabell">
            <thead>
            <tr>
                <th>Artikel</th><th>Arbetsställe</th><th class="tal-hoger">Saldo</th>
                <th class="tal-hoger">Miniminivå</th><th class="tal-hoger">Brist</th><th>Åtgärd</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rader as $r): ?>
                <tr>
                    <td>
                        <a href="<?= e(u('/artiklar/' . e($r['artikel']['id']))) ?>"><?= e($r['artikel']['namn']) ?></a>
                        <span class="dampad mono" style="font-size:.8rem"><?= e($r['artikel']['artikelnummer']) ?></span>
                    </td>
                    <td data-etikett="Arbetsställe"><?= e($r['plats']['namn']) ?></td>
                    <td class="tal-hoger <?= $r['saldo'] === 0 ? 'brist' : '' ?>" data-etikett="Saldo">
                        <?= (int) $r['saldo'] ?> <?= e($r['artikel']['enhet']) ?>
                    </td>
                    <td class="tal-hoger dampad" data-etikett="Miniminivå"><?= (int) $r['miniminiva'] ?></td>
                    <td class="tal-hoger" data-etikett="Brist"><strong><?= (int) $r['brist'] ?></strong></td>
                    <td>
                        <a class="knapp liten" href="<?= e(u('/inleverans?artikel=' . (e($r['artikel']['id'])) . '&plats=' . (e($r['plats']['id'])))) ?>">Fyll på</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<div class="kort">
    <h2>Larmet på översikten</h2>
    <?php if ($larmPa): ?>
        <p>En rad på översikten visar hur många rader som ligger under miniminivå, med länk hit.</p>
    <?php else: ?>
        <p class="dampad">Larmet är avstängt. Slå på det under <a href="<?= e(u('/system')) ?>">System</a>.</p>
    <?php endif; ?>

    <h3>E-post en gång om dagen</h3>
    <p class="dampad">
        Vill du få en sammanställning på morgonen i stället för att titta här?
        Slå på det under <a href="<?= e(u('/system')) ?>">System</a> och lägg in
        <span class="mono">php bin/miniminiva-mail.php</span> i cron på webbhotellet.
        Instruktionen finns i <span class="mono">dokument/06_drift-webbhotell.md</span>.
    </p>
</div>
