<?php /** @var array $artiklar @var array $platser */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Överföring</h1>
<p class="dampad">Flytta antal eller QR-enheter mellan två arbetsställen. Käll- och målsaldo uppdateras sammanhängande.</p>

<form method="post" action="<?= e(u('/overforing')) ?>" class="kort">
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

    <div class="rad">
        <div class="falt">
            <label for="fran_plats_id">Från</label>
            <select id="fran_plats_id" name="fran_plats_id" required>
                <option value="">Välj …</option>
                <?php foreach ($platser as $p): ?>
                    <option value="<?= e($p['id']) ?>" <?= ($valdFran ?? '') === $p['id'] ? 'selected' : '' ?>><?= e($p['namn']) ?> (<?= e($p['kod']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php $valtStalle = \TDL\Support\Arbetsstalle::aktivt(); ?>
            <?php if ($valtStalle !== null): ?>
                <div class="falt-hjalp">Förvalt: <?= e($valtStalle['namn']) ?> (ditt arbetsställe).
                    <a href="<?= e(u('/arbetsstalle/byt')) ?>" data-byt-stalle>Byt</a></div>
            <?php endif; ?>
        </div>
        <div class="falt">
            <label for="till_plats_id">Till</label>
            <select id="till_plats_id" name="till_plats_id" required>
                <option value="">Välj …</option>
                <?php foreach ($platser as $p): ?>
                    <option value="<?= e($p['id']) ?>"><?= e($p['namn']) ?> (<?= e($p['kod']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="antal-falt">
        <label for="antal">Antal</label>
        <input id="antal" name="antal" type="number" min="0" step="1">
    </div>

    <div id="koder-falt" style="display:none">
        <label for="enhetskoder">Enhetskoder (en per rad)</label>
        <textarea id="enhetskoder" name="enhetskoder[]" rows="4" class="mono"></textarea>
        <div class="falt-hjalp">Enhetens ID är beständigt och följer med vid överföringen.</div>
    </div>

    <label for="kommentar">Kommentar (valfri)</label>
    <input id="kommentar" name="kommentar" maxlength="200">

    <div class="actions">
        <button type="submit" class="primar">Genomför överföring</button>
    </div>
</form>

<script src="<?= e(u('/assets/app.js?v=' . e(\TDL_VERSION))) ?>"></script>

