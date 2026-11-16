<?php /** @var array $info */ $csrf = \TDL\Support\Sakerhet::csrfToken(); $arAdmin = ($konto['roll'] ?? '') === 'admin'; ?>
<h1>Arbetsställen</h1>
<p class="dampad">Varje arbetsställe har ett beständigt ID och en egen arbetsplats-QR. En plats kan inte
    stängas medan den har saldo, enheter eller en öppen inventering.</p>

<?php if ($arAdmin): ?>
<div class="kort">
    <h2>Nytt arbetsställe</h2>
    <p class="dampad" style="margin-top:0">
        Lägg till så många arbetsställen du behöver – lager, filial, bil, byggarbetsplats.
        Varje plats har eget saldo och egen inventering.
    </p>
    <form method="post" action="<?= e(u('/platser/ny')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt">
                <label for="namn">Namn</label>
                <input id="namn" name="namn" required maxlength="40" placeholder="t.ex. Örebro" autofocus>
            </div>
            <div class="falt">
                <label for="kod">Kort kod</label>
                <input id="kod" name="kod" required minlength="2" maxlength="6" class="mono"
                       placeholder="ORE" pattern="[A-Za-z0-9]{2,6}">
                <div class="falt-hjalp">2–6 tecken, syns på etiketter och i listor.</div>
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="primar">Lägg till</button>
            <span class="dampad" style="margin:0">
                <?= count(array_filter($info, fn ($r) => !empty($r['plats']['aktiv']))) ?> aktiva arbetsställen
            </span>
        </div>
    </form>
</div>
<?php else: ?>
<div class="flash info">
    Bara administratörer kan lägga till eller ändra arbetsställen.
</div>
<?php endif; ?>

<?php foreach ($info as $rad): $p = $rad['plats']; ?>
<div class="kort">
    <div class="actions" style="justify-content:space-between;margin:0 0 6px">
        <h2 style="margin:0">
            <?= e($p['namn']) ?>
            <span class="dampad mono" style="font-size:.9rem"><?= e($p['kod']) ?></span>
            <?php if (empty($p['aktiv'])): ?><span class="tag">stängd</span><?php endif; ?>
            <?php if (!empty($p['system'])): ?><span class="tag varning">systempost</span><?php endif; ?>
            <?php if ($rad['oppenSession'] !== null): ?><span class="tag varning">öppen inventering</span><?php endif; ?>
        </h2>
        <a class="knapp liten" href="<?= e(u('/platsetikett?plats=' . e($p['id']))) ?>" target="_blank">Skriv ut QR</a>
    </div>
    <p class="dampad mono" style="font-size:.85rem"><?= e(\TDL\Support\Koder::visa($p['qr'])) ?> · ID <?= e($p['id']) ?></p>

    <?php if (!empty($p['system'])): ?>
        <p class="falt-hjalp">Systemposten används för äldre saldo som ännu inte fördelats. Kan inte stängas.</p>
    <?php else: ?>
        <p><?= (int) $rad['enheter'] ?> aktiva QR-enheter</p>
        <?php if ($rad['rader'] !== []): ?>
            <div class="tabell-rull">
            <table>
                <tr><th>Artikel</th><th class="tal">Saldo</th></tr>
                <?php foreach ($rad['rader'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(u('/artiklar/' . e($r['artikel']['id']))) ?>"><?= e($r['artikel']['namn']) ?></a></td>
                        <td class="tal"><?= (int) $r['antal'] ?> <?= e($r['artikel']['enhet']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>
        <?php else: ?>
            <p class="dampad">Inget saldo på platsen.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($arAdmin && empty($p['system'])): ?>
        <hr class="avdelare">
        <form method="post" action="<?= e(u('/platser/' . (e($p['id'])) . '/redigera')) ?>" class="actions" style="margin-top:0">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input name="namn" value="<?= e($p['namn']) ?>" maxlength="40" required style="max-width:220px" aria-label="Namn">
            <input name="kod" value="<?= e($p['kod']) ?>" maxlength="4" required class="mono" style="max-width:100px" aria-label="Kod">
            <button type="submit" class="liten">Spara namn/kod</button>
        </form>
        <?php if (!empty($p['aktiv'])): ?>
        <form method="post" action="<?= e(u('/platser/' . (e($p['id'])) . '/stang')) ?>" style="margin-top:10px"
              data-bekrafta="Stäng arbetsstället? Det går bara om saldo, enheter och sessioner är avslutade. Ingen data raderas.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="fara liten">Stäng arbetsstället</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
