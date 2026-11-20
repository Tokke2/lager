<?php $csrf = \TDL\Support\Sakerhet::csrfToken(); ?>
<h1>Stöd Lager-appen</h1>
<p class="dampad">Appen är gratis och utan reklam. Din gåva hjälper till att hålla utvecklingen igång.</p>

<div class="kort">
    <h2>Välj belopp</h2>
    <div class="actions" style="flex-wrap:wrap;gap:8px;margin-top:8px">
        <button type="button" class="knapp stod-belopp" data-kr="25">25 kr</button>
        <button type="button" class="knapp primar stod-belopp" data-kr="50">50 kr</button>
        <button type="button" class="knapp stod-belopp" data-kr="100">100 kr</button>
        <button type="button" class="knapp stod-belopp" data-kr="200">200 kr</button>
        <input id="stod-eget" type="number" min="1" max="99999" placeholder="Eget belopp (kr)" style="max-width:160px">
    </div>
    <p id="stod-info" class="falt-hjalp" style="margin-top:8px">Valt belopp: 50 kr</p>

    <div class="actions" style="flex-wrap:wrap;gap:10px;margin-top:14px">
        <button type="button" class="knapp primar" id="stod-swish">Swish</button>
        <a class="knapp" id="stod-paypal" href="https://paypal.me/Rickard3dPrint/50SEK" target="_blank" rel="noopener">PayPal</a>
        <a class="knapp" href="https://ko-fi.com/tokke2" target="_blank" rel="noopener">Ko-fi</a>
    </div>

    <div id="stod-qrbox" style="display:none;margin-top:16px;text-align:center">
        <p><strong>Skanna med Swish-appen</strong></p>
        <img id="stod-qrimg" width="220" height="220" alt="Swish QR-kod" style="background:#fff;padding:8px;border-radius:12px">
    </div>
</div>

<script>
(function () {
    var valtKr = 50;
    function uppdatera() {
        document.getElementById('stod-info').textContent = 'Valt belopp: ' + valtKr + ' kr';
        document.getElementById('stod-paypal').href = 'https://paypal.me/Rickard3dPrint/' + valtKr + 'SEK';
    }
    document.querySelectorAll('.stod-belopp').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.stod-belopp').forEach(function (b) { b.classList.remove('primar'); });
            btn.classList.add('primar');
            document.getElementById('stod-eget').value = '';
            valtKr = parseInt(btn.getAttribute('data-kr'), 10);
            uppdatera();
        });
    });
    document.getElementById('stod-eget').addEventListener('input', function () {
        var v = parseInt(this.value, 10);
        if (v > 0 && v < 100000) {
            document.querySelectorAll('.stod-belopp').forEach(function (b) { b.classList.remove('primar'); });
            valtKr = v;
            uppdatera();
        }
    });
    document.getElementById('stod-swish').addEventListener('click', function () {
        var nr = '1234567890'; // Byt till ditt Swish-nummer vid behov
        if (/android|iphone|ipad/i.test(navigator.userAgent)) {
            var data = JSON.stringify({
                version: 1,
                payee: { value: nr, editable: false },
                amount: { value: valtKr, editable: true },
                message: { value: 'Lager', editable: true }
            });
            location.href = 'swish://payment?data=' + encodeURIComponent(data);
            return;
        }
        var payload = 'C' + nr + ';' + String(valtKr) + ';Lager;6';
        var mal = document.getElementById('stod-qr-lokal');
        if (window.qrcode) {
            var qr = window.qrcode(0, 'M');
            qr.addData(payload);
            qr.make();
            mal.innerHTML = qr.createSvgTag(6, 2);
        } else {
            mal.innerHTML = '<img width="220" height="220" alt="Swish QR-kod" src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(payload) + '">';
        }
        document.getElementById('stod-qrbox').style.display = 'block';
    });
})();
</script>
