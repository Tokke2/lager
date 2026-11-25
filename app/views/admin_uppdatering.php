<h1>Uppdateringskontroll</h1>
<div class="kort">
    <p>Tillåten gren: <span class="mono"><?= e(\TDL\Support\Uppdateringskontroll::GREN) ?></span><br>
       Senaste release: <a href="<?= e($release['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($release['tagg']) ?></a></p>
    <p>Jämförda programfiler: <strong><?= (int) $release['antal'] ?></strong> · oförändrade: <strong><?= (int) $release['oforandrade'] ?></strong> · ändrade: <strong><?= count($release['andrade']) ?></strong> · saknade: <strong><?= count($release['saknade']) ?></strong> · ej kontrollerbara: <strong><?= count($release['otillgangliga']) ?></strong>.</p>
    <?php if ($release['andrade'] === [] && $release['saknade'] === [] && $release['otillgangliga'] === []): ?>
        <p class="flash ok">Alla jämförda programfiler stämmer med senaste releasen.</p>
    <?php else: ?>
        <p class="flash varning">En eller flera programfiler avviker eller kunde inte kontrolleras. Granska skillnaderna innan något uppdateras.</p>
    <?php endif; ?>
    <?php foreach (['andrade' => 'Ändrade filer', 'saknade' => 'Saknade filer', 'otillgangliga' => 'Ej kontrollerbara filer'] as $nyckel => $rubrik): ?>
        <?php if ($release[$nyckel] !== []): ?><details><summary><?= e($rubrik) ?> (<?= count($release[$nyckel]) ?>)</summary><ul><?php foreach ($release[$nyckel] as $fil): ?><li class="mono uppdateringsfil"><?= e($fil) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <?php endforeach; ?>
    <p class="falt-hjalp">Jämför versionshanterade filer i app, public, bin och brand samt index.php. Extra lokala filer och filrättigheter kontrolleras inte; storage och all lagerdata lämnas orörda. GitHub-blobhashar visar skillnader men är inte en signaturkontroll.</p>
    <p>Kontrollen installerar ingen kod och skapar eller tar inte bort någon backup. Installation kräver fortsatt driftansvarig och verifierad separat backup.</p>
    <a class="knapp" href="<?= e(u('/admin/system')) ?>">Tillbaka till systemstatus</a>
</div>
