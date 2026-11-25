<?php
/** @var array $rader @var array $typer @var array $artiklar @var string $typ @var string $artikelFilter @var int $sida @var int $antalSidor */
$beskrivning = [
    'inleverans' => 'Inleverans',
    'hylla_felrapporterad' => 'Felplacerad vara rapporterad',
    'hylla_rapport_atgardad' => 'Hyllrapport åtgärdad',
    'utlan' => 'Utlåning',
    'utlan_overlamnad' => 'Utlåning överlämnad',
    'utlan_saknad' => 'Saknad utlåning rapporterad',
    'utlan_avskriven' => 'Saknad utlåning avskriven',
    'aterlamning' => 'Återlämning',
    'uttag' => 'Uttag',
    'overforing' => 'Överföring',
    'plocklista' => 'Plocklista (flera uttag)',
    'inventering_start' => 'Inventering startad',
    'inventering_avslut' => 'Inventering avslutad',
    'avstamning_uttag' => 'Avstämning',
    'etikett_skapad' => 'Etikett skapad',
    'enhet_aktiverad' => 'Enhet aktiverad',
    'verktyg_godkand' => 'Verktyg kontrollerat och godkänt',
    'enhet_utgang' => 'Enhet uttagen',
    'artikel_skapad' => 'Artikel skapad',
    'artikel_andrad' => 'Artikel ändrad',
    'artikel_inaktiverad' => 'Artikel inaktiverad',
    'plats_skapad' => 'Arbetsställe skapat',
    'plats_andrad' => 'Arbetsställe ändrat',
    'plats_stangd' => 'Arbetsställe stängt',
    'konto_skapad' => 'Konto skapat',
    'konto_andrad' => 'Konto ändrat',
    'konto_avstangd' => 'Konto avstängt',
    'losenord_andrat' => 'Inloggningsuppgift ändrad',
    'inloggning_nekad' => 'Inloggning nekad',
    'installation' => 'Installation',
    'migrering_import' => 'Migrering',
];
?>
<h1>Historik</h1>
<p class="dampad">Historiken är append-only: poster kan inte ändras eller raderas i appen.
    Varje post har en hash som binder den till föregående post.</p>

<form method="get" action="<?= e(u('/historik')) ?>" class="actions" style="margin-bottom:14px">
    <select name="typ" style="max-width:240px">
        <option value="">Alla händelser</option>
        <?php foreach ($typer as $t): ?>
            <option value="<?= e($t) ?>" <?= $typ === $t ? 'selected' : '' ?>><?= e($beskrivning[$t] ?? $t) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="artikel" style="max-width:280px">
        <option value="">Alla artiklar</option>
        <?php foreach ($artiklar as $a): ?>
            <option value="<?= e($a['id']) ?>" <?= $artikelFilter === $a['id'] ? 'selected' : '' ?>><?= e($a['namn']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filtrera</button>
</form>

<div class="kort">
    <div class="tabell-rull">
    <table>
        <tr>
            <th>#</th><th>Tid</th><th>Händelse</th><th>Artikel</th><th>Plats</th>
            <th class="tal">Antal</th><th>Av</th><th>Detaljer</th>
        </tr>
        <?php foreach ($rader as $h): ?>
            <tr>
                <td class="mono dampad"><?= e($h['seq'] ?? '') ?></td>
                <td class="dampad"><?= e(sv_tid($h['tid'] ?? null)) ?></td>
                <td><?= e($beskrivning[$h['typ'] ?? ''] ?? ($h['typ'] ?? '')) ?></td>
                <td>
                    <?php if (!empty($h['artikel_id'])): ?>
                        <a href="<?= e(u('/artiklar/' . e($h['artikel_id']))) ?>"><?= e($h['artikelnamn'] ?? $h['artikel_id']) ?></a>
                    <?php else: ?>–<?php endif; ?>
                </td>
                <td>
                    <?php
                    $platsText = $h['plats_namn'] ?? null;
                    if (($h['typ'] ?? '') === 'overforing') {
                        $platsText = ($h['fran_plats_namn'] ?? '?') . ' → ' . ($h['till_plats_namn'] ?? '?');
                    }
                    echo e($platsText ?? '–');
                    ?>
                </td>
                <td class="tal"><?= isset($h['antal']) ? (int) $h['antal'] : '–' ?></td>
                <td><?= e($h['ansvarig_namn'] ?? '–') ?></td>
                <td class="dampad" style="font-size:.8rem">
                    <?php if (!empty($h['enheter'])): ?>
                        <?= count($h['enheter']) ?> enhets-ID
                    <?php endif; ?>
                    <?php if (!empty($h['kommentar'])): ?>
                        <?= e(mb_substr((string) $h['kommentar'], 0, 60)) ?>
                    <?php endif; ?>
                    <?php if (($h['typ'] ?? '') === 'aterlamning' && !empty($h['detaljer']['utlan_id']) && !empty($h['detaljer']['kvitto_id'])): ?>
                        · <a href="<?= e(u('/utlan/' . rawurlencode($h['detaljer']['utlan_id']) . '/kvitto/' . rawurlencode($h['detaljer']['kvitto_id']))) ?>">Visa kvitto</a>
                    <?php endif; ?>
                    <?php if (!empty($h['session_id'])): ?>
                        · <a href="<?= e(u('/inventering/' . e($h['session_id']))) ?>"><?= e($h['session_id']) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($h['request_id'])): ?>
                        · <span class="mono" title="request-id"><?= e($h['request_id']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rader === []): ?>
            <tr><td colspan="8" class="dampad">Inga poster matchar filtret.</td></tr>
        <?php endif; ?>
    </table>
    </div>

    <?php if ($antalSidor > 1): ?>
    <div class="actions" style="justify-content:center">
        <?php if ($sida > 1): ?>
            <a class="knapp liten" href="<?= e(u('/historik?sida=' . ($sida - 1) . '&typ=' . (urlencode($typ)) . '&artikel=' . (urlencode($artikelFilter)))) ?>">← Föregående</a>
        <?php endif; ?>
        <span class="dampad">Sida <?= $sida ?> av <?= $antalSidor ?></span>
        <?php if ($sida < $antalSidor): ?>
            <a class="knapp liten" href="<?= e(u('/historik?sida=' . ($sida + 1) . '&typ=' . (urlencode($typ)) . '&artikel=' . (urlencode($artikelFilter)))) ?>">Nästa →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
