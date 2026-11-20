</main>
<?php if (($konto ?? null) !== null): ?>
<nav class="botten-nav">
    <?php
    // Bottenmenyn är appens främsta navigation på telefon. Den visar de
    // fem vanligaste arbetsmomenten plus "Mitt konto" – startsidan och
    // översikten nås via logotypen respektive menyn.
    $botten = [
        ['id' => 'start', 'href' => '/', 'text' => 'Start', 'ikon' => '⌂'],
        ['id' => 'inleverans', 'href' => '/inleverans', 'text' => 'Ta emot', 'ikon' => '↓'],
        ['id' => 'uttag', 'href' => '/uttag', 'text' => 'Uttag', 'ikon' => '↑'],
        ['id' => 'skanna', 'href' => '/skanna', 'text' => 'Skanna', 'ikon' => '▣'],
        ['id' => 'oversikt', 'href' => '/oversikt', 'text' => 'Översikt', 'ikon' => '▦'],
    ];
    foreach ($botten as $n): ?>
        <a href="<?= e(u($n['href'])) ?>" class="<?= ($aktiv ?? '') === $n['id'] ? 'aktiv' : '' ?>">
            <span class="ikon"><?= $n['ikon'] ?></span><?= $n['text'] ?>
        </a>
    <?php endforeach; ?>
    <a href="<?= e(u('/mitt-konto')) ?>" class="<?= ($aktiv ?? '') === 'mer' ? 'aktiv' : '' ?>">
        <span class="ikon">☺</span>Konto
    </a>
</nav>
<?php endif; ?>
<footer class="sidfot">
    <?= e(\TDL\Support\Varumarke::v('namn')) ?> · <?= e(\TDL_VERSION) ?> · alla ändringar sparas i historiken
</footer>
<!-- All klientlogik: bekräftelser, autospar vid räkning, kamera, QR. Extern fil = CSP script-src 'self' -->
<script src="<?= e(u('/assets/app.js?v=' . (int) @filemtime(TDL_PUBLIC . '/assets/app.js'))) ?>"></script>
</body>
</html>
