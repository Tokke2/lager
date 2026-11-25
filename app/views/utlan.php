<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Utlåning och återlämning</h1>
<p class="dampad">Utlånade varor dras från tillgängligt saldo. Återlämning gäller hela utlåningen och sker till samma arbetsställe. QR-spårade artiklar lånas ut med sina enhetskoder; verktygstavlor stöds inte. Pågående inventering måste avslutas först.</p>
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
                <td><form method="post" action="<?= e(u('/utlan/' . $lan['id'] . '/aterlamna')) ?>" data-bekrafta="Återlämna hela utlåningen till <?= e($lan['plats_namn']) ?>?">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button type="submit" class="liten">Återlämna</button>
                </form></td>
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
