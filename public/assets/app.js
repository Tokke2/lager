/* Tadega Lager – litet gränssnittslager. Ingen extern kod. */
'use strict';

/** Visa antal- eller enhetsfält beroende på vald artikels saldohanteringssätt. */
function TDLArtiklarValjare() {
    var artikel = document.getElementById('artikel_id');
    if (!artikel) return;
    var antalFalt = document.getElementById('antal-falt');
    var koderFalt = document.getElementById('koder-falt');
    function uppdatera() {
        var opt = artikel.options[artikel.selectedIndex];
        var spar = opt ? opt.getAttribute('data-spar') : null;
        if (spar === 'qr') {
            if (antalFalt) antalFalt.style.display = '';
            if (koderFalt) koderFalt.style.display = '';
        } else {
            if (antalFalt) antalFalt.style.display = '';
            if (koderFalt) koderFalt.style.display = 'none';
        }
    }
    artikel.addEventListener('change', uppdatera);
    uppdatera();
}

/**
 * Kamera-skanner. Kameran begärs FÖRST när användaren trycker på knappen.
 * Om kameran inte fungerar finns alltid det manuella fältet kvar.
 */
function TDLKamera(opts) {
    var video = document.getElementById(opts.videoId || 'kamera');
    var knapp = document.getElementById(opts.knappId || 'starta-kamera');
    var status = document.getElementById(opts.statusId || 'kamera-status');
    var manuell = document.getElementById(opts.manuellId || 'enhetskod');
    var stream = null;
    var aktiv = false;

    function sattStatus(text) {
        if (status) status.textContent = text;
    }

    async function starta() {
        if (!window.isSecureContext) {
            sattStatus('Kameran kräver HTTPS. Använd det manuella fältet i stället.');
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            sattStatus('Kameran stöds inte i den här webbläsaren. Använd det manuella fältet.');
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
                audio: false
            });
            video.srcObject = stream;
            video.setAttribute('playsinline', 'true');
            await video.play();
            aktiv = true;
            if (knapp) knapp.textContent = 'Stoppa kameran';
            sattStatus('Rikta kameran mot QR-koden.');
            loopa();
        } catch (e) {
            sattStatus('Kunde inte starta kameran (' + (e && e.name ? e.name : 'fel') + '). Använd det manuella fältet.');
        }
    }

    function stoppa() {
        aktiv = false;
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        if (video) video.srcObject = null;
        if (knapp) knapp.textContent = 'Starta kamera';
        sattStatus('Kameran är avstängd.');
    }

    function loopa() {
        if (!aktiv) return;
        if (window.jsQR && video && video.readyState === video.HAVE_ENOUGH_DATA) {
            var canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var bild = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var kod = window.jsQR(bild.data, bild.width, bild.height, { inversionAttempts: 'dontInvert' });
            if (kod && kod.data) {
                stoppa();
                if (manuell) manuell.value = kod.data.trim();
                if (opts.onKod) opts.onKod(kod.data.trim());
                else {
                    var form = manuell && manuell.form;
                    skickaForm(form);
                }
                return;
            }
        }
        requestAnimationFrame(loopa);
    }

    if (knapp) {
        knapp.addEventListener('click', function () {
            if (aktiv) stoppa(); else starta();
        });
    }

    // Stoppa kameran om användaren lämnar sidan
    window.addEventListener('pagehide', stoppa);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden && aktiv) stoppa();
    });
}

/* ---------------------------------------------------------------------------
 * PWA: registrera service workern så att appen kan installeras på mobilen
 * (Android/Chrome: "Lägg till på startskärmen", iOS: "Dela → Lägg till")
 * och startar snabbt även med dålig täckning. Data cachas aldrig.
 * ------------------------------------------------------------------------- */
if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', function () {
        // Basvägen: appen kan ligga i /lager – sw.js måste hämtas därifrån
        // och scopen måste vara samma katalog, annars cachar den fel.
        var bas = (document.body && document.body.getAttribute('data-bas')) || '';
        navigator.serviceWorker.register(bas + '/sw.js', { scope: bas + '/' }).catch(function () {
            /* Installation är en bonus – appen fungerar utan. */
        });
    });
}

/* Visa en diskret påminnelse på iOS om hur man lägger till appen */
(function () {
    var arIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var starFristaende = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    if (!arIos || starFristaende) return;
    if (localStorage.getItem('tdl_ios_tips') === 'nej') return;
    var tips = document.createElement('div');
    tips.className = 'flash info ios-tips';
    tips.innerHTML = '<strong>Lägg appen på hemskärmen:</strong> tryck på Dela-ikonen och välj '
        + '”Lägg till på hemskärmen”. <button type="button" class="liten" aria-label="Stäng">×</button>';
    tips.querySelector('button').addEventListener('click', function () {
        localStorage.setItem('tdl_ios_tips', 'nej');
        tips.remove();
    });
    var main = document.querySelector('.innehall');
    if (main) main.insertBefore(tips, main.firstChild);
})();

/* ---------------------------------------------------------------------------
 * Självstart – ersätter tidigare inline-anrop i vyerna.
 * CSP är script-src 'self', så <script>TDLArtiklarValjare()</script> blockerades tyst.
 * ------------------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function () {
    // Inloggning: växla mellan personal (PIN) och admin (lösenord + PIN)
    var flikar = document.querySelectorAll('[data-inloggning]');
    if (flikar.length) {
        flikar.forEach(function (flik) {
            flik.addEventListener('click', function () {
                flikar.forEach(function (f) {
                    f.classList.remove('vald');
                    f.setAttribute('aria-selected', 'false');
                });
                flik.classList.add('vald');
                flik.setAttribute('aria-selected', 'true');
                var arAdmin = flik.getAttribute('data-inloggning') === 'admin';
                var pf = document.getElementById('personal-form');
                var af = document.getElementById('admin-form');
                if (pf) pf.hidden = arAdmin;
                if (af) af.hidden = !arAdmin;
                // Namnfältet är ett textfält (lösenordshanterare behöver det),
                // inte en lista – fokusera första inmatningsfältet.
                var fokus = document.querySelector((arAdmin ? '#admin-form' : '#personal-form') + ' input:not([type=hidden])');
                if (fokus) fokus.focus();
            });
        });
    }

    // Utskriftssidor: ?skriv=1 startar utskriftsdialogen utan inline-skript
    if (new URLSearchParams(window.location.search).get('skriv') === '1') {
        window.print();
    }

    // Lagerplats i artikelformuläret: visa platsen (A-3-2) medan man fyller i,
    // och berätta hur många rader/fack zonen har.
    var platsZon = document.querySelector('[data-plats-zon]');
    if (platsZon) {
        var radFalt = document.querySelector('[data-plats-rad]');
        var fackFalt = document.querySelector('[data-plats-fack]');
        var platsText = document.querySelector('[data-plats-text]');
        var synka = function () {
            var val = platsZon.options[platsZon.selectedIndex];
            var rader = parseInt((val && val.getAttribute('data-rader')) || '0', 10);
            var fack = parseInt((val && val.getAttribute('data-fack')) || '0', 10);
            var kod = val ? (val.textContent.split('–')[0] || '').trim() : '';
            if (radFalt && rader > 0) {
                radFalt.max = rader;
                var h = document.querySelector('[data-plats-rad-hjalp]');
                if (h) h.textContent = '1–' + rader;
            }
            if (fackFalt && fack > 0) {
                fackFalt.max = fack;
                var h2 = document.querySelector('[data-plats-fack-hjalp]');
                if (h2) h2.textContent = '1–' + fack;
            }
            if (platsText) {
                var r = radFalt && radFalt.value ? radFalt.value : '–';
                var f = fackFalt && fackFalt.value ? fackFalt.value : '–';
                platsText.textContent = kod ? (kod + '-' + r + '-' + f) : '—';
            }
        };
        [platsZon, radFalt, fackFalt].forEach(function (e) {
            if (e) { e.addEventListener('change', synka); e.addEventListener('input', synka); }
        });
        synka();
    }

    // Lokalplan: dra ut zonerna över lokalens bild.
    // Rutorna sparas i procent av bildens storlek, så de hamnar rätt på alla skärmar.
    var planYta = document.querySelector('[data-plan-yta]');
    if (planYta) {
        var planForm = document.querySelector('[data-lokalplan]');
        var planFalt = document.getElementById('plan-rutor');
        var rutor = Array.prototype.slice.call(planYta.querySelectorAll('.plan-zon'));

        var samla = function () {
            var vy = planYta.getBoundingClientRect();
            var data = rutor.map(function (r) {
                return {
                    zon_id: r.getAttribute('data-zon'),
                    x: parseFloat(r.style.left) || 0,
                    y: parseFloat(r.style.top) || 0,
                    b: parseFloat(r.style.width) || 10,
                    h: parseFloat(r.style.height) || 10
                };
            });
            if (planFalt) { planFalt.value = JSON.stringify(data); }
            return vy;
        };

        rutor.forEach(function (ruta) {
            var lage = null;

            ruta.addEventListener('pointerdown', function (e) {
                var handtag = e.target.closest('[data-handtag]');
                var vy = planYta.getBoundingClientRect();
                lage = {
                    lage: handtag ? 'storlek' : 'flytta',
                    startX: e.clientX,
                    startY: e.clientY,
                    x: parseFloat(ruta.style.left) || 0,
                    y: parseFloat(ruta.style.top) || 0,
                    b: parseFloat(ruta.style.width) || 10,
                    h: parseFloat(ruta.style.height) || 10,
                    vyBredd: vy.width,
                    vyHojd: vy.height
                };
                ruta.setPointerCapture(e.pointerId);
                e.preventDefault();
            });

            ruta.addEventListener('pointermove', function (e) {
                if (!lage) { return; }
                var dx = ((e.clientX - lage.startX) / lage.vyBredd) * 100;
                var dy = ((e.clientY - lage.startY) / lage.vyHojd) * 100;
                if (lage.lage === 'flytta') {
                    ruta.style.left = Math.max(0, Math.min(100 - lage.b, lage.x + dx)) + '%';
                    ruta.style.top = Math.max(0, Math.min(100 - lage.h, lage.y + dy)) + '%';
                } else {
                    ruta.style.width = Math.max(3, Math.min(100 - lage.x, lage.b + dx)) + '%';
                    ruta.style.height = Math.max(3, Math.min(100 - lage.y, lage.h + dy)) + '%';
                }
                samla();
            });

            var slapp = function () {
                if (lage) {
                    lage = null;
                    samla();
                    // Fyll i formuläret så att tangentbordets Enter också sparar rätt
                    if (planFalt) { planFalt.value = planFalt.value; }
                }
            };
            ruta.addEventListener('pointerup', slapp);
            ruta.addEventListener('pointercancel', slapp);
            ruta.addEventListener('lostpointercapture', slapp);
        });

        if (planForm) {
            planForm.addEventListener('submit', function () { samla(); });
        }
        samla();
    }

    // Lagerkarta: växla mellan 2D och 3D
    var kartaKnappar = document.querySelectorAll('[data-karta-vy]');
    kartaKnappar.forEach(function (knapp) {
        knapp.addEventListener('click', function () {
            var vy = knapp.getAttribute('data-karta-vy');
            kartaKnappar.forEach(function (k) { k.classList.toggle('vald', k === knapp); });
            var tva = document.getElementById('karta-2d');
            var tre = document.getElementById('karta-3d');
            if (tva) tva.hidden = vy !== '2d';
            if (tre) tre.hidden = vy !== '3d';
        });
    });

    // Verktygstavla: visa delfälten när kryssrutan är ikryssad.
    // (Inline onchange blockeras av CSP script-src 'self'.)
    var tavlaVaxla = document.querySelectorAll('[data-tavla-vaxla]');
    tavlaVaxla.forEach(function (ruta) {
        var mal = document.getElementById(ruta.getAttribute('data-tavla-vaxla'));
        if (!mal) return;
        var synka = function () { mal.hidden = !ruta.checked; };
        ruta.addEventListener('change', synka);
        synka();
    });

    // Inleverans, uttag, överföring: visa rätt fält för vald artikel
    if (document.getElementById('artikel_id')) {
        TDLArtiklarValjare();
    }

    // Skanna-sidan: kamera startas först vid knapptryck (integritet + prestanda)
    var kameraKnapp = document.getElementById('starta-kamera');
    var kameraVideo = document.getElementById('kamera');
    if (kameraKnapp && kameraVideo && typeof TDLKamera === 'function') {
        kameraKnapp.addEventListener('click', function () {
            TDLKamera({
                onKod: function (kod) {
                    // Basvägen: appen kan ligga i /lager – då duger inte '/skanna'
                    var bas = (document.body && document.body.getAttribute('data-bas')) || '';
                    window.location.href = bas + '/skanna?kod=' + encodeURIComponent(kod);
                }
            });
        });
    }

    // Skanna-sidan: fyll i fältet direkt om koden kom via ?kod=
    var kodFalt = document.getElementById('enhetskod');
    if (kodFalt && !kodFalt.value) {
        var kod = new URLSearchParams(window.location.search).get('kod');
        if (kod) kodFalt.value = kod;
    }
});

/* ---------------------------------------------------------------------------
 * Direktutskrift: <body data-skriv-direkt> öppnar utskriftsdialogen så snart
 * QR-koderna är ritade. Används när man klickar "Etikett" i artikellistan och
 * inte vill trycka en gång till. Liten fördröjning så att SVG:erna hinner bli
 * klara – annars kan tomma rutor hamna i utskriften.
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

/* ---------------------------------------------------------------------------
 * CSP-vänliga ersättare för onclick/onsubmit/onchange.
 * Inline-attribut blockeras när Content-Security-Policy är script-src 'self',
 * så i stället används data-attribut:
 *
 *   <button data-skriv-ut>Skriv ut</button>
 *   <form data-bekrafta="Är du säker?">…</form>
 *   <select data-skicka-vid-andring>…</select>
 *   <form data-action-mall="/artiklar/{id}/etiketter" data-action-kalla="artikel">…</form>
 * ------------------------------------------------------------------------- */
document.addEventListener('click', function (e) {
    var knapp = e.target.closest('[data-skriv-ut]');
    if (knapp) {
        e.preventDefault();
        window.print();
        return;
    }

    // +/− i inventeringslistan: räkna i bakgrunden, ladda aldrig om sidan
    var steg = e.target.closest('form[data-autoskicka] button[name="steg"]');
    if (steg && steg.closest('form')) {
        e.preventDefault();
        var f = steg.closest('form');
        var faltSteg = f.querySelector('input[name="steg"]');
        if (!faltSteg) {
            faltSteg = document.createElement('input');
            faltSteg.type = 'hidden';
            faltSteg.name = 'steg';
            f.appendChild(faltSteg);
        }
        faltSteg.value = steg.value;
        // Skicka med nuvarande antal så servern inte behöver gissa
        var antalFalt = f.querySelector('input[name="antal"]');
        skickaRakning(f, antalFalt, true);
    }
});

document.addEventListener('change', function (e) {
    var val = e.target.closest('[data-skicka-vid-andring]');
    if (val && val.form) skickaForm(val.form);

    // Räknefält i inventeringen: spara direkt utan sidladdning
    var form = e.target.form;
    if (form && form.hasAttribute('data-autoskicka') && e.target.name === 'antal') {
        skickaRakning(form, e.target);
    }
});

/**
 * Skickar en räkning i bakgrunden och uppdaterar raden utan att ladda om sidan.
 * Faller tillbaka på vanlig inskickning om fetch saknas eller misslyckas.
 */
function skickaRakning(form, falt, arSteg) {
    if (!window.fetch || !window.FormData) { skickaForm(form); return; }

    var data = new FormData(form);
    data.delete('steg');                      // steg skickas bara vid knapptryck
    if (arSteg === true) {
        var stegFalt = form.querySelector('input[name="steg"]');
        if (stegFalt) data.set('steg', stegFalt.value);
    }
    var knappar = form.querySelectorAll('button');
    for (var i = 0; i < knappar.length; i++) knappar[i].disabled = true;

    fetch(form.action, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Begar': 'fetch' }
    }).then(function (svar) {
        if (!svar.ok) throw new Error('svar ' + svar.status);
        var stegVarde = arSteg === true ? parseInt(data.get('steg') || '0', 10) || 0 : 0;
        var antalVarde = falt ? parseInt(falt.value, 10) : NaN;
        return svaraKvittens(form, falt, antalVarde, stegVarde);
    }).catch(function () {
        skickaForm(form);
    }).then(function () {
        for (var i = 0; i < knappar.length; i++) knappar[i].disabled = false;
    });
}

/**
 * Markera raden som sparad och räkna om differensen lokalt.
 * antal = värdet i fältet, steg = +1/-1 när man tryckt på knapparna.
 */
function svaraKvittens(form, falt, antal, steg) {
    var rad = form.closest('tr');
    if (!rad || !falt) return;
    if (isNaN(antal) || antal < 0) return;
    antal = Math.max(0, antal + (steg || 0));
    falt.value = String(antal);

    // Förväntat står i andra kolumnen, differensen i fjärde
    var celler = rad.querySelectorAll('td');
    var forvantat = parseInt((celler[1] || {}).textContent || '', 10);
    if (celler[3]) {
        if (!isNaN(forvantat)) {
            var diff = antal - forvantat;
            if (diff === 0) {
                celler[3].innerHTML = '<span class="tag ok">✓</span>';
                rad.className = 'rad-ok';
            } else {
                celler[3].innerHTML = '<span class="tag ' + (diff < 0 ? 'fel' : 'varning') + '">'
                    + (diff > 0 ? '+' + diff : diff) + '</span>';
                rad.className = diff < 0 ? 'rad-fattas' : 'rad-overskott';
            }
        }
    }
    rad.setAttribute('data-raknad', '1');
    rad.setAttribute('data-avvikelse', antal === forvantat ? '0' : '1');
    rad.classList.add('sparad');
    setTimeout(function () { rad.classList.remove('sparad'); }, 900);

    ritaFramsteg();
}

/** Räkna om framstegslisten och texten ovanför listan. */
function ritaFramsteg() {
    var text = document.getElementById('framsteg-text');
    var rader = document.querySelectorAll('.inventerings-tabell tbody tr[data-raknad]');
    if (!text || !rader.length) return;

    var total = parseInt(text.getAttribute('data-total'), 10) || rader.length;
    var raknade = 0;
    var avvikelser = 0;
    for (var i = 0; i < rader.length; i++) {
        if (rader[i].getAttribute('data-raknad') === '1') {
            raknade++;
            if (rader[i].getAttribute('data-avvikelse') === '1') avvikelser++;
        }
    }

    var procent = total > 0 ? Math.round(raknade / total * 100) : 0;
    var kvar = total - raknade;

    var eRaknat = document.getElementById('framsteg-raknat');
    var eStapel = document.getElementById('framsteg-stapel');
    var eUnder = document.getElementById('framsteg-undertext');
    var eAvvik = document.getElementById('framsteg-avvikelse');
    var eAvvikTal = document.getElementById('framsteg-avvikelse-tal');
    var eAvvikOrd = document.getElementById('framsteg-avvikelse-ord');

    if (eRaknat) eRaknat.textContent = String(raknade);
    if (eStapel) eStapel.style.width = procent + '%';
    if (eUnder) {
        eUnder.textContent = procent + ' % klart' + (kvar > 0
            ? ' · ' + kvar + ' kvar att räkna'
            : ' · allt räknat');
    }
    if (eAvvik) {
        eAvvik.hidden = avvikelser === 0;
        if (eAvvikTal) eAvvikTal.textContent = String(avvikelser);
        if (eAvvikOrd) eAvvikOrd.textContent = avvikelser === 1 ? 'avvikelse' : 'avvikelser';
    }

    // Antal oräknade högst upp i avslutsrutan
    var kvarVarning = document.getElementById('kvar-varning');
    var kvarTal = document.getElementById('kvar-tal');
    var kvarOrd = document.getElementById('kvar-ord');
    if (kvarVarning) {
        kvarVarning.hidden = kvar === 0;
        if (kvarTal) kvarTal.textContent = String(kvar);
        if (kvarOrd) kvarOrd.textContent = kvar === 1 ? 'artikel är inte räknad' : 'artiklar är inte räknade';
    }

    var tomText = document.getElementById('avvikelse-tom');
    if (tomText) {
        tomText.textContent = raknade === 0
            ? 'Inget är räknat ännu.'
            : 'Inga avvikelser att stämma av – förväntat och räknat stämmer.';
    }

    // Avvikelsetabellen och avstämningen ritas av servern – be användaren ladda om
    var varning = document.getElementById('avvikelse-varning');
    if (!varning) {
        varning = document.createElement('div');
        varning.id = 'avvikelse-varning';
        varning.className = 'flash info';
        varning.setAttribute('role', 'status');
        varning.style.marginTop = '12px';
        var undertext = document.getElementById('framsteg-undertext');
        if (undertext && undertext.parentNode) {
            undertext.parentNode.appendChild(varning);
        }
    }
    varning.innerHTML = 'Räkningen är sparad. <a href="">Ladda om sidan</a> för att se '
        + 'avvikelserna och kunna stämma av.';
}

/**
 * Skickar ett formulär som ett riktigt klick hade gjort.
 *
 * form.submit() utlöser INTE submit-händelsen – då hade varken sessions-id:t
 * (förhandsgranskningsläget) eller data-bekrafta följt med. requestSubmit()
 * finns i alla webbläsare appen stöder; submit() är reserv för äldre.
 */
function skickaForm(form) {
    if (!form) return;
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
}

/**
 * Lägger sessions-id:t i en adress utan att förstöra frågesträngen.
 *
 * Avgränsaren (? eller &) måste avgöras på den RENSADE adressen: en adress som
 * redan innehöll ?tdlagersid=… blev annars "/inleverans&tdlagersid=…" – en
 * ogiltig adress som gav 404.
 */
function medSessionsId(adress, sidFraga) {
    var ren = String(adress).replace(/[?&]tdlagersid=[^&]*/g, '');
    ren = ren.replace(/[?&]$/, '');
    if (!sidFraga) return ren;
    return ren + (ren.indexOf('?') === -1 ? '?' : '&') + sidFraga;
}

document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    var fraga = form.getAttribute('data-bekrafta');
    if (fraga && !window.confirm(fraga)) {
        e.preventDefault();
        return;
    }

    var mall = form.getAttribute('data-action-mall');
    var kalla = form.getAttribute('data-action-kalla');
    if (mall && kalla) {
        var valjare = form.querySelector('[name="' + kalla + '"]');
        var varde = valjare ? valjare.value : '';
        if (!varde) {
            e.preventDefault();
            return;
        }
        // Endast tecken som förekommer i artikel-id och platskoder
        form.action = mall.replace('{id}', encodeURIComponent(varde.replace(/[^A-Za-z0-9]/g, '')));
    }

    // Förhandsgranskningsläge: sessions-id:t måste följa med i adressen.
    // Detta ligger SIST – en data-action-mall kan ha skrivit om form.action
    // ovan, och då hade sessions-id:t annars försvunnit (inloggningen tappades).
    // Servern flaggar sidan med <body data-sid-fraga="tdlagersid=…">.
    var sidFraga = document.body.getAttribute('data-sid-fraga');
    if (sidFraga && (form.method || '').toLowerCase() === 'post') {
        form.action = medSessionsId(form.action, sidFraga);
    }
});
