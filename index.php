<?php
/**
 * Tadega Lager – ingång i huvudmappen.
 *
 * Varför den finns:
 *
 * Appens riktiga webbrot är public/. Det är dit dokumentroten ska peka, och
 * då kan storage/, app/ och bin/ aldrig nås via URL. Men många webbhotell
 * (särskilt billigare paket och "one-click"-installationer) låter dig inte
 * välja dokumentrot – webbroten är och förblir kontots hemkatalog.
 *
 * Utan den här filen blir det då ett 403 eller en kataloglista, eftersom
 * rotens .htaccess bara fungerar om mod_rewrite är påslaget. Den här filen
 * gör att appen startar ändå: Apache letar efter index.php först (DirectoryIndex)
 * och hamnar här i stället för att visa en filservering av katalogen.
 *
 * Filen innehåller ingen logik – den lämnar över till samma front controller
 * som public/ använder, med sökvägen satt så att routningen blir identisk.
 *
 * Viktigt: den här filen skyddar INTE storage/ om dokumentroten är roten.
 * Rotens .htaccess nekar de katalogerna, men bara med mod_rewrite. Har hotellet
 * inte det måste appen ändå flyttas – se dokument/15_installera-pa-webhotell.md.
 */

declare(strict_types=1);

// Samma front controller som public/index.php använder.
require __DIR__ . '/public/index.php';
