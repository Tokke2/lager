<?php
/**
 * Byt lösenord (endast administratörer).
 * @var array $session @var array $konto @var bool $masteByta @var ?int $dagarKvar
 */
$csrf = \TDL\Support\Sakerhet::csrfToken();
$max = (int) \TDL\Support\Konto::LÖSENORD_DAGAR;
?>
<div class="kort" style="max-width:560px;margin:4vh auto 0">
    <h1>Byt lösenord</h1>

    <?php if ($masteByta): ?>
        <div class="flash varning">
            <strong>Ditt lösenord har gått ut.</strong>
            Det måste bytas var <?= $max ?>:e dag. Välj ett nytt för att fortsätta.
        </div>
    <?php elseif ($dagarKvar !== null): ?>
        <p class="dampad">
            Lösenordet går ut om <strong><?= max(0, (int) $dagarKvar) ?> dag(ar)</strong>.
            Det byts automatiskt var <?= $max ?>:e dag av säkerhetsskäl.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e(u('/mitt-konto/losenord')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="nuvarande">Nuvarande lösenord</label>
        <input id="nuvarande" name="nuvarande" type="password" autocomplete="current-password"
               <?= $masteByta ? '' : 'required' ?>>

        <label for="nytt">Nytt lösenord</label>
        <input id="nytt" name="nytt" type="password" minlength="12" required autocomplete="new-password">
        <div class="falt-hjalp">
            Minst 12 tecken, både bokstäver och siffror. Får inte ha använts de senaste gångerna.
        </div>

        <label for="nytt2">Upprepa nytt lösenord</label>
        <input id="nytt2" name="nytt2" type="password" minlength="12" required autocomplete="new-password">

        <div class="actions">
            <button type="submit" class="primar">Spara nytt lösenord</button>
            <?php if (!$masteByta): ?>
                <a class="knapp" href="<?= e(u('/')) ?>">Avbryt</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="falt-hjalp">
        Glömt lösenordet? En annan administratör kan sätta ett nytt under
        <a href="<?= e(u('/konton')) ?>">Konton</a>. Finns bara en admin: kör
        <span class="mono">php bin/aterstall-losenord.php</span> på servern.
    </p>
</div>
