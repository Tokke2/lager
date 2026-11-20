<?php
/** @var array|null $artikel @var array $konto */
$csrf = \TDL\Support\Sakerhet::csrfToken();
// Personal lägger upp artikeln men bestämmer inte nummer eller pris – servern sätter dem.
$arAdmin = ($konto['roll'] ?? '') === 'admin';
$nyArtikel = $artikel === null;
$visaNummerFalt = $arAdmin || !$nyArtikel;
?>
<h1><?= $artikel === null ? 'Ny artikel' : 'Redigera artikel' ?></h1>
<form method="post" action="<?= e($artikel === null ? u('/artiklar/ny') : u('/artiklar/' . $artikel['id'] . '/redigera')) ?>" class="kort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="rad">
        <div class="falt">
            <label for="namn">Namn *</label>
            <input id="namn" name="namn" required maxlength="120" value="<?= e($artikel['namn'] ?? '') ?>">
        </div>
        <div class="falt">
            <?php /* Admin sätter numret själv. Personal får det automatiskt.
                     Det inaktiva fältet får aldrig vara required – då kan
                     formuläret inte skickas alls, eftersom ett disabled-fält
                     inte kan fyllas i. */ ?>
            <?php if (($konto['roll'] ?? '') === 'admin'): ?>
                <label for="artikelnummer">Artikelnummer</label>
                <input id="artikelnummer" name="artikelnummer" maxlength="40"
                       value="<?= e($artikel['artikelnummer'] ?? '') ?>"
                       placeholder="Lämna tomt för automatiskt">
                <div class="falt-hjalp">Lämnas det tomt byggs numret av namnet och nästa lediga löpnummer.</div>
            <?php else: ?>
                <label for="artikelnummer">Artikelnummer</label>
                <input id="artikelnummer" type="text" value="Sätts automatiskt när du sparar" disabled>
                <div class="falt-hjalp">Numret byggs av namnet och nästa lediga löpnummer.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="rad">
        <div class="falt">
            <label for="enhet">Enhet</label>
            <input id="enhet" name="enhet" maxlength="20" value="<?= e($artikel['enhet'] ?? 'st') ?>">
        </div>
        <div class="falt">
            <label for="miniminiva">Miniminivå (per arbetsställe)</label>
            <input id="miniminiva" name="miniminiva" type="number" min="0" step="1" value="<?= e((string) ($artikel['miniminiva'] ?? 0)) ?>">
            <div class="falt-hjalp">Jämförs mot varje plats för sig, inte mot totalen.</div>
        </div>
        <?php if ($visaNummerFalt): ?>
        <div class="falt">
            <label for="prisreferens">Prisreferens (kr, valfri)</label>
            <input id="prisreferens" name="prisreferens" type="number" min="0" step="0.01" value="<?= $artikel !== null && $artikel['prisreferens'] !== null ? e((string) $artikel['prisreferens']) : '' ?>">
        </div>
        <?php endif; ?>
    </div>
    <div class="rad">
        <div class="falt">
            <label for="kategori">Kategori (valfri)</label>
            <input id="kategori" name="kategori" maxlength="40" list="kategorier"
                   value="<?= e($artikel['kategori'] ?? '') ?>" placeholder="t.ex. Fästelement">
            <datalist id="kategorier">
                <?php foreach ($kategorier ?? [] as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?>
            </datalist>
            <div class="falt-hjalp">Samlar artiklar som hör ihop. Blir filter i artikellistan.</div>
        </div>
    </div>

    <?php // ---- Lagerplats: zon + rad + fack. Obligatorisk. ---- ?>
    <?php
    $valdZon = $artikel['zon_id'] ?? ($forvalZon ?? '');
    $valdRad = (int) ($artikel['rad'] ?? 0);
    $valdFack = (int) ($artikel['fack'] ?? 0);
    if ($valdRad === 0) { $valdRad = (int) ($forvalRad ?? 0); }
    if ($valdFack === 0) { $valdFack = (int) ($forvalFack ?? 0); }
    $forvalPlats = (string) (\TDL\Domain\Lagerplats::platsText($artikel) ?: '');
    ?>
    <div class="rad" id="lagerplats-falt">
        <div class="falt" style="flex:2">
            <label for="zon_id">Zon *</label>
            <?php if (($zoner ?? []) === []): ?>
                <div class="flash fel" style="margin:0">
                    Det finns ingen zon ännu. <a href="<?= e(u('/lagerplatser/ny')) ?>">Skapa en zon</a> först –
                    varje artikel måste ha en plats.
                </div>
            <?php else: ?>
                <select id="zon_id" name="zon_id" required data-plats-zon>
                    <option value="">Välj zon …</option>
                    <?php foreach ($zoner as $z): ?>
                        <option value="<?= e($z['id']) ?>"
                                data-rader="<?= (int) $z['rader'] ?>" data-fack="<?= (int) $z['fack'] ?>"
                                <?= $z['id'] === $valdZon ? 'selected' : '' ?>>
                            <?= e($z['kod']) ?> – <?= e($z['namn']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="falt" style="flex:0 0 110px">
            <label for="rad">Rad *</label>
            <input id="rad" name="rad" type="number" min="1" required data-plats-rad
                   value="<?= $valdRad > 0 ? (int) $valdRad : '' ?>">
            <div class="falt-hjalp" data-plats-rad-hjalp></div>
        </div>
        <div class="falt" style="flex:0 0 110px">
            <label for="fack">Fack *</label>
            <input id="fack" name="fack" type="number" min="1" required data-plats-fack
                   value="<?= $valdFack > 0 ? (int) $valdFack : '' ?>">
            <div class="falt-hjalp" data-plats-fack-hjalp></div>
        </div>
        <div class="falt" style="flex:1;justify-content:flex-end">
            <label>&nbsp;</label>
            <div class="plats-forhandsvisning">
                <span class="dampad">Plats</span>
                <strong class="mono" data-plats-text><?= $forvalPlats !== '' ? e($forvalPlats) : '—' ?></strong>
            </div>
        </div>
    </div>
    <p class="falt-hjalp" style="margin-top:-6px">
        Platsen skrivs ut på etiketten, sorterar plocklistan i gångordning och ritar upp
        artikeln på <a href="<?= e(u('/lagerkarta')) ?>">lagerkartan</a>. Den gamla fritexthylla
        finns kvar som kommentar om du vill skriva något extra.
    </p>
    <div class="falt">
        <label for="sparningssatt">Saldohanteringssätt</label>
        <select id="sparningssatt" name="sparningssatt">
            <option value="antal" <?= ($artikel['sparningssatt'] ?? 'antal') === 'antal' ? 'selected' : '' ?>>Saldo i antal</option>
            <option value="qr" <?= ($artikel['sparningssatt'] ?? '') === 'qr' ? 'selected' : '' ?>>QR per fysisk enhet</option>
        </select>
        <div class="falt-hjalp">
            QR per enhet kan inte stängas av så snart enheten har historik. Ett byte från antal till QR
            kräver att saldot är noll på alla arbetsställen.
        </div>
    </div>
    <?php if ($artikel !== null): ?>
        <p class="dampad">Sparningssätt: QR-spårning kan inte slås av när enheter finns. Det går att inaktivera artikeln i stället för att radera.</p>
    <?php endif; ?>

    <div class="falt">
        <label for="etikett_mall">Etikettstorlek för den här artikeln</label>
        <select id="etikett_mall" name="etikett_mall">
            <?php $valdMall = $artikel['etikett_mall'] ?? \TDL\Support\Etikettmallar::STANDARD; ?>
            <?php foreach (\TDL\Support\Etikettmallar::valbara() as $nyckel => $m): ?>
                <option value="<?= e($nyckel) ?>" <?= $nyckel === $valdMall ? 'selected' : '' ?>><?= e($m['namn']) ?></option>
            <?php endforeach; ?>
            <?php if (!isset(\TDL\Support\Etikettmallar::MALLAR[$valdMall])): ?>
                <option value="<?= e($valdMall) ?>" selected>Eget mått <?= e(str_replace('x', ' × ', $valdMall)) ?> mm</option>
            <?php endif; ?>
        </select>
        <div class="falt-hjalp">
            Används automatiskt när du skriver ut etiketter för artikeln.
            <?php $sp = \TDL\Support\Skrivare::aktiv(); ?>
            Din skrivare är <strong><?= e($sp['namn']) ?></strong> och skriver
            <?= e(\TDL\Support\Skrivare::tal((float) $sp['bredd'])) ?> mm brett – bredare etiketter klipps.
            Byt skrivare under <a href="<?= e(u('/admin/system')) ?>">System</a>.
        </div>
    </div>

    <?php if ($artikel === null): ?>
    <div class="kort" style="margin:16px 0;background:rgba(47,107,255,.06)">
        <h2 style="font-size:1rem;margin-top:0">Etiketter direkt</h2>
        <p class="falt-hjalp" style="margin-top:0">
            Välj antal så skapas etiketterna i samma steg som artikeln – du slipper
            gå via Etiketter efteråt. Fungerar bara för <strong>QR per fysisk enhet</strong>.
            Etiketterna räknas in i saldot först när de tas emot via inleverans.
        </p>
        <div class="rad">
            <div class="falt">
                <label for="antal_etiketter">Antal etiketter</label>
                <input id="antal_etiketter" name="antal_etiketter" type="number" min="0" max="60" step="1" value="0">
            </div>
            <div class="falt">
                <label for="etikett_plats">Arbetsställe för etiketterna</label>
                <select id="etikett_plats" name="etikett_plats">
                    <option value="">Välj …</option>
                    <?php foreach (($platser ?? []) as $p): ?>
                        <option value="<?= e($p['id']) ?>"><?= e($p['namn']) ?> (<?= e($p['kod']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php // ---- Verktygstavla: artikeln innehåller andra artiklar ---- ?>
    <?php if (!empty($farHanteraTavla)): ?>
    <div class="kort" style="margin-top:16px" id="tavla-falt">
        <h2>Verktygstavla eller låda</h2>
        <p class="dampad">Är det en tavla eller låda med flera verktyg i? Då kan du lista delarna här.
            Varje del är en egen artikel med eget saldo och egen etikett, och de följer med
            automatiskt när tavlan tas ut.</p>

        <label style="display:flex;align-items:center;gap:8px;font-weight:600">
            <?php /* Ingen onchange här: CSP är script-src 'self' och blockerar
                     inline-skript. app.js sköter växlingen (data-tavla-vaxla). */ ?>
            <input type="checkbox" name="ar_tavla" value="1" id="ar_tavla"
                   data-tavla-vaxla="delar-lista" <?= !empty($arTavla) ? 'checked' : '' ?>>
            Den här artikeln är en tavla/låda som innehåller andra artiklar
        </label>
        <input type="hidden" name="hade_innehall" value="<?= ($taveldelar ?? []) !== [] ? '1' : '0' ?>">

        <div id="delar-lista" <?= empty($arTavla) ? 'hidden' : '' ?> style="margin-top:1rem">
            <?php $befintliga = $taveldelar ?? []; ?>
            <?php if ($befintliga !== []): ?>
                <div class="tabell-rull">
                <table>
                    <tr><th>Del</th><th>Placering</th><th>Antal</th><th></th></tr>
                    <?php foreach ($befintliga as $i => $rad): ?>
                        <tr>
                            <td>
                                <?= e($rad['artikel']['namn'] ?? 'Borttagen artikel') ?>
                                <br><span class="dampad mono"><?= e($rad['artikel']['artikelnummer'] ?? '') ?></span>
                                <input type="hidden" name="del_artikel[]" value="<?= e($rad['artikel_id']) ?>">
                            </td>
                            <td>
                                <input type="text" name="del_fack[]" maxlength="40" placeholder="t.ex. A3"
                                       value="<?= e($rad['fack'] ?? '') ?>" style="width:110px">
                            </td>
                            <td>
                                <input type="number" name="del_antal[]" min="1" max="<?= (int) \TDL\Domain\Tavlor::MAX_ANTAL ?>"
                                       value="<?= (int) $rad['antal'] ?>" style="width:90px">
                            </td>
                            <td><span class="dampad">Sätt antal till 0 för att ta bort</span></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <p class="falt-hjalp">Vill du flytta en del till en annan tavla? Öppna artikelns sida
                    och använd <strong>Flytta</strong> i raden – då sker flytten i ett svep.</p>
                <p class="falt-hjalp">För att lägga till fler delar: spara artikeln först och använd
                    knappen <strong>Lägg till del</strong> på artikelsidan.</p>
            <?php else: ?>
                <p class="dampad">Inga delar ännu. Spara artikeln först – lägg sedan till delarna
                    på artikelsidan.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <label for="bild">Artikelbild (valfritt – fota direkt eller välj fil)</label>
    <input id="bild" name="bild" type="file" accept="image/*" capture="environment">
    <div id="bild-forhand" style="margin-top:8px"></div>
    <script>
    (function () {
        var b = document.getElementById('bild'), p = document.getElementById('bild-forhand');
        if (!b || !p) return;
        b.addEventListener('change', function () {
            p.innerHTML = '';
            if (!b.files || !b.files[0]) return;
            var file = b.files[0];
            var reader = new FileReader();
            reader.onload = function (ev) {
                var img = new Image();
                img.onload = function () {
                    var max = 1200, w = img.width, h = img.height;
                    if (w > max || h > max) {
                        if (w > h) { h = Math.round(h * max / w); w = max; }
                        else { w = Math.round(w * max / h); h = max; }
                    }
                    var c = document.createElement('canvas');
                    c.width = w; c.height = h;
                    c.getContext('2d').drawImage(img, 0, 0, w, h);
                    c.toBlob(function (blob) {
                        if (blob && typeof DataTransfer !== 'undefined') {
                            var dt = new DataTransfer();
                            dt.items.add(new File([blob], (file.name || 'foto') + '.jpg', { type: 'image/jpeg' }));
                            b.files = dt.files;
                        }
                        var prev = document.createElement('img');
                        prev.src = c.toDataURL('image/jpeg', 0.82);
                        prev.style.cssText = 'max-width:160px;max-height:160px;border-radius:10px;object-fit:cover;';
                        p.innerHTML = '';
                        p.appendChild(prev);
                    }, 'image/jpeg', 0.82);
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        });
    })();
    </script>

    <div class="actions">
        <button type="submit" class="primar"><?= $artikel === null ? 'Skapa artikel' : 'Spara' ?></button>
        <a class="knapp" href="<?= e(u('/artiklar' . ($artikel !== null ? '/' . e($artikel['id']) : ''))) ?>">Avbryt</a>
    </div>
</form>
