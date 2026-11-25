<h1>Inventeringsövning</h1>
<p class="flash info"><strong>Övning – bara på den här sidan.</strong> Artiklarna och saldot nedan är påhittade. Inget skickas till servern, sparas i lagret eller läggs i historiken. När du lämnar sidan försvinner räkningen.</p>
<div class="kort">
    <h2>Räkna det fiktiva lagret</h2>
    <p>Fyll i vad du räknat och välj sedan Visa differens. Prova också att ändra ett antal.</p>
    <form id="ovning-form">
        <div class="tabell-rull"><table>
            <thead><tr><th>Artikel</th><th>Förväntat</th><th>Räknat</th><th>Differens</th></tr></thead>
            <tbody>
                <tr data-ovning-forvantat="12"><td>Övning: skruvar</td><td>12 st</td><td><label class="dolt" for="ovning-1">Räknade skruvar</label><input id="ovning-1" type="number" min="0" step="1" required></td><td data-ovning-resultat>–</td></tr>
                <tr data-ovning-forvantat="4"><td>Övning: skyddsglasögon</td><td>4 st</td><td><label class="dolt" for="ovning-2">Räknade skyddsglasögon</label><input id="ovning-2" type="number" min="0" step="1" required></td><td data-ovning-resultat>–</td></tr>
                <tr data-ovning-forvantat="2"><td>Övning: ficklampor</td><td>2 st</td><td><label class="dolt" for="ovning-3">Räknade ficklampor</label><input id="ovning-3" type="number" min="0" step="1" required></td><td data-ovning-resultat>–</td></tr>
            </tbody>
        </table></div>
        <div class="actions"><button type="submit" class="primar">Visa differens</button><button type="reset">Börja om</button></div>
        <p id="ovning-status" aria-live="polite"></p>
    </form>
    <noscript>Övningen kräver JavaScript. Den riktiga inventeringen fungerar separat.</noscript>
</div>
<script src="<?= e(tdl_tillgang('assets/inventering-ovning.js')) ?>" defer></script>
