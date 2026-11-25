<?php
/** Utlåning registreras tillsammans med artikeln och saldot i en transaktion. */
declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Koder;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Utlan
{
    /** @return list<array> */
    public static function oppna(): array
    {
        $resultat = [];
        foreach (Lager::artiklar() as $artikel) {
            foreach ($artikel['utlan'] ?? [] as $lan) {
                if (($lan['status'] ?? '') === 'oppen') {
                    $resultat[] = $lan + ['artikel_id' => $artikel['id'], 'artikelnamn' => $artikel['namn'], 'sparningssatt' => $artikel['sparningssatt'], 'version' => self::version($lan)];
                }
            }
        }
        usort($resultat, static fn ($a, $b) => strcmp($a['forfallodatum'], $b['forfallodatum']));
        return $resultat;
    }

    public static function skapa(string $artikelId, string $platsId, int $antal, array $koder, string $lanare, string $datum, array $av, ?array $godkannare = null): array
    {
        $lanare = trim($lanare);
        if ($lanare === '' || mb_strlen($lanare) > 120) {
            throw new AppFel('Ange namnet på den som lånar (högst 120 tecken).');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
        if ($d === false || $d->format('Y-m-d') !== $datum || $datum < gmdate('Y-m-d')) {
            throw new AppFel('Ange ett giltigt återlämningsdatum som inte har passerat.');
        }
        return Las::under(function () use ($artikelId, $platsId, $antal, $koder, $lanare, $datum, $av, $godkannare) {
            $tx = new Transaktion('utlan', $av);
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $artikelId);
            $plats = Json::hitta($tx->fil('platser'), $platsId);
            if ($artikel === null || empty($artikel['aktiv'])) throw new AppFel('Artikeln finns inte eller är inaktiv.');
            if ($plats === null || empty($plats['aktiv']) || !empty($plats['system'])) throw new AppFel('Välj ett aktivt arbetsställe.');
            if (!empty($artikel['ar_tavla'])) throw new AppFel('Verktygstavlor kan ännu inte lånas ut här.');
            if (Inventering::oppenSessionForPlats($platsId) !== null) throw new AppFel('Avsluta inventeringen på arbetsstället innan utlåning.');
            $koder = array_values(array_filter(array_map(static fn ($k) => Koder::utanBindestreck((string) $k), $koder), static fn ($k) => $k !== ''));
            if (count($koder) !== count(array_unique($koder))) throw new AppFel('Samma enhetskod får inte anges två gånger.');
            $arQr = ($artikel['sparningssatt'] ?? '') === Lager::SPAR_QR;
            $antalLan = $arQr ? count($koder) : $antal;
            if ($antalLan <= 0 || (!$arQr && $koder !== [])) throw new AppFel('Ange antal eller giltiga enhetskoder för artikeln.');
            $krav = Tvaperson::kraverGodkannande($artikel, $plats, $antalLan);
            if ($krav !== null && ($godkannare === null || empty($godkannare['id']) || empty($godkannare['aktiv']) || !empty($godkannare['maste_byta_pin']) || ($godkannare['id'] ?? '') === ($av['id'] ?? ''))) {
                throw new AppFel('Utlåningen kräver en annan kollegas bekräftelse: ' . $krav . '.');
            }
            if ($godkannare !== null) {
                $aktuell = Json::hitta($tx->fil('anvandare'), (string) ($godkannare['id'] ?? ''));
                if ($aktuell === null || empty($aktuell['aktiv']) || !empty($aktuell['maste_byta_pin'])
                    || ($aktuell['pin_hash'] ?? '') !== ($godkannare['pin_hash'] ?? '')
                    || ($aktuell['id'] ?? '') === ($av['id'] ?? '')) {
                    throw new AppFel('Kollegans konto har ändrats. Bekräfta på nytt.');
                }
            }
            $lanId = 'L' . strtoupper(bin2hex(random_bytes(8)));
            if ($arQr) {
                $enheter = $tx->fil('enheter');
                $index = [];
                foreach ($enheter['poster'] as $i => $en) $index[Koder::utanBindestreck((string) $en['id'])] = $i;
                foreach ($koder as $kod) {
                    if (!isset($index[$kod])) throw new AppFel('Enhetskoden finns inte: ' . Koder::visa($kod));
                    $i = $index[$kod]; $en = $enheter['poster'][$i];
                    if (($en['artikel_id'] ?? '') !== $artikelId || ($en['plats_id'] ?? '') !== $platsId || ($en['status'] ?? '') !== 'aktiv') {
                        throw new AppFel('Enheten är inte tillgänglig för artikeln på det valda arbetsstället: ' . Koder::visa($kod));
                    }
                    $enheter['poster'][$i]['status'] = 'utlanad';
                    $enheter['poster'][$i]['utlan_id'] = $lanId;
                }
                $tx->sattFil('enheter', $enheter);
            } else {
                if ((int) ($artikel['saldo'][$platsId] ?? 0) < $antalLan) throw new AppFel('Otillräckligt saldo på arbetsstället.');
                $artikel['saldo'][$platsId] = (int) $artikel['saldo'][$platsId] - $antalLan;
            }
            $lan = ['id' => $lanId, 'status' => 'oppen', 'plats_id' => $platsId, 'plats_namn' => $plats['namn'], 'antal' => $antalLan, 'enheter' => $koder, 'lanare' => $lanare, 'forfallodatum' => $datum, 'skapad' => nu()];
            $artikel['utlan'][] = $lan;
            $artikel['andrad'] = nu();
            Json::uppdatera($artiklar, $artikelId, static fn ($a) => $artikel);
            $tx->sattFil('artiklar', $artiklar);
            $tx->historik(['typ' => 'utlan', 'artikel_id' => $artikelId, 'artikelnummer' => $artikel['artikelnummer'], 'artikelnamn' => $artikel['namn'], 'plats_id' => $platsId, 'plats_namn' => $plats['namn'], 'antal' => $antalLan, 'enheter' => $koder, 'detaljer' => ['utlan_id' => $lanId, 'lanare' => $lanare, 'forfallodatum' => $datum, 'godkand_av' => $godkannare === null ? null : ['id' => $godkannare['id'], 'namn' => $godkannare['namn']]]]);
            $tx->spara();
            return $lan;
        });
    }

    /** Fingeravtryck hindrar att en gammal blankett återlämnar samma antal två gånger. */
    public static function version(array $lan): string
    {
        return hash('sha256', tdl_json($lan));
    }

    /** @return array{kvittokod:string,antal:int,kvar:int} */
    public static function aterlamna(string $lanId, array $av, ?int $antal = null, array $valdaKoder = [], ?string $version = null, string $skick = 'ok', string $notering = '', ?array $bild = null): array
    {
        return Las::under(function () use ($lanId, $av, $antal, $valdaKoder, $version, $skick, $notering, $bild) {
            $tx = new Transaktion('aterlamning', $av);
            $artiklar = $tx->fil('artiklar');
            $hittad = null;
            foreach ($artiklar['poster'] as $ai => $artikel) {
                foreach ($artikel['utlan'] ?? [] as $li => $lan) {
                    if (($lan['id'] ?? '') === $lanId) { $hittad = [$ai, $li, $lan]; break 2; }
                }
            }
            if ($hittad === null) throw new AppFel('Utlåningen finns inte.', 404);
            [$ai, $li, $lan] = $hittad;
            if (($lan['status'] ?? '') !== 'oppen') throw new AppFel('Utlåningen är redan återlämnad.');
            if ($version !== null && !hash_equals(self::version($lan), $version)) throw new AppFel('Utlåningen har ändrats. Ladda om sidan och kontrollera antalet innan du fortsätter.');
            $artikel = $artiklar['poster'][$ai];
            $plats = Json::hitta($tx->fil('platser'), $lan['plats_id']);
            if ($plats === null || empty($plats['aktiv']) || !empty($plats['system'])) throw new AppFel('Arbetsstället är inte aktivt.');
            if (empty($artikel['aktiv'])) throw new AppFel('Artikeln är inte aktiv.');
            if (Inventering::oppenSessionForPlats($lan['plats_id']) !== null) throw new AppFel('Avsluta inventeringen på arbetsstället innan återlämning.');
            $arQr = ($artikel['sparningssatt'] ?? '') === Lager::SPAR_QR;
            $notering = trim($notering);
            if (!in_array($skick, ['ok', 'skadad'], true) || mb_strlen($notering) > 500) throw new AppFel('Ogiltigt skick eller för lång notering.');
            if ($skick === 'skadad' && (!$arQr || $notering === '')) throw new AppFel('Skadade verktyg kräver QR-spårning och en beskrivning.');
            if (!$arQr && ($bild !== null || $notering !== '')) throw new AppFel('Hälsokort kräver en individuellt QR-spårad enhet.');
            $koder = [];
            if ($arQr) {
                // Äldre anrop utan explicit antal/val avser hela lånet.
                $koder = ($antal === null && $valdaKoder === []) ? ($lan['enheter'] ?? []) : array_map(static fn ($kod) => Koder::utanBindestreck((string) $kod), $valdaKoder);
                if ($koder === [] || count($koder) !== count(array_unique($koder))) throw new AppFel('Välj minst en unik utlånad enhet.');
                $enheter = $tx->fil('enheter'); $index = [];
                foreach ($enheter['poster'] as $i => $en) $index[Koder::utanBindestreck((string) $en['id'])] = $i;
                foreach ($koder as $kod) {
                    if (!in_array($kod, $lan['enheter'] ?? [], true) || !isset($index[$kod])) throw new AppFel('Enheten ingår inte i den pågående utlåningen. Ingen återlämning gjordes.');
                    $i = $index[$kod]; $en = $enheter['poster'][$i];
                    if (($en['status'] ?? '') !== 'utlanad' || ($en['utlan_id'] ?? '') !== $lanId || ($en['artikel_id'] ?? '') !== $artikel['id']) throw new AppFel('Enhetens status har ändrats. Ingen återlämning gjordes.');
                    $enheter['poster'][$i]['status'] = $skick === 'skadad' ? 'skadad' : 'aktiv';
                    $enheter['poster'][$i]['plats_id'] = $lan['plats_id'];
                    $enheter['poster'][$i]['halsokort'][] = ['tid' => nu(), 'skick' => $skick, 'notering' => $notering, 'bild' => $bild, 'av' => $av['namn'] ?? ''];
                    unset($enheter['poster'][$i]['utlan_id']);
                }
                $tx->sattFil('enheter', $enheter);
                $aterAntal = count($koder);
                $lan['enheter'] = array_values(array_diff($lan['enheter'], $koder));
            } else {
                if ($valdaKoder !== []) throw new AppFel('Antalsartiklar använder inte enhetskoder.');
                $aterAntal = $antal ?? (int) $lan['antal'];
                if ($aterAntal <= 0 || $aterAntal > (int) $lan['antal']) throw new AppFel('Ange ett antal mellan 1 och det som fortfarande är utlånat.');
                $artiklar['poster'][$ai]['saldo'][$lan['plats_id']] = (int) ($artikel['saldo'][$lan['plats_id']] ?? 0) + $aterAntal;
            }
            $lan['antal'] = (int) $lan['antal'] - $aterAntal;
            if ($lan['antal'] === 0) { $lan['status'] = 'aterlamnad'; $lan['aterlamnad'] = nu(); if (!empty($lan['saknad'])) $lan['saknad']['status'] = 'aterlamnad'; }
            $kvitto = ['id' => strtoupper(bin2hex(random_bytes(8))), 'tid' => nu(), 'antal' => $aterAntal, 'enheter' => $koder, 'kvar' => $lan['antal'], 'lanare' => $lan['lanare'], 'artikelnamn' => $artikel['namn'], 'plats_namn' => $plats['namn'], 'av' => $av['namn'] ?? '', 'skick' => $skick, 'notering' => $notering, 'bild' => $bild];
            $lan['aterlamningar'][] = $kvitto;
            $artiklar['poster'][$ai]['utlan'][$li] = $lan;
            $artiklar['poster'][$ai]['andrad'] = nu();
            $tx->sattFil('artiklar', $artiklar);
            $tx->historik(['typ' => 'aterlamning', 'artikel_id' => $artikel['id'], 'artikelnummer' => $artikel['artikelnummer'], 'artikelnamn' => $artikel['namn'], 'plats_id' => $lan['plats_id'], 'plats_namn' => $plats['namn'], 'antal' => $aterAntal, 'enheter' => $koder, 'detaljer' => ['utlan_id' => $lanId, 'kvitto_id' => $kvitto['id'], 'kvar' => $lan['antal'], 'lanare' => $lan['lanare'], 'skick' => $skick, 'notering' => $notering, 'bild' => $bild['fil'] ?? null]]);
            $tx->spara();
            return ['kvittokod' => $kvitto['id'], 'antal' => $aterAntal, 'kvar' => $lan['antal']];
        });
    }

    /** Skapa en uppföljning utan att ändra saldo eller enhetsstatus. */
    public static function anmalSaknad(string $lanId, string $version, string $ansvarigId, string $plan, array $av): void
    {
        $plan = trim($plan);
        if ($plan === '' || mb_strlen($plan) > 500) throw new AppFel('Ange en åtgärdsplan på högst 500 tecken.');
        Las::under(function () use ($lanId, $version, $ansvarigId, $plan, $av) {
            $tx = new Transaktion('utlan_saknad', $av); $artiklar = $tx->fil('artiklar');
            $ansvarig = Json::hitta($tx->fil('anvandare'), $ansvarigId);
            if ($ansvarig === null || empty($ansvarig['aktiv'])) throw new AppFel('Välj en aktiv ansvarig.');
            $hittad = false;
            foreach ($artiklar['poster'] as &$a) foreach ($a['utlan'] ?? [] as $i => $lan) {
                if (($lan['id'] ?? '') !== $lanId) continue;
                if (($lan['status'] ?? '') !== 'oppen') throw new AppFel('Utlåningen är inte öppen.');
                if (!hash_equals(self::version($lan), $version)) throw new AppFel('Utlåningen har ändrats. Ladda om sidan.');
                if (($lan['saknad']['status'] ?? '') === 'oppen') throw new AppFel('En saknadrapport finns redan.');
                $a['utlan'][$i]['saknad'] = ['status' => 'oppen', 'ansvarig_id' => $ansvarig['id'], 'ansvarig' => $ansvarig['namn'], 'plan' => $plan, 'tid' => nu()];
                $tx->historik(['typ' => 'utlan_saknad', 'artikel_id' => $a['id'], 'artikelnamn' => $a['namn'], 'antal' => $lan['antal'], 'kommentar' => $plan, 'detaljer' => ['utlan_id' => $lanId, 'ansvarig_id' => $ansvarig['id'], 'ansvarig' => $ansvarig['namn']]]);
                $hittad = true; break 2;
            }
            unset($a);
            if (!$hittad) throw new AppFel('Utlåningen finns inte.', 404);
            $tx->sattFil('artiklar', $artiklar); $tx->spara();
        });
    }

    /** Bekräftad avskrivning av hela återstoden. Saldot var redan draget vid utlåningen. */
    public static function avskrivSaknad(string $lanId, string $version, string $motivering, array $av): void
    {
        Lager::kravAdmin($av); $motivering = trim($motivering);
        if ($motivering === '' || mb_strlen($motivering) > 500) throw new AppFel('Ange motivering på högst 500 tecken.');
        Las::under(function () use ($lanId, $version, $motivering, $av) {
            $tx = new Transaktion('utlan_avskriven', $av); $artiklar = $tx->fil('artiklar'); $hittad = false;
            foreach ($artiklar['poster'] as &$a) foreach ($a['utlan'] ?? [] as $i => $lan) {
                if (($lan['id'] ?? '') !== $lanId) continue;
                if (($lan['status'] ?? '') !== 'oppen' || ($lan['saknad']['status'] ?? '') !== 'oppen') throw new AppFel('Det finns ingen öppen saknadrapport.');
                if (!hash_equals(self::version($lan), $version)) throw new AppFel('Utlåningen har ändrats. Ladda om sidan.');
                $koder = $lan['enheter'] ?? [];
                if (($a['sparningssatt'] ?? '') === Lager::SPAR_QR) {
                    $enheter = $tx->fil('enheter'); $index = [];
                    foreach ($enheter['poster'] as $ei => $en) $index[Koder::utanBindestreck((string) $en['id'])] = $ei;
                    foreach ($koder as $kod) {
                        if (!isset($index[$kod])) throw new AppFel('En utlånad enhet saknas i registret. Ingen avskrivning gjordes.');
                        $ei = $index[$kod]; $en = $enheter['poster'][$ei];
                        if (($en['status'] ?? '') !== 'utlanad' || ($en['utlan_id'] ?? '') !== $lanId || ($en['artikel_id'] ?? '') !== $a['id']) throw new AppFel('Enhetens status har ändrats. Ingen avskrivning gjordes.');
                        $enheter['poster'][$ei]['status'] = 'utgang'; unset($enheter['poster'][$ei]['utlan_id']);
                    }
                    $tx->sattFil('enheter', $enheter);
                }
                $a['utlan'][$i]['status'] = 'avskriven'; $a['utlan'][$i]['saknad']['status'] = 'avskriven';
                $a['utlan'][$i]['saknad']['avskriven'] = nu(); $a['utlan'][$i]['saknad']['motivering'] = $motivering;
                $tx->historik(['typ' => 'utlan_avskriven', 'artikel_id' => $a['id'], 'artikelnamn' => $a['namn'], 'plats_id' => $lan['plats_id'], 'antal' => $lan['antal'], 'enheter' => $koder, 'kommentar' => $motivering, 'detaljer' => ['utlan_id' => $lanId]]);
                $hittad = true; break 2;
            }
            unset($a);
            if (!$hittad) throw new AppFel('Utlåningen finns inte.', 404);
            $tx->sattFil('artiklar', $artiklar); $tx->spara();
        });
    }

    /** Administratör återaktiverar en kontrollerad skadad QR-enhet. */
    public static function godkannEnhet(string $kod, string $notering, array $av): void
    {
        Lager::kravAdmin($av); $kod = Koder::utanBindestreck($kod); $notering = trim($notering);
        if ($notering === '' || mb_strlen($notering) > 500) throw new AppFel('Beskriv kontrollen med 1–500 tecken.');
        Las::under(function () use ($kod, $notering, $av) {
            $tx = new Transaktion('verktyg_godkand', $av); $enheter = $tx->fil('enheter'); $finns = false;
            foreach ($enheter['poster'] as &$en) {
                if (Koder::utanBindestreck((string) $en['id']) !== $kod) continue;
                if (($en['status'] ?? '') !== 'skadad') throw new AppFel('Enheten är inte spärrad som skadad.');
                $artikel = Json::hitta($tx->fil('artiklar'), (string) $en['artikel_id']);
                $plats = Json::hitta($tx->fil('platser'), (string) ($en['plats_id'] ?? ''));
                if ($artikel === null || empty($artikel['aktiv']) || $plats === null || empty($plats['aktiv'])) throw new AppFel('Artikeln eller arbetsstället är inaktivt.');
                if (Inventering::oppenSessionForPlats($en['plats_id']) !== null) throw new AppFel('Avsluta inventeringen innan enheten godkänns.');
                $en['status'] = 'aktiv';
                $en['halsokort'][] = ['tid' => nu(), 'skick' => 'godkand', 'notering' => $notering, 'av' => $av['namn'] ?? ''];
                $tx->historik(['typ' => 'verktyg_godkand', 'artikel_id' => $artikel['id'], 'artikelnamn' => $artikel['namn'], 'plats_id' => $plats['id'], 'plats_namn' => $plats['namn'], 'enheter' => [$kod], 'kommentar' => $notering]);
                $finns = true; break;
            }
            unset($en);
            if (!$finns) throw new AppFel('Enheten finns inte.', 404);
            $tx->sattFil('enheter', $enheter); $tx->spara();
        });
    }

    /** Den inloggade låntagaren initierar och mottagaren verifieras med sin PIN. */
    public static function overlamna(string $lanId, string $version, array $av, array $mottagare): void
    {
        Las::under(function () use ($lanId, $version, $av, $mottagare) {
            $tx = new Transaktion('utlan_overlamnad', $av);
            $artiklar = $tx->fil('artiklar');
            $aktuellaKonton = $tx->fil('anvandare');
            $fran = Json::hitta($aktuellaKonton, (string) ($av['id'] ?? ''));
            $till = Json::hitta($aktuellaKonton, (string) ($mottagare['id'] ?? ''));
            if ($fran === null || empty($fran['aktiv']) || !empty($fran['maste_byta_pin']) || ($fran['pin_hash'] ?? '') !== ($av['pin_hash'] ?? '')
                || $till === null || empty($till['aktiv']) || !empty($till['maste_byta_pin']) || ($till['pin_hash'] ?? '') !== ($mottagare['pin_hash'] ?? '')
                || $fran['id'] === $till['id']) throw new AppFel('Användarkontot har ändrats eller mottagaren är samma person. Bekräfta på nytt.');
            $hittad = false;
            foreach ($artiklar['poster'] as &$artikel) {
                foreach ($artikel['utlan'] ?? [] as $i => $lan) {
                    if (($lan['id'] ?? '') !== $lanId) continue;
                    if (($lan['status'] ?? '') !== 'oppen') throw new AppFel('Utlåningen är inte längre öppen.');
                    if (!hash_equals(self::version($lan), $version)) throw new AppFel('Utlåningen har ändrats. Ladda om sidan.');
                    if (mb_strtolower(trim((string) $lan['lanare'])) !== mb_strtolower(trim((string) $fran['namn']))) throw new AppFel('Endast den inloggade låntagaren kan överlämna lånet.');
                    $artikel['utlan'][$i]['lanare'] = $till['namn'];
                    $artikel['utlan'][$i]['overlamningar'][] = ['tid' => nu(), 'fran_id' => $fran['id'], 'fran' => $fran['namn'], 'till_id' => $till['id'], 'till' => $till['namn']];
                    $tx->historik(['typ' => 'utlan_overlamnad', 'artikel_id' => $artikel['id'], 'artikelnamn' => $artikel['namn'], 'plats_id' => $lan['plats_id'], 'antal' => $lan['antal'], 'detaljer' => ['utlan_id' => $lanId, 'fran' => $fran['namn'], 'till' => $till['namn'], 'till_id' => $till['id']]]);
                    $hittad = true; break 2;
                }
            }
            unset($artikel);
            if (!$hittad) throw new AppFel('Utlåningen finns inte.', 404);
            $tx->sattFil('artiklar', $artiklar); $tx->spara();
        });
    }

    public static function kvitto(string $lanId, string $kvittoId): ?array
    {
        foreach (Lager::artiklar() as $artikel) {
            foreach ($artikel['utlan'] ?? [] as $lan) {
                if (($lan['id'] ?? '') !== $lanId) continue;
                foreach ($lan['aterlamningar'] ?? [] as $kvitto) {
                    if (($kvitto['id'] ?? '') === $kvittoId) return $kvitto + ['artikelnamn' => $artikel['namn'], 'lanare' => $lan['lanare'], 'plats_namn' => $lan['plats_namn']];
                }
            }
        }
        return null;
    }
}
