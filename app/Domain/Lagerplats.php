<?php
/**
 * Lagerplatser: zoner, hyllor och fack – och hyllplatsernas QR-etiketter.
 *
 * MODELL
 *   zon          Ett fysiskt område, t.ex. "A – Verkstad" eller "Förråd".
 *                Har en kod (A, B, F) och ett antal rader och fack som
 *                bestämmer rutnätet på kartan.
 *   hyllplats    En fysisk hylla/fackposition: zon + rad + fack. Får en egen
 *                QR-kod (TDL-L-…) som kan skrivas ut och sättas på hyllan.
 *   artikel      Har fälten zon_id, rad och fack. Tre nivåer, inga fritext-
 *                varianter som "a3" kontra "A-3".
 *
 * VARFÖR HYLLPLATSEN ÄR ETT EGET OBJEKT
 * En hylla finns på riktigt även när den är tom. Därför kan man skriva ut
 * lappen innan man fyller hyllan, och kameran kan tala om var man står även
 * för ett tomt fack. QR-koden innehåller hyllplatsens id, inte artikelns.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Koder;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Lagerplats
{
    public const MAX_ZONER = 40;
    public const MAX_RADER = 60;
    public const MAX_FACK = 40;
    public const NAMN_MAX = 40;

    /* ================================================================
     *  Zoner
     * ================================================================ */

    public static function zoner(): array
    {
        $ut = Json::las('zoner')['poster'];
        usort($ut, fn ($a, $b) => strnatcasecmp((string) ($a['kod'] ?? ''), (string) ($b['kod'] ?? '')));
        return $ut;
    }

    public static function zon(string $id): ?array
    {
        return Json::hitta(Json::las('zoner'), $id);
    }

    public static function zonViaKod(string $kod): ?array
    {
        $k = mb_strtoupper(trim($kod));
        foreach (self::zoner() as $z) {
            if (mb_strtoupper((string) $z['kod']) === $k) {
                return $z;
            }
        }
        return null;
    }

    public static function skapaZon(string $namn, string $kod, int $rader = 5, int $fack = 8, ?array $anvandare = null): array
    {
        $namn = trim((string) preg_replace('/\s+/u', ' ', $namn));
        $kod = mb_strtoupper(trim($kod));

        if ($namn === '' || mb_strlen($namn) > self::NAMN_MAX) {
            throw new AppFel('Zonens namn måste vara 1–' . self::NAMN_MAX . ' tecken.');
        }
        if (!preg_match('/^[A-ZÅÄÖ0-9]{1,3}$/u', $kod)) {
            throw new AppFel('Zonkoden ska vara 1–3 tecken: bokstäver eller siffror, t.ex. A eller F2.');
        }
        if ($rader < 1 || $rader > self::MAX_RADER) {
            throw new AppFel('Antal rader ska vara 1–' . self::MAX_RADER . '.');
        }
        if ($fack < 1 || $fack > self::MAX_FACK) {
            throw new AppFel('Antal fack per rad ska vara 1–' . self::MAX_FACK . '.');
        }

        return Las::under(function () use ($namn, $kod, $rader, $fack, $anvandare) {
            $tx = new Transaktion('zon_skapad', $anvandare);
            $zoner = $tx->fil('zoner');
            if (count($zoner['poster']) >= self::MAX_ZONER) {
                throw new AppFel('Det finns redan ' . self::MAX_ZONER . ' zoner. Ta bort en först.');
            }
            foreach ($zoner['poster'] as $z) {
                if (mb_strtoupper((string) $z['kod']) === $kod) {
                    throw new AppFel('Zonkoden ' . $kod . ' används redan av ' . $z['namn'] . '.');
                }
                if (mb_strtolower((string) $z['namn']) === mb_strtolower($namn)) {
                    throw new AppFel('Det finns redan en zon som heter ' . $namn . '.');
                }
            }
            $zon = [
                'id' => Koder::interntId('Z'),
                'namn' => $namn,
                'kod' => $kod,
                'rader' => $rader,
                'fack' => $fack,
                'skapad' => nu(),
            ];
            Json::laggTill($zoner, $zon);
            $tx->sattFil('zoner', $zoner);
            $tx->historik([
                'typ' => 'zon_skapad',
                'kommentar' => 'Zon ' . $kod . ' – ' . $namn . ' (' . $rader . ' rader × ' . $fack . ' fack)',
            ]);
            $tx->spara();
            return $zon;
        });
    }

    public static function andraZon(string $id, array $data, ?array $anvandare = null): array
    {
        $zon = self::zon($id);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte.', 404);
        }
        $namn = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['namn'] ?? $zon['namn'])));
        $kod = mb_strtoupper(trim((string) ($data['kod'] ?? $zon['kod'])));
        $rader = (int) ($data['rader'] ?? $zon['rader']);
        $fack = (int) ($data['fack'] ?? $zon['fack']);

        if ($namn === '' || mb_strlen($namn) > self::NAMN_MAX) {
            throw new AppFel('Zonens namn måste vara 1–' . self::NAMN_MAX . ' tecken.');
        }
        if (!preg_match('/^[A-ZÅÄÖ0-9]{1,3}$/u', $kod)) {
            throw new AppFel('Zonkoden ska vara 1–3 tecken: bokstäver eller siffror.');
        }
        if ($rader < 1 || $rader > self::MAX_RADER) {
            throw new AppFel('Antal rader ska vara 1–' . self::MAX_RADER . '.');
        }
        if ($fack < 1 || $fack > self::MAX_FACK) {
            throw new AppFel('Antal fack per rad ska vara 1–' . self::MAX_FACK . '.');
        }

        return Las::under(function () use ($id, $namn, $kod, $rader, $fack, $anvandare) {
            $tx = new Transaktion('zon_andrad', $anvandare);
            $zoner = $tx->fil('zoner');
            $artiklar = $tx->fil('artiklar');

            foreach ($zoner['poster'] as $z) {
                if ($z['id'] !== $id && mb_strtoupper((string) $z['kod']) === $kod) {
                    throw new AppFel('Zonkoden ' . $kod . ' används redan av ' . $z['namn'] . '.');
                }
            }
            $utanfor = [];
            foreach ($artiklar['poster'] as $a) {
                if (($a['zon_id'] ?? '') !== $id) {
                    continue;
                }
                if ((int) ($a['rad'] ?? 0) > $rader || (int) ($a['fack'] ?? 0) > $fack) {
                    $utanfor[] = $a['namn'];
                }
            }
            if ($utanfor !== []) {
                $exempel = implode(', ', array_slice($utanfor, 0, 3));
                throw new AppFel('Rutnätet kan inte krympas: ' . count($utanfor) . ' artiklar hamnar utanför ('
                    . $exempel . (count($utanfor) > 3 ? ' m.fl.' : '') . '). Flytta dem först.');
            }
            $hyllor = $tx->fil('hyllplatser');
            $bort = 0;
            foreach ($hyllor['poster'] as $i => $h) {
                if (($h['zon_id'] ?? '') !== $id) {
                    continue;
                }
                if ((int) $h['rad'] > $rader || (int) $h['fack'] > $fack) {
                    $bort++;
                    unset($hyllor['poster'][$i]);
                }
            }
            $hyllor['poster'] = array_values($hyllor['poster']);

            Json::uppdatera($zoner, $id, function ($z) use ($namn, $kod, $rader, $fack) {
                $z['namn'] = $namn;
                $z['kod'] = $kod;
                $z['rader'] = $rader;
                $z['fack'] = $fack;
                return $z;
            });
            $tx->sattFil('zoner', $zoner);
            $tx->sattFil('hyllplatser', $hyllor);
            $tx->historik([
                'typ' => 'zon_andrad',
                'kommentar' => 'Zon ' . $kod . ' – ' . $namn . ' (' . $rader . ' × ' . $fack . ')'
                    . ($bort > 0 ? '. ' . $bort . ' hyllplatser utanför rutnätet togs bort.' : ''),
            ]);
            $tx->spara();
            return self::zon($id) ?? [];
        });
    }

    /* ================================================================
     *  Platsangivelse på artiklar
     * ================================================================ */

    public static function valideraPlats(mixed $zonId, mixed $rad, mixed $fack): array
    {
        $zonId = trim((string) $zonId);
        if ($zonId === '') {
            throw new AppFel('Välj en zon. Varje artikel måste ha en plats i lagret.');
        }
        $zon = self::zon($zonId);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte. Skapa zonen under Lagerplats först.');
        }
        $r = (int) $rad;
        $f = (int) $fack;
        if ($r < 1 || $r > (int) $zon['rader']) {
            throw new AppFel('Raden måste vara 1–' . (int) $zon['rader'] . ' i zon ' . $zon['kod'] . '.');
        }
        if ($f < 1 || $f > (int) $zon['fack']) {
            throw new AppFel('Facket måste vara 1–' . (int) $zon['fack'] . ' i zon ' . $zon['kod'] . '.');
        }
        return ['zon_id' => $zonId, 'rad' => $r, 'fack' => $f];
    }

    public static function platsText(?array $artikel): string
    {
        if ($artikel === null) {
            return '';
        }
        $zonId = (string) ($artikel['zon_id'] ?? '');
        if ($zonId === '') {
            return '';
        }
        $zon = self::zon($zonId);
        $kod = $zon === null ? '?' : (string) $zon['kod'];
        return $kod . '-' . (int) ($artikel['rad'] ?? 0) . '-' . (int) ($artikel['fack'] ?? 0);
    }

    public static function sorteringsNyckel(array $artikel): string
    {
        $zon = self::zon((string) ($artikel['zon_id'] ?? ''));
        $kod = $zon === null ? '~~~' : (string) $zon['kod'];
        return sprintf('%s-%03d-%03d', $kod, (int) ($artikel['rad'] ?? 0), (int) ($artikel['fack'] ?? 0));
    }

    public static function utanPlats(): array
    {
        $ut = [];
        foreach (Lager::artiklar() as $a) {
            if ((string) ($a['zon_id'] ?? '') === '') {
                $ut[] = $a;
            }
        }
        usort($ut, fn ($a, $b) => strcasecmp($a['namn'], $b['namn']));
        return $ut;
    }

    /* ================================================================
     *  Hyllplatser och deras QR
     * ================================================================ */

    public static function hyllplatser(?string $zonId = null): array
    {
        $ut = [];
        foreach (Json::las('hyllplatser')['poster'] as $h) {
            if ($zonId !== null && ($h['zon_id'] ?? '') !== $zonId) {
                continue;
            }
            $ut[] = $h;
        }
        usort($ut, fn ($a, $b) => [(int) $a['rad'], (int) $a['fack']] <=> [(int) $b['rad'], (int) $b['fack']]);
        return $ut;
    }

    public static function hyllplats(string $id): ?array
    {
        return Json::hitta(Json::las('hyllplatser'), $id);
    }

    public static function hyllplatsViaQr(string $kod): ?array
    {
        $u = Koder::utanBindestreck($kod);
        if ($u === '') {
            return null;
        }
        foreach (Json::las('hyllplatser')['poster'] as $h) {
            if (Koder::utanBindestreck((string) ($h['qr'] ?? '')) === $u) {
                return $h;
            }
        }
        return null;
    }

    public static function hyllplatsFor(string $zonId, int $rad, int $fack): ?array
    {
        foreach (Json::las('hyllplatser')['poster'] as $h) {
            if (($h['zon_id'] ?? '') === $zonId && (int) $h['rad'] === $rad && (int) $h['fack'] === $fack) {
                return $h;
            }
        }
        return null;
    }

    /**
     * Skapar hyllplatser för ett helt rutnät, eller bara de som saknas.
     * Skapas i en enda transaktion så att antingen finns hela zonen eller ingen.
     */
    public static function skapaHyllplatser(string $zonId, ?array $anvandare = null): array
    {
        $zon = self::zon($zonId);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte.', 404);
        }

        return Las::under(function () use ($zon, $anvandare) {
            $tx = new Transaktion('hyllplatser_skapade', $anvandare);
            $hyllor = $tx->fil('hyllplatser');
            $befintliga = [];
            foreach ($hyllor['poster'] as $h) {
                if (($h['zon_id'] ?? '') === $zon['id']) {
                    $befintliga[$h['rad'] . '-' . $h['fack']] = true;
                }
            }
            $nya = [];
            for ($r = 1; $r <= (int) $zon['rader']; $r++) {
                for ($f = 1; $f <= (int) $zon['fack']; $f++) {
                    if (isset($befintliga[$r . '-' . $f])) {
                        continue;
                    }
                    $h = [
                        'id' => Koder::interntId('H'),
                        'zon_id' => $zon['id'],
                        'rad' => $r,
                        'fack' => $f,
                        'qr' => self::nyQr($hyllor),
                        'skapad' => nu(),
                    ];
                    Json::laggTill($hyllor, $h);
                    $nya[] = $h;
                }
            }
            if ($nya === []) {
                throw new AppFel('Alla hyllplatser i zon ' . $zon['kod'] . ' finns redan.');
            }
            $tx->sattFil('hyllplatser', $hyllor);
            $tx->historik([
                'typ' => 'hyllplatser_skapade',
                'kommentar' => count($nya) . ' hyllplatser skapades i zon ' . $zon['kod'] . ' – ' . $zon['namn'],
            ]);
            $tx->spara();
            return $nya;
        });
    }

    /** Ny unik QR-kod för en hylla (krockar aldrig med plats- eller enhetskoder). */
    private static function nyQr(array $hyllor): string
    {
        $tagna = [];
        foreach ($hyllor['poster'] as $h) {
            $tagna[Koder::utanBindestreck((string) ($h['qr'] ?? ''))] = true;
        }
        foreach (Json::las('platser')['poster'] as $p) {
            $tagna[Koder::utanBindestreck((string) ($p['qr'] ?? ''))] = true;
        }
        do {
            $kod = Koder::nyKod('L');
        } while (isset($tagna[Koder::utanBindestreck($kod)]));
        return $kod;
    }

    /* ================================================================
     *  Innehåll per hylla och kartan
     * ================================================================ */

    public static function artiklarPa(string $zonId, int $rad, int $fack): array
    {
        $ut = [];
        foreach (Lager::artiklar(true) as $a) {
            if (($a['zon_id'] ?? '') === $zonId && (int) ($a['rad'] ?? 0) === $rad && (int) ($a['fack'] ?? 0) === $fack) {
                $ut[] = $a;
            }
        }
        usort($ut, fn ($a, $b) => strcasecmp($a['namn'], $b['namn']));
        return $ut;
    }

    /**
     * Kartan för en zon: rutnät med artiklarna per fack.
     * max används för att färglägga rutorna efter hur fulla de är.
     */
    public static function karta(string $zonId): array
    {
        $zon = self::zon($zonId);
        if ($zon === null) {
            throw new AppFel('Zonen finns inte.', 404);
        }
        $per = [];
        foreach (Lager::artiklar(true) as $a) {
            if (($a['zon_id'] ?? '') !== $zonId) {
                continue;
            }
            $per[(int) $a['rad'] . '-' . (int) $a['fack']][] = $a;
        }
        $hyllor = [];
        foreach (self::hyllplatser($zonId) as $h) {
            $hyllor[(int) $h['rad'] . '-' . (int) $h['fack']] = $h;
        }
        $rutor = [];
        $max = 0;
        for ($r = 1; $r <= (int) $zon['rader']; $r++) {
            for ($f = 1; $f <= (int) $zon['fack']; $f++) {
                $nyckel = $r . '-' . $f;
                $artiklar = $per[$nyckel] ?? [];
                usort($artiklar, fn ($a, $b) => strcasecmp($a['namn'], $b['namn']));
                $antal = count($artiklar);
                $max = max($max, $antal);
                $rutor[$r][$f] = [
                    'rad' => $r,
                    'fack' => $f,
                    'artiklar' => $artiklar,
                    'antal' => $antal,
                    'hyllplats' => $hyllor[$nyckel] ?? null,
                ];
            }
        }
        return ['zon' => $zon, 'rutor' => $rutor, 'max' => $max];
    }

    public static function antalPerZon(): array
    {
        $ut = [];
        foreach (Lager::artiklar(true) as $a) {
            $z = (string) ($a['zon_id'] ?? '');
            if ($z !== '') {
                $ut[$z] = ($ut[$z] ?? 0) + 1;
            }
        }
        return $ut;
    }
}
