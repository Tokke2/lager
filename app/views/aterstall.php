<?php
/** @var int $antalKonton */
$csrf = \TDL\Support\Sakerhet::csrfToken();
?>
<div class="kort" style="max-width:560px;margin:6vh auto 0">
    <h1>Nollställ installationen</h1>
    <p>Appen är redan installerad med <strong><?= (int) $antalKonton ?> konto/konton</strong>.
        Den här sidan tar bort allt och startar om från början, så att du kan ange
        företagets namn och välja PIN-kod igen.</p>

    <div class="flash varning">
        <strong>Allt tas bort.</strong><br>
        <span class="falt-hjalp">Konton, artiklar, saldon, historik, bilder, företagsnamn och
            QR-nyckeln försvinner. Redan utskrivna QR-lappar slutar fungera.
            Säkerhetskopiera först om du vill behålla något – det går inte att ångra.</span>
    </div>

    <form method="post" action="<?= e(u('/aterstall')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="token">Installationstoken</label>
        <input id="token" name="token" autocomplete="off" class="mono" required
               placeholder="Klistra in token från storage/setup.token">
        <div class="falt-hjalp">Samma token som vid första installationen. Den står i
            <span class="mono">storage/setup.token</span> på servern.</div>

        <label for="bekrafta">Skriv <span class="mono">NOLLSTALL</span> för att bekräfta</label>
        <input id="bekrafta" name="bekrafta" required autocomplete="off" class="mono">

        <div class="actions">
            <button type="submit" class="primar">Ta bort allt och börja om</button>
        </div>
    </form>

    <p class="falt-hjalp" style="margin-top:1.5rem">
        <a href="<?= e(u('/login')) ?>">Tillbaka till inloggningen</a>
        – stäng den här sidan om du inte menade att nollställa.
    </p>
</div>
