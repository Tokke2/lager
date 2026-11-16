<h1>Lagerkarta</h1>
<div class="kort">
    <h2>Inga zoner ännu</h2>
    <p class="dampad">Kartan ritas upp från zoner och deras rader och fack. Skapa en zon först –
        då syns rutnätet här, med en ruta per hylla.</p>
    <?php if ($arAdmin): ?>
        <div class="actions"><a class="knapp primar" href="<?= e(u('/lagerplatser/ny')) ?>">Skapa en zon</a></div>
    <?php endif; ?>
</div>
