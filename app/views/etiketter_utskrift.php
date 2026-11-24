<?php
/**
 * Utskrift av enhetens QR-etiketter – på rulle (QL-500) eller A4.
 *
 * Används av två flöden:
 *   1. /artiklar/{id}/etiketter  – välj antal och storlek, skriv ut hyllkant
 *   2. /etiketter/utskrift       – etiketterna som just skapades när en
 *                                  artikel lades upp (personalens flöde)
 *
 * Måtten kommer från den artikelns sparade etikettstorlek (app/Support/Etikettmallar.php),
 * men kan alltid överstyras här utan att ändra artikeln.
 *
 * Alla QR ritas på klientsidan av qr-render.js – CSP tillåter inga inline-skript.
 *
 * @var array $artikel @var list<array> $rader @var string $rulle
 * @var array<string,string> $koder
 */
$brand = $varumarke ?? \TDL\Support\Varumarke::alla();

// Rullmått: hämtas ur den gemensamma mallen (app/Support/Etikettmallar.php).
// QL-500/550 skriver 60,9 mm brett (720 punkter à 300 dpi) – därför marginal.
$rullar = \TDL\Support\Etikettmallar::valbara();
$vald = \TDL\Support\Etikettmallar::tolka($rulle);
$arArk = strtoupper((string) $vald['mm']) === 'A4';
$arRulle = !$arArk;
// Aldrig bredare än skrivhuvudet – men smal tape ska förstås förbli smal.
// Skrivaren väljs i System (Brother QL-500, QL-800, Dymo, Zebra …).
$skrivare = \TDL\Support\Skrivare::aktiv();
$tryckbredd = \TDL\Support\Skrivare::tryckbredd($vald, $skrivare);
$skrivarvarning = \TDL\Support\Skrivare::varning($vald, $skrivare);

// Layout: "namn" = bara QR + artikelnamn (standard). "allt" lägger till
// artikelnummer, streckkod och enhetskod.
$layoutVal = get('layout', \TDL\Support\Etikettmallar::LAYOUT);
if (!isset(\TDL\Support\Etikettmallar::LAYOUTS[$layoutVal])) {
    $layoutVal = \TDL\Support\Etikettmallar::LAYOUT;
}
$visaAllt = $layoutVal === 'allt';

$layout = \TDL\Support\Skrivare::forEtikett($vald, $artikel['artikelnummer'], $arArk, $visaAllt, get('placering', 'auto'));
$placering = $layout['placering'];
$namnPt = $layout['namn_pt'];
$qrMm = $layout['qr'];
$streckbredd = $layout['streckbredd'];
$streckInfo = ['ok' => !$layout['kortad'], 'modul' => $layout['modul'], 'max_tecken' => 0, 'text' => $layout['full']];
$streckkod = $layout['kod'];
$streckElement = $layout['element'];

$antal = count($rader);
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etiketter – <?= e($artikel['namn']) ?></title>
<link rel="stylesheet" href="<?= e(tdl_tillgang('assets/app.css')) ?>">
<style>
    /* Sidan ställs in efter den fysiska etiketten – etikettskrivaren tar måttet härifrån */
    @page { size: <?= $vald['mm'] ?>; margin: 0; }
    <?php if ($arRulle): ?>
    /* QL-500 skriver 60,9 mm även om rullen är 62 mm bred – annars klipps högerkanten */
    @media print {
        .etikett { width: <?= rtrim(rtrim(number_format($tryckbredd, 1, '.', ''), '0'), '.') ?>mm !important; padding: 0.5mm !important; }
    }
    <?php endif; ?>

    body { background: #eef1f6; }

    .ark {
        display: flex; flex-direction: column; align-items: center;
        gap: 6px; padding: 14px;
    }
    .etikett {
        background: #fff; color: #000;
        <?php if ($arArk): ?>
        width: 60mm; min-height: 30mm; border: 1px dashed #9aa6b5;
        <?php else: ?>
        width: <?= (int) $vald['mm_bredd'] ?>mm; min-height: <?= (int) $vald['mm_hojd'] ?>mm;
        height: <?= $vald['mm_hojd'] >= 1000 ? 'auto' : (int) $vald['mm_hojd'] . 'mm' ?>;
        <?php endif; ?>
        overflow: hidden;
        display: flex;
        flex-direction: <?= ($placering === 'hoger' || $arArk) ? 'row' : 'column' ?>;
        align-items: center;
        justify-content: <?= ($placering === 'hoger' || $arArk) ? 'flex-start' : 'center' ?>;
        gap: <?= ($placering === 'hoger' || $arArk) ? '1.4mm' : '0.8mm' ?>;
        text-align: <?= ($placering === 'hoger' || $arArk) ? 'left' : 'center' ?>;
        padding: 1mm 1.2mm; box-sizing: border-box;
        break-inside: avoid; page-break-inside: avoid;
    }
    .etikett .qr { flex: 0 0 auto; width: <?= $qrMm ?>mm; height: <?= $qrMm ?>mm; line-height: 0; }
    <?php if ($placering === 'over' && !$arArk): ?>
    .etikett .qr { order: 2; }
    .etikett .info { order: 1; }
    <?php endif; ?>
    .etikett .qr svg { display: block; width: 100%; height: 100%; }

    .etikett .info { min-width: 0; flex: 1 1 auto; }
    .etikett .namn {
        /* Smal rulle (29 mm) behöver fler rader – annars blir namnet "Spik 90…" */
        display: -webkit-box;
        -webkit-line-clamp: <?= $arArk ? 2 : ($placering === 'hoger' ? ($vald['mm_bredd'] < 40 ? 4 : 3) : 3) ?>;
        -webkit-box-orient: vertical;
        overflow: hidden;
        font-weight: 800;
        /* Textstorleken räknas fram i Skrivare::forEtikett */
        font-size: <?= $namnPt ?>pt;
        line-height: 1.12;
        letter-spacing: -0.01em;
        overflow-wrap: anywhere; hyphens: auto;
    }
    .etikett .nr {
        display: block; font-family: ui-monospace, monospace;
        font-size: <?= $arArk ? '7pt' : round(max(5.6, min(11, $namnPt * 0.7)), 1) . 'pt' ?>;
        font-weight: 600;
    }
    .etikett .streckkod {
        display: flex; align-items: stretch;
        height: <?= $arArk ? '3.5mm' : round(max(2.2, min(6, $vald['mm_hojd'] * 0.09)), 1) . 'mm' ?>;
        margin-top: 0.6mm; width: 100%;
    }
    .etikett .streckkod i { display: block; min-width: 0; }
    .etikett .streckkod i.svart { background: #000; }
    .etikett .strecktext {
        display: block; font-family: ui-monospace, monospace;
        font-size: 5pt; color: #333;
        margin-top: 0.6mm;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .etikett .foretag {
        display: block; font-size: 5pt; color: #888;
        margin-top: 0.6mm;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    @media print {
        .inget-utskrift { display: none !important; }
        body { background: #fff; margin: 0; }
        .ark { padding: 0; gap: 0; }
        .etikett { border: none; }
    }
</style>
</head>
<?php // ?skriv=1 (från "Skriv ut"-knappen i listan) öppnar utskriftsdialogen direkt ?>
<body data-skriv-ut<?= get('skriv') === '1' ? ' data-skriv-direkt' : '' ?>>

<div class="actions inget-utskrift" style="padding:12px 16px;flex-wrap:wrap">
    <button type="button" data-skriv-ut class="primar">Skriv ut</button>
    <a class="knapp" href="<?= e(u('/artiklar/' . e($artikel['id']))) ?>">Tillbaka till artikeln</a>
    <a class="knapp" href="?<?= e(http_build_query(['artikel' => $artikel['id'], 'koder' => implode(',', array_column($rader, 'id')), 'rulle' => 'A4'])) ?>">A4-ark i stället</a>
</div>

<?php // Storlek: välj den som står på etikettens baksida. Artikeln har ett förval. ?>
<form method="get" class="inget-utskrift"
      style="padding:0 16px 10px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="artikel" value="<?= e($artikel['id']) ?>">
    <input type="hidden" name="koder" value="<?= e(implode(',', array_column($rader, 'id'))) ?>">
    <label for="rulle" style="margin:0">Etikettstorlek</label>
    <select id="rulle" name="rulle" data-skicka-vid-andring style="max-width:340px">
        <?php foreach (\TDL\Support\Etikettmallar::grupper() as $grupp => $poster): ?>
            <optgroup label="<?= e($grupp) ?>">
                <?php foreach ($poster as $nyckel => $r): ?>
                    <option value="<?= e($nyckel) ?>"<?= $nyckel === $rulle ? ' selected' : '' ?>><?= e($r['namn']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
        <?php if (!empty($vald['egen'])): ?>
            <option value="<?= e($rulle) ?>" selected><?= e($vald['namn']) ?></option>
        <?php endif; ?>
    </select>
    <span class="dampad" style="margin:0">
        <?= $antal ?> <?= $antal === 1 ? 'etikett' : 'etiketter' ?> · QR <?= rtrim(rtrim(number_format($qrMm, 1, ',', ''), '0'), ',') ?> mm · <?= $arArk ? 'A4' : 'rulle' ?>
    </span>
</form>

<?php // Egna mått: kontinuerlig tape klipps i valfri längd (25–1000 mm på QL-500) ?>
<form method="get" class="inget-utskrift"
      style="padding:0 16px 10px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="artikel" value="<?= e($artikel['id']) ?>">
    <input type="hidden" name="koder" value="<?= e(implode(',', array_column($rader, 'id'))) ?>">
    <div>
        <label for="egen_bredd" style="margin:0">Eget mått (mm)</label>
        <div style="display:flex;gap:6px;align-items:center">
            <input id="egen_bredd" name="bredd" type="number" min="20" max="<?= (int) $skrivare['max_etikettbredd'] ?>" value="<?= $arArk ? (int) $skrivare['max_etikettbredd'] : (int) $vald['mm_bredd'] ?>" style="width:70px;min-height:40px">
            <span class="dampad">×</span>
            <input id="egen_hojd" name="hojd" type="number" min="20" max="<?= (int) $skrivare['max_hojd'] ?>" value="<?= $arArk ? 40 : (int) $vald['mm_hojd'] ?>" style="width:80px;min-height:40px">
            <button type="submit">Använd</button>
        </div>
    </div>
    <span class="dampad" style="margin:0;max-width:460px">
        <?= e($skrivare['namn']) ?> skriver <?= e(\TDL\Support\Skrivare::tal((float) $skrivare['bredd'])) ?> mm brett.
        Bredare etiketter klipps, smalare skrivs i sin egen bredd.
        <a href="<?= e(u('/admin/system')) ?>">Byt skrivare</a>
    </span>
</form>

<?php if ($skrivarvarning !== ''): ?>
<div class="flash fel inget-utskrift" style="margin:0 16px 10px" role="status">
    <?= e($skrivarvarning) ?>
</div>
<?php endif; ?>

<div class="ark" id="arket">
<?php foreach ($rader as $en): ?>
    <div class="etikett">
        <?php // Felkorrigering L = färre moduler = grövre rutor = lättare att skanna på litet tryck ?>
        <div class="qr" data-kod="<?= e($en['id']) ?>" data-fel="L" data-cell="<?= $arArk ? '3' : '4' ?>" data-storlek="0"></div>
        <div class="info">
            <span class="namn"><?= e($artikel['namn']) ?></span>
            <?php if ($visaAllt): ?>
                <?php if ($arArk): ?>
                    <span class="nr"><?= e($artikel['artikelnummer']) ?></span>
                <?php endif; ?>
                <?php if ($streckkod !== '' && !$arArk): ?>
                    <?php // Code 39 – streck och mellanrum med relativ bredd så koden passar etiketten ?>
                    <span class="streckkod" aria-hidden="true"><?php
                        foreach ($streckElement as $el) {
                            echo '<i class="' . ($el['streck'] ? 'svart' : 'tom') . '" style="flex:' . $el['enheter'] . ' 1 0"></i>';
                        }
                    ?></span>
                    <span class="strecktext"><?= e($streckkod) ?></span>
                    <?php if (!$streckInfo['ok'] && $streckkod !== ''): ?>
                        <?php // Bara på skärmen: tala om att streckkoden är kortad, QR:en är komplett ?>
                        <span class="strecknotis inget-utskrift">Streckkoden är kortad till <?= e($streckkod) ?>
                            (<?= e(number_format($layout['modul'], 2, ',', '')) ?> mm modul). QR-koden gäller hela numret.</span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($koder[$en['id']])): ?>
                    <span class="strecktext"><?= e($koder[$en['id']]) ?></span>
                <?php endif; ?>
            <?php endif; ?>
            <span class="foretag"><?= e($brand['namn']) ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script src="<?= e(u('/assets/qrcode.js')) ?>"></script>
<script src="<?= e(u('/assets/qr-render.js')) ?>"></script>
</body>
</html>
