/* Fiktiv inventering: ingen lagring, inga nätanrop. */
(function () {
    'use strict';
    var form = document.getElementById('ovning-form');
    if (!form) return;
    var status = document.getElementById('ovning-status');
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var avvikelser = 0;
        form.querySelectorAll('[data-ovning-forvantat]').forEach(function (rad) {
            var n = Number(rad.querySelector('input').value);
            var diff = n - Number(rad.getAttribute('data-ovning-forvantat'));
            rad.querySelector('[data-ovning-resultat]').textContent = (diff > 0 ? '+' : '') + diff;
            if (diff !== 0) avvikelser++;
        });
        status.textContent = avvikelser ? avvikelser + ' övningsrad(er) har en differens. Inget saldo har ändrats.' : 'Alla övningsrader stämmer. Inget saldo har ändrats.';
    });
    form.addEventListener('reset', function () {
        form.querySelectorAll('[data-ovning-resultat]').forEach(function (el) { el.textContent = '–'; });
        status.textContent = '';
    });
})();
