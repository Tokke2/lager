<?php
/** @var array $artikel @var array $oversikt @var array $enheter @var array $historik */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$arAdmin = ($konto['roll'] ?? '') === 'admin';
?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0"><?= e($artikel['namn']) ?></h1>
    <?php if ($arAdmin): ?>
        <a class="knapp" href="<?= e(u('/artiklar/' . (e($artikel['id'])) . '/redigera')) ?>">Redigera</a>
    <?php endif; ?>
</div>
<p class="dampad">
    <span class="mono"><?= e($artikel['artikelnummer']) ?></span> ·
    <?= e($artikel['enhet']) ?> ·
    <?= $artikel['sparningssatt'] === 'qr' ? 'QR per fysisk enhet' : 'Saldo i antal' ?>
    <?php if (!empty($artikel['miniminiva'])): ?> · miniminivå <?= (int) $artikel['miniminiva'] ?><?php endif; ?>
    <?php if (!empty($artikel['kategori'])): ?> · kategori <strong><?= e($artikel['kategori']) ?></strong><?php endif; ?>
    <?php $platsText = \TDL\Domain\Lagerplats::platsText($artikel); ?>
    <?php if ($platsText !== ''): ?>
        · plats <a href="<?= e(u('/lagerkarta?zon=' . e($artikel['zon_id']))) ?>"><strong class="mono"><?= e($platsText) ?></strong></a>
    <?php else: ?>
        · <a href="<?= e(u('/artiklar/' . e($artikel['id']) . '/redigera')) ?>" class="tag varning">plats saknas</a>
    <?php endif; ?>
    <?php if (!empty($artikel['hylla'])): ?> · <span class="dampad"><?= e($artikel['hylla']) ?></span><?php endif; ?>
    <?php if ($artikel['prisreferens'] !== null): ?> · prisreferens <?= number_format((float) $artikel['prisreferens'], 2, ',', ' ') ?> kr<?php endif; ?>
    <?php if (empty($artikel['aktiv'])): ?> · <span class="tag fel">inaktiv</span><?php endif; ?>
</p>

<?php if (!empty($artikel['ean'])): ?><p class="dampad">EAN: <span class="mono"><?= e($artikel['ean']) ?></span></p><?php endif; ?>
<?php if (!empty($artikel['oppen_forpackning'])): ?>
<div class="kort">
    <h2>Notering om öppnad förpackning</h2>
    <p><?= nl2br(e($artikel['oppen_forpackning'])) ?></p>
    <p class="falt-hjalp">Senast ändrad <?= e(sv_tid($artikel['oppen_forpackning_andrad'] ?? null)) ?>. Uppgiften är ungefärlig, kan vara inaktuell och är inte lagersaldo.</p>
    <?php if ($arAdmin): ?><a href="<?= e(u('/artiklar/' . $artikel['id'] . '/redigera')) ?>">Ändra noteringen</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="actions">
    <?php if (!empty($artikel['zon_id']) && !empty($artikel['rad']) && !empty($artikel['fack'])): ?>
        <a class="knapp" href="<?= e(u('/lagerkarta?artikel=' . urlencode((string) $artikel['id']))) ?>">Visa på lagerkartan</a>
    <?php endif; ?>
    <?php if ($artikel['aktiv']): ?>
        <a class="knapp primar" href="<?= e(u('/inleverans?artikel=' . e($artikel['id']))) ?>">Inleverans</a>
        <a class="knapp" href="<?= e(u('/uttag?artikel=' . e($artikel['id']))) ?>">Uttag</a>
        <?php if (empty($artikel['ar_tavla'])): ?><a class="knapp" href="<?= e(u('/utlan?artikel=' . urlencode($artikel['id']))) ?>">Låna ut</a><?php endif; ?>
        <a class="knapp" href="<?= e(u('/overforing?artikel=' . e($artikel['id']))) ?>">Överför</a>
        <?php if ($artikel['sparningssatt'] === 'antal'): ?>
            <a class="knapp" href="<?= e(u('/plocklista?artikel=' . e($artikel['id']))) ?>">Till plocklista</a>
        <?php endif; ?>
        <?php if ($artikel['sparningssatt'] === 'qr'): ?>
            <a class="knapp" href="<?= e(u('/etiketter?artikel=' . e($artikel['id']))) ?>">Etiketter</a>
        <?php endif; ?>
        <a class="knapp" href="<?= e(u('/etiketter?artikel=' . (e($artikel['id'])) . '&typ=artikel')) ?>">Artikel-QR</a>
    <?php endif; ?>
</div>

<?php if ($arAdmin && !empty($flyttforslag)): ?>
<div class="kort">
    <h2>Förslag på hyllplacering</h2>
    <p>Artikeln har <?= (int) $flyttforslag['handelser'] ?> registrerade uttag eller utlåningar de senaste 90 dagarna.
        Rad 1 fack <?= (int) $flyttforslag['fack'] ?> i zon <?= e($flyttforslag['zon']) ?> saknar registrerade aktiva artiklar.</p>
    <p class="falt-hjalp">Förslaget antar att rad 1 är lättare att nå; appen känner inte till verklig gångväg, fysiskt utrymme eller om hyllan är lämplig. Kontrollera på plats. Inget ändras automatiskt.</p>
    <a class="knapp" href="<?= e(u('/artiklar/' . $artikel['id'] . '/redigera')) ?>">Granska och ändra hyllplats</a>
</div>
<?php endif; ?>

<div class="kort" style="margin-top:14px">
    <h2>Bilder och QR</h2>
    <div class="bild-qr">
        <div>
            <?php if ($bilder === []): ?>
                <p class="dampad" style="margin-top:0">Inga bilder ännu. Lägg till en bild på artikelns framsida eller etikett.</p>
            <?php else: ?>
                <div class="bild-ruta">
                    <?php foreach ($bilder as $bild): ?>
                        <figure style="margin:0">
                            <?php
                            $bildUrl = \TDL\Http\ArtikelQr::bildUrl($artikel['id'], $bild['fil']);
                            $miniUrl = \TDL\Http\ArtikelQr::bildUrl($artikel['id'], $bild['fil'], null, 480);
                            $storUrl = \TDL\Http\ArtikelQr::bildUrl($artikel['id'], $bild['fil'], null, 960);
                            ?>
                            <a href="<?= e($bildUrl) ?>" target="_blank" rel="noopener">
                                <img src="<?= e($miniUrl) ?>"
                                     srcset="<?= e($miniUrl) ?> 480w, <?= e($storUrl) ?> 960w"
                                     sizes="(max-width: 620px) 44vw, 132px"
                                     width="132" height="132" decoding="async"
                                     alt="<?= e($bild['text'] !== '' ? $bild['text'] : $artikel['namn']) ?>" loading="lazy">
                            </a>
                            <?php if ($arAdmin): ?>
                                <form method="post" action="<?= e(u('/artiklar/' . (e($artikel['id'])) . '/bilder/ta-bort')) ?>"
                                      data-bekrafta="Ta bort bilden?" style="margin-top:4px">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="fil" value="<?= e($bild['fil']) ?>">
                                    <button type="submit" class="fara liten" style="width:100%">Ta bort</button>
                                </form>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(u('/artiklar/' . (e($artikel['id'])) . '/bilder')) ?>" enctype="multipart/form-data" style="margin-top:12px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="bilder">Lägg till bild (JPG, PNG, WebP – max 8 MB, högst <?= \TDL\Support\Bilder::MAX_BILDER ?> bilder)</label>
                <input id="bilder" name="bilder[]" type="file" accept="image/*" capture="environment" multiple>
                <label for="bildtext">Bildtext (valfritt)</label>
                <input id="bildtext" name="bildtext" maxlength="120" placeholder="t.ex. framsida">
                <div class="actions"><button type="submit">Ladda upp</button></div>
            </form>
        </div>
        <div style="text-align:center">
            <div id="artikel-qr" class="qr" data-url="<?= e(\TDL\Http\ArtikelQr::qrInnehall($artikel['id'])) ?>" data-storlek="160" data-cell="4"
                 style="background:#fff;padding:8px;border-radius:10px;display:inline-block"></div>
            <p class="falt-hjalp" style="max-width:170px">Skanna för att öppna artikeln på mobilen – visa bilder och justera antal.</p>
            <div class="actions" style="justify-content:center">
                <a class="knapp liten" href="<?= e(\TDL\Http\ArtikelQr::lankUrl($artikel['id'])) ?>">Öppna sidan</a>
            </div>
        </div>
    </div>
    <script src="<?= e(u('/assets/qrcode.js')) ?>"></script>
    <script src="<?= e(u('/assets/qr-render.js')) ?>"></script>
</div>

<div class="kort">
    <h2>Saldo per arbetsställe</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>Arbetsställe</th><th class="tal">Antal</th><th></th></tr>
        <?php foreach ($oversikt['rader'] as $rad): $p = $rad['plats']; ?>
            <tr>
                <td>
                    <?= e($p['namn']) ?>
                    <span class="dampad mono"><?= e($p['kod']) ?></span>
                    <?php if (empty($p['aktiv'])): ?><span class="tag">stängd</span><?php endif; ?>
                    <?php if (!empty($p['system'])): ?><span class="tag varning">system</span><?php endif; ?>
                </td>
                <td class="tal <?= $rad['antal'] === 0 ? 'dampad' : '' ?>"><?= (int) $rad['antal'] ?></td>
                <td class="dampad">
                    <?php if ($artikel['sparningssatt'] === 'qr' && $rad['antal'] > 0): ?>
                        <?= (int) $rad['antal'] ?> aktiva QR-enheter
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>Totalt</strong> <span class="falt-hjalp">(sekundär siffra – se per plats)</span></td>
            <td class="tal"><strong><?= (int) $oversikt['total'] ?></strong></td><td></td>
        </tr>
    </table>
    </div>
</div>

<?php if ($artikel['sparningssatt'] === 'qr'): ?>
<div class="kort">
    <h2>QR-enheter (<?= count($enheter) ?>)</h2>
    <?php if ($enheter === []): ?>
        <p class="dampad">Inga enheter ännu. Skapa etiketter under <a href="<?= e(u('/etiketter?artikel=' . e($artikel['id']))) ?>">Etiketter</a>.</p>
    <?php else: ?>
    <div class="tabell-rull">
    <table>
        <tr><th>Enhetskod</th><th>Status</th><th>Plats</th><th>Senast skannad</th></tr>
        <?php foreach ($enheter as $en): ?>
            <?php $plats = $en['plats_id'] ? \TDL\Domain\Lager::plats($en['plats_id']) : null; ?>
            <tr>
                <td class="mono"><?= e(\TDL\Support\Koder::visa($en['id'])) ?></td>
                <td>
                    <?php
                    $klass = match ($en['status']) {
                        'aktiv' => 'ok',
                        'vantande' => 'varning',
                        'utgang' => 'fel',
                        'saknad' => 'fel',
                        'skadad' => 'fel',
                        default => '',
                    };
                    echo '<span class="tag ' . $klass . '">' . e($en['status']) . '</span>';
                    ?>
                </td>
                <td><?= $plats ? e($plats['namn']) : '<span class="dampad">–</span>' ?></td>
                <td class="dampad"><?= e(sv_tid($en['senast_skannad'] ?? null)) ?>
                    <?php if (!empty($en['halsokort'])): ?>
                    <details><summary>Visa hälsokort</summary><ul>
                        <?php foreach ($en['halsokort'] as $kort): ?><li>
                            <?= e(sv_tid($kort['tid'] ?? null)) ?> · <?= e($kort['skick'] ?? '') ?> · <?= e($kort['av'] ?? '') ?>
                            <?php if (!empty($kort['notering'])): ?> – <?= e($kort['notering']) ?><?php endif; ?>
                            <?php if (!empty($kort['bild']['fil'])): ?> · <a href="<?= e(u('/bilder/' . $artikel['id'] . '/' . $kort['bild']['fil'])) ?>">Bild</a><?php endif; ?>
                        </li><?php endforeach; ?>
                    </ul></details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // ---- Verktygstavla: innehållet, med möjlighet att lägga till och ta bort ----
// Ligger medvetet UTANFÖR qr-villkoret ovan: en tavla är en antalsartikel. ?>
<?php if (\TDL\Domain\Tavlor::arTavla($artikel)): ?>
<?php $innehall = $delar ?? []; ?>
<div class="kort" id="tavla">
    <h2>Innehåll i tavlan (<?= count($innehall) ?> olika delar<?= \TDL\Domain\Tavlor::antalDelar($artikel) > count($innehall) ? ', ' . \TDL\Domain\Tavlor::antalDelar($artikel) . ' st totalt' : '' ?>)</h2>
    <p class="dampad">Delarna följer med automatiskt när tavlan tas ut, och läggs tillbaka
        när den lämnas in. Varje del har eget saldo och egen etikett. Ska en del byta tavla
        väljer du mottagare direkt i raden – flytten görs i ett svep.</p>

    <?php if ($innehall === []): ?>
        <p class="dampad">Inga delar ännu. Lägg till dem nedan.</p>
    <?php else: ?>
    <div class="tabell-rull">
    <table>
        <tr><th>Del</th><th>Placering</th><th>Antal</th><th>Saldo här</th><th></th></tr>
        <?php foreach ($innehall as $rad): ?>
            <?php
            $del = $rad['artikel'];
            $saldoHar = $del === null ? null : (int) ($del['saldo'][$arbetsstalle['id'] ?? ''] ?? 0);
            ?>
            <tr>
                <td>
                    <?php if ($del === null): ?>
                        <span class="tag fel">borttagen</span>
                    <?php elseif (empty($del['aktiv'])): ?>
                        <?= e($del['namn']) ?> <span class="tag varning">inaktiv</span>
                    <?php else: ?>
                        <a href="<?= e(u('/artiklar/' . e($del['id']))) ?>"><?= e($del['namn']) ?></a>
                        <br><span class="dampad mono"><?= e($del['artikelnummer']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($rad['fack'] ?? '') !== ''): ?>
                        <span class="mono"><?= e($rad['fack']) ?></span>
                    <?php else: ?>
                        <span class="dampad">–</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) $rad['antal'] ?> <?= $del ? e($del['enhet']) : '' ?></td>
                <td>
                    <?php if ($saldoHar === null): ?>
                        <span class="dampad">–</span>
                    <?php elseif ($saldoHar >= $rad['antal']): ?>
                        <span class="tag ok"><?= $saldoHar ?></span>
                    <?php else: ?>
                        <span class="tag fel"><?= $saldoHar ?> av <?= (int) $rad['antal'] ?></span>
                    <?php endif; ?>
                </td>
                <td class="tal">
                    <?php if ($arAdmin): ?>
                    <div class="actions" style="justify-content:flex-end;margin:0;gap:6px">
                        <?php if (($mottagare ?? []) !== []): ?>
                        <form method="post" action="<?= e(u('/artiklar/' . e($artikel['id']) . '/delar/flytta')) ?>"
                              data-bekrafta="Flytta <?= e($rad['artikel']['namn'] ?? 'delen') ?> till vald tavla?">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="del_id" value="<?= e($rad['artikel_id']) ?>">
                            <input type="number" name="del_antal" min="1" max="<?= (int) $rad['antal'] ?>"
                                   value="<?= (int) $rad['antal'] ?>" style="width:70px" aria-label="Antal att flytta">
                            <select name="till_tavla" required style="max-width:190px" aria-label="Mottagande tavla">
                                <option value="">Flytta till …</option>
                                <?php foreach ($mottagare as $m): ?>
                                    <option value="<?= e($m['id']) ?>"><?= e($m['namn']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="del_fack" maxlength="40" placeholder="placering"
                                   style="width:90px" aria-label="Ny placering">
                            <button type="submit" class="liten">Flytta</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(u('/artiklar/' . e($artikel['id']) . '/delar/ta-bort')) ?>"
                              data-bekrafta="Ta bort delen från tavlan?">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="del_id" value="<?= e($rad['artikel_id']) ?>">
                            <button type="submit" class="liten fara">Ta bort</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($arAdmin): ?>
    <h3 style="margin-top:1.4rem">Lägg till del</h3>
    <?php if (($valjbara ?? []) === []): ?>
        <p class="dampad">Det finns inga fler artiklar att lägga till. Skapa delarna som vanliga
            artiklar först – de får då eget saldo och egen QR-etikett.</p>
    <?php else: ?>
    <form method="post" action="<?= e(u('/artiklar/' . e($artikel['id']) . '/delar')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt" style="flex:3">
                <label for="del_id">Artikel</label>
                <select id="del_id" name="del_id" required>
                    <option value="">Välj …</option>
                    <?php foreach ($valjbara as $v): ?>
                        <option value="<?= e($v['id']) ?>">
                            <?= e($v['namn']) ?> (<?= e($v['artikelnummer']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="falt">
                <label for="del_antal">Antal</label>
                <input id="del_antal" name="del_antal" type="number" min="1" max="<?= \TDL\Domain\Tavlor::MAX_ANTAL ?>" value="1">
            </div>
            <div class="falt">
                <label for="del_fack">Placering</label>
                <input id="del_fack" name="del_fack" maxlength="40" placeholder="t.ex. A3">
            </div>
            <div class="falt" style="flex:0 0 auto;justify-content:flex-end">
                <button type="submit">Lägg till</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="kort">
    <div class="actions" style="justify-content:space-between;margin:0 0 8px">
        <h2 style="margin:0">Senaste historik</h2>
        <a href="<?= e(u('/historik?artikel=' . e($artikel['id']))) ?>">Visa all</a>
    </div>
    <?php if ($historik === []): ?>
        <p class="dampad">Ingen historik ännu.</p>
    <?php else: ?>
    <div class="tabell-rull">
    <table>
        <tr><th>Tid</th><th>Typ</th><th>Plats</th><th class="tal">Antal</th><th>Av</th></tr>
        <?php foreach ($historik as $h): ?>
            <tr>
                <td class="dampad"><?= e(sv_tid($h['tid'] ?? null)) ?></td>
                <td><?= e($h['typ'] ?? '') ?></td>
                <td><?= e($h['plats_namn'] ?? $h['fran_plats_namn'] ?? '–') ?></td>
                <td class="tal"><?= isset($h['antal']) ? (int) $h['antal'] : '–' ?></td>
                <td><?= e($h['ansvarig_namn'] ?? '–') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($arAdmin): ?>
<div class="kort">
    <h2>Administration</h2>
    <?php if (!empty($artikel['aktiv'])): ?>
    <form method="post" action="<?= e(u('/artiklar/' . (e($artikel['id'])) . '/inaktivera')) ?>"
          data-bekrafta="Inaktivera artikeln? Den försvinner från listor men historiken finns kvar. Går inte om saldo eller enheter finns.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="fara liten">Inaktivera artikel</button>
    </form>
    <?php else: ?>
        <p class="dampad">Artikeln är inaktiverad. Historiken finns kvar och artikeln kan inte raderas.</p>
    <?php endif; ?>
</div>
<?php endif; ?>
