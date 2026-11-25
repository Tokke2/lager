<?php
/**
 * @var array $datafiler  datafiler
 * @var array $kedjefel   historikens avvikelser
 * @var array $backuper   befintliga backuper
 * @var int   $historikAntal
 * @var int   $journaler
 * @var array $kanns      serverförutsättningar
 * @var string $php
 * @var float|false $disk
 * @var int   $sessioner
 * @var string $backupKatalog
 */
$csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Systemstatus</h1>
<p class="dampad">Kontroll av lagring, historik och underhåll. Allt här kräver administratör.</p>

<div class="flash <?= ($backupLage['lage'] ?? 'saknas') === 'ok' ? 'ok' : 'fel' ?>" role="status">
    <strong>Backup:</strong>
    <?= e(\TDL\Support\Backupkontroll::sammanfattning()) ?>
    <?php if (($backupLage['lage'] ?? '') !== 'ok'): ?>
        Kontrollera cron-jobbet på webbhotellet (<span class="mono">dokument/06_drift-webbhotell.md</span>).
    <?php else: ?>
        Gräns för röd varning: <?= (int) $backupMaxDygn ?> dygn.
    <?php endif; ?>
</div>

<?php
// Hälsokontrollen (förslag 20): allt på ett ställe, med nivå per rad.
$halso = $halso ?? \TDL\Support\HalsoKontroll::kor();
$halsoFarg = $halso['niva'] === 'fel' ? 'fel' : ($halso['niva'] === 'varning' ? 'varning' : 'ok');
?>
<div class="flash <?= e($halsoFarg) ?>" role="status">
    <?php if ($halso['niva'] === 'ok'): ?>
        <strong>Hälsokontroll: allt är som det ska.</strong>
        <?= count($halso['punkter']) ?> kontroller utan anmärkning.
    <?php elseif ($halso['niva'] === 'varning'): ?>
        <strong>Hälsokontroll: <?= (int) $halso['varningar'] ?> <?= (int) $halso['varningar'] === 1 ? 'sak' : 'saker' ?> att se över.</strong>
        Inget är trasigt, men åtgärda det som står längre ner.
    <?php else: ?>
        <strong>Hälsokontroll: <?= (int) $halso['fel'] ?> fel måste åtgärdas.</strong>
        Appen kan inte litas på förrän de är lösta.
    <?php endif; ?>
    <a href="<?= e(u('/admin/halsa')) ?>">Visa hela hälsokontrollen</a>
</div>

<div class="rutnat">
    <div class="kort stat <?= $kedjefel === [] ? '' : 'varning' ?>">
        <span class="tal"><?= $kedjefel === [] ? 'OK' : count($kedjefel) ?></span>
        <span class="etikett">historikens hashkedja (<?= (int) $historikAntal ?> poster)</span>
    </div>
    <div class="kort stat">
        <span class="tal"><?= (int) $journaler ?></span>
        <span class="etikett">öppna journaler</span>
    </div>
    <div class="kort stat">
        <span class="tal"><?= count($backuper) ?></span>
        <span class="etikett">backuper i arkivet</span>
    </div>
    <div class="kort stat">
        <span class="tal"><?= e($php) ?></span>
        <span class="etikett">PHP-version</span>
    </div>
    <?php $sp = \TDL\Support\Skrivare::aktiv(); ?>
    <div class="kort stat">
        <span class="tal"><?= e($sp['kort']) ?></span>
        <span class="etikett">etikettskrivare · <?= e(\TDL\Support\Skrivare::tal((float) $sp['bredd'])) ?> mm</span>
    </div>
</div>

<?php if ($kedjefel !== []): ?>
    <div class="flash fel">
        <strong>Historiken har avvikelser</strong> – kontakta teknisk ansvarig och kör
        <span class="mono">php bin/verifiera-historik.php</span> på servern.
        <ul>
            <?php foreach (array_slice($kedjefel, 0, 5) as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="kort">
    <h2>Uppdatering av webbappen</h2>
    <p>Jämför installerade programfiler med den senaste GitHub-releasen från den tillåtna grenens aktuella commit.</p>
    <p class="falt-hjalp">Kontrollen installerar inte kod. Den verifierar inte en releases signatur och ersätter inte test och säkerhetskopiering. Inga ordinarie säkerhetskopior raderas.</p>
    <form method="post" action="<?= e(u('/admin/uppdatering/kontrollera')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit">Jämför med senaste releasen</button>
    </form>
</div>

<div class="kort">
    <h2>Lokal ordlista</h2>
    <p class="falt-hjalp">Ange verksamhetens term och förklaring för ett fält. Båda behövs; lämna båda tomma för att ta bort raden. Förklaringarna visas vid motsvarande fält.</p>
    <form method="post" action="<?= e(u('/admin/system/ordlista')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php $ordlista = \TDL\Support\Ordlista::alla(); foreach (\TDL\Support\Ordlista::FALT as $falt => $etikett): $ord = is_array($ordlista[$falt] ?? null) ? $ordlista[$falt] : []; ?>
        <fieldset><legend><?= e($etikett) ?></legend>
            <label for="ord-<?= e($falt) ?>">Lokal term</label>
            <input id="ord-<?= e($falt) ?>" name="ordlista[<?= e($falt) ?>][term]" maxlength="80" value="<?= e((string) ($ord['term'] ?? '')) ?>">
            <label for="forklaring-<?= e($falt) ?>">Förklaring</label>
            <input id="forklaring-<?= e($falt) ?>" name="ordlista[<?= e($falt) ?>][forklaring]" maxlength="300" value="<?= e((string) ($ord['forklaring'] ?? '')) ?>">
        </fieldset>
        <?php endforeach; ?>
        <div class="actions"><button type="submit">Spara ordlistan</button></div>
    </form>
</div>

<div class="kort">
    <h2>Larm och säkerhet</h2>
    <form method="post" action="<?= e(u('/admin/system/installningar')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <h3>Miniminivå</h3>
        <label class="kryss">
            <input type="checkbox" name="miniminiva_larm" value="1" <?= !empty($installningar['miniminiva_larm']) ? 'checked' : '' ?>>
            Visa larmrad på översikten när något ligger under miniminivå
        </label>
        <label class="kryss">
            <input type="checkbox" name="miniminiva_per_mail" value="1" <?= !empty($installningar['miniminiva_per_mail']) ? 'checked' : '' ?>>
            Skicka sammanställning per e-post (körs av cron)
        </label>
        <label for="miniminiva_mail">E-postadress för sammanställningen</label>
        <input id="miniminiva_mail" name="miniminiva_mail" type="email"
               value="<?= e((string) $installningar['miniminiva_mail']) ?>" placeholder="lager@foretaget.se">

        <h3>Inköp och påfyllning</h3>
        <p class="falt-hjalp">
            Används av beställningsförslagen: målet är att täcka leverantörens
            ledtid plus den extra täckningstiden, räknat på hur mycket som
            förbrukats under mätperioden.
        </p>
        <div class="rad">
            <div class="falt">
                <label for="inkop_ledtid">Leverantörens ledtid (dagar)</label>
                <input id="inkop_ledtid" name="inkop_ledtid" type="number" min="0" max="120" step="1"
                       value="<?= (int) $installningar['inkop_ledtid'] ?>">
            </div>
            <div class="falt">
                <label for="inkop_tackning">Extra täckning (dagar)</label>
                <input id="inkop_tackning" name="inkop_tackning" type="number" min="0" max="180" step="1"
                       value="<?= (int) $installningar['inkop_tackning'] ?>">
            </div>
            <div class="falt">
                <label for="inkop_forbrukning_dagar">Mät förbrukning senaste</label>
                <select id="inkop_forbrukning_dagar" name="inkop_forbrukning_dagar">
                    <?php foreach ([30, 60, 90, 180] as $d): ?>
                        <option value="<?= $d ?>" <?= $d === (int) $installningar['inkop_forbrukning_dagar'] ? 'selected' : '' ?>><?= $d ?> dagar</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h3>Tvåpersonersregel för stora uttag</h3>
        <p class="falt-hjalp">
            Ett uttag som når gränsen måste bekräftas av en kollega med sin PIN-kod.
            Sätt 0 för att stänga av. 0 i båda fälten = regeln är av.
        </p>
        <div class="rad">
            <div class="falt">
                <label for="tva_person_grans">Antal enheter</label>
                <input id="tva_person_grans" name="tva_person_grans" type="number" min="0" step="1"
                       value="<?= (int) $installningar['tva_person_grans'] ?>">
            </div>
            <div class="falt">
                <label for="tva_person_varde">Eller värde i kronor</label>
                <input id="tva_person_varde" name="tva_person_varde" type="number" min="0" step="100"
                       value="<?= (int) $installningar['tva_person_varde'] ?>">
            </div>
            <div class="falt">
                <label for="backup_max_dygn">Backup får vara högst</label>
                <select id="backup_max_dygn" name="backup_max_dygn">
                    <?php foreach ([1, 2, 3, 7, 14] as $d): ?>
                        <option value="<?= $d ?>" <?= $d === (int) $backupMaxDygn ? 'selected' : '' ?>><?= $d ?> dygn gammal</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h3>Etikettskrivare</h3>
        <p class="falt-hjalp">
            Styr hur brett etiketterna skrivs ut. Appen skickar etiketten till
            webbläsarens utskriftsdialog – välj samma skrivare där.
        </p>
        <div class="falt">
            <label for="skrivare">Skrivare</label>
            <select id="skrivare" name="skrivare">
                <?php $valdSkrivare = \TDL\Support\Skrivare::aktiv(); ?>
                <?php foreach (\TDL\Support\Skrivare::PROFILER as $nyckel => $sp): ?>
                    <option value="<?= e($nyckel) ?>" <?= $nyckel === $valdSkrivare['nyckel'] ? 'selected' : '' ?>>
                        <?= e($sp['namn']) ?> – <?= e(\TDL\Support\Skrivare::tal((float) $sp['bredd'])) ?> mm tryckbredd
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="falt-hjalp">
                <strong><?= e($valdSkrivare['namn']) ?>:</strong>
                <?= e($valdSkrivare['notis']) ?>
                <br>Rullar som passar: <?= e($valdSkrivare['exempel']) ?>.
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="primar">Spara inställningar</button>
        </div>
    </form>
</div>

<div class="kort">
    <h2>Datafiler</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>Fil</th><th class="tal">Poster</th><th class="tal">Storlek</th><th>Rättigheter</th><th>Ändrad</th></tr>
        <?php foreach ($datafiler as $nyckel => $d): ?>
            <tr>
                <td class="mono"><?= e($nyckel) ?>.json</td>
                <td class="tal"><?= $d['poster'] === null ? '–' : (int) $d['poster'] ?></td>
                <td class="tal"><?= number_format($d['storlek'], 0, ',', ' ') ?> B</td>
                <td><?= e($d['rattigheter']) ?><?= $d['rattigheter'] === '0600' ? '' : ' ⚠' ?></td>
                <td class="dampad"><?= e($d['andrad']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p class="falt-hjalp">Rättigheter bör vara 0600. Avvikelse betyder att filen är läsbar för andra
        användare på servern.</p>
</div>

<div class="kort">
    <h2>Artikel-QR – adress och nyckel</h2>
    <p class="dampad">Artikel-QR-etiketterna öppnar en sida med bilder och snabb +/- av antal.
        QR-koden behöver appens publika adress – den kan inte gissas fram i en app som körs lokalt.</p>
    <?php if (empty($basUrlFast) && empty($basUrlPublik)): ?>
        <div class="flash varning">
            <strong>Adressen fungerar inte från en telefon.</strong>
            Appen nås just nu på <span class="mono"><?= e($basUrl) ?></span>, som bara fungerar
            på den här datorn. Etiketter som skrivs ut nu går inte att skanna med mobilen.
            Ange företagets riktiga webbadress nedan <em>innan</em> du skriver ut etiketter.
        </div>
    <?php elseif (empty($basUrlFast)): ?>
        <div class="flash info">
            <strong>Adressen räknas fram automatiskt.</strong>
            Just nu används <span class="mono"><?= e($basUrl) ?></span> och den fungerar från
            andra enheter. Spara den nedan när ni vet vilken adress appen ska ha permanent –
            annars slutar gamla etiketter att fungera om adressen ändras.
        </div>
    <?php else: ?>
        <div class="flash ok">
            <strong>Adressen är fastställd:</strong> <span class="mono"><?= e($basUrl) ?></span>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(u('/admin/system/adress')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="bas_url">Appens adress (lämna tom för att använda den aktuella)</label>
        <input id="bas_url" name="bas_url" value="<?= e($basUrl) ?>" placeholder="https://lager.foretag.se" class="mono">
        <div class="falt-hjalp">
            Den här adressen skrivs in i QR-koden på varje etikett. Ändrar du den måste
            etiketterna skrivas ut igen.
            <?php if (!empty($basUrlFast)): ?><strong>Adressen är fastställd.</strong><?php endif; ?>
        </div>
        <div class="actions"><button type="submit">Spara adress</button></div>
    </form>
    <p class="falt-hjalp">Bilder i lagringen: <?= (int) $bildStatistik['antal'] ?> st,
        <?= number_format($bildStatistik['bytes'] / 1048576, 1, ',', ' ') ?> MB.</p>
    <hr class="avdelare">
    <form method="post" action="<?= e(u('/admin/system/qr-nyckel')) ?>" data-bekrafta="Alla tidigare utskrivna artikel-QR slutar fungera. Fortsätt?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="bekrafta" value="ja" required style="width:auto;min-height:0">
            <span>Jag förstår att alla tidigare artikel-QR måste skrivas ut igen</span>
        </label>
        <div class="actions"><button type="submit" class="fara">Byt QR-nyckel</button></div>
    </form>

    <h2>Backup</h2>
    <p>
        <strong>Katalog:</strong> <span class="mono"><?= e($backupKatalog) ?></span><br>
        <strong>Ledigt diskutrymme:</strong> <?= $disk === false ? 'okänt' : number_format($disk / 1048576, 0, ',', ' ') . ' MB' ?><br>
        <strong>Öppna sessioner i lagringen:</strong> <?= (int) $sessioner ?>
    </p>
    <form method="post" action="<?= e(u('/admin/system/backup')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="primar">Skapa backup nu</button>
    </form>
    <?php if ($backuper !== []): ?>
        <div class="tabell-rull" style="margin-top:14px">
        <table>
            <tr><th>Fil</th><th>Tid</th><th class="tal">Storlek</th></tr>
            <?php foreach ($backuper as $b): ?>
                <tr>
                    <td class="mono"><?= e($b['namn']) ?></td>
                    <td class="dampad"><?= e($b['tid']) ?> UTC</td>
                    <td class="tal"><?= number_format($b['storlek'] / 1024, 0, ',', ' ') ?> kB</td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php else: ?>
        <p class="dampad" style="margin-top:12px">Inga backuper ännu. Skapa en innan appen tas i drift
            och schemalägg sedan <span class="mono">bin/backup.php</span> dagligen via cron.</p>
    <?php endif; ?>
</div>

<div class="kort">
    <h2>Serverförutsättningar</h2>
    <div class="tabell-rull">
    <table>
        <tr><th>Kontroll</th><th>Status</th></tr>
        <?php foreach ($kanns as $namn => $finns): ?>
            <tr>
                <td><?= e($namn) ?></td>
                <td><span class="tag <?= $finns ? 'ok' : 'fel' ?>"><?= $finns ? 'finns' : 'saknas' ?></span></td>
            </tr>
        <?php endforeach; ?>
        <tr><td>PHP-version</td><td><span class="mono"><?= e(PHP_VERSION) ?></span></td></tr>
        <tr>
            <td>Driftläge</td>
            <td>
                <?php if (\defined('TDL_MILJO') && \TDL_MILJO === 'utveckling'): ?>
                    <span class="tag varning">utveckling – förhandsgranskning</span>
                    <span class="falt-hjalp">Sessions-id skickas i adressen och ramen tillåts.
                        Används bara vid test; skarp drift rapporterar <em>produktion</em>.</span>
                <?php else: ?>
                    <span class="tag ok">produktion</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><td>session.gc_maxlifetime</td><td><?= e((string) ini_get('session.gc_maxlifetime')) ?> s</td></tr>
        <tr><td>Kontroll av privata filer (SÄK-01)</td><td>Verifieras i HTTPS-staging – se dokument/05_drift.md</td></tr>
    </table>
    </div>
</div>

<div class="kort">
    <h2>Underhållskommandon (SSH)</h2>
    <pre class="mono" style="background:#0c1520;border:1px solid var(--kant);padding:12px;border-radius:10px;overflow-x:auto">php bin/backup.php                      # backup + rotation
php bin/aterlas.php --fil=… --till-testkatalog
php bin/verifiera-historik.php          # ska säga "Kedjan är intakt"
php bin/aterhamta.php                   # om serverkrasch mitt i skrivning
php bin/migrera-aldre-lager.php --kalla=… --torrkorning</pre>
    <p class="falt-hjalp">Schemalägg backup dagligen med cron, t.ex.<br>
        <span class="mono">15 2 * * * /usr/bin/php <?= e(TDL_ROT) ?>/bin/backup.php</span></p>
</div>
