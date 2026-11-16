<?php
/**
 * Inventeringsöversikt: starta ny räkning per arbetsställe, se pågående och historik.
 *
 * @var array $sessioner @var array $platser @var array $antalPerPlats @var bool $arAdmin
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$oppna = array_values(array_filter($sessioner, fn ($s) => ($s['status'] ?? '') === 'öppen'));
$oppenPerPlats = [];
foreach ($oppna as $s) {
    $oppenPerPlats[$s['plats_id']] = $s;
}
?>
<h1>Inventering</h1>
<p class="dampad">
    En inventering gäller ett <strong>arbetsställe</strong> och räknar alla artiklar som finns där.
    Förväntade antal frysas vid start, och differensen visas alltid innan något stäms av.
</p>

<?php if ($oppna !== []): ?>
<div class="kort">
    <h2>Pågående inventeringar</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>Arbetsställe</th><th>Startad</th><th>Framsteg</th><th></th></tr>
        <?php foreach ($oppna as $s): ?>
            <?php
            $lage = \TDL\Domain\Inventering::lage($s);
            $plats = \TDL\Domain\Lager::plats($s['plats_id']);
            ?>
            <tr>
                <td><strong><?= e($plats['namn'] ?? '?') ?></strong> <span class="mono dampad"><?= e($s['id']) ?></span></td>
                <td class="dampad"><?= e(sv_tid($s['startad'] ?? null)) ?></td>
                <td>
                    <?= (int) $lage['antal_raknade'] ?> / <?= (int) $lage['antal_artiklar'] ?>
                    <?php if ($lage['antal_avvikelser'] > 0): ?>
                        <span class="tag varning"><?= (int) $lage['antal_avvikelser'] ?> avvik.</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <a class="knapp primar" href="<?= e(u('/inventering/' . e($s['id']))) ?>">Fortsätt räkna</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="kort">
    <h2>Starta ny inventering</h2>
    <?php if ($platser === []): ?>
        <p class="dampad">Det behövs minst ett aktivt arbetsställe.</p>
    <?php else: ?>
    <form method="post" action="<?= e(u('/inventering/start')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="plats_id">Vilket arbetsställe ska räknas?</label>
        <select id="plats_id" name="plats_id" required style="max-width:420px">
            <option value="">Välj …</option>
            <?php foreach ($platser as $p): ?>
                <?php
                $antal = (int) ($antalPerPlats[$p['id']] ?? 0);
                $harOppen = isset($oppenPerPlats[$p['id']]);
                ?>
                <option value="<?= e($p['id']) ?>" <?= $harOppen ? 'disabled' : ($forvalt === $p['id'] ? ' selected' : '') ?>>
                    <?= e($p['namn']) ?> – <?= $antal ?> <?= $antal === 1 ? 'artikel' : 'artiklar' ?> med saldo<?= $harOppen ? ' (pågående inventering)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($forvalt !== ''): ?>
            <p class="falt-hjalp" style="margin-bottom:0">
                Förvalt: <strong>ditt arbetsställe</strong>.
                <a href="<?= e(u('/arbetsstalle/byt')) ?>" data-byt-stalle>Byt</a>
            </p>
        <?php endif; ?>
        <p class="falt-hjalp">
            Alla artiklar som har saldo på arbetsstället tas med automatiskt, plus alla artiklar
            med QR-enheter där. Du kan lägga till och ta bort artiklar efter start.
        </p>
        <div class="actions">
            <button type="submit" class="primar">Starta inventering</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="kort">
    <h2>Senaste inventeringar</h2>
    <?php if ($sessioner === []): ?>
        <p class="dampad">Inga inventeringar ännu.</p>
    <?php else: ?>
    <div class="tabell-rull">
    <table>
        <tr><th>ID</th><th>Arbetsställe</th><th>Arbetsställets artiklar</th><th>Räknat</th><th>Status</th><th>Startad</th><th></th></tr>
        <?php foreach ($sessioner as $s): ?>
            <?php
            $plats = \TDL\Domain\Lager::plats($s['plats_id']);
            $antalArtiklar = count(\TDL\Domain\Inventering::artikelIds($s));
            $raknat = $s['avstamning']['raknade'] ?? null;
            $status = $s['status'] ?? '';
            $tagKlass = $status === 'öppen' ? 'varning' : ($status === 'annullerad' ? '' : 'ok');
            ?>
            <tr>
                <td class="mono"><a href="<?= e(u('/inventering/' . e($s['id']))) ?>"><?= e($s['id']) ?></a></td>
                <td><?= e($plats['namn'] ?? '?') ?></td>
                <td class="dampad"><?= (int) $antalArtiklar ?></td>
                <td class="dampad">
                    <?php if (($s['avstamning']['justerade'] ?? null) !== null): ?>
                        <?= (int) $s['avstamning']['justerade'] ?> justerade
                    <?php else: ?>
                        –
                    <?php endif; ?>
                </td>
                <td><span class="tag <?= $tagKlass ?>"><?= e($status) ?></span></td>
                <td class="dampad"><?= e(sv_tid($s['startad'] ?? null)) ?></td>
                <td style="text-align:right">
                    <a class="knapp liten" href="<?= e(u('/inventering/' . e($s['id']))) ?>">
                        <?= $status === 'öppen' ? 'Fortsätt' : 'Visa' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
