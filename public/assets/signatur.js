/** Ritbar signatur med högupplöst, storleksanpassad canvas. */
(function () {
    'use strict';
    var canvas = document.getElementById('signatur-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    if (!ctx) return;
    var ritar = false;
    var innehall = false;

    // Begränsa minnesåtgången på mycket högupplösta enheter. Bilden kopieras
    // innan canvasens backing store ändras: en rotation raderar inte signaturen.
    function anpassa() {
        var r = canvas.getBoundingClientRect();
        if (!r.width || !r.height || ritar) return;
        var ratio = Math.min(3, Math.max(1, window.devicePixelRatio || 1));
        var bredd = Math.max(1, Math.round(r.width * ratio));
        var hojd = Math.max(1, Math.round(r.height * ratio));
        if (canvas.width === bredd && canvas.height === hojd) return;
        var kopia = null;
        if (innehall) {
            kopia = document.createElement('canvas');
            kopia.width = canvas.width; kopia.height = canvas.height;
            var kopiaCtx = kopia.getContext('2d');
            if (!kopiaCtx) return;
            kopiaCtx.drawImage(canvas, 0, 0);
        }
        canvas.width = bredd; canvas.height = hojd;
        if (kopia) ctx.drawImage(kopia, 0, 0, bredd, hojd);
        ctx.setTransform(bredd / r.width, 0, 0, hojd / r.height, 0, 0);
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
    }
    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var t = e.touches ? e.touches[0] : e;
        return { x: t.clientX - r.left, y: t.clientY - r.top };
    }
    function start(e) {
        anpassa();
        ritar = true;
        var p = pos(e);
        ctx.beginPath(); ctx.moveTo(p.x, p.y);
    }
    function move(e) {
        if (!ritar) return;
        e.preventDefault();
        var p = pos(e);
        ctx.lineTo(p.x, p.y); ctx.stroke(); innehall = true;
    }
    function stop() { if (ritar) { ritar = false; anpassa(); } }
    function rensa() {
        ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.restore();
        innehall = false;
    }
    anpassa();
    if (typeof ResizeObserver === 'function') new ResizeObserver(anpassa).observe(canvas);
    window.addEventListener('resize', anpassa, { passive: true });
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', stop);
    canvas.addEventListener('touchstart', start, { passive: true });
    canvas.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('touchend', stop);
    window.addEventListener('touchcancel', stop);
    var knapp = document.querySelector('[data-rensa-signatur]');
    if (knapp) knapp.addEventListener('click', rensa);
    window.tdlRensaSignatur = rensa;
})();
