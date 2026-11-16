<?php
/**
 * Kontohantering – PIN-baserade konton med rollkontroll på serversidan.
 *
 * Personal loggar in med namn + PIN. Adminpanelens kontovy visar PIN-koden
 * EN gång vid skapande/byte (den finns inte i klartext i lagringen), och
 * kan därefter aldrig visas igen.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Konto;
use TDL\Support\Koder;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Kontohantering
{
    public const ROLLER = ['admin', 'personal'];

    public static function alla(): array
    {
        $alla = Konto::anvandare();
        usort($alla, fn ($a, $b) => strcmp($a['namn'], $b['namn']));
        return $alla;
    }

    public static function konto(string $id): ?array
    {
        return Json::hitta(Json::las('anvandare'), $id);
    }

    public static function skapa(string $namn, string $pin, string $roll, array $av): array
    {
        return Konto::skapaKonto($namn, $pin, $roll, $av, true);
    }

    /** Slumpa en PIN-kod som admin kan lämna ut (visas en gång). */
    public static function slumpaPin(): string
    {
        do {
            $pin = (string) random_int(1000, 9999);
        } while (in_array($pin, ['1234', '4321', '1111', '0000', '1234'], true));
        return $pin;
    }

    public static function bytRoll(string $kontoId, string $roll, array $av): void
    {
        if (!in_array($roll, self::ROLLER, true)) {
            throw new AppFel('Ogiltig roll.');
        }
        Las::under(function () use ($kontoId, $roll, $av) {
            $tx = new Transaktion('konto_andrad', $av);
            $fil = $tx->fil('anvandare');
            $konto = Json::hitta($fil, $kontoId);
            if ($konto === null) {
                throw new AppFel('Kontot finns inte.', 404);
            }
            if ($konto['id'] === ($av['id'] ?? '') && $roll !== 'admin') {
                throw new AppFel('Du kan inte ta bort din egen administratörsroll.');
            }
            if ($konto['roll'] === 'admin' && $roll === 'personal') {
                $aktivaAdmin = 0;
                foreach ($fil['poster'] as $u) {
                    if ($u['roll'] === 'admin' && !empty($u['aktiv'])) {
                        $aktivaAdmin++;
                    }
                }
                if ($aktivaAdmin <= 1) {
                    throw new AppFel('Den sista administratören kan inte ändras till personal.');
                }
            }
            $gammal = $konto['roll'];
            Json::uppdatera($fil, $kontoId, function ($u) use ($roll) {
                $u['roll'] = $roll;
                $u['andrad'] = nu();
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'konto_andrad',
                'detaljer' => ['konto_id' => $kontoId, 'namn' => $konto['namn'], 'fore' => $gammal, 'efter' => $roll],
            ]);
            $tx->spara();
        });
    }

    public static function bytNamn(string $kontoId, string $namn, array $av): void
    {
        $namn = trim($namn);
        if (mb_strlen($namn) < 2 || mb_strlen($namn) > 40) {
            throw new AppFel('Namnet måste vara 2–40 tecken.');
        }
        Las::under(function () use ($kontoId, $namn, $av) {
            $tx = new Transaktion('konto_andrad', $av);
            $fil = $tx->fil('anvandare');
            foreach ($fil['poster'] as $u) {
                if ($u['id'] !== $kontoId && mb_strtolower($u['namn']) === mb_strtolower($namn)) {
                    throw new AppFel('Namnet används redan av ett annat konto.');
                }
            }
            Json::uppdatera($fil, $kontoId, function ($u) use ($namn) {
                $u['namn'] = $namn;
                $u['andrad'] = nu();
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->spara();
        });
    }
}
