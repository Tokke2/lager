# Drift på webbhotell (WebSupport)

Websupport kör Apache 2.4 med PHP 8.2–8.5, SSH och cron. Det passar Tadega Lager.
Kontrollera alltid med `verktyg/miljokontroll.php` på det aktuella paketet.

---

## 1. Katalog på servern

```
~/
├── tadega-lager/
│   ├── public/            ← dokumentrot för subdomänen
│   ├── app/               ← utanför webbroten
│   ├── bin/               ← körs via SSH
│   ├── storage/           ← data, lås, journal, backup (0700)
│   ├── brand/             ← logotypfiler (behövs för standardlogotyp)
│   └── dokument/
```

**Viktigast:** subdomänens dokumentrot ska peka på `tadega-lager/public`.
Då kan `storage/`, `app/` och `bin/` aldrig nås via URL, oavsett webbserverkonfiguration.

Om hotellet inte tillåter egen dokumentrot:
1. Lägg appen i en undermapp och peka dokumentroten på appens rot.
2. Rotens `.htaccess` skickar allt till `public/` och nekar `storage|app|bin|tests|verktyg`.
3. Verifiera med test SÄK-01–SÄK-06 innan drift.

---

## 2. Installation i tio steg

```bash
# 1. Ladda upp koden (SFTP/SSH eller git)
cd ~/tadega-lager

# 2. Kontrollera miljön
php verktyg/miljokontroll.php

# 3. Sätt rättigheter
chmod 700 storage storage/*
chmod 600 storage/data/*.json 2>/dev/null

# 4. Skapa datafiler och första administratören
php bin/installera.php --namn="Anna Andersson" --pin=4821

# 5. Verifiera
php bin/verifiera-historik.php
php bin/backup.php
```

6. Sätt subdomänens dokumentrot till `tadega-lager/public` i Webadmin.
7. Aktivera HTTPS (Let's Encrypt finns i paketet).
8. Öppna `https://lager.doman.se` och logga in.
9. Skapa arbetsställen och skriv ut arbetsplats-QR.
10. Kör igenom test SÄK-01–SÄK-06 (nedan) och ett par av godkännandetesterna.

---

## 3. Cron

```
# Backup varje natt 02:15
15 2 * * * /usr/bin/php /home/KUND/tadega-lager/bin/backup.php >> /home/KUND/tadega-lager/storage/logg/backup.log 2>&1

# Historikkontroll varje måndag
30 3 * * 1 /usr/bin/php /home/KUND/tadega-lager/bin/verifiera-historik.php >> /home/KUND/tadega-lager/storage/logg/historik.log 2>&1

# Miniminivåsammanställning varje vardagsmorgon (valfritt, kräver mail())
30 6 * * 1-5 /usr/bin/php /home/KUND/tadega-lager/bin/miniminiva-mail.php >> /home/KUND/tadega-lager/storage/logg/miniminiva.log 2>&1
```

Kör `backup.php` även manuellt före varje uppdatering av koden.

### Kontrollera att cron verkligen kör

Backupen syns på **Översikt** och **System** – raden blir röd om senaste backup är
äldre än gränsen (standard 2 dygn). Se
[10_larm-sakerhet-och-arkiv.md](10_larm-sakerhet-och-arkiv.md).

```bash
ls -lt storage/backup/ | head -5          # senaste filerna
tail -20 storage/logg/backup.log          # vad cron skrev
```

Testa e-postskriptet utan att skicka något:

```bash
php bin/miniminiva-mail.php --visa
```

---

## 4. Säkerhetstester i driftmiljö (SÄK-01–06)

Kör från valfri dator, **utan** inloggning:

```bash
B=https://lager.doman.se
for p in /storage/data/anvandare.json /storage/data/artiklar.json \
         /app/bootstrap.php /bin/backup.php /tests/integrationstest.php \
         /.git/config /.htaccess /storage/journal/ /storage/backup/; do
  printf "%-40s %s\n" "$p" "$(curl -s -o /dev/null -w '%{http_code}' "$B$p")"
done
```

Alla ska ge **403 eller 404**. Ett enda 200 är ett stopp.

Kontrollera också:
- `curl -sI https://lager.doman.se | grep -i strict-transport` (HSTS om hotellet sätter det)
- att inloggning krävs: `curl -s -o /dev/null -w '%{http_code}' https://lager.doman.se/` → 303 till `/login`
- att kameran bara startar efter knapptryck på en riktig telefon.

---

## 5. Uppdatera appen

```bash
cd ~/tadega-lager
php bin/backup.php                    # 1. backup först
git pull                              # eller ladda upp nya filer
php bin/verifiera-historik.php        # 2. historiken intakt
php bin/aterhamta.php                 # 3. ska säga "Inga öppna journaler"
```

Datamodellen har `schema_version`. Om en ny version kräver migrering ska den ligga som
`bin/migrera-vX.php` med torrkörning som standard.

---

## 6. Felsökning

| Symptom | Kontroll |
|---|---|
| "Lagringen är i återhämtningsläge" | `php bin/aterhamta.php` – en skrivning avbröts |
| 500 med referens-id | `storage/logg/` eller PHP-felloggen, sök på request-id |
| Kan inte logga in | Kontot avstängt? `php bin/visa-konton.php` (fas 2) eller läs `storage/data/anvandare.json` |
| Kameran startar inte | HTTPS aktivt? iOS Safari kräver HTTPS för `getUserMedia` |
| Etiketterna blir fel i storlek | Skriv ut utan "anpassa till sida", 100 % skala, A4 |
| Långsam app | `storage/data/*.json` växer; arkivera historik äldre än 2 år till egen fil (fas 2) |

---

## 7. Vad som inte ska göras

- Redigera `storage/data/*.json` manuellt. Historikens hashkedja upptäcker det, men
  saldon kan bli fel. Använd appen eller `bin/`-skripten.
- Kopiera en installation genom att kopiera `storage/` medan appen används. Kör backup först.
- Sätta `storage/` i en synkad mapp (Dropbox, OneDrive) – fillås fungerar inte där.
- Köra webbversionen och skrivbordsappen mot samma datakatalog samtidigt.

---

## 8. Artikel-QR i drift

Artikel-QR-etiketterna innehåller en fullständig adress och en signerad kontrollsumma.
Två saker måste stämma på servern:

```bash
# 1. Rätt adress (annars pekar etiketterna fel)
#    Sätt under Admin → System → Artikel-QR, eller:
echo '{"bas_url":"https://lager.doman.se"}' > storage/adress.json
chmod 600 storage/adress.json

# 2. Bilder
du -sh storage/bilder                      # utrymme
find storage/bilder -type f | wc -l        # antal
chmod -R 700 storage/bilder
```

**Vid flytt till ny domän:** ändra adressen i adminpanelen och skriv ut nya etiketter.
De gamla etiketterna pekar på den gamla adressen.

**Vid misstanke om att en QR-kod spridits:** *System → Byt QR-nyckel*. Då slutar alla
tidigare utskrivna artikel-QR att fungera direkt – skriv ut nya.

Backupen tar med `storage/bilder` (se `bin/backup.php`). Kontrollera efter återläsning
att bilderna finns: `ls storage/bilder | wc -l`.

---

## 12. Innan första skarpa dagen – checklista

Kör igenom den här listan **en gång** innan personalen börjar använda systemet.
Uppskattad tid: 30–45 minuter.

### A. Adressen måste vara fast

1. Logga in som admin → **System**.
2. Fyll i **Appens adress** med företagets riktiga webbadress, t.ex.
   `https://lager.foretag.se`. Står det en varning om att adressen inte är
   fastställd, åtgärda det **först**.
3. Spara. Varningen ska försvinna.

> Utan fast adress skrivs QR-koden på etiketterna med den adress webbläsaren råkade
> använda just då. Ett vanligt fel som upptäcks först när hyllorna är märkta.

### B. Rensa demoinnehållet

Demolagret (skruv, mutter, bricka …) finns för att kunna prova. Ta bort det:

1. **Inventering** – avsluta eller annullera öppna sessioner.
2. **Artiklar** – inaktivera demoartiklarna (de kan inte raderas med saldo).
3. **Arbetsställen** – döp om eller stäng av dem som inte används.
4. **Konton** – skapa riktiga konton, stäng av testkontona.

### C. Egna uppgifter

1. **Varumärke** – företagsnamn, färger och egen logotyp.
2. **Arbetsställen** – alla riktiga arbetsställen med egna koder.
3. **System → Larm och säkerhet**:
   - miniminivålarm: på
   - tvåpersonersregel: sätt en gräns (helst på **värde**, inte antal)
   - backupgräns: 2 dygn
4. **Konton** – alla administratörer får ett eget lösenord. Kontrollera att
   ingen står som *"inget lösenord"*.

### D. Driften

1. Cron för `backup.php` – kontrollera dagen efter att raden på **Översikt** är grön.
2. `php bin/verifiera-historik.php` – kedjan ska vara intakt.
3. `php bin/backup.php` manuellt och **teståterläs** den:

   ```bash
   php bin/aterlas.php --fil=storage/backup/<senaste>.zip
   php bin/aterlas.php --fil=storage/backup/<senaste>.zip --till-testkatalog
   ```

   Torrkörningen ska säga *"Manifest och checksummor OK"* och listan ska omfatta
   datafiler, artikelbilder, konfiguration, **arkiv** och **inställningar**.

### E. Etiketter

1. Skriv ut **en** provetikett och skanna den med telefonen.
2. Sidan som öppnas ska visa artikeln med bild och rätt antal.
3. Skriv ut resten först när provet fungerar.

### F. Personalen

1. Visa inloggningen: personal = PIN, admin = lösenord + PIN.
2. Visa inventeringen på telefonen – det är den de kommer använda mest.
3. Berätta att admin-lösenordet måste bytas var 20:e dag.
