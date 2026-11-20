/**
 * Passar in arbetsplatsetiketten på rullens höjd.
 *
 * Textens höjd går inte att räkna ut exakt i PHP (typsnitt, radbrytningar och
 * webbläsarens avrundning spelar in). I stället mäts den verkliga höjden här
 * och QR-koden krymps med precis så mycket som behövs – aldrig under 12 mm.
 *
 * Utan det här steget kan etiketten spilla över 4–6 px på nästa etikett, och
 * då klipps QR:en så att den inte går att skanna.
 */
(function () {
    'use strict';

    function passaIn() {
        var box = document.querySelector('.platsetikett');
        var qr = box && box.querySelector('.qr');
        if (!box || !qr) return;

        var mm = 96 / 25.4;
        var qrMm = parseFloat(window.getComputedStyle(qr).width) / mm;
        var minMm = 12;

        // Krymp QR:en tills innehållet ryms (högst 30 steg om 0,4 mm)
        var steg = 0;
        while (box.scrollHeight > box.clientHeight + 1 && qrMm > minMm && steg < 30) {
            qrMm = Math.max(minMm, qrMm - 0.4);
            qr.style.width = qrMm + 'mm';
            qr.style.height = qrMm + 'mm';
            steg++;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', passaIn);
    } else {
        passaIn();
    }
    // Efter tryckning: QR-svg:n kan ändra mått när den ritas
    window.addEventListener('afterprint', passaIn);
})();

/* ---------------------------------------------------------------------------
 * Direktutskrift: /platsetikett?plats=…&skriv=1 öppnar utskriftsdialogen så
 * snart QR-koden är ritad. Samma vana som artikellapparna – man klickar
 * "Skriv ut" en gång, inte två.
 * ------------------------------------------------------------------------- */
(function () {
    if (!document.body || !document.body.hasAttribute('data-skriv-direkt')) return;
    var start = function () {
        window.setTimeout(function () { window.print(); }, 600);
    };
    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start);
    }
})();
