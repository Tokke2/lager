<div class="flikar" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <a class="knapp sekundar" href="<?= e(u('/artiklar')) ?>">📦 Artiklar</a>
    <a class="knapp primar" href="<?= e(u('/inventering')) ?>">📋 Inventering</a>
    <a class="knapp sekundar" href="<?= e(u('/miniminiva')) ?>">⚠️ Miniminivå</a>
</div>
<?php
/**
 * Inventering av ett arbetsställe – flera artiklar i samma räkning.
 *
 * Tre grupper, i den ordning man jobbar:
 *   1. Kvar att räkna   – artiklar med saldo kvar
 *   2. Avvikelser       – räknade med differens
 *   3. Klara            – räknade utan avvikelse (kollapsad)
 *
 * Antalsartiklar räknas med +/− direkt i listan (autosparat).
 * QR-artiklar räknas genom att skanna enhetskoden.
 *
 * @var array $session @var array $plats @var array $lage @var array $utanfor @var bool $arAdmin
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$oppen = ($session['status'] ?? '') === 'öppen';
$arGammal = \TDL\Domain\Inventering::arGammal($session);
$rader = $lage['rader'];

$kvar    = array_values(array_filter($rader, fn ($r) => !$r['raknad']));
$avvik   = array_values(array_filter($rader, fn ($r) => $r['raknad'] && $r['differens'] !== 0));
$klara   = array_values(array_filter($rader, fn ($r) => $r['raknad'] && $r['differens'] === 0));
$procent = $lage['antal_artiklar'] > 0
    ? (int) round($lage['antal_raknade'] / $lage['antal_artiklar'] * 100)
    : 0;
?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0">Inventering <span class="mono"><?= e($session['id']) ?></span></h1>
    <a class="knapp" href="<?= e(u('/inventering')) ?>">Alla inventeringar</a>
</div>
<p class="dampad">
    <strong style="color:var(--text)"><?= e($plats['namn'] ?? '?') ?></strong> ·
    startad <?= e(sv_tid($session['startad'] ?? null)) ?> av <?= e($session['startad_av_namn'] ?? '–') ?> ·
    <span class="tag <?= $oppen ? 'varning' : 'ok' ?>"><?= e($session['status']) ?></span>
</p>

<?php if ($arGammal): ?>
    <div class="flash info">
        Den här inventeringen skapades före uppdateringen och gäller <strong>bara en artikel</strong>.
        Du kan räkna klart och avsluta den, men inte lägga till fler artiklar.
    </div>
<?php endif; ?>

<!-- Framsteg -->
<div class="kort">
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <h2 style="margin:0">Räkningen</h2>
        <span class="dampad" style="margin:0" id="framsteg-text"
              data-total="<?= (int) $lage['antal_artiklar'] ?>">
            <strong style="color:var(--text)" id="framsteg-raknat"><?= (int) $lage['antal_raknade'] ?></strong>
            av <span id="framsteg-total"><?= (int) $lage['antal_artiklar'] ?></span> artiklar räknade
            <span id="framsteg-avvikelse"<?= $lage['antal_avvikelser'] > 0 ? '' : ' hidden' ?>>
                · <span class="tag varning"><span id="framsteg-avvikelse-tal"><?= (int) $lage['antal_avvikelser'] ?></span>
                  <span id="framsteg-avvikelse-ord">avvikelse<?= $lage['antal_avvikelser'] === 1 ? '' : 'r' ?></span></span>
            </span>
        </span>
    </div>
    <div style="height:10px;background:var(--kant);border-radius:999px;overflow:hidden;margin:10px 0 4px">
        <div id="framsteg-stapel" style="height:100%;width:<?= $procent ?>%;background:var(--accent);transition:width .3s"></div>
    </div>
    <p class="dampad" style="margin:4px 0 0;font-size:.85rem" id="framsteg-undertext">
        <?= $procent ?> % klart<?= $lage['antal_kvar'] > 0 ? ' · ' . (int) $lage['antal_kvar'] . ' kvar att räkna' : ' · allt räknat' ?>
    </p>
</div>

<?php if ($oppen && !$arGammal): ?>
<!-- Skanna -->
<div class="kort">
    <h2>Skanna en enhet</h2>
    <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/skanna')) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="falt" style="flex:1 1 260px;margin:0">
            <label for="enhetskod">Enhetskod (handscanner eller kamera)</label>
            <input id="enhetskod" name="enhetskod" required autofocus autocomplete="off" class="mono"
                   placeholder="TDL-U-…" inputmode="text">
        </div>
        <button type="submit" class="primar">Räkna</button>
        <button type="button" id="starta-kamera" class="knapp">Kamera</button>
    </form>
    <p class="falt-hjalp" style="margin-top:8px">
        En skanning räknar enheten i rätt artikel automatiskt – du behöver inte välja artikel först.
    </p>
</div>
<?php endif; ?>

<?php if ($oppen): ?>
<!-- Artikellistan -->
<div class="kort">
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <h2 style="margin:0">Räkna artiklar</h2>
        <?php if ($lage['antal_artiklar'] > 1 && !$arGammal): ?>
            <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/antal')) ?>" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="artikel_id" value="">
                <button type="submit" class="liten">Spara alla ändringar</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($rader === []): ?>
        <p class="dampad">Inga artiklar i inventeringen ännu.</p>
    <?php else: ?>
    <div class="tabell-rull">
    <table class="inventerings-tabell">
        <thead>
            <tr>
                <th>Artikel</th>
                <th class="tal-kol">Förväntat</th>
                <th class="tal-kol">Räknat</th>
                <th class="tal-kol">Differens</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rader as $r): ?>
            <?php
            $diff = (int) $r['differens'];
            $klass = !$r['raknad'] ? '' : ($diff === 0 ? 'rad-ok' : ($diff < 0 ? 'rad-fattas' : 'rad-overskott'));
            ?>
            <tr class="<?= $klass ?>"
                data-raknad="<?= $r['raknad'] ? '1' : '0' ?>"
                data-avvikelse="<?= ($r['raknad'] && $diff !== 0) ? '1' : '0' ?>">
                <td>
                    <a href="<?= e(u('/artiklar/' . e($r['artikel_id']))) ?>"><?= e($r['namn']) ?></a>
                    <span class="dampad mono" style="font-size:.78rem"><?= e($r['artikelnummer']) ?></span>
                    <?php if ($r['typ'] === 'qr'): ?><span class="tag">QR</span><?php endif; ?>
                </td>
                <td class="tal-kol mono" data-etikett="Förväntat"><?= (int) $r['forvantat'] ?></td>
                <td class="tal-kol">
                    <?php if ($r['typ'] === 'qr'): ?>
                        <span class="mono" style="font-weight:700"><?= (int) $r['raknat'] ?></span>
                    <?php elseif ($oppen): ?>
                        <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/antal')) ?>"
                              class="rakna-form" data-autoskicka>
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="artikel_id" value="<?= e($r['artikel_id']) ?>">
                            <div class="rakna-grupp">
                                <button type="submit" name="steg" value="-1" class="rakna-knapp"
                                        aria-label="Minska <?= e($r['namn']) ?>">−</button>
                                <input type="number" name="antal" min="0" step="1"
                                       value="<?= (int) $r['visat'] ?>" class="rakna-falt"
                                       data-raknat="<?= (int) $r['raknat'] ?>"
                                       aria-label="Räknat antal för <?= e($r['namn']) ?>"
                                       inputmode="numeric">
                                <button type="submit" name="steg" value="1" class="rakna-knapp"
                                        aria-label="Öka <?= e($r['namn']) ?>">+</button>
                            </div>
                            <noscript><button type="submit" class="liten">Spara</button></noscript>
                        </form>
                    <?php else: ?>
                        <span class="mono" style="font-weight:700"><?= (int) $r['raknat'] ?></span>
                    <?php endif; ?>
                </td>
                <td class="tal-kol" data-etikett="Differens">
                    <?php if (!$r['raknad']): ?>
                        <span class="dampad">–</span>
                    <?php elseif ($diff === 0): ?>
                        <span class="tag ok">✓</span>
                    <?php else: ?>
                        <span class="tag <?= $diff < 0 ? 'fel' : 'varning' ?>">
                            <?= $diff > 0 ? '+' . $diff : $diff ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <?php if ($oppen && !$arGammal): ?>
                        <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/ta-bort')) ?>" style="margin:0"
                              data-bekrafta="Ta bort <?= e($r['namn']) ?> ur inventeringen? Räknade värden försvinner.">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="artikel_id" value="<?= e($r['artikel_id']) ?>">
                            <button type="submit" class="liten" title="Ta bort ur inventeringen">×</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($oppen && $utanfor !== [] && !$arGammal): ?>
        <details style="margin-top:16px">
            <summary style="cursor:pointer;font-weight:600">
                Lägg till fler artiklar (<?= count($utanfor) ?> finns på platsen)
            </summary>
            <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/lagg-till')) ?>"
                  style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="falt" style="flex:1 1 280px;margin:0">
                    <label for="lagg-till-artikel">Artikel</label>
                    <select id="lagg-till-artikel" name="artikel_id" required>
                        <option value="">Välj …</option>
                        <?php foreach ($utanfor as $a): ?>
                            <option value="<?= e($a['id']) ?>">
                                <?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>)
                                <?= $a['sparningssatt'] === 'qr' ? '– QR' : '– saldo ' . (int) ($a['saldo'][$session['plats_id']] ?? 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="primar">Lägg till</button>
            </form>
        </details>
    <?php endif; ?>
</div>

<?php if ($lage['saknade'] !== [] || $lage['okanda'] !== []): ?>
<div class="kort">
    <h2>Avvikelser i detalj</h2>
    <?php if ($lage['saknade'] !== []): ?>
        <h3>Saknade enheter (<?= count($lage['saknade']) ?>)</h3>
        <p class="falt-hjalp">Dessa enheter förväntades finnas men skannades aldrig.</p>
        <div class="tabell-rull">
        <table>
            <tr><th>Enhetskod</th><th>Artikel</th><th>Status</th></tr>
            <?php foreach ($lage['saknade'] as $en): ?>
                <tr>
                    <td class="mono"><?= e(\TDL\Support\Koder::visa($en['id'] ?? '')) ?></td>
                    <td><?= e($en['artikelnamn'] ?? '') ?></td>
                    <td><span class="tag fel">saknad</span></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
    <?php if ($lage['okanda'] !== []): ?>
        <h3>Skannade men ej förväntade (<?= count($lage['okanda']) ?>)</h3>
        <p class="falt-hjalp">Dessa läggs aldrig till automatiskt. De måste hanteras separat (t.ex. som överföring).</p>
        <div class="tabell-rull">
        <table>
            <tr><th>Enhetskod</th><th>Artikel</th><th>Status</th><th>Plats</th></tr>
            <?php foreach ($lage['okanda'] as $en): ?>
                <?php $p = !empty($en['plats_id']) ? \TDL\Domain\Lager::plats($en['plats_id']) : null; ?>
                <tr>
                    <td class="mono"><?= e(\TDL\Support\Koder::visa($en['id'] ?? '')) ?></td>
                    <td><?= e($en['artikelnamn'] ?? '') ?></td>
                    <td><?= e($en['status'] ?? '') ?></td>
                    <td><?= $p ? e($p['namn']) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Avslut -->
<div class="kort">
    <h2>Avsluta inventeringen</h2>

    <div class="flash info" id="kvar-varning"<?= $lage['antal_kvar'] > 0 ? '' : ' hidden' ?>>
        <strong><span id="kvar-tal"><?= (int) $lage['antal_kvar'] ?></span>
        <span id="kvar-ord"><?= $lage['antal_kvar'] === 1 ? 'artikel är inte räknad' : 'artiklar är inte räknade' ?></span>.</strong>
        De räknas som oförändrade om du avslutar nu. Vill du hellre räkna dem först, gör det ovan.
    </div>

    <?php if ($avvik !== []): ?>
        <h3>Stäm av avvikelser (<?= count($avvik) ?> <?= count($avvik) === 1 ? 'artikel' : 'artiklar' ?>)</h3>
        <p class="falt-hjalp">
            Avstämning ändrar lagret så det matchar det räknade. Antalsartiklar får saldot satt till
            räknat antal; saknade QR-enheter markeras som saknade. Allt bokförs i historiken.
            <?php if (!$arAdmin): ?><br><strong>Kräver administratör.</strong><?php endif; ?>
        </p>
        <div class="tabell-rull">
        <table>
            <tr><th>Artikel</th><th class="tal-kol">Förväntat</th><th class="tal-kol">Räknat</th><th class="tal-kol">Ändring</th></tr>
            <?php foreach ($avvik as $r): ?>
                <tr>
                    <td><?= e($r['namn']) ?></td>
                    <td class="tal-kol mono" data-etikett="Förväntat"><?= (int) $r['forvantat'] ?></td>
                    <td class="tal-kol mono"><?= (int) $r['raknat'] ?></td>
                    <td class="tal-kol mono"><?= $r['differens'] > 0 ? '+' . (int) $r['differens'] : (int) $r['differens'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>

        <?php if ($arAdmin): ?>
        <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/avstamning')) ?>"
              data-bekrafta="Bekräfta avstämning: lagret ändras för <?= count($avvik) ?> artikel(er) och kan inte ångras."
              style="margin-top:12px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label for="bekraftelse">Skriv <span class="mono">stam av</span> för att bekräfta</label>
            <input id="bekraftelse" name="bekraftelse" required autocomplete="off" style="max-width:240px">
            <div class="actions">
                <button type="submit" class="fara">Genomför avstämning</button>
            </div>
        </form>
        <?php else: ?>
            <p class="dampad">En administratör måste genomföra avstämningen.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="dampad" id="avvikelse-tom">
            <?= $lage['antal_raknade'] === 0
                ? 'Inget är räknat ännu.'
                : 'Inga avvikelser att stämma av – förväntat och räknat stämmer.' ?>
        </p>
    <?php endif; ?>
    <?php if ($lage['antal_avvikelser'] !== count($avvik)): ?>
        <p class="falt-hjalp">
            Avvikelselistan uppdateras när sidan laddas om.
        </p>
    <?php endif; ?>

    <hr style="border:none;border-top:1px solid var(--kant);margin:18px 0">

    <h3>Avsluta utan avstämning</h3>
    <p class="falt-hjalp">Differensen sparas som dokumenterad avvikelse. Inget saldo ändras.</p>
    <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/avsluta')) ?>"
          data-bekrafta="Avsluta inventeringen? Differensen bokförs men lagret ändras inte.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="motivering">Motivering (minst 3 tecken)</label>
        <input id="motivering" name="motivering" required minlength="3" autocomplete="off" style="max-width:420px">
        <div class="actions">
            <button type="submit">Avsluta utan avstämning</button>
        </div>
    </form>

    <?php if ($arAdmin): ?>
    <hr style="border:none;border-top:1px solid var(--kant);margin:18px 0">
    <h3>Annullera</h3>
    <p class="falt-hjalp">Om inventeringen startades av misstag. Inget saldo rörs och räkningen försvinner.</p>
    <form method="post" action="<?= e(u('/inventering/' . (e($session['id'])) . '/annullera')) ?>"
          data-bekrafta="Annullera hela inventeringen? Räkningen försvinner.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="motivering-annullera">Motivering</label>
        <input id="motivering-annullera" name="motivering" required minlength="3" autocomplete="off" style="max-width:420px">
        <div class="actions">
            <button type="submit" class="fara liten">Annullera inventering</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$oppen): ?>
<div class="kort">
    <h2>Resultat</h2>
    <p class="dampad">
        <?= (int) $lage['antal_artiklar'] ?> artiklar ·
        <?= (int) $lage['antal_raknade'] ?> räknade ·
        <?= (int) $lage['antal_avvikelser'] ?> med avvikelse
    </p>
    <p class="dampad">
        Avslutad <?= e(sv_tid($session['avslutad'] ?? null)) ?> av <?= e($session['avslutad_av_namn'] ?? '–') ?>
        <?php if (($session['avstamning']['typ'] ?? '') === 'uttag'): ?>
            · avstämd: <?= (int) ($session['avstamning']['justerade'] ?? 0) ?> enheter justerade
        <?php elseif (($session['avstamning']['typ'] ?? '') === 'ingen'): ?>
            · avslutad utan avstämning (<?= e($session['avstamning']['motivering'] ?? '') ?>)
        <?php elseif (($session['avstamning']['typ'] ?? '') === 'annullerad'): ?>
            · annullerad (<?= e($session['avstamning']['motivering'] ?? '') ?>)
        <?php endif; ?>
    </p>
    <div class="tabell-rull">
    <table>
        <tr><th>Artikel</th><th class="tal-kol">Förväntat</th><th class="tal-kol">Räknat</th><th class="tal-kol">Differens</th></tr>
        <?php foreach ($rader as $r): ?>
            <tr>
                <td><?= e($r['namn']) ?></td>
                <td class="tal-kol mono" data-etikett="Förväntat"><?= (int) $r['forvantat'] ?></td>
                <td class="tal-kol mono"><?= (int) $r['raknat'] ?></td>
                <td class="tal-kol mono">
                    <?= $r['differens'] > 0 ? '+' . (int) $r['differens'] : (int) $r['differens'] ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>

<style>
    .inventerings-tabell td { vertical-align: middle; }
    .inventerings-tabell tr.rad-ok     { background: rgba(52, 211, 153, .07); }
    .inventerings-tabell tr.rad-fattas { background: rgba(239, 68, 68, .08); }
    .inventerings-tabell tr.rad-overskott { background: rgba(245, 158, 11, .08); }
    .tal-kol { text-align: right; white-space: nowrap; }

    /* Räkna direkt i listan */
    .rakna-form { display: inline-flex; align-items: center; gap: 4px; margin: 0; max-width: 100%; }
    .rakna-grupp { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    .rakna-knapp {
        width: 40px; height: 40px; padding: 0; font-size: 1.25rem; line-height: 1;
        border-radius: 10px; border: 1px solid var(--kant); background: var(--yta);
        color: var(--text); cursor: pointer; font-weight: 700;
    }
    .rakna-knapp:hover { border-color: var(--accent); }
    .rakna-falt {
        width: 78px; height: 40px; text-align: center; font-size: 1.05rem;
        font-weight: 700; padding: 0 4px; font-variant-numeric: tabular-nums;
    }
    @media (max-width: 620px) {
        .rakna-knapp { width: 48px; height: 48px; }   /* tumvänligt på mobil */
        .rakna-falt { width: 68px; height: 48px; font-size: 1.15rem; }

        /* Mobil: varje artikel blir ett eget kort i stället för en tabellrad */
        .inventerings-tabell thead { display: none; }
        .inventerings-tabell, .inventerings-tabell tbody, .inventerings-tabell tr,
        .inventerings-tabell td { display: block; width: auto; }
        .inventerings-tabell tr {
            border: 1px solid var(--kant); border-radius: 12px;
            padding: 10px 12px; margin-bottom: 8px; position: relative;
        }
        .inventerings-tabell tr.rad-ok     { border-color: #1d5c45; }
        .inventerings-tabell tr.rad-fattas { border-color: #5c2a2a; }
        .inventerings-tabell tr.rad-overskott { border-color: #5c4a1a; }
        .inventerings-tabell td { border: none; padding: 2px 0; text-align: left; }
        /* Etikett före värdet i stället för kolumnrubrik */
        .inventerings-tabell td[data-etikett] {
            display: inline-flex; gap: 6px; align-items: baseline; margin-right: 14px;
        }
        .inventerings-tabell td[data-etikett]::before {
            content: attr(data-etikett) ':'; color: var(--dampad); font-size: .8rem;
        }
        /* Räknaren får egen rad och full tumstorlek */
        .inventerings-tabell td:nth-child(3) { margin-top: 8px; }
        .rakna-form { width: 100%; }
        .rakna-grupp { width: 100%; justify-content: space-between; }
        .rakna-falt { flex: 1 1 auto; }
        /* Artikelnamnet får plats att brytas – det är viktigast */
        .inventerings-tabell td:first-child { padding-right: 36px; line-height: 1.35; }
        .inventerings-tabell td:first-child .dampad { display: block; margin-top: 2px; }
        /* Ta bort-krysset uppe till höger */
        .inventerings-tabell td:last-child {
            position: absolute; top: 6px; right: 6px; width: auto; padding: 0;
        }
    }
</style>
