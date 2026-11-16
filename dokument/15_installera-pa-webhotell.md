# Installera Tadega Lager på webhotell

*Skrivet för WebSupport (Apache + PHP 8.1+), men stegen är desamma hos nästan
alla svenska webbhotell. Allt är provat på PHP 8.3.*

---

## Innan du börjar

Du behöver tre saker:

1. **PHP 8.1 eller nyare** — kontrollera under PHP-inställningar i kontrollpanelen.
2. **SSH eller terminal** — de flesta paket har det. Saknas det, se avsnitt
   *Utan SSH* längst ner.
3. **En subdomän** — t.ex. `lager.dittforetag.se`. Använd inte huvuddomänen om
   ni har en hemsida där.

> **Ingen databas behövs.** Appen lagrar allt i JSON-filer i `storage/`.
> Det är därför installationen bara är filuppladdning + två kommandon.

---

## Steg för steg

### 1. Ladda upp filerna

Packa upp `Tadega-Lager.zip` lokalt och ladda upp mappen med SFTP till
hemkatalogen på servern. Slutresultatet ska bli:

```
/home/DITTKONTO/
└── tadega-lager/
    ├── app/
    ├── bin/
    ├── public/      ← den här ska vara dokumentrot
    ├── storage/
    ├── brand/
    ├── dokument/
    └── .htaccess
```

**Ladda inte upp zipfilen och packa upp på servern om du är osäker** — vissa
SFTP-klienter tappar filrättigheterna. Uppackad mapp uppladdad fungerar alltid.

### 2. Kontrollera att servern duger

```bash
cd ~/tadega-lager
php verktyg/miljokontroll.php
```

Skriptet skriver inget och ändrar inget. Det svarar:

```
Kritiska fel: 0  (miljön kan användas)
```

Får du kritiska fel är det nästan alltid PHP-versionen. Byt till 8.3 i
kontrollpanelen och kör igen. Saknas `zip` går det bra — bara backupen blir
okomprimerad. `gd` behövs för bildnedskalning.

### 3. Sätt rättigheter

```bash
chmod 700 storage storage/*
chmod 600 storage/data/*.json
```

`storage/` innehåller alla data. Den ska bara läsas av ditt konto — aldrig av
webbservern direkt, och aldrig via en URL.

### 4. Skapa första administratören

Två sätt. Välj **ett** av dem.

**A. Från terminalen (snabbast):**

```bash
php bin/installera.php --namn="Anna Andersson" --pin=4821
```

Du får tillbaka:

```
Första administratören skapad: Anna Andersson (K31Q2)
Dela aldrig PIN-koden i klartext. Den kan bytas under Mitt konto.
Arbetsställe: Örebro (PW085)
Arbetsställe: Laxå (PZQYA)
```

**B. I webbläsaren (om du hellre vill klicka):**

Öppna `https://lager.dittforetag.se/installera` — guiden dyker upp automatiskt
när det inte finns några konton. Den frågar efter en **installationstoken**,
som står i filen `storage/setup.token`:

```bash
cat storage/setup.token
```

Det är en spärr så att ingen annan kan ta över installationen medan den pågår.
Guiden skapar kontot, loggar in dig och raderar tokenfilen.

> **Byt PIN-koden efter första inloggningen.** Under *Mitt konto*.

### 5. Peka subdomänen rätt

I kontrollpanelen (Webadmin → Subdomäner):

| Inställning | Värde |
|---|---|
| Subdomän | `lager` |
| Dokumentrot | `/home/DITTKONTO/tadega-lager/public` |

**Det här är det viktigaste steget.** Pekar dokumentroten på `tadega-lager/`
i stället för `tadega-lager/public` går `storage/` att nå via URL — då kan vem
som helst läsa era lagersaldon och konton.

Går det inte att sätta egen dokumentrot? Då finns **två** reserver, och båda
ligger redan på plats:

1. **Rotens `index.php`** — Apache letar alltid efter `index.php` först, så
   appen startar även om dokumentroten är projektets rot. Filen innehåller
   ingen logik, bara en överlämning till `public/index.php`.
2. **Rotens `.htaccess`** — skickar allt vidare till `public/` och nekar
   `storage|app|bin|tests|verktyg`.

Provat: med dokumentroten i projektets rot fungerar inloggning, val av
arbetsställe och startsidan. `/storage/data/anvandare.json` och
`/app/bootstrap.php` ger 404.

**Men**: `index.php` löser bara att appen *startar*. Skyddet av `storage/`
bygger fortfarande på att Apache får läsa `.htaccess` (mod_rewrite). Har
hotellet inte det är reserven svag — flytta då appen eller sätt dokumentroten.
**Verifiera alltid efteråt:**

```
https://lager.dittforetag.se/storage/data/anvandare.json
https://lager.dittforetag.se/app/bootstrap.php
```

Båda ska svara **403 eller 404**. Får du innehåll — stoppa och rätta
dokumentroten innan du lägger in några riktiga data.

### 6. Slå på HTTPS

Aktivera Let's Encrypt för subdomänen. Det finns i paketet och kostar inget.

Appen kräver HTTPS i drift: utan det skickas PIN-koder och lösenord i klartext
över nätet.

### 7. Sätt den publika adressen — **före första utskriften**

Logga in och gå till **System → Publik adress**. Skriv `https://lager.dittforetag.se`.

Det är den adress QR-koderna pekar på. Skriver du ut lappar innan adressen är
satt pekar de fel, och **lappar som redan sitter uppe slutar fungera om du
ändrar adressen efteråt.**

### 8. Skapa era arbetsställen

Under **Arbetsställen** — lägg upp de ställen ni faktiskt jobbar på.
Installationen skapar Örebro och Laxå som exempel; döp om eller ta bort dem.

Skriv sedan ut en arbetsplats-QR per ställe under **Etiketter** och sätt vid
ingången.

### 9. Lägg in varorna

Antingen manuellt under **Artiklar → Ny artikel**, eller via
`php bin/demodata.php` för att se hur det ser ut innan ni börjar med de
riktiga.

### 10. Sätt upp backup

Lägg det här i cron (Webadmin → Cron):

```
15 2 * * * /usr/bin/php /home/DITTKONTO/tadega-lager/bin/backup.php >> /home/DITTKONTO/tadega-lager/storage/logg/backup.log 2>&1
30 3 * * 1 /usr/bin/php /home/DITTKONTO/tadega-lager/bin/verifiera-historik.php >> /home/DITTKONTO/tadega-lager/storage/logg/historik.log 2>&1
```

Den första tar backup varje natt, den andra kontrollerar historikkedjan varje
måndag. Båda syns på **Översikt** — blir raden röd är något fel.

Kör gärna en manuell backup direkt:

```bash
php bin/backup.php
```

---

## Kontrollera att det fungerar

| Test | Förväntat |
|---|---|
| `https://lager.dittforetag.se` | inloggningssidan |
| Logga in med admin | frågan *Var jobbar du i dag?* |
| Välj arbetsställe | översikten, stället syns i huvudet |
| **System → Hälsokontroll** | alla 8 kontroller gröna |
| `.../storage/data/anvandare.json` | **403 eller 404** |
| Ta emot en vara | saldot ökar, posten syns i Historik |

---

## Utan SSH

En del billiga paket saknar terminal. Då:

1. Ladda upp filerna som vanligt med SFTP.
2. Öppna `https://lager.dittforetag.se/installera` i webbläsaren.
3. Hämta token genom att ladda ner `storage/setup.token` via SFTP och öppna
   filen lokalt.
4. Fyll i guiden.

Backupen blir svårare utan cron. Be hotellet aktivera det, eller ta en manuell
kopia av `storage/` med jämna mellanrum via SFTP.

---

## Vanliga problem

| Symptom | Orsak | Lösning |
|---|---|---|
| **500 Internal Server Error** | PHP för gammalt, eller `.htaccess` nekar | Kör `php verktyg/miljokontroll.php`, byt till PHP 8.3 |
| **Tom vit sida** | `display_errors` avstängt och ett fel | Kolla `storage/logg/` eller felmeddelandeloggen i kontrollpanelen |
| **"Ogiltig CSRF-token"** | Sessionskatalogen är inte skrivbar | `chmod 700 storage storage/sessions` |
| **Ingen QR-kod ritas** | CSP blockerar, eller `assets/qrcode.js` saknas | Ladda om hårt, kontrollera att `public/assets/` kom med |
| **Inloggningen går inte igenom** | Fel PIN, eller klockan på servern går fel | PIN: 4–8 siffror. Kontrollera servertiden |
| **"Installationen är redan genomförd"** | Det finns redan ett konto | Logga in i stället. Glömt PIN: `php bin/aterstall-losenord.php` |
| **QR-koden öppnar inget** | Publik adress är inte satt | System → Publik adress |

---

## Uppdatera appen senare

```bash
# 1. Ta backup först
php bin/backup.php

# 2. Byt ut app/, public/, bin/ och brand/ – rör ALDRIG storage/
# 3. Kontrollera
php bin/verifiera-historik.php
```

`storage/` innehåller er data. Den ska aldrig skrivas över vid en uppdatering.

---

*Allt ovan är provat: installation från tom katalog, webbinstallationsguiden,
och en uppackad kopia som kördes direkt. 90 prov gröna, historikkedjan intakt.*

---

# Installation i en undermapp (`/public_html/lager`)

Många webbhotell ger en enda webbrot per konto. Då ligger appen i en
undermapp:

```
/home/kund/public_html/lager/     ← hit laddas hela projektet
https://kund.se/lager/            ← hit går besökaren
```

Fram till 13 november 2026 fungerade inte det: alla länkar var absoluta
(`href="/login"`) och pekade därför på `kund.se/login` i stället för
`kund.se/lager/login`. Provat i en riktig undermapp gav **404 på allt**.

## Vad som ändrades

**`app/Support/Bas.php`** räknar fram var appen ligger – en gång per
förfrågan – och lägger den delen framför varje intern adress:

| Anrop | Resultat i `/lager` |
|---|---|
| `u('/login')` | `/lager/login` |
| `u('/')` | `/lager/` |
| `u('/uttag?artikel=A1')` | `/lager/uttag?artikel=A1` |
| `u('https://x.se')` | `https://x.se` (rörs inte) |

Basvägen **räknas ut automatiskt** ur `SCRIPT_NAME` — flyttar man mappen
fungerar det ändå. Behövs en överstyrning (t.ex. bakom en proxy som skriver
om sökvägen) sätter man miljövariabeln:

```bash
TDL_BAS_PATH=/lager
```

## Vad som täcktes

- **95 länkar och 40 formulär** i alla 33 vyer — omskrivna till `u('…')`
- **52 omdirigeringar** — basvägen läggs på i `omdirigera()`, ett enda ställe
- **Routningen** — `public/index.php` kapar bort prefixet innan ruttabellen läses
- **QR-adresserna** — pekar på `https://kund.se/lager/artikel/…`
- **Logotypen och ikonerna** — `Varumarke::logotypUrl()` och `ikonUrl()`
- **Service workern** — registreras med rätt `scope`, cachar rätt filer
- **JS** — `data-bas` på `<body>`, används för skanning och sw-registrering

## Provat

Med appen i `/lager` och dokumentroten i `public_html`:

| Steg | Resultat |
|---|---|
| `/lager/login` | inloggningssidan, 200 |
| Inloggning | "Var jobbar du i dag?" |
| Välj ställe | "Hej Anna", 21 kort |
| **Alla 20 kort** | **200 med rätt rubrik** |
| `/lager/assets/app.css` | 200, text/css |
| `/lager/assets/app.js` | 200, text/javascript |
| `/lager/site.webmanifest` | 200, application/manifest+json |
| HTTP-fel / JS-fel | inga |

## Två buggar som proven hittade

1. **Korten saknade prefix.** `$kort()` fick adressen som argument och gick
   aldrig genom `u()` – meny och ikoner hade prefix, men inte korten.
2. **`/tmp` blev basväg på kommandoraden.** `Bas` läste `SCRIPT_NAME`, som i
   CLI är scriptets sökväg på disk. Alla länkar hade blivit `/tmp/login`.
   CLI-läget returnerar nu alltid tom basväg. Prov **BAS-01** vaktar det.

## Så här ska det se ut

```
public_html/
└── lager/              ← hela projektet hit
    ├── index.php       ← reservingång
    ├── public/
    ├── app/
    ├── storage/        ← måste skyddas, se nedan
    └── .htaccess
```

Dokumentroten är `public_html` (hotellets fasta val). Adressen blir
`https://kund.se/lager`.

**Skydda storage/.** Rotens `.htaccess` nekar `storage|app|bin|tests`, men
bara med `mod_rewrite`. Verifiera:

```
https://kund.se/lager/storage/data/anvandare.json   → 403 eller 404
https://kund.se/lager/app/bootstrap.php             → 403 eller 404
```

Får du innehåll: lägg `lager/` utanför `public_html` och peka om, eller be
hotellet slå på `mod_rewrite`.

---

*Provsviten: **100 godkända, 0 misslyckade**.*

---

# Första gången: namn, PIN och lösenord

Releasen är en **ren utgångspunkt** – den innehåller inga konton, inga artiklar
och inget företagsnamn. Första besökaren möts av installationsguiden.

## 1. Öppna appens adress

```
https://kund.se/lager
```

Guiden ber om fem saker – **namn och PIN sätts innan man loggar in**:

| Fält | Vad det är |
|---|---|
| **Företagets namn** | står i menyn och historiken, t.ex. "Nordiska Bygg AB" |
| **Ditt namn** | visas i historiken ("Anna tog ut 4 st") |
| **PIN-kod** | 4–8 siffror, används vid varje inloggning |
| **Lösenord** | minst 12 tecken, bara för administratörer |
| **Installationstoken** | står i `storage/setup.token` på servern |

När du klickar **Installera och fortsätt till inloggning** skickas du till
inloggningssidan med företagsnamnet redan satt. Logga in med den PIN och det
lösenord du valde. Ingen automatisk inloggning – PIN-koden är den du själv
satte, inte en tillfällig som måste bytas direkt.

Token finns för att ingen annan ska kunna ta över appen innan du hunnit
installera den. Den visas **inte** på sidan – öppna filen via FTP eller
hotellets filhanterare och klistra in innehållet.

Har du kommandoraden går det snabbare:

```bash
php bin/installera.php --namn="Anna" --pin=7391
```

## 2. Logga in med din PIN

Ange namn, PIN och (som administratör) lösenord. Klart.

## 3. PIN-byte för andra konton

Den **första** administratören väljer sin PIN i guiden och behöver inte byta
den. Men alla **andra** konton får en tillfällig PIN när en admin skapar dem
– och då gäller en spärr:

> Du måste välja en egen PIN-kod innan du kan använda appen.

**Ingen annan sida går att öppna förrän PIN:en är bytt** – försöker man gå
till Artiklar skickas man tillbaka till Mitt konto. Tidigare var detta bara
en upplysning som gick att klicka bort; då kunde ett konto fortsätta med en
PIN som någon annan hade satt.

Samma spärr gäller konton som återaktiveras efter avstängning.

## 4. Laddar du upp ovanpå en gammal installation?

Har appen varit installerad tidigare ligger konton och företagsnamn kvar i
`storage/`. Då visas inloggningen i stället för guiden – och namnlistan kan
vara tom, så att det inte går att komma in alls.

Öppna då:

```
https://kund.se/lager/aterstall
```

Sidan tar bort konton, artiklar, saldon, historik, bilder, företagsnamn och
QR-nyckeln – och startar om från guiden. Den kräver **installationstoken**
(från `storage/setup.token`) och att du skriver `NOLLSTALL` för att bekräfta.
Det går inte att ångra, så säkerhetskopiera först om du vill behålla något.

Utan åtkomst till sidan går det lika bra att ta bort filerna via FTP:

```
storage/data/anvandare.json     ← kontona
storage/data/artiklar.json
storage/data/historik.json
storage/data/enheter.json
storage/data/platser.json
storage/data/sessioner.json
storage/varumarke.json          ← företagsnamnet
```

## 5. Ändra företagsnamn och varumärke

Under **Mer → Varumärke** ändrar du företagsnamnet och sätter logotyp,
färger och ordval (t.ex. om ni säger "butik" i stället för "arbetsställe").

## Vad releasen inte innehåller

| Fil | Varför den saknas |
|---|---|
| `storage/data/anvandare.json` | konton ska skapas av kunden, inte följa med |
| `storage/data/artiklar.json` | inga demoprylar i skarp drift |
| `storage/data/historik.json` | historiken ska börja tom och egna rader |
| `storage/varumarke.json` | inget kundnamn är förvalt |
| `storage/artikelqr.json` | QR-nyckeln skapas per installation |

Datafilerna skapas automatiskt vid första besöket, så appen kan aldrig
levereras med ett konto någon annan känner till.

Vill du se appen med innehåll i en testmiljö:

```bash
php bin/demodata.php --ja-jag-vet-att-detta-ar-test
```

Kör den **aldrig** i skarp drift.

---

*Provsviten: **100 godkända, 0 misslyckade** (INST-01–03 vaktar installationsflödet).*

---

# Spara inloggningen i Bitwarden

Inloggningssidorna är byggda så att **Bitwarden, 1Password, Chrome och Safari**
erbjuder sig att spara inloggningen – både vid installationen och vid vanlig
inloggning.

| Formulär | Användarnamn | Sparas som lösenord |
|---|---|---|
| **Guiden** (`/installera`) | Ditt namn | Lösenordet |
| **Personal** | Namn (textfält) | PIN-koden |
| **Administratör** | Namn (textfält) | Lösenordet — PIN-koden skrivs för hand |

**Två saker krävdes för att det skulle fungera:**

1. **Namnet är ett textfält, inte en lista.** Bitwarden sparar bara när
   användarnamnet är ett inmatningsfält. Det gamla `<select>`-fältet gick
   inte att spara alls. Namnen föreslås fortfarande – via `datalist`, så du
   kan skriva de tre första bokstäverna i stället för att rulla i en lista.
2. **Bara ett lösenordsfält per formulär.** Administratörens PIN-fält är
   märkt `autocomplete="one-time-code"`, vilket säger åt lösenordshanteraren
   att det inte är det sparade lösenordet. Annars ser Bitwarden två
   lösenordsfält och vägrar spara.

## Så här ser posten ut i Bitwarden

```
Namn:      Tadega Lager (eller vad du kallar appen)
Användare: Anna Andersson
Lösenord:  KistaLager2026b        ← admin
           – eller PIN-koden –    ← personal
```

Är du **personal** sparas PIN-koden som lösenord – det är den enda uppgift du
anger. Är du **administratör** sparas lösenordet, och PIN-koden skriver du
för hand (den är kort och lätt att komma ihåg; att lägga den i valvet vore
att spara två hemligheter i samma post).

Vill du spara även PIN-koden som ett eget fält: öppna posten i Bitwarden,
välj **Nytt fält → Dolt fält**, namnge det `pin` och skriv koden. Bitwarden
kan sedan fylla i det via **Högerklicka → Bitwarden → Autofyll** på sidan.

## Vad som inte går att spara

Bitwarden kan bara spara **ett** lösenord per post. Två lösenordsfält i
samma formulär gör att den inte erbjuder sig att spara alls – det är därför
PIN-fältet är undantaget.

---

*Provsviten: **101 godkända, 0 misslyckade** (BW-01 vaktar att inloggningen
går att spara).*
