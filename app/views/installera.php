<?php /** @var array $token */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="kort" style="max-width:520px;margin:6vh auto 0">
    <h1>Installera</h1>
    <p>Ange företagets namn och välj den <strong>första administratörens</strong> PIN-kod.
        Sedan loggar du in med den. Flödet fungerar bara en gång – när kontot
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

        <label for="pin">PIN-kod (4–10 tecken)</label>
        <input id="pin" name="pin" type="password" minlength="4" maxlength="10" required
               autocomplete="new-password">
        <div class="falt-hjalp">4–10 tecken (siffror och/eller specialtecken, t.ex. 547869/).</div>
        <label for="pin2">Upprepa PIN-kod</label>
        <input id="pin2" name="pin2" type="password" minlength="4" maxlength="10" required
               autocomplete="new-password">
        <label for="token">Installationstoken</label>
        <input id="token" name="token" autocomplete="off" class="mono" required
               placeholder="Klistra in token från storage/setup.token">
        <div class="actions">
            <button type="submit" class="primar">Installera och fortsätt till inloggning</button>
        </div>
    </form>
    <form method="post" action="<?= e(u('/installera/token')) ?>" style="margin-top:10px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="sekundar">Generera ny token på servern</button>
    </form>
</div>
<script>
(function () {
    var form = document.querySelector('form[action*="installera"]');
    if (!form) return;
    function visaBubbla(el, msg) {
        var gammal = document.getElementById('inst-fel-bubbla');
        if (gammal) gammal.remove();
        document.querySelectorAll('.inst-fel-falt').forEach(function (x) {
            x.classList.remove('inst-fel-falt');
            x.style.borderColor = '';
        });
        el.classList.add('inst-fel-falt');
        el.style.borderColor = '#dc2626';
        var b = document.createElement('div');
        b.id = 'inst-fel-bubbla';
        b.textContent = msg;
        b.style.cssText = 'background:#dc2626;color:#fff;padding:6px 10px;border-radius:8px;font-size:.82rem;font-weight:700;margin:6px 0 4px;display:inline-block;';
        el.parentNode.insertBefore(b, el);
        el.focus();
    }
    form.addEventListener('submit', function (e) {
        var f = document.getElementById('foretag');
        var n = document.getElementById('namn');
        var p1 = document.getElementById('pin');
        var p2 = document.getElementById('pin2');
        if (!f.value.trim()) { e.preventDefault(); visaBubbla(f, 'Fyll i företagets namn.'); return; }
        if (!n.value.trim()) { e.preventDefault(); visaBubbla(n, 'Fyll i ditt namn.'); return; }
        if (p1.value.length < 4 || p1.value.length > 10) {
            e.preventDefault();
            visaBubbla(p1, 'PIN-koden måste vara 4–10 tecken (t.ex. 547869/).');
            return;
        }
        if (p1.value !== p2.value) {
            e.preventDefault();
            visaBubbla(p2, 'PIN-koderna stämmer inte överens.');
            return;
        }
    });
    var tgForm = document.getElementById('token-gen-form');
    if (tgForm) {
        tgForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var st = document.getElementById('token-gen-status');
            if (st) st.textContent = 'Skapar token...';
            fetch(tgForm.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new FormData(tgForm)
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (st) st.textContent = d.meddelande || 'Token skapad!';
            }).catch(function () {
                if (st) st.textContent = 'Kunde inte skapa token.';
            });
        });
    }
})();
</script>
