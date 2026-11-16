# Testplan – Tadega Lager (fas 1)

Testplanen är skriven för att kunna köras **innan** appen är byggd: varje test beskriver förväntat beteende, så att implementationen kan testas mot kontraktet i stället för tvärtom.

**Miljöer:** `dev` (lokal kopia) → `staging` (HTTPS, samma PHP-version och proxy som drift) → `drift`.
**Ingen testning sker mot portalens data.** Migreringstester körs mot kopior i `/tmp` eller `storage-test/`.

**Tecken:** 🧪 funktionellt · 🛡️ säkerhet · 🔁 data/integritet

---

## 0. Godkännandetest (GT) – de tolv från kravspecen

| GT | Krav | Test |
|---|---|---|
| GT-01 | Samma artikel har oberoende saldo på två platser | `ART-03`: leverera 10 st till Örebro → saldo Örebro 10, Laxå 0. Leverera 4 till Laxå → 10 / 4. Uttag 10 från Örebro lyckas, Laxå orörd. |
| GT-02 | QR-enhet behåller ID vid överföring | `ÖVR-01`: skapa enhet, överför Örebro→Laxå, samma `id` i både ledger och `enheter.json`. |
| GT-03 | Inleverans/uttag ändrar endast rätt saldo + historik | `INV-01`, `INV-02`: verifiera alla övriga platser oförändrade och exakt en ledger-post per händelse. |
| GT-04 | Fel plats och dubblettskanning stoppas utan saldoändring | `INV-02`, `SKAN-02`: skanna samma kod två gånger → andra svaret "redan räknad", inget saldo rört. |
| GT-05 | Överföring ändrar käll- och målsaldo sammanhängande | `ÖVR-02`: simulera krasch efter första filskrivningen, kör `aterhamta.php`, verifiera att båda saldon stämmer. |
| GT-06 | Inventering visar differens före avstämning + kräver bekräftelse | `INV-04`, `INV-05`. |
| GT-07 | Lagerpersonal kan inte utföra adminåtgärder | `AUTH-02`: anropa admin-endpoints direkt med personal-session → 403, inget skrivet. |
| GT-08 | Reset/inaktivering ogiltigförklarar gamla sessioner | `AUTH-05`: logga in på två enheter, återställ lösenord → båda sessionerna nekas nästa request. |
| GT-09 | Direkt åtkomst till privata datafiler nekas | `SÄK-01`–`SÄK-06`: `GET /storage/data/artiklar.json`, `/app/…`, `/bin/…`, `/.git/…` → 403/404. |
| GT-10 | Backup kan återläsas utan att saldon, ID:n eller historik förvanskas | `BAK-01`, `BAK-02`. |
| GT-11 | Kamera och utskrift fungerar i HTTPS-staging på riktiga enheter | `KAM-01`, `ETIK-01` (iOS Safari, Android Chrome, skrivare). |
| GT-12 | Appen fungerar utan offlinekö/autosynk | `DATA-01`: flygplansläge → tydligt fel, inget köat, inget "tyst" sparat. |

---

## 1. Artiklar och saldo (ART)

| ID | Test | Förväntat |
|---|---|---|
| ART-01 | Skapa artikel med obligatoriska fält; testa tomt namn, negativt pris | 400 med fältfel, inget sparat |
| ART-02 | Sätt `tracking_mode: qr_units`, skapa enhet, försök byta till `quantity` | Nekas med förklaring; ingen ändring |
| ART-03 | Oberoende saldon per plats | Se GT-01 |
| ART-04 | Radera artikel med historik | Nekas; erbjud `inaktivera` → ledger-post |

## 2. Arbetsställen (PLATS)

| ID | Test | Förväntat |
|---|---|---|
| PLATS-01 | Två platser har egna ID:n över omstart och backup/återläsning | ID:n oförändrade |
| PLATS-02 | Skanna arbetsplats-QR | Platsen blir vald i gränssnittet; ingen post i ledger, inget saldo ändrat |
| PLATS-03 | Admin skapar/ändrar/stänger plats | Lyckas, ledger-post per åtgärd |
| PLATS-04 | Stäng plats med saldo / väntande enhet / öppen session | Nekas med förklaring i alla tre fallen |

## 3. Inleverans, uttag, överföring (INV/ÖVR)

| ID | Test | Förväntat |
|---|---|---|
| INV-01 | Inleverans 10 st till Örebro | Saldo 10, ledger-post `inleverans`, request-id loggat |
| INV-02 | Uttag 11 st från Örebro (saldo 10) | Nekas, saldo 10, ingen ledger-post |
| INV-02b | Uttag från fel plats (Laxå, saldo 0) | Nekas, oförändrat |
| INV-03 | Starta inventering | Session med ID, tid, ansvarig; förväntade enheter frysta |
| INV-04 | Skanna in 3 av 5 förväntade enheter | Vy visar 5 förväntat / 3 räknat / differens −2; inget saldo rört |
| INV-05 | Avstämning som personal | 403. Som admin utan bekräftelse: kräver explicit confirm. Med bekräftelse: ledger-poster |
| INV-06 | Okänd enhet skannas i inventeringen | Kan visas som "okänd", läggs **inte** till automatiskt |
| ÖVR-01 | Överför 1 st enhet Örebro→Laxå | Samma enhets-ID, båda saldon uppdaterade |
| ÖVR-02 | Kraschsimulering mitt i överföring (`kill -9` mellan filbyten) | Efter `aterhamta.php`: båda saldon konsekventa, exakt en ledger-post |

## 4. Skanning (SKAN)

| ID | Test | Förväntat |
|---|---|---|
| SKAN-01 | Skanna enhet som redan är `retired` eller ligger på annan plats | Tydligt fel; ingen ändring |
| SKAN-02 | Samma kod två gånger i samma session | Andra gången avvisas, räknat antal oförändrat |
| SKAN-03 | Manuellt inmatad kod med fel kontrolltecken | Avvisas innan uppslag |

## 5. Etiketter (ETIK)

| ID | Test | Förväntat |
|---|---|---|
| ETIK-01 | Skriv ut A4-ark med 10 enhetsetiketter + 1 platsetikett | Alla QR skanningsbara; platsetiketten säger att den inte flyttar lager |
| ETIK-02 | Väntande etikett → inleverans aktiverar enheten | Status `pending` → `active`, ledger `enhet_aktiverad` |
| ETIK-03 | Skriv ut på rulle 62 × 29 (QL-500) | PDF-sidans mått är 62 × 29 mm; allt innehåll inom 60,9 mm bredd; QR skanningsbar |
| ETIK-04 | Skriv ut på smal rulle 29 × 62 | Etiketten blir 29 mm bred (inte 60,9), inget innehåll utanför kanten |
| ETIK-05 | Byt etikettstorlek på en artikel, skriv ut igen | Utskriften förväljer artikelns storlek; eget mått i adressfältet vinner |
| ETIK-06 | Sätt ett orimligt mått (t.ex. `999x999`) | Sparas inte – standardrullen 62 × 29 används |
| ETIK-07 | Skriv ut flera etiketter på rulle | En etikett per sida, ingen avklippt etikett mitt i en sida |
| ETIK-08 | Byt skrivare i System till Dymo och skriv en 62 mm-etikett | Etiketten skrivs 56 mm bred, inget klipps, ingen varning |
| ETIK-09 | Välj en 101 × 152-etikett med Dymo vald | Röd varning: etiketten är bredare än skrivaren klarar |
| ETIK-10 | Välj Zebra 4 tum och skriv en 101 × 152-fraktetikett | Etiketten skrivs i full bredd, QR 50 mm |

## 6. Historik (HIST)

| ID | Test | Förväntat |
|---|---|---|
| HIST-01 | Historikvy visar tid, ansvarig, artikel, plats, typ, mängd, enhets-ID | Allt ifyllt, sorterat nyast först |
| HIST-02 | Ändra en rad i `historik.json` manuellt, kör `verifiera-historik.php` | Kedjan bryts vid rätt post; skillnad rapporteras |

## 7. Roller och konton (AUTH)

| ID | Test | Förväntat |
|---|---|---|
| AUTH-01 | Admin når alla admin-routes | 200 |
| AUTH-02 | Personal når `/konto/*`, `/platser/ny`, `/artiklar/ny`, avstämning | 403, inget skrivet, loggat som nekad |
| AUTH-03 | `bin/installera.php` när konton finns | Vägrar, exitkod ≠ 0 |
| AUTH-04 | Kontroll av lagrad hash | `password_verify` OK; ingen klartext i fil, logg eller svar |
| AUTH-05 | Lösenordsbyte/avstängning | `session_epoch` höjs; gamla sessioner 403 |
| AUTH-06 | POST utan CSRF-token / fel Origin | 403 |

## 8. Data och migrering (DATA/MIG)

| ID | Test | Förväntat |
|---|---|---|
| DATA-01 | Nätet bryts mitt i uttag | Ett tydligt fel, inget "köat", ingen partiell post |
| DATA-02 | Skicka ogiltiga typer i alla fält (sträng som antal, array som ID) | 400 i alla fall, inget skrivet |
| DATA-03 | Två samtidiga uttag av sista enheten | En lyckas, en nekas – aldrig negativt saldo |
| MIG-01 | `migrera-aldre-lager.php --torrkorning` mot kopior | Rapport utan skrivning; diff mot källa tvåvägs |
| MIG-02 | Import efter skriftligt ok | Ledger `migrering_import` per rad, summor stämmer |
| MIG-03 | Portalens filer efter migrering | Oförändrade (hash före/efter identisk) |

## 9. Säkerhet (SÄK)

| ID | Test | Förväntat |
|---|---|---|
| SÄK-01 | `GET /storage/data/*.json` | 403 |
| SÄK-02 | `GET /app/bootstrap.php`, `/bin/backup.php`, `/tests/…` | 403 |
| SÄK-03 | `GET /.git/config`, `/.htaccess`, `/storage/journal/…` | 403/404 |
| SÄK-04 | Path traversal `?fil=../../storage/data/konton.json` | Nekas |
| SÄK-05 | `X-Forwarded-For`-spoof, host header-bytest | Ingen effekt på behörighet/logg |
| SÄK-06 | Kataloglistning på `/storage/` | Ingen listing |
| SÄK-07 | Loggar efter 20 blandade händelser | Inga lösenord, tokens eller personuppgifter; alla med request-id |

## 10. Kamera (KAM)

| ID | Test | Förväntat |
|---|---|---|
| KAM-01 | Ingen kameraförfrågan förrän skanningsknappen trycks; endast under HTTPS | Ingen prompt innan klick; tydligt fel över HTTP |
| KAM-02 | Neka kamerabehörighet | Manuellt fält fungerar fullt ut |

## 11. Backup (BAK)

| ID | Test | Förväntat |
|---|---|---|
| BAK-01 | Kör backup, verifiera manifest (SHA-256 + antal poster) | Alla matchar |
| BAK-02 | Återläs till tom testkatalog, jämför saldon, enhets-ID:n, ledger | Identiskt; `verifiera-historik.php` OK |

## 12. Gränsdragning mot portalen (GRÄNS)

| ID | Test | Förväntat |
|---|---|---|
| GRÄNS-01 | Sök i ny app efter anrop till portalens `update_stock()` | Inga |
| GRÄNS-02 | Portalen efter att lagerappen byggts | Oförändrad funktion; inga nya lagerflikar |

---

## Genomförandeordning

1. `DATA-01`, `DATA-02`, `SÄK-01`–`SÄK-06` – kan köras så snart ett tomt skelett finns (de skyddar allt annat).
2. `AUTH-01`–`AUTH-06` – innan någon affärslogik byggs.
3. `ART`, `PLATS`, `INV`, `ÖVR`, `SKAN`, `HIST`.
4. `ETIK`, `KAM`, `BAK`, `MIG` – sist, i staging och på riktiga enheter.

Varje grönt test noteras med datum, miljö, commit/testversion och request-id, så att godkännandet i staging är spårbart.
