<?php
/**
 * Års- och månadsarkiv för inventeringar.
 * @var int $ar @var int $manad @var array $allaAr @var array $oversikt
 * @var ?array $manadsdata @var array $arssammanstallning @var ?array $valdPost @var array $manadsnamn
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$arAdmin = ($konto['roll'] ?? '') === 'admin';
$summa = $arssammanstallning;
?>
<h1>Arkiv</h1>
<p class="dampad">
    Varje avslutad inventering sparas som en fryst kopia – per <strong>år</strong> och
    <strong>månad</strong>. Ingenting ändras i efterhand, så arkivet går alltid att lita på.
</p>

<div class="rutnat">
    <div class="kort stat">
        <span class="tal"><?= (int) $oversikt['totalt'] ?></span>
        <span class="etikett">arkiverade inventeringar totalt</span>
    </div>
    <div class="kort stat">
        <span class="tal"><?= (int) $summa['totalt'] ?></span>
        <span class="etikett">under <?= (int) $ar ?></span>
    </div>
    <div class="kort stat <?= $summa['avvikelser'] > 0 ? 'varning' : '' ?>">
        <span class="tal"><?= (int) $summa['avvikelser'] ?></span>
        <span class="etikett">avvikelser under <?= (int) $ar ?></span>
    </div>
</div>

<div class="kort">
    <h2>Välj år och månad</h2>
    <div class="arkiv-ar">
        <?php foreach ($allaAr as $a): ?>
            <a class="knapp liten <?= $a === $ar ? 'primar' : '' ?>" href="<?= e(u('/arkiv?ar=' . $a)) ?>"><?= $a ?></a>
        <?php endforeach; ?>
    </div>
    <div class="arkiv-månader">
        <?php foreach ($manadsnamn as $nr => $namn): ?>
            <?php $m = $summa['manader'][$nr] ?? null; ?>
            <a class="arkiv-manad <?= $manad === $nr ? 'vald' : '' ?> <?= $m === null ? 'tom' : '' ?>"
               href="<?= e(u('/arkiv?ar=' . ($ar) . '&manad=' . ($nr) . '#manad')) ?>">
                <span class="mn"><?= e($namn) ?></span>
                <?php if ($m === null): ?>
                    <span class="antal">–</span>
                <?php else: ?>
                    <span class="antal"><?= (int) $m['sessioner'] ?></span>
                    <span class="avv"><?= (int) $m['avvikelser'] ?> avv.</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($manad >= 1 && $manad <= 12 && $manadsdata !== null): ?>
<div class="kort" id="manad">
    <h2><?= e($manadsnamn[$manad]) ?> <?= (int) $ar ?></h2>
    <?php if ($manadsdata['poster'] === []): ?>
        <p class="dampad">Inga inventeringar arkiverade den här månaden.</p>
    <?php else: ?>
        <div class="tabell-rull">
        <table class="kort-tabell">
            <thead>
            <tr>
                <th>Inventering</th><th>Arbetsställe</th><th>Avslutad</th>
                <th class="tal-hoger">Artiklar</th><th class="tal-hoger">Avvikelser</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($manadsdata['poster'] as $p): ?>
                <tr>
                    <td class="mono"><?= e($p['session_id']) ?></td>
                    <td data-etikett="Arbetsställe"><?= e($p['plats_namn']) ?></td>
                    <td data-etikett="Avslutad"><?= e(sv_tid($p['avslutad'] ?? null)) ?></td>
                    <td class="tal-hoger" data-etikett="Artiklar"><?= (int) $p['antal_artiklar'] ?></td>
                    <td class="tal-hoger <?= $p['antal_avvikelser'] > 0 ? 'brist' : '' ?>" data-etikett="Avvikelser"><?= (int) $p['antal_avvikelser'] ?></td>
                    <td><a class="knapp liten" href="<?= e(u('/arkiv?ar=' . ($ar) . '&manad=' . ($manad) . '&session=' . (e($p['session_id'])) . '#post')) ?>">Visa</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($valdPost !== null): ?>
<div class="kort" id="post">
    <h2>Inventering <?= e($valdPost['session_id']) ?></h2>
    <p class="dampad">
        <?= e($valdPost['plats_namn']) ?> ·
        startad <?= e(sv_tid($valdPost['startad'] ?? null)) ?> av <?= e($valdPost['startad_av']) ?><br>
        avslutad <?= e(sv_tid($valdPost['avslutad'] ?? null)) ?> av <?= e($valdPost['avslutad_av']) ?>
        <?php if (!empty($valdPost['avstamning']['typ'])): ?>
            · avstämd som <strong><?= e($valdPost['avstamning']['typ']) ?></strong>
        <?php endif; ?>
    </p>

    <div class="rutnat">
        <div class="kort stat">
            <span class="tal"><?= (int) $valdPost['antal_artiklar'] ?></span>
            <span class="etikett">artiklar</span>
        </div>
        <div class="kort stat <?= $valdPost['antal_avvikelser'] > 0 ? 'varning' : '' ?>">
            <span class="tal"><?= (int) $valdPost['antal_avvikelser'] ?></span>
            <span class="etikett">avvikelser</span>
        </div>
        <div class="kort stat">
            <span class="tal"><?= (int) $valdPost['summa_raknat'] ?></span>
            <span class="etikett">räknade enheter</span>
        </div>
    </div>

    <div class="tabell-rull">
    <table class="kort-tabell">
        <thead><tr><th>Artikel</th><th class="tal-hoger">Förväntat</th><th class="tal-hoger">Räknat</th><th class="tal-hoger">Differens</th></tr></thead>
        <tbody>
        <?php foreach ($valdPost['rader'] as $r): ?>
            <tr>
                <td><?= e($r['namn']) ?> <span class="dampad mono" style="font-size:.8rem"><?= e($r['artikelnummer']) ?></span></td>
                <td class="tal-hoger" data-etikett="Förväntat"><?= (int) $r['forvantat'] ?> <?= e($r['enhet']) ?></td>
                <td class="tal-hoger" data-etikett="Räknat"><?= (int) $r['raknat'] ?></td>
                <td class="tal-hoger <?= $r['differens'] !== 0 ? 'brist' : '' ?>" data-etikett="Differens">
                    <?= $r['differens'] > 0 ? '+' : '' ?><?= (int) $r['differens'] ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if ($arAdmin): ?>
<div class="kort">
    <h2>Bygg arkivet</h2>
    <p class="dampad">
        Nya inventeringar arkiveras automatiskt när de avslutas. Den här knappen lägger
        till äldre avslutade inventeringar som saknas i arkivet – den ändrar ingenting annat.
    </p>
    <form method="post" action="<?= e(u('/arkiv/bygg')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="primar">Arkivera det som saknas</button>
    </form>
</div>
<?php endif; ?>
