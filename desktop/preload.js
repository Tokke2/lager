/**
 * Den enda bryggan mellan webbsidan och skrivbordsappen.
 * Webbsidan får INTE tillgång till Node – bara dessa små funktioner.
 */

'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('tadegaSkrivbord', {
    arSkrivbordsapp: true,
    platform: process.platform,
    doljTillTray: () => ipcRenderer.invoke('dölj-till-tray'),
});
