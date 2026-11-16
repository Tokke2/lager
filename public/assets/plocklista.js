/**
 * Plocklistan: lägg till och ta bort rader utan att ladda om sidan.
 *
 * Allt arbete görs i webbläsaren – servern får hela listan i en POST och
 * kontrollerar allt-eller-inget själv. Inget här är säkerhetskritiskt.
 */
(function () {
    'use strict';

    var tabell = document.getElementById('plockrader');
    var valjArtikel = document.querySelector('[data-plock-lagg-till]');
    var tomRad = document.getElementById('plocktom');
    var summa = document.getElementById('plocksumma');
    var knapp = document.querySelector('[data-plock-knapp]');
    if (!tabell || !valjArtikel) return;

    /** Nästa lediga radindex – namn[@n] måste vara unika i formuläret. */
    function nastaIndex() {
        var falt = tabell.querySelectorAll('input[name$="[artikel_id]"]');
        var storst = -1;
        for (var i = 0; i < falt.length; i++) {
            var m = falt[i].name.match(/rader\[(\d+)\]/);
            if (m) storst = Math.max(storst, parseInt(m[1], 10));
        }
        return storst + 1;
    }

    function raknaOm() {
        var rader = tabell.querySelectorAll('tr');
        var antal = rader.length;
        if (tomRad) tomRad.style.display = antal === 0 ? '' : 'none';
        if (summa) summa.textContent = antal + ' rad(er) i listan.';
        if (knapp) {
            if (antal === 0) knapp.setAttribute('disabled', 'disabled');
            else knapp.removeAttribute('disabled');
        }
    }

    /** Markera rader där antalet överstiger saldot (servern stoppar dem ändå). */
    function kontrolleraSaldo(input) {
        var saldo = parseInt(input.getAttribute('data-saldo'), 10);
        var antal = parseInt(input.value, 10);
        var rad = input.closest('tr');
        if (!rad) return;
        if (!isNaN(saldo) && !isNaN(antal) && antal > saldo) {
            rad.style.background = 'rgba(200,40,40,.10)';
            input.setAttribute('title', 'Saldot är ' + saldo + ' – hela listan avbryts om du bokför med fler.');
        } else {
            rad.style.background = '';
            input.removeAttribute('title');
        }
    }

    valjArtikel.addEventListener('change', function () {
        var opt = valjArtikel.options[valjArtikel.selectedIndex];
        if (!opt || !opt.value) return;

        var i = nastaIndex();
        var tr = document.createElement('tr');
        var hylla = (opt.getAttribute('data-hylla') || '').trim();
        var enhet = opt.getAttribute('data-enhet') || '';
        // Saldot hämtas ur datalistan om den finns (satt av vyn) – annars 0.
        var saldo = opt.getAttribute('data-saldo') || '0';

        tr.innerHTML =
            '<td class="mono dampad"></td>' +
            '<td><span class="plock-namn"></span>' +
            '<input type="hidden" name="rader[' + i + '][artikel_id]"></td>' +
            '<td class="mono dampad"></td>' +
            '<td class="tal"><input type="number" name="rader[' + i + '][antal]" min="1" step="1" value="1" required ' +
            'style="max-width:6rem;text-align:right" data-plock-antal></td>' +
            '<td class="tal dold-mobil" data-plock-saldo></td>' +
            '<td><button type="button" class="fara liten" data-plock-ta-bort>Ta bort</button></td>';

        tr.querySelector('.mono.dampad').textContent = hylla || '–';
        tr.querySelectorAll('td')[2].textContent = opt.getAttribute('data-nummer') || '';
        tr.querySelector('.plock-namn').textContent = opt.getAttribute('data-namn') || '';
        tr.querySelector('input[type="hidden"]').value = opt.value;
        tr.querySelector('td[data-plock-saldo]').textContent = saldo + (enhet ? ' ' + enhet : '');

        var antalFalt = tr.querySelector('[data-plock-antal]');
        antalFalt.setAttribute('data-saldo', saldo);

        tabell.appendChild(tr);
        valjArtikel.value = '';
        antalFalt.focus();
        antalFalt.select();
        raknaOm();
    });

    // Saldot hör till arbetsstället. När platsen byts måste både valslistan och
    // de rader som redan ligger i listan visa saldot för den nya platsen.
    var platsVal = document.getElementById('plats_id');
    if (platsVal) {
        platsVal.addEventListener('change', function () {
            var plats = platsVal.value;

            // Valslistans artiklar: data-saldo-json har saldo per plats-id
            var opts = valjArtikel.querySelectorAll('option[data-saldo-json]');
            for (var i = 0; i < opts.length; i++) {
                var saldon = {};
                try { saldon = JSON.parse(opts[i].getAttribute('data-saldo-json') || '{}'); } catch (e) { saldon = {}; }
                opts[i].setAttribute('data-saldo', String(saldon[plats] || 0));
            }

            // Rader som redan ligger i listan
            var inputs = tabell.querySelectorAll('[data-plock-antal]');
            for (var j = 0; j < inputs.length; j++) {
                var nyttSaldo = '0';
                for (var k = 0; k < opts.length; k++) {
                    if (opts[k].value === inputs[j].closest('tr').querySelector('input[type=hidden]').value) {
                        nyttSaldo = opts[k].getAttribute('data-saldo');
                        break;
                    }
                }
                inputs[j].setAttribute('data-saldo', nyttSaldo);
                var cell = inputs[j].closest('tr').querySelector('[data-plock-saldo]');
                if (cell) {
                    var enhet = cell.textContent.trim().split(' ').slice(1).join(' ');
                    cell.textContent = nyttSaldo + (enhet ? ' ' + enhet : '');
                }
                kontrolleraSaldo(inputs[j]);
            }
        });
    }

    tabell.addEventListener('click', function (e) {
        var knappBort = e.target.closest('[data-plock-ta-bort]');
        if (!knappBort) return;
        var rad = knappBort.closest('tr');
        if (rad) rad.parentNode.removeChild(rad);
        raknaOm();
    });

    tabell.addEventListener('input', function (e) {
        var falt = e.target.closest('[data-plock-antal]');
        if (falt) kontrolleraSaldo(falt);
    });

    raknaOm();
})();
