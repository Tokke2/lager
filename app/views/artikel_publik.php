<?php
/**
 * Publik artikelsida – öppnas via artikelns QR-kod.
 *
 * Fungerar i alla mobilwebbläsare utan inloggning för att TITTA.
 * För att ändra antal krävs inloggning (samma konto som i appen).
 *
 * @var array $artikel @var array $oversikt @var list<array> $platser
 * @var string $vald_plats @var list<array> $bilder @var string $csrf
 * @var string $signatur @var string $nonce @var array $varumarke @var array|null $konto
 */
$csrf = $csrf ?? \TDL\Support\Sakerhet::csrfToken();
$brand = $varumarke ?? \TDL\Support\Varumarke::alla();
$bas = '/artikel/' . rawurlencode($artikel['id']) . '?n=' . rawurlencode($nonce) . '&s=' . rawurlencode($signatur);
$arQr = ($artikel['sparningssatt'] ?? 'antal') === 'qr';
$saldoHär = 0;
foreach ($oversikt['rader'] as $rad) {
    if (($rad['plats']['id'] ?? '') === $vald_plats) {
        $saldoHär = (int) $rad['antal'];
    }
}
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($brand['farg_bakgrund']) ?>">
<title><?= e($artikel['namn']) ?> – <?= e($brand['namn']) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(\TDL\Support\Varumarke::ikonUrl()) ?>">
<link rel="apple-touch-icon" href="<?= e(u('/assets/ikoner/ikon-256.png?v=' . e(\TDL_VERSION))) ?>">
<link rel="manifest" href="<?= e(u('/site.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(u('/assets/app.css?v=' . e(\TDL_VERSION))) ?>">
<style>
:root {
    --accent: <?= e($brand['farg_primar']) ?>;
    --ok: <?= e($brand['farg_accent']) ?>;
    --bak: <?= e($brand['farg_bakgrund']) ?>;
}
.kort-qr { max-width: 560px; margin: 0 auto; }
.antal-ruta { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 10px 0 4px; }
.antal-ruta .stor-siffra { font-size: 3.2rem; line-height: 1; }
.jamna { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.jamna form { margin: 0; }
.jamna button { width: 100%; }
@media (max-width: 380px) { .jamna { grid-template-columns: 1fr; } }
</style>
</head>
<body class="<?= e($brand['tema'] === 'ljus' ? 'tema-ljus' : 'tema-mork') ?>">
<?php $harLogotyp = ($brand['logotyp_fil'] ?? '') !== ''; ?>
<header class="topp">
    <a class="varumarke" href="<?= e(u('/')) ?>">
        <?php if ($harLogotyp): ?>
            <img class="varumarke-logotyp" src="<?= e(\TDL\Support\Varumarke::logotypUrl()) ?>" alt="<?= e($brand['namn']) ?>">
        <?php else: ?>
            <svg class="varumarke-ikon" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="<?= e($brand['farg_primar']) ?>"/><path d="M9 10h14M16 10v13" stroke="#fff" stroke-width="3.2" stroke-linecap="round"/><circle cx="23" cy="21" r="2.4" fill="<?= e($brand['farg_accent']) ?>"/></svg>
            <?= e($brand['namn']) ?>
        <?php endif; ?>
    </a>
    <?php if ($konto !== null): ?>
        <span class="topp-konto">
            <span><?= e($konto['namn']) ?></span>
            <form method="post" action="<?= e(u('/logout')) ?>"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="liten" type="submit">Logga ut</button></form>
        </span>
    <?php endif; ?>
</header>

<main class="innehall">
<div class="kort kort-qr">
    <h1 style="margin-top:0"><?= e($artikel['namn']) ?></h1>
    <p class="dampad" style="margin-top:0">
        <span class="mono"><?= e($artikel['artikelnummer']) ?></span> ·
        <?= e($artikel['enhet']) ?> ·
        <?= $arQr ? 'QR per enhet' : 'Saldo i antal' ?>
    </p>

    <?php if ($bilder !== []): ?>
        <div class="bild-ruta">
            <?php foreach ($bilder as $bild): ?>
                <?php $bildUrl = \TDL\Http\ArtikelQr::bildUrl($artikel['id'], $bild['fil'], $nonce); ?>
                <a href="<?= e($bildUrl) ?>" target="_blank" rel="noopener">
                    <img src="<?= e($bildUrl) ?>"
                         alt="<?= e($bild['text'] !== '' ? $bild['text'] : $artikel['namn']) ?>" loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="dampad">Inga bilder ännu.</p>
    <?php endif; ?>

    <?php if ($platser !== []): ?>
        <form method="get" action="<?= e(u('/artikel/' . e($artikel['id']))) ?>" style="margin-top:14px">
            <input type="hidden" name="n" value="<?= e($nonce) ?>">
            <input type="hidden" name="s" value="<?= e($signatur) ?>">
            <label for="plats">Arbetsställe</label>
            <select id="plats" name="plats" data-skicka-vid-andring>
                <?php foreach ($platser as $p): ?>
                    <option value="<?= e($p['id']) ?>" <?= $p['id'] === $vald_plats ? 'selected' : '' ?>><?= e($p['namn']) ?> (<?= e($p['kod']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <noscript><div class="actions"><button type="submit" class="liten">Visa</button></div></noscript>
        </form>

        <div class="antal-ruta">
            <span class="stor-siffra"><?= (int) $saldoHär ?></span>
            <span class="dampad"><?= e($artikel['enhet']) ?> på platsen</span>
        </div>

        <?php if ($konto === null): ?>
            <div class="flash info" style="margin-top:10px">
                <strong>Visa utan inloggning.</strong><br>
                <span class="falt-hjalp">Logga in för att lägga till eller ta bort antal.</span>
            </div>
            <div class="actions">
                <a class="knapp primar" href="<?= e(u('/login?ater=' . rawurlencode($bas . '&plats=' . $vald_plats))) ?>">Logga in</a>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(u('/artikel/' . (e($artikel['id'])) . '/antal')) ?>" style="margin-top:6px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="n" value="<?= e($nonce) ?>">
                <input type="hidden" name="s" value="<?= e($signatur) ?>">
                <input type="hidden" name="plats_id" value="<?= e($vald_plats) ?>">
                <input type="hidden" name="antal" value="1">
                <div class="jamna">
                    <button class="primar" type="submit" name="riktning" value="plus">+ 1 <?= e($artikel['enhet']) ?></button>
                    <button type="submit" name="riktning" value="minus">− 1 <?= e($artikel['enhet']) ?></button>
                </div>
            </form>

            <form method="post" action="<?= e(u('/artikel/' . (e($artikel['id'])) . '/antal')) ?>" style="margin-top:14px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="n" value="<?= e($nonce) ?>">
                <input type="hidden" name="s" value="<?= e($signatur) ?>">
                <input type="hidden" name="plats_id" value="<?= e($vald_plats) ?>">
                <label for="antal">Annat antal</label>
                <input id="antal" name="antal" type="number" min="1" max="9999" step="1" value="1" inputmode="numeric" required>
                <label for="kommentar">Kommentar (valfritt)</label>
                <input id="kommentar" name="kommentar" maxlength="200" placeholder="t.ex. påfyllning hylla 4">
                <div class="actions">
                    <button class="primar" type="submit" name="riktning" value="plus">Lägg till</button>
                    <button type="submit" name="riktning" value="minus">Ta bort</button>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="kort kort-qr" style="margin-top:12px">
    <h2 style="margin-top:0">Bilder</h2>
    <?php if ($konto !== null): ?>
        <form method="post" action="<?= e(u('/artikel/' . (e($artikel['id'])) . '/bild')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="n" value="<?= e($nonce) ?>">
            <input type="hidden" name="s" value="<?= e($signatur) ?>">
            <label for="bilder">Lägg till bild (JPG, PNG, WebP · max 8 MB)</label>
            <input id="bilder" name="bilder[]" type="file" accept="image/*" capture="environment" multiple>
            <label for="bildtext">Bildtext (valfritt)</label>
            <input id="bildtext" name="bildtext" maxlength="120" placeholder="t.ex. framsida, etikett">
            <div class="actions"><button type="submit" class="primar">Ladda upp</button></div>
        </form>
        <?php if ($bilder !== []): ?>
            <p class="falt-hjalp">Bilder tas bort från artikelkortet i appen.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="dampad" style="margin:0">Logga in för att lägga till bilder.</p>
    <?php endif; ?>
</div>

<p class="sidfot"><?= e($brand['namn']) ?> · <?= e($artikel['id']) ?></p>
</main>
</body>
</html>
