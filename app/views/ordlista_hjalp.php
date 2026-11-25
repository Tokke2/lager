<?php
/** @var string $ordlisteFalt */
$lokaltOrd = \TDL\Support\Ordlista::hamta($ordlisteFalt);
if ($lokaltOrd !== null): ?>
    <div class="falt-hjalp"><strong><?= e($lokaltOrd['term']) ?>:</strong> <?= e($lokaltOrd['forklaring']) ?></div>
<?php endif; ?>
