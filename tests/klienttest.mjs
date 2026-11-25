/** Kör med: node tests/klienttest.mjs (inga externa paket). */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const las = fil => fs.readFileSync(path.join(root, 'public/assets', fil), 'utf8');
const emitter = () => ({ events: {}, addEventListener(n, fn) { this.events[n] = fn; } });

// Signaturen ska bevara innehållet vid rotation och använda begränsad DPI.
{
    const win = { ...emitter(), devicePixelRatio: 2 };
    let bredd = 300, kopior = 0, rensningar = 0, streck = 0, observer;
    const ctx = () => ({ setTransform() {}, drawImage() { kopior++; }, beginPath() {}, moveTo() {}, lineTo() {},
        stroke() { streck++; }, save() {}, restore() {}, clearRect() { rensningar++; } });
    const canvas = { ...emitter(), width: 420, height: 120, getContext: () => ctx(),
        getBoundingClientRect: () => ({left: 0, top: 0, width: bredd, height: 120}) };
    const knapp = emitter();
    const document = { getElementById: () => canvas, querySelector: () => knapp,
        createElement: () => ({width: 0, height: 0, getContext: () => ctx()}) };
    const ResizeObserver = class { constructor(fn) { observer = fn; } observe() {} };
    vm.runInNewContext(las('signatur.js'), { window: win, document, ResizeObserver });
    assert.equal(canvas.width, 600); assert.equal(canvas.height, 240);
    canvas.events.mousedown({clientX: 10, clientY: 10});
    canvas.events.mousemove({clientX: 30, clientY: 30, preventDefault() {}});
    win.events.mouseup(); assert.equal(streck, 1);
    bredd = 200; observer(); assert.equal(canvas.width, 400); assert.equal(kopior, 2);
    knapp.events.click(); assert.equal(rensningar, 1);
    win.devicePixelRatio = 8; observer(); assert.equal(canvas.width, 600); // tak: 3×
    console.log('OK: signatur-DPI, ändrad storlek och rensning');
}

// Databesparing och låg batterinivå är oberoende orsaker till energisparläge.
{
    const body = { active: false, classList: { toggle(_, value) { body.active = value; } } };
    const network = { ...emitter(), saveData: true };
    const battery = { ...emitter(), level: 1, charging: true };
    const navigator = { connection: network, getBattery: () => Promise.resolve(battery) };
    const script = las('app.js');
    const section = script.slice(script.indexOf('(function () {\n    var kropp = document.body;', script.indexOf(' * Energisparläge.')));
    vm.runInNewContext(section, {document: {body}, navigator});
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(body.active, 'datasparläge gäller även vid laddning');
    network.saveData = false; network.events.change(); assert(!body.active);
    battery.level = 0.1; battery.charging = false; battery.events.levelchange(); assert(body.active);
    battery.charging = true; battery.events.chargingchange(); assert(!body.active);
    console.log('OK: energisparläge för nätverk och batteri');
}

// Materialberäkningen är fristående och rundar bara för information.
{
    const f1 = { ...emitter(), value: '' }, f2 = { ...emitter(), value: '' };
    const result = { textContent: '' };
    const ruta = { querySelector(s) { return ({ '[data-atgang-arbete]': f1, '[data-atgang-per]': f2, '[data-atgang-resultat]': result })[s]; } };
    const js = las('app.js');
    const section = js.slice(js.indexOf('function TDLAtgang('), js.indexOf('/** Visa antal-'));
    const context = { Number, Math }; vm.runInNewContext(section, context); context.TDLAtgang(ruta);
    f1.value = '3'; f2.value = '1.5'; f1.events.input();
    assert.match(result.textContent, /4,5/); assert.match(result.textContent, /hela enheter: 5/);
    f1.value = '0.0000001'; f2.value = '1'; f1.events.input(); assert.match(result.textContent, /hela enheter: 1/);
    f2.value = '-1'; f2.events.input(); assert.match(result.textContent, /giltiga värden/);
    f2.value = ''; f2.events.input(); assert.match(result.textContent, /Ange båda/);
    console.log('OK: isolerad materialberäkning och indata');
}

// Ingen automatisk återstart när fliken döljs eller kameraströmmen avbryts.
{
    let starter = 0, stoppar = 0;
    const track = { ...emitter(), stop() { stoppar++; }, getCapabilities() { return {}; } };
    const stream = { getTracks: () => [track], getVideoTracks: () => [track] };
    const knapp = { ...emitter(), textContent: '', disabled: false };
    const status = { textContent: '' };
    const video = { srcObject: null, setAttribute() {}, play: async () => {} };
    const doc = { ...emitter(), hidden: false, getElementById(id) { return ({kamera: video, 'starta-kamera': knapp, 'kamera-status': status})[id] || null; } };
    const win = { ...emitter(), isSecureContext: true, setTimeout, clearTimeout };
    const navigator = { mediaDevices: { async getUserMedia() { starter++; return stream; } } };
    const js = las('app.js');
    const section = js.slice(js.indexOf('function TDLKamera('), js.indexOf('/* ---------------------------------------------------------------------------\n * PWA:'));
    const context = { document: doc, window: win, navigator }; vm.runInNewContext(section, context);
    context.TDLKamera({});
    const tick = () => new Promise(resolve => setTimeout(resolve, 0));
    knapp.events.click(); await tick(); assert.equal(starter, 1); assert.equal(knapp.textContent, 'Stoppa kameran');
    track.events.mute(); assert.match(status.textContent, /pausad/);
    track.events.unmute(); assert.match(status.textContent, /aktiv igen/);
    doc.hidden = true; doc.events.visibilitychange();
    assert.equal(stoppar, 1); assert.equal(knapp.textContent, 'Återuppta kameran'); assert.match(status.textContent, /sidan doldes/);
    doc.hidden = false; doc.events.visibilitychange(); await tick(); assert.equal(starter, 1);
    knapp.events.click(); await tick(); assert.equal(starter, 2);
    track.events.mute(); assert.equal(knapp.textContent, 'Återuppta kameran');
    knapp.events.click(); await tick(); assert.equal(starter, 3); assert.equal(stoppar, 2);
    track.events.ended(); assert.equal(stoppar, 3); assert.match(status.textContent, /avslutades/); assert.equal(knapp.textContent, 'Återuppta kameran');
    assert.equal(video.srcObject, null);
    console.log('OK: kamerapaus, avbruten ström och manuell återstart');
}
