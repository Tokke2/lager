<?php
/** @var array $artiklar @var array $platser @var array $tvaPerson @var array $kollegor @var string $valdPlats */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$harRegel = ($tvaPerson['antal'] ?? 0) > 0 || ($tvaPerson['varde'] ?? 0) > 0;
?>
<h1>Uttag</h1>
<p class="dampad">Uttag kräver tillräckligt saldo <strong>på valt arbetsställe</strong>. Räcker inte saldot sparas ingenting.</p>

<form method="post" action="<?= e(u('/uttag')) ?>" class="kort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <label for="artikel_id">Artikel</label>
    <select id="artikel_id" name="artikel_id" required>
        <option value="">Välj artikel …</option>
        <?php foreach ($artiklar as $a): ?>
            <option value="<?= e($a['id']) ?>" data-spar="<?= e($a['sparningssatt']) ?>">
                <?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>) – <?= $a['sparningssatt'] === 'qr' ? 'QR per enhet' : 'antal' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="plats_id">Arbetsställe som lämnar ut</label>
    <?php $valtStalle = \TDL\Support\Arbetsstalle::aktivt(); ?>
    <?php if ($valtStalle !== null): ?>
        <div class="falt-hjalp" style="margin:0 0 6px">
            Förvalt: <strong><?= e($valtStalle['namn']) ?></strong> – ditt arbetsställe för passet.
            <a href="<?= e(u('/arbetsstalle/byt')) ?>" data-byt-stalle>Byt</a>
        </div>
    <?php endif; ?>
    <select id="plats_id" name="plats_id" required>
        <option value="">Välj arbetsställe …</option>
        <?php foreach ($platser as $p): ?>
            <option value="<?= e($p['id']) ?>" <?= ($valdPlats ?? '') === $p['id'] ? 'selected' : '' ?>><?= e($p['namn']) ?> (<?= e($p['kod']) ?>)</option>
        <?php endforeach; ?>
    </select>

    <div id="antal-falt">
        <label for="antal">Antal</label>
        <input id="antal" name="antal" type="number" min="0" step="1">
    </div>

    <div id="koder-falt" style="display:none">
        <label for="enhetskoder">Enhetskoder (en per rad)</label>
        <textarea id="enhetskoder" name="enhetskoder[]" rows="4" class="mono" placeholder="TDL-U-XXXXX-XXXXX-X"></textarea>
        <div class="falt-hjalp">Enheterna måste vara aktiva på valt arbetsställe. De markeras som uttagna och spåret finns kvar i historiken.</div>
    </div>

    <label for="kommentar">Kommentar (valfri)</label>
    <input id="kommentar" name="kommentar" maxlength="200">

    <?php if ($harRegel): ?>
    <fieldset class="godkannande" id="godkannande">
        <legend>Krävs ibland: kollegans bekräftelse</legend>
        <p class="falt-hjalp">
            <?php if (($tvaPerson['antal'] ?? 0) > 0): ?>
                Uttag på <strong><?= (int) $tvaPerson['antal'] ?> enheter eller mer</strong>
            <?php endif; ?>
            <?php if (($tvaPerson['varde'] ?? 0) > 0): ?>
                <?= ($tvaPerson['antal'] ?? 0) > 0 ? ' eller ' : '' ?>uttag värt <strong><?= number_format((float) $tvaPerson['varde'], 0, ',', ' ') ?> kr eller mer</strong>
            <?php endif; ?>
            måste bekräftas av en kollega. Låt kollegan välja sitt namn och slå sin PIN-kod.
            Räcker det inte till så sparas ingenting.
        </p>

        <div class="rad">
            <div class="falt">
                <label for="godkannare">Kollega som bekräftar</label>
                <select id="godkannare" name="godkannare">
                    <option value="">Ingen – bara vid små uttag</option>
                    <?php foreach ($kollegor as $k): ?>
                        <option value="<?= e($k['namn']) ?>"><?= e($k['namn']) ?> (<?= $k['roll'] === 'admin' ? 'admin' : 'personal' ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="falt">
                <label for="godkannare_pin">Kollegans PIN-kod</label>
                <input id="godkannare_pin" name="godkannare_pin" type="password"
                       inputmode="numeric" pattern="[0-9]{4,8}" autocomplete="off">
            </div>
        </div>
    </fieldset>
    <?php endif; ?>

    <div class="actions">
        <button type="submit" class="primar">Registrera uttag</button>
    </div>
</form>

<script src="<?= e(tdl_tillgang('assets/app.js')) ?>"></script>

