<?php
/**
 * Lagerrörelser: inleverans, uttag, överföring och etiketter.
 *
 * Alla operationer:
 *   – håller skrivlåset,
 *   – validerar ALLT innan något skrivs (fel plats, otillräckligt saldo,
 *     fel enhetsstatus m.m. ger ett undantag och ingen delvis ändring),
 *   – sparar via Transaktion (journal → atomiska filbyten → historik).
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Installningar;
use TDL\Support\Koder;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Rorelser
{
    /* ================================================================
     *  Inleverans
     * ================================================================ */

    /**
     * Inleverans till en plats.
     *
     * antal-artikel: $antal > 0 läggs till platsens saldo.
     * qr-artikel:    antingen befintliga väntande enhetskoder ($enhetskoder)
     *                eller $antal nya enheter (nya koder skapas).
     */
    public static function inleverans(
        string $artikelId,
        string $platsId,
        int $antal,
        array $enhetskoder,
        array $anvandare,
        ?string $kommentar = null
    ): array {
        return Las::under(function () use ($artikelId, $platsId, $antal, $enhetskoder, $anvandare, $kommentar) {
            $tx = new Transaktion('inleverans', $anvandare);

            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null || empty($artikel['aktiv'])) {
                throw new AppFel('Artikeln finns inte eller är inaktiv.');
            }
            $plats = self::kravAktivPlats($tx, $platsId);
            if (!empty($plats['system'])) {
                throw new AppFel('Systemposten för äldre saldo kan inte ta emot nya inleveranser.');
            }

            $antal = max(0, $antal);
            $skapadeKoder = [];

            if ($artikel['sparningssatt'] === Lager::SPAR_ANTAL) {
                if ($enhetskoder !== []) {
                    throw new AppFel('Artikeln hanteras i antal och kan inte ta emot QR-enheter.');
                }
                if ($antal <= 0) {
                    throw new AppFel('Ange ett antal större än 0.');
                }
                Json::uppdatera($artiklar, $artikelId, function ($a) use ($platsId, $antal) {
                    $a['saldo'][$platsId] = (int) ($a['saldo'][$platsId] ?? 0) + $antal;
                    $a['andrad'] = nu();
                    return $a;
                });
                $tx->sattFil('artiklar', $artiklar);
                $antalPost = $antal;
            } else {
                // QR-artikel
                $enheter = $tx->fil('enheter');
                $berorda = [];
                if ($enhetskoder !== []) {
                    // Befintliga väntande etiketter aktiveras.
                    foreach (array_unique(array_map(fn ($k) => Koder::utanBindestreck($k), $enhetskoder)) as $kod) {
                        $funna = null;
                        foreach ($enheter['poster'] as $i => $en) {
                            if (Koder::utanBindestreck($en['id']) === $kod) {
                                $funna = $i;
                                break;
                            }
                        }
                        if ($funna === null) {
                            throw new AppFel('Enhetskoden ' . Koder::visa($kod) . ' finns inte. Skapa etiketten först.');
                        }
                        $en = $enheter['poster'][$funna];
                        if (($en['artikel_id'] ?? '') !== $artikelId) {
                            throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' tillhör en annan artikel.');
                        }
                        if (($en['status'] ?? '') !== 'vantande') {
                            throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' är inte väntande (status: ' . $en['status'] . ').');
                        }
                        $berorda[] = $funna;
                    }
                } else {
                    if ($antal <= 0) {
                        throw new AppFel('Ange antal enheter eller enhetskoder.');
                    }
                    for ($i = 0; $i < $antal; $i++) {
                        $kod = Koder::nyKod('U');
                        $berorda[] = count($enheter['poster']);
                        $enheter['poster'][] = [
                            'id' => $kod,
                            'artikel_id' => $artikelId,
                            'plats_id' => null,
                            'status' => 'vantande',
                            'skapad' => nu(),
                        ];
                        $skapadeKoder[] = $kod;
                    }
                }
                foreach ($berorda as $i) {
                    $enheter['poster'][$i]['plats_id'] = $platsId;
                    $enheter['poster'][$i]['status'] = 'aktiv';
                    $enheter['poster'][$i]['aktiverad'] = nu();
                    $enheter['poster'][$i]['inleverans_id'] = $tx->txid();
                }
                $tx->sattFil('enheter', $enheter);
                $antalPost = count($berorda);
                $tx->historik([
                    'typ' => 'enhet_aktiverad',
                    'artikel_id' => $artikelId,
                    'artikelnummer' => $artikel['artikelnummer'],
                    'artikelnamn' => $artikel['namn'],
                    'plats_id' => $platsId,
                    'plats_namn' => $plats['namn'],
                    'antal' => $antalPost,
                    'enheter' => array_map(fn ($i) => $enheter['poster'][$i]['id'], $berorda),
                ]);
            }

            $tx->historik([
                'typ' => 'inleverans',
                'artikel_id' => $artikelId,
                'artikelnummer' => $artikel['artikelnummer'],
                'artikelnamn' => $artikel['namn'],
                'plats_id' => $platsId,
                'plats_namn' => $plats['namn'],
                'antal' => $antalPost,
                'kommentar' => $kommentar,
            ]);
            $tx->spara();

            // Verktygstavla: delarna följer med tillbaka automatiskt.
            // Egen transaktion – en del som inte går att lägga tillbaka ska
            // inte rulla tillbaka själva tavlan.
            // Delarna följer med både ut och in: en tavla som lämnas tillbaka
            // tar med sig sina verktyg, och en ny tavla från leverantören
            // kommer också med sina. Det gör saldot förutsägbart – man räknar
            // aldrig delarna separat så länge de sitter i en tavla.
            $delar = ['aterstallda' => [], 'misslyckade' => []];
            if (!empty($artikel['ar_tavla'])) {
                $delar = \TDL\Domain\Tavlor::inleveransAvDelar($artikel, $platsId, $anvandare, $antalPost);
            }

            return ['antal' => $antalPost, 'enheter' => $skapadeKoder, 'delar' => $delar];
        });
    }

    /* ================================================================
     *  Uttag
     * ================================================================ */

    /**
     * Uttag från en plats.
     *
     * antal-artikel: saldot på platsen minskas, aldrig under noll.
     * qr-artikel:    angivna enhetskoder måste vara aktiva på platsen och
     *                sätts till status "utgang".
     */
    /**
     * Uttag. Vid stora uttag kan tvåpersonersregeln kräva att en kollega
     * bekräftar med sitt konto ($godkannare).
     */
    public static function uttag(
        string $artikelId,
        string $platsId,
        int $antal,
        array $enhetskoder,
        array $anvandare,
        ?string $kommentar = null,
        ?array $godkannare = null
    ): array {
        return Las::under(function () use ($artikelId, $platsId, $antal, $enhetskoder, $anvandare, $kommentar, $godkannare) {
            $tx = new Transaktion('uttag', $anvandare);

            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null || empty($artikel['aktiv'])) {
                throw new AppFel('Artikeln finns inte eller är inaktiv.');
            }
            $plats = self::kravAktivPlats($tx, $platsId);
            if (!empty($plats['system'])) {
                throw new AppFel('Systemposten för äldre saldo kan inte användas för uttag.');
            }

            // Tvåpersonersregel: stora uttag kräver en kollegas bekräftelse
            $antalForKontroll = $antal > 0 ? $antal : count($enhetskoder);
            $kraver = Tvaperson::kraverGodkannande($artikel, $plats, $antalForKontroll);
            if ($kraver !== null) {
                if ($godkannare === null) {
                    throw new AppFel(
                        'Det här uttaget kräver att en kollega bekräftar med sin PIN-kod (' . $kraver . ').'
                        . ' Be en kollega logga in och godkänna.'
                    );
                }
                if (($godkannare['id'] ?? '') === ($anvandare['id'] ?? '')) {
                    throw new AppFel('Uttaget måste bekräftas av en annan person än den som gör uttaget.');
                }
            }

            if ($artikel['sparningssatt'] === Lager::SPAR_ANTAL) {
                if ($enhetskoder !== []) {
                    throw new AppFel('Artikeln hanteras i antal och har inga QR-enheter.');
                }
                if ($antal <= 0) {
                    throw new AppFel('Ange ett antal större än 0.');
                }
                $saldoNu = (int) ($artikel['saldo'][$platsId] ?? 0);
                if ($saldoNu < $antal) {
                    throw new AppFel('Otillräckligt saldo på ' . $plats['namn'] . ': ' . $saldoNu . ' ' . $artikel['enhet'] . ' kvar, ' . $antal . ' begärda. Ingen ändring gjordes.');
                }
                Json::uppdatera($artiklar, $artikelId, function ($a) use ($platsId, $antal) {
                    $a['saldo'][$platsId] = (int) ($a['saldo'][$platsId] ?? 0) - $antal;
                    $a['andrad'] = nu();
                    return $a;
                });
                $tx->sattFil('artiklar', $artiklar);
                $antalPost = $antal;
            } else {
                if ($enhetskoder === []) {
                    throw new AppFel('För QR-artiklar måste enhetskoder anges vid uttag.');
                }
                $enheter = $tx->fil('enheter');
                $berorda = [];
                foreach (array_unique(array_map(fn ($k) => Koder::utanBindestreck($k), $enhetskoder)) as $kod) {
                    $funna = null;
                    foreach ($enheter['poster'] as $i => $en) {
                        if (Koder::utanBindestreck($en['id']) === $kod) {
                            $funna = $i;
                            break;
                        }
                    }
                    if ($funna === null) {
                        throw new AppFel('Enhetskoden ' . Koder::visa($kod) . ' finns inte.');
                    }
                    $en = $enheter['poster'][$funna];
                    if (($en['artikel_id'] ?? '') !== $artikelId) {
                        throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' tillhör en annan artikel.');
                    }
                    if (($en['status'] ?? '') !== 'aktiv') {
                        throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' är inte aktiv (status: ' . $en['status'] . ').');
                    }
                    if (($en['plats_id'] ?? '') !== $platsId) {
                        throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' ligger inte på ' . $plats['namn'] . '. Välj rätt plats.');
                    }
                    $berorda[] = $funna;
                }
                foreach ($berorda as $i) {
                    $enheter['poster'][$i]['status'] = 'utgang';
                    $enheter['poster'][$i]['plats_id'] = $platsId; // spåret finns kvar: var den togs ut
                    $enheter['poster'][$i]['utgang'] = nu();
                    $enheter['poster'][$i]['uttag_id'] = $tx->txid();
                }
                $tx->sattFil('enheter', $enheter);
                $antalPost = count($berorda);
                $tx->historik([
                    'typ' => 'enhet_utgang',
                    'artikel_id' => $artikelId,
                    'artikelnummer' => $artikel['artikelnummer'],
                    'artikelnamn' => $artikel['namn'],
                    'plats_id' => $platsId,
                    'plats_namn' => $plats['namn'],
                    'antal' => $antalPost,
                    'enheter' => array_map(fn ($i) => $enheter['poster'][$i]['id'], $berorda),
                ]);
            }

            $tx->historik([
                'typ' => 'uttag',
                'artikel_id' => $artikelId,
                'artikelnummer' => $artikel['artikelnummer'],
                'artikelnamn' => $artikel['namn'],
                'plats_id' => $platsId,
                'plats_namn' => $plats['namn'],
                'antal' => $antalPost,
                'kommentar' => $kommentar,
                'godkand_av' => $godkannare === null ? null : [
                    'id' => $godkannare['id'] ?? '',
                    'namn' => $godkannare['namn'] ?? '',
                ],
            ]);
            $tx->spara();

            // Verktygstavla: delarna följer med automatiskt. Egen transaktion –
            // en del som saknas ska inte stoppa uttaget av själva tavlan.
            $delar = ['utdragna' => [], 'saknade' => []];
            if (!empty($artikel['ar_tavla'])) {
                $delar = \TDL\Domain\Tavlor::uttagAvDelar($artikel, $platsId, $anvandare, $antalPost);
            }

            return ['antal' => $antalPost, 'delar' => $delar];
        });
    }

    /* ================================================================
     *  Överföring
     * ================================================================ */

    /**
     * Överför antal eller QR-enheter mellan två aktiva platser.
     * En QR-enhets ID bevaras; endast platsen ändras.
     */
    public static function overforing(
        string $artikelId,
        string $franPlatsId,
        string $tillPlatsId,
        int $antal,
        array $enhetskoder,
        array $anvandare,
        ?string $kommentar = null
    ): array {
        return Las::under(function () use ($artikelId, $franPlatsId, $tillPlatsId, $antal, $enhetskoder, $anvandare, $kommentar) {
            $tx = new Transaktion('overforing', $anvandare);
            if ($franPlatsId === $tillPlatsId) {
                throw new AppFel('Käll- och målplats måste vara olika.');
            }
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null || empty($artikel['aktiv'])) {
                throw new AppFel('Artikeln finns inte eller är inaktiv.');
            }
            $fran = self::kravAktivPlats($tx, $franPlatsId);
            $till = self::kravAktivPlats($tx, $tillPlatsId);
            if (!empty($fran['system']) || !empty($till['system'])) {
                throw new AppFel('Systemposten för äldre saldo kan inte användas i överföringar.');
            }

            if ($artikel['sparningssatt'] === Lager::SPAR_ANTAL) {
                if ($enhetskoder !== []) {
                    throw new AppFel('Artikeln hanteras i antal och har inga QR-enheter.');
                }
                if ($antal <= 0) {
                    throw new AppFel('Ange ett antal större än 0.');
                }
                $saldoFran = (int) ($artikel['saldo'][$franPlatsId] ?? 0);
                if ($saldoFran < $antal) {
                    throw new AppFel('Otillräckligt saldo på ' . $fran['namn'] . ': ' . $saldoFran . ' kvar, ' . $antal . ' begärda.');
                }
                Json::uppdatera($artiklar, $artikelId, function ($a) use ($franPlatsId, $tillPlatsId, $antal) {
                    $a['saldo'][$franPlatsId] = (int) ($a['saldo'][$franPlatsId] ?? 0) - $antal;
                    $a['saldo'][$tillPlatsId] = (int) ($a['saldo'][$tillPlatsId] ?? 0) + $antal;
                    $a['andrad'] = nu();
                    return $a;
                });
                $tx->sattFil('artiklar', $artiklar);
                $antalPost = $antal;
            } else {
                if ($enhetskoder === []) {
                    throw new AppFel('För QR-artiklar måste enhetskoder anges vid överföring.');
                }
                $enheter = $tx->fil('enheter');
                $berorda = [];
                foreach (array_unique(array_map(fn ($k) => Koder::utanBindestreck($k), $enhetskoder)) as $kod) {
                    $funna = null;
                    foreach ($enheter['poster'] as $i => $en) {
                        if (Koder::utanBindestreck($en['id']) === $kod) {
                            $funna = $i;
                            break;
                        }
                    }
                    if ($funna === null) {
                        throw new AppFel('Enhetskoden ' . Koder::visa($kod) . ' finns inte.');
                    }
                    $en = $enheter['poster'][$funna];
                    if (($en['artikel_id'] ?? '') !== $artikelId) {
                        throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' tillhör en annan artikel.');
                    }
                    if (($en['status'] ?? '') !== 'aktiv') {
                        throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' är inte aktiv (status: ' . $en['status'] . ').');
                    }
                    if (($en['plats_id'] ?? '') !== $franPlatsId) {
                        throw new AppFel('Enhet ' . Koder::visa($en['id']) . ' ligger inte på ' . $fran['namn'] . '.');
                    }
                    $berorda[] = $funna;
                }
                foreach ($berorda as $i) {
                    $enheter['poster'][$i]['plats_id'] = $tillPlatsId;
                    $enheter['poster'][$i]['overford'] = nu();
                    $enheter['poster'][$i]['overforing_id'] = $tx->txid();
                }
                $tx->sattFil('enheter', $enheter);
                $antalPost = count($berorda);
                $tx->historik([
                    'typ' => 'overforing',
                    'artikel_id' => $artikelId,
                    'artikelnummer' => $artikel['artikelnummer'],
                    'artikelnamn' => $artikel['namn'],
                    'fran_plats_id' => $franPlatsId,
                    'fran_plats_namn' => $fran['namn'],
                    'till_plats_id' => $tillPlatsId,
                    'till_plats_namn' => $till['namn'],
                    'antal' => $antalPost,
                    'enheter' => array_map(fn ($i) => $enheter['poster'][$i]['id'], $berorda),
                    'kommentar' => $kommentar,
                ]);
                $tx->spara();
                return ['antal' => $antalPost];
            }

            $tx->historik([
                'typ' => 'overforing',
                'artikel_id' => $artikelId,
                'artikelnummer' => $artikel['artikelnummer'],
                'artikelnamn' => $artikel['namn'],
                'fran_plats_id' => $franPlatsId,
                'fran_plats_namn' => $fran['namn'],
                'till_plats_id' => $tillPlatsId,
                'till_plats_namn' => $till['namn'],
                'antal' => $antalPost,
                'kommentar' => $kommentar,
            ]);
            $tx->spara();
            return ['antal' => $antalPost];
        });
    }

    /* ================================================================
     *  Etiketter
     * ================================================================ */

    /**
     * Skapa väntande enhetsetiketter för en QR-artikel.
     * Enheterna har status "vantande" och aktiveras vid inleverans.
     *
     * @return list<array{id:string, artikel_id:string}>
     */
    /**
     * Skapa N nya, oanvända QR-enheter (etiketter) för en artikel på ett
     * arbetsställe – utan att ändra saldot.
     *
     * Används när personal lägger upp en ny artikel och behöver etiketter
     * direkt. Enheterna får status "ny" och räknas in i saldot först när de
     * tas emot via inleverans.
     */
    public static function skapaEnheter(string $artikelId, string $platsId, int $antal, array $anvandare): array
    {
        if ($antal < 1 || $antal > 60) {
            throw new AppFel('Ange 1–60 etiketter.');
        }
        return Las::under(function () use ($artikelId, $platsId, $antal, $anvandare) {
            $tx = new Transaktion('etikett_skapad', $anvandare);
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null || empty($artikel['aktiv'])) {
                throw new AppFel('Artikeln finns inte eller är inaktiv.');
            }
            if (($artikel['sparningssatt'] ?? '') !== Lager::SPAR_QR) {
                throw new AppFel('Artikeln hanteras i antal och har inga QR-etiketter.');
            }
            $plats = self::kravAktivPlats($tx, $platsId);
            if (!empty($plats['system'])) {
                throw new AppFel('Systemposten för äldre saldo kan inte få etiketter.');
            }

            $enheter = $tx->fil('enheter');
            $nya = [];
            for ($i = 0; $i < $antal; $i++) {
                $id = Koder::nyKod('U');
                while (Json::hitta($enheter, $id) !== null) {
                    $id = Koder::nyKod('U');
                }
                $en = [
                    'id' => $id,
                    'artikel_id' => $artikelId,
                    'plats_id' => $platsId,
                    'status' => 'ny',
                    'skapad' => nu(),
                    'skapad_av' => $anvandare['id'] ?? null,
                    'etikett_skapad' => nu(),
                ];
                Json::laggTill($enheter, $en);
                $nya[] = $en;
            }
            $tx->sattFil('enheter', $enheter);
            $tx->historik([
                'typ' => 'etikett_skapad',
                'artikel_id' => $artikelId,
                'artikelnummer' => $artikel['artikelnummer'],
                'artikelnamn' => $artikel['namn'],
                'plats_id' => $platsId,
                'plats_namn' => $plats['namn'],
                'antal' => count($nya),
                'detaljer' => ['nya_enheter' => count($nya), 'status' => 'ny'],
            ]);
            $tx->spara();
            return $nya;
        });
    }

    public static function skapaEtiketter(string $artikelId, int $antal, array $anvandare): array
    {
        if ($antal < 1 || $antal > 100) {
            throw new AppFel('Antal etiketter ska vara 1–100 per omgång.');
        }
        return Las::under(function () use ($artikelId, $antal, $anvandare) {
            $tx = new Transaktion('etikett_skapad', $anvandare);
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            if ($artikel === null || empty($artikel['aktiv'])) {
                throw new AppFel('Artikeln finns inte eller är inaktiv.');
            }
            if ($artikel['sparningssatt'] !== Lager::SPAR_QR) {
                throw new AppFel('Artikeln hanteras i antal. Byt saldohanteringssätt till QR om enhetsetiketter ska användas.');
            }
            $enheter = $tx->fil('enheter');
            $nya = [];
            for ($i = 0; $i < $antal; $i++) {
                $nod = [
                    'id' => Koder::nyKod('U'),
                    'artikel_id' => $artikelId,
                    'plats_id' => null,
                    'status' => 'vantande',
                    'skapad' => nu(),
                    'skapad_av' => $anvandare['id'] ?? null,
                ];
                Json::laggTill($enheter, $nod);
                $nya[] = $nod;
            }
            $tx->sattFil('enheter', $enheter);
            $tx->historik([
                'typ' => 'etikett_skapad',
                'artikel_id' => $artikelId,
                'artikelnummer' => $artikel['artikelnummer'],
                'artikelnamn' => $artikel['namn'],
                'antal' => $antal,
                'enheter' => array_map(fn ($n) => $n['id'], $nya),
            ]);
            $tx->spara();
            return $nya;
        });
    }

    /* ================================================================
     *  Plocklista – flera uttag i en rörelse
     * ================================================================ */

    /**
     * Uttag av flera artiklar samtidigt, i en enda transaktion.
     *
     * Allt-eller-inget: kontrollerar HELA listan innan något skrivs. Räcker
     * inte saldot på en rad avbryts hela plocklistan med besked om vilken
     * rad det gällde – inget halvt uttag kan uppstå.
     *
     * $rader = [['artikel_id' => 'A…', 'antal' => 3], …]
     * Stödjer bara antalsartiklar; QR-artiklar tas ut en och en via skanning
     * eftersom varje fysisk enhet måste pekas ut.
     *
     * @return array{antal_rader:int, antal_enheter:int, rader:list<array>}
     */
    public static function plocklista(string $platsId, array $rader, array $anvandare, ?string $kommentar = null): array
    {
        return Las::under(function () use ($platsId, $rader, $anvandare, $kommentar) {
            $tx = new Transaktion('plocklista', $anvandare);
            $plats = self::kravAktivPlats($tx, $platsId);
            if (!empty($plats['system'])) {
                throw new AppFel('Systemposten för äldre saldo kan inte användas för uttag.');
            }

            // Slå ihop dubbletter (samma artikel två gånger i listan)
            $ihop = [];
            foreach ($rader as $rad) {
                $id = trim((string) ($rad['artikel_id'] ?? ''));
                $antal = (int) ($rad['antal'] ?? 0);
                if ($id === '' || $antal <= 0) {
                    continue;
                }
                $ihop[$id] = ($ihop[$id] ?? 0) + $antal;
            }
            if ($ihop === []) {
                throw new AppFel('Plocklistan är tom. Lägg till minst en artikel med antal större än 0.');
            }

            $artiklar = $tx->fil('artiklar');
            $kontrollerade = [];
            $totalt = 0;

            // ---- Kontrollera allt först ----
            foreach ($ihop as $id => $antal) {
                $artikel = Json::hitta($artiklar, $id);
                if ($artikel === null || empty($artikel['aktiv'])) {
                    throw new AppFel('En artikel i listan finns inte längre eller är inaktiv.');
                }
                if ($artikel['sparningssatt'] !== Lager::SPAR_ANTAL) {
                    throw new AppFel(
                        $artikel['namn'] . ' hanteras per QR-enhet och kan inte plockas i en lista. '
                        . 'Ta ut den via skanning i stället.'
                    );
                }
                $saldoNu = (int) ($artikel['saldo'][$platsId] ?? 0);
                if ($saldoNu < $antal) {
                    throw new AppFel(
                        'Otillräckligt saldo på ' . $plats['namn'] . ': ' . $artikel['namn'] . ' har '
                        . $saldoNu . ' ' . $artikel['enhet'] . ' kvar, ' . $antal . ' begärda. '
                        . 'Ingen ändring gjordes.'
                    );
                }
                $krav = Tvaperson::kraverGodkannande($artikel, $plats, $antal);
                if ($krav !== null) {
                    throw new AppFel(
                        $artikel['namn'] . ' når gränsen för tvåpersonersregeln (' . $krav . '). '
                        . 'Ta ut den raden separat med en kollegas godkännande.'
                    );
                }
                $kontrollerade[] = ['artikel' => $artikel, 'antal' => $antal];
                $totalt += $antal;
            }

            // ---- Skriv ----
            $summering = [];
            foreach ($kontrollerade as $post) {
                $artikel = $post['artikel'];
                $antal = $post['antal'];
                Json::uppdatera($artiklar, $artikel['id'], function ($a) use ($platsId, $antal) {
                    $a['saldo'][$platsId] = (int) ($a['saldo'][$platsId] ?? 0) - $antal;
                    $a['andrad'] = nu();
                    return $a;
                });
                $summering[] = [
                    'artikel_id' => $artikel['id'],
                    'artikelnummer' => $artikel['artikelnummer'],
                    'artikelnamn' => $artikel['namn'],
                    'antal' => $antal,
                    'enhet' => $artikel['enhet'],
                    'hylla' => $artikel['hylla'] ?? null,
                ];
            }
            $tx->sattFil('artiklar', $artiklar);

            foreach ($summering as $rad) {
                $tx->historik([
                    'typ' => 'uttag',
                    'artikel_id' => $rad['artikel_id'],
                    'artikelnummer' => $rad['artikelnummer'],
                    'artikelnamn' => $rad['artikelnamn'],
                    'plats_id' => $platsId,
                    'plats_namn' => $plats['namn'],
                    'antal' => $rad['antal'],
                    'kommentar' => $kommentar,
                    'godkand_av' => null,
                    'plocklista' => true,
                ]);
            }
            $tx->historik([
                'typ' => 'plocklista',
                'plats_id' => $platsId,
                'plats_namn' => $plats['namn'],
                'antal' => $totalt,
                'detaljer' => [
                    'rader' => count($summering),
                    'artiklar' => array_map(fn ($r) => $r['artikelnummer'] . ' × ' . $r['antal'], $summering),
                    'kommentar' => $kommentar,
                ],
            ]);
            $tx->spara();

            return ['antal_rader' => count($summering), 'antal_enheter' => $totalt, 'rader' => $summering];
        });
    }

    /* ================================================================
     *  Hjälp
     * ================================================================ */

    public static function kravAktivPlats(Transaktion $tx, string $platsId): array
    {
        $plats = Json::hitta($tx->fil('platser'), $platsId);
        if ($plats === null) {
            throw new AppFel('Arbetsstället finns inte.', 404);
        }
        if (empty($plats['aktiv'])) {
            throw new AppFel('Arbetsstället ' . $plats['namn'] . ' är stängt och kan inte användas.');
        }
        return $plats;
    }
}
