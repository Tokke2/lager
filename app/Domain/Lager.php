<?php
/**
 * Tadega Lager – domänlogik.
 *
 * Alla skrivande operationer går genom den här klassen, håller det globala
 * skrivlåset och sparar via Transaktion (journal + atomiska filbyten).
 * All validering sker FÖRE första skrivning.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Las;
use TDL\Support\Transaktion;
use TDL\Support\Koder;

final class Lager
{
    public const SPAR_ANTAL = 'antal';
    public const SPAR_QR = 'qr';

    /** Kräver administratörsroll på domännivå – inte bara i gränssnittet. */
    public static function kravAdmin(?array $anvandare): void
    {
        if (($anvandare['roll'] ?? '') !== 'admin') {
            throw new AppFel('Åtgärden kräver administratörsbehörighet.', 403);
        }
    }

    /* ================================================================
     *  Initiering
     * ================================================================ */

    /** Skapa tomma datafiler om de saknas (rör aldrig befintliga filer). */
    public static function initieraFiler(): array
    {
        $skapade = [];
        foreach (array_keys(Json::FILER) as $nyckel) {
            $sokvag = Json::sokvag($nyckel);
            if (!is_file($sokvag)) {
                try {
                    Json::skrivAtomiskt($sokvag, tdl_json(Json::tom($nyckel)));
                    $skapade[] = $nyckel;
                } catch (\Throwable $e) {
                    throw new AppFel('Kan inte skapa ' . basename($sokvag) . ': ' . $e->getMessage(), 500);
                }
            }
        }
        return $skapade;
    }

    public static function arInstallerad(): bool
    {
        return is_file(Json::sokvag('anvandare'));
    }

    public static function behoverInstallation(): bool
    {
        if (!self::arInstallerad()) {
            return true;
        }
        $anv = Json::las('anvandare');
        return count($anv['poster']) === 0;
    }

    /* ================================================================
     *  Läsning
     * ================================================================ */

    public static function platser(bool $endastAktiva = false): array
    {
        $p = Json::las('platser')['poster'];
        return $endastAktiva ? array_values(array_filter($p, fn ($x) => !empty($x['aktiv']))) : $p;
    }

    public static function plats(string $id): ?array
    {
        return Json::hitta(Json::las('platser'), $id);
    }

    public static function platsViaQr(string $kod): ?array
    {
        $u = Koder::utanBindestreck($kod);
        foreach (Json::las('platser')['poster'] as $plats) {
            if (Koder::utanBindestreck($plats['qr'] ?? '') === $u) {
                return $plats;
            }
        }
        return null;
    }

    public static function artiklar(bool $endastAktiva = false): array
    {
        $a = Json::las('artiklar')['poster'];
        return $endastAktiva ? array_values(array_filter($a, fn ($x) => !empty($x['aktiv']))) : $a;
    }

    public static function artikel(string $id): ?array
    {
        return Json::hitta(Json::las('artiklar'), $id);
    }

    public static function artikelViaNummer(string $nummer): ?array
    {
        foreach (Json::las('artiklar')['poster'] as $a) {
            if (mb_strtolower((string) ($a['artikelnummer'] ?? '')) === mb_strtolower($nummer)) {
                return $a;
            }
        }
        return null;
    }

    /** EAN-8/EAN-13 ska vara oförändrade strängar, inklusive inledande nollor. */
    public static function valideraEan(string $ean): string
    {
        $ean = trim($ean);
        if ($ean === '') return '';
        if (!preg_match('/^(?:[0-9]{8}|[0-9]{13})$/D', $ean)) {
            throw new AppFel('EAN-koden ska ha 8 eller 13 siffror.');
        }
        $summa = 0;
        $vikt = 3;
        for ($i = strlen($ean) - 2; $i >= 0; $i--) {
            $summa += (int) $ean[$i] * $vikt;
            $vikt = $vikt === 3 ? 1 : 3;
        }
        if ((10 - $summa % 10) % 10 !== (int) substr($ean, -1)) {
            throw new AppFel('EAN-kodens kontrollsiffra stämmer inte.');
        }
        return $ean;
    }

    public static function artikelViaEan(string $ean): ?array
    {
        try { $ean = self::valideraEan($ean); } catch (AppFel $e) { return null; }
        if ($ean === '') return null;
        foreach (self::artiklar(true) as $a) {
            if (($a['ean'] ?? '') === $ean) return $a;
        }
        return null;
    }

    public static function enhet(string $kod): ?array
    {
        $u = Koder::utanBindestreck($kod);
        foreach (Json::las('enheter')['poster'] as $en) {
            if (Koder::utanBindestreck($en['id']) === $u) {
                return $en;
            }
        }
        return null;
    }

    public static function enheter(array $filter = []): array
    {
        $alla = Json::las('enheter')['poster'];
        if ($filter === []) {
            return $alla;
        }
        return array_values(array_filter($alla, function ($en) use ($filter) {
            foreach ($filter as $k => $v) {
                if (($en[$k] ?? null) !== $v) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Saldo per plats för en artikel.
     *
     * antal:  lagrat i artikel['saldo']
     * qr:     härleds ur enheter med status 'aktiv' (aldrig dubbellagrat)
     *
     * @return array<string,int>  platsId => antal
     */
    public static function saldoPerPlats(array $artikel, ?array $allaEnheter = null): array
    {
        $ut = [];
        if (($artikel['sparningssatt'] ?? self::SPAR_ANTAL) === self::SPAR_ANTAL) {
            foreach (($artikel['saldo'] ?? []) as $platsId => $antal) {
                $ut[$platsId] = (int) $antal;
            }
            return $ut;
        }
        $enheter = $allaEnheter ?? self::enheter();
        foreach ($enheter as $en) {
            if (($en['artikel_id'] ?? '') !== $artikel['id'] || ($en['status'] ?? '') !== 'aktiv') {
                continue;
            }
            $pid = (string) ($en['plats_id'] ?? '');
            $ut[$pid] = ($ut[$pid] ?? 0) + 1;
        }
        return $ut;
    }

    public static function totalt(array $saldo): int
    {
        return array_sum(array_map('intval', $saldo));
    }

    /** Översikt: alla aktiva platser och deras saldon för en artikel. */
    public static function saldoOversikt(array $artikel): array
    {
        $saldo = self::saldoPerPlats($artikel);
        $rader = [];
        foreach (self::platser() as $plats) {
            $rader[] = [
                'plats' => $plats,
                'antal' => $saldo[$plats['id']] ?? 0,
            ];
        }
        return ['rader' => $rader, 'total' => self::totalt($saldo)];
    }

    /* ================================================================
     *  Platser
     * ================================================================ */

    public static function skapaPlats(string $namn, string $kod, array $anvandare, bool $system = false): array
    {
        self::kravAdmin($anvandare);
        $namn = trim($namn);
        if ($namn === '' || mb_strlen($namn) > 40) {
            throw new AppFel('Platsnamn måste vara 1–40 tecken.');
        }
        $kod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kod) ?? '');
        if (!preg_match('/^[A-Z0-9]{2,6}$/', $kod)) {
            throw new AppFel('Platskoden ska vara 2–6 tecken (bokstäver/siffror).');
        }

        return Las::under(function () use ($namn, $kod, $anvandare, $system) {
            $tx = new Transaktion('plats_skapad', $anvandare);
            $platser = $tx->fil('platser');
            foreach ($platser['poster'] as $p) {
                if (mb_strtolower($p['namn']) === mb_strtolower($namn)) {
                    throw new AppFel('Det finns redan en plats med namnet ' . $namn . '.');
                }
                if (strtoupper($p['kod']) === $kod) {
                    throw new AppFel('Platskoden ' . $kod . ' används redan av ' . $p['namn'] . '.');
                }
            }
            $plats = [
                'id' => Koder::interntId('P', 4),
                'namn' => $namn,
                'kod' => $kod,
                'qr' => Koder::nyKod('L'),
                'system' => $system,
                'aktiv' => true,
                'skapad' => nu(),
            ];
            Json::laggTill($platser, $plats);
            $tx->sattFil('platser', $platser);
            $tx->historik([
                'typ' => 'plats_skapad',
                'plats_id' => $plats['id'],
                'plats_namn' => $plats['namn'],
                'detaljer' => ['qr' => $plats['qr'], 'system' => $system],
            ]);
            $tx->spara();
            return $plats;
        });
    }

    public static function andraPlats(string $id, string $namn, string $kod, array $anvandare): array
    {
        self::kravAdmin($anvandare);
        $namn = trim($namn);
        $kod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kod) ?? '');
        if ($namn === '' || mb_strlen($namn) > 40) {
            throw new AppFel('Platsnamn måste vara 1–40 tecken.');
        }
        if (!preg_match('/^[A-Z0-9]{2,4}$/', $kod)) {
            throw new AppFel('Platskoden ska vara 2–4 tecken.');
        }
        return Las::under(function () use ($id, $namn, $kod, $anvandare) {
            $tx = new Transaktion('plats_andrad', $anvandare);
            $platser = $tx->fil('platser');
            $plats = Json::hitta($platser, $id);
            if ($plats === null) {
                throw new AppFel('Platsen finns inte.', 404);
            }
            foreach ($platser['poster'] as $p) {
                if ($p['id'] !== $id && mb_strtolower($p['namn']) === mb_strtolower($namn)) {
                    throw new AppFel('Namnet används redan av en annan plats.');
                }
                if ($p['id'] !== $id && strtoupper($p['kod']) === $kod) {
                    throw new AppFel('Platskoden används redan av ' . $p['namn'] . '.');
                }
            }
            $gammalt = ['namn' => $plats['namn'], 'kod' => $plats['kod']];
            Json::uppdatera($platser, $id, function ($p) use ($namn, $kod) {
                $p['namn'] = $namn;
                $p['kod'] = $kod;
                $p['andrad'] = nu();
                return $p;
            });
            $tx->sattFil('platser', $platser);
            $tx->historik([
                'typ' => 'plats_andrad',
                'plats_id' => $id,
                'plats_namn' => $namn,
                'detaljer' => ['fore' => $gammalt, 'efter' => ['namn' => $namn, 'kod' => $kod]],
            ]);
            $tx->spara();
            return Json::hitta(Json::las('platser'), $id);
        });
    }

    /**
     * Stäng en plats. Nekas om den har saldo, aktiva/väntande enheter eller
     * en öppen session. Ingen data raderas – platsen blir bara inaktiv.
     */
    public static function stangPlats(string $id, array $anvandare): void
    {
        self::kravAdmin($anvandare);
        Las::under(function () use ($id, $anvandare) {
            $tx = new Transaktion('plats_stangd', $anvandare);
            $platser = $tx->fil('platser');
            $plats = Json::hitta($platser, $id);
            if ($plats === null) {
                throw new AppFel('Platsen finns inte.', 404);
            }
            if (!empty($plats['system'])) {
                throw new AppFel('Systemposten för äldre saldo kan inte stängas.');
            }
            if (empty($plats['aktiv'])) {
                throw new AppFel('Platsen är redan stängd.');
            }

            $artiklar = $tx->fil('artiklar');
            $problem = [];
            foreach ($artiklar['poster'] as $a) {
                $saldo = self::saldoPerPlats($a);
                if (($saldo[$id] ?? 0) !== 0) {
                    $problem[] = $a['namn'] . ' har ' . $saldo[$id] . ' ' . $a['enhet'] . ' kvar';
                }
                foreach ($a['utlan'] ?? [] as $lan) {
                    if (($lan['status'] ?? '') === 'oppen' && ($lan['plats_id'] ?? '') === $id) {
                        $problem[] = $a['namn'] . ' har en pågående utlåning';
                    }
                }
            }
            foreach (($tx->fil('enheter'))['poster'] as $en) {
                if (($en['plats_id'] ?? '') === $id && in_array($en['status'] ?? '', ['aktiv', 'vantande', 'saknad', 'skadad'], true)) {
                    $problem[] = 'enhet ' . Koder::visa($en['id']) . ' (' . $en['status'] . ')';
                }
            }
            foreach (($tx->fil('sessioner'))['poster'] as $s) {
                if (($s['plats_id'] ?? '') === $id && ($s['status'] ?? '') === 'öppen') {
                    $problem[] = 'öppen inventering ' . $s['id'];
                }
            }
            if ($problem !== []) {
                throw new AppFel('Platsen kan inte stängas: ' . implode('; ', array_slice($problem, 0, 5)) . '.');
            }

            Json::uppdatera($platser, $id, function ($p) {
                $p['aktiv'] = false;
                $p['stangd'] = nu();
                return $p;
            });
            $tx->sattFil('platser', $platser);
            $tx->historik(['typ' => 'plats_stangd', 'plats_id' => $id, 'plats_namn' => $plats['namn']]);
            $tx->spara();
        });
    }

    /* ================================================================
     *  Artiklar
     * ================================================================ */

    /**
     * Skapa artikel. Personal får lägga upp nya artiklar – de ser vad som
     * fattas vid hyllan. Att ÄNDRA en befintlig artikel är fortfarande admin
     * (se andraArtikel), liksom att inaktivera den.
     */
    /** Kontrollera att etikettmallen finns (fast mått eller egna mm). */
    /**
     * Bara storlekar som går att skriva ut sparas: de kända rullarna eller ett
     * eget mått inom de vanligaste skrivarnas gränser (se Support/Skrivare.php).
     * Allt annat faller tillbaka på standardrullen – hellre rätt än trasigt.
     */
    private static function valideraEtikettmall(string $mall): string
    {
        $mall = trim($mall);
        if (isset(\TDL\Support\Etikettmallar::MALLAR[$mall])) {
            return $mall;
        }
        if (preg_match('/^(\d{2,3})x(\d{2,4})$/', $mall, $m)) {
            $bredd = (int) $m[1];
            $hojd = (int) $m[2];
            if ($bredd >= 20 && $bredd <= \TDL\Support\Etikettmallar::RULLBREDD_MAX && $hojd >= 20 && $hojd <= 1000) {
                return $bredd . 'x' . $hojd;
            }
        }
        return \TDL\Support\Etikettmallar::STANDARD;
    }

    /* ================================================================
     *  Kategori och hylla (frivilliga fält på artikeln)
     * ================================================================ */

    public const KATEGORI_MAX = 40;
    public const HYLLA_MAX = 40;

    /**
     * Städar ett kategori- eller hyllnamn: trimmar, kollapsar blanksteg och
     * kontrollerar längd. Tomt värde blir null (= inte satt).
     */
    private static function valideraPlatsfalt(mixed $varde, int $max, string $namn): ?string
    {
        $text = trim((string) $varde);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new AppFel(ucfirst($namn) . ' får vara högst ' . $max . ' tecken.');
        }
        return $text;
    }

    /** Frivilliga artikelnoteringar ändrar aldrig saldo. */
    private static function valideraArtikelnotering(mixed $varde, string $namn): string
    {
        if (!is_string($varde)) {
            throw new AppFel($namn . ' måste vara text.');
        }
        $text = trim($varde);
        if (mb_strlen($text) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            throw new AppFel($namn . ' får vara högst 500 tecken och får inte innehålla styrtecken.');
        }
        return $text;
    }

    /** Alla kategorier som används, för autokomplettering i formuläret. */
    public static function kategorier(): array
    {
        $ut = [];
        foreach (self::artiklar() as $a) {
            $k = trim((string) ($a['kategori'] ?? ''));
            if ($k !== '') {
                $ut[mb_strtolower($k)] = $k;
            }
        }
        $ut = array_values($ut);
        usort($ut, 'strcoll');
        return $ut;
    }

    /** Alla hyllor som används, sorterade i plockordning (A1, A2, A10, B1 …). */
    public static function hyllor(): array
    {
        $ut = [];
        foreach (self::artiklar() as $a) {
            $h = trim((string) ($a['hylla'] ?? ''));
            if ($h !== '') {
                $ut[mb_strtolower($h)] = $h;
            }
        }
        return self::sorteraPaHylla(array_values($ut));
    }

    /**
     * Sorterar strängar i den ordning man går genom lagret: siffror jämförs
     * som tal, så att "A2" kommer före "A10". Används av plocklistan.
     */
    public static function sorteraPaHylla(array $varden): array
    {
        usort($varden, function ($a, $b) {
            return strnatcasecmp((string) $a, (string) $b);
        });
        return $varden;
    }

    public static function skapaArtikel(array $data, array $anvandare): array
    {
        if (($anvandare['roll'] ?? '') !== 'admin' && ($anvandare['roll'] ?? '') !== 'personal') {
            throw new AppFel('Åtgärden kräver ett konto.', 403);
        }
        $namn = trim((string) ($data['namn'] ?? ''));
        $nummer = trim((string) ($data['artikelnummer'] ?? ''));
        $ean = self::valideraEan((string) ($data['ean'] ?? ''));
        $enhet = trim((string) ($data['enhet'] ?? 'st'));
        $spar = (string) ($data['sparningssatt'] ?? self::SPAR_ANTAL);
        $min = (int) ($data['miniminiva'] ?? 0);
        $prisRå = $data['prisreferens'] ?? '';
        $pris = $prisRå === '' || $prisRå === null ? null : (float) $prisRå;
        $etikettMall = self::valideraEtikettmall($data['etikett_mall'] ?? \TDL\Support\Etikettmallar::STANDARD);
        $kategori = self::valideraPlatsfalt($data['kategori'] ?? '', self::KATEGORI_MAX, 'kategorin');
        $hylla = self::valideraPlatsfalt($data['hylla'] ?? '', self::HYLLA_MAX, 'hyllan');
        $rakneregel = self::valideraArtikelnotering($data['rakneinstruktion'] ?? '', 'Räkneinstruktionen');
        $forpackning = self::valideraArtikelnotering($data['oppen_forpackning'] ?? '', 'Förpackningsnoteringen');
        // Lagerplats: zon + rad + fack. Obligatorisk – utan plats går inget att hitta.
        $plats = \TDL\Domain\Lagerplats::valideraPlats(
            $data['zon_id'] ?? '',
            $data['rad'] ?? '',
            $data['fack'] ?? ''
        );
        // Verktygstavla/låda: artikeln innehåller andra artiklar
        $arTavla = !empty($data['ar_tavla']);
        $innehall = $arTavla ? \TDL\Domain\Tavlor::valideraInnehall(
            $data['innehall'] ?? []
        ) : [];

        if ($namn === '' || mb_strlen($namn) > 120) {
            throw new AppFel('Artikelnamn måste vara 1–120 tecken.');
        }
        if ($nummer === '' || mb_strlen($nummer) > 40) {
            throw new AppFel('Artikelnummer krävs (max 40 tecken).');
        }
        if (!preg_match('/^[A-Za-z0-9._\-\/ ]{1,20}$/', $enhet)) {
            throw new AppFel('Enheten får vara max 20 tecken (t.ex. st, m, kg).');
        }
        if (!in_array($spar, [self::SPAR_ANTAL, self::SPAR_QR], true)) {
            throw new AppFel('Saldohanteringssätt måste vara antal eller qr.');
        }
        if ($min < 0) {
            throw new AppFel('Miniminivån kan inte vara negativ.');
        }
        if ($pris !== null && $pris < 0) {
            throw new AppFel('Prisreferensen kan inte vara negativ.');
        }

        return Las::under(function () use ($namn, $nummer, $ean, $enhet, $spar, $min, $pris, $etikettMall, $kategori, $hylla, $rakneregel, $forpackning, $plats, $anvandare, $arTavla, $innehall) {
            $tx = new Transaktion('artikel_skapad', $anvandare);
            $artiklar = $tx->fil('artiklar');
            foreach ($artiklar['poster'] as $a) {
                if (mb_strtolower($a['artikelnummer']) === mb_strtolower($nummer)) {
                    throw new AppFel('Artikelnummer ' . $nummer . ' finns redan.');
                }
                if ($ean !== '' && ($a['ean'] ?? '') === $ean) {
                    throw new AppFel('EAN-koden används redan av ' . $a['namn'] . '.');
                }
            }
            $artikel = [
                'id' => Koder::interntId('A', 6),
                'artikelnummer' => $nummer,
                'ean' => $ean,
                'namn' => $namn,
                'enhet' => $enhet,
                'prisreferens' => $pris,
                'miniminiva' => $min,
                'sparningssatt' => $spar,
                'etikett_mall' => $etikettMall,
                'kategori' => $kategori,
                'hylla' => $hylla,
                'rakneinstruktion' => $rakneregel,
                'oppen_forpackning' => $forpackning,
                'oppen_forpackning_andrad' => $forpackning !== '' ? nu() : null,
                'zon_id' => $plats['zon_id'],
                'rad' => $plats['rad'],
                'fack' => $plats['fack'],
                'saldo' => [],
                'aktiv' => true,
                'skapad' => nu(),
            ];
            if ($arTavla) {
                $artikel['ar_tavla'] = true;
                $artikel['innehall'] = $innehall;
            }
            Json::laggTill($artiklar, $artikel);
            $tx->sattFil('artiklar', $artiklar);
            $tx->historik([
                'typ' => 'artikel_skapad',
                'artikel_id' => $artikel['id'],
                'artikelnummer' => $nummer,
                'artikelnamn' => $namn,
                'detaljer' => ['sparningssatt' => $spar, 'miniminiva' => $min, 'kategori' => $kategori, 'hylla' => $hylla, 'rakneinstruktion' => $rakneregel, 'oppen_forpackning' => $forpackning,
                    'plats' => \TDL\Domain\Lagerplats::platsText(['zon_id' => $plats['zon_id'], 'rad' => $plats['rad'], 'fack' => $plats['fack']])]
                    + ($arTavla ? ['ar_tavla' => true, 'antal_delar' => count($innehall)] : []),
            ]);
            $tx->spara();
            return Json::hitta(Json::las('artiklar'), $artikel['id']);
        });
    }

    public static function andraArtikel(string $id, array $data, array $anvandare): array
    {
        self::kravAdmin($anvandare);
        return Las::under(function () use ($id, $data, $anvandare) {
            $tx = new Transaktion('artikel_andrad', $anvandare);
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $id);
            if ($artikel === null) {
                throw new AppFel('Artikeln finns inte.', 404);
            }

            $namn = trim((string) ($data['namn'] ?? $artikel['namn']));
            $nummer = trim((string) ($data['artikelnummer'] ?? $artikel['artikelnummer']));
            $ean = self::valideraEan((string) ($data['ean'] ?? $artikel['ean'] ?? ''));
            $enhet = trim((string) ($data['enhet'] ?? $artikel['enhet']));
            $min = (int) ($data['miniminiva'] ?? $artikel['miniminiva']);
            $pris = ($data['prisreferens'] ?? '') === '' ? null : (float) $data['prisreferens'];
            $nyttSpar = (string) ($data['sparningssatt'] ?? $artikel['sparningssatt']);
            $etikettMall = self::valideraEtikettmall(
                $data['etikett_mall'] ?? ($artikel['etikett_mall'] ?? \TDL\Support\Etikettmallar::STANDARD)
            );
            $kategori = self::valideraPlatsfalt(
                array_key_exists('kategori', $data) ? $data['kategori'] : ($artikel['kategori'] ?? ''),
                self::KATEGORI_MAX,
                'kategorin'
            );
            $hylla = self::valideraPlatsfalt(
                array_key_exists('hylla', $data) ? $data['hylla'] : ($artikel['hylla'] ?? ''),
                self::HYLLA_MAX,
                'hyllan'
            );
            $rakneregel = self::valideraArtikelnotering(
                array_key_exists('rakneinstruktion', $data) ? $data['rakneinstruktion'] : ($artikel['rakneinstruktion'] ?? ''), 'Räkneinstruktionen'
            );
            $forpackning = self::valideraArtikelnotering(
                array_key_exists('oppen_forpackning', $data) ? $data['oppen_forpackning'] : ($artikel['oppen_forpackning'] ?? ''), 'Förpackningsnoteringen'
            );

            // Lagerplats: obligatorisk även vid ändring. Skickas inget alls
            // behålls den befintliga (t.ex. när bara sparningssättet rörs).
            $rorPlats = array_key_exists('zon_id', $data) || array_key_exists('rad', $data) || array_key_exists('fack', $data);
            if ($rorPlats) {
                $plats = \TDL\Domain\Lagerplats::valideraPlats(
                    $data['zon_id'] ?? '',
                    $data['rad'] ?? '',
                    $data['fack'] ?? ''
                );
            } else {
                $plats = [
                    'zon_id' => (string) ($artikel['zon_id'] ?? ''),
                    'rad' => (int) ($artikel['rad'] ?? 0),
                    'fack' => (int) ($artikel['fack'] ?? 0),
                ];
            }

            // Verktygstavla: delar kan läggas till och tas bort även i efterhand.
            // Skickas inget innehall med alls lämnas det som det är (t.ex. när
            // bara priset ändras från en annan vy).
            $rorInnehall = array_key_exists('innehall', $data) || !empty($data['ar_tavla']);
            $arTavla = $rorInnehall ? !empty($data['ar_tavla']) : !empty($artikel['ar_tavla']);
            $innehall = $artikel['innehall'] ?? [];
            if ($rorInnehall) {
                $innehall = $arTavla
                    ? \TDL\Domain\Tavlor::valideraInnehall($data['innehall'] ?? [], $id)
                    : [];
            }
            // En tavla utan delar är ingen tavla. Tar man bort sista delen
            // (antal 0) ska artikeln bli en vanlig artikel igen i stället för
            // att bli en tom tavla som drar med sig ingenting.
            if ($arTavla && $innehall === []) {
                $arTavla = false;
            }
            if ($arTavla && empty($artikel['ar_tavla'])) {
                foreach ($artikel['utlan'] ?? [] as $lan) {
                    if (($lan['status'] ?? '') === 'oppen') throw new AppFel('Avsluta utlåningen innan artikeln blir en verktygstavla.');
                }
            }

            if ($namn === '' || mb_strlen($namn) > 120) {
                throw new AppFel('Artikelnamn måste vara 1–120 tecken.');
            }
            if ($nummer === '') {
                throw new AppFel('Artikelnummer krävs.');
            }
            if ($min < 0) {
                throw new AppFel('Miniminivån kan inte vara negativ.');
            }
            foreach ($artiklar['poster'] as $a) {
                if ($a['id'] !== $id && mb_strtolower($a['artikelnummer']) === mb_strtolower($nummer)) {
                    throw new AppFel('Artikelnummer används redan av ' . $a['namn'] . '.');
                }
                if ($a['id'] !== $id && $ean !== '' && ($a['ean'] ?? '') === $ean) {
                    throw new AppFel('EAN-koden används redan av ' . $a['namn'] . '.');
                }
            }

            // Spårningssätt får inte stängas av när historik/enheter finns.
            if ($nyttSpar !== $artikel['sparningssatt']) {
                foreach ($artikel['utlan'] ?? [] as $lan) {
                    if (($lan['status'] ?? '') === 'oppen') throw new AppFel('Artikeln har en pågående utlåning.');
                }
                $harEnheter = self::enheter(['artikel_id' => $id]) !== [];
                $saldoNu = self::saldoPerPlats($artikel);
                if ($harEnheter) {
                    throw new AppFel('Saldohanteringssättet kan inte ändras: artikeln har QR-enheter. QR-spårning kan inte stängas av.');
                }
                if ($artikel['sparningssatt'] === self::SPAR_QR) {
                    throw new AppFel('Saldohanteringssättet för en QR-artikel kan inte ändras.');
                }
                if (self::totalt($saldoNu) > 0) {
                    throw new AppFel('Saldohanteringssättet kan inte ändras medan artikeln har saldo. Nolla saldot först.');
                }
            }

            Json::uppdatera($artiklar, $id, function ($a) use ($namn, $nummer, $ean, $enhet, $min, $pris, $nyttSpar, $etikettMall, $kategori, $hylla, $rakneregel, $forpackning, $plats, $rorInnehall, $arTavla, $innehall) {
                $a['namn'] = $namn;
                $a['artikelnummer'] = $nummer;
                $a['ean'] = $ean;
                $a['enhet'] = $enhet;
                $a['miniminiva'] = $min;
                $a['prisreferens'] = $pris;
                $a['sparningssatt'] = $nyttSpar;
                $a['etikett_mall'] = $etikettMall;
                $a['kategori'] = $kategori;
                $a['hylla'] = $hylla;
                $a['rakneinstruktion'] = $rakneregel;
                if ($forpackning !== ($a['oppen_forpackning'] ?? '')) {
                    $a['oppen_forpackning'] = $forpackning;
                    $a['oppen_forpackning_andrad'] = $forpackning !== '' ? nu() : null;
                }
                $a['zon_id'] = $plats['zon_id'];
                $a['rad'] = $plats['rad'];
                $a['fack'] = $plats['fack'];
                if ($rorInnehall) {
                    if ($arTavla) {
                        $a['ar_tavla'] = true;
                        $a['innehall'] = $innehall;
                    } else {
                        unset($a['ar_tavla'], $a['innehall']);
                    }
                }
                $a['andrad'] = nu();
                return $a;
            });
            $tx->sattFil('artiklar', $artiklar);
            $tx->historik([
                'typ' => 'artikel_andrad',
                'artikel_id' => $id,
                'artikelnummer' => $nummer,
                'artikelnamn' => $namn,
                'detaljer' => [
                    'fore' => ['namn' => $artikel['namn'], 'sparningssatt' => $artikel['sparningssatt'], 'kategori' => $artikel['kategori'] ?? null, 'hylla' => $artikel['hylla'] ?? null, 'rakneinstruktion' => $artikel['rakneinstruktion'] ?? '', 'oppen_forpackning' => $artikel['oppen_forpackning'] ?? '', 'plats' => \TDL\Domain\Lagerplats::platsText($artikel)],
                    'efter' => ['namn' => $namn, 'sparningssatt' => $nyttSpar, 'kategori' => $kategori, 'hylla' => $hylla, 'rakneinstruktion' => $rakneregel, 'oppen_forpackning' => $forpackning, 'plats' => \TDL\Domain\Lagerplats::platsText(['zon_id' => $plats['zon_id'], 'rad' => $plats['rad'], 'fack' => $plats['fack']])]
                        + ($rorInnehall ? ['ar_tavla' => $arTavla, 'antal_delar' => count($innehall)] : []),
                ],
            ]);
            $tx->spara();
            return Json::hitta(Json::las('artiklar'), $id);
        });
    }

    /**
     * Inaktivera (mjuk radering). Hård radering tillåts bara om artikeln
     * saknar saldo, enheter och historik.
     */
    public static function inaktiveraArtikel(string $id, array $anvandare): void
    {
        self::kravAdmin($anvandare);
        Las::under(function () use ($id, $anvandare) {
            $tx = new Transaktion('artikel_inaktiverad', $anvandare);
            $artiklar = $tx->fil('artiklar');
            $artikel = Json::hitta($artiklar, $id);
            if ($artikel === null) {
                throw new AppFel('Artikeln finns inte.', 404);
            }
            foreach ($artikel['utlan'] ?? [] as $lan) {
                if (($lan['status'] ?? '') === 'oppen') throw new AppFel('Artikeln har en pågående utlåning och kan inte inaktiveras.');
            }
            $saldo = self::saldoPerPlats($artikel);
            if (self::totalt($saldo) !== 0) {
                throw new AppFel('Artikeln har saldo kvar och kan inte inaktiveras. Nolla saldot först.');
            }
            if (self::enheter(['artikel_id' => $id]) !== []) {
                throw new AppFel('Artikeln har QR-enheter och kan inte inaktiveras. Hantera enheterna först.');
            }
            Json::uppdatera($artiklar, $id, function ($a) {
                $a['aktiv'] = false;
                $a['inaktiverad'] = nu();
                return $a;
            });
            $tx->sattFil('artiklar', $artiklar);
            $tx->historik([
                'typ' => 'artikel_inaktiverad',
                'artikel_id' => $id,
                'artikelnummer' => $artikel['artikelnummer'],
                'artikelnamn' => $artikel['namn'],
            ]);
            $tx->spara();
        });
    }
}
