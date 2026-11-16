<?php
/**
 * Startsidan – appens nav.
 *
 * En ingång till allt. Korten är grupperade efter vad man ska göra, inte
 * efter vilken tabell datan ligger i: först det dagliga arbetet, sedan
 * varorna, sedan utskrifter och till sist adminpanelen.
 *
 * Ikonerna är inline-SVG, inte unicode-tecken. Tecken som ⌖ ⚙ ◈ saknas i
 * många typsnitt och blev tomma rutor – SVG ser likadana ut överallt och
 * ärver färgen från kortet.
 *
 * @var int $antalArtiklar @var int $antalPlatser @var int $antalEnheter
 * @var int $underMin @var array $oppnaSessioner @var array|null $aktivtStalle
 * @var array $konto
 */
$arAdmin = ($konto['roll'] ?? '') === 'admin';
$stalle = $aktivtStalle ?? null;

/**
 * Ikonbibliotek. Varje ikon är en eller flera SVG-banor i 24×24-rutnät,
 * ritade med samma linjetjocklek så att de känns som en familj.
 */
$ikoner = [
    // Nedåtpil i en låda – ta emot
    'in' => '<path d="M12 4v10m0 0l-4-4m4 4l4-4"/><path d="M4 17v2a1 1 0 001 1h14a1 1 0 001-1v-2"/>',
    // Uppåtpil i en låda – lämna ut
    'ut' => '<path d="M12 15V5m0 0L8 9m4-4l4 4"/><path d="M4 17v2a1 1 0 001 1h14a1 1 0 001-1v-2"/>',
    // Checklista – plocklista
    'lista' => '<path d="M4 6h2m0 0L4.5 7.5 6.5 5M4 12h2m0 0l-1.5 1.5L6.5 11M4 18h2m0 0l-1.5 1.5L6.5 17"/><path d="M10 6h10M10 12h10M10 18h10"/>',
    // Två pilar åt varsitt håll – överför
    'byt' => '<path d="M4 8h13m0 0l-3-3m3 3l-3 3"/><path d="M20 16H7m0 0l3-3m-3 3l3 3"/>',
    // Sökram med hörn – skanna
    'skanna' => '<path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2"/><path d="M4 12h16"/>',
    // Bock i cirkel – inventering
    'bock' => '<circle cx="12" cy="12" r="8"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
    // Böcker – artiklar
    'artiklar' => '<path d="M4 5.5A1.5 1.5 0 015.5 4H10v16H5.5A1.5 1.5 0 014 18.5z"/><path d="M10 4h4.5A1.5 1.5 0 0116 5.5v13a1.5 1.5 0 01-1.5 1.5H10"/>',
    // Hus – översikt
    'hus' => '<path d="M4 10.5L12 4l8 6.5V19a1 1 0 01-1 1h-4v-6H9v6H5a1 1 0 01-1-1z"/>',
    // Varningstriangel – under miniminivå
    'varning' => '<path d="M12 4.5L21 19H3z"/><path d="M12 10v4"/><circle cx="12" cy="16.6" r=".7" fill="currentColor" stroke="none"/>',
    // Rader med text – historik
    'historik' => '<path d="M4 7h16M4 12h16M4 17h10"/>',
    // Arkivlåda
    'arkiv' => '<rect x="3.5" y="5" width="17" height="4.5" rx="1"/><path d="M5 9.5V18a1 1 0 001 1h12a1 1 0 001-1V9.5"/><path d="M10 13h4"/>',
    // Etikett med hål
    'etikett' => '<path d="M11.6 3.5H19a1 1 0 011 1v7.4a1 1 0 01-.3.7l-7.6 7.6a1 1 0 01-1.4 0l-7.4-7.4a1 1 0 010-1.4l7.6-7.6a1 1 0 01.7-.3z"/><circle cx="16" cy="8" r="1.3"/>',
    // Platsnål – arbetsställe
    'nal' => '<path d="M12 21s6-5.2 6-10a6 6 0 10-12 0c0 4.8 6 10 6 10z"/><circle cx="12" cy="11" r="2.2"/>',
    // Person – konto
    'person' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20a7 7 0 0114 0"/>',
    // Kugghjul – system
    'kugghjul' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M20.5 12h-2.2M5.7 12H3.5M18 6l-1.6 1.6M7.6 16.4L6 18M18 18l-1.6-1.6M7.6 7.6L6 6"/>',
    // Diamant – varumärke
    'diamant' => '<path d="M12 3.5l2.6 4.3 4.9.7-3.5 3.5.8 4.9L12 14.6l-4.8 2.3.8-4.9L4.5 8.5l4.9-.7z"/>',
    // Plus i sköld – hälsokontroll
    'halsa' => '<path d="M12 3.5l7 2.6v6c0 4-3 7-7 8.4-4-1.4-7-4.4-7-8.4v-6z"/><path d="M12 9v5M9.5 11.5h5"/>',
    // Nyckel
    'nyckel' => '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H20m-3 0v3m3-3v2.5"/>',
];

/** En ikon som inline-SVG. Ärver färg via currentColor. */
$svg = function (string $namn) use ($ikoner): string {
    $banor = $ikoner[$namn] ?? $ikoner['hus'];
    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
        . 'focusable="false">' . $banor . '</svg>';
};

/** Ett kort på startsidan. */
$kort = function (string $href, string $titel, string $text, string $ikon, string $extra = '') use ($svg): string {
    // u() lägger på basvägen – appen kan ligga i /lager på webbhotellet.
    return '<a class="start-kort' . ($extra !== '' ? ' ' . $extra : '') . '" href="' . e(u($href)) . '">'
        . '<span class="start-ikon">' . $svg($ikon) . '</span>'
        . '<span class="start-text">'
        . '<strong>' . e($titel) . '</strong>'
        . '<span class="dampad">' . e($text) . '</span>'
        . '</span>'
        . '<span class="start-pil" aria-hidden="true">→</span>'
        . '</a>';
};
?>

<div class="start">
    <header class="start-topp">
        <h1>Hej <?= e(explode(' ', (string) $konto['namn'])[0]) ?></h1>
        <p class="dampad">
            <?php if ($stalle !== null): ?>
                Du jobbar på <strong><?= e($stalle['namn']) ?></strong>.
                Det fylls i automatiskt i formulären.
            <?php else: ?>
                Du arbetar utan fast arbetsställe – välj ställe i varje steg.
            <?php endif; ?>
        </p>
    </header>

    <?php // ---- Första gången: kunden måste sätta sitt eget namn ---- ?>
    <?php if (($konto['roll'] ?? '') === 'admin' && !\TDL\Support\Varumarke::harEgetNamn()): ?>
        <a class="flash info larm-rad" href="<?= e(u('/admin/varumarke')) ?>">
            <strong>Ange företagets namn.</strong>
            Det syns i menyn, på kvitton och i historiken.
            <span class="larm-lank">Öppna Varumärke →</span>
        </a>
    <?php endif; ?>

    <?php // ---- Larmet först: det är därför man öppnar appen ---- ?>
    <?php if ($underMin > 0): ?>
        <a class="flash varning larm-rad" href="<?= e(u('/miniminiva')) ?>">
            <strong><?= (int) $underMin ?> <?= $underMin === 1 ? 'artikel ligger' : 'artiklar ligger' ?> under miniminivå.</strong>
            <span class="larm-lank">Visa listan →</span>
        </a>
    <?php endif; ?>
    <?php if ($oppnaSessioner !== []): ?>
        <div class="flash info">
            <strong>Pågående inventering:</strong>
            <?php foreach ($oppnaSessioner as $s): ?>
                <a href="<?= e(u('/inventering/' . e($s['id']))) ?>"><?= e($s['id']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php // ---- 1. Det dagliga arbetet ---- ?>
    <section class="start-grupp">
        <h2>Dagligt arbete</h2>
        <div class="start-rutnat">
            <?= $kort('/inleverans', 'Inleverans', 'Ta emot varor på arbetsstället', 'in', 'stor') ?>
            <?= $kort('/uttag', 'Uttag', 'Lämna ut och skriv av saldo', 'ut') ?>
            <?= $kort('/plocklista', 'Plocklista', 'Plocka flera artiklar på en gång', 'lista') ?>
            <?= $kort('/overforing', 'Överför', 'Flytta mellan arbetsställen', 'byt') ?>
            <?= $kort('/skanna', 'Skanna', 'Läs QR-kod med kameran', 'skanna') ?>
            <?= $kort('/inventering', 'Inventering', 'Räkna av ett helt arbetsställe', 'bock') ?>
        </div>
    </section>

    <?php // ---- 2. Lagret och varorna ---- ?>
    <section class="start-grupp">
        <h2>Lager och artiklar</h2>
        <div class="start-rutnat">
            <?= $kort('/artiklar', 'Artiklar', $antalArtiklar . ' aktiva · sök, filtrera, redigera', 'artiklar') ?>
            <?= $kort('/oversikt', 'Översikt', 'Saldo per ställe, larm och status', 'hus') ?>
            <?= $kort('/miniminiva', 'Under miniminivå', $underMin > 0 ? $underMin . ' att beställa' : 'Allt är över miniminivå', 'varning') ?>
            <?= $kort('/historik', 'Historik', 'Allt som hänt, spårbart', 'historik') ?>
            <?= $kort('/arkiv', 'Arkiv', 'Äldre månader och år', 'arkiv') ?>
        </div>
    </section>

    <?php // ---- 3. Etiketter och utskrift ---- ?>
    <section class="start-grupp">
        <h2>Etiketter och utskrift</h2>
        <div class="start-rutnat">
            <?= $kort('/etiketter', 'Skriv ut etiketter', 'QR-lappar till hyllor, verktyg och ingångar', 'etikett') ?>
            <?= $kort('/platsetikett', 'Arbetsställets lapp', 'En QR per ställe, sätts vid ingången', 'nal') ?>
        </div>
        <p class="falt-hjalp" style="margin-top:10px">
            All utskrift sker från datorn med etikettskrivaren – inget kräver mobil.
        </p>
    </section>

    <?php // ---- 4. Adminpanelen ---- ?>
    <?php if ($arAdmin): ?>
    <section class="start-grupp">
        <h2>Adminpanel <span class="bricka">Admin</span></h2>
        <div class="start-rutnat">
            <?= $kort('/platser', 'Arbetsställen', $antalPlatser . ' ställen · lägg till och stäng', 'nal') ?>
            <?= $kort('/konton', 'Konton', 'Personal, PIN-koder och behörigheter', 'person') ?>
            <?= $kort('/admin/system', 'System', 'Adress, skrivare, backup och säkerhet', 'kugghjul') ?>
            <?= $kort('/admin/varumarke', 'Varumärke', 'Namn, färger och logotyp', 'diamant') ?>
            <?= $kort('/admin/halsa', 'Hälsokontroll', 'Åtta kontroller av hela appen', 'halsa') ?>
            <?php
            // Bytet är en POST (CSRF-skyddad), alltså en knapp – inte en länk.
            ?>
            <form method="post" action="<?= e(u('/arbetsstalle/byt')) ?>" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= e(\TDL\Support\Sakerhet::csrfToken()) ?>">
                <button type="submit" class="start-kort" style="width:100%;text-align:left;cursor:pointer">
                    <span class="start-ikon"><?= $svg('byt') ?></span>
                    <span class="start-text">
                        <strong>Byt arbetsställe</strong>
                        <span class="dampad">Välj ett annat ställe för passet</span>
                    </span>
                    <span class="start-pil" aria-hidden="true">→</span>
                </button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <?php // ---- 5. Mitt konto ---- ?>
    <section class="start-grupp">
        <h2>Mitt konto</h2>
        <div class="start-rutnat">
            <?= $kort('/mitt-konto', 'Mitt konto', e((string) $konto['namn']), 'person') ?>
            <?php if ($arAdmin): ?>
                <?= $kort('/mitt-konto/losenord', 'Byt lösenord', 'Krävs var 20:e dag', 'nyckel') ?>
            <?php else: ?>
                <?= $kort('/mitt-konto/pin', 'Byt PIN-kod', 'Välj en ny fyrasiffrig kod', 'nyckel') ?>
            <?php endif; ?>
        </div>
    </section>

    <p class="dampad start-fot">
        <?= (int) $antalArtiklar ?> artiklar · <?= (int) $antalPlatser ?> arbetsställen ·
        <?= (int) $antalEnheter ?> QR-enheter · alla ändringar sparas i historiken
    </p>
</div>
