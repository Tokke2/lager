<?php
/**
 * Lokalplan – våningar med zoner, byggda av användarens foton.
 *
 * Flödet vyn understöder:
 *   1. Ladda upp foton (flera på en gång) – varje foto blir en zon.
 *   2. Tryck "Klart – lokalen är färdigfotad" → appen sätter ihop en skiss.
 *   3. Dra/ändra storlek på zonerna tills skissen stämmer. Spara.
 *   4. Fler våningar: ny flik, samma sak igen.
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$vaningId = $vald['id'] ?? '';
$planbild = $vald['bild'] ?? null;
$planbildUrl = \TDL\Domain\Lokalplan::bildUrl($vald, $planbild);
$antalFoton = count($foton);
?>
<div class="actions" style="justify-content:space-between;margin-top:0;flex-wrap:wrap;gap:8px">
    <h1 style="margin:0">Lokalplan</h1>
    <?php if ($plan !== null && $arAdmin): ?>
        <form method="post" action="<?= e(u('/lokalplan/ta-bort')) ?>"
              data-bekrafta="Ta bort hela lokalplanen? Alla våningar och bilder raderas.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="liten fara">Ta bort hela planen</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($plan === null): ?>
    <?php /* ---------- Ingen plan ännu: förklara och sätt igång ---------- */ ?>
    <div class="kort">
        <h2>Bygg en plan av lokalens foton</h2>
        <p class="dampad">
            Fota lokalen – ett foto per hörn eller område. Varje foto blir en zon på
            en planlösning. När du markerar <strong>Klart</strong> sätter appen ihop
            bilderna till en skiss, och du kan sedan flytta och ändra zonerna tills
            allt stämmer. Har du flera våningar gör du likadant för våning 2.
        </p>
        <?php if ($arAdmin): ?>
        <form method="post" action="<?= e(u('/lokalplan/bild')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="typ" value="foto">
            <label for="bild">Foton av lokalen (JPG, PNG eller WebP – max <?= (int) round(\TDL\Domain\Lokalplan::MAX_BYTES / 1024 / 1024) ?> MB per bild)</label>
            <input id="bild" name="bild[]" type="file" accept="image/*" capture="environment" multiple required>
            <div class="falt-hjalp">Du kan välja flera foton samtidigt. På mobilen öppnas kameran.</div>
            <div class="actions"><button type="submit" class="primar">Ladda upp foton</button></div>
        </form>
        <details style="margin-top:14px">
            <summary class="dampad" style="cursor:pointer">Har du en färdig ritning i stället?</summary>
            <form method="post" action="<?= e(u('/lokalplan/bild')) ?>" enctype="multipart/form-data" style="margin-top:10px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="typ" value="planbild">
                <input name="bild" type="file" accept="image/*" required>
                <div class="falt-hjalp">Ritningen blir underlag, och du placerar zonerna ovanpå den.</div>
                <div class="actions"><button type="submit">Ladda upp ritning</button></div>
            </form>
        </details>
        <?php else: ?>
            <p class="dampad">Ingen lokalplan är upplagd ännu. En administratör kan lägga upp den.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php /* ---------- Våningsflikar ---------- */ ?>
    <div class="vaning-flikar" role="tablist" aria-label="Våningar">
        <?php foreach ($vaningar as $v): ?>
            <a class="vaning-flik <?= $vald !== null && $v['id'] === $vald['id'] ? 'vald' : '' ?>"
               role="tab" aria-selected="<?= $vald !== null && $v['id'] === $vald['id'] ? 'true' : 'false' ?>"
               href="<?= e(u('/lokalplan?vaning=' . $v['id'])) ?>"><?= e($v['namn']) ?>
                <span class="vaning-antal"><?= count($v['bilder']) ?> foto</span>
            </a>
        <?php endforeach; ?>
        <?php if ($arAdmin): ?>
            <form method="post" action="<?= e(u('/lokalplan/vaning')) ?>" class="vaning-ny">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="liten" title="Lägg till nästa våning">+ Våning</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($vald !== null && $arAdmin): ?>
    <div class="kort">
        <div class="actions" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h2 style="margin:0"><?= e($vald['namn']) ?></h2>
            <div class="actions" style="margin:0;gap:6px">
                <form method="post" action="<?= e(u('/lokalplan/vaning/andra')) ?>" style="display:flex;gap:6px;margin:0">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
                    <input name="namn" value="<?= e($vald['namn']) ?>" maxlength="40" style="width:160px"
                           aria-label="Våningens namn" class="mono">
                    <button type="submit" class="liten">Byt namn</button>
                </form>
                <?php if (count($vaningar) > 1): ?>
                <form method="post" action="<?= e(u('/lokalplan/vaning/ta-bort')) ?>"
                      data-bekrafta="Ta bort <?= e($vald['namn']) ?> med alla dess foton och zoner?">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
                    <button type="submit" class="liten fara">Ta bort våningen</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php /* ---------- Steg 1: fota ---------- */ ?>
        <details style="margin-top:12px" <?= $antalFoton === 0 ? 'open' : '' ?>>
            <summary style="cursor:pointer;font-weight:600">
                <?= $antalFoton === 0 ? 'Ladda upp foton av lokalen' : 'Lägg till fler foton' ?>
                <span class="dampad" style="font-weight:400">(<?= $antalFoton ?> på den här våningen)</span>
            </summary>
            <form method="post" action="<?= e(u('/lokalplan/bild')) ?>" enctype="multipart/form-data" style="margin-top:10px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
                <input type="hidden" name="typ" value="foto">
                <label for="fler-bilder">Välj ett eller flera foton</label>
                <input id="fler-bilder" name="bild[]" type="file" accept="image/*" capture="environment" multiple required>
                <div class="falt-hjalp">Varje foto blir en zon. Du kan också byta planbild längst ner.</div>
                <div class="actions"><button type="submit" class="primar">Ladda upp</button></div>
            </form>
        </details>

        <?php if ($antalFoton > 0): ?>
        <?php /* ---------- Steg 2: klart ---------- */ ?>
        <form method="post" action="<?= e(u('/lokalplan/klart')) ?>" style="margin-top:12px"
              data-bekrafta="Sätt ihop fotona till en skiss? Zonernas placering läggs då i ett rutnät – du kan ändra efteråt.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
            <button type="submit" class="primar">Klart – lokalen är färdigfotad</button>
            <span class="dampad" style="margin-left:8px;font-size:.85rem">
                Appen sätter ihop bilderna till en skiss.
            </span>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($vald === null): ?>
        <div class="kort"><p class="dampad">Välj en våning ovan.</p></div>
    <?php else: ?>

    <?php /* ---------- Ytan med zonerna ---------- */ ?>
    <form method="post" action="<?= e(u('/lokalplan/rutor')) ?>" id="plan-form" data-lokalplan>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
        <input type="hidden" name="rutor" id="plan-rutor" value="">

        <div class="kort">
            <div class="actions" style="justify-content:space-between;margin:0 0 10px;flex-wrap:wrap;gap:6px">
                <h2 style="margin:0"><?= e($vald['namn']) ?> – skiss</h2>
                <span class="dampad" style="font-size:.85rem">
                    Dra i rutan för att flytta · dra i hörnet för att ändra storlek
                </span>
            </div>

            <?php if ($egna === [] && $lediga === []): ?>
                <p class="dampad">Det finns inga zoner ännu.
                    <a href="<?= e(u('/lagerplatser/ny')) ?>">Skapa en zon</a> – eller ladda upp foton ovan.</p>
            <?php endif; ?>

            <div class="plan-yta" data-plan-yta
                 style="<?= $planbildUrl === '' ? 'aspect-ratio:' . (int) $ytaBredd . '/' . (int) $ytaHojd : '' ?>">
                <?php if ($planbildUrl !== ''): ?>
                    <img src="<?= e($planbildUrl) ?>" alt="Planbild för <?= e($vald['namn']) ?>" draggable="false">
                <?php endif; ?>

                <?php
                // Bara våningens EGNA zoner ritas. Oplacerade zoner ligger
                // inte på ytan och blir därför aldrig "råkade" sparas in –
                // de placeras medvetet med knappen nedanför i stället.
                $visaZoner = $egna;
                $placerade = count($rutor);
                $kolumner = max(1, min(4, count($visaZoner) ?: 1));
                ?>
                <?php foreach ($visaZoner as $i => $z): ?>
                    <?php
                    $r = $rutor[$z['id']] ?? null;
                    // Oplacerade zoner sprids i ett rutnät – annars ligger de
                    // på samma fläck och bara den översta går att dra.
                    $kol = $i % $kolumner;
                    $rad = intdiv($i, $kolumner);
                    $startX = 4 + $kol * (92 / $kolumner);
                    $startY = 4 + $rad * 20;
                    $foto = null;
                    foreach ($foton as $f) {
                        if ($f['zon_id'] === $z['id']) {
                            $foto = $f;
                        }
                    }
                    ?>
                    <div class="plan-zon <?= $foto !== null ? 'plan-zon-foto' : '' ?>"
                         data-zon="<?= e($z['id']) ?>"
                         data-namn="<?= e($z['kod']) ?> – <?= e($z['namn']) ?>"
                         data-antal="<?= (int) ($antal[$z['id']] ?? 0) ?>"
                         style="<?= $r !== null
                            ? 'left:' . (float) $r['x'] . '%;top:' . (float) $r['y'] . '%;width:' . (float) $r['b'] . '%;height:' . (float) $r['h'] . '%'
                            : 'left:' . round($startX, 2) . '%;top:' . round(min($startY, 80), 2) . '%;width:18%;height:16%' ?>">
                        <?php if ($foto !== null): ?>
                            <?php /* Fotot syns inne i sin ruta – då ser man vilket hörn rutan är */ ?>
                            <img class="plan-zon-foto-bild" draggable="false" alt=""
                                 src="<?= e(\TDL\Domain\Lokalplan::bildUrl($vald, $foto['fil'])) ?>">
                        <?php endif; ?>
                        <span class="plan-zon-namn">
                            <?= e($z['kod']) ?>
                            <?php if ($foto !== null && $foto['etikett'] !== ''): ?>
                                <span class="plan-zon-etikett"><?= e($foto['etikett']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="plan-zon-info"><?= (int) ($antal[$z['id']] ?? 0) ?> art.</span>
                        <span class="plan-handtag" data-handtag aria-hidden="true"></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="falt-hjalp" style="margin-top:10px">
                Rutorna sparas i procent av ytan, så de hamnar rätt även på mobilen och vid utskrift.
            </p>

            <?php if ($lediga !== [] && $vald !== null): ?>
            <div class="lediga-zoner">
                <strong>Zoner som inte är utplacerade ännu</strong>
                <p class="falt-hjalp" style="margin:2px 0 8px">
                    De ligger inte på skissen. Placera dem här – sedan kan du dra dem på plats.
                </p>
                <?php foreach ($lediga as $z): ?>
                    <form method="post" action="<?= e(u('/lokalplan/zon')) ?>" style="margin:0">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
                        <input type="hidden" name="zon" value="<?= e($z['id']) ?>">
                        <button type="submit" class="liten">+ <span class="mono"><?= e($z['kod']) ?></span> · <?= e($z['namn']) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($arAdmin): ?>
            <div class="actions">
                <button type="submit" class="primar">Spara placeringen</button>
                <a class="knapp" href="<?= e(u('/lagerkarta')) ?>">Se kartan</a>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <?php /* ---------- Fotolista ---------- */ ?>
    <?php if ($foton !== []): ?>
    <div class="kort">
        <h2>Foton på <?= e($vald['namn']) ?> <span class="dampad" style="font-weight:400">(<?= $antalFoton ?>)</span></h2>
        <p class="dampad">Varje foto är en zon. Ta bort ett foto du inte vill ha kvar.</p>
        <div class="foto-rutnat">
            <?php foreach ($foton as $f): ?>
                <div class="foto-kort">
                    <img src="<?= e(\TDL\Domain\Lokalplan::bildUrl($vald, $f['fil'])) ?>"
                         alt="<?= e($f['etikett'] !== '' ? $f['etikett'] : $f['fil']) ?>" loading="lazy">
                    <div class="foto-text">
                        <strong><?= e($f['etikett'] !== '' ? $f['etikett'] : 'Foto') ?></strong>
                        <span class="dampad mono"><?= e($f['zon_id'] ?? '') ?></span>
                    </div>
                    <?php if ($arAdmin): ?>
                    <form method="post" action="<?= e(u('/lokalplan/foto/ta-bort')) ?>"
                          data-bekrafta="Ta bort fotot och dess zon?">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
                        <input type="hidden" name="fil" value="<?= e($f['fil']) ?>">
                        <button type="submit" class="liten fara">Ta bort</button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ---------- Zontabell ---------- */ ?>
    <?php if ($egna !== []): ?>
    <div class="kort">
        <h2>Zoner på <?= e($vald['namn']) ?></h2>
        <div class="tabell-rull">
        <table>
            <tr><th>Zon</th><th>Placering</th><th class="tal">Artiklar</th><th></th></tr>
            <?php foreach ($egna as $z): ?>
                <?php $r = $rutor[$z['id']] ?? null; ?>
                <tr>
                    <td><span class="mono"><?= e($z['kod']) ?></span> · <?= e($z['namn']) ?></td>
                    <td class="dampad">
                        <?php if ($r === null): ?>
                            <span class="tag varning">inte utplacerad</span>
                        <?php else: ?>
                            x <?= (int) round((float) $r['x']) ?> %, y <?= (int) round((float) $r['y']) ?> %
                        <?php endif; ?>
                    </td>
                    <td class="tal"><?= (int) ($antal[$z['id']] ?? 0) ?></td>
                    <td class="tal"><a class="knapp liten" href="<?= e(u('/lagerkarta?zon=' . $z['id'])) ?>">Karta</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($arAdmin): ?>
    <div class="kort">
        <h2>Byt planbild</h2>
        <p class="dampad">
            Har du en ritning eller en fågelvy? Ladda upp den som underlag i stället.
            Zonernas placering behålls.
        </p>
        <form method="post" action="<?= e(u('/lokalplan/bild')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="vaning" value="<?= e($vaningId) ?>">
            <input type="hidden" name="typ" value="planbild">
            <input name="bild" type="file" accept="image/*" required>
            <div class="actions"><button type="submit">Ladda upp planbild</button></div>
        </form>
    </div>
    <?php endif; ?>

    <?php endif; ?>
<?php endif; ?>

<style>
/* Våningsflikar */
.vaning-flikar { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; align-items: center; }
.vaning-flik {
    display: inline-flex; align-items: baseline; gap: 6px;
    padding: 7px 13px; border-radius: 9px; text-decoration: none;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12);
    color: inherit; font-weight: 600; font-size: .9rem;
}
.vaning-flik.vald { background: var(--accent, #4a9eff); color: #0b1220; border-color: transparent; }
.vaning-flik .vaning-antal { font-size: .72rem; font-weight: 400; opacity: .75; }
.vaning-ny { margin: 0; }

/* Ytan zonerna ligger på */
.plan-yta {
    position: relative; display: block; max-width: 100%;
    line-height: 0; user-select: none; touch-action: none;
    border-radius: 10px; overflow: hidden;
    /* Neutral yta när våningen saknar planbild – rutorna behöver något att ligga på */
    background:
        repeating-linear-gradient(0deg, rgba(255,255,255,.045) 0 1px, transparent 1px 40px),
        repeating-linear-gradient(90deg, rgba(255,255,255,.045) 0 1px, transparent 1px 40px),
        #1a1f27;
    min-height: 220px;
}
/* Bara planbilden (direkt i ytan) ska skalas. Regeln får INTE träffa
   fotona inne i zonrutorna – då sätter den height:auto och fotot svämmar
   över sin ruta i stället för att beskäras. */
.plan-yta > img { max-width: 100%; height: auto; display: block; pointer-events: none; }

/* Zonrutorna */
.plan-zon {
    position: absolute; line-height: 1.2; overflow: hidden;
    border: 2px solid #4a9eff; border-radius: 8px;
    background: rgba(74,158,255,.22);
    color: #fff; font-size: .82rem; font-weight: 700;
    display: flex; flex-direction: column; align-items: flex-start; justify-content: flex-start;
    padding: 5px 7px; cursor: grab; box-sizing: border-box;
    min-width: 44px; min-height: 32px;
    box-shadow: 0 2px 10px rgba(0,0,0,.35);
}
.plan-zon:active { cursor: grabbing; }
/* Fotozon: fotot ligger som bakgrund inuti rutan */
.plan-zon-foto { background: rgba(11,18,32,.9); }
.plan-zon .plan-zon-foto-bild {
    position: absolute; inset: 0; width: 100%; height: 100%;
    max-width: none; max-height: none;
    object-fit: cover; opacity: .85; pointer-events: none;
}
.plan-zon-namn, .plan-zon-info { position: relative; z-index: 1; }
.plan-zon-namn {
    font-size: 1rem; letter-spacing: .04em;
    text-shadow: 0 1px 3px rgba(0,0,0,.9);
}
.plan-zon-etikett {
    display: block; font-size: .68rem; font-weight: 500; opacity: .9; letter-spacing: 0;
}
.plan-zon-info {
    font-weight: 500; opacity: .85; font-size: .74rem;
    text-shadow: 0 1px 3px rgba(0,0,0,.9);
}
.plan-handtag {
    position: absolute; right: -1px; bottom: -1px;
    width: 16px; height: 16px; border-radius: 3px 0 7px 0;
    background: #4a9eff; cursor: nwse-resize; z-index: 2;
    box-shadow: -1px -1px 0 rgba(0,0,0,.3);
}
.plan-handtag::after {
    content: ''; position: absolute; right: 4px; bottom: 4px;
    width: 6px; height: 6px; border-right: 2px solid #0b1220; border-bottom: 2px solid #0b1220;
}

/* Oplacerade zoner */
.lediga-zoner {
    margin-top: 12px; padding: 10px 12px; border-radius: 9px;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1);
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
    font-size: .88rem;
}
.lediga-zoner strong { width: 100%; }

/* Fotolistan */
.foto-rutnat {
    display: grid; gap: 10px;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    margin-top: 10px;
}
.foto-kort {
    border: 1px solid rgba(255,255,255,.12); border-radius: 10px;
    overflow: hidden; background: rgba(255,255,255,.04);
    display: flex; flex-direction: column;
}
.foto-kort img { width: 100%; height: 110px; object-fit: cover; display: block; }
.foto-text { padding: 7px 9px; display: flex; flex-direction: column; gap: 2px; font-size: .82rem; }
.foto-kort form { padding: 0 9px 9px; margin: 0; }

@media (max-width: 640px) {
    .plan-zon { font-size: .72rem; padding: 3px 5px; }
    .foto-rutnat { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
}
</style>
