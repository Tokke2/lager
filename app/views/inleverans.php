<?php
/** @var array $artiklar @var array $platser @var string $valdArtikel @var string $valdPlats */
$csrf = \TDL\Support\Sakerhet::csrfToken();
?>
<h1>Inleverans</h1>
<p class="dampad">Registrera mottagande arbetsställe. Saldot ändras först när allt validerats.</p>

<form method="post" action="<?= e(u('/inleverans')) ?>" class="kort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <label for="artikel_id">Artikel</label>
    <select id="artikel_id" name="artikel_id" required>
        <option value="">Välj artikel …</option>
        <?php foreach ($artiklar as $a): ?>
            <option value="<?= e($a['id']) ?>" data-spar="<?= e($a['sparningssatt']) ?>" <?= $valdArtikel === $a['id'] ? 'selected' : '' ?>>
                <?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>) – <?= $a['sparningssatt'] === 'qr' ? 'QR per enhet' : 'antal' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="plats_id">Mottagande arbetsställe</label>
    <?php $valtStalle = \TDL\Support\Arbetsstalle::aktivt(); ?>
    <?php if ($valtStalle !== null && $valdPlats === $valtStalle['id']): ?>
        <div class="falt-hjalp" style="margin:0 0 6px">
            Förvalt: <strong><?= e($valtStalle['namn']) ?></strong> – ditt arbetsställe för passet.
            <a href="<?= e(u('/arbetsstalle/byt')) ?>" data-byt-stalle>Byt</a>
        </div>
    <?php endif; ?>
    <select id="plats_id" name="plats_id" required>
        <option value="">Välj arbetsställe …</option>
        <?php foreach ($platser as $p): ?>
            <option value="<?= e($p['id']) ?>" <?= $valdPlats === $p['id'] ? 'selected' : '' ?>><?= e($p['namn']) ?> (<?= e($p['kod']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <div class="falt-hjalp">Arbetsplats-QR:en på väggen väljer plats – den flyttar inget lager. Det här är en registrering.</div>

    <div id="antal-falt">
        <label for="antal">Antal</label>
        <input id="antal" name="antal" type="number" min="0" step="1" value="">
    </div>

    <div id="koder-falt" style="display:none">
        <label for="enhetskoder">Enhetskoder (en per rad, eller skanna)</label>
        <textarea id="enhetskoder" name="enhetskoder[]" rows="4" placeholder="TDL-U-XXXXX-XXXXX-X" class="mono"></textarea>
        <div class="falt-hjalp">
            Lämna tomt för att skapa nya enheter med nytt antal ovan. Fyll i koder för att aktivera
            redan utskrivna väntande etiketter.
        </div>
    </div>

    <label for="kommentar">Kommentar (valfri)</label>
    <input id="kommentar" name="kommentar" maxlength="200">

    <div class="actions">
        <button type="submit" class="primar">Registrera inleverans</button>
    </div>
</form>


