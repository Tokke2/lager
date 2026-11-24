<?php /** @var array $konton */ $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Konton</h1>
<p class="dampad">Alla konton loggar in med namn och PIN-kod.
    <br><strong>Stäng av</strong> ett konto direkt när någon slutar – personen kan då inte logga in,
    och PIN-koden slutar gälla omedelbart. Återaktiverar du kontot senare behöver personen en ny PIN.
    Allt lagras hashat och kan inte läsas ut –
    vid skapande eller återställning visas den en gång så att du kan lämna den till personen.</p>

<div class="kort">
    <h2>Nytt konto</h2>
    <form method="post" action="<?= e(u('/konton')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt">
                <label for="namn">Namn</label>
                <input id="namn" name="namn" required maxlength="40" placeholder="t.ex. Anna">
            </div>
            <div class="falt">
                <label for="roll">Roll</label>
                <select id="roll" name="roll">
                    <option value="personal">Personal</option>
                    <option value="admin">Administratör</option>
                </select>
            </div>
            <div class="falt">
                <label for="pin">PIN (4–8 siffror, tomt = slumpas)</label>
                <input id="pin" name="pin" inputmode="numeric" pattern="[0-9]{4,8}" autocomplete="off" class="mono">
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="primar">Skapa konto</button>
        </div>
    </form>
</div>

<div class="kort">
    <h2>Alla konton</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>Namn</th><th>Roll</th><th>Status</th><th>Senast inloggad</th><th>Åtgärder</th></tr>
        <?php foreach ($konton as $u): ?>
            <tr>
                <td>
                    <strong><?= e($u['namn']) ?></strong>
                    <?php if (!empty($u['maste_byta_pin'])): ?><span class="tag varning">tillfällig PIN</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" action="<?= e(u('/konton/' . (e($u['id'])) . '/roll')) ?>" class="actions" style="margin:0;gap:6px">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <select name="roll" style="min-height:36px;width:auto">
                            <option value="personal" <?= $u['roll'] === 'personal' ? 'selected' : '' ?>>Personal</option>
                            <option value="admin" <?= $u['roll'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                        <button type="submit" class="liten">Byt</button>
                    </form>
                </td>
                <td>
                    <?php if (!empty($u['aktiv'])): ?>
                        <span class="tag ok">aktiv</span>
                    <?php else: ?>
                        <span class="tag fel">avstängd</span>
                    <?php endif; ?>
                </td>
                <td class="dampad"><?= e(sv_tid($u['senast_inloggad'] ?? null)) ?></td>
                <td>
                    <?php if (!empty($u['aktiv']) && $u['id'] !== $konto['id']): ?>
                        <div class="actions" style="margin:0;gap:6px">
                            <form method="post" action="<?= e(u('/konton/' . (e($u['id'])) . '/pin')) ?>" style="margin:0"
                                  data-bekrafta="Sätt ny PIN för <?= e($u['namn']) ?>? Den gamla slutar fungera direkt.">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="liten">Ny PIN</button>
                            </form>
                            <form method="post" action="<?= e(u('/konton/' . (e($u['id'])) . '/stang')) ?>" style="margin:0"
                                  data-bekrafta="Stäng av <?= e($u['namn']) ?>? Kontot kan inte logga in längre.">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="fara liten">Stäng av</button>
                            </form>
                        </div>
                    <?php elseif (empty($u['aktiv'])): ?>
                        <div class="actions" style="margin:0;gap:6px">
                            <form method="post" action="<?= e(u('/konton/' . (e($u['id'])) . '/aktivera')) ?>" style="margin:0"
                                  data-bekrafta="Aktivera <?= e($u['namn']) ?>? Personen behöver en ny PIN-kod innan inloggning fungerar.">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="liten">Aktivera</button>
                            </form>
                            <form method="post" action="<?= e(u('/konton/' . (e($u['id'])) . '/pin')) ?>" style="margin:0"
                                  data-bekrafta="Sätt ny PIN för <?= e($u['namn']) ?>?">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="liten">Ny PIN</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="dampad">ditt konto</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
