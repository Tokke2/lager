<?php
/**
 * Sidans skal: head, sidhuvud, sidomeny och innehållsram.
 *
 * @var string $titel @var array|null $konto @var string $aktiv @var array $flash
 */

$csrf = \TDL\Support\Sakerhet::csrfToken();
$varumarke = \TDL\Support\Varumarke::alla();
$tema = $varumarke['tema'] === 'ljus' ? 'tema-ljus' : 'tema-mork';
// v('namn') faller tillbaka på "Lager" tills kunden angett sitt namn.
$foretagsnamn = \TDL\Support\Varumarke::v('namn');
$harEgenLogotyp = $varumarke['logotyp_fil'] !== '';

require_once __DIR__ . '/ikoner.php';

/**
 * Menyn är grupperad efter vad man gör – inte efter vilken tabell datan
 * ligger i. Samma grupper används i sidomenyn på datorn och i menyn på
 * telefonen, så det finns bara ett ställe att ändra på.
 */
$menyGrupper = [
    'Dagligt arbete' => [
        ['id' => 'start', 'href' => '/', 'text' => 'Start', 'ikon' => 'hus'],
        ['id' => 'oversikt', 'href' => '/oversikt', 'text' => 'Översikt', 'ikon' => 'oversikt'],
        ['id' => 'inleverans', 'href' => '/inleverans', 'text' => 'Inleverans', 'ikon' => 'in'],
        ['id' => 'uttag', 'href' => '/uttag', 'text' => 'Uttag', 'ikon' => 'ut'],
        ['id' => 'plocklista', 'href' => '/plocklista', 'text' => 'Plocklista', 'ikon' => 'lista'],
        ['id' => 'overforing', 'href' => '/overforing', 'text' => 'Överför', 'ikon' => 'byt'],
        ['id' => 'skanna', 'href' => '/skanna', 'text' => 'Skanna', 'ikon' => 'skanna'],
        ['id' => 'inventering', 'href' => '/inventering', 'text' => 'Inventering', 'ikon' => 'bock'],
    ],
    'Lager och artiklar' => [
        ['id' => 'artiklar', 'href' => '/artiklar', 'text' => 'Artiklar', 'ikon' => 'artiklar'],
        ['id' => 'miniminiva', 'href' => '/miniminiva', 'text' => 'Under miniminivå', 'ikon' => 'varning'],
        ['id' => 'lagerkarta', 'href' => '/lagerkarta', 'text' => 'Lagerkarta', 'ikon' => 'lagerkarta'],
        ['id' => 'lokalplan', 'href' => '/lokalplan', 'text' => 'Lokalplan', 'ikon' => 'lokalplan'],
        ['id' => 'historik', 'href' => '/historik', 'text' => 'Historik', 'ikon' => 'historik'],
        ['id' => 'arkiv', 'href' => '/arkiv', 'text' => 'Arkiv', 'ikon' => 'arkiv'],
    ],
    'Etiketter' => [
        ['id' => 'etiketter', 'href' => '/etiketter', 'text' => 'Skriv ut etiketter', 'ikon' => 'etikett'],
        ['id' => 'platsetikett', 'href' => '/platsetikett', 'text' => 'Arbetsställets lapp', 'ikon' => 'platsetikett'],
    ],
];

$adminNav = [
    ['id' => 'lagerplats', 'href' => '/lagerplatser', 'text' => 'Lagerplatser', 'ikon' => 'lagerkarta'],
    ['id' => 'platser', 'href' => '/platser', 'text' => 'Arbetsställen', 'ikon' => 'nal'],
    ['id' => 'konton', 'href' => '/konton', 'text' => 'Konton', 'ikon' => 'konton'],
    ['id' => 'varumarke', 'href' => '/admin/varumarke', 'text' => 'Varumärke', 'ikon' => 'diamant'],
    ['id' => 'halsa', 'href' => '/admin/halsa', 'text' => 'Hälsokontroll', 'ikon' => 'halsa'],
    ['id' => 'system', 'href' => '/admin/system', 'text' => 'System', 'ikon' => 'kugghjul'],
];

// Hälsokontrollen (förslag 20): en diskret prick vid System när något behöver
// åtgärdas. Svaret cachas i 60 s – inget tungt per sidladdning.
$halsoFel = '';
if (($konto['roll'] ?? '') === 'admin') {
    try {
        $halsoSvar = \TDL\Support\HalsoKontroll::snabb();
        if (($halsoSvar['fel'] ?? 0) > 0) {
            $halsoFel = (string) $halsoSvar['fel'];
        }
    } catch (\Throwable $e) {
        $halsoFel = '';
    }
}

// Arbetsstället syns alltid i huvudet – annars tar man lätt emot varor på
// fel ställe utan att märka det.
$valtStalle = null;
try {
    $valtStalle = \TDL\Support\Arbetsstalle::aktivt();
} catch (\Throwable $e) {
    $valtStalle = null;
}

/** En menylänk med ikon, aktiv markering och rätt aria-attribut. */
$menyLank = static function (array $n, bool $admin = false) use ($aktiv, $halsoFel): string {
    $arAktiv = $aktiv === $n['id'];
    $klass = 'meny-lank' . ($admin ? ' meny-lank-admin' : '') . ($arAktiv ? ' aktiv' : '');
    $ut = '<a class="' . $klass . '" href="' . e(u($n['href'])) . '"'
        . ($arAktiv ? ' aria-current="page"' : '') . '>'
        . tdl_ikon($n['ikon'])
        . '<span class="meny-text">' . e($n['text']) . '</span>';
    if ($admin && $n['id'] === 'system' && $halsoFel !== '') {
        $ut .= '<span class="halso-prick" title="' . (int) $halsoFel . ' fel i hälsokontrollen"'
            . ' aria-label="Varning: ' . (int) $halsoFel . ' fel">' . (int) $halsoFel . '</span>';
    }
    return $ut . '</a>';
};

$initial = '';
if ($konto !== null) {
    $delar = preg_split('/\s+/u', trim((string) $konto['namn'])) ?: [];
    $initial = mb_strtoupper(mb_substr($delar[0] ?? '', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="sv" data-tema="<?= $tema === 'tema-ljus' ? 'ljus' : 'mork' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="<?= e($varumarke['farg_bakgrund']) ?>">
<meta name="description" content="Lager, inventering och QR-etiketter – saldo per arbetsställe med spårbar historik.">
<title><?= e($titel) ?> · <?= e($foretagsnamn) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(\TDL\Support\Varumarke::ikonUrl()) ?>?v=<?= e(\TDL_VERSION) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(tdl_tillgang('assets/ikoner/marke-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(tdl_tillgang('assets/ikoner/ikon-256.png')) ?>">
<link rel="manifest" href="<?= e(u('/site.webmanifest')) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(\TDL\Support\Varumarke::v('kortnamn')) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="stylesheet" href="<?= e(tdl_tillgang('assets/app.css')) ?>" fetchpriority="high">
<link rel="apple-touch-startup-image" href="<?= e(tdl_tillgang('assets/ikoner/ikon-512.png')) ?>">
<style>
/* Endast kundens färger – resten ligger i app.css (cachas av webbläsaren). */
:root {
    --accent: <?= e($varumarke['farg_primar']) ?>;
    --ok: <?= e($varumarke['farg_accent']) ?>;
    --bak: <?= e($varumarke['farg_bakgrund']) ?>;
}
</style>
</head>
<body data-sid-fraga="<?= e(\TDL\Support\Auth::sidFraga()) ?>"
      data-bas="<?= e(\TDL\Support\Bas::vard()) ?>" class="<?= e($tema) ?> <?= $konto !== null ? 'med-meny' : 'utan-meny' ?>">
<?= tdl_ikon_sprite() ?>
<a class="hoppa-till" href="#innehall">Hoppa till innehållet</a>

<div class="app">
<?php if ($konto !== null): ?>
<header class="topp">
    <button class="meny-knapp" type="button" data-meny-oppna aria-controls="huvudmeny"
            aria-expanded="false" aria-label="Öppna menyn">
        <?= tdl_ikon('meny') ?>
    </button>

    <a class="varumarke" href="<?= e(u('/')) ?>" aria-label="<?= e($foretagsnamn) ?> – start">
        <?php if ($harEgenLogotyp): ?>
            <img src="<?= e(\TDL\Support\Varumarke::logotypUrl()) ?>" alt="<?= e($foretagsnamn) ?>" class="varumarke-logotyp" width="190" height="30" decoding="async">
        <?php else: ?>
            <?= \TDL\Support\Varumarke::menyLogotyp((string) $foretagsnamn) ?>
            <?= \TDL\Support\Varumarke::symbolSvg() ?>
        <?php endif; ?>
    </a>

    <div class="topp-verktyg">
        <?php if ($valtStalle !== null): ?>
            <form method="post" action="<?= e(u('/arbetsstalle/byt')) ?>" class="stalle-val">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="stalle-knapp" title="Byt arbetsställe">
                    <span class="stalle-punkt" aria-hidden="true"></span>
                    <span class="stalle-namn"><?= e($valtStalle['namn']) ?></span>
                    <span class="stalle-byt">byt</span>
                </button>
            </form>
        <?php elseif (($konto['roll'] ?? '') === 'admin'): ?>
            <a class="knapp liten" href="<?= e(u('/')) ?>" title="Välj arbetsställe">Välj arbetsställe</a>
        <?php endif; ?>

        <a class="konto-lank" href="<?= e(u('/mitt-konto')) ?>" title="Mitt konto">
            <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
            <span class="konto-namn"><?= e($konto['namn']) ?></span>
            <span class="bricka"><?= ($konto['roll'] ?? '') === 'admin' ? 'Admin' : 'Personal' ?></span>
        </a>

        <form method="post" action="<?= e(u('/logout')) ?>" class="utlogg-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knapp liten ikon-knapp" title="Logga ut" aria-label="Logga ut">
                <?= tdl_ikon('utlogg') ?><span class="dold-mobil">Logga ut</span>
            </button>
        </form>
    </div>
</header>

<aside class="sido-nav" id="huvudmeny" aria-label="Huvudmeny">
    <div class="sido-topp">
        <span class="sido-rubrik">Meny</span>
        <button class="sido-stang" type="button" data-meny-stang aria-label="Stäng menyn">
            <?= tdl_ikon('stang') ?>
        </button>
    </div>
    <nav class="sido-innre">
        <?php foreach ($menyGrupper as $grupp => $lankar): ?>
            <div class="sido-grupp">
                <p class="sido-rubrik"><?= e($grupp) ?></p>
                <?php foreach ($lankar as $n) { echo $menyLank($n); } ?>
            </div>
        <?php endforeach; ?>
        <?php if (($konto['roll'] ?? '') === 'admin'): ?>
            <div class="sido-grupp sido-grupp-admin">
                <p class="sido-rubrik">Adminpanel</p>
                <?php foreach ($adminNav as $n) { echo $menyLank($n, true); } ?>
            </div>
        <?php endif; ?>
        <div class="sido-grupp">
            <p class="sido-rubrik">Konto</p>
            <?= $menyLank(['id' => 'konto', 'href' => '/mitt-konto', 'text' => $konto['namn'], 'ikon' => 'person']) ?>
            <?php if (($konto['roll'] ?? '') === 'admin'): ?>
                <?= $menyLank(['id' => 'losenord', 'href' => '/mitt-konto/losenord', 'text' => 'Byt lösenord', 'ikon' => 'nyckel']) ?>
            <?php else: ?>
                <?= $menyLank(['id' => 'pin', 'href' => '/mitt-konto/pin', 'text' => 'Byt PIN-kod', 'ikon' => 'nyckel']) ?>
            <?php endif; ?>
        </div>
    </nav>
    <p class="sido-fot"><?= e($foretagsnamn) ?> · <?= e(\TDL_VERSION) ?></p>
</aside>
<div class="meny-skrim" data-meny-skrim hidden></div>

<nav class="botten-nav" aria-label="Snabbmeny">
    <?php
    // Fem vanliga arbetsmoment + menyknappen. Översikt och allt annat finns
    // i menyn – bottenraden ska rymmas utan att texten blir mikroskopisk.
    $botten = [
        ['id' => 'start', 'href' => '/', 'text' => 'Start', 'ikon' => 'hus'],
        ['id' => 'inleverans', 'href' => '/inleverans', 'text' => 'Ta emot', 'ikon' => 'in'],
        ['id' => 'uttag', 'href' => '/uttag', 'text' => 'Uttag', 'ikon' => 'ut'],
        ['id' => 'skanna', 'href' => '/skanna', 'text' => 'Skanna', 'ikon' => 'skanna'],
    ];
    ?>
    <?php foreach ($botten as $n): ?>
        <a href="<?= e(u($n['href'])) ?>" class="<?= $aktiv === $n['id'] ? 'aktiv' : '' ?>"
           <?= $aktiv === $n['id'] ? 'aria-current="page"' : '' ?>>
            <?= tdl_ikon($n['ikon']) ?><span><?= e($n['text']) ?></span>
        </a>
    <?php endforeach; ?>
    <button type="button" class="botten-meny" data-meny-oppna aria-controls="huvudmeny" aria-expanded="false">
        <?= tdl_ikon('meny') ?><span>Meny</span>
    </button>
</nav>
<?php endif; ?>

<main class="innehall" id="innehall">
<div class="innehall-inre">
<?php
$arLosenordssidan = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/mitt-konto/losenord');
if (($konto['roll'] ?? '') === 'admin' && !$arLosenordssidan && \TDL\Support\Konto::losenordSnartUtgatt($konto)): ?>
    <?php $kvar = \TDL\Support\Konto::dagarKvarPaLosenord($konto); ?>
    <div class="flash varning" role="status">
        <?= tdl_ikon('varning') ?>
        <div>
            <strong>Lösenordet <?= ($kvar !== null && $kvar <= 0) ? 'har gått ut' : 'går ut snart' ?>.</strong>
            <a href="<?= e(u('/mitt-konto/losenord')) ?>">Byt lösenord</a> –
            administratörens lösenord ska bytas var <?= (int) \TDL\Support\Konto::LÖSENORD_DAGAR ?>:e dag.
        </div>
    </div>
<?php endif; ?>
<?php foreach ($flash as $f): ?>
    <div class="flash <?= e($f['typ']) ?>" role="status">
        <?= tdl_ikon($f['typ'] === 'ok' ? 'bock' : ($f['typ'] === 'fel' || $f['typ'] === 'varning' ? 'varning' : 'info')) ?>
        <div><?= e($f['text']) ?></div>
    </div>
<?php endforeach; ?>
