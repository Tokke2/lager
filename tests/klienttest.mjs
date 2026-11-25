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
