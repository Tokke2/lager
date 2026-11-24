<?php
/**
 * Inventering och avstämning.
 *
 * MODELL (från 2026-11-12): en inventering gäller ETT ARBETSSTÄLLE och kan
 * omfatta flera artiklar i samma räkning. Tidigare gällde en session en enda
 * artikel, vilket gjorde att en hylla med 40 artiklar krävde 40 sessioner.
 *
 * Sessionens innehåll:
 *   plats_id            arbetsstället som inventeras
 *   artiklar            { <artikel-id>: { forvantat, raknat, raknade_enheter,
 *                                          okanda_enheter, uppdaterad } }
 *   status              öppen | avslutad | annullerad
 *
 * Regler som gäller oförändrat:
 * – Förväntat saldo fryses vid start (per artikel).
 * – Dubblettskanning ökar aldrig räknat antal.
 * – Differens visas alltid före avstämning. Avstämning kräver admin,
 *   uttrycklig bekräftelse och skapar historik per artikel.
 * – Saknade enheter bokförs som "saknad" vid avstämning. Okända enheter
 *   läggs ALDRIG till automatiskt.
 * – GAMLA sessioner (med artikel_id på toppnivå) läses fortfarande, så att
 *   pågående inventeringar kan avslutas efter uppdateringen.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Koder;
use TDL\Support\Json;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Inventering
{
    /* ================================================================
     *  Läsa
     * ================================================================ */

    public static function session(string $id): ?array
    {
        return Json::hitta(Json::las('sessioner'), $id);
    }

    public static function oppenSessionForPlats(string $platsId): ?array
    {
        foreach (Json::las('sessioner')['poster'] as $s) {
            if (($s['plats_id'] ?? '') === $platsId && ($s['status'] ?? '') === 'öppen') {
                return $s;
            }
        }
        return null;
    }

    /** @return list<array> */
    public static function sessioner(int $max = 50): array
    {
        $s = Json::las('sessioner')['poster'];
        usort($s, fn ($a, $b) => strcmp($b['startad'] ?? '', $a['startad'] ?? ''));
        return array_slice($s, 0, $max);
    }

    /** Senaste sessionerna samt alla äldre som fortfarande är öppna. */
    public static function sessionerMedOppna(int $max = 100): array
    {
        $alla = self::sessioner(PHP_INT_MAX);
        return array_values(array_filter($alla, static fn ($s, $i) =>
            $i < $max || ($s['status'] ?? '') === 'öppen', ARRAY_FILTER_USE_BOTH
        ));
    }

    /** Är sessionen av den gamla typen (en artikel per session)? */
    public static function arGammal(array $session): bool
    {
        return !isset($session['artiklar']) && isset($session['artikel_id']);
    }

    /**
     * Lista med artikel-id:n som ingår i sessionen.
     * Gamla sessioner har exakt en artikel.
     *
     * @return list<string>
     */
    public static function artikelIds(array $session): array
    {
        if (self::arGammal($session)) {
            return [(string) $session['artikel_id']];
        }
        return array_keys($session['artiklar'] ?? []);
    }

    /** Raden för en artikel i sessionen, eller null. */
    public static function rad(array $session, string $artikelId): ?array
    {
        if (self::arGammal($session)) {
            if ((string) $session['artikel_id'] !== $artikelId) {
                return null;
            }
            return [
                'forvantat' => (int) ($session['forvantat'] ?? $session['saldo_vid_start'] ?? 0),
                'raknat' => (int) ($session['raknat_antal'] ?? 0),
                'raknade_enheter' => $session['raknade_enheter'] ?? [],
                'okanda_enheter' => $session['okanda_enheter'] ?? [],
                'typ' => $session['typ'] ?? 'antal',
            ];
        }
        return $session['artiklar'][$artikelId] ?? null;
    }

    /**
     * Beräkna läget för HELA sessionen.
     *
     * @return array{
     *   forvantat:int, raknat:int, differens:int,
     *   rader:list<array>, saknade:list<array>, okanda:list<array>,
     *   raknade:list<array>, antal_artiklar:int, antal_raknade:int,
     *   antal_avvikelser:int, antal_kvar:int
     * }
     */
    public static function lage(array $session): array
    {
        $plats = Json::hitta(Json::las('platser'), $session['plats_id']);
        $allaArtiklar = Json::las('artiklar')['poster'];
        $enheter = Json::las('enheter')['poster'];

        $enhetsIndex = [];
        foreach ($enheter as $en) {
            $enhetsIndex[Koder::utanBindestreck($en['id'])] = $en;
        }

        $rader = [];
        $saknade = [];
        $okanda = [];
        $raknade = [];
        $summaForvantat = 0;
        $summaRaknat = 0;
        $antalRaknade = 0;
        $antalAvvikelser = 0;

        foreach (self::artikelIds($session) as $artikelId) {
            $artikel = null;
            foreach ($allaArtiklar as $a) {
                if ((string) $a['id'] === $artikelId) {
                    $artikel = $a;
                    break;
                }
            }
            if ($artikel === null) {
                continue;
            }

            $rad = self::rad($session, $artikelId) ?? [];
            $arQr = ($rad['typ'] ?? $session['typ'] ?? 'antal') === 'qr';

            $raknadeKoder = array_map(fn ($k) => Koder::utanBindestreck($k), $rad['raknade_enheter'] ?? []);
            $okandaKoder = array_map(fn ($k) => Koder::utanBindestreck($k), $rad['okanda_enheter'] ?? []);

            if ($arQr) {
                $forvantadeKoder = array_map(
                    fn ($k) => Koder::utanBindestreck($k),
                    $rad['forvantade_enheter'] ?? $session['forvantade_enheter'] ?? []
                );
                $artikelSaknade = [];
                foreach ($forvantadeKoder as $kod) {
                    if (!in_array($kod, $raknadeKoder, true)) {
                        $artikelSaknade[] = $enhetsIndex[$kod] ?? ['id' => $kod, 'status' => 'okänd'];
                    }
                }
                foreach ($artikelSaknade as $s) {
                    $saknade[] = $s + ['artikel_id' => $artikelId, 'artikelnamn' => $artikel['namn']];
                }
                $forvantat = count($forvantadeKoder);
                $raknat = count($raknadeKoder);
                $artikelRaknade = [];
                foreach ($raknadeKoder as $kod) {
                    if (isset($enhetsIndex[$kod])) {
                        $artikelRaknade[] = $enhetsIndex[$kod];
                    }
                }
                $raknade = array_merge($raknade, $artikelRaknade);
                // Räknad så snart minst en enhet skannats, eller raden markerats uppdaterad
                $arRaknad = $raknadeKoder !== [] || isset($rad['uppdaterad']);
            } else {
                $forvantat = (int) ($rad['forvantat'] ?? 0);
                $raknat = (int) ($rad['raknat'] ?? 0);
                $arRaknad = isset($rad['raknat_uppdaterad']);
            }

            foreach ($okandaKoder as $kod) {
                if (isset($enhetsIndex[$kod])) {
                    $okanda[] = $enhetsIndex[$kod] + ['artikel_id' => $artikelId, 'artikelnamn' => $artikel['namn']];
                }
            }

            $differens = $raknat - $forvantat;
            if ($arRaknad && $differens !== 0) {
                $antalAvvikelser++;
            }
            if ($arRaknad) {
                $antalRaknade++;
            }

            $summaForvantat += $forvantat;
            $summaRaknat += $raknat;

            $rader[] = [
                'artikel_id' => $artikelId,
                'artikelnummer' => $artikel['artikelnummer'],
                'namn' => $artikel['namn'],
                'enhet' => $artikel['enhet'] ?? 'st',
                'typ' => $arQr ? 'qr' : 'antal',
                'forvantat' => $forvantat,
                'raknat' => $raknat,
                // Vad räknefältet visar: räknat antal om raden är räknad, annars förväntat
                'visat' => $arRaknad ? $raknat : $forvantat,
                'differens' => $differens,
                'raknad' => $arRaknad,
                'uppdaterad' => $rad['raknat_uppdaterad'] ?? null,
            ];
        }

        // Sortera: oräknade först, därefter avvikelser, därefter i bokstavsordning
        usort($rader, function ($a, $b) {
            if ($a['raknad'] !== $b['raknad']) {
                return $a['raknad'] ? 1 : -1;      // oräknade först
            }
            $aAvvik = $a['raknad'] && $a['differens'] !== 0;
            $bAvvik = $b['raknad'] && $b['differens'] !== 0;
            if ($aAvvik !== $bAvvik) {
                return $aAvvik ? -1 : 1;           // avvikelser före ok
            }
            return strcmp($a['namn'], $b['namn']);
        });

        return [
            'forvantat' => $summaForvantat,
            'raknat' => $summaRaknat,
            'differens' => $summaRaknat - $summaForvantat,
            'rader' => $rader,
            'saknade' => $saknade,
            'okanda' => $okanda,
            'raknade' => $raknade,
            'antal_artiklar' => count($rader),
            'antal_raknade' => $antalRaknade,
            'antal_avvikelser' => $antalAvvikelser,
            'antal_kvar' => count($rader) - $antalRaknade,
        ];
    }

    /* ================================================================
     *  Start
     * ================================================================ */

    /**
     * Starta inventering för ett arbetsställe.
     * Alla aktiva artiklar som har saldo > 0 på platsen tas med automatiskt,
     * plus de artiklar som skickas in explicit (t.ex. nollsaldo man vill kolla).
     *
     * @param list<string> $extraArtikelIds
     */
    public static function startaInventering(string $platsId, array $extraArtikelIds, array $anvandare): array
    {
        return Las::under(function () use ($platsId, $extraArtikelIds, $anvandare) {
            $tx = new Transaktion('inventering_start', $anvandare);
            $plats = Rorelser::kravAktivPlats($tx, $platsId);
            if (self::oppenSessionForPlats($platsId) !== null) {
                throw new AppFel('Det finns redan en öppen inventering på ' . $plats['namn'] . '. Avsluta den först.');
            }

            $artiklar = $tx->fil('artiklar');
            $enheter = $tx->fil('enheter');

            // Automatiskt urval: artiklar med saldo > 0 på platsen
            $valda = [];
            foreach ($artiklar['poster'] as $a) {
                if (empty($a['aktiv'])) {
                    continue;
                }
                $saldo = (int) ($a['saldo'][$platsId] ?? 0);
                if ($a['sparningssatt'] === Lager::SPAR_QR) {
                    // QR-artikel: räkna om det finns aktiva enheter på platsen
                    $antalEnheter = 0;
                    foreach ($enheter['poster'] as $en) {
                        if (($en['artikel_id'] ?? '') === $a['id']
                            && ($en['plats_id'] ?? '') === $platsId
                            && ($en['status'] ?? '') === 'aktiv') {
                            $antalEnheter++;
                        }
                    }
                    if ($antalEnheter > 0) {
                        $valda[$a['id']] = true;
                    }
                } elseif ($saldo > 0) {
                    $valda[$a['id']] = true;
                }
            }
            foreach ($extraArtikelIds as $id) {
                $valda[$id] = true;
            }

            if ($valda === []) {
                throw new AppFel('Det finns inga artiklar med saldo på ' . $plats['namn'] . '. Lägg till en artikel manuellt om du vill räkna nollsaldo.');
            }

            $sessionArtiklar = [];
            $antalQr = 0;
            $antalAntal = 0;
            foreach (array_keys($valda) as $artikelId) {
                $artikel = Json::hitta($artiklar, $artikelId);
                if ($artikel === null || empty($artikel['aktiv'])) {
                    continue; // okänt eller inaktivt id hoppas över
                }
                if ($artikel['sparningssatt'] === Lager::SPAR_QR) {
                    $forvantade = [];
                    foreach ($enheter['poster'] as $en) {
                        if (($en['artikel_id'] ?? '') === $artikelId
                            && ($en['plats_id'] ?? '') === $platsId
                            && ($en['status'] ?? '') === 'aktiv') {
                            $forvantade[] = $en['id'];
                        }
                    }
                    $sessionArtiklar[$artikelId] = [
                        'typ' => 'qr',
                        'forvantat' => count($forvantade),
                        'forvantade_enheter' => array_values($forvantade),
                        'raknade_enheter' => [],
                        'okanda_enheter' => [],
                        'raknat' => 0,
                    ];
                    $antalQr++;
                } else {
                    $sessionArtiklar[$artikelId] = [
                        'typ' => 'antal',
                        'forvantat' => (int) ($artikel['saldo'][$platsId] ?? 0),
                        'raknade_enheter' => [],
                        'okanda_enheter' => [],
                        'raknat' => 0,
                    ];
                    $antalAntal++;
                }
            }

            if ($sessionArtiklar === []) {
                throw new AppFel('Inga giltiga artiklar att inventera på ' . $plats['namn'] . '.');
            }

            $session = [
                'id' => Koder::interntId('S', 6),
                'plats_id' => $platsId,
                'startad' => nu(),
                'startad_av' => $anvandare['id'] ?? null,
                'startad_av_namn' => $anvandare['namn'] ?? null,
                'status' => 'öppen',
                'artiklar' => $sessionArtiklar,
            ];

            $sessioner = $tx->fil('sessioner');
            Json::laggTill($sessioner, $session);
            $tx->sattFil('sessioner', $sessioner);
            $tx->historik([
                'typ' => 'inventering_start',
                'plats_id' => $platsId,
                'plats_namn' => $plats['namn'],
                'session_id' => $session['id'],
                'detaljer' => [
                    'artiklar' => count($sessionArtiklar),
                    'qr_artiklar' => $antalQr,
                    'antal_artiklar' => $antalAntal,
                ],
            ]);
            $tx->spara();
            return $session;
        });
    }

    /** Lägg till en artikel i en pågående inventering. */
    public static function laggTillArtikel(string $sessionId, string $artikelId, array $anvandare): void
    {
        Las::under(function () use ($sessionId, $artikelId, $anvandare) {
            $tx = new Transaktion('inventering_start', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är avslutad.');
            }
            if (self::arGammal($session)) {
                throw new AppFel('Den här inventeringen gäller bara en artikel och kan inte utökas.');
            }
            if (isset($session['artiklar'][$artikelId])) {
                throw new AppFel('Artikeln finns redan i inventeringen.');
            }
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null || empty($artikel['aktiv'])) {
                throw new AppFel('Artikeln finns inte eller är inaktiv.');
            }
            $platsId = $session['plats_id'];
            if ($artikel['sparningssatt'] === Lager::SPAR_QR) {
                $forvantade = [];
                foreach ($tx->fil('enheter')['poster'] as $en) {
                    if (($en['artikel_id'] ?? '') === $artikelId
                        && ($en['plats_id'] ?? '') === $platsId
                        && ($en['status'] ?? '') === 'aktiv') {
                        $forvantade[] = $en['id'];
                    }
                }
                $rad = [
                    'typ' => 'qr',
                    'forvantat' => count($forvantade),
                    'forvantade_enheter' => array_values($forvantade),
                    'raknade_enheter' => [],
                    'okanda_enheter' => [],
                    'raknat' => 0,
                ];
            } else {
                $rad = [
                    'typ' => 'antal',
                    'forvantat' => (int) ($artikel['saldo'][$platsId] ?? 0),
                    'raknade_enheter' => [],
                    'okanda_enheter' => [],
                    'raknat' => 0,
                ];
            }
            Json::uppdatera($sessioner, $sessionId, function ($s) use ($artikelId, $rad) {
                $s['artiklar'][$artikelId] = $rad;
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->spara();
        });
    }

    /** Ta bort en artikel ur en pågående inventering (räknade värden försvinner). */
    public static function taBortArtikel(string $sessionId, string $artikelId, array $anvandare): void
    {
        Las::under(function () use ($sessionId, $artikelId, $anvandare) {
            $tx = new Transaktion('inventering_start', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är avslutad.');
            }
            if (self::arGammal($session)) {
                throw new AppFel('Den här inventeringen gäller bara en artikel och kan inte ändras.');
            }
            Json::uppdatera($sessioner, $sessionId, function ($s) use ($artikelId) {
                unset($s['artiklar'][$artikelId]);
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->spara();
        });
    }

    /* ================================================================
     *  Räkna
     * ================================================================ */

    /**
     * Registrera en skanning. Enheten identifierar själv sin artikel, så
     * samma fält tar emot både enhetskoder och artikel-QR.
     *
     * @return array{resultat:string, meddelande:string, artikel_id:?string, rakent:int, differens:int}
     */
    public static function skanna(string $sessionId, string $kod, array $anvandare): array
    {
        return Las::under(function () use ($sessionId, $kod, $anvandare) {
            $tx = new Transaktion('inventering_skanning', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är avslutad.');
            }

            $ren = Koder::utanBindestreck($kod);

            // 1) Är det en enhetskod?
            $enhet = null;
            foreach ($tx->fil('enheter')['poster'] as $en) {
                if (Koder::utanBindestreck($en['id']) === $ren) {
                    $enhet = $en;
                    break;
                }
            }

            if ($enhet !== null) {
                return self::raknaEnhet($tx, $session, $enhet, $anvandare);
            }

            // 2) Är det ett artikel-id (artikel-QR)? Kräver att artikeln är med i sessionen.
            $artikelId = strtoupper($ren);
            if (isset($session['artiklar'][$artikelId])) {
                $rad = $session['artiklar'][$artikelId];
                if (($rad['typ'] ?? '') === 'antal') {
                    throw new AppFel('Artikeln räknas i antal – ange antalet i listan i stället.');
                }
                throw new AppFel('Artikeln är en QR-artikel – skanna någon av dess enhetskoder.');
            }

            throw new AppFel(
                'Koden ' . Koder::visa($kod) . ' känns inte igen. Okända enheter läggs inte till automatiskt.'
            );
        });
    }

    /** Räkna en enskild enhet i sessionen. */
    private static function raknaEnhet(Transaktion $tx, array $session, array $enhet, array $anvandare): array
    {
        $sessionId = $session['id'];
        $sessioner = $tx->fil('sessioner');
        $artikelId = (string) ($enhet['artikel_id'] ?? '');

        // Gammal session: artikeln måste matcha sessionens enda artikel
        if (self::arGammal($session)) {
            if ($artikelId !== (string) $session['artikel_id']) {
                throw new AppFel('Enheten tillhör en annan artikel och räknas inte i den här inventeringen.');
            }
            if (($enhet['plats_id'] ?? '') !== $session['plats_id'] || ($enhet['status'] ?? '') !== 'aktiv') {
                throw new AppFel('Enheten är registrerad men inte aktiv eller ligger på en annan plats. Den räknas som avvikelse.', 409);
            }
            $raknade = array_map(fn ($k) => Koder::utanBindestreck($k), $session['raknade_enheter'] ?? []);
            if (in_array(Koder::utanBindestreck($enhet['id']), $raknade, true)) {
                return ['resultat' => 'dubblett', 'meddelande' => 'Enheten är redan räknad.', 'artikel_id' => $artikelId, 'rakent' => count($raknade), 'differens' => 0];
            }
            $session['raknade_enheter'][] = $enhet['id'];
            Json::uppdatera($sessioner, $sessionId, fn ($s) => array_merge($s, ['raknade_enheter' => $session['raknade_enheter']]));
            $tx->sattFil('sessioner', $sessioner);
            $tx->spara();
            return ['resultat' => 'raknad', 'meddelande' => 'Enhet ' . Koder::visa($enhet['id']) . ' räknad.', 'artikel_id' => $artikelId, 'rakent' => count($session['raknade_enheter']), 'differens' => 0];
        }

        if (!isset($session['artiklar'][$artikelId])) {
            throw new AppFel('Enheten tillhör en artikel som inte ingår i den här inventeringen. Lägg till artikeln först.');
        }
        $rad = $session['artiklar'][$artikelId];
        if (($rad['typ'] ?? '') !== 'qr') {
            throw new AppFel('Artikeln räknas i antal – ange antalet i listan i stället.');
        }

        $raknadeKoder = array_map(fn ($k) => Koder::utanBindestreck($k), $rad['raknade_enheter'] ?? []);
        $okandaKoder = array_map(fn ($k) => Koder::utanBindestreck($k), $rad['okanda_enheter'] ?? []);

        if (in_array(Koder::utanBindestreck($enhet['id']), $raknadeKoder, true)) {
            return [
                'resultat' => 'dubblett',
                'meddelande' => 'Enheten är redan räknad i den här inventeringen. Räknat antal ändras inte.',
                'artikel_id' => $artikelId,
                'rakent' => count($raknadeKoder),
                'differens' => count($raknadeKoder) - (int) $rad['forvantat'],
            ];
        }

        if (($enhet['plats_id'] ?? '') !== $session['plats_id'] || ($enhet['status'] ?? '') !== 'aktiv') {
            // Registrera som avvikelse men räkna den inte
            if (!in_array(Koder::utanBindestreck($enhet['id']), $okandaKoder, true)) {
                $okandaKoder[] = Koder::utanBindestreck($enhet['id']);
            }
            Json::uppdatera($sessioner, $sessionId, function ($s) use ($artikelId, $okandaKoder) {
                $s['artiklar'][$artikelId]['okanda_enheter'] = $okandaKoder;
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->spara();
            $orsak = ($enhet['status'] ?? '') !== 'aktiv'
                ? 'inte aktiv (status: ' . $enhet['status'] . ')'
                : 'ligger på en annan plats';
            throw new AppFel(
                'Enheten är registrerad men ' . $orsak . '. Den räknas som avvikelse och påverkar inte inventeringen.',
                409
            );
        }

        $raknadeKoder[] = Koder::utanBindestreck($enhet['id']);
        Json::uppdatera($sessioner, $sessionId, function ($s) use ($artikelId, $raknadeKoder) {
            $s['artiklar'][$artikelId]['raknade_enheter'] = $raknadeKoder;
            $s['artiklar'][$artikelId]['uppdaterad'] = nu();
            return $s;
        });
        $tx->sattFil('sessioner', $sessioner);
        $tx->spara();

        return [
            'resultat' => 'raknad',
            'meddelande' => 'Enhet ' . Koder::visa($enhet['id']) . ' räknad.',
            'artikel_id' => $artikelId,
            'rakent' => count($raknadeKoder),
            'differens' => count($raknadeKoder) - (int) $rad['forvantat'],
        ];
    }

    /** Sätt räknat antal för en antalsartikel i sessionen. */
    public static function sattRaknatAntal(string $sessionId, string $artikelId, int $antal, array $anvandare): array
    {
        if ($antal < 0) {
            throw new AppFel('Antalet kan inte vara negativt.');
        }
        return Las::under(function () use ($sessionId, $artikelId, $antal, $anvandare) {
            $tx = new Transaktion('inventering_skanning', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är avslutad.');
            }

            if (self::arGammal($session)) {
                if ((string) $session['artikel_id'] !== $artikelId) {
                    throw new AppFel('Artikeln ingår inte i inventeringen.');
                }
                if (($session['typ'] ?? '') !== 'antal') {
                    throw new AppFel('Det här är en QR-inventering – skanna enheterna i stället.');
                }
                Json::uppdatera($sessioner, $sessionId, function ($s) use ($antal) {
                    $s['raknat_antal'] = $antal;
                    $s['raknat_uppdaterad'] = nu();
                    return $s;
                });
                $tx->sattFil('sessioner', $sessioner);
                $tx->spara();
                $forvantat = (int) ($session['forvantat'] ?? $session['saldo_vid_start'] ?? 0);
                return ['artikel_id' => $artikelId, 'rakent' => $antal, 'differens' => $antal - $forvantat];
            }

            if (!isset($session['artiklar'][$artikelId])) {
                throw new AppFel('Artikeln ingår inte i inventeringen.');
            }
            $rad = $session['artiklar'][$artikelId];
            if (($rad['typ'] ?? '') !== 'antal') {
                throw new AppFel('Det här är en QR-artikel – skanna enheterna i stället.');
            }
            Json::uppdatera($sessioner, $sessionId, function ($s) use ($artikelId, $antal) {
                $s['artiklar'][$artikelId]['raknat'] = $antal;
                $s['artiklar'][$artikelId]['raknat_uppdaterad'] = nu();
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->spara();
            return [
                'artikel_id' => $artikelId,
                'rakent' => $antal,
                'differens' => $antal - (int) $rad['forvantat'],
            ];
        });
    }

    /** Öka/minska räknat antal med ett steg (för +/− i listan). */
    public static function justeraAntal(string $sessionId, string $artikelId, int $steg, array $anvandare): array
    {
        return Las::under(function () use ($sessionId, $artikelId, $steg, $anvandare) {
            $session = Json::hitta(Json::las('sessioner'), $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            $rad = self::rad($session, $artikelId);
            if ($rad === null) {
                throw new AppFel('Artikeln ingår inte i inventeringen.');
            }
            // Innan raden är räknad utgår man från det förväntade antalet – det är
            // snabbare att justera nedåt när något saknas än att skriva av allt.
            $arRaknad = isset($rad['raknat_uppdaterad']);
            $nuvarande = ($rad['typ'] ?? '') === 'qr'
                ? count($rad['raknade_enheter'] ?? [])
                : ($arRaknad ? (int) ($rad['raknat'] ?? 0) : (int) ($rad['forvantat'] ?? 0));
            $nytt = max(0, $nuvarande + $steg);
            return self::sattRaknatAntal($sessionId, $artikelId, $nytt, $anvandare);
        });
    }

    /* ================================================================
     *  Avslut och avstämning
     * ================================================================ */

    /**
     * Avsluta inventeringen utan avstämning. Differensen kvarstår som
     * dokumenterad avvikelse – inget saldo rörs.
     */
    public static function avslutaUtanAvstamning(string $sessionId, array $anvandare, string $motivering): void
    {
        $motivering = trim($motivering);
        if (mb_strlen($motivering) < 3) {
            throw new AppFel('Ange en kort motivering (minst 3 tecken).');
        }
        Las::under(function () use ($sessionId, $anvandare, $motivering) {
            $tx = new Transaktion('inventering_avslut', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är redan avslutad.');
            }
            $lage = self::lage($session);
            Json::uppdatera($sessioner, $sessionId, function ($s) use ($anvandare, $motivering, $lage) {
                $s['status'] = 'avslutad';
                $s['avslutad'] = nu();
                $s['avslutad_av'] = $anvandare['id'] ?? null;
                $s['avslutad_av_namn'] = $anvandare['namn'] ?? null;
                $s['avstamning'] = [
                    'typ' => 'ingen',
                    'motivering' => $motivering,
                    'differens' => $lage['differens'],
                    'artiklar' => $lage['antal_artiklar'],
                ];
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->historik([
                'typ' => 'inventering_avslut',
                'plats_id' => $session['plats_id'],
                'session_id' => $sessionId,
                'detaljer' => [
                    'avstamning' => 'ingen',
                    'motivering' => $motivering,
                    'artiklar' => $lage['antal_artiklar'],
                    'raknade' => $lage['antal_raknade'],
                    'differens' => $lage['differens'],
                ],
            ]);
            $tx->spara();
            self::arkivera($sessionId, $anvandare);
        });
    }

    /**
     * Stäm av inventeringen. Kräver admin och uttrycklig bekräftelse.
     * Varje artikel med avvikelse bokförs för sig i historiken.
     *
     * @param list<string>|null $valdaArtikelIds null = alla med avvikelse
     */
    public static function stammaAv(string $sessionId, string $bekraftelse, array $anvandare, ?array $valdaArtikelIds = null): array
    {
        if (($anvandare['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer får stämma av en inventering.', 403);
        }
        if (trim(mb_strtolower($bekraftelse)) !== 'stam av') {
            throw new AppFel('Skriv "stam av" i bekräftelsefältet för att genomföra avstämningen.');
        }

        return Las::under(function () use ($sessionId, $anvandare, $valdaArtikelIds) {
            $tx = new Transaktion('avstamning_uttag', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är redan avslutad.');
            }

            $lage = self::lage($session);
            $plats = Json::hitta($tx->fil('platser'), $session['plats_id']);

            // Artiklar som ska stämmas av
            $attJusteras = [];
            foreach ($lage['rader'] as $rad) {
                if ($rad['differens'] === 0 || !$rad['raknad']) {
                    continue;
                }
                if ($valdaArtikelIds !== null && !in_array($rad['artikel_id'], $valdaArtikelIds, true)) {
                    continue;
                }
                $attJusteras[] = $rad;
            }

            if ($attJusteras === []) {
                throw new AppFel('Inga avvikelser att stämma av. Avsluta inventeringen i stället.');
            }

            $artiklarFil = $tx->fil('artiklar');
            $enheterFil = $tx->fil('enheter');
            $totaltJusterat = 0;
            $antalPoster = 0;

            foreach ($attJusteras as $rad) {
                $artikelId = $rad['artikel_id'];
                $artikel = Json::hitta($artiklarFil, $artikelId);
                if ($artikel === null) {
                    continue;
                }
                $sessionRad = self::rad($session, $artikelId) ?? [];
                $justerat = 0;

                if (($rad['typ'] ?? '') === 'qr') {
                    if ($rad['differens'] > 0) {
                        // Fler enheter än förväntat – de finns redan som aktiva på platsen.
                        // Inget att bokföra, men noteras.
                        continue;
                    }
                    // Saknade enheter: markera dem som saknad
                    $raknadeKoder = array_map(fn ($k) => Koder::utanBindestreck($k), $sessionRad['raknade_enheter'] ?? []);
                    $forvantadeKoder = array_map(fn ($k) => Koder::utanBindestreck($k), $sessionRad['forvantade_enheter'] ?? []);
                    foreach ($forvantadeKoder as $kod) {
                        if (in_array($kod, $raknadeKoder, true)) {
                            continue;
                        }
                        foreach ($enheterFil['poster'] as $i => $en) {
                            if (Koder::utanBindestreck($en['id']) === $kod) {
                                $enheterFil['poster'][$i]['status'] = 'saknad';
                                $enheterFil['poster'][$i]['saknad_sedan'] = nu();
                                $enheterFil['poster'][$i]['inventering_id'] = $sessionId;
                                $justerat++;
                                break;
                            }
                        }
                    }
                } else {
                    // Antalsartikel: sätt saldot till räknat
                    $saldoNu = (int) ($artikel['saldo'][$session['plats_id']] ?? 0);
                    $nytt = (int) $rad['raknat'];
                    if ($saldoNu === $nytt) {
                        continue;
                    }
                    foreach ($artiklarFil['poster'] as $i => $a) {
                        if ((string) $a['id'] === $artikelId) {
                            $artiklarFil['poster'][$i]['saldo'][$session['plats_id']] = $nytt;
                            $artiklarFil['poster'][$i]['andrad'] = nu();
                            break;
                        }
                    }
                    $justerat = abs($nytt - $saldoNu);
                }

                if ($justerat === 0) {
                    continue;
                }

                $totaltJusterat += $justerat;
                $antalPoster++;
                $tx->historik([
                    'typ' => 'avstamning_uttag',
                    'artikel_id' => $artikelId,
                    'artikelnummer' => $artikel['artikelnummer'],
                    'artikelnamn' => $artikel['namn'],
                    'plats_id' => $session['plats_id'],
                    'plats_namn' => $plats['namn'] ?? '',
                    'antal' => $justerat,
                    'session_id' => $sessionId,
                    'detaljer' => [
                        'forvantat' => $rad['forvantat'],
                        'raknat' => $rad['raknat'],
                        'differens' => $rad['differens'],
                        'inventering_typ' => $rad['typ'],
                    ],
                ]);
            }

            $tx->sattFil('artiklar', $artiklarFil);
            $tx->sattFil('enheter', $enheterFil);

            Json::uppdatera($sessioner, $sessionId, function ($s) use ($anvandare, $lage, $totaltJusterat, $antalPoster) {
                $s['status'] = 'avslutad';
                $s['avslutad'] = nu();
                $s['avslutad_av'] = $anvandare['id'] ?? null;
                $s['avslutad_av_namn'] = $anvandare['namn'] ?? null;
                $s['avstamning'] = [
                    'typ' => 'uttag',
                    'avstalld_av' => $anvandare['id'] ?? null,
                    'avstalld_av_namn' => $anvandare['namn'] ?? null,
                    'tid' => nu(),
                    'artiklar' => $antalPoster,
                    'justerade' => $totaltJusterat,
                ];
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->historik([
                'typ' => 'inventering_avslut',
                'plats_id' => $session['plats_id'],
                'session_id' => $sessionId,
                'detaljer' => [
                    'avstamning' => 'uttag',
                    'artiklar' => $antalPoster,
                    'justerade' => $totaltJusterat,
                ],
            ]);
            $tx->spara();
            self::arkivera($sessionId, $anvandare);
            return ['justerade' => $totaltJusterat, 'artiklar' => $antalPoster];
        });
    }

    /** Avbryt en öppen session utan differenshantering (t.ex. felstartad). Kräver admin. */
    public static function annullera(string $sessionId, array $anvandare, string $motivering): void
    {
        if (($anvandare['roll'] ?? '') !== 'admin') {
            throw new AppFel('Endast administratörer kan annullera en inventering.', 403);
        }
        $motivering = trim($motivering);
        if (mb_strlen($motivering) < 3) {
            throw new AppFel('Ange en kort motivering.');
        }
        Las::under(function () use ($sessionId, $anvandare, $motivering) {
            $tx = new Transaktion('inventering_avslut', $anvandare);
            $sessioner = $tx->fil('sessioner');
            $session = Json::hitta($sessioner, $sessionId);
            if ($session === null) {
                throw new AppFel('Inventeringen finns inte.', 404);
            }
            if (($session['status'] ?? '') !== 'öppen') {
                throw new AppFel('Inventeringen är redan avslutad.');
            }
            Json::uppdatera($sessioner, $sessionId, function ($s) use ($anvandare, $motivering) {
                $s['status'] = 'annullerad';
                $s['avslutad'] = nu();
                $s['avslutad_av'] = $anvandare['id'] ?? null;
                $s['avslutad_av_namn'] = $anvandare['namn'] ?? null;
                $s['avstamning'] = ['typ' => 'annullerad', 'motivering' => $motivering];
                return $s;
            });
            $tx->sattFil('sessioner', $sessioner);
            $tx->historik([
                'typ' => 'inventering_avslut',
                'plats_id' => $session['plats_id'],
                'session_id' => $sessionId,
                'detaljer' => ['avstamning' => 'annullerad', 'motivering' => $motivering],
            ]);
            $tx->spara();
        });
    }

    /** Arkivera en just avslutad inventering (års- och månadsarkiv). */
    private static function arkivera(string $sessionId, array $anvandare): void
    {
        $session = self::session($sessionId);
        if ($session === null) {
            return;
        }
        try {
            Arkiv::arkiveraSession($session, $anvandare);
        } catch (\Throwable $e) {
            // Arkivet får aldrig hindra att inventeringen avslutas.
            error_log('Arkivering misslyckades för ' . $sessionId . ': ' . $e->getMessage());
        }
    }
}
