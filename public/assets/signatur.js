/**
 * Ritbar signatur på inventeringsutskriften.
 *
 * Ligger i en egen fil eftersom appens CSP är script-src 'self' – ett
 * inline-skript hade blockerats och då hade signaturen varit tom.
 * Fungerar med mus, penna och finger; canvasen töms med knappen.
 */
(function () {
    'use strict';

    var canvas = document.getElementById('signatur-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    var ritar = false;
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';

    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var t = e.touches ? e.touches[0] : e;
        return {
            x: (t.clientX - r.left) * (canvas.width / r.width),
            y: (t.clientY - r.top) * (canvas.height / r.height)
        };
    }

    function start(e) {
        ritar = true;
        var p = pos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function move(e) {
        if (!ritar) return;
        e.preventDefault();
        var p = pos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }

    function stop() { ritar = false; }

    function rensa() { ctx.clearRect(0, 0, canvas.width, canvas.height); }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', stop);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('touchend', stop);

    var knapp = document.querySelector('[data-rensa-signatur]');
    if (knapp) knapp.addEventListener('click', rensa);
    window.tdlRensaSignatur = rensa;
})();
