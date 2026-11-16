/**
 * Tadega Lager – skrivbordsapp (Electron) med systray.
 *
 * Beteende:
 *   – Startar en lokal PHP-server (samma kod som webbversionen).
 *   – Fönstret kan minimeras/stängas till systemfältet (systray).
 *   – På macOS: klick på ikonen i menyraden öppnar fönstret; appen lever
 *     vidare även när fönstret stängs (tills man väljer Avsluta).
 *   – Data sparas i användarens datakatalog, inte i appens installationsmapp.
 *
 * Plattformar: Windows 10/11, macOS 11+, Linux (X11/Wayland via Electron).
 */

'use strict';

const {
    app, BrowserWindow, Tray, Menu, shell, nativeImage, dialog, ipcMain, Notification,
} = require('electron');
const path = require('path');
const fs = require('fs');
const { pathToFileURL } = require('url');

const ärDev = process.argv.includes('--dev');
const ärMac = process.platform === 'darwin';
const ärWin = process.platform === 'win32';
const ärLinux = process.platform === 'linux';

let huvudfonster = null;
let tray = null;
let serverInfo = null;
let avslutarMedvetet = false;

const loggFil = () => path.join(app.getPath('userData'), 'tadega-lager.log');
function logg(rad) {
    try {
        fs.appendFileSync(loggFil(), `${new Date().toISOString()} ${rad}\n`);
    } catch (_) {
        /* loggning får aldrig krascha appen */
    }
}

// ---------------------------------------------------------------------------
// En enda instans
// ---------------------------------------------------------------------------
if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => visaFonster());
}

// ---------------------------------------------------------------------------
// Datakatalog
// ---------------------------------------------------------------------------
function sakerstallDatakatalog() {
    const katalog = path.join(app.getPath('userData'), 'storage');
    for (const under of ['data', 'locks', 'tmp', 'journal', 'logg', 'backup', 'sessions']) {
        fs.mkdirSync(path.join(katalog, under), { recursive: true, mode: 0o700 });
    }
    return katalog;
}

function resursKatalog() {
    return app.isPackaged ? process.resourcesPath : path.join(__dirname, '..');
}

// ---------------------------------------------------------------------------
// Ikoner (fungerar i dev och paketerad app)
// ---------------------------------------------------------------------------
function ikonSokvag(namn) {
    const kandidater = [
        path.join(resursKatalog(), 'brand', 'ikoner', namn),
        path.join(__dirname, '..', 'brand', 'ikoner', namn),
        path.join(__dirname, '..', 'public', 'assets', 'ikoner', namn),
        path.join(process.resourcesPath || '', 'brand', 'ikoner', namn),
    ];
    return kandidater.find((p) => fs.existsSync(p)) || null;
}

function appIkon() {
    if (ärWin) return ikonSokvag('ikon.ico');
    if (ärMac) return null; // macOS använder appens bundlade ikon
    return ikonSokvag('ikon-512.png');
}

function trayIkon() {
    if (ärMac) {
        // Template-bild: svart + transparent, macOS färgar den efter menyläget
        const p = ikonSokvag('trayTemplate.png');
        const bild = p ? nativeImage.createFromPath(p) : nativeImage.createEmpty();
        bild.setTemplateImage(true);
        return bild;
    }
    if (ärWin) {
        const p = ikonSokvag('ikon.ico');
        return p ? nativeImage.createFromPath(p) : nativeImage.createEmpty();
    }
    // Linux: använd 32px PNG (fungerar i de flesta skrivbordsmiljöer)
    const p = ikonSokvag('tray-32.png');
    return p ? nativeImage.createFromPath(p) : nativeImage.createEmpty();
}

// ---------------------------------------------------------------------------
// Server
// ---------------------------------------------------------------------------
async function startaServer() {
    const datakatalog = sakerstallDatakatalog();
    process.env.TDL_STORAGE = datakatalog;
    process.env.TDL_SKRIVBORD = '1';
    process.env.PHP_BIN = process.env.PHP_BIN || '';
    const { starta } = require('./server.js');
    try {
        serverInfo = await starta(resursKatalog(), logg);
        logg(`server på port ${serverInfo.port}, data i ${datakatalog}`);
        return serverInfo;
    } catch (e) {
        logg('serverfel: ' + e.message);
        dialog.showErrorBox(
            'Tadega Lager kunde inte starta',
            e.message + '\n\nLogg: ' + loggFil()
        );
        throw e;
    }
}

// ---------------------------------------------------------------------------
// Fönster
// ---------------------------------------------------------------------------
function skapaFonster() {
    huvudfonster = new BrowserWindow({
        width: 1180,
        height: 820,
        minWidth: 380,
        minHeight: 520,
        show: false,
        title: 'Tadega Lager',
        icon: appIkon() || undefined,
        backgroundColor: '#0d1420',
        autoHideMenuBar: true,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            spellcheck: true,
        },
    });

    huvudfonster.once('ready-to-show', () => {
        huvudfonster.show();
        if (ärDev) huvudfonster.webContents.openDevTools({ mode: 'detach' });
    });

    huvudfonster.loadURL(`http://127.0.0.1:${serverInfo.port}/`);

    // Stäng = minimera till systray (utom vid medveten avslutning)
    huvudfonster.on('close', (e) => {
        if (!avslutarMedvetet) {
            e.preventDefault();
            if (!huvudfonster.isVisible()) return;
            huvudfonster.hide();
            if (!ärMac) visaForstaGangenMeddelande();
            logg('fönstret dolt till systemfältet');
        }
    });

    // Öppna externa länkar i systemwebbläsaren, aldrig i appen
    huvudfonster.webContents.setWindowOpenHandler(({ url }) => {
        shell.openExternal(url);
        return { action: 'deny' };
    });

    // Navigering utanför den lokala servern nekas
    huvudfonster.webContents.on('will-navigate', (e, url) => {
        if (!url.startsWith(`http://127.0.0.1:${serverInfo.port}`)) {
            e.preventDefault();
            shell.openExternal(url);
        }
    });

    huvudfonster.on('closed', () => {
        huvudfonster = null;
    });

    // Om renderaren kraschar/dör: ladda om fönstret i stället för att visa en tom yta
    huvudfonster.webContents.on('render-process-gone', (e, detaljer) => {
        logg('renderaren avslutades: ' + JSON.stringify(detaljer));
        if (!avslutarMedvetet && huvudfonster && !huvudfonster.isDestroyed()) {
            setTimeout(() => {
                if (huvudfonster && !huvudfonster.isDestroyed()) {
                    huvudfonster.loadURL(`http://127.0.0.1:${serverInfo.port}/`);
                }
            }, 500);
        }
    });
}

let meddelat = false;
function visaForstaGangenMeddelande() {
    if (meddelat) return;
    meddelat = true;
    if (Notification.isSupported()) {
        new Notification({
            title: 'Tadega Lager',
            body: 'Appen körs kvar i systemfältet. Klicka på ikonen för att öppna den igen.',
            icon: ikonSokvag('ikon-256.png') || undefined,
            silent: true,
        }).show();
    } else if (tray) {
        tray.displayBalloon?.({
            title: 'Tadega Lager',
            content: 'Appen körs kvar i systemfältet.',
            iconType: 'info',
        });
    }
}

function visaFonster() {
    if (!huvudfonster || huvudfonster.isDestroyed()) {
        skapaFonster();
        return;
    }
    if (huvudfonster.isMinimized()) huvudfonster.restore();
    if (!huvudfonster.webContents.isDestroyed()) {
        huvudfonster.show();
        huvudfonster.moveTop();
        huvudfonster.focus();
    }

    // Vissa Linux-skal (bl.a. Wayland/X11 med viss fönsterhanterare) kan lämna
    // fönstret "unmapped" efter att det dolts. Då bygger vi helt enkelt om det.
    setTimeout(() => {
        if (!huvudfonster || huvudfonster.isDestroyed()) return;
        if (!huvudfonster.isVisible()) {
            logg('fönstret blev inte synligt efter show – skapar om det');
            const gammalt = huvudfonster;
            huvudfonster = null;
            try {
                gammalt.destroy();
            } catch (_) {
                /* ignoreras */
            }
            skapaFonster();
        }
    }, 450);
}

// ---------------------------------------------------------------------------
// Systray
// ---------------------------------------------------------------------------
function skapaTray() {
    try {
        tray = new Tray(trayIkon());
    } catch (e) {
        logg('tray kunde inte skapas: ' + e.message);
        return;
    }
    const meny = Menu.buildFromTemplate([
        { label: 'Öppna Tadega Lager', click: () => visaFonster() },
        { type: 'separator' },
        {
            label: 'Öppna i webbläsaren',
            click: () => shell.openExternal(`http://127.0.0.1:${serverInfo.port}/`),
        },
        {
            label: 'Matappnamn på server',
            visible: ärDev,
            click: () => huvudfonster?.webContents.openDevTools({ mode: 'detach' }),
        },
        { type: 'separator' },
        {
            label: 'Öppna datakatalog',
            click: () => shell.openPath(path.join(app.getPath('userData'), 'storage')),
        },
        {
            label: 'Öppna logg',
            click: () => shell.openPath(loggFil()),
        },
        { type: 'separator' },
        { label: 'Avsluta', click: () => { avslutarMedvetet = true; app.quit(); } },
    ]);
    tray.setToolTip('Tadega Lager');
    tray.setContextMenu(meny);
    // Vänsterklick öppnar fönstret, högerklick visar menyn
    tray.on('click', () => visaFonster());
    tray.on('double-click', () => visaFonster());
}

// ---------------------------------------------------------------------------
// Livscykel
// ---------------------------------------------------------------------------
app.whenReady().then(async () => {
    if (!ärMac) {
        Menu.setApplicationMenu(null);
    }
    try {
        await startaServer();
    } catch (_) {
        app.quit();
        return;
    }
    skapaFonster();
    skapaTray();
});

// macOS: klick på dockikonen ska visa fönstret igen
app.on('activate', () => visaFonster());

// På macOS vill man normalt att appen lever kvar utan fönster
app.on('window-all-closed', () => {
    if (!ärMac && !tray) {
        app.quit();
    }
});

app.on('before-quit', () => {
    avslutarMedvetet = true;
});

app.on('will-quit', () => {
    if (serverInfo?.proces) {
        try {
            serverInfo.proces.kill();
        } catch (_) {
            /* ignoreras */
        }
    }
});

process.on('uncaughtException', (e) => {
    logg('okänt fel: ' + (e?.stack || e));
});

// Enkel IPC för menyn (används av renderer om den vill stänga till tray)
ipcMain.handle('dölj-till-tray', () => {
    huvudfonster?.hide();
    visaForstaGangenMeddelande();
    return true;
});
