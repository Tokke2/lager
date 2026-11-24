<?php /** @var array $rader @var bool $larmPa */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Under miniminivå</h1>
<p class="dampad">
    Artiklar där saldot på ett arbetsställe är lägre än artikelns miniminivå.
    Sorterat efter största brist först – det är där det tar slut snabbast.
</p>

<?php if ($flyttningar !== []): ?>
    <a class="flash info larm-rad" href="#flytt">
        <strong><?= count($flyttningar) ?> <?= count($flyttningar) === 1 ? 'rad kan' : 'rader kan' ?> lösas med en överföring.</strong>
        Flytta mellan arbetsställena i stället för att beställa.
        <span class="larm-lank">Visa förslagen →</span>
    </a>
<?php endif; ?>

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
                        <span class="dampad mono" style="font-size:.8rem;margin-left:6px"><?= e($r['artikel']['artikelnummer']) ?></span>
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

<?php if ($flyttningar !== []): ?>
    <div class="kort" id="flytt">
        <h2>Flytta i stället för att beställa</h2>
        <p class="dampad">
            Samma artikel har överskott på ett arbetsställe och brist på ett annat.
            Här är den kortaste vägen – varje förslag går att genomföra direkt och
            lämnar aldrig givaren under miniminivån.
        </p>
        <div class="tabell-rull">
        <table class="kort-tabell">
            <thead>
            <tr>
                <th>Artikel</th><th>Från</th><th>Till</th>
                <th class="tal-hoger">Antal</th><th>Åtgärd</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($flyttningar as $f): ?>
                <tr>
                    <td>
                        <a href="<?= e(u('/artiklar/' . e($f['artikel']['id']))) ?>"><?= e($f['artikel']['namn']) ?></a>
                        <span class="dampad mono" style="font-size:.8rem;margin-left:6px"><?= e($f['artikel']['artikelnummer']) ?></span>
                    </td>
                    <td data-etikett="Från"><?= e($f['fran']['namn']) ?></td>
                    <td data-etikett="Till"><?= e($f['till']['namn']) ?></td>
                    <td class="tal-hoger" data-etikett="Antal">
                        <?= (int) $f['antal'] ?> <?= e($f['artikel']['enhet']) ?>
                    </td>
                    <td>
                        <a class="knapp liten" href="<?= e(u('/overforing?artikel=' . e($f['artikel']['id'])
                            . '&fran=' . e($f['fran']['id'])
                            . '&till=' . e($f['till']['id'])
                            . '&antal=' . (int) $f['antal'])) ?>">Föreslå överföring</a>
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
