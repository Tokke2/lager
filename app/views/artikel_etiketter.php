<?php
/**
 * Utskrift av artikel-QR-etiketter på etikettskrivare (rulle).
 *
 * Etiketten klistras på hyllkanten eller lådan och visar artikelns namn
 * tydligt. QR-koden öppnar artikelns publika sida (bilder + snabb +/-).
 *
 * Etikettstorleken kommer från artikelns sparade val (?rulle=62x29 kan
 * överstyra). Måtten finns i app/Support/Etikettmallar.php – för Brother
 * QL-500 gäller 60,9 mm tryckbredd och högst 59 mm höjd per etikett.
 * Standard är 62x29 (hyllkant); A4 ger 3 kolumner på vanlig skrivare.
 *
 * All QR ritas på klientsidan av qr-render.js – CSP tillåter inga inline-skript.
 *
 * @var array $artikel @var int $antal @var string $url @var ?string $bildUrl
 * @var string $rulle @var array $varumarke
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

// Layout: "namn" = bara QR + artikelnamn (standard, lugnast på hyllan).
// "allt" = även artikelnummer, streckkod och enhetskod för den som vill ha det.
$layoutVal = get('layout', \TDL\Support\Etikettmallar::LAYOUT);
if (!isset(\TDL\Support\Etikettmallar::LAYOUTS[$layoutVal])) {
    $layoutVal = \TDL\Support\Etikettmallar::LAYOUT;
}
$visaAllt = $layoutVal === 'allt';

// ---- QR, artikelnamn och (valfritt) streckkod ----------------------------
// Skrivare::forEtikett räknar fram storlek och placering på ett ställe.
$layout = \TDL\Support\Skrivare::forEtikett($vald, $artikel['artikelnummer'], $arArk, $visaAllt, get('placering', 'auto'));
$placering = $layout['placering'];
$namnPt = $layout['namn_pt'];
$qrMm = $layout['qr'];
$streckbredd = $layout['streckbredd'];
$streckInfo = ['ok' => !$layout['kortad'], 'modul' => $layout['modul'], 'max_tecken' => 0, 'text' => $layout['full']];
$streckkod = $layout['kod'];
$streckElement = $layout['element'];



?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Artikel-QR – <?= e($artikel['namn']) ?></title>
<link rel="stylesheet" href="<?= e(u('/assets/app.css?v=' . e(\TDL_VERSION))) ?>">
<style>
    /* Sidan ställs in efter den fysiska etiketten – etikettskrivaren tar måttet härifrån */
    @page { size: <?= $vald['mm'] ?>; margin: 0; }
    <?php if ($arRulle): ?>
    /*
     * Blockera aldrig bredare än skrivhuvudet: Brother QL-500 = 60,9 mm,
     * QL-800 = 58 mm, Dymo = 56 mm, Zebra 4 tum = 104 mm. Skrivaren väljs i System.
     */
    @media print {
        .ark { width: <?= \TDL\Support\Skrivare::mm($tryckbredd) ?>mm !important; margin: 0 auto !important; }
        .etikett { width: <?= \TDL\Support\Skrivare::mm($tryckbredd) ?>mm !important; padding-left: 0.5mm !important; padding-right: 0.5mm !important; }
    }
    <?php endif; ?>

    body {
        background: #fff; color: #000; margin: 0;
        font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }

    /* Skärmförhandsvisning: visa etiketterna i exakt tryckstorlek */
    .ark {
        display: grid;
        grid-template-columns: repeat(<?= (int) $vald['kolumner'] ?>, 1fr);
        gap: <?= $arArk ? '4mm' : '0' ?>;
        <?= $arArk ? '' : 'width: ' . $vald['mm_bredd'] . 'mm; margin: 0 auto; background: #fff; box-shadow: 0 0 0 1px #d5dbe4;' ?>
    }
    @media print {
        .ark { width: auto; margin: 0; box-shadow: none; }
    }

    .etikett {
        display: flex;
        flex-direction: <?= ($placering === 'hoger' || $arArk) ? 'row' : 'column' ?>;
        align-items: center;
        /* Staplad layout: 'safe center' mittställer utan att kunna trycka ut
           innehåll över kanten när det är högre än etiketten (då vore det
           bättre att det klipps i nederkant än i båda kanter). */
        justify-content: <?= ($placering === 'hoger' || $arArk) ? 'flex-start' : 'safe center' ?>;
        gap: <?= $arArk ? '1.5mm' : ($placering === 'hoger' ? '1.5mm' : '0.8mm') ?>;
        text-align: <?= ($placering === 'hoger' || $arArk) ? 'left' : 'center' ?>;
        padding: <?= $arArk ? '3mm' : '1mm 1.5mm' ?>;
        box-sizing: border-box;
        <?= $arArk
            ? 'border: 1px dashed #8892a0; border-radius: 3mm; min-height: 42mm;'
            : 'height: ' . explode(' ', $vald['mm'])[1] . '; border-bottom: 1px dotted #ddd;' ?>
        break-inside: avoid; page-break-inside: avoid;
        background: #fff;
    }

    /* QR-koden får exakt mått i millimeter – annars blir SVG:n noll bred.
       Måttet är uträknat ovan (högst 55 % av höjden, 45 % av bredden). */
    .etikett .qr { flex: 0 0 auto; width: <?= $qrMm ?>mm; height: <?= $qrMm ?>mm; line-height: 0; }
    .etikett .qr svg { display: block; width: 100%; height: 100%; }

    /* Bredvid QR: texten får ta resten av bredden.
       Under QR: texten får INTE växa – då trycks innehållet uppåt och
       etiketten ser tom ut nedtill i stället för centrerad. */
    .etikett .info { min-width: 0; flex: <?= ($placering === 'hoger' || $arArk) ? '1 1 auto' : '0 0 auto' ?>; }
    <?php if ($placering === 'over' && !$arArk): ?>
    /* Namnet överst: låt textblocket komma före QR-koden i staplad layout */
    .etikett .qr { order: 2; }
    .etikett .info { order: 1; }
    <?php endif; ?>

    /* Artikelnamnet är det viktigaste – det ska läsas på håll */
    .etikett .namn {
        /* Smal rulle (29 mm) behöver fler rader – annars blir namnet "Spik 90…" */
        display: -webkit-box;
        -webkit-line-clamp: <?= $arArk ? 2 : ($placering === 'hoger' ? ($vald['mm_bredd'] < 40 ? 4 : 3) : 3) ?>;
        -webkit-box-orient: vertical;
        overflow: hidden;
        font-weight: 800;
        /* Textstorleken följer etikettens höjd: 29 mm -> 8pt, 45 mm -> 10,5pt */
        font-size: <?= $arArk ? '8.5pt' : round(min(11, max(6.5, 0.19 * $vald['mm_hojd'])), 1) . 'pt' ?>;
        line-height: 1.12;
        letter-spacing: -0.01em;
        overflow-wrap: anywhere; hyphens: auto;
    }

    /* Hyllan: liten men tydlig rad direkt under namnet */
    .etikett .hylla {
        display: block;
        font-size: <?= $arArk ? '6.5pt' : '5.8pt' ?>;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #333;
        margin-top: 0.4mm;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .etikett .nr {
        font-family: ui-monospace, "SF Mono", Consolas, monospace;
        font-size: <?= $arArk ? '7.5pt' : round(max(6, min(12, $namnPt * 0.62)), 1) . 'pt' ?>;
        font-weight: 600;
        color: #222; display: block; margin-top: 0.6mm;
        letter-spacing: 0.02em;
    }

    /* Streckkoden fyller etikettens bredd – modulerna delar lika på utrymmet */
    .etikett .streckkod {
        display: flex; align-items: stretch;
        height: <?= $arArk ? '3.5mm' : round(max(2.2, min(6, $vald['mm_hojd'] * 0.09)), 1) . 'mm' ?>;
        margin-top: 0.6mm; width: 100%;
    }
    .etikett .streckkod i { display: block; min-width: 0; }
    .etikett .streckkod i.svart { background: #000; }
    .etikett .strecktext {
        display: block; font-family: ui-monospace, "SF Mono", Consolas, monospace;
        font-size: 5pt; letter-spacing: 0.06em; color: #333; margin-top: 0.2mm;
    }

    .etikett .strecknotis {
        display: block; font-size: 4.6pt; color: #8a5a00; margin-top: 0.2mm;
    }

    .etikett .plats {
        font-size: <?= $arArk ? '6.5pt' : '5.5pt' ?>;
        color: #444; display: block; margin-top: 0.4mm;
    }

    /* Företagsnamnet är valfritt – döljs automatiskt om det inte får plats */
    .etikett .enhetskod {
        display: block;
        font-family: ui-monospace, "SF Mono", Consolas, monospace;
        font-size: <?= $arArk ? '6.5pt' : round(max(4.6, min(8, $namnPt * 0.5)), 1) . 'pt' ?>;
        color: #000; letter-spacing: 0.02em; margin-top: 0.2mm;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .etikett .foretag {
        text-align: inherit;
        display: block;
        font-size: 5pt; color: #888;
        margin-top: 0.6mm;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* Utskriftsvalen: två rader som håller ihop även på mobil */
    .etikettval { padding: 0 16px 10px; }
    .etikettval-rad {
        display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
        margin-bottom: 8px;
    }
    .etikettval-rad label { margin: 0; }

    @media print {
        .inget-utskrift { display: none !important; }
        body { margin: 0; }
    }
</style>
</head>
<?php // ?skriv=1 (från "Skriv ut"-knappen i listan) öppnar utskriftsdialogen direkt ?>
<body data-skriv-ut<?= get('skriv') === '1' ? ' data-skriv-direkt' : '' ?>>

<div class="actions inget-utskrift" style="padding:12px 16px;flex-wrap:wrap">
    <button type="button" data-skriv-ut class="primar">Skriv ut</button>
    <a class="knapp" href="<?= e(u('/artiklar/' . e($artikel['id']))) ?>">Tillbaka till artikeln</a>
    <a class="knapp" href="<?= e(u('/artiklar/' . (e($artikel['id'])) . '/etiketter?antal=' . ((int) $antal) . '&rulle=A4')) ?>">A4-ark i stället</a>
</div>

<!-- Rullbredd: välj den som står på etikettens baksida -->
<form method="get" class="inget-utskrift etikettval">
    <input type="hidden" name="antal" value="<?= (int) $antal ?>">
    <div class="etikettval-rad">
    <label for="rulle" style="margin:0">Etikettstorlek</label>
    <select id="rulle" name="rulle" data-skicka-vid-andring style="max-width:300px">
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
    </div>
    </div>
    <div class="etikettval-rad">
    <label for="layout" style="margin:0">Innehåll</label>
    <select id="layout" name="layout" data-skicka-vid-andring style="max-width:250px">
        <?php foreach (\TDL\Support\Etikettmallar::LAYOUTS as $nyckel => $text): ?>
            <option value="<?= e($nyckel) ?>"<?= $nyckel === $layoutVal ? ' selected' : '' ?>><?= e($text) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($layoutVal === 'namn' && !$arArk): ?>
        <label for="placering" style="margin:0">Namnet sitter</label>
        <select id="placering" name="placering" data-skicka-vid-andring style="max-width:190px">
            <?php foreach (['auto' => 'Automatiskt', 'hoger' => 'Bredvid QR', 'under' => 'Under QR', 'over' => 'Över QR'] as $nyckel => $text): ?>
                <option value="<?= e($nyckel) ?>"<?= $nyckel === $placering ? ' selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <span class="dampad" style="margin:0">
        <?= (int) $antal ?> <?= (int) $antal === 1 ? 'etikett' : 'etiketter' ?> · QR <?= rtrim(rtrim(number_format($qrMm, 1, ',', ''), '0'), ',') ?> mm · <?= $arArk ? '3 per rad' : '1 per rad' ?>
    </span>
    </div>
</form>

<?php // Egna mått: kontinuerlig tape klipps i valfri längd (25–1000 mm på QL-500) ?>
<form method="get" class="inget-utskrift"
      style="padding:0 16px 10px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="antal" value="<?= (int) $antal ?>">
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
<?php for ($i = 0; $i < $antal; $i++): ?>
    <div class="etikett">
        <?php // Felkorrigering L = färre moduler = grövre rutor = lättare att skanna på litet tryck ?>
        <div class="qr" data-url="<?= e($url) ?>" data-fel="L" data-cell="<?= $arArk ? '3' : '4' ?>" data-storlek="0"></div>
        <div class="info">
            <span class="namn"><?= e($artikel['namn']) ?></span>
            <?php if (trim((string) ($artikel['hylla'] ?? '')) !== ''): ?>
                <?php // Hyllan är den uppgift man behöver vid påfyllning – den får egen rad ?>
                <span class="hylla"><?= e($artikel['hylla']) ?></span>
            <?php endif; ?>
            <?php if ($visaAllt): ?>
                <?php if ($streckkod === '' || $arArk): ?>
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
                <?php
                // Läsbar kod: enhetens kod om den finns, annars artikelnumret.
                $radKod = $enhetsKoder[$i] ?? ($streckkod !== '' ? $streckkod : $artikel['artikelnummer']);
                ?>
                <span class="enhetskod"><?= e($radKod) ?></span>
            <?php endif; ?>
            <span class="foretag"><?= e($brand['namn']) ?></span>
        </div>
    </div>
<?php endfor; ?>
</div>

<script src="<?= e(u('/assets/qrcode.js')) ?>"></script>
<script src="<?= e(u('/assets/qr-render.js')) ?>"></script>
</body>
</html>
