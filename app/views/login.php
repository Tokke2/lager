<?php /** @var array $aktiva */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="kort" style="max-width:440px;margin:6vh auto 0">
    <?php $v = \TDL\Support\Varumarke::alla(); ?>
    <?php if ($v['logotyp_fil'] !== ''): ?>
        <img src="<?= e(\TDL\Support\Varumarke::logotypUrl()) ?>" alt="<?= e($v['namn']) ?>" style="max-width:260px;height:auto;margin-bottom:12px">
    <?php else: ?>
        <div class="inlogg-marke"><?= \TDL\Support\Varumarke::menyLogotyp((string) $v['namn']) ?></div>
    <?php endif; ?>

    <?php if ($aktiva === []): ?>
        <p class="dampad">Inga aktiva konton finns. En administratör måste skapa konton.</p>
    <?php else: ?>

    <div class="valj-typ" role="tablist" aria-label="Inloggningstyp">
        <button type="button" class="valj-knapp vald" data-inloggning="personal" role="tab" aria-selected="true">
            Personal<br><span class="dampad" style="font-size:.78rem">PIN-kod</span>
        </button>
        <button type="button" class="valj-knapp" data-inloggning="admin" role="tab" aria-selected="false">
            Administratör<br><span class="dampad" style="font-size:.78rem">Endast PIN</span>
        </button>
    </div>

    <form method="post" action="<?= e(u('/login')) ?>" id="personal-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="namn">Namn</label>
        <?php /* Textfält, inte en lista: Bitwarden och andra lösenordshanterare
                 sparar bara inloggningar när användarnamnet är ett inmatningsfält.
                 Namnet matchas utan hänsyn till versaler (Konto::kontoViaNamn). */ ?>
        <input id="namn" name="namn" type="text" required autofocus
               autocomplete="username" autocapitalize="words" spellcheck="false"
               placeholder="Ditt namn" list="namn-lista">
        <datalist id="namn-lista">
            <?php foreach ($aktiva as $u): ?>
                <?php if ($u['roll'] === 'admin') { continue; } ?>
                <option value="<?= e($u['namn']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <label for="pin">PIN-kod</label>
        <input id="pin" name="pin" type="password"
               autocomplete="current-password" required>
        <div class="actions">
            <button type="submit" class="primar" style="width:100%">Logga in</button>
        </div>
    </form>
    <div style="margin-top:10px;text-align:center">
        <button type="button" class="sekundar" id="qr-bricka-btn" style="width:100%">📷 Skanna QR-bricka</button>
        <video id="qr-bricka-video" playsinline style="display:none;width:100%;border-radius:10px;margin-top:8px"></video>
    </div>
    <div id="pin-knappsats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:12px">
        <?php foreach (['1','2','3','4','5','6','7','8','9','/','0','⌫'] as $k): ?>
            <button type="button" class="sekundar pin-knapp" data-k="<?= e($k) ?>" style="padding:12px;font-size:1.1rem;font-weight:700"><?= e($k) ?></button>
        <?php endforeach; ?>
    </div>
    <script>
    (function () {
        document.querySelectorAll('.pin-knapp').forEach(function (b) {
            b.addEventListener('click', function () {
                var adminSynlig = !document.getElementById('admin-form').hasAttribute('hidden');
                var inp = document.getElementById(adminSynlig ? 'pin-admin' : 'pin');
                if (!inp) return;
                var k = b.getAttribute('data-k');
                if (k === '⌫') inp.value = inp.value.slice(0, -1);
                else inp.value += k;
                inp.focus();
            });
        });
    })();
    </script>

    <form method="post" action="<?= e(u('/login')) ?>" id="admin-form" hidden>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="namn-admin">Administratör</label>
        <input id="namn-admin" name="namn" type="text" required
               autocomplete="username" autocapitalize="words" spellcheck="false"
               placeholder="Ditt namn" list="namn-lista-admin">
        <datalist id="namn-lista-admin">
            <?php foreach ($aktiva as $u): ?>
                <?php if ($u['roll'] !== 'admin') { continue; } ?>
                <option value="<?= e($u['namn']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <label for="pin-admin">PIN-kod</label>
        <input id="pin-admin" name="pin" type="password"
               autocomplete="current-password" required>
        <div class="actions">
            <button type="submit" class="primar" style="width:100%">Logga in som administratör</button>
        </div>
        <p class="falt-hjalp">PIN-koden måste bytas var <?= (int) \TDL\Support\Konto::LÖSENORD_DAGAR ?>:e dag.</p>
    </form>

    <p class="falt-hjalp">Glömd PIN? En administratör kan sätta en ny.</p>
    <?php endif; ?>
</div>

<style>
    .valj-typ { display: flex; gap: 8px; margin: 0 0 18px; }
    .valj-knapp {
        flex: 1; padding: 10px 8px; border-radius: 10px; cursor: pointer;
        border: 1px solid var(--kant); background: var(--yta); color: var(--text);
        font-weight: 600; font-size: .9rem; line-height: 1.3; text-align: center;
    }
    .valj-knapp.vald { border-color: var(--accent); background: rgba(52, 211, 153, .1); }
</style>
