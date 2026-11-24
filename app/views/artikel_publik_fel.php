<?php
/** Fel sida för artikel-QR (utgången länk, okänd artikel). @var string $rubrik @var string $text */
$brand = \TDL\Support\Varumarke::alla();
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($brand['farg_bakgrund']) ?>">
<title><?= e($rubrik) ?> – <?= e($brand['namn']) ?></title>
<link rel="stylesheet" href="<?= e(tdl_tillgang('assets/app.css')) ?>">
<style>:root { --accent: <?= e($brand['farg_primar']) ?>; --ok: <?= e($brand['farg_accent']) ?>; --bak: <?= e($brand['farg_bakgrund']) ?>; }</style>
</head>
<body class="<?= e($brand['tema'] === 'ljus' ? 'tema-ljus' : 'tema-mork') ?>">
<main class="innehall">
    <div class="kort" style="max-width:480px;margin:12vh auto 0;text-align:center">
        <h1 style="margin-top:0"><?= e($rubrik) ?></h1>
        <p><?= e($text) ?></p>
        <div class="actions" style="justify-content:center">
            <a class="knapp" href="<?= e(u('/')) ?>">Till <?= e($brand['namn']) ?></a>
        </div>
    </div>
    <p class="sidfot"><?= e($brand['namn']) ?></p>
</main>
</body>
</html>
