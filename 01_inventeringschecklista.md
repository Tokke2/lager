# Inventeringschecklista – Tadega Lager

Syftet är att få en **faktisk** bild av vad som finns i arbetskopian innan något byggs eller migreras. Allt nedan är läsning. Inget skrivs, flyttas eller raderas i det granskade trädet.

---

## 1. Princip

- Arbeta alltid mot en **kopia** av arbetskopian, aldrig mot produktionskatalogen.
- Inventera med hash (SHA-256) så att samma fil kan identifieras igen senare.
- Notera storlek och mtime – en "tom" JSON kan vara tom på riktigt eller bara sakna poster.
- Inga riktiga lösenord, tokens eller kunduppgifter ska in i rapporten.

---

## 2. Vad som ska inventeras

| # | Område | Leta i | Leta efter | Varför |
|---|---|---|---|---|
| 1 | Router/nav | rotens `index.php`, `router.php`, ev. `routes/` | Träffar på `lager`, `stock`, `invent` | Bekräfta att ingen lagerflik är nåbar i aktuell version |
| 2 | Äldre lager-vyer | `includes/`, `includes/views/`, `views/` | Filnamn med `lager`, `stock`, `saldo`, `artikel` | De två kvarvarande visnings-/domänfilerna – vad gör de, har de dataanrop? |
| 3 | Gemensamma hjälpare | `includes/functions.php` e.d. | `update_stock(`, `saldo`, `backup` | Kartlägg det gamla flödet som **inte** ska återanvändas som lagermotor |
| 4 | JSON/data | `data/`, `storage/`, `json/` | `*.json` med lagerinnehåll | Identifiera tom lager-JSON, ev. äldre saldofiler |
| 5 | Lagerappens mapp | ev. `lager/`, `lager-app/`, `apps/` | Kravspec (md/pdf), skelett, README | Fastställa vad som redan är specat vs. byggt |
| 6 | Webbkonfig | `.htaccess`, `nginx.conf`, `web.config` | Skyddar befintliga datafiler? | Modell för hur `storage/` ska spärras |
| 7 | Beroenden | `composer.json`, `vendor/` | QR-bibliotek, kamera-JS | Återanvändning utan nätberoende i drift |
| 8 | Backup | rotmapp, cronfiler, `*.tar.gz`, `*.zip` | Befintlig backuprutin | Får inte kollidera med ny backupstruktur |

---

## 3. Kör så här (alternativ C)

```bash
# 1. Gör en kopia att granska (exempel)
rsync -a --exclude vendor --exclude node_modules /sökväg/till/arbetskopia/ /tmp/granskning/

# 2. Kör inventeringen – read-only mot /tmp/granskning
./verktyg/inventera-lagerfiler.sh /tmp/granskning \
    --ut /tmp/granskning-lager-rapport.md

# 3. Granska rapporten, skicka den hit
```

Valfria flaggor:

```
--max N          max antal kodträffar per fil i rapporten (default 5)
--php-lint       kör `php -l` på kandidatfiler (rör inget, kräver php i PATH)
--ingen-lint     hoppa över lint
--help           hjälp
```

Rapporten innehåller:

1. Sammanfattning: antal kandidatfiler per kategori.
2. Tabell: sökväg, typ, storlek, mtime, SHA-256.
3. Nyckelordsträffar (`update_stock`, `lager`, `saldo`, `inventer`, `qr`, `artikel`) med radnummer och kort kontext.
4. Router-/indexfiler och om de nämner lager.
5. Tomma eller misstänkt små JSON-filer.
6. Förväntade sökvägar som saknas.
7. `php -l`-resultat per fil (om aktiverat).

---

## 4. Om du skickar en zip i stället

**Skicka gärna – men sanera först:**

- Ta bort eller maska `config.php`, `.env` och liknande filer med databaslösenord, API-nycklar och portalens inloggningsuppgifter. Jag behöver strukturen, inte hemligheterna.
- Ta bort `uploads/`, kund-PDF:er och andra personuppgiftsbärande filer om de inte behövs för lagerfrågan.
- Om portalens datafiler behövs för migreringsanalys: skicka **bara** lagerrelaterade filer, gärna med fältnamn kvar men utan kundnamn.
- Skicka aldrig produktionsbackuper.

---

## 5. Frågor jag vill ha svar på samtidigt

1. **PHP-version och webbhotell** – kan docroot pekas på en undermapp? (Alternativ 2 i `00_start-har.md`.)
2. **Domän/subdomän** – är `lager.<domän>` ledig och HTTPS satt?
3. **Arbetsställen** – är Örebro, Laxå och systemposten de enda vid start, eller tillkommer fler?
4. **Vem blir första admin** – namn/roll (inga lösenord i chatten; lösenordet sätts i engångsflödet på servern).
5. **Etiketter** – finns skrivare/etikettark redan, eller ska jag föreslå två alternativ (A4-ark + termisk)?
6. **Befintliga lagerfiler** – får de kopieras ut för analys, och får migrering ske först efter din skriftliga check?

---

## 6. Vad rapporten **inte** kan avgöra

- Om produktionsdata skiljer sig från arbetskopian.
- Om portalen körs med annan kod på servern än i kopian.
- Huruvida webbhotellets proxy faktiskt spärrar `storage/` – det verifieras först i HTTPS-staging (test SÄK-01–SÄK-06 i `04_testplan.md`).
