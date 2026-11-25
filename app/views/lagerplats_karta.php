<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<?php
$z = $karta['zon'];
$rutor = $karta['rutor'];
$max = max(1, (int) $karta['max']);
?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0">Lagerkarta</h1>
    <div class="actions" style="margin:0">
        <a class="knapp liten" href="<?= e(u('/lagerplatser')) ?>">Zoner</a>
    </div>
</div>

<form method="get" action="<?= e(u('/lagerkarta')) ?>" class="kort">
    <?php if (\TDL\Support\Auth::iRutaMiljo()): ?><input type="hidden" name="tdlagersid" value="<?= e(session_id()) ?>"><?php endif; ?>
    <label for="karta-artikel">Hitta en artikel på kartan</label>
    <div class="actions" style="margin:0">
        <select id="karta-artikel" name="artikel" required>
            <option value="">Välj artikel …</option>
            <?php foreach ($sokArtiklar as $a): ?>
                <option value="<?= e($a['id']) ?>" <?= ($soktArtikel['id'] ?? '') === $a['id'] ? 'selected' : '' ?>><?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Visa plats</button>
    </div>
</form>
<?php if ($soktArtikel !== null): ?>
    <?php if (($soktArtikel['zon_id'] ?? '') === $z['id'] && (int) ($soktArtikel['rad'] ?? 0) >= 1 && (int) $soktArtikel['rad'] <= (int) $z['rader'] && (int) ($soktArtikel['fack'] ?? 0) >= 1 && (int) $soktArtikel['fack'] <= (int) $z['fack']): ?>
        <p class="flash info"><strong><?= e($soktArtikel['namn']) ?></strong> finns på <?= e($z['kod']) ?>–<?= (int) $soktArtikel['rad'] ?>–<?= (int) $soktArtikel['fack'] ?>. Den markerade rutan visar platsen.</p>
    <?php else: ?>
        <p class="flash varning">Artikeln saknar en giltig plats på kartan.</p>
    <?php endif; ?>
<?php endif; ?>

<form method="get" action="<?= e(u('/lagerkarta')) ?>" class="actions" style="margin-top:0">
    <?php if (\TDL\Support\Auth::iRutaMiljo()): ?><input type="hidden" name="tdlagersid" value="<?= e(session_id()) ?>"><?php endif; ?>
    <select name="zon" data-skicka-vid-andring style="width:auto;min-width:220px">
        <?php foreach ($zoner as $zz): ?>
            <option value="<?= e($zz['id']) ?>" <?= $zz['id'] === $z['id'] ? 'selected' : '' ?>>
                <?= e($zz['kod']) ?> – <?= e($zz['namn']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="vaxlare" role="tablist" aria-label="Vy">
        <button type="button" class="vald" data-karta-vy="2d">2D</button>
        <button type="button" data-karta-vy="3d">3D</button>
    </div>
</form>

<p class="dampad">Zon <strong><?= e($z['kod']) ?> – <?= e($z['namn']) ?></strong>.
    Varje ruta är en hylla. Siffran är antalet artiklar, färgen visar hur full hyllan är.
    Klicka på en ruta för att se vad som står där.</p>

<?php /* ---------- 2D: rutnät ovanifrån ---------- */ ?>
<div id="karta-2d">
    <div class="kort">
        <div class="karta-rull">
            <div class="karta-rutnat" style="grid-template-columns: 44px repeat(<?= (int) $z['fack'] ?>, minmax(78px, 1fr))">
                <div class="karta-horn"></div>
                <?php for ($f = 1; $f <= (int) $z['fack']; $f++): ?>
                    <div class="karta-kolhuv"><?= $f ?></div>
                <?php endfor; ?>

                <?php foreach ($rutor as $rad => $kolumner): ?>
                    <div class="karta-radhuv">R<?= (int) $rad ?></div>
                    <?php foreach ($kolumner as $ruta): ?>
                        <?php
                        $antal = (int) $ruta['antal'];
                        $markerad = $soktArtikel !== null && ($soktArtikel['zon_id'] ?? '') === $z['id']
                            && (int) ($soktArtikel['rad'] ?? 0) === (int) $ruta['rad']
                            && (int) ($soktArtikel['fack'] ?? 0) === (int) $ruta['fack'];
                        // 0 artiklar = tom, annars färg efter andel av max
                        $styrka = $max > 0 ? $antal / $max : 0;
                        $klass = $antal === 0 ? 'tom' : ($styrka >= 0.66 ? 'full' : ($styrka >= 0.33 ? 'halv' : 'lag'));
                        $namn = [];
                        foreach (array_slice($ruta['artiklar'], 0, 4) as $a) {
                            $namn[] = $a['namn'];
                        }
                        $tips = $z['kod'] . '-' . $ruta['rad'] . '-' . $ruta['fack'];
                        if ($antal === 0) {
                            $tips .= ' · tom hylla';
                        } else {
                            $tips .= ' · ' . implode(', ', $namn) . ($antal > 4 ? ' +' . ($antal - 4) : '');
                        }
                        $lank = $ruta['hyllplats'] !== null
                            ? '/hylla/' . $ruta['hyllplats']['id']
                            : '/lagerkarta/ruta?zon=' . $z['id'] . '&rad=' . $ruta['rad'] . '&fack=' . $ruta['fack'];
                        ?>
                        <a class="karta-ruta <?= $klass ?><?= $markerad ? ' karta-markerad' : '' ?>" <?= $markerad ? 'data-karta-markerad aria-current="location"' : '' ?> href="<?= e(u($lank)) ?>" title="<?= e($tips) ?>">
                            <span class="karta-koord mono"><?= (int) $ruta['rad'] ?>-<?= (int) $ruta['fack'] ?></span>
                            <?php if ($antal > 0): ?>
                                <span class="karta-antal"><?= $antal ?></span>
                            <?php else: ?>
                                <span class="karta-tom">–</span>
                            <?php endif; ?>
                            <?php if ($ruta['hyllplats'] === null): ?>
                                <span class="karta-ingen-qr" title="Ingen hyllapp skapad">•</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="karta-teckenforklaring">
            <span><i class="karta-punkt tom"></i> tom</span>
            <span><i class="karta-punkt lag"></i> 1–<?= max(1, (int) floor($max / 3)) ?></span>
            <span><i class="karta-punkt halv"></i> <?= max(2, (int) floor($max / 2)) ?>–<?= max(2, (int) ceil($max * 2 / 3)) ?></span>
            <span><i class="karta-punkt full"></i> <?= max(3, (int) ceil($max * 2 / 3) + 1) ?>+</span>
            <span class="dampad">· <span class="karta-ingen-qr">•</span> = ingen hyllapp utskriven</span>
        </div>
    </div>
</div>

<?php /* ---------- 3D: hyllorna som block ---------- */ ?>
<div id="karta-3d" hidden>
    <div class="kort">
        <div class="karta-3d-scen">
            <?php
            // Djupet ökar bakåt: rad 1 ligger längst fram.
            $antalRader = count($rutor);
            foreach ($rutor as $rad => $kolumner):
                $djup = $antalRader - (int) $rad; // rad 1 = störst z (längst fram)
            ?>
                <div class="karta-3d-rad" style="transform: translateZ(<?= ($djup - ($antalRader / 2)) * 40 ?>px) translateY(<?= ((int) $rad - ($antalRader + 1) / 2) * 12 ?>px)">
                    <div class="karta-3d-etikett">R<?= (int) $rad ?></div>
                    <?php foreach ($kolumner as $ruta): ?>
                        <?php
                        $antal = (int) $ruta['antal'];
                        $markerad = $soktArtikel !== null && ($soktArtikel['zon_id'] ?? '') === $z['id']
                            && (int) ($soktArtikel['rad'] ?? 0) === (int) $ruta['rad']
                            && (int) ($soktArtikel['fack'] ?? 0) === (int) $ruta['fack'];
                        $hojd = $antal === 0 ? 8 : 14 + (int) round(46 * ($antal / $max));
                        $styrka = $max > 0 ? $antal / $max : 0;
                        $klass = $antal === 0 ? 'tom' : ($styrka >= 0.66 ? 'full' : ($styrka >= 0.33 ? 'halv' : 'lag'));
                        $lank = $ruta['hyllplats'] !== null
                            ? '/hylla/' . $ruta['hyllplats']['id']
                            : '/lagerkarta/ruta?zon=' . $z['id'] . '&rad=' . $ruta['rad'] . '&fack=' . $ruta['fack'];
                        ?>
                        <a class="karta-3d-hylla <?= $klass ?><?= $markerad ? ' karta-markerad' : '' ?>" href="<?= e(u($lank)) ?>"
                           title="<?= e($z['kod'] . '-' . $ruta['rad'] . '-' . $ruta['fack']) ?> · <?= $antal ?> artiklar">
                            <span class="topp" style="height:<?= $hojd ?>px"><?= $antal > 0 ? $antal : '' ?></span>
                            <span class="sida"></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="falt-hjalp" style="text-align:center;margin-top:18px">
            Rad 1 står längst fram. Höjden visar hur många artiklar hyllan har – tomma hyllor syns som låga streck.
        </p>
    </div>
</div>

<style>
/* ---------- 2D ---------- */
.karta-rull { overflow-x: auto; padding-bottom: 6px; }
.karta-rutnat { display: grid; gap: 6px; align-items: stretch; }
.karta-horn { }
.karta-kolhuv, .karta-radhuv {
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; font-weight: 600; color: var(--dampad, #8b93a7);
    letter-spacing: .04em;
}
.karta-radhuv { justify-content: flex-end; padding-right: 6px; }
.karta-ruta {
    position: relative; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 2px;
    min-height: 62px; border-radius: 8px; text-decoration: none;
    border: 1px solid rgba(255,255,255,.12);
    background: rgba(255,255,255,.04); color: inherit;
    transition: transform .08s ease, border-color .12s ease;
}
.karta-ruta:hover { transform: translateY(-1px); border-color: rgba(255,255,255,.34); }
.karta-ruta.tom { background: rgba(255,255,255,.02); }
.karta-ruta.lag { background: rgba(56,142,60,.22); border-color: rgba(76,175,80,.34); }
.karta-ruta.halv { background: rgba(251,192,45,.22); border-color: rgba(251,192,45,.38); }
.karta-ruta.full { background: rgba(229,57,53,.24); border-color: rgba(229,57,53,.42); }
.karta-koord { font-size: .68rem; opacity: .62; }
.karta-antal { font-size: 1.02rem; font-weight: 700; }
.karta-tom { font-size: .9rem; opacity: .34; }
.karta-ingen-qr {
    position: absolute; top: 4px; right: 6px;
    font-size: .68rem; color: #d9a441; opacity: .95;
}
.karta-teckenforklaring {
    display: flex; flex-wrap: wrap; gap: 14px; align-items: center;
    margin-top: 14px; font-size: .8rem; color: var(--dampad, #8b93a7);
}
.karta-punkt { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 4px; vertical-align: -1px; }
.karta-punkt.tom { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.16); }
.karta-punkt.lag { background: rgba(56,142,60,.6); }
.karta-punkt.halv { background: rgba(251,192,45,.6); }
.karta-punkt.full { background: rgba(229,57,53,.6); }

/* ---------- 3D (ren CSS, inga bibliotek) ---------- */
.karta-3d-scen {
    position: relative;
    height: 520px;
    perspective: 900px;
    perspective-origin: 50% 50%;
    display: flex; flex-direction: column; justify-content: center;
    align-items: center;
    transform-style: preserve-3d;
    transform: rotateX(16deg) scale(.96);
    overflow: visible;
}
.karta-3d-rad {
    display: flex; align-items: flex-end; justify-content: center; gap: 10px;
    transform-style: preserve-3d;
    margin-bottom: 30px;
    /* Raden bakom ska synas svagare – ger djupkänsla */
    opacity: var(--rad-opacitet, 1);
}
.karta-3d-scen {
    transform-style: preserve-3d;
}
.karta-3d-etikett {
    width: 34px; font-size: .74rem; font-weight: 600;
    color: var(--dampad, #8b93a7); transform: translateZ(0);
}
.karta-3d-hylla {
    display: block; width: 52px; text-decoration: none;
    transform-style: preserve-3d;
    position: relative;
    transition: transform .12s ease;
}
.karta-3d-hylla:hover { transform: translateY(-4px); }
/* Framsidan av blocket ger hyllan kropp */
.karta-3d-hylla .sida {
    display: block; height: 7px;
    border-radius: 0 0 3px 3px;
    background: rgba(0,0,0,.42);
    border: 1px solid rgba(255,255,255,.10);
    border-top: 0;
}
.karta-3d-hylla .topp {
    display: block; border-radius: 4px 4px 2px 2px;
    border: 1px solid rgba(255,255,255,.16);
    border-bottom-width: 3px;
    text-align: center; font-size: .72rem; font-weight: 700;
    color: #fff; line-height: 1.4;
}
.karta-3d-hylla.tom .topp { background: rgba(255,255,255,.06); }
.karta-3d-hylla.lag .topp { background: rgba(56,142,60,.55); }
.karta-3d-hylla.halv .topp { background: rgba(251,192,45,.55); }
.karta-3d-hylla.full .topp { background: rgba(229,57,53,.62); }
.vaxlare { display: inline-flex; border: 1px solid rgba(255,255,255,.16); border-radius: 8px; overflow: hidden; }
.vaxlare button {
    background: transparent; border: 0; padding: 8px 16px; cursor: pointer;
    color: inherit; font: inherit; font-weight: 600;
}
.vaxlare button.vald { background: rgba(255,255,255,.14); }
@media (max-width: 720px) {
    .karta-3d-scen { height: 300px; }
    .karta-3d-hylla { width: 34px; }
}
</style>
