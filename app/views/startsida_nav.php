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
 * @var int $underMin @var array $uppgifter @var array|null $aktivtStalle
 * @var array $konto
 */
$arAdmin = ($konto['roll'] ?? '') === 'admin';
$stalle = $aktivtStalle ?? null;

require_once __DIR__ . '/ikoner.php';

/**
 * Ikonerna ligger i ikoner.php (en gemensam SVG-sprite). Här behålls bara
 * en tunn hjälpare så att kortmarkeringen nedan blir läsbar.
 */
$svg = static fn (string $namn): string => tdl_ikon($namn);

/** Ett kort på startsidan. */
$kort = function (string $href, string $titel, string $text, string $ikon, string $extra = '') use ($svg): string {
    // u() lägger på basvägen – appen kan ligga i /lager på webbhotellet.
    return '<a class="start-kort' . ($extra !== '' ? ' ' . $extra : '') . '" href="' . e(u($href)) . '">'
        . '<span class="start-ikon">' . $svg($ikon) . '</span>'
        . '<span class="start-text">'
        . '<strong>' . e($titel) . '</strong>'
        . '<span class="dampad">' . e($text) . '</span>'
        . '</span>'
        . '<span class="start-pil" aria-hidden="true">' . tdl_ikon('pil-hoger') . '</span>'
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

    <section class="dagens-uppgifter" aria-labelledby="dagens-rubrik">
        <h2 id="dagens-rubrik">Dagens uppgifter</h2>
        <p class="dampad"><?= $stalle !== null ? 'För ' . e($stalle['namn']) . ' och din roll.' : 'För alla arbetsställen och din roll.' ?></p>
        <p class="falt-hjalp">En bristrad gäller en artikel på ett arbetsställe.</p>
        <?php if ($uppgifter === []): ?>
            <p>Inga aktuella uppgifter hittades.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($uppgifter as $uppgift): ?>
                    <li><a href="<?= e(u($uppgift['href'])) ?>"><?= e($uppgift['text']) ?> <span aria-hidden="true">→</span></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php // ---- Läget i siffror: det man oftast vill veta först ---- ?>
    <div class="start-siffror" aria-label="Läget i korthet">
        <a class="start-siffra" href="<?= e(u('/artiklar')) ?>">
            <span class="tal"><?= (int) $antalArtiklar ?></span>
            <span class="etikett">aktiva artiklar</span>
        </a>
        <a class="start-siffra" href="<?= e(u('/platser')) ?>">
            <span class="tal"><?= (int) $antalPlatser ?></span>
            <span class="etikett">arbetsställen</span>
        </a>
        <a class="start-siffra" href="<?= e(u('/skanna')) ?>">
            <span class="tal"><?= (int) $antalEnheter ?></span>
            <span class="etikett">QR-enheter</span>
        </a>
        <a class="start-siffra<?= $underMin > 0 ? ' varning' : '' ?>" href="<?= e(u('/miniminiva')) ?>">
            <span class="tal"><?= (int) $underMin ?></span>
            <span class="etikett">bristrader under miniminivå</span>
        </a>
    </div>

    <?php // ---- Första gången: kunden måste sätta sitt eget namn ---- ?>
    <?php if (($konto['roll'] ?? '') === 'admin' && !\TDL\Support\Varumarke::harEgetNamn()): ?>
        <a class="flash info larm-rad" href="<?= e(u('/admin/varumarke')) ?>">
            <strong>Ange företagets namn.</strong>
            Det syns i menyn, på kvitton och i historiken.
            <span class="larm-lank">Öppna Varumärke →</span>
        </a>
    <?php endif; ?>

    <?php // ---- 1. Det dagliga arbetet ---- ?>
    <section class="start-grupp">
        <h2>Dagligt arbete</h2>
        <div class="start-rutnat">
            <?= $kort('/inleverans', 'Inleverans', 'Ta emot varor på arbetsstället', 'in', 'stor') ?>
            <?= $kort('/uttag', 'Uttag', 'Lämna ut och skriv av saldo', 'ut') ?>
            <?= $kort('/utlan', 'Utlåning', 'Låna ut och registrera återlämning', 'person') ?>
            <?= $kort('/plocklista', 'Plocklista', 'Plocka flera artiklar på en gång', 'lista') ?>
            <?= $kort('/overforing', 'Överför', 'Flytta mellan arbetsställen', 'byt') ?>
            <?= $kort('/skanna', 'Skanna', 'Läs QR och EAN eller skriv koden', 'skanna') ?>
            <?= $kort('/inventering', 'Inventering', 'Räkna av ett helt arbetsställe', 'bock') ?>
        </div>
    </section>

    <?php // ---- 2. Lagret och varorna ---- ?>
    <section class="start-grupp">
        <h2>Lager och artiklar</h2>
        <div class="start-rutnat">
            <?= $kort('/artiklar', 'Artiklar', $antalArtiklar . ' aktiva · sök, filtrera, redigera', 'artiklar') ?>
            <?= $kort('/oversikt', 'Översikt', 'Saldo per ställe, larm och status', 'hus') ?>
            <?= $kort('/lagerkarta', 'Lagerkarta', 'Hitta artikelns zon, rad och fack', 'lagerkarta') ?>
            <?= $kort('/miniminiva', 'Under miniminivå', $underMin > 0 ? $underMin . ($underMin === 1 ? ' bristrad' : ' bristrader') : 'Allt är över miniminivå', 'varning') ?>
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
                    <span class="start-pil" aria-hidden="true"><?= tdl_ikon('pil-hoger') ?></span>
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
            <?= $kort('/mitt-konto', 'Byt PIN-kod', 'Välj en ny fyr- till åttasiffrig kod', 'nyckel') ?>
        </div>
    </section>

    <p class="dampad start-fot">
        <?= (int) $antalArtiklar ?> artiklar · <?= (int) $antalPlatser ?> arbetsställen ·
        <?= (int) $antalEnheter ?> QR-enheter · alla ändringar sparas i historiken
    </p>
</div>
