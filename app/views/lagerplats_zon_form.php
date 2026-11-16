<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1><?= $zon === null ? 'Ny zon' : 'Redigera zon ' . e($zon['kod']) ?></h1>
<p class="dampad">Zonen beskriver ett område i lagret. Rad- och fackantalet bestämmer rutnätet
    på kartan och hur många hyllplatser som kan skapas.</p>

<form method="post" action="<?= e($zon === null ? u('/lagerplatser/ny') : u('/lagerplatser/' . $zon['id'] . '/redigera')) ?>" class="kort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="rad">
        <div class="falt" style="flex:2">
            <label for="namn">Namn *</label>
            <input id="namn" name="namn" required maxlength="<?= \TDL\Domain\Lagerplats::NAMN_MAX ?>"
                   value="<?= e($zon['namn'] ?? '') ?>" placeholder="t.ex. Verkstad">
        </div>
        <div class="falt">
            <label for="kod">Kod *</label>
            <input id="kod" name="kod" required maxlength="3" class="mono"
                   value="<?= e($zon['kod'] ?? '') ?>" placeholder="A">
            <div class="falt-hjalp">1–3 tecken. Blir prefix i platsen: A-3-2.</div>
        </div>
    </div>
    <div class="rad">
        <div class="falt">
            <label for="rader">Antal rader</label>
            <input id="rader" name="rader" type="number" min="1" max="<?= \TDL\Domain\Lagerplats::MAX_RADER ?>"
                   value="<?= (int) ($zon['rader'] ?? 5) ?>">
        </div>
        <div class="falt">
            <label for="fack">Antal fack per rad</label>
            <input id="fack" name="fack" type="number" min="1" max="<?= \TDL\Domain\Lagerplats::MAX_FACK ?>"
                   value="<?= (int) ($zon['fack'] ?? 8) ?>">
            <div class="falt-hjalp">Rutnätet blir <span class="mono">rader × fack</span> rutor.</div>
        </div>
    </div>
    <div class="actions">
        <button type="submit" class="primar"><?= $zon === null ? 'Skapa zon' : 'Spara' ?></button>
        <a class="knapp" href="<?= e(u($zon === null ? '/lagerplatser' : '/lagerplatser/' . $zon['id'])) ?>">Avbryt</a>
    </div>
    <?php if ($zon !== null): ?>
        <p class="falt-hjalp">Rutnätet kan inte krympas så att artiklar hamnar utanför. Flytta dem i så fall först.</p>
    <?php endif; ?>
</form>
