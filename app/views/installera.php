<?php /** @var array $token */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="kort" style="max-width:520px;margin:6vh auto 0">
    <h1>Installera</h1>
    <p>Ange företagets namn och välj den <strong>första administratörens</strong> PIN-kod. Sedan loggar du in med namn och PIN. Flödet fungerar bara en gång – när kontot
        finns stängs det automatiskt.</p>

    <?php if (!empty($token['lokal'])): ?>
        <div class="flash info">
            <strong>Lokal installation</strong><br>
            <span class="falt-hjalp">Du kör appen på den här datorn. Välj namn och PIN-kod –
                token behövs inte.</span>
        </div>
    <?php else: ?>
        <div class="flash info">
            <strong>Installationstoken</strong><br>
            <span class="falt-hjalp">Token står i filen <span class="mono">storage/setup.token</span> på servern
                (öppna den via FTP eller filhanteraren). Den visas inte här – då skulle vem som helst som
                hittade sidan kunna ta över appen. Har du åtkomst till kommandoraden går det snabbare:
                <span class="mono">php bin/installera.php --namn="Anna" --pin=4821</span></span>
        </div>
    <?php endif; ?>


    <form method="post" action="<?= e(u('/installera')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="foretag">Företagets namn</label>
        <input id="foretag" name="foretag" required maxlength="40" autocomplete="organization"
               placeholder="Företagets namn">
        <div class="falt-hjalp">Visas i menyn och i historiken. Kan ändras senare under Varumärke.</div>

        <label for="namn">Ditt namn (visas i historiken)</label>
        <?php /* autocomplete="username" gör att Bitwarden och andra
                 lösenordshanterare erbjuder sig att spara inloggningen som
                 skapas här – det är ju den du sedan loggar in med. */ ?>
        <input id="namn" name="namn" required maxlength="40" autocomplete="username">

        <label for="pin">PIN-kod (4–8 siffror)</label>
        <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" required
               autocomplete="new-password">
        <div class="falt-hjalp">Den PIN-kod du loggar in med. Välj en som bara du vet.</div>
        <label for="pin2">Upprepa PIN-kod</label>
        <input id="pin2" name="pin2" type="password" inputmode="numeric" pattern="[0-9]{4,8}" required
               autocomplete="new-password">
        <label for="token">Installationstoken</label>
        <input id="token" name="token" autocomplete="off" class="mono"
               placeholder="Klistra in token från storage/setup.token">
        <div class="actions">
            <button type="submit" class="primar">Installera och fortsätt till inloggning</button>
        </div>
    </form>
</div>
