<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Mitt konto</h1>

<div class="kort">
    <p><strong><?= e($konto['namn']) ?></strong><br>
        <span class="dampad">Roll: <?= $konto['roll'] === 'admin' ? 'Administratör' : 'Lagerpersonal' ?></span><br>
        <span class="dampad">Senast inloggad: <?= e(sv_tid($konto['senast_inloggad'] ?? null)) ?></span></p>
    <form method="post" action="<?= e(u('/logout')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit">Logga ut</button>
    </form>
</div>

<div class="kort">
    <h2><?= !empty($konto['maste_byta_pin']) ? 'Välj din PIN-kod' : 'Byt PIN-kod' ?></h2>
    <?php if (!empty($konto['maste_byta_pin'])): ?>
        <div class="flash varning">
            <strong>Du måste välja en egen PIN-kod innan du kan använda appen.</strong><br>
            <span class="falt-hjalp">Kontot har en tillfällig PIN som någon annan har satt.
                Välj en fyra till åtta siffror lång kod som bara du vet.</span>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(u('/mitt-konto/pin')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt">
                <label for="nuvarande_pin">Nuvarande PIN</label>
                <input id="nuvarande_pin" name="nuvarande_pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" required autocomplete="current-password">
            </div>
            <div class="falt">
                <label for="pin">Ny PIN (4–8 siffror)</label>
                <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" required autocomplete="new-password">
            </div>
            <div class="falt">
                <label for="pin2">Upprepa ny PIN</label>
                <input id="pin2" name="pin2" type="password" inputmode="numeric" pattern="[0-9]{4,8}" required autocomplete="new-password">
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="primar">Byt PIN</button>
        </div>
    </form>
</div>

<?php if (($konto['roll'] ?? '') === 'admin'): ?>
<div class="kort">
    <h2>Lösenord</h2>
    <?php
    $dagar = \TDL\Support\Konto::dagarKvarPaLosenord($konto);
    $max = (int) \TDL\Support\Konto::LÖSENORD_DAGAR;
    ?>
    <?php if ($dagar === null): ?>
        <div class="flash varning">Du har inget lösenord än. Sätt ett nu – det behövs vid nästa inloggning.</div>
    <?php elseif ($dagar <= 0): ?>
        <div class="flash varning">Lösenordet har gått ut och måste bytas nu.</div>
    <?php elseif ($dagar <= \TDL\Support\Konto::LÖSENORD_VARNING_DAGAR): ?>
        <div class="flash varning">Lösenordet går ut om <?= $dagar ?> dag(ar).</div>
    <?php else: ?>
        <p class="dampad">Lösenordet gäller i <?= $max ?> dagar – <?= $dagar ?> dag(ar) kvar.</p>
    <?php endif; ?>
    <div class="actions">
        <a class="knapp primar" href="<?= e(u('/mitt-konto/losenord')) ?>">Byt lösenord</a>
    </div>
    <p class="falt-hjalp">Lösenordet behövs vid inloggning utöver PIN-koden och gäller i <?= $max ?> dagar i taget.</p>
</div>
<?php endif; ?>
