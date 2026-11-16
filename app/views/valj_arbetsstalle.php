<?php
/**
 * Välj arbetsställe – första steget innan arbetet börjar.
 *
 * Visas när flera arbetsställen finns och inget är valt. Valet sparas i
 * sessionen och fyller i sig självt i inleverans, uttag, plocklista och
 * inventering. Det går att byta när som helst i huvudet.
 *
 * @var array $platser  valbara arbetsställen
 * @var string $nasta   vart man ska efteråt (tomt = översikten)
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$nasta = $nasta ?? '';
// Bara egna sidor som returmål
if ($nasta !== '' && !str_starts_with($nasta, '/')) {
    $nasta = '';
}
?>
<div class="valj-stalle">
    <h1>Var jobbar du i dag?</h1>
    <p class="dampad">
        Välj arbetsställe innan du börjar. Då fylls det i automatiskt när du tar emot,
        tar ut, plockar eller inventerar – och du slipper välja om för varje sak.
        Du kan byta när som helst uppe i huvudet.
    </p>

    <form method="post" action="<?= e(u('/arbetsstalle')) ?>" class="stalle-lista">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="nasta" value="<?= e($nasta) ?>">

        <?php foreach ($platser as $p): ?>
            <button type="submit" name="plats_id" value="<?= e($p['id']) ?>" class="stalle-kort">
                <span class="stalle-namn"><?= e($p['namn']) ?></span>
                <span class="stalle-kod mono"><?= e($p['kod']) ?></span>
            </button>
        <?php endforeach; ?>
    </form>

    <?php if (($konto['roll'] ?? '') === 'admin'): ?>
        <form method="post" action="<?= e(u('/arbetsstalle')) ?>" style="margin-top:18px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="plats_id" value="">
            <input type="hidden" name="nasta" value="<?= e($nasta) ?>">
            <button type="submit" class="liten">Arbeta överallt (byt ställe i varje steg)</button>
        </form>
    <?php endif; ?>
</div>
