<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titel) ?></title>
<style>
    /* Utskrift: lapparna ska gå att klippa isär och tejpa på hyllan */
    @page { margin: 10mm; }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; color: #111; background: #fff; }
    h1 { font-size: 16pt; margin: 0 0 6mm; }
    .hjalp { font-size: 9pt; color: #555; margin: 0 0 8mm; }
    .ark { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; }
    .lapp {
        border: 1px dashed #999; border-radius: 3mm; padding: 4mm;
        display: flex; flex-direction: column; align-items: center; text-align: center;
        break-inside: avoid; page-break-inside: avoid;
    }
    /* Appens mörka tema sätter ljus text; lappen är vit, så all text måste
       få egen färg – annars blir koordinaten osynlig. */
    .lapp { background: #fff; color: #111; }
    .lapp .kod { font-size: 22pt; font-weight: 800; letter-spacing: .5pt; margin: 2mm 0 1mm; color: #000; }
    .lapp .zon { font-size: 9pt; color: #555; text-transform: uppercase; letter-spacing: 1pt; }
    /* QR:en ritas som en SVG med fast storlek. Utan låst höjd på behållaren
       spiller den över texten under lappen. */
    .lapp .qr {
        width: 42mm; height: 42mm; flex: 0 0 42mm;
        display: flex; align-items: center; justify-content: center;
        overflow: hidden;
    }
    .lapp .qr svg, .lapp .qr img, .lapp .qr canvas {
        width: 42mm !important; height: 42mm !important;
        display: block;
    }
    .lapp .rad { font-size: 9pt; color: #333; margin-top: 1mm; }
    .lapp .qr { background: #fff; }
    .knappar { margin-bottom: 8mm; }
    @media print { .knappar { display: none; } }
</style>
</head>
<body>
<?php /* Ingen onclick: CSP är script-src 'self'. Utskriften startas med
         en vanlig länk (?skriv=1) och ett skript i app.js. */ ?>
<div class="knappar">
    <a class="knapp primar" href="?skriv=1" data-skriv-ut>Skriv ut</a>
    <a class="knapp" href="<?= e(u('/lagerplatser/' . e($zon['id']))) ?>">Tillbaka</a>
</div>
<h1>Hyllappar – zon <?= e($zon['kod']) ?> <?= e($zon['namn']) ?></h1>
<p class="hjalp">Klipp isär och sätt på respektive hylla. Lappen innehåller hyllplatsens QR-kod –
    skanna den med appen under <em>Skanna</em> för att se vad som står på hyllan.</p>

<div class="ark">
    <?php foreach ($hyllplatser as $h): ?>
        <div class="lapp">
            <div class="zon">Zon <?= e($zon['kod']) ?> · <?= e($zon['namn']) ?></div>
            <div class="kod"><?= e($zon['kod']) ?>-<?= (int) $h['rad'] ?>-<?= (int) $h['fack'] ?></div>
            <div class="qr" data-url="<?= e(\TDL\Http\ArtikelQr::qrInnehall((string) $h['qr'])) ?>" data-storlek="200" data-cell="5"></div>
            <div class="rad">Rad <?= (int) $h['rad'] ?> · Fack <?= (int) $h['fack'] ?></div>
            <div class="rad"><?= e(\TDL\Support\Koder::visa($h['qr'])) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<script src="<?= e(u('/assets/qrcode.js')) ?>"></script>
<script src="<?= e(u('/assets/app.js?v=' . e(\TDL_VERSION))) ?>"></script>
<script src="<?= e(u('/assets/qr-render.js')) ?>"></script>
</body>
</html>
