<?php /** @var array $rader @var string $q */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="actions" style="justify-content:space-between;margin-top:0">
    <h1 style="margin:0">Artiklar</h1>
    <?php // Personal får lägga upp nya artiklar (admin kan ändra dem efteråt) ?>
    <a class="knapp primar" href="<?= e(u('/artiklar/ny')) ?>">Ny artikel</a>
</div>

<form method="get" action="<?= e(u('/artiklar')) ?>" class="actions" style="margin-top:12px;flex-wrap:wrap;align-items:end">
    <div class="falt" style="margin:0">
        <label for="q" class="dold-mobil">Sök</label>
        <input id="q" name="q" value="<?= e($q) ?>" placeholder="Namn, nummer, kategori eller hylla"
               style="width:min(34ch, 100%);min-width:16ch">
    </div>
    <div class="falt" style="margin:0">
        <label for="kategori" class="dold-mobil">Kategori</label>
        <select id="kategori" name="kategori" data-skicka-vid-andring style="width:auto;max-width:220px">
            <option value="">Alla kategorier</option>
            <?php foreach ($kategorier as $k): ?>
                <option value="<?= e($k) ?>" <?= mb_strtolower($k) === mb_strtolower($kategori) ? 'selected' : '' ?>><?= e($k) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="falt" style="margin:0">
        <label for="hylla" class="dold-mobil">Hylla</label>
        <select id="hylla" name="hylla" data-skicka-vid-andring style="width:auto;max-width:200px">
            <option value="">Alla hyllor</option>
            <?php foreach ($hyllor as $h): ?>
                <option value="<?= e($h) ?>" <?= mb_strtolower($h) === mb_strtolower($hylla) ? 'selected' : '' ?>><?= e($h) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <label class="kryss" style="margin:0">
        <input type="checkbox" name="under_min" value="1" <?= $underMin ? 'checked' : '' ?> data-skicka-vid-andring>
        Under miniminivå
    </label>
    <button type="submit">Sök</button>
    <?php if ($q !== '' || $kategori !== '' || $hylla !== '' || $underMin): ?>
        <a class="knapp liten" href="<?= e(u('/artiklar')) ?>">Rensa</a>
    <?php endif; ?>
</form>

<div class="kort" style="margin-top:14px">
    <div class="tabell-rull">
    <table>
        <tr>
            <th>Artikel</th><th>Nr</th><th>Plats</th><th class="dold-mobil">Kategori</th><th>Saldohantering</th><th class="tal">Totalt</th><th>Per arbetsställe</th><th class="dold-mobil">Bilder</th><th></th>
        </tr>
        <?php foreach ($rader as $rad): $a = $rad['artikel']; ?>
            <tr>
                <td>
                    <a href="<?= e(u('/artiklar/' . e($a['id']))) ?>"><strong><?= e($a['namn']) ?></strong></a>
                    <?php if (empty($a['aktiv'])): ?> <span class="tag fel">inaktiv</span><?php endif; ?>
                    <?php if (!empty($a['bilder'])): ?><span class="tag"><?= count($a['bilder']) ?> bild<?= count($a['bilder']) === 1 ? '' : 'er' ?></span><?php endif; ?>
                    <?php if (!empty($a['miniminiva']) && $rad['total'] < $a['miniminiva']): ?>
                        <span class="tag varning">under miniminivå</span>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= e($a['artikelnummer']) ?></td>
                <td>
                    <?php $plats = \TDL\Domain\Lagerplats::platsText($a); ?>
                    <?php if ($plats === ''): ?>
                        <a href="<?= e(u('/artiklar/' . e($a['id']) . '/redigera')) ?>" class="tag varning">plats saknas</a>
                    <?php else: ?>
                        <span class="mono" style="white-space:nowrap"><?= e($plats) ?></span>
                    <?php endif; ?>
                </td>
                <td class="dold-mobil">
                    <?php $kat = (string) ($a['kategori'] ?? ''); ?>
                    <?php if ($kat === ''): ?>
                        <span class="dampad">–</span>
                    <?php else: ?>
                        <span class="tag"><?= e($kat) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $a['sparningssatt'] === 'qr' ? 'QR per enhet' : 'Antal' ?></td>
                <td class="tal"><?= (int) $rad['total'] ?> <?= e($a['enhet']) ?></td>
                <td>
                    <?php
                    $delar = [];
                    foreach (\TDL\Domain\Lager::platser() as $p) {
                        $n = $rad['saldo'][$p['id']] ?? 0;
                        if ($n !== 0) {
                            $delar[] = e($p['kod'] . ': ' . $n);
                        }
                    }
                    echo $delar === [] ? '<span class="dampad">–</span>' : implode(' · ', $delar);
                    ?>
                </td>
                <td class="dold-mobil">
                    <?php if (!empty($a['bilder'])): ?>
                        <img src="<?= e(\TDL\Http\ArtikelQr::bildUrl($a['id'], $a['bilder'][0]['fil'])) ?>" alt="" loading="lazy" style="width:44px;height:44px;object-fit:cover;border-radius:8px">
                    <?php else: ?>
                        <span class="dampad">–</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <a class="knapp liten" href="<?= e(u('/artiklar/' . (e($a['id'])) . '/etiketter?antal=1&skriv=1')) ?>"
                       title="Skriv ut etikett direkt">Etikett</a>
                    <a class="knapp liten" href="<?= e(\TDL\Http\ArtikelQr::lankUrl($a['id'])) ?>" title="Öppna artikel-QR-sidan">QR</a>
                    <?php if ((\TDL\Support\Auth::inloggad()['roll'] ?? '') === 'admin'): ?>
                        <form method="post" action="<?= e(u('/artiklar/' . e($a['id']) . '/ta-bort')) ?>" style="display:inline"
                              data-bekrafta="Ta bort <?= e($a['namn']) ?>?">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="knapp liten fara" title="Ta bort artikel">Ta bort</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rader === []): ?>
            <tr><td colspan="8" class="dampad">Inga artiklar matchar sökningen.</td></tr>
        <?php endif; ?>
    </table>
    </div>
</div>
