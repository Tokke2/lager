<?php /** @var array $aktiva */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<div class="inlogg">
<div class="kort">
    <?php $v = \TDL\Support\Varumarke::alla(); ?>
    <?php if ($v['logotyp_fil'] !== ''): ?>
        <img src="<?= e(\TDL\Support\Varumarke::logotypUrl()) ?>" alt="<?= e($v['namn']) ?>" style="max-width:260px;height:auto;margin-bottom:12px">
    <?php else: ?>
        <div class="inlogg-marke"><?= \TDL\Support\Varumarke::menyLogotyp((string) $v['namn']) ?></div>
    <?php endif; ?>

    <?php if ($aktiva === []): ?>
        <p class="dampad">Inga aktiva konton finns. En administratör måste skapa konton.</p>
    <?php else: ?>

    <form method="post" action="<?= e(u('/login')) ?>" id="login-form">
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
                <option value="<?= e($u['namn']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <label for="pin">PIN-kod</label>
        <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8"
               autocomplete="current-password" required>
        <div class="actions">
            <button type="submit" class="primar" style="width:100%">Logga in</button>
        </div>
    </form>

    <p class="falt-hjalp">Glömd PIN? En administratör kan sätta en ny.</p>
    <?php endif; ?>
</div>
</div><!-- /.inlogg -->
