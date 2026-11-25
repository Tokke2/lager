'use strict';

// Provar serverstart och proxy med en isolerad testserver i stället för PHP.
const assert = require('node:assert/strict');
const childProcess = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lager-skrivbordstest-'));
fs.mkdirSync(path.join(tmp, 'public'));
fs.mkdirSync(path.join(tmp, 'app'));
fs.writeFileSync(path.join(tmp, 'public', 'index.php'), '');
const fakePhp = path.join(tmp, 'fake-php.cjs');
fs.writeFileSync(fakePhp, `
const http = require('node:http');
const address = process.argv[process.argv.indexOf('-S') + 1];
const port = Number(address.split(':').pop());
http.createServer((req, res) => res.end('testserver ' + req.url)).listen(port, '127.0.0.1');
`);

const originalSpawn = childProcess.spawn;
let spawnOptions;
childProcess.spawn = (_program, args, options) => {
    spawnOptions = options;
    return originalSpawn(process.execPath, [fakePhp, ...args], options);
};
const { starta } = require('../desktop/server.js');
childProcess.spawn = originalSpawn;

function hamta(port, url) {
    return new Promise((resolve, reject) => {
        http.get({ host: '127.0.0.1', port, path: url }, (res) => {
            let body = '';
            res.setEncoding('utf8');
            res.on('data', (part) => { body += part; });
            res.on('end', () => resolve(body));
        }).on('error', reject);
    });
}

(async () => {
    let server;
    try {
        server = await starta(tmp, () => {}, 20000 + Math.floor(Math.random() * 20000));
        assert.equal(spawnOptions.windowsHide, true);
        assert.deepEqual(spawnOptions.stdio, ['ignore', 'pipe', 'pipe']);
        assert.equal(await hamta(server.port, '/prova'), 'testserver /prova');
        console.log('OK: windowsHide vid serverstart och fungerande lokal testproxy');
    } finally {
        if (server) {
            await new Promise((resolve) => server.proxy.close(resolve));
            const exited = new Promise((resolve) => server.proces.once('exit', resolve));
            server.proces.kill();
            await exited;
        }
        fs.rmSync(tmp, { recursive: true, force: true });
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
