<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Skanna</h1>
<p class="dampad">Kameran startar först när du trycker på knappen. Fungerar inte kameran kan du alltid skriva koden.</p>

<div class="kort">
    <button type="button" id="starta-kamera" class="primar">Starta kamera</button>
    <div class="kamera-box">
        <video id="kamera" playsinline muted></video>
    </div>
    <p id="kamera-status" class="falt-hjalp" aria-live="polite">Kameran är avstängd.</p>

    <hr class="avdelare">
    <form method="get" action="<?= e(u('/skanna')) ?>" id="manuell-form">
        <label for="enhetskod">Eller skriv koden manuellt</label>
        <input id="enhetskod" name="kod" class="mono" autocomplete="off" placeholder="TDL-U-XXXXX-XXXXX-X">
        <div class="actions">
            <button type="submit" class="primar">Leta upp</button>
        </div>
    </form>
    <?php
    $kod = get('kod');
    if ($kod !== ''):
        $enhet = \TDL\Domain\Lager::enhet($kod);
        $platsKod = \TDL\Domain\Lager::platsViaQr($kod);
        // Hyllapp: QR-koden sitter på hyllan och talar om var man står
        $hyllplats = \TDL\Domain\Lagerplats::hyllplatsViaQr($kod);
        ?>
        <?php if ($enhet !== null): ?>
            <?php
            $artikel = \TDL\Domain\Lager::artikel($enhet['artikel_id']);
            $plats = $enhet['plats_id'] ? \TDL\Domain\Lager::plats($enhet['plats_id']) : null;
            ?>
            <div class="flash <?= $enhet['status'] === 'aktiv' ? 'ok' : 'info' ?>" style="margin-top:16px">
                <strong>Enhet <?= e(\TDL\Support\Koder::visa($enhet['id'])) ?></strong><br>
                Artikel: <a href="<?= e(u('/artiklar/' . (e($artikel['id'] ?? '')))) ?>"><?= e($artikel['namn'] ?? '?') ?></a><br>
                Status: <strong><?= e($enhet['status']) ?></strong><br>
                Arbetsställe: <?= $plats ? e($plats['namn']) : '<span class="dampad">inget (väntande)</span>' ?><br>
                <?php if (!empty($enhet['senast_skannad'])): ?>Senast skannad: <?= e(sv_tid($enhet['senast_skannad'])) ?><?php endif; ?>
            </div>
            <?php if ($enhet['status'] === 'aktiv' && $plats !== null): ?>
                <div class="actions">
                    <a class="knapp" href="<?= e(u('/overforing?artikel=' . e($artikel['id']))) ?>">Överför enheten</a>
                    <a class="knapp" href="<?= e(u('/uttag?artikel=' . e($artikel['id']))) ?>">Uttag</a>
                </div>
            <?php endif; ?>
        <?php elseif ($hyllplats !== null): ?>
            <?php
            $hyZon = \TDL\Domain\Lagerplats::zon((string) $hyllplats['zon_id']);
            $hyArtiklar = \TDL\Domain\Lagerplats::artiklarPa(
                (string) $hyllplats['zon_id'],
                (int) $hyllplats['rad'],
                (int) $hyllplats['fack']
            );
            ?>
            <div class="flash ok" style="margin-top:16px">
                <strong>Hylla <?= e(($hyZon['kod'] ?? '?') . '-' . (int) $hyllplats['rad'] . '-' . (int) $hyllplats['fack']) ?></strong>
                <?= $hyZon !== null ? '· ' . e($hyZon['namn']) : '' ?><br>
                <?= count($hyArtiklar) ?> artiklar på hyllan
            </div>
            <div class="actions">
                <a class="knapp primar" href="<?= e(u('/hylla/' . e($hyllplats['id']))) ?>">Öppna hyllan</a>
                <a class="knapp" href="<?= e(u('/artiklar/ny?zon=' . e($hyllplats['zon_id']) . '&rad=' . (int) $hyllplats['rad'] . '&fack=' . (int) $hyllplats['fack'])) ?>">
                    Ny artikel här
                </a>
            </div>
        <?php elseif ($platsKod !== null): ?>
            <div class="flash info" style="margin-top:16px">
                Det här är arbetsplats-QR för <strong><?= e($platsKod['namn']) ?></strong>.
                Den används för att välja plats i formulären – den flyttar inget lager.
            </div>
            <div class="actions">
                <a class="knapp primar" href="<?= e(u('/inleverans?plats=' . e($platsKod['id']))) ?>">Inleverans till <?= e($platsKod['namn']) ?></a>
                <a class="knapp" href="<?= e(u('/uttag')) ?>">Uttag</a>
            </div>
        <?php else: ?>
            <div class="flash fel" style="margin-top:16px">
                Koden hittas inte. Kontrollera stavningen – kontrolltecknet måste stämma.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="<?= e(u('/assets/jsqr.js')) ?>"></script>
<script src="<?= e(u('/assets/app.js?v=' . e(\TDL_VERSION))) ?>"></script>

