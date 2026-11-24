# Inloggning med namn och PIN

Alla användare, även administratörer, loggar in med namn och en PIN-kod på
4–8 siffror. Det finns inget separat lösenord eller krav på lösenordsbyte.
PIN-koden lagras som en säker hash, aldrig i klartext.

Efter fem felaktiga försök spärras kontot i fem minuter. En användare med
tillfällig PIN måste välja en egen under **Mitt konto** innan övriga appen kan
användas. En administratör kan återställa andra användares PIN under **Konton**;
den nya koden är tillfällig och måste bytas av användaren. PIN-byte och
avstängning ogiltigförklarar gamla sessioner vid nästa begäran. Efter eget
PIN-byte loggar man in igen. Uppdateringen kräver även en ny inloggning för
äldre sessioner. Sessioner går ut efter 60 minuters inaktivitet eller högst
24 timmar.
Om den enda administratörens PIN glömts behövs hjälp av den som administrerar
servern. Kör `php bin/aterstall-pin.php --namn="Namn"` på servern för att skapa
en tillfällig PIN som visas en gång. Nollställ inte installationen.

Äldre installationer behåller befintliga PIN-koder. Vid första lyckade
inloggning efter uppdateringen rensas gamla lösenordsfält i den aktiva
användarfilen. Äldre säkerhetskopior och Git-historik ändras inte automatiskt.
HTTPS behövs för att skydda PIN-koden under överföring. Endast PIN ger ett
svagare skydd än PIN tillsammans med ett separat lösenord; begränsa åtkomsten
till servern och använd starka, unika PIN-koder.
