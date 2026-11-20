<?php
/**
 * Konton, roller och sessioner.
 *
 * Roller:
 *   admin     – allt, inklusive konton, artiklar, platser och avstämning
 *   personal  – dagliga lageråtgärder (inleverans, uttag, överföring,
 *               inventering, etiketter). Når inte admin-API:er.
 *
 * Inloggning:
 *   personal  – PIN-kod (4–8 siffror). Välj namn, ange PIN.
 *   admin     – LÖSENORD (minst 12 tecken) OCH PIN-kod. Lösenordet måste bytas
 *               var 20:e dag (LÖSENORD_DAGAR). PIN-koden räcker inte ensam för
 *               en admin – det skyddar mot att någon ser PIN-koden över axeln.
 *
 * Efter 5 fel i rad på samma konto spärras kontot i 5 minuter.
 */

declare(strict_types=1);

namespace TDL\Support;

use TDL\Domain\Lager;

final class Konto
{
    public const PIN_MIN = 4;
    public const PIN_MAX = 8;
    public const MAX_FORSOK = 5;
    public const SPARR_MINUTER = 5;

    /** Admin-lösenord: minst 12 tecken och måste bytas var 20:e dag. */
    public const LÖSENORD_MIN = 12;
    public const LÖSENORD_DAGAR = 20;
    /** Varning visas när det är färre dagar kvar än så. */
    public const LÖSENORD_VARNING_DAGAR = 5;

    public static function anvandare(): array
    {
        return Json::las('anvandare')['poster'];
    }

    public static function aktivAnvandare(string $id): ?array
    {
        $u = Json::hitta(Json::las('anvandare'), $id);
        return $u !== null && !empty($u['aktiv']) ? $u : null;
    }

    public static function kontoViaNamn(string $namn): ?array
    {
        foreach (self::anvandare() as $u) {
            if (mb_strtolower($u['namn']) === mb_strtolower(trim($namn))) {
                return $u;
            }
        }
        return null;
    }

    /* ================================================================
     *  Lösenord – endast för administratörer
     * ================================================================ */

    /** Krav på admin-lösenord: 12+ tecken, blandat. */
    public static function valideraLosenord(string $losenord): string
    {
        if (mb_strlen($losenord) < self::LÖSENORD_MIN) {
            throw new AppFel('Lösenordet måste vara minst ' . self::LÖSENORD_MIN . ' tecken.');
        }
        $harBokstav = preg_match('/[A-Za-zÅÄÖåäö]/u', $losenord) === 1;
        $harSiffra  = preg_match('/[0-9]/', $losenord) === 1;
        if (!$harBokstav || !$harSiffra) {
            throw new AppFel('Lösenordet måste innehålla både bokstäver och siffror.');
        }
        $vanliga = ['lösenord', 'losenord', 'password', '123456', 'qwerty', 'admin123', 'tadega'];
        if (in_array(mb_strtolower($losenord), $vanliga, true)
            || preg_match('/^(losenord|lösenord|password|tadega|admin)\d*$/iu', $losenord) === 1) {
            throw new AppFel('Lösenordet är för vanligt. Välj ett annat som inte är enbart ordet självt.');
        }
        return $losenord;
    }

    /** Behöver admin byta sin PIN-kod? */
    public static function losenordUtgatt(array $konto): bool
    {
        if (($konto['roll'] ?? '') !== 'admin') {
            return false;
        }
        $satt = $konto['pin_satt'] ?? $konto['pin_andrad'] ?? $konto['losenord_satt'] ?? $konto['skapad'] ?? null;
        if ($satt === null) {
            return true;
        }
        $dagar = self::dagarKvarPaLosenord($konto);
        return $dagar !== null && $dagar <= 0;
    }

    /**
     * Dagar kvar innan PIN-koden måste bytas.
     * null = kontot är inte admin eller saknar datum.
     */
    public static function dagarKvarPaLosenord(array $konto): ?int
    {
        $sattDatum = $konto['pin_satt'] ?? $konto['pin_andrad'] ?? $konto['losenord_satt'] ?? $konto['skapad'] ?? null;
        if (($konto['roll'] ?? '') !== 'admin' || empty($sattDatum)) {
            return null;
        }
        try {
            $satt = new \DateTimeImmutable((string) $sattDatum);
            $utgar = $satt->modify('+' . self::LÖSENORD_DAGAR . ' days');
            $nu = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $sekunder = $utgar->getTimestamp() - $nu->getTimestamp();
            return (int) floor($sekunder / 86400);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Visa varning i gränssnittet? */
    public static function losenordSnartUtgatt(array $konto): bool
    {
        $kvar = self::dagarKvarPaLosenord($konto);
        return $kvar !== null && $kvar <= self::LÖSENORD_VARNING_DAGAR;
    }

    /** Sätt nytt lösenord. Kräver att man anger det nuvarande om man inte är admin. */
    public static function andraLosenord(string $kontoId, string $nytt, string $nuvarande, array $av, bool $avAdmin = false): void
    {
        $nytt = self::valideraLosenord($nytt);
        Las::under(function () use ($kontoId, $nytt, $nuvarande, $av, $avAdmin) {
            $tx = new Transaktion('losenord_andrat', $av);
            $fil = $tx->fil('anvandare');
            $konto = Json::hitta($fil, $kontoId);
            if ($konto === null) {
                throw new AppFel('Kontot finns inte.', 404);
            }
            if (($konto['roll'] ?? '') !== 'admin') {
                throw new AppFel('Lösenord används bara för administratörer. Personal loggar in med PIN-kod.');
            }
            if (!$avAdmin && !password_verify($nuvarande, (string) ($konto['losenord_hash'] ?? ''))) {
                throw new AppFel('Det nuvarande lösenordet stämmer inte.');
            }
            $historik = $konto['losenord_historik'] ?? [];
            if (password_verify($nytt, (string) ($konto['losenord_hash'] ?? ''))
                || in_array(hash('sha256', $nytt), array_column($historik, 'hash'), true)) {
                throw new AppFel('Lösenordet har använts tidigare för det här kontot. Välj ett nytt.');
            }
            // Historiken ska innehålla de lösenord som använts, alltså även det
            // som just nu är giltigt och därmed byts bort i det här steget.
            $hashNu = (string) ($konto['losenord_hash'] ?? '');
            $nyHistorik = array_slice(array_merge(
                $historik,
                [['hash' => hash('sha256', $nytt), 'tid' => nu()]],
                $hashNu !== '' ? [['hash' => hash('sha256', $hashNu), 'tid' => $konto['losenord_satt'] ?? null]] : []
            ), -8);
            Json::uppdatera($fil, $kontoId, function ($u) use ($nytt, $nyHistorik) {
                $u['losenord_hash'] = password_hash($nytt, PASSWORD_DEFAULT);
                $u['losenord_satt'] = nu();
                $u['losenord_maste_bytas'] = false;
                $u['losenord_historik'] = $nyHistorik;
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'losenord_andrat',
                'detaljer' => ['konto_id' => $kontoId, 'namn' => $konto['namn'], 'av_admin' => $avAdmin],
            ]);
            $tx->spara();
        });
    }

    /**
     * Kontrollera inloggning. Personal: PIN. Admin: lösenord + PIN.
     * Returnerar kontot vid lyckat försök.
     */
    public static function verifieraInloggning(string $namn, string $pin, string $losenord = ''): array
    {
        $konto = self::kontoViaNamn($namn);
        if ($konto === null) {
            throw new AppFel('Fel namn eller PIN-kod.');
        }
        if (empty($konto['aktiv'])) {
            throw new AppFel('Kontot är avstängt. Kontakta en administratör.');
        }
        $nu = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!empty($konto['sparrad_till'])) {
            $till = new \DateTimeImmutable((string) $konto['sparrad_till']);
            if ($till > $nu) {
                $kvar = max(1, (int) ceil((($till->getTimestamp() - $nu->getTimestamp()) / 60)));
                throw new AppFel('För många felaktiga försök. Försök igen om ' . $kvar . ' minut(er).');
            }
        }

        if (!password_verify($pin, (string) $konto['pin_hash'])) {
            self::registreraMisslyckande($konto);
            throw new AppFel('Fel namn eller PIN-kod.');
        }

        // Lyckat försök: nollställ räknaren.
        Las::under(function () use ($konto) {
            $tx = new Transaktion('konto_andrad', null);
            $fil = $tx->fil('anvandare');
            Json::uppdatera($fil, $konto['id'], function ($u) {
                $u['misslyckade'] = 0;
                $u['sparrad_till'] = null;
                $u['senast_inloggad'] = nu();
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->spara();
        });
        return self::aktivAnvandare($konto['id']) ?? $konto;
    }

    public static function valideraPin(string $pin): string
    {
        if (!preg_match('/^[0-9!@#$%^&*()_+\-=\[\]{};:,.<>?\/~]{4,16}$/', $pin)) {
            throw new AppFel('PIN-koden ska vara 4–16 tecken (siffror och/eller specialtecken).');
        }
        if (preg_match('/^(.)\1+$/', $pin) || $pin === '1234' || $pin === '12345' || $pin === '0000' || $pin === '1111') {
            throw new AppFel('PIN-koden är för enkel. Välj en annan.');
        }
        return $pin;
    }

    /**
     * Skapa konto. PIN-koden hashas med password_hash; klartexten lagras aldrig.
     */
    public static function skapaKonto(string $namn, string $pin, string $roll, array $av, bool $masteByta = false): array
    {
        $namn = trim($namn);
        if (mb_strlen($namn) < 2 || mb_strlen($namn) > 40) {
            throw new AppFel('Namnet måste vara 2–40 tecken.');
        }
        if (!in_array($roll, ['admin', 'personal'], true)) {
            throw new AppFel('Rollen måste vara admin eller personal.');
        }
        $pin = self::valideraPin($pin);
        if (self::kontoViaNamn($namn) !== null) {
            throw new AppFel('Det finns redan ett konto med namnet ' . $namn . '.');
        }

        return Las::under(function () use ($namn, $pin, $roll, $av, $masteByta) {
            $tx = new Transaktion('konto_skapad', $av);
            $fil = $tx->fil('anvandare');
            $konto = [
                'id' => Koder::interntId('K', 4),
                'namn' => $namn,
                'roll' => $roll,
                'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
                'aktiv' => true,
                'maste_byta_pin' => $masteByta,
                'misslyckade' => 0,
                'sparrad_till' => null,
                'skapad' => nu(),
            ];
            Json::laggTill($fil, $konto);
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'konto_skapad',
                'detaljer' => ['konto_id' => $konto['id'], 'namn' => $namn, 'roll' => $roll],
            ]);
            $tx->spara();
            return $konto;
        });
    }

    /**
     * Första administratören. Får bara köras innan konton finns.
     * Anropas från bin/installera.php.
     */
    public static function skapaForstaAdmin(string $namn, string $pin, bool $masteBytaPin = true): array
    {
        if (!self::behoverForstaAdmin()) {
            throw new AppFel('Det finns redan konton. Första administratören kan bara skapas en gång.');
        }
        return Las::under(function () use ($namn, $pin, $masteBytaPin) {
            $tx = new Transaktion('konto_skapad', null);
            $fil = $tx->fil('anvandare');
            $konto = [
                'id' => Koder::interntId('K', 4),
                'namn' => trim($namn),
                'roll' => 'admin',
                'pin_hash' => password_hash(self::valideraPin($pin), PASSWORD_DEFAULT),
                'aktiv' => true,
                // PIN-koden väljs av administratören själv redan i
                // installationsguiden – då finns ingen tillfällig kod att byta.
                'maste_byta_pin' => $masteBytaPin,
                'misslyckade' => 0,
                'sparrad_till' => null,
                'skapad' => nu(),
            ];
            Json::laggTill($fil, $konto);
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'konto_skapad',
                'ansvarig_namn' => 'installation',
                'detaljer' => ['konto_id' => $konto['id'], 'namn' => $konto['namn'], 'roll' => 'admin', 'forsta_admin' => true],
            ]);
            $tx->spara();
            return $konto;
        });
    }

    public static function behoverForstaAdmin(): bool
    {
        if (!is_file(Json::sokvag('anvandare'))) {
            return true;
        }
        return count(self::anvandare()) === 0;
    }

    private static function registreraMisslyckande(array $konto): void
    {
        Las::under(function () use ($konto) {
            $tx = new Transaktion('inloggning_nekad', null);
            $fil = $tx->fil('anvandare');
            Json::uppdatera($fil, $konto['id'], function ($u) {
                $u['misslyckade'] = (int) ($u['misslyckade'] ?? 0) + 1;
                if ($u['misslyckade'] >= self::MAX_FORSOK) {
                    $u['sparrad_till'] = (new \DateTimeImmutable('+' . self::SPARR_MINUTER . ' minutes', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                    $u['misslyckade'] = 0;
                }
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->spara();
        });
    }

    public static function andraPin(string $kontoId, string $nyPin, array $av, bool $avAdmin = false): void
    {
        // Admin behöver inte ange nuvarande PIN; användaren gör det via bytaPin.
        $nyPin = self::valideraPin($nyPin);
        Las::under(function () use ($kontoId, $nyPin, $av, $avAdmin) {
            $tx = new Transaktion('losenord_andrat', $av);
            $fil = $tx->fil('anvandare');
            $konto = Json::hitta($fil, $kontoId);
            if ($konto === null) {
                throw new AppFel('Kontot finns inte.', 404);
            }
            // Samma PIN som den nuvarande vore ingen ändring alls – och då
            // försvinner heller inte markeringen "tillfällig PIN", vilket gör
            // att personen fastnar i en varning som aldrig kan lösas.
            if (!empty($konto['pin_hash']) && password_verify($nyPin, (string) $konto['pin_hash'])) {
                throw new AppFel('Den nya PIN-koden är samma som den nuvarande. Välj en annan.');
            }
            Json::uppdatera($fil, $kontoId, function ($u) use ($nyPin) {
                $u['pin_hash'] = password_hash($nyPin, PASSWORD_DEFAULT);
                $u['maste_byta_pin'] = false;
                $u['misslyckade'] = 0;
                $u['sparrad_till'] = null;
                $u['pin_andrad'] = nu();
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'losenord_andrat',
                'detaljer' => ['konto_id' => $kontoId, 'namn' => $konto['namn'], 'av_admin' => $avAdmin],
            ]);
            $tx->spara();
        });
    }

    public static function bytaPin(string $kontoId, string $nuvarandePin, string $nyPin): void
    {
        $konto = self::aktivAnvandare($kontoId);
        if ($konto === null) {
            throw new AppFel('Kontot är inte aktivt.');
        }
        if (!password_verify($nuvarandePin, (string) $konto['pin_hash'])) {
            throw new AppFel('Den nuvarande PIN-koden stämmer inte.');
        }
        self::andraPin($kontoId, $nyPin, $konto);
    }

    public static function stangAv(string $kontoId, array $av): void
    {
        Las::under(function () use ($kontoId, $av) {
            $tx = new Transaktion('konto_avstangd', $av);
            $fil = $tx->fil('anvandare');
            $konto = Json::hitta($fil, $kontoId);
            if ($konto === null) {
                throw new AppFel('Kontot finns inte.', 404);
            }
            if ($konto['id'] === ($av['id'] ?? '')) {
                throw new AppFel('Du kan inte stänga av ditt eget konto.');
            }
            // Se till att minst en aktiv admin finns kvar.
            $aktivaAdmin = 0;
            foreach ($fil['poster'] as $u) {
                if ($u['roll'] === 'admin' && !empty($u['aktiv'])) {
                    $aktivaAdmin++;
                }
            }
            if ($konto['roll'] === 'admin' && $aktivaAdmin <= 1) {
                throw new AppFel('Den sista administratören kan inte stängas av.');
            }
            Json::uppdatera($fil, $kontoId, function ($u) {
                $u['aktiv'] = false;
                $u['avstangd'] = nu();
                $u['pin_hash'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'konto_avstangd',
                'detaljer' => ['konto_id' => $kontoId, 'namn' => $konto['namn'], 'roll' => $konto['roll']],
            ]);
            $tx->spara();
        });
    }

    /**
     * Återaktivera ett avstängt konto.
     *
     * PIN-koden förbrukades vid avstängningen (den skrivs över), så kontot
     * måste få en ny PIN innan personen kan logga in. Markeringen
     * 'maste_byta_pin' gör att gränssnittet visar det direkt.
     */
    public static function aktivera(string $kontoId, array $av): void
    {
        Las::under(function () use ($kontoId, $av) {
            $tx = new Transaktion('konto_andrad', $av);
            $fil = $tx->fil('anvandare');
            $konto = Json::hitta($fil, $kontoId);
            if ($konto === null) {
                throw new AppFel('Kontot finns inte.', 404);
            }
            Json::uppdatera($fil, $kontoId, function ($u) {
                $u['aktiv'] = true;
                $u['avstangd'] = null;
                // Ny PIN krävs – den gamla finns inte längre.
                $u['maste_byta_pin'] = true;
                $u['misslyckade'] = 0;
                $u['sparrad_till'] = null;
                $u['andrad'] = nu();
                return $u;
            });
            $tx->sattFil('anvandare', $fil);
            $tx->historik([
                'typ' => 'konto_andrad',
                'detaljer' => ['konto_id' => $kontoId, 'namn' => $konto['namn'], 'handelse' => 'ateraktiverat'],
            ]);
            $tx->spara();
        });
    }
}
