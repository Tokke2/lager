# Kravmatris – kravspecifikation mot nuläge och plan

> Historiskt planeringsunderlag: autentiseringsavsnitten är inaktuella. Aktuellt: [inloggning med PIN](dokument/09_inloggning-och-losenord.md).

**Statuskolumner:** `Nuläge` = vad som är känt idag. `Plan` = vad som ska byggas. Inget har verifierats i kod – arbetskopian är inte tillgänglig i den här sessionen (se `00_start-har.md`).

Teckenförklaring: ✅ klart · 🟡 delvis/finns som referens · ❌ saknas · ⬜ ej verifierat

---

## A. Artikel och saldo

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| A1 | Artikel med namn, artikelnummer, enhet, prisreferens, miniminivå | ⬜ (äldre vy finns enligt bakgrund) | `artiklar.json` enligt modellen | ART-01 |
| A2 | Saldohanteringssätt per artikel (`quantity` eller `qr_units`) | ⬜ | Låst fält `tracking_mode` | ART-02 |
| A3 | Separata saldon per arbetsställe | ❌ (äldre = aggregerat) | `saldo: {plats: antal}` | ART-03 |
| A4 | Totalen får inte dölja var lagret finns | 🟡 | Översikt per plats, total endast som sekundär siffra | ART-03 |
| A5 | QR-spårning kan inte stängas av med enhetshistorik | ❌ | `tracking_mode` låses när historik finns | ART-02 |
| A6 | Artikel med saldo/historik kan inte raderas utan särskild hantering | ❌ | `aktiv: false` + ledger | ART-04 |

## B. Arbetsställen

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| B1 | Beständigt ID per arbetsställe | 🟡 (Örebro/Laxå föreslagna) | `LOC-…` | PLATS-01 |
| B2 | Arbetsplats-QR väljer plats, flyttar inget lager | ❌ | QR `TDL-L-…` + val i gränssnittet | PLATS-02 |
| B3 | Systempost för äldre ofördelat saldo | 🟡 | `SYSTEM_LEGACY` | MIG-01 |
| B4 | Admin kan lägga till/ändra/stänga plats | ❌ | Admin-route + ledger | PLATS-03 |
| B5 | Stängning blockeras vid saldo, aktiva/väntande enheter eller öppen session | ❌ | Serversidekontroll | PLATS-04 |

## C. Lagerhändelser

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| C1 | Inleverans registrerar plats; saldo ändras först efter validering | ❌ | Full validering före skrivning | INV-01 |
| C2 | Uttag stoppas vid fel plats eller otillräckligt saldo, utan deländring | ❌ | Saldo per plats + allt-eller-inget | INV-02 |
| C3 | Överföring mellan två aktiva platser, enhets-ID bevaras | ❌ | Två-plats-transaktion | ÖVR-01 |
| C4 | Käll- och målsaldo + historik uppdateras tillsammans | ❌ | Journal + atomiskt byte | ÖVR-02 |
| C5 | Skanning kontrollerar status, artikel, aktuell plats | ❌ | Valideringsregel 5 | SKAN-01 |
| C6 | Samma kod räknas inte två gånger i samma session | ❌ | `raknade_enheter[]` | SKAN-02 |

## D. Inventering och avstämning

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| D1 | Session har eget ID, starttid, ansvarig | ❌ | `sessioner.json` | INV-03 |
| D2 | QR-inventering sparar förväntade enheter vid start | ❌ | `forvantade_enheter[]` | INV-03 |
| D3 | Differens visas före avstämning | 🟡 (äldre fas-1-logik) | Läsbar diff-vy | INV-04 |
| D4 | Avstämning kräver uttrycklig behörig åtgärd + historik | 🟡 | Admin + bekräftelse + ledger | INV-05 |
| D5 | Saknade enheter bokförs som uttagna; okända läggs inte till | 🟡 | Enligt äldre fas-1-logik | INV-06 |

## E. Etiketter och historik

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| E1 | Skapa/skriva ut enhets- och platsetiketter | ❌ | HTML/SVG-generator | ETIK-01 |
| E2 | Etiketter som väntande inleverans eller mot befintligt saldo | ❌ | `pending` → `active` | ETIK-02 |
| E3 | Historik visar tid, ansvarig, artikel, plats, typ, mängd, enhets-ID | ❌ | Ledger + vy | HIST-01 |
| E4 | Historik kan inte redigeras i användarvyn | ❌ | Append-only + hashkedja | HIST-02 |

## F. Roller och konton

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| F1 | Admin hanterar konton, roller, artiklar, platser, adminåtgärder | ❌ | Roll per route | AUTH-01 |
| F2 | Personal utför dagliga åtgärder men inte admin | ❌ | Nekas på servern | AUTH-02 |
| F3 | Första admin via engångsflöde innan konton finns | ❌ | `bin/installera.php` | AUTH-03 |
| F4 | Säker lösenordshashning | ❌ | `password_hash` | AUTH-04 |
| F5 | Reset/avstängning ogiltigförklarar äldre sessioner | ❌ | `session_epoch` | AUTH-05 |
| F6 | Behörighet kontrolleras på serversidan per skrivande åtgärd | ❌ | Middleware + controller | AUTH-02 |

## G. Teknik och data

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| G1 | PHP + JSON i fas 1, ingen databas | 🟡 (liten tom lager-JSON finns) | Enligt arkitekturdokumentet | DATA-01 |
| G2 | JSON i privat lagring, ej direkt nåbar via URL | 🟡 (äldre data syns i portalstruktur) | `storage/` utanför docroot + spärr | SÄK-01–06 |
| G3 | All indata valideras på servern | ❌ | Validator-lager | DATA-02 |
| G4 | Samtidiga skrivningar: lås + säkra filersättningar | ❌ | flock + journal + rename | DATA-03 |
| G5 | Backup och återställning provas med testdata | ❌ | `backup.php` / `aterlas.php` | BAK-01–02 |
| G6 | Äldre `update_stock()` används inte som lagermotor | ⚠️ finns kvar i portalen | Lämnas orörd; ersätts helt | GRÄNS-01 |

## H. Säkerhet och avgränsning

| # | Krav | Nuläge | Plan | Test |
|---|---|---|---|---|
| H1 | Proxy/hotells åtkomstskydd omfattar hela appen och privata filer | ⬜ | Verifieras i staging | SÄK-01–06 |
| H2 | Egen inloggning, rollkontroll, CSRF, säkra sessionsinställningar | ❌ | Enligt avsnitt 8 | AUTH-06 |
| H3 | Kamera först efter knapptryck, endast HTTPS | ❌ | Lazy scanner.js | KAM-01 |
| H4 | Manuell kod alltid möjlig | ❌ | Alltid synligt fält | KAM-02 |
| H5 | Loggar utan lösenord/tokens/personuppgifter | ❌ | Fast händelselista | SÄK-07 |
| H6 | Ingen data flyttas/raderas före säkerhetskopia + verifierad migrering | ⬜ | Migrering endast efter skriftligt ok | MIG-01–03 |

## I. Utanför första fasen (ingen åtgärd nu)

Offlinekö/autosynk · PWA · databas/externt API/portalintegration · avancerade rapporter och dashboards · ändringar i portalens äldre lagerfunktioner.

---

## Migrering av äldre lagerfiler (kräver filerna)

| # | Steg | Regel |
|---|---|---|
| MIG-01 | Identifiera källor | Endast kopior; hash noteras |
| MIG-02 | Mappa till ny modell | Aggregerat saldo → `SYSTEM_LEGACY` om plats okänd; det framgår av ledgern att det är migrerat |
| MIG-03 | Torrkörning | `migrera-aldre-lager.php --torrkorning` skriver rapport utan att ändra något |
| MIG-04 | Skarp import | Först efter din skriftliga bekräftelse; ledger-post `migrering_import` per rad |
| MIG-05 | Verifiering | Antal poster och summor jämförs tvåvägs mot källan |

---

## Sammanfattning

- Av ~40 krav är **0 verifierade** i den här sessionen eftersom arbetskopian saknas.
- Enligt bakgrunden är endast referensmaterial känt: äldre aggregerad `update_stock()`, två visningsfiler, en tom lager-JSON, äldre hjälpreferenser.
- Mönstret i nuläget är alltså: **aggregerat saldo utan platsdimension, ingen enhetsmodell, ingen session, ingen full historik** – vilket stämmer med slutsatsen att en ny lagermotor måste byggas.
