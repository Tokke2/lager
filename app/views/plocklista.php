<?php
/**
 * Plocklista – flera uttag i en rörelse.
 *
 * Listan byggs i webbläsaren (lägg till/ta bort rader utan serveranrop),
 * sorteras på hylla i plockordning och skickas som en enda POST. Servern
 * kontrollerar allt-eller-inget i Rorelser::plocklista().
 *
 * @var array $artiklar  aktiva antalsartiklar med saldo per plats
 * @var array $platser   aktiva arbetsställen
 * @var array $rader     förifyllda rader (från ?artikel= eller ett fel)
 * @var string $valdPlats
 * @var string $kommentar
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();

// Raderna sorteras i gångordning: zon → rad → fack, sedan namn.
// Den strukturerade platsen går före den gamla fritexthyllan, eftersom den
// följer hur man faktiskt går genom lagret (A-1-1 före A-1-2 före B-1-1).
$sorterade = $rader;
usort($sorterade, function ($a, $b) {
    $pa = (string) ($a['plats_nyckel'] ?? '');
    $pb = (string) ($b['plats_nyckel'] ?? '');
    $jfr = strnatcasecmp($pa, $pb);
    if ($jfr !== 0) { return $jfr; }
    $ha = (string) ($a['hylla'] ?? '');
    $hb = (string) ($b['hylla'] ?? '');
    if ($ha === '' && $hb !== '') { return 1; }
    if ($hb === '' && $ha !== '') { return -1; }
    $jfr = strnatcasecmp($ha, $hb);
    return $jfr !== 0 ? $jfr : strcasecmp((string) ($a['namn'] ?? ''), (string) ($b['namn'] ?? ''));
});
$antalRader = count($sorterade);
?>
<h1>Plocklista</h1>
<p class="dampad">
    Flera uttag på en gång. Listan sorteras i gångordning (zon → rad → fack) och bokförs
    som <strong>en</strong> rörelse. Räcker inte saldot någonstans sparas ingenting.
</p>

<form method="post" action="<?= e(u('/plocklista')) ?>" class="kort" id="plockform" data-plockform>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="rad">
        <div class="falt">
            <label for="plats_id">Arbetsställe som lämnar ut *</label>
            <select id="plats_id" name="plats_id" required>
                <option value="">Välj arbetsställe …</option>
                <?php foreach ($platser as $p): ?>
                    <option value="<?= e($p['id']) ?>" <?= $p['id'] === $valdPlats ? 'selected' : '' ?>>
                        <?= e($p['namn']) ?> (<?= e($p['kod']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="falt">
            <label for="nar_plats">Lägg till artikel</label>
            <select id="nar_plats" data-plock-lagg-till>
                <option value="">Välj artikel att lägga till …</option>
                <?php foreach ($artiklar as $a): ?>
                    <option value="<?= e($a['id']) ?>"
                            data-namn="<?= e($a['namn']) ?>"
                            data-nummer="<?= e($a['artikelnummer']) ?>"
                            data-enhet="<?= e($a['enhet']) ?>"
                            data-hylla="<?= e((string) ($a['hylla'] ?? '')) ?>"
                            data-saldo="<?= (int) ($saldoPlats[$a['id']] ?? 0) ?>"
                            data-saldo-json="<?= e(json_encode($a['saldo'] ?? [])) ?>">
                        <?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>)<?= trim((string) ($a['hylla'] ?? '')) !== '' ? ' – ' . e($a['hylla']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="falt-hjalp">
                Bara artiklar som hanteras i antal. QR-artiklar tas ut en och en via skanning.
            </div>
        </div>
    </div>

    <h2 style="font-size:1rem">Rader i listan</h2>
    <div class="tabell-rull">
        <table class="plocktabell">
            <thead>
                <tr>
                    <th>Hylla</th><th>Artikel</th><th>Nr</th>
                    <th class="tal" style="width:7rem">Antal</th><th class="tal dold-mobil">Saldo</th><th></th>
                </tr>
            </thead>
            <tbody id="plockrader">
            <?php foreach ($sorterade as $i => $r): ?>
                <tr>
                    <td class="mono dampad"><?= e((string) ($r['hylla'] ?? '')) ?: '–' ?></td>
                    <td>
                        <?= e($r['namn']) ?>
                        <input type="hidden" name="rader[<?= (int) $i ?>][artikel_id]" value="<?= e($r['artikel_id']) ?>">
                    </td>
                    <td class="mono dampad"><?= e((string) ($r['artikelnummer'] ?? '')) ?></td>
                    <td class="tal">
                        <input type="number" name="rader[<?= (int) $i ?>][antal]" min="1" step="1"
                               value="<?= (int) $r['antal'] ?>" required style="max-width:6rem;text-align:right"
                               data-plock-antal data-saldo="<?= (int) ($r['saldo'] ?? 0) ?>">
                    </td>
                    <td class="tal dold-mobil" data-plock-saldo><?= (int) ($r['saldo'] ?? 0) ?> <?= e($r['enhet'] ?? '') ?></td>
                    <td><button type="button" class="fara liten" data-plock-ta-bort>Ta bort</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="dampad" id="plocktom" <?= $antalRader > 0 ? 'style="display:none"' : '' ?>>
        Listan är tom. Välj en artikel ovan för att lägga till den.
    </p>

    <p class="falt-hjalp" id="plocksumma">
        <?= (int) $antalRader ?> rad(er) i listan.
    </p>

    <label for="kommentar">Kommentar (valfri, sparas på varje rad i historiken)</label>
    <input id="kommentar" name="kommentar" maxlength="200" value="<?= e($kommentar) ?>"
           placeholder="t.ex. order 4711 till verkstaden">

    <div class="actions">
        <button type="submit" class="primar" <?= $antalRader === 0 ? 'disabled data-plock-knapp' : 'data-plock-knapp' ?>>
            Bokför hela listan
        </button>
        <a class="knapp" href="<?= e(u('/uttag')) ?>">Ett enstaka uttag i stället</a>
    </div>
    <p class="falt-hjalp">
        Tvåpersonersregeln gäller även här: når en rad gränsen avbryts hela listan
        och raden måste tas ut separat med en kollegas bekräftelse.
    </p>
</form>

<script src="<?= e(tdl_tillgang('assets/plocklista.js')) ?>"></script>
