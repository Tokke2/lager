<?php
/**
 * Verktygstavlor och lådor – artiklar som innehåller andra artiklar.
 *
 * En tavla är en vanlig artikel (egen QR-etikett, eget saldo, egen hylla) som
 * dessutom har en innehållsförteckning: vilka delar som sitter i den och hur
 * många av varje. Varje del är också en vanlig artikel med eget saldo och egen
 * QR-etikett, så en saknad hylsnyckel syns direkt vid återlämning.
 *
 * Två lager:
 *   – tavlans saldo         = hur många tavlor som finns på platsen
 *   – delarnas saldo        = hur många delar som finns på platsen
 *
 * Vid uttag av tavlan följer alla delar med automatiskt (se Rorelser::uttag).
 * Vid inleverans läggs tavlan och alla delar tillbaka.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Tavlor
{
    /** Max antal olika delar i en tavla. */
    public const MAX_DELAR = 100;

    /** Max antal av en enskild del. */
    public const MAX_ANTAL = 999;

    /* ================================================================
     *  Läsning
     * ================================================================ */

    /**
     * Innehållet i en tavla, med aktuella artikeluppgifter.
     * @return list<array{artikel_id:string, antal:int, artikel:?array}>
     */
    public static function innehall(array $tavla): array
    {
        $rader = $tavla['innehall'] ?? [];
        if (!is_array($rader) || $rader === []) {
            return [];
        }
        $alla = [];
        foreach (Json::las('artiklar')['poster'] as $a) {
            $alla[$a['id']] = $a;
        }
        $ut = [];
        foreach ($rader as $r) {
            $id = (string) ($r['artikel_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $ut[] = [
                'artikel_id' => $id,
                'antal' => max(1, (int) ($r['antal'] ?? 1)),
                // Placeringen måste följa med ut, annars visas den aldrig i vyn
                'fack' => (string) ($r['fack'] ?? ''),
                'artikel' => $alla[$id] ?? null,
            ];
        }
        return $ut;
    }

    /** Är artikeln en tavla eller låda med innehåll? */
    public static function arTavla(array $artikel): bool
    {
        return !empty($artikel['ar_tavla']);
    }

    /** Antal delar (summan av antalen) i en tavla. */
    public static function antalDelar(array $tavla): int
    {
        $summa = 0;
        foreach (self::innehall($tavla) as $rad) {
            $summa += $rad['antal'];
        }
        return $summa;
    }

    /**
     * Tavlor som en artikel ingår i. Används för att varna innan en del
     * inaktiveras – annars blir tavlans innehåll pekande på en död artikel.
     * @return list<array>
     */
    public static function tavlorMed(string $artikelId): array
    {
        $ut = [];
        foreach (Json::las('artiklar')['poster'] as $a) {
            if (empty($a['ar_tavla']) || empty($a['aktiv'])) {
                continue;
            }
            foreach ($a['innehall'] ?? [] as $r) {
                if (($r['artikel_id'] ?? '') === $artikelId) {
                    $ut[] = $a;
                    break;
                }
            }
        }
        return $ut;
    }

    /* ================================================================
     *  Skrivning
     * ================================================================ */

    /**
     * Sätter innehållet i en tavla. Anropas inifrån en öppen transaktion
     * (Lager::skapaArtikel / Lager::andraArtikel) så att allt hamnar i
     * samma historikpost.
     *
     * @param array $rader list<array{artikel_id:string, antal:int}>
     */
    public static function valideraInnehall(array $rader, string $tavlaId = ''): array
    {
        $ren = [];
        $sedda = [];
        foreach ($rader as $r) {
            $id = trim((string) ($r['artikel_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($id === $tavlaId) {
                throw new AppFel('En tavla kan inte innehålla sig själv.');
            }
            if (isset($sedda[$id])) {
                throw new AppFel('Samma del är angiven flera gånger. Ändra antalet i stället.');
            }
            $sedda[$id] = true;

            $del = Json::hitta(Json::las('artiklar'), $id);
            if ($del === null) {
                throw new AppFel('Delen finns inte bland artiklarna.');
            }
            if (empty($del['aktiv'])) {
                throw new AppFel('Artikeln ' . $del['namn'] . ' är inaktiverad och kan inte läggas i en tavla.');
            }
            if (!empty($del['ar_tavla'])) {
                throw new AppFel('En tavla kan inte ligga inuti en annan tavla. Ta delarna i stället.');
            }
            $antal = (int) ($r['antal'] ?? 1);
            // 0 = ta bort delen ur tavlan (används i redigeringsformuläret)
            if ($antal === 0) {
                continue;
            }
            if ($antal < 0 || $antal > self::MAX_ANTAL) {
                throw new AppFel('Antalet för ' . $del['namn'] . ' måste vara 0–' . self::MAX_ANTAL . '.');
            }
            // Fack/rad i tavlan: var delen sitter, t.ex. "A3" eller "översta raden".
            // Fritt fält – tavlor ser olika ut, så vi tvingar ingen nomenklatur.
            $fack = trim((string) ($r['fack'] ?? ''));
            if (mb_strlen($fack) > 40) {
                throw new AppFel('Placeringen (fack/rad) får vara högst 40 tecken.');
            }
            $ren[] = ['artikel_id' => $id, 'antal' => $antal, 'fack' => $fack];
        }

        if (count($ren) > self::MAX_DELAR) {
            throw new AppFel('En tavla kan innehålla högst ' . self::MAX_DELAR . ' olika delar.');
        }
        return $ren;
    }

    /**
     * Läs in innehållsrader från ett formulär.
     *
     * Formuläret skickar två parallella listor: del_artikel[] och del_antal[].
     * Tomma rader (oanvända fält) hoppas över.
     *
     * @return list<array{artikel_id:string, antal:int}>
     */
    public static function raderFranFormular(): array
    {
        $artiklar = $_POST['del_artikel'] ?? [];
        $antalen = $_POST['del_antal'] ?? [];
        $facken = $_POST['del_fack'] ?? [];
        if (!is_array($artiklar)) {
            return [];
        }
        $rader = [];
        foreach (array_values($artiklar) as $i => $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $antal = isset($antalen[$i]) ? (int) $antalen[$i] : 1;
            $rader[] = [
                'artikel_id' => $id,
                'antal' => $antal,
                'fack' => trim((string) ($facken[$i] ?? '')),
            ];
        }
        return $rader;
    }


    /* ================================================================
     *  Flytta delar mellan tavlor
     * ================================================================ */

    /**
     * Flyttar en del från en tavla till en annan i ett svep.
     *
     * Hela flytten sker i EN transaktion: antingen hamnar raden i mottagaren
     * och försvinner ur källan, eller så händer ingenting. Det skiljer sig från
     * att ta bort + lägga till, där ett avbrott mitt emellan kan tappa delen.
     *
     * @param string $franId    tavlan delen lämnar
     * @param string $tillId    tavlan delen hamnar i
     * @param string $delId     artikel-ID för själva delen
     * @param int    $antal     hur många som flyttas (1 = hela raden om den bara har 1)
     * @param string $fack      ny placering i mottagaren ('' = behåll källans)
     * @return array{del:string, antal:int, fran:string, till:string, kvar:int}
     */
    public static function flyttaDel(
        string $franId,
        string $tillId,
        string $delId,
        int $antal = 1,
        string $fack = '',
        ?array $anvandare = null
    ): array {
        if ($franId === $tillId) {
            throw new AppFel('Delen ska flyttas till en annan tavla. Välj en annan mottagare.');
        }
        if ($delId === '') {
            throw new AppFel('Välj vilken del som ska flyttas.');
        }
        $antal = max(1, $antal);

        return Las::under(function () use ($franId, $tillId, $delId, $antal, $fack, $anvandare) {
            $tx = new Transaktion('tavla_del_flyttad', $anvandare);
            $artiklar = $tx->fil('artiklar');

            $fran = Json::hitta($artiklar, $franId);
            $till = Json::hitta($artiklar, $tillId);
            if ($fran === null || $till === null) {
                throw new AppFel('Tavlan finns inte.');
            }
            if (empty($fran['ar_tavla']) || empty($till['ar_tavla'])) {
                throw new AppFel('Både källan och mottagaren måste vara verktygstavlor.');
            }
            $del = Json::hitta($artiklar, $delId);
            if ($del === null) {
                throw new AppFel('Delen finns inte bland artiklarna.');
            }

            // Plocka ut raden ur källan
            $franRader = [];
            $flyttad = null;
            foreach ($fran['innehall'] ?? [] as $r) {
                if ((string) $r['artikel_id'] === $delId) {
                    $flyttad = $r;
                    continue;
                }
                $franRader[] = [
                    'artikel_id' => (string) $r['artikel_id'],
                    'antal' => (int) $r['antal'],
                    'fack' => (string) ($r['fack'] ?? ''),
                ];
            }
            if ($flyttad === null) {
                throw new AppFel($del['namn'] . ' ligger inte i ' . $fran['namn'] . '.');
            }
            $finnsAntal = (int) $flyttad['antal'];
            if ($antal > $finnsAntal) {
                throw new AppFel('Det finns bara ' . $finnsAntal . ' ' . $del['enhet'] . ' av ' . $del['namn']
                    . ' i ' . $fran['namn'] . '.');
            }
            $kvar = $finnsAntal - $antal;
            if ($kvar > 0) {
                // Lägg tillbaka det som blev kvar på SAMMA plats i listan, så
                // raderna inte hoppar om varandra efter en flytt.
                $ater = [
                    'artikel_id' => $delId,
                    'antal' => $kvar,
                    'fack' => (string) ($flyttad['fack'] ?? ''),
                ];
                $plats = 0;
                foreach ($fran['innehall'] ?? [] as $i => $r) {
                    if ((string) $r['artikel_id'] === $delId) {
                        $plats = $i;
                        break;
                    }
                }
                array_splice($franRader, $plats, 0, [$ater]);
            }

            // Lägg in i mottagaren (samma del kan redan finnas där -> öka)
            $tillRader = [];
            foreach ($till['innehall'] ?? [] as $r) {
                $tillRader[] = [
                    'artikel_id' => (string) $r['artikel_id'],
                    'antal' => (int) $r['antal'],
                    'fack' => (string) ($r['fack'] ?? ''),
                ];
            }
            $nyPlacering = $fack !== '' ? mb_substr($fack, 0, 40) : (string) ($flyttad['fack'] ?? '');
            $fannsRedan = false;
            foreach ($tillRader as $i => $r) {
                if ($r['artikel_id'] === $delId) {
                    $tillRader[$i]['antal'] += $antal;
                    if ($nyPlacering !== '') {
                        $tillRader[$i]['fack'] = $nyPlacering;
                    }
                    $fannsRedan = true;
                }
            }
            if (!$fannsRedan) {
                $tillRader[] = ['artikel_id' => $delId, 'antal' => $antal, 'fack' => $nyPlacering];
            }

            // Källan kan bli tom: då är den ingen tavla längre
            $franArTavla = $franRader !== [];
            $skrivFran = function (array $a) use ($franRader, $franArTavla) {
                if ($franArTavla) {
                    $a['ar_tavla'] = true;
                    $a['innehall'] = $franRader;
                } else {
                    unset($a['ar_tavla'], $a['innehall']);
                }
                $a['andrad'] = nu();
                return $a;
            };
            $skrivTill = function (array $a) use ($tillRader) {
                $a['ar_tavla'] = true;
                $a['innehall'] = $tillRader;
                $a['andrad'] = nu();
                return $a;
            };

            if (Json::uppdatera($artiklar, $franId, $skrivFran) === false
                || Json::uppdatera($artiklar, $tillId, $skrivTill) === false) {
                throw new AppFel('Kunde inte uppdatera tavlorna.');
            }
            $tx->sattFil('artiklar', $artiklar);

            $tx->historik([
                'typ' => 'tavla_del_flyttad',
                'artikel_id' => $delId,
                'artikelnummer' => $del['artikelnummer'],
                'artikelnamn' => $del['namn'],
                'antal' => $antal,
                'kommentar' => $del['namn'] . ' ×' . $antal . ' flyttades från ' . $fran['namn'] . ' till ' . $till['namn'],
            ]);
            $tx->spara();

            return [
                'del' => $del['namn'],
                'antal' => $antal,
                'fran' => $fran['namn'],
                'till' => $till['namn'],
                'kvar' => $kvar,
                'enhet' => $del['enhet'],
            ];
        });
    }

    /**
     * Tavlor som en del kan flyttas TILL från en given tavla.
     * Alla andra aktiva tavlor, utan den del som redan ligger i källan
     * behöver inte filtreras bort – den får slås ihop.
     *
     * @return list<array>
     */
    public static function mojligaMottagare(string $franId): array
    {
        $ut = [];
        foreach (Lager::artiklar() as $a) {
            if (empty($a['ar_tavla']) || $a['id'] === $franId) {
                continue;
            }
            $ut[] = $a;
        }
        usort($ut, fn ($a, $b) => strcasecmp($a['namn'], $b['namn']));
        return $ut;
    }

    /* ================================================================
     *  Rörelser
     * ================================================================ */

    /**
     * Bokar ut tavlans delar tillsammans med tavlan.
     *
     * Anropas EFTER att tavlans eget uttag sparats, i en egen transaktion.
     * Bara delar som faktiskt finns på platsen rörs – saknas en del där
     * hoppas den över och rapporteras, i stället för att hela uttaget faller.
     *
     * @return array{utdragna:list<array>, saknade:list<array>}
     */
    public static function uttagAvDelar(array $tavla, string $platsId, array $anvandare, int $antalTavlor = 1): array
    {
        $utdragna = [];
        $saknade = [];
        $rader = self::innehall($tavla);
        if ($rader === [] || $antalTavlor < 1) {
            return ['utdragna' => [], 'saknade' => []];
        }

        foreach ($rader as $rad) {
            $del = $rad['artikel'];
            if ($del === null || empty($del['aktiv'])) {
                continue;
            }
            // Läs om delen färskt: saldot kan ha ändrats av en tidigare del i
            // samma tavla (samma artikel kan inte ligga två gånger, men
            // saldot kan ha rörts av en annan tavla i samma sekund).
            $farsk = Lager::artikel($del['id']);
            if ($farsk !== null) {
                $del = $farsk;
            }
            $behov = $rad['antal'] * $antalTavlor;
            $saldoNu = (int) ($del['saldo'][$platsId] ?? 0);
            if ($saldoNu < $behov) {
                $saknade[] = [
                    'namn' => $del['namn'],
                    'behov' => $behov,
                    'fanns' => $saldoNu,
                ];
                $behov = $saldoNu; // ta det som finns
            }
            if ($behov <= 0) {
                continue;
            }
            try {
                if ($del['sparningssatt'] === Lager::SPAR_ANTAL) {
                    Rorelser::uttag($del['id'], $platsId, $behov, [], $anvandare,
                        'Följde med tavlan ' . $tavla['namn']);
                } else {
                    // QR-artikel: ta ut de enheter som ligger på platsen
                    $koder = [];
                    foreach (Lager::enheter(['artikel_id' => $del['id'], 'plats_id' => $platsId, 'status' => 'aktiv']) as $en) {
                        $koder[] = $en['id'];
                        if (count($koder) >= $behov) {
                            break;
                        }
                    }
                    if ($koder === []) {
                        $saknade[] = ['namn' => $del['namn'], 'behov' => $behov, 'fanns' => 0];
                        continue;
                    }
                    Rorelser::uttag($del['id'], $platsId, 0, $koder, $anvandare,
                        'Följde med tavlan ' . $tavla['namn']);
                }
                $utdragna[] = ['namn' => $del['namn'], 'antal' => $behov];
            } catch (AppFel $e) {
                // En del som inte går att ta ut ska inte stoppa hela tavlan
                $saknade[] = ['namn' => $del['namn'], 'behov' => $behov, 'fanns' => $saldoNu];
            }
        }
        return ['utdragna' => $utdragna, 'saknade' => $saknade];
    }

    /**
     * Lägger tillbaka tavlans delar vid inleverans.
     *
     * @return array{aterställda:list<array>, misslyckade:list<array>}
     */
    public static function inleveransAvDelar(array $tavla, string $platsId, array $anvandare, int $antalTavlor = 1): array
    {
        $aterstallda = [];
        $misslyckade = [];
        foreach (self::innehall($tavla) as $rad) {
            $del = $rad['artikel'];
            if ($del === null || empty($del['aktiv'])) {
                continue;
            }
            $antal = $rad['antal'] * $antalTavlor;
            try {
                Rorelser::inleverans($del['id'], $platsId, $antal, [], $anvandare,
                    'Tillbaka med tavlan ' . $tavla['namn']);
                $aterstallda[] = ['namn' => $del['namn'], 'antal' => $antal];
            } catch (AppFel $e) {
                $misslyckade[] = ['namn' => $del['namn'], 'fel' => $e->getMessage()];
            }
        }
        return ['aterstallda' => $aterstallda, 'misslyckade' => $misslyckade];
    }
}
