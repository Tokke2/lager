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
    var timer = null;
    var canvas = null;
    var ctx = null;

    function sattStatus(text) {
        if (status) status.textContent = text;
    }

    /**
     * En enda liten canvas återanvänds för alla avläsningar. Tidigare
     * skapades en ny canvas + ImageData i varje bildruta (60/s) – det gav
     * hög minnesanvändning och drog batteri i onödan. Nu skalas bilden ned
     * till max 640 px och läses av ~8 gånger i sekunden, vilket är mer än
     * nog för en QR-kod och minskar arbetet med över 90 %.
     */
    function litenCanvas() {
        if (!canvas) {
            canvas = document.createElement('canvas');
            ctx = canvas.getContext('2d', { willReadFrequently: true });
        }
        var max = 640;
        var skala = Math.min(1, max / Math.max(video.videoWidth || 1, video.videoHeight || 1));
        var b = Math.max(1, Math.round(video.videoWidth * skala));
        var h = Math.max(1, Math.round(video.videoHeight * skala));
        if (canvas.width !== b || canvas.height !== h) {
            canvas.width = b;
            canvas.height = h;
        }
        return canvas;
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
            schema();
        } catch (e) {
            sattStatus('Kunde inte starta kameran (' + (e && e.name ? e.name : 'fel') + '). Använd det manuella fältet.');
        }
    }

    function stoppa() {
        aktiv = false;
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        if (video) video.srcObject = null;
        if (knapp) knapp.textContent = 'Starta kamera';
        sattStatus('Kameran är avstängd.');
    }

    function schema() {
        if (!aktiv) return;
        timer = window.setTimeout(avlas, 120);
    }

    function avlas() {
        if (!aktiv) return;
        try {
            if (window.jsQR && video && video.readyState >= 2 && video.videoWidth) {
                var c = litenCanvas();
                ctx.drawImage(video, 0, 0, c.width, c.height);
                var bild = ctx.getImageData(0, 0, c.width, c.height);
                var kod = window.jsQR(bild.data, bild.width, bild.height, { inversionAttempts: 'dontInvert' });
                if (kod && kod.data) {
                    stoppa();
                    if (manuell) manuell.value = kod.data.trim();
                    if (opts.onKod) opts.onKod(kod.data.trim());
                    else skickaForm(manuell && manuell.form);
                    return;
                }
            }
        } catch (e) {
            /* Enstaka avläsningsfel ska inte stoppa kameran. */
        }
        schema();
    }

    if (knapp) {
        knapp.addEventListener('click', function () {
            if (aktiv) stoppa(); else starta();
        });
    }

    // Stoppa kameran när fliken döljs eller sidan lämnas – ingen ström
    // eller timer får ligga kvar i bakgrunden och dra batteri.
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

/* ---------------------------------------------------------------------------
 * Menyn på telefon/surfplatta.
 *
 * Sidomenyn ligger utanför bilden och öppnas med menyknappen (både i huvudet
 * och i bottenraden). Fokus flyttas in i menyn, Escape och klick på skuggan
 * stänger, och bakgrunden kan inte scrollas medan menyn är öppen. Allt sker
 * med klassbyten – inga nya element skapas, inget minne läcker.
 * ------------------------------------------------------------------------- */
(function () {
    function startaMeny() {
        var meny = document.getElementById('huvudmeny');
        if (!meny) return;
        var knappar = document.querySelectorAll('[data-meny-oppna]');
        var stangKnapp = document.querySelector('[data-meny-stang]');
        var skrim = document.querySelector('[data-meny-skrim]');
        var foreFokus = null;
        var oppen = false;

        function fokusbara() {
            return Array.prototype.filter.call(
                meny.querySelectorAll('a[href], button:not([disabled])'),
                function (el) { return el.offsetParent !== null; }
            );
        }

        function satt(v) {
            if (v === oppen) return;
            oppen = v;
            document.body.classList.toggle('meny-oppen', v);
            for (var i = 0; i < knappar.length; i++) {
                knappar[i].setAttribute('aria-expanded', v ? 'true' : 'false');
            }
            if (skrim) skrim.hidden = !v;
            if (v) {
                foreFokus = document.activeElement;
                var forsta = fokusbara()[0];
                if (forsta) forsta.focus({ preventScroll: true });
            } else if (foreFokus && typeof foreFokus.focus === 'function') {
                foreFokus.focus({ preventScroll: true });
                foreFokus = null;
            }
        }

        for (var i = 0; i < knappar.length; i++) {
            knappar[i].addEventListener('click', function () { satt(!oppen); });
        }
        if (stangKnapp) stangKnapp.addEventListener('click', function () { satt(false); });
        if (skrim) skrim.addEventListener('click', function () { satt(false); });

        // Stäng när en länk i menyn används – annars ligger menyn kvar
        // över nästa sida i webbläsare som återställer historiken.
        meny.addEventListener('click', function (e) {
            if (e.target.closest('a[href]')) satt(false);
        });

        document.addEventListener('keydown', function (e) {
            if (!oppen) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                satt(false);
                return;
            }
            if (e.key !== 'Tab') return;
            // Enkel fokusfälla: Tab ska stanna inom menyn.
            var element = fokusbara();
            if (!element.length) return;
            var forst = element[0];
            var sist = element[element.length - 1];
            if (e.shiftKey && document.activeElement === forst) {
                e.preventDefault();
                sist.focus();
            } else if (!e.shiftKey && document.activeElement === sist) {
                e.preventDefault();
                forst.focus();
            }
        });

        // Har man en gång valt bort menyn på en bred skärm ska den inte
        // ligga kvar som "öppen" när fönstret blir litet igen.
        var bredd = window.matchMedia('(min-width: 1024px)');
        var lyssnare = function (e) { if (e.matches) satt(false); };
        if (bredd.addEventListener) bredd.addEventListener('change', lyssnare);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startaMeny);
    } else {
        startaMeny();
    }
})();

/* ---------------------------------------------------------------------------
 * Små prestandavinster som märks i vardagen:
 *  - bilder under huvudet laddas först när de behövs (sparar data/minne)
 *  - bilder avkodas utanför huvudtråden (jämnare scroll)
 *  - långa listor får content-visibility så att webbläsaren hoppar över
 *    det som inte syns (mindre arbete per bildruta = lägre energi)
 * ------------------------------------------------------------------------- */
(function () {
    function finjustera() {
        var bilder = document.querySelectorAll('.innehall img:not([loading])');
        for (var i = 0; i < bilder.length; i++) {
            bilder[i].loading = 'lazy';
            bilder[i].decoding = 'async';
        }
        if (CSS.supports && CSS.supports('content-visibility', 'auto')) {
            var tunga = document.querySelectorAll('.historik-lista, .arkiv-månader, .foto-rutnat');
            for (var j = 0; j < tunga.length; j++) {
                tunga[j].style.contentVisibility = 'auto';
                tunga[j].style.containIntrinsicSize = 'auto 400px';
            }
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', finjustera);
    } else {
        finjustera();
    }
})();

/* ---------------------------------------------------------------------------
 * Gör om breda tabeller till kort på smala skärmar.
 *
 * Tabeller som redan är uppmärkta (artiklar, inventeringen) lämnas i fred.
 * Övriga får sin rubrikrad flyttad till <thead>, cellerna får data-etikett
 * och CSS ritar ett kort per rad. Det gör att knappar och summor aldrig
 * hamnar utanför skärmen – utan att varje vy behöver ändras för hand.
 * Ingen data flyttas eller kopieras; bara attribut sätts en gång.
 * ------------------------------------------------------------------------- */
(function () {
    function gorOmTabeller() {
        if (!window.matchMedia('(max-width: 620px)').matches) return;
        var tabeller = document.querySelectorAll('.innehall table');
        for (var t = 0; t < tabeller.length; t++) {
            var tabell = tabeller[t];
            if (tabell.classList.contains('kort-tabell')
                || tabell.classList.contains('mobil-kort')
                || tabell.classList.contains('inventerings-tabell')) {
                continue;
            }
            if (tabell.querySelector('[colspan], [rowspan]')) continue;

            var forsta = tabell.rows[0];
            if (!forsta) continue;
            var rubriker = forsta.querySelectorAll('th');
            if (!rubriker.length || rubriker.length < 2) continue;

            // Rubrikraden ska inte visas som ett tomt kort.
            if (forsta.parentNode.tagName !== 'THEAD') {
                var thead = document.createElement('thead');
                thead.appendChild(forsta);
                tabell.insertBefore(thead, tabell.firstChild);
            }
            tabell.classList.add('mobil-kort');

            // En tabell som börjar med ID/tid ska inte göra den siffran till
            // kortets rubrik – då ser en historikpost ut som en artikel.
            var forstaArSiffra = /^(id|nr|#|tid|datum|saldo)$/i.test(rubriker[0].textContent.trim());

            for (var r = 1; r < tabell.rows.length; r++) {
                var rad = tabell.rows[r];
                for (var c = 0; c < rad.cells.length; c++) {
                    var cell = rad.cells[c];
                    if (!cell.hasAttribute('data-etikett') && rubriker[c]) {
                        var text = rubriker[c].textContent.trim();
                        if (text) cell.setAttribute('data-etikett', text);
                    }
                    // Tomma värden ("–") tar bara plats i kortet.
                    if (!cell.children.length) {
                        var varde = cell.textContent.trim();
                        if (varde === '' || varde === '–' || varde === '-' || varde === '—') {
                            cell.classList.add('tom');
                        }
                    }
                }
                if (forstaArSiffra && rad.cells[0]) rad.cells[0].classList.add('forst-siffra');
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', gorOmTabeller);
    } else {
        gorOmTabeller();
    }
})();

/* ---------------------------------------------------------------------------
 * Kommandopalett (Ctrl+K / ⌘K).
 *
 * Listan byggs av menyn som redan finns i sidan – inget nytt API och inget
 * nätanrop. Skriv "uttag", "räkna" eller "system" och tryck Enter. Fungerar
 * även offline eftersom allt redan är hämtat. Utan <dialog>-stöd händer
 * ingenting; då finns menyn kvar som vanligt.
 * ------------------------------------------------------------------------- */
(function () {
    var dialog = document.getElementById('kommando');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    var falt = document.getElementById('kommando-falt');
    var lista = document.getElementById('kommando-lista');
    var stang = dialog.querySelector('[data-kommando-stang]');
    if (!falt || !lista) return;

    var poster = [];
    var synliga = [];
    var vald = 0;

    // Ord som inte står i menyn men som personalen säger.
    var synonymer = {
        '/inleverans': 'ta emot fylla på leverans ankomst',
        '/uttag': 'lämna ut plocka ta ut förbruka',
        '/plocklista': 'plocka flera lista order',
        '/overforing': 'flytta överföra mellan ställen',
        '/skanna': 'kamera qr streckkod läs av',
        '/inventering': 'räkna av inventera lagerkontroll',
        '/artiklar': 'vara sök artikelnummer saldo',
        '/miniminiva': 'brist beställa larm slut',
        '/lagerkarta': 'hylla karta zon fack',
        '/lokalplan': 'ritning lokal plan',
        '/historik': 'logg händelser spårbarhet',
        '/arkiv': 'gamla månader år',
        '/etiketter': 'skriv ut lapp qr',
        '/platsetikett': 'arbetsställe lapp ingång',
        '/konton': 'användare personal admin',
        '/platser': 'arbetsställe ställe',
        '/admin/varumarke': 'logotyp färg företag namn tema',
        '/admin/system': 'backup inställningar system',
        '/admin/halsa': 'hälsa kontroll fel status'
    };

    function normalisera(text) {
        return (text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function byggPoster() {
        var sedda = {};
        var lankar = document.querySelectorAll('.sido-innre a.meny-lank');
        for (var i = 0; i < lankar.length; i++) {
            var a = lankar[i];
            var href = a.getAttribute('href') || '';
            var namn = a.querySelector('.meny-text');
            var text = (namn ? namn.textContent : a.textContent).trim();
            if (!href || !text || sedda[href + text]) continue;
            sedda[href + text] = 1;
            var grupp = '';
            var omrade = a.closest('.sido-grupp');
            if (omrade) {
                var rubrik = omrade.querySelector('.sido-rubrik');
                if (rubrik) grupp = rubrik.textContent.trim();
            }
            var sok = synonymer[href.replace(/^.*?(?=\/[^/]*$)/, '')] || '';
            poster.push({ text: text, href: href, grupp: grupp, sok: sok });
        }
        // Genvägar som sparar ett klick men inte får plats i menyn.
        poster.push({ text: 'Ny artikel', href: (poster[0] ? poster[0].href.replace(/\/[^/]*$/, '') : '') + '/artiklar/ny', grupp: 'Genväg', sok: 'skapa lägg upp' });
        poster.push({ text: 'Nytt arbetsställe', href: (poster[0] ? poster[0].href.replace(/\/[^/]*$/, '') : '') + '/platser', grupp: 'Genväg', sok: 'skapa lägg upp' });
    }

    function rita() {
        lista.textContent = '';
        if (synliga.length === 0) {
            var tom = document.createElement('li');
            tom.className = 'kommando-tom';
            tom.textContent = 'Ingen träff. Prova ett annat ord.';
            lista.appendChild(tom);
            falt.removeAttribute('aria-activedescendant');
            return;
        }
        for (var i = 0; i < synliga.length; i++) {
            var p = synliga[i];
            var li = document.createElement('li');
            li.className = 'kommando-rad' + (i === vald ? ' vald' : '');
            li.id = 'kommando-rad-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', i === vald ? 'true' : 'false');
            li.dataset.href = p.href;

            var namn = document.createElement('span');
            namn.className = 'kommando-rad-namn';
            namn.textContent = p.text;
            li.appendChild(namn);

            if (p.grupp) {
                var grupp = document.createElement('span');
                grupp.className = 'kommando-rad-grupp';
                grupp.textContent = p.grupp;
                li.appendChild(grupp);
            }
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                ga(this.dataset.href);
            });
            lista.appendChild(li);
        }
        falt.setAttribute('aria-activedescendant', 'kommando-rad-' + vald);
    }

    function filtrera() {
        var q = normalisera(falt.value);
        var ord = q.split(' ').filter(Boolean);
        synliga = poster.filter(function (p) {
            var h = normalisera(p.text + ' ' + p.grupp + ' ' + p.sok);
            return ord.every(function (o) { return h.indexOf(o) !== -1; });
        }).slice(0, 8);
        vald = 0;
        rita();
    }

    function ga(href) {
        if (!href) return;
        if (dialog.open) dialog.close();
        window.location.href = href;
    }

    function oppna() {
        if (!poster.length) byggPoster();
        if (!dialog.open) dialog.showModal();
        falt.value = '';
        vald = 0;
        filtrera();
        window.setTimeout(function () { falt.focus(); }, 30);
    }

    var knappar = document.querySelectorAll('[data-kommando-oppna]');
    for (var k = 0; k < knappar.length; k++) {
        knappar[k].addEventListener('click', oppna);
    }
    if (stang) stang.addEventListener('click', function () { dialog.close(); });

    falt.addEventListener('input', filtrera);

    falt.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (synliga.length === 0) return;
            vald = (vald + (e.key === 'ArrowDown' ? 1 : -1) + synliga.length) % synliga.length;
            rita();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (synliga[vald]) ga(synliga[vald].href);
        }
    });

    // Klick utanför rutan stänger, som en vanlig dialog.
    dialog.addEventListener('click', function (e) {
        if (e.target === dialog) dialog.close();
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && normalisera(e.key) === 'k') {
            e.preventDefault();
            if (dialog.open) dialog.close(); else oppna();
        }
    });
})();
