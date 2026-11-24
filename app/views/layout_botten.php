<?php
/**
 * Stänger innehållsramen och laddar klientlogiken.
 *
 * @var array|null $konto
 */
?>
</div><!-- /.innehall-inre -->
<footer class="sidfot">
    <span><?= e(\TDL\Support\Varumarke::v('namn')) ?></span>
    <span aria-hidden="true">·</span>
    <span><?= e(\TDL_VERSION) ?></span>
    <span aria-hidden="true">·</span>
    <span>alla ändringar sparas i historiken</span>
</footer>
</main>
</div><!-- /.app -->

<!-- All klientlogik: bekräftelser, autospar vid räkning, kamera, QR.
     Extern fil = CSP script-src 'self'. defer = HTML parsas färdigt först. -->
<script src="<?= e(tdl_tillgang('assets/app.js')) ?>" defer></script>
</body>
</html>
