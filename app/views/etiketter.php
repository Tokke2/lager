<?php
/**
 * Etiketter – utskriftscentralen.
 *
 * Alla QR-lappar skrivs ut HÄRIFRÅN, på en dator med etikettskrivare.
 * Tanken: den som står på ett arbetsställe skriver aldrig ut lappar själv –
 * det görs vid datorn, och lapparna sätts upp på hyllan. Därför samlar sidan
 * de tre slagen av lappar med samma flöde:
 *
 *   1. Artikellappar   – QR + artikelnamn, klistras på hyllkanten
 *   2. Enhetslappar    – en per fysisk enhet (QR per enhet-artiklar)
 *   3. Arbetsställelapp – en per arbetsställe, sätts vid ingången
 *
 * @var array $artiklar       QR-per-enhet-artiklar
 * @var array $allaArtiklar   alla aktiva artiklar
 * @var array $vantande       etiketter som skapats men inte tagits emot
 * @var array|null $aktivtStalle
 * @var array $skrivare
 * @var string $valdArtikel
 * @var string $valdTyp
 * @var string $basUrl
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$allaArtiklar = $allaArtiklar ?? $artiklar;
$valdTyp = $valdTyp ?? '';
$vanliga = array_values(array_filter($allaArtiklar, fn ($a) => ($a['sparningssatt'] ?? '') === 'antal'));
$enhetsArtiklar = array_values(array_filter($allaArtiklar, fn ($a) => ($a['sparningssatt'] ?? '') === 'qr'));
$stalle = $aktivtStalle ?? null;
?>

<h1>Etiketter</h1>
<p class="dampad">
    Alla QR-lappar skrivs ut härifrån – på datorn med etikettskrivaren.
    Lapparna sätts sedan upp på hyllan eller vid ingången.
</p>

<?php if ($stalle !== null): ?>
    <div class="flash info">
        <strong>Arbetsställe för passet: <?= e($stalle['namn']) ?>.</strong>
        Etiketterna hör till varan, inte till stället – de kan sättas upp var som helst.
        Enhetslapparna binds till <?= e($stalle['namn']) ?> först när de tas emot i inleverans.
    </div>
<?php endif; ?>

<?php // ---- Skrivarens status: det vanligaste felet är fel rullbredd ---- ?>
<div class="kort">
    <h2>Skrivare</h2>
    <p style="margin-top:0">
        <strong><?= e($skrivare['namn']) ?></strong> –
        <?= e(\TDL\Support\Skrivare::tal((float) $skrivare['bredd'])) ?> mm tryckbredd.
        <a href="<?= e(u('/admin/system')) ?>">Byt skrivare</a>
    </p>
    <p class="falt-hjalp" style="margin-bottom:0">
        Appen skickar lappen till datorns utskriftsdialog – välj samma skrivare där.
        Etiketter som är bredare än skrivhuvudet klipps, därför varnar appen innan.
    </p>
</div>

<?php // ---- 1. Etiketter att sätta upp (redan skapade, väntande) ---- ?>
<?php if ($vantande !== []): ?>
<div class="kort">
    <h2>Redo att sätta upp</h2>
    <p class="dampad" style="margin-top:0">
        De här lapparna är skapade men inte kopplade till något arbetsställe än.
        De binds när varan tas emot i inleverans.
    </p>
    <div class="tabell-rull">
    <table>
        <tr><th>Artikel</th><th>Nr</th><th class="tal">Väntande etiketter</th><th></th></tr>
        <?php foreach ($vantande as $rad): $a = $rad['artikel']; ?>
            <tr>
                <td><strong><?= e($a['namn']) ?></strong></td>
                <td class="mono"><?= e($a['artikelnummer']) ?></td>
                <td class="tal"><?= (int) $rad['antal'] ?></td>
                <td style="white-space:nowrap">
                    <a class="knapp liten" href="<?= e(u('/artiklar/' . (e($a['id'])) . '/etiketter?antal=' . ((int) $rad['antal']) . '&skriv=1')) ?>">Skriv ut</a>
                    <a class="knapp liten" href="<?= e(u('/inleverans?artikel=' . e($a['id']))) ?>">Ta emot</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>

<?php // ---- 2. Artikellappar ---- ?>
<div class="kort">
    <h2>1. Artikellappar – QR + artikelnamn</h2>
    <p class="dampad" style="margin-top:0">
        En lapp per artikel, klistras på hyllkanten. Den som skannar får upp artikelns
        bilder och saldo och kan justera antal. Hyllan skrivs ut på lappen om den är ifylld.
    </p>
    <?php if ($allaArtiklar === []): ?>
        <p class="dampad">Inga aktiva artiklar ännu.</p>
    <?php else: ?>
    <?php // data-action-mall bygger adressen av vald artikel – /artiklar/<id>/etiketter ?>
    <form method="get" action="<?= e(u('/artiklar')) ?>" data-action-mall="/artiklar/{id}/etiketter" data-action-kalla="artikel">
        <div class="rad">
            <div class="falt">
                <label for="artikel-qr">Artikel</label>
                <select id="artikel-qr" name="artikel" required>
                    <option value="">Välj artikel …</option>
                    <?php foreach ($allaArtiklar as $a): ?>
                        <option value="<?= e($a['id']) ?>" <?= $valdArtikel === $a['id'] ? 'selected' : '' ?>>
                            <?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>)<?= trim((string) ($a['hylla'] ?? '')) !== '' ? ' – ' . e($a['hylla']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="falt">
                <label for="antal-qr">Antal lappar</label>
                <input id="antal-qr" name="antal" type="number" min="1" max="60" value="1">
                <div class="falt-hjalp">Oftast räcker 1 per hyllplats.</div>
            </div>
            <div class="falt">
                <label for="rulle-qr">Etikettstorlek</label>
                <?php // Ingen data-skicka-vid-andring här: det här är ett skapandeformulär,
                      // inte ett filter – storleken väljs innan man trycker på knappen. ?>
                <select id="rulle-qr" name="rulle">
                    <?php foreach (\TDL\Support\Etikettmallar::grupper() as $grupp => $poster): ?>
                        <optgroup label="<?= e($grupp) ?>">
                            <?php foreach ($poster as $nyckel => $r): ?>
                                <option value="<?= e($nyckel) ?>"><?= e($r['namn']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="actions"><button type="submit" class="primar">Skriv ut artikellapp</button></div>
    </form>
    <p class="falt-hjalp" style="margin-top:10px">
        QR-koden pekar på <span class="mono"><?= e($basUrl) ?></span>.
        Ändra under <a href="<?= e(u('/admin/system')) ?>">System</a> om appen ligger på en annan adress –
        lappar som redan sitter uppe slutar fungera om adressen ändras.
    </p>
    <?php endif; ?>
</div>

<?php // ---- 3. Enhetslappar ---- ?>
<div class="kort">
    <h2>2. Enhetslappar – en per fysisk enhet</h2>
    <p class="dampad" style="margin-top:0">
        Bara för artiklar som spåras per enhet (verktyg, maskiner). Varje lapp får en
        egen kod som följer enheten hela livet – även när den flyttas mellan ställen.
    </p>
    <?php if ($enhetsArtiklar === []): ?>
        <p class="dampad">
            Inga artiklar med QR per enhet finns ännu. Skapa en sådan artikel först
            (<a href="<?= e(u('/artiklar/ny')) ?>">Ny artikel</a> med saldohanteringssätt <em>QR per fysisk enhet</em>).
        </p>
    <?php else: ?>
    <form method="post" action="<?= e(u('/etiketter')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt">
                <label for="artikel_id">Artikel</label>
                <select id="artikel_id" name="artikel_id" required>
                    <option value="">Välj artikel …</option>
                    <?php foreach ($enhetsArtiklar as $a): ?>
                        <option value="<?= e($a['id']) ?>" <?= $valdArtikel === $a['id'] ? 'selected' : '' ?>>
                            <?= e($a['namn']) ?> (<?= e($a['artikelnummer']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="falt">
                <label for="antal">Antal enheter</label>
                <input id="antal" name="antal" type="number" min="1" max="100" value="10" required>
                <div class="falt-hjalp">Lika många lappar som enheter du köper in.</div>
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="primar">Skapa och skriv ut</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php // ---- 4. Arbetsställelappar ---- ?>
<div class="kort">
    <h2>3. Arbetsställelappar – vid ingången</h2>
    <p class="dampad" style="margin-top:0">
        En lapp per arbetsställe. Den som skannar den i appen får stället förvalt –
        den flyttar aldrig lager.
    </p>
    <div class="tabell-rull">
    <table>
        <tr><th>Arbetsställe</th><th>Kod</th><th>Plats-QR</th><th></th></tr>
        <?php foreach (\TDL\Domain\Lager::platser() as $p): ?>
            <tr>
                <td><?= e($p['namn']) ?><?= empty($p['aktiv']) ? ' <span class="tag">stängd</span>' : '' ?></td>
                <td class="mono"><?= e($p['kod']) ?></td>
                <td class="mono"><?= e(\TDL\Support\Koder::visa($p['qr'])) ?></td>
                <td><a class="knapp liten" href="<?= e(u('/platsetikett?plats=' . (e($p['id'])) . '&skriv=1')) ?>">Skriv ut</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
