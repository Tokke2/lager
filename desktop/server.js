/**
 * Lokal server för skrivbordsappen.
 *
 * Startar PHP:s inbyggda server (samma kod som webbversionen) på en intern
 * port och lägger en liten HTTP-proxy framför på en egen port, som appens
 * fönster använder. Två separata portar – proxyn och PHP kan inte dela port.
 *
 * PHP hittas i den här ordningen:
 *   1. PHP_BIN i miljön
 *   2. php/bin/php i appens resurskatalog (för inbuntad PHP)
 *   3. php / php.exe i PATH
 */

'use strict';

const http = require('http');
const net = require('net');
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');

/** Hitta en ledig port på 127.0.0.1 med början på `start`. */
function ledigPort(start) {
    return new Promise((resolve, reject) => {
        const försök = (port, kvar) => {
            if (kvar <= 0) {
                reject(new Error('Hittade ingen ledig lokal port.'));
                return;
            }
            const s = net.createServer();
            s.once('error', () => försök(port + 1, kvar - 1));
            s.once('listening', () => s.close(() => resolve(port)));
            s.listen(port, '127.0.0.1');
        };
        försök(start, 80);
    });
}

function hittaPhp(resursKatalog) {
    const kandidater = [];
    if (process.env.PHP_BIN) kandidater.push(process.env.PHP_BIN);
    kandidater.push(
        path.join(resursKatalog, 'php', 'bin', process.platform === 'win32' ? 'php.exe' : 'php'),
        path.join(resursKatalog, '..', 'php', 'bin', process.platform === 'win32' ? 'php.exe' : 'php')
    );
    for (const k of kandidater) {
        if (k && fs.existsSync(k)) return k;
    }
    return process.platform === 'win32' ? 'php.exe' : 'php';
}

/** Vänta tills PHP svarar på en enkel förfrågan. */
function vantaPaPhp(port, timeoutMs = 15000) {
    const start = Date.now();
    return new Promise((resolve, reject) => {
        const försök = () => {
            const req = http.request(
                { host: '127.0.0.1', port, path: '/login', method: 'GET', timeout: 1000 },
                (res) => {
                    res.resume();
                    resolve();
                }
            );
            req.on('error', () => {
                if (Date.now() - start > timeoutMs) {
                    reject(new Error('PHP-servern svarade inte inom ' + Math.round(timeoutMs / 1000) + ' s.'));
                    return;
                }
                setTimeout(försök, 150);
            });
            req.on('timeout', () => req.destroy());
            req.end();
        };
        försök();
    });
}

/**
 * Starta PHP + proxy.
 * @returns {Promise<{port:number, phpPort:number, proces:import('child_process').ChildProcess, proxy:http.Server}>}
 */
async function starta(resursKatalog, logg, startPort = Number(process.env.TDL_PORT) || 18420) {
    const appKatalog = path.join(resursKatalog, 'app');
    const publig = path.join(resursKatalog, 'public');
    const datakatalog = process.env.TDL_STORAGE;

    if (!fs.existsSync(path.join(publig, 'index.php'))) {
        throw new Error('Appens filer saknas i ' + publig + '.');
    }

    const php = hittaPhp(resursKatalog);
    const phpPort = await ledigPort(startPort + 1);

    logg && logg(`startar php (${php}) på port ${phpPort}, data: ${datakatalog || '(standard)'}`);

    const phpProcess = spawn(
        php,
        ['-S', `127.0.0.1:${phpPort}`, '-t', publig, path.join(publig, 'index.php')],
        {
            cwd: appKatalog,
            env: { ...process.env },
            stdio: ['ignore', 'pipe', 'pipe'],
        }
    );

    let avslutad = false;
    phpProcess.on('exit', (kod) => {
        avslutad = true;
        logg && logg(`php avslutades med kod ${kod}`);
    });
    phpProcess.stderr.on('data', (d) => {
        const text = d.toString().trim();
        if (text) logg && logg(`php: ${text}`);
    });

    // Vänta tills PHP faktiskt svarar (eller ge ett tydligt fel)
    try {
        await vantaPaPhp(phpPort);
    } catch (e) {
        try { phpProcess.kill(); } catch (_) { /* ignoreras */ }
        if (avslutad) {
            throw new Error(
                'PHP-servern avslutades direkt. PHP 8.1+ krävs. Kontrollera loggen: ' +
                'php -v i terminalen.'
            );
        }
        throw e;
    }

    // Proxy på egen port framför PHP
    const proxyPort = await ledigPort(startPort);

    const proxy = http.createServer((req, res) => {
        const uppströms = http.request(
            {
                host: '127.0.0.1',
                port: phpPort,
                path: req.url,
                method: req.method,
                headers: { ...req.headers, host: `127.0.0.1:${phpPort}` },
            },
            (svar) => {
                res.writeHead(svar.statusCode || 502, svar.headers);
                svar.pipe(res);
            }
        );
        uppströms.on('error', () => {
            if (!res.headersSent) {
                res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
            }
            res.end('Servern svarar inte just nu.');
        });
        req.pipe(uppströms);
    });

    await new Promise((resolve, reject) => {
        proxy.once('error', reject);
        proxy.listen(proxyPort, '127.0.0.1', resolve);
    });

    logg && logg(`appens adress: http://127.0.0.1:${proxyPort}/`);

    return { port: proxyPort, phpPort, proces: phpProcess, proxy };
}

module.exports = { starta, ledigPort };
