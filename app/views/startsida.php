<?php
/** @var array $perPlats @var int $antalArtiklar @var array $oppnaSessioner @var array $sessioner @var array $underMinimi @var array $backupLage */
$arAdmin = ($konto['roll'] ?? '') === 'admin';
?>
<h1>Översikt</h1>
<p class="dampad">Lager per arbetsställe – totalen döljer aldrig var sakerna finns.</p>

<?php if ($oppnaSessioner !== []): ?>
    <div class="flash info">
        <strong>Pågående inventering:</strong>
        <?php foreach ($oppnaSessioner as $s): ?>
            <a href="<?= e(u('/inventering/' . e($s['id']))) ?>"><?= e($s['id']) ?> (<?= e($s['startad_av_namn'] ?? '') ?>)</a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($underMinimi !== []): ?>
    <?php
    $antalRader = count($underMinimi);
    $slut = count(array_filter($underMinimi, fn ($r) => $r['saldo'] === 0));
    ?>
    <a class="flash varning larm-rad" href="<?= e(u('/miniminiva')) ?>">
        <strong><?= $antalRader ?> <?= $antalRader === 1 ? 'artikel ligger' : 'artiklar ligger' ?> under miniminivå.</strong>
        <?php if ($slut > 0): ?>
            <?= $slut ?> av dem är helt slut.
        <?php endif; ?>
        <span class="larm-lank">Visa listan →</span>
    </a>
<?php else: ?>
    <a class="flash ok larm-rad" href="<?= e(u('/miniminiva')) ?>">
        <strong>Allt är över miniminivå.</strong>
        <span class="larm-lank">Visa larmlistan →</span>
    </a>
<?php endif; ?>

<div class="rutnat">
    <div class="kort stat">
        <span class="tal"><?= $antalArtiklar ?></span>
        <span class="etikett">aktiva artiklar</span>
    </div>
    <?php foreach ($perPlats as $rad): ?>
        <div class="kort stat <?= $rad['under_min'] > 0 ? 'varning' : '' ?>">
            <span class="tal"><?= (int) $rad['antal_artiklar'] ?></span>
            <span class="etikett">
                <?= e($rad['plats']['namn']) ?>
                <?php if (empty($rad['plats']['aktiv'])): ?><span class="tag">stängd</span><?php endif; ?>
            </span>
            <?php if (!empty($rad['plats']['system'])): ?>
                <span class="falt-hjalp">systempost för äldre saldo</span>
            <?php else: ?>
                <span class="falt-hjalp"><?= (int) $rad['enheter'] ?> QR-<?= (int) $rad['enheter'] === 1 ? 'enhet' : 'enheter' ?><?= $rad['under_min'] > 0 ? ' · ' . (int) $rad['under_min'] . ' under miniminivå' : '' ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="kort">
    <h2>Snabbåtgärder</h2>
    <div class="actions">
        <a class="knapp primar" href="<?= e(u('/inleverans')) ?>">Inleverans</a>
        <a class="knapp" href="<?= e(u('/uttag')) ?>">Uttag</a>
        <a class="knapp" href="<?= e(u('/overforing')) ?>">Överför</a>
        <a class="knapp" href="<?= e(u('/inventering')) ?>">Inventering</a>
        <a class="knapp" href="<?= e(u('/skanna')) ?>">Skanna</a>
    </div>
</div>

<?php if ($arAdmin): ?>
    <?php $bl = $backupLage; ?>
    <a class="flash <?= $bl['lage'] === 'ok' ? 'ok' : 'fel' ?> larm-rad" href="<?= e(u('/admin/system')) ?>">
        <strong>Backup:</strong> <?= e(\TDL\Support\Backupkontroll::sammanfattning()) ?>
        <?php if ($bl['lage'] !== 'ok'): ?>
            – kontrollera schemaläggningen
        <?php endif; ?>
        <span class="larm-lank">Systemstatus →</span>
    </a>
<?php endif; ?>

<?php if ($sessioner !== []): ?>
<div class="kort">
    <h2>Senaste inventeringar</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>ID</th><th>Startad</th><th>Av</th><th>Status</th></tr>
        <?php foreach ($sessioner as $s): ?>
            <tr>
                <td><a href="<?= e(u('/inventering/' . e($s['id']))) ?>" class="mono"><?= e($s['id']) ?></a></td>
                <td><?= e(sv_tid($s['startad'] ?? null)) ?></td>
                <td><?= e($s['startad_av_namn'] ?? '–') ?></td>
                <td><span class="tag <?= ($s['status'] ?? '') === 'öppen' ? 'varning' : 'ok' ?>"><?= e($s['status'] ?? '') ?></span></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>
