<h1>Uppdateringskontroll</h1>
<div class="kort">
    <p>Installerad versionsbeteckning: <strong><?= e(TDL_VERSION) ?></strong></p>
    <p>Tillåten gren: <span class="mono"><?= e(\TDL\Support\Uppdateringskontroll::GREN) ?></span></p>
    <?php if ($release !== null): ?>
        <p>Nyare stabil release i grenens historik: <strong><?= e($release['tagg']) ?></strong>.</p>
        <p><a href="<?= e($release['url']) ?>" target="_blank" rel="noopener noreferrer">Granska releasen på GitHub</a></p>
    <?php else: ?>
        <p>Ingen nyare stabil release i den tillåtna grenens historik hittades bland de senaste 20 releaserna.</p>
    <?php endif; ?>
    <p class="falt-hjalp">Detta är endast en kontroll av GitHubs metadata, inte en kryptografisk signaturkontroll eller installation. Releaser på andra grenar och äldre versioner används inte.</p>
    <p>Uppdatering måste tills vidare utföras av driftansvarig enligt dokumentationen, efter verifierad separat backup och test. Ingen backup har tagits eller raderats av kontrollen.</p>
    <a class="knapp" href="<?= e(u('/admin/system')) ?>">Tillbaka till systemstatus</a>
</div>
