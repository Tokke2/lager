<?php
/** @var array $fordon */
/** @var array $artiklar */
$csrf = \TDL\Support\Sakerhet::csrfToken();
?>
<div class="rad-mellan">
    <h1>🚗 Fordon & Materialkostnad</h1>
    <button type="button" class="knapp sekundar no-print" onclick="window.print()">📄 Skriv ut rapport (PDF)</button>
</div>

<div class="kort no-print" style="margin-bottom:16px">
    <h2>Registrera nytt fordon</h2>
    <form method="post" action="<?= e(u('/fordon/ny')) ?>" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div>
            <label for="regnr">Regnr / ID *</label>
            <input id="regnr" name="regnr" required maxlength="20" placeholder="ABC 123">
        </div>
        <div>
            <label for="modell">Modell / Beskrivning</label>
            <input id="modell" name="modell" maxlength="80" placeholder="t.ex. Volvo V70">
        </div>
        <div>
            <label for="fordon-bild">Foto (valfritt)</label>
            <input id="fordon-bild" name="bild" type="file" accept="image/*" capture="environment">
        </div>
        <button type="submit" class="primar">Lägg till fordon</button>
    </form>
</div>

<div class="kort no-print" style="margin-bottom:16px">
    <h2>Registrera använd lagervara på fordon</h2>
    <?php if ($fordon === [] || $artiklar === []): ?>
        <p class="dampad">Lägg till minst ett fordon och en artikel först.</p>
    <?php else: ?>
    <form method="post" action="<?= e(u('/fordon/anvand')) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div>
            <label for="fordon_id">Fordon</label>
            <select id="fordon_id" name="fordon_id" required>
                <?php foreach ($fordon as $f): ?>
                    <option value="<?= e($f['id']) ?>"><?= e($f['regnr']) ?> <?= $f['modell'] !== '' ? '– ' . e($f['modell']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="artikel_id">Lagervara</label>
            <select id="artikel_id" name="artikel_id" required>
                <?php foreach ($artiklar as $a): ?>
                    <option value="<?= e($a['id']) ?>"><?= e($a['namn']) ?> (<?= number_format((float)($a['inkopspris'] ?? 0), 2, ',', ' ') ?> kr)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="antal">Antal</label>
            <input id="antal" name="antal" type="number" step="1" min="1" value="1" required style="width:90px">
        </div>
        <button type="submit" class="primar">Registrera på fordon</button>
    </form>
    <?php endif; ?>
</div>

<?php foreach ($fordon as $f): ?>
<div class="kort" style="margin-bottom:14px">
    <div class="rad-mellan">
        <h2><?= e($f['regnr']) ?> <?= $f['modell'] !== '' ? '– ' . e($f['modell']) : '' ?></h2>
        <strong>Totalkostnad: <?= number_format((float)($f['totalkostnad'] ?? 0), 2, ',', ' ') ?> kr</strong>
    </div>
    <?php $poster = $f['material'] ?? []; ?>
    <?php if ($poster === []): ?>
        <p class="dampad">Inga lagervaror registrerade ännu.</p>
    <?php else: ?>
        <div class="tabell-svep">
            <table class="tabell">
                <thead><tr><th>Datum</th><th>Artikel</th><th class="tal">Antal</th><th class="tal">À-pris</th><th class="tal">Summa</th></tr></thead>
                <tbody>
                <?php foreach ($poster as $p): ?>
                    <tr>
                        <td><?= e($p['datum'] ?? '') ?></td>
                        <td><?= e($p['namn'] ?? '') ?></td>
                        <td class="tal"><?= (int)($p['antal'] ?? 0) ?></td>
                        <td class="tal"><?= number_format((float)($p['pris'] ?? 0), 2, ',', ' ') ?> kr</td>
                        <td class="tal"><strong><?= number_format((float)($p['summa'] ?? 0), 2, ',', ' ') ?> kr</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
