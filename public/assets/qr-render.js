/**
 * Renderar QR-koder i element med data-attribut.
 *
 *   <div class="qr" data-kod="TDL-U-XXXXX-YYYYY-Z"></div>
 *   <div class="qr" data-url="https://..."></div>
 *
 * Kräver qrcode.js (laddas före). Ingen inline-JS behövs, vilket gör att
 * appens CSP kan fortsätta vara strikt (script-src 'self').
 */
(function () {
    'use strict';

    function rita(element, text, storlek, cell, niva) {
        if (!text || typeof window.qrcode !== 'function') return;
        try {
            // Felkorrigering: 'L' ger färre moduler (= grövre rutor) för små etiketter
            var q = window.qrcode(0, niva || 'M');
            q.addData(text);
            q.make();
            element.innerHTML = q.createSvgTag({ cellSize: cell || 3, margin: 0, scalable: true });
            var svg = element.querySelector('svg');
            if (svg) {
                if (storlek) {
                    svg.setAttribute('width', String(storlek));
                    svg.setAttribute('height', String(storlek));
                }
                svg.setAttribute('role', 'img');
                svg.setAttribute('aria-label', 'QR-kod');
            }
        } catch (e) {
            element.textContent = 'QR-fel';
        }
    }

    function ritaAlla(rot) {
        (rot || document).querySelectorAll('.qr[data-kod], .qr[data-url]').forEach(function (el) {
            if (el.getAttribute('data-ritad') === '1') return;
            var text = el.getAttribute('data-kod') || el.getAttribute('data-url') || '';
            var storlek = parseInt(el.getAttribute('data-storlek') || '0', 10) || 0;
            var cell = parseInt(el.getAttribute('data-cell') || '3', 10) || 3;
            var niva = (el.getAttribute('data-fel') || 'M').toUpperCase();
            rita(el, text, storlek, cell, niva);
            el.setAttribute('data-ritad', '1');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { ritaAlla(); });
    } else {
        ritaAlla();
    }

    // På utskriftssidor: skriv ut automatiskt när alla koder är ritade
    if (document.body && document.body.hasAttribute('data-skriv-ut')) {
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 500);
        });
    }

    window.TDLQr = { rita: rita, ritaAlla: ritaAlla };
})();
