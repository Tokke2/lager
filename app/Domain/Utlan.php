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
                    $resultat[] = $lan + ['artikel_id' => $artikel['id'], 'artikelnamn' => $artikel['namn']];
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

    public static function aterlamna(string $lanId, array $av): void
    {
        Las::under(function () use ($lanId, $av) {
            $tx = new Transaktion('aterlamning', $av);
            $artiklar = $tx->fil('artiklar');
            $hittad = null; $index = null;
            foreach ($artiklar['poster'] as $ai => $artikel) {
                foreach ($artikel['utlan'] ?? [] as $li => $lan) {
                    if (($lan['id'] ?? '') === $lanId) { $hittad = [$ai, $li, $lan]; break 2; }
                }
            }
            if ($hittad === null) throw new AppFel('Utlåningen finns inte.', 404);
            [$ai, $li, $lan] = $hittad;
            if (($lan['status'] ?? '') !== 'oppen') throw new AppFel('Utlåningen är redan återlämnad.');
            $artikel = $artiklar['poster'][$ai];
            $plats = Json::hitta($tx->fil('platser'), $lan['plats_id']);
            if ($plats === null || empty($plats['aktiv']) || !empty($plats['system'])) throw new AppFel('Arbetsstället är inte aktivt.');
            if (empty($artikel['aktiv'])) throw new AppFel('Artikeln är inte aktiv.');
            if (Inventering::oppenSessionForPlats($lan['plats_id']) !== null) throw new AppFel('Avsluta inventeringen på arbetsstället innan återlämning.');
            if (($artikel['sparningssatt'] ?? '') === Lager::SPAR_QR) {
                $enheter = $tx->fil('enheter'); $index = [];
                foreach ($enheter['poster'] as $i => $en) $index[Koder::utanBindestreck((string) $en['id'])] = $i;
                foreach ($lan['enheter'] as $kod) {
                    if (!isset($index[$kod])) throw new AppFel('En utlånad enhet saknas. Ingen återlämning gjordes.');
                    $i = $index[$kod]; $en = $enheter['poster'][$i];
                    if (($en['status'] ?? '') !== 'utlanad' || ($en['utlan_id'] ?? '') !== $lanId || ($en['artikel_id'] ?? '') !== $artikel['id']) throw new AppFel('Enhetens status har ändrats. Ingen återlämning gjordes.');
                    $enheter['poster'][$i]['status'] = 'aktiv';
                    $enheter['poster'][$i]['plats_id'] = $lan['plats_id'];
                    unset($enheter['poster'][$i]['utlan_id']);
                }
                $tx->sattFil('enheter', $enheter);
            } else {
                $artiklar['poster'][$ai]['saldo'][$lan['plats_id']] = (int) ($artikel['saldo'][$lan['plats_id']] ?? 0) + (int) $lan['antal'];
            }
            $artiklar['poster'][$ai]['utlan'][$li]['status'] = 'aterlamnad';
            $artiklar['poster'][$ai]['utlan'][$li]['aterlamnad'] = nu();
            $artiklar['poster'][$ai]['andrad'] = nu();
            $tx->sattFil('artiklar', $artiklar);
            $tx->historik(['typ' => 'aterlamning', 'artikel_id' => $artikel['id'], 'artikelnummer' => $artikel['artikelnummer'], 'artikelnamn' => $artikel['namn'], 'plats_id' => $lan['plats_id'], 'plats_namn' => $plats['namn'], 'antal' => (int) $lan['antal'], 'enheter' => $lan['enheter'], 'detaljer' => ['utlan_id' => $lanId, 'lanare' => $lan['lanare']]]);
            $tx->spara();
        });
    }
}
