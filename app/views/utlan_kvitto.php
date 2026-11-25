<h1>Kvitto för återlämning</h1>
<div class="kort">
    <p><strong><?= e($kvitto['artikelnamn']) ?></strong> · <?= e($kvitto['lanare']) ?></p>
    <p>Återlämnat: <?= (int) $kvitto['antal'] ?> · kvar på lånet: <?= (int) $kvitto['kvar'] ?></p>
    <p>Arbetsställe: <?= e($kvitto['plats_namn']) ?><br>Tid: <?= e(sv_tid($kvitto['tid'])) ?><br>Registrerat av: <?= e($kvitto['av']) ?></p>
    <?php if ($kvitto['enheter'] !== []): ?><p>Enheter: <?= e(implode(', ', array_map(static fn ($kod) => \TDL\Support\Koder::visa($kod), $kvitto['enheter']))) ?></p><?php endif; ?>
    <?php if (($kvitto['skick'] ?? 'ok') === 'skadad'): ?><p><strong>Skadad – spärrad i väntan på kontroll.</strong> <?= e($kvitto['notering'] ?? '') ?></p><?php endif; ?>
    <p class="mono">Kvitto <?= e($kvitto['id']) ?></p>
    <div class="actions"><a class="knapp" href="<?= e(u('/utlan')) ?>">Till utlåningarna</a></div>
</div>
