<?php
/**
 * Arbetsplatsetikett (platsens QR).
 *
 * Klistras vid hyllan/ingången till ett arbetsställe. Skannas i appen och
 * väljer då arbetsställe – den flyttar aldrig lager.
 *
 * Skrivs ut på samma rullar som artiklarna: en egen etikettstorlek kan anges
 * med ?rulle=… eller ?bredd=&hojd=, annars används standardrullen.
 *
 * @var array $plats @var string $rulle @var array $varumarke
 */
$brand = $varumarke ?? \TDL\Support\Varumarke::alla();
$skrivare = \TDL\Support\Skrivare::aktiv();
$vald = \TDL\Support\Etikettmallar::tolka($rulle ?? '');
$arArk = strtoupper((string) $vald['mm']) === 'A4';
$tryckbredd = \TDL\Support\Skrivare::tryckbredd($vald, $skrivare);
$skrivarvarning = \TDL\Support\Skrivare::varning($vald, $skrivare);

// På en arbetsplatsetikett finns ingen streckkod, så QR:en får ta plats.
// Den kan inte vara bredare än etiketten (minus marginal) men får däremot
// gärna växa på höjden – en 29 × 90-rulle ger en stor, lättskannad QR.
// Textens höjd dras av innan QR:en får resten – annars spiller etiketten
// över på nästa och QR:en klipps (en klippt QR går inte att skanna).
// Raderna räknas med sina riktiga radavstånd: 1 pt = 0,353 mm.
// paddingen ligger inne i höjden (box-sizing: border-box) – räkna den en gång
$padMm = 2 * max(1, round($vald['mm_hojd'] * 0.04, 1));
$namnMm = $namnPt * 1.05 * 0.353;
$kodMm = max(6, $namnPt * 0.62) * 1.1 * 0.353;
$regelMm = max(5, $namnPt * 0.42) * 1.2 * 0.353 * 2;      // två rader
$textMm = $namnMm + $kodMm + $regelMm + 1.6 + $padMm;      // + luft mellan raderna

$qrTakHojd = max(12.0, round($vald['mm_hojd'] - $textMm, 1));
$qrMm = $arArk
    ? 60.0
    : max(12.0, min(
        (float) rtrim($vald['qr'], 'mm'),
        $qrTakHojd,
        $vald['mm_bredd'] > 40 ? round($vald['mm_bredd'] * 0.9, 1) : round($vald['mm_bredd'] - 4, 1)
    ));

$namnPt = $arArk ? 14 : round(min(16, max(7, 0.16 * $vald['mm_hojd'])), 1);
$kod = \TDL\Support\Koder::visa($plats['qr']);
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Arbetsplatsetikett – <?= e($plats['namn']) ?></title>
<link rel="stylesheet" href="<?= e(u('/assets/app.css?v=' . e(\TDL_VERSION))) ?>">
<style>
    @page { size: <?= $vald['mm'] ?>; margin: 0; }

    body {
        background: #eef1f6; color: #000; margin: 0;
        font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }

    .platsetikett {
        background: #fff; color: #000;
        <?php if ($arArk): ?>
        max-width: 120mm; margin: 16px auto; padding: 8mm; border: 1px dashed #8892a0;
        <?php else: ?>
        width: <?= (int) $vald['mm_bredd'] ?>mm; margin: 16px auto;
        height: <?= (int) $vald['mm_hojd'] ?>mm; box-sizing: border-box; overflow: hidden;
        <?php if ($arArk): ?>display: flex; flex-direction: column; justify-content: center;<?php endif; ?>
        padding: <?= max(1, round($vald['mm_hojd'] * 0.04, 1)) ?>mm;
        <?php endif; ?>
        text-align: center;
    }

    /* QR:ens mått sätts i millimeter och får aldrig krympa – en ihoptryckt QR
       går inte att skanna. Textblocket under mäts av ett litet skript. */
    .platsetikett .qr {
        flex: 0 0 auto;
        width: <?= $qrMm ?>mm; height: <?= $qrMm ?>mm; margin: 0 auto; line-height: 0;
    }
    .platsetikett .qr svg { display: block; width: 100%; height: 100%; }

    .platsetikett h1 {
        font-size: <?= $namnPt ?>pt; margin: 0.8mm 0 0; color: #000; line-height: 1.05;
        overflow-wrap: anywhere; hyphens: auto;
    }
    .platsetikett .nr {
        font-family: ui-monospace, "SF Mono", Consolas, monospace;
        font-size: <?= round(max(6, $namnPt * 0.62), 1) ?>pt;
        letter-spacing: 0.04em; margin: 0.5mm 0 0; line-height: 1.1;
    }
    .platsetikett .regel {
        font-size: <?= round(max(5, $namnPt * 0.42), 1) ?>pt;
        margin: 0.8mm 0 0; color: #444; line-height: 1.2;
    }

    @media print {
        body { background: #fff; }
        .inget-utskrift { display: none !important; }
        .platsetikett {
            <?php if ($arArk): ?>margin: 0;<?php else: ?>margin: 0 auto;<?php endif; ?>
            border: none; outline: none;
            width: <?= \TDL\Support\Skrivare::mm($tryckbredd) ?>mm !important;
        }
    }
</style>
</head>
<?php // ?skriv=1 öppnar utskriftsdialogen direkt – samma vana som artiklapparna ?>
<body<?= get('skriv') === '1' ? ' data-skriv-direkt' : '' ?>>

<div class="actions inget-utskrift" style="padding:12px 16px;flex-wrap:wrap">
    <button type="button" data-skriv-ut class="primar">Skriv ut</button>
    <a class="knapp" href="<?= e(u('/etiketter')) ?>">Tillbaka</a>
    <a class="knapp" href="<?= e(u('/platsetikett?kod=' . (e(urlencode($plats['qr']))) . '&rulle=A4')) ?>">A4-ark i stället</a>
</div>

<form method="get" class="inget-utskrift"
      style="padding:0 16px 10px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="kod" value="<?= e($plats['qr']) ?>">
    <label for="rulle" style="margin:0">Etikettstorlek</label>
    <select id="rulle" name="rulle" data-skicka-vid-andring style="max-width:340px">
        <?php foreach (\TDL\Support\Etikettmallar::grupper() as $grupp => $poster): ?>
            <optgroup label="<?= e($grupp) ?>">
                <?php foreach ($poster as $nyckel => $r): ?>
                    <option value="<?= e($nyckel) ?>"<?= $nyckel === ($rulle ?? '') ? ' selected' : '' ?>><?= e($r['namn']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>
    <span class="dampad" style="margin:0">
        QR <?= \TDL\Support\Skrivare::tal($qrMm) ?> mm · <?= e($skrivare['kort']) ?> ·
        <?= e(\TDL\Support\Skrivare::tal($tryckbredd)) ?> mm
    </span>
</form>

<?php if ($skrivarvarning !== ''): ?>
    <div class="flash fel inget-utskrift" style="margin:0 16px 10px" role="status"><?= e($skrivarvarning) ?></div>
<?php endif; ?>

<div class="platsetikett">
    <div class="qr" data-kod="<?= e($plats['qr']) ?>" data-fel="L" data-cell="4" data-storlek="0"></div>
    <h1><?= e($plats['namn']) ?></h1>
    <div class="nr"><?= e($kod) ?></div>
    <p class="regel">
        <strong>Väljer arbetsställe i appen.</strong><br>
        Flyttar inte lager.
    </p>
</div>

<script src="<?= e(u('/assets/qrcode.js')) ?>"></script>
<script src="<?= e(u('/assets/qr-render.js')) ?>"></script>
<?php if (!$arArk): ?>
<script src="<?= e(u('/assets/platsetikett.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
