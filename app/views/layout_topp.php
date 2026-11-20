<?php
/** @var string $titel @var array|null $konto @var string $aktiv @var array $flash */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$varumarke = \TDL\Support\Varumarke::alla();
$tema = $varumarke['tema'] === 'ljus' ? 'tema-ljus' : 'tema-mork';
// v('namn') faller tillbaka på "Lager" tills kunden angett sitt namn.
$foretagsnamn = \TDL\Support\Varumarke::v('namn');
$harEgenLogotyp = $varumarke['logotyp_fil'] !== '';
$nav = [
    ['id' => 'start', 'href' => '/', 'text' => 'Start', 'ikon' => '⌂'],
    ['id' => 'oversikt', 'href' => '/oversikt', 'text' => 'Översikt', 'ikon' => '▦'],
    ['id' => 'inleverans', 'href' => '/inleverans', 'text' => 'Inleverans', 'ikon' => '↓'],
    ['id' => 'uttag', 'href' => '/uttag', 'text' => 'Uttag', 'ikon' => '↑'],
    ['id' => 'plocklista', 'href' => '/plocklista', 'text' => 'Plocklista', 'ikon' => '☰'],
    ['id' => 'overforing', 'href' => '/overforing', 'text' => 'Överför', 'ikon' => '⇄'],
    ['id' => 'skanna', 'href' => '/skanna', 'text' => 'Skanna', 'ikon' => '▣'],
    ['id' => 'inventering', 'href' => '/inventering', 'text' => 'Inventering', 'ikon' => '✓'],
    ['id' => 'artiklar', 'href' => '/artiklar', 'text' => 'Artiklar', 'ikon' => '▤'],
    ['id' => 'lagerkarta', 'href' => '/lagerkarta', 'text' => 'Lagerkarta', 'ikon' => '▩'],
    ['id' => 'lokalplan', 'href' => '/lokalplan', 'text' => 'Lokalplan', 'ikon' => '▧'],
    ['id' => 'etiketter', 'href' => '/etiketter', 'text' => 'Etiketter', 'ikon' => '❏'],
    ['id' => 'historik', 'href' => '/historik', 'text' => 'Historik', 'ikon' => '≡'],
    ['id' => 'arkiv', 'href' => '/arkiv', 'text' => 'Arkiv', 'ikon' => '▦'],
    ['id' => 'stod', 'href' => '/stod', 'text' => 'Stöd', 'ikon' => '♥'],
];
$adminNav = [
    ['id' => 'lagerplats', 'href' => '/lagerplatser', 'text' => 'Lagerplatser', 'ikon' => '▥'],
    ['id' => 'platser', 'href' => '/platser', 'text' => 'Arbetsställen', 'ikon' => '⌖'],
    ['id' => 'konton', 'href' => '/konton', 'text' => 'Konton', 'ikon' => '☺'],
    ['id' => 'varumarke', 'href' => '/admin/varumarke', 'text' => 'Varumärke', 'ikon' => '◈'],
    ['id' => 'system', 'href' => '/admin/system', 'text' => 'System', 'ikon' => '⚙'],
];
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($varumarke['farg_bakgrund']) ?>">
<title><?= e($titel) ?> – <?= e($foretagsnamn) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(\TDL\Support\Varumarke::ikonUrl()) ?>?v=<?= e(\TDL_VERSION) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(u('/assets/ikoner/marke-32.png?v=' . e(\TDL_VERSION))) ?>">
<link rel="shortcut icon" type="image/png" href="<?= e(u('/assets/ikoner/marke-32.png?v=' . e(\TDL_VERSION))) ?>">
<link rel="apple-touch-icon" href="<?= e(u('/assets/ikoner/ikon-256.png?v=' . e(\TDL_VERSION))) ?>">
<link rel="manifest" href="<?= e(u('/site.webmanifest')) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(\TDL\Support\Varumarke::v('kortnamn')) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="stylesheet" href="<?= e(u('/assets/app.css?v=' . e(\TDL_VERSION))) ?>">
<link rel="apple-touch-startup-image" href="<?= e(u('/assets/ikoner/ikon-512.png?v=' . e(\TDL_VERSION))) ?>">
<style>
:root {
    --accent: <?= e($varumarke['farg_primar']) ?>;
    --ok: <?= e($varumarke['farg_accent']) ?>;
    --bak: <?= e($varumarke['farg_bakgrund']) ?>;
}
</style>
</head>
<body data-sid-fraga="<?= e(\TDL\Support\Auth::sidFraga()) ?>"
      data-bas="<?= e(\TDL\Support\Bas::vard()) ?>" class="<?= e($tema) ?>">
<?php if ($konto !== null): ?>
<header class="topp">
    <a class="varumarke" href="<?= e(u('/')) ?>" aria-label="<?= e($foretagsnamn) ?>">
        <?php if ($harEgenLogotyp): ?>
            <img src="<?= e(\TDL\Support\Varumarke::logotypUrl()) ?>" alt="<?= e($foretagsnamn) ?>" class="varumarke-logotyp">
        <?php else: ?>
            <?= \TDL\Support\Varumarke::menyLogotyp((string) $foretagsnamn) ?>
        <?php endif; ?>
    </a>
    <nav class="topp-nav">
        <?php $lagAntal = count(\TDL\Domain\Lager::underMiniminiva()); ?>
        <?php foreach ($nav as $n): ?>
            <a href="<?= e(u($n['href'])) ?>" class="<?= $aktiv === $n['id'] ? 'aktiv' : '' ?>">
                <?= $n['text'] ?>
                <?php if ($n['id'] === 'miniminiva' && $lagAntal > 0): ?>
                    <span style="background:#dc2626;color:#fff;border-radius:999px;padding:1px 6px;font-size:.72rem;margin-left:4px"><?= $lagAntal ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        <?php if (($konto['roll'] ?? '') === 'admin'): ?>
            <?php
            // Hälsokontrollen (förslag 20): en diskret prick på System när något
            // behöver åtgärdas. Svaret cachas i 60 s – inget tungt per sidladdning.
            $halsoFel = '';
            try {
                $halsoSvar = \TDL\Support\HalsoKontroll::snabb();
                if (($halsoSvar['fel'] ?? 0) > 0) {
                    $halsoFel = (string) $halsoSvar['fel'];
                }
            } catch (\Throwable $e) {
                $halsoFel = '';
            }
            ?>
            <?php foreach ($adminNav as $n): ?>
                <a href="<?= e(u($n['href'])) ?>" class="admin <?= $aktiv === $n['id'] ? 'aktiv' : '' ?>"><?= $n['text'] ?><?php if ($n['id'] === 'system' && $halsoFel !== ''): ?><span class="halso-prick" title="<?= (int) $halsoFel ?> fel i hälsokontrollen" aria-label="Varning: <?= (int) $halsoFel ?> fel"><?= (int) $halsoFel ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    <div class="topp-konto">
        <?php
        // Vilket arbetsställe passet gäller. Alltid synligt – annars tar man
        // lätt emot varor på fel ställe utan att märka det.
        $valtStalle = null;
        try {
            $valtStalle = \TDL\Support\Arbetsstalle::aktivt();
        } catch (\Throwable $e) {
            $valtStalle = null;
        }
        ?>
        <?php if ($valtStalle !== null): ?>
            <form method="post" action="<?= e(u('/arbetsstalle/byt')) ?>" class="stalle-val" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="liten" title="Byt arbetsställe">
                    <span class="stalle-punkt" aria-hidden="true"></span>
                    <?= e($valtStalle['namn']) ?>
                    <span class="stalle-byt">byt</span>
                </button>
            </form>
        <?php elseif (($konto['roll'] ?? '') === 'admin'): ?>
            <a class="knapp liten" href="<?= e(u('/')) ?>" title="Välj arbetsställe">Välj arbetsställe</a>
        <?php endif; ?>
        <a href="<?= e(u('/mitt-konto')) ?>" title="Mitt konto"><?= e($konto['namn']) ?>
            <span class="bricka"><?= $konto['roll'] === 'admin' ? 'Admin' : 'Personal' ?></span>
        </a>
        <form method="post" action="<?= e(u('/logout')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="liten">Logga ut</button>
        </form>
    </div>
</header>
<?php endif; ?>

<main class="innehall">
<?php
$arLosenordssidan = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/mitt-konto/losenord');
if (($konto['roll'] ?? '') === 'admin' && !$arLosenordssidan && \TDL\Support\Konto::losenordSnartUtgatt($konto)): ?>
    <?php $kvar = \TDL\Support\Konto::dagarKvarPaLosenord($konto); ?>
    <div class="flash varning" role="status">
        <strong>PIN-koden <?= ($kvar !== null && $kvar <= 0) ? 'har gått ut' : 'går ut snart' ?>.</strong>
        <a href="<?= e(u('/mitt-konto')) ?>">Byt PIN-kod</a> –
        administratörens lösenord ska bytas var <?= (int) \TDL\Support\Konto::LÖSENORD_DAGAR ?>:e dag.
    </div>
<?php endif; ?>
<?php foreach ($flash as $f): ?>
    <div class="flash <?= e($f['typ']) ?>" role="status"><?= e($f['text']) ?></div>
<?php endforeach; ?>
