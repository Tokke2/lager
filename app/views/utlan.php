<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Utlåning och återlämning</h1>
<p class="dampad">Utlånade varor dras från tillgängligt saldo. Återlämning kan göras i delar och sker till samma arbetsställe. QR-spårade artiklar lånas ut med sina enhetskoder; verktygstavlor stöds inte. Pågående inventering måste avslutas först.</p>
<div class="kort">
    <h2>Pågående utlåningar</h2>
    <?php if ($utlan === []): ?>
        <p>Inga pågående utlåningar.</p>
    <?php else: ?>
        <div class="tabell-rull"><table>
            <thead><tr><th>Artikel</th><th>Låntagare</th><th>Arbetsställe</th><th>Antal</th><th>Åter senast</th><th></th></tr></thead>
            <tbody><?php foreach ($utlan as $lan): ?>
            <tr>
                <td data-etikett="Artikel"><a href="<?= e(u('/artiklar/' . $lan['artikel_id'])) ?>"><?= e($lan['artikelnamn']) ?></a></td>
                <td data-etikett="Låntagare"><?= e($lan['lanare']) ?></td>
                <td data-etikett="Arbetsställe"><?= e($lan['plats_namn']) ?></td>
                <td data-etikett="Antal"><?= (int) $lan['antal'] ?><?php if (!empty($lan['enheter'])): ?><br><small><?= e(implode(', ', array_map(static fn ($kod) => \TDL\Support\Koder::visa($kod), $lan['enheter']))) ?></small><?php endif; ?></td>
                <td data-etikett="Åter senast"><?= e($lan['forfallodatum']) ?><?= $lan['forfallodatum'] < gmdate('Y-m-d') ? ' – försenad' : '' ?></td>
                <td><form method="post" enctype="multipart/form-data" action="<?= e(u('/utlan/' . $lan['id'] . '/aterlamna')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="version" value="<?= e($lan['version']) ?>">
                    <?php if (($lan['sparningssatt'] ?? '') === 'qr'): ?>
                        <fieldset><legend>Välj enheter att återlämna</legend>
                        <?php foreach ($lan['enheter'] as $kod): ?><label><input type="checkbox" name="enheter[]" value="<?= e($kod) ?>"> <?= e(\TDL\Support\Koder::visa($kod)) ?></label><?php endforeach; ?>
                        </fieldset>
                    <?php else: ?>
                        <label>Antal att återlämna <input type="number" name="antal" min="1" max="<?= (int) $lan['antal'] ?>" value="<?= (int) $lan['antal'] ?>" required></label>
                    <?php endif; ?>
                    <?php if (($lan['sparningssatt'] ?? '') === 'qr'): ?>
                    <label>Skick <select name="skick"><option value="ok">I ordning</option><option value="skadad">Skadad – spärra för nytt lån</option></select></label>
                    <label>Notering om skick <textarea name="notering" maxlength="500" rows="2"></textarea></label>
                    <label>Bild på skick (valfri) <input type="file" name="bild" accept="image/jpeg,image/png,image/gif,image/webp"></label>
                    <p class="falt-hjalp">Valt skick gäller alla markerade enheter. Skada kräver en notering och admin godkänner verktyget före nytt lån.</p>
                    <?php endif; ?>
                    <button type="submit" class="liten">Återlämna valt antal</button>
                </form>
                <?php if (($lan['saknad']['status'] ?? '') === 'oppen'): ?>
                    <p><strong>Saknadrapport:</strong> <?= e($lan['saknad']['plan']) ?> · ansvarig <?= e($lan['saknad']['ansvarig']) ?></p>
                    <?php if (($konto['roll'] ?? '') === 'admin'): ?>
                    <details><summary>Skriv av återstående lån</summary>
                        <form method="post" action="<?= e(u('/utlan/' . $lan['id'] . '/avskriv')) ?>">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="version" value="<?= e($lan['version']) ?>">
                            <label>Motivering <input name="motivering" maxlength="500" required></label>
                            <label><input type="checkbox" name="bekrafta" value="1" required> Jag bekräftar att hela återstoden skrivs av.</label>
                            <button type="submit" class="liten fara">Bekräfta avskrivning</button>
                        </form>
                    </details>
                    <?php endif; ?>
                <?php else: ?>
                <details><summary>Rapportera hela återstående lånet som saknat</summary>
                    <form method="post" action="<?= e(u('/utlan/' . $lan['id'] . '/saknad')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="version" value="<?= e($lan['version']) ?>">
                        <label>Ansvarig för uppföljning <select name="ansvarig" required><option value="">Välj …</option><option value="<?= e($konto['id']) ?>"><?= e($konto['namn']) ?></option><?php foreach ($kollegor as $k): ?><option value="<?= e($k['id']) ?>"><?= e($k['namn']) ?></option><?php endforeach; ?></select></label>
                        <label>Åtgärdsplan <textarea name="plan" maxlength="500" required></textarea></label>
                        <button type="submit" class="liten">Spara saknadrapport</button>
                    </form>
                </details>
                <?php endif; ?>
                <?php if (mb_strtolower(trim($lan['lanare'])) === mb_strtolower(trim($konto['namn']))): ?>
                <details><summary>Överlämna lånet till en kollega</summary>
                    <p class="falt-hjalp">Du är inloggad som nuvarande låntagare. Mottagaren bekräftar med sin egen PIN. Hela återstående lånet överlämnas.</p>
                    <form method="post" action="<?= e(u('/utlan/' . $lan['id'] . '/overlamna')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="version" value="<?= e($lan['version']) ?>">
                        <label>Mottagare <select name="mottagare" required><option value="">Välj kollega …</option><?php foreach ($kollegor as $k): ?><option value="<?= e($k['namn']) ?>"><?= e($k['namn']) ?></option><?php endforeach; ?></select></label>
                        <label>Mottagarens PIN <input type="password" name="mottagare_pin" inputmode="numeric" pattern="[0-9]{4,8}" autocomplete="off" required></label>
                        <button type="submit" class="liten">Bekräfta överlämning</button>
                    </form>
                </details>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</div>
<form class="kort" method="post" action="<?= e(u('/utlan')) ?>">
    <h2>Registrera utlåning</h2>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="artikel_id">Artikel</label>
    <select id="artikel_id" name="artikel_id" required>
        <option value="">Välj artikel …</option>
        <?php foreach ($artiklar as $a): if (!empty($a['ar_tavla'])) continue; ?>
            <option value="<?= e($a['id']) ?>" data-spar="<?= e($a['sparningssatt']) ?>" <?= $valdArtikel === $a['id'] ? 'selected' : '' ?>><?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <label for="plats_id">Arbetsställe</label>
    <select id="plats_id" name="plats_id" required><option value="">Välj arbetsställe …</option>
        <?php foreach ($platser as $p): ?><option value="<?= e($p['id']) ?>" <?= $valdPlats === $p['id'] ? 'selected' : '' ?>><?= e($p['namn']) ?></option><?php endforeach; ?>
    </select>
    <div id="antal-falt"><label for="antal">Antal (för antalartiklar)</label><input type="number" id="antal" name="antal" min="1" step="1"></div>
    <div id="koder-falt"><label for="enhetskoder">QR-enhetskoder (en per rad)</label><textarea id="enhetskoder" name="enhetskoder" rows="3" class="mono"></textarea></div>
    <label for="lanare">Vem lånar?</label><input id="lanare" name="lanare" maxlength="120" required>
    <label for="forfallodatum">Åter senast</label><input type="date" id="forfallodatum" name="forfallodatum" min="<?= e(gmdate('Y-m-d')) ?>" required>
    <fieldset><legend>Kollegans bekräftelse vid större utlåning</legend>
        <p class="falt-hjalp">Samma tvåpersonsregel som för uttag gäller. Om den kräver bekräftelse anger en annan kollega sin PIN här.</p>
        <label for="godkannare">Kollega</label><select id="godkannare" name="godkannare"><option value="">Ingen</option>
            <?php foreach ($kollegor as $k): ?><option value="<?= e($k['namn']) ?>"><?= e($k['namn']) ?></option><?php endforeach; ?>
        </select>
        <label for="godkannare_pin">Kollegans PIN</label><input type="password" id="godkannare_pin" name="godkannare_pin" inputmode="numeric" pattern="[0-9]{4,8}" autocomplete="off">
    </fieldset>
    <div class="actions"><button type="submit" class="primar">Registrera utlåning</button></div>
</form>

<?php if (!empty($skadade)): ?>
<div class="kort"><h2>Verktyg som väntar på kontroll</h2>
    <?php foreach ($skadade as $en): $a = \TDL\Domain\Lager::artikel($en['artikel_id']); $p = \TDL\Domain\Lager::plats((string) ($en['plats_id'] ?? '')); $kortlista = $en['halsokort'] ?? []; $kort = end($kortlista); ?>
    <div class="kort"><p><strong><?= e($a['namn'] ?? '?') ?></strong> · <?= e(\TDL\Support\Koder::visa($en['id'])) ?> · <?= e($p['namn'] ?? '?') ?></p>
        <p><?= e($kort['notering'] ?? '') ?></p>
        <?php if (!empty($kort['bild']['fil'])): ?><p><a href="<?= e(u('/bilder/' . $en['artikel_id'] . '/' . $kort['bild']['fil'])) ?>">Visa bild på skadan</a></p><?php endif; ?>
        <?php if (($konto['roll'] ?? '') === 'admin'): ?><form method="post" action="<?= e(u('/utlan/enhet/' . $en['id'] . '/godkann')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label>Kontroll eller reparation <input name="notering" maxlength="500" required></label>
            <button type="submit" class="liten">Godkänn och gör tillgänglig</button>
        </form><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
