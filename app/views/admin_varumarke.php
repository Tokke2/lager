<?php
/** @var array $v @var array $standard */
$csrf = \TDL\Support\Sakerhet::csrfToken();
?>
<h1>Varumärke</h1>
<p class="dampad">Här anpassas appen för ert företag: namn, färger, logotyp och ordval. Ändringarna
    påverkar gränssnitt, etiketter och inloggningssida direkt – ingen kod behöver ändras.</p>

<div class="kort">
    <h2>Förhandsvisning</h2>
    <div style="padding:16px;border-radius:12px;background:<?= e($v['farg_bakgrund']) ?>;display:flex;align-items:center;gap:14px">
        <?php if ($v['logotyp_fil'] !== ''): ?>
            <img src="<?= e(\TDL\Support\Varumarke::logotypUrl()) ?>" alt="" style="height:44px;max-width:280px;object-fit:contain">
        <?php else: ?>
            <svg viewBox="0 0 64 64" style="width:44px;height:44px;flex:0 0 auto" aria-hidden="true">
                <rect x="10.5" y="10.5" width="43" height="43" rx="14" fill="none"
                      stroke="<?= e($v['farg_primar']) ?>" stroke-width="5.8"/>
                <rect x="19" y="24" width="26" height="5.4" rx="2.7" fill="<?= e($v['farg_primar']) ?>"/>
                <rect x="19" y="34" width="17" height="5.4" rx="2.7" fill="<?= e($v['farg_primar']) ?>"/>
                <circle cx="45.5" cy="46" r="5.4" fill="<?= e($v['farg_accent']) ?>"/>
            </svg>
            <strong style="color:#fff;font-size:1.2rem"><?= e($v['namn']) ?></strong>
        <?php endif; ?>
    </div>
</div>

<div class="kort">
    <h2>Namn och färger</h2>
    <form method="post" action="<?= e(u('/admin/varumarke')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt">
                <label for="namn">Företagets namn</label>
                <input id="namn" name="namn" maxlength="40" value="<?= e($v['namn']) ?>"
                       placeholder="Företagets namn" required>
                <p class="falt-hjalp">Visas i menyn, i historiken och på kvitton. Tills du anger det
                   står det <strong>Lager</strong>.</p>
            </div>
            <div class="falt">
                <label for="undertext">Undertext (valfri)</label>
                <input id="undertext" name="undertext" maxlength="60" value="<?= e($v['undertext']) ?>">
            </div>
            <div class="falt">
                <label for="tema">Tema</label>
                <select id="tema" name="tema">
                    <option value="mork" <?= $v['tema'] === 'mork' ? 'selected' : '' ?>>Mörkt</option>
                    <option value="ljus" <?= $v['tema'] === 'ljus' ? 'selected' : '' ?>>Ljust</option>
                </select>
            </div>
        </div>
        <div class="rad">
            <div class="falt">
                <label for="farg_primar">Primärfärg</label>
                <input id="farg_primar" name="farg_primar" type="color" value="<?= e($v['farg_primar']) ?>" style="height:48px;padding:4px">
            </div>
            <div class="falt">
                <label for="farg_accent">Accentfärg</label>
                <input id="farg_accent" name="farg_accent" type="color" value="<?= e($v['farg_accent']) ?>" style="height:48px;padding:4px">
            </div>
            <div class="falt">
                <label for="farg_bakgrund">Bakgrundsfärg</label>
                <input id="farg_bakgrund" name="farg_bakgrund" type="color" value="<?= e($v['farg_bakgrund']) ?>" style="height:48px;padding:4px">
            </div>
        </div>
        <div class="rad">
            <div class="falt">
                <label for="term_arbetsstalle">Ordet för "arbetsställe"</label>
                <input id="term_arbetsstalle" name="term_arbetsstalle" maxlength="30" value="<?= e($v['term_arbetsstalle']) ?>">
            </div>
            <div class="falt">
                <label for="term_inventering">Ordet för "inventering"</label>
                <input id="term_inventering" name="term_inventering" maxlength="30" value="<?= e($v['term_inventering']) ?>">
            </div>
            <div class="falt">
                <label for="support_epost">Support-e-post (visas för personal)</label>
                <input id="support_epost" name="support_epost" type="email" maxlength="80" value="<?= e($v['support_epost']) ?>">
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="primar">Spara varumärke</button>
            <a class="knapp" href="<?= e(u('/admin/varumarke')) ?>">Återställ formuläret</a>
        </div>
    </form>
</div>

<div class="kort">
    <h2>Förhandsvisning</h2>
    <p class="dampad">Så här ser märket ut i menyn och på inloggningssidan. Färgerna följer
        inställningarna ovan – byt färg och spara för att se resultatet direkt.</p>
    <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:center;padding:16px 0 4px">
        <div style="background:#0a101a;border:1px solid var(--kant);border-radius:12px;padding:14px 18px">
            <?= \TDL\Support\Varumarke::menyLogotyp((string) $v['namn']) ?>
        </div>
        <?php if ($v['logotyp_fil'] === ''): ?>
        <img src="<?= e(tdl_tillgang('assets/marke.svg')) ?>" alt="" style="height:56px;width:auto">
        <img src="<?= e(tdl_tillgang('assets/ikon.svg')) ?>" alt="" style="height:56px;width:auto;border-radius:10px">
        <?php endif; ?>
    </div>
</div>

<div class="kort">
    <h2>Tryckoriginal</h2>
    <p class="dampad">Märket är fristående: <strong>inga bokstäver och inget företagsnamn</strong>,
        så det fungerar för vilken installation som helst – även efter vitmärkning. Filerna är ren
        SVG med mått i mm, och följer med appen; det finns ingen lös fil att hålla reda på.</p>
    <table>
        <tr><th>Fil</th><th>Användning</th></tr>
        <tr><td class="mono">brand/tryck/marke-tryckark.svg</td><td>Färdigt A3-ark med alla varianter, mått och färger</td></tr>
        <tr><td class="mono">brand/ikon.svg</td><td>Appikon, mörk botten</td></tr>
        <tr><td class="mono">brand/ikon-ljus.svg</td><td>Appikon, ljus botten</td></tr>
        <tr><td class="mono">brand/marke.svg + marke-ljus.svg</td><td>Märket utan bakgrund (favicon, tray, etsning)</td></tr>
        <tr><td class="mono">brand/marke-enfarg.svg</td><td>Stämpel, gravyr, brodyr</td></tr>
    </table>
    <div class="actions">
        <a class="knapp primar" href="<?= e(u('/varumarke/tryck')) ?>">Ladda ner alla tryckoriginal (.zip)</a>
    </div>
</div>

<div class="kort">
    <h2>Egen logotyp (valfri)</h2>
    <p class="dampad">Standardmärket är fristående och innehåller inga bokstäver eller firmanamn –
        det behöver inte bytas. Vill ni ändå använda ett eget märke kan ni ladda upp en SVG här.
        Filen saneras automatiskt: skript, externa bilder och händelseattribut tas bort.</p>
    <form method="post" action="<?= e(u('/admin/varumarke/logotyp')) ?>" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="rad">
            <div class="falt">
                <label for="fil">Logotypfil (.svg, max 512 kB)</label>
                <input id="fil" name="fil" type="file" accept=".svg,image/svg+xml" required>
            </div>
            <div class="falt">
                <label for="typ">Användning</label>
                <select id="typ" name="typ">
                    <option value="logotyp">Huvudlogotyp (bredvid namn)</option>
                    <option value="ikon">Appikon (kvadratisk)</option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="primar">Ladda upp</button>
        </div>
    </form>

    <?php if ($v['logotyp_fil'] !== '' || $v['ikon_fil'] !== ''): ?>
    <hr class="avdelare">
    <form method="post" action="<?= e(u('/admin/varumarke/aterstall')) ?>" class="actions" style="margin:0">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="typ" value="logotyp">
        <button type="submit" class="fara liten">Ta bort egen logotyp</button>
    </form>
    <?php endif; ?>
</div>

<div class="kort">
    <h2>Så vitmärks en installation</h2>
    <ol class="dampad" style="line-height:1.8">
        <li>Ladda upp kundens logotyp här (eller lägg filen i <span class="mono">storage/varumarke/logotyp.svg</span>).</li>
        <li>Sätt namn, färger och tema ovan.</li>
        <li>Skapa första administratören med <span class="mono">php bin/installera.php --namn="…" --pin=…</span>.</li>
        <li>Kör <span class="mono">php bin/backup.php</span> och verifiera med <span class="mono">php bin/verifiera-historik.php</span>.</li>
        <li>Skriv ut arbetsplats-QR och etiketter – de använder automatiskt kundens namn och färger.</li>
    </ol>
    <p class="falt-hjalp">Logotypfilen och varumärkesinställningarna ligger i <span class="mono">storage/</span>
        som är privat och aldrig nåbart via URL.</p>
</div>
