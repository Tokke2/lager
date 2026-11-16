<?php
/**
 * Lokalplan: lokalen ritad som våningar med zoner utplacerade.
 *
 * VARFÖR APPEN INTE "TITTAR" PÅ FOTON
 * Ett foto har ingen skala och inget djup. En app kan inte avgöra om en hylla
 * är två eller fem meter bort, eller var en zon börjar och slutar. Att gissa
 * ger en karta som ser trovärdig ut men visar fel – och då är den farligare än
 * ingen karta alls.
 *
 * DET SOM FUNGERAR: ANVÄNDAREN RITAR, APPEN SÄTTER IHOP
 * Användaren fotar lokalens hörn, markerar "nu är det klart", och appen lägger
 * varje foto som en zon på en planlösning. Sedan kan administratören flytta,
 * ändra storlek och byta namn på zonerna så att skissen stämmer med verkligheten.
 * Är det flera våningar gör man likadant för varje våning.
 *
 * MODELL
 *   plan.json
 *   {
 *     "våningar": [
 *       {
 *         "id": "V1",
 *         "namn": "Bottenvåning",
 *         "bild": "lokal-….jpg" | null,      ← planbild (ritning/fågelvy) om man har
 *         "bilder": [                          ← foton som blev zoner
 *             {"fil": "foto-….jpg", "etikett": "Hörn 1", "zon_id": "…", "x":…, "y":…, "b":…, "h":…}
 *         ],
 *         "zonrutor": [{"zon_id","x","y","b","h"}]   ← procent av ytan
 *       }
 *     ],
 *     "skapad": "…"
 *   }
 *
 * Allt mäts i procent av ytan – då hamnar rutorna rätt på mobil, dator och
 * vid utskrift. Saknar våningen en planbild ritar appen själv en neutral yta
 * (16:10) att placera zonerna på, så flödet aldrig stannar.
 */

declare(strict_types=1);

namespace TDL\Domain;

use TDL\Support\AppFel;
use TDL\Support\Json;
use TDL\Support\Las;
use TDL\Support\Transaktion;

final class Lokalplan
{
    /** Tillåtna bildtyper. Samma som artiklarnas bilder. */
    private const TYPER = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Längsta sida efter nedskalning – 2000 px räcker för att se hyllor. */
    public const MAX_SIDA = 2000;

    /** Proportion på den ritade ytan när våningen saknar planbild (16:10). */
    public const YTA_BREDD = 1600;
    public const YTA_HOJD = 1000;

    /** Standardnamn när användaren inte döper våningen. */
    private const VANING_NAMN = ['Bottenvåning', 'Våning 2', 'Våning 3', 'Våning 4'];

    /* ----------------------------------------------------------------
     *  Läsa
     * ---------------------------------------------------------------- */

    public static function mapp(): string
    {
        return TDL_STORAGE . '/lokalplan';
    }

    /**
     * Hela planen, normaliserad. null när ingen våning finns.
     * Äldre plan.json (en enda bild, "zonrutor" i roten) läses som våning 1,
     * så en plan som redan är upplagd behöver inte läggas om.
     */
    public static function plan(): ?array
    {
        $data = self::lasFil();
        if ($data === null) {
            return null;
        }
        $vaningar = self::normalisera($data);
        if ($vaningar === []) {
            return null;
        }
        return [
            'vaningar' => $vaningar,
            'skapad' => (string) ($data['skapad'] ?? ''),
        ];
    }

    /** En våning, eller null. */
    public static function vaning(string $id): ?array
    {
        foreach (self::plan()['vaningar'] ?? [] as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        return null;
    }

    /** Första våningen – den man oftast vill visa. */
    public static function forstaVaning(): ?array
    {
        return (self::plan()['vaningar'] ?? [])[0] ?? null;
    }

    /**
     * Adressen till en bild i planen. $fil = null ger våningens planbild;
     * annars ett fotos filnamn. Saknas bilden blir det den ritade ytan.
     */
    public static function bildUrl(?array $vaning, ?string $fil = null): string
    {
        if ($vaning === null) {
            return '';
        }
        $namn = $fil ?? $vaning['bild'];
        if ($namn === null || $namn === '') {
            // Ingen bild: vyn ritar ytan själv. Ingen adress behövs.
            return '';
        }
        $v = rawurlencode((string) ($vaning['skapad'] ?? ''));
        return u('/lokalplan/bild.jpg?v=' . $v . '&fil=' . rawurlencode($namn)
            . '&vaning=' . rawurlencode($vaning['id']));
    }

    /** Sökvägen till en bildfil, om den finns. Skyddar mot ../ ur mappen. */
    public static function bildSokvag(?string $fil): ?string
    {
        $fil = basename((string) $fil);
        if ($fil === '') {
            return null;
        }
        $sokvag = self::mapp() . '/' . $fil;
        return is_file($sokvag) ? $sokvag : null;
    }

    /**
     * Zoner som ännu inte är utplacerade på någon våning.
     * En zon hör till en våning – den ska inte kunna ligga på två samtidigt.
     */
    public static function oplacerade(): array
    {
        $placerade = self::placeradeZonIder();
        $ut = [];
        foreach (Lagerplats::zoner() as $z) {
            if (!isset($placerade[$z['id']])) {
                $ut[] = $z;
            }
        }
        return $ut;
    }

    /** [zon_id => vaning_id] för alla zoner som ligger på en våning. */
    public static function placeradeZonIder(): array
    {
        $ut = [];
        foreach (self::plan()['vaningar'] ?? [] as $v) {
            foreach ($v['zonrutor'] as $r) {
                $ut[$r['zon_id']] = $v['id'];
            }
        }
        return $ut;
    }

    /* ----------------------------------------------------------------
     *  Våningar
     * ---------------------------------------------------------------- */

    /** Lägger till en våning. Returnerar den nya våningen. */
    public static function skapaVaning(?string $namn = null, ?array $anvandare = null): array
    {
        return Las::under(function () use ($namn, $anvandare) {
            $tx = new Transaktion('lokalplan_vaning_skapad', $anvandare);
            $data = self::lasFil() ?? ['vaningar' => [], 'skapad' => nu()];
            $vaningar = self::normalisera($data);

            $nummer = count($vaningar) + 1;
            $namn = trim((string) $namn);
            if ($namn === '') {
                $namn = self::VANING_NAMN[$nummer - 1] ?? ('Våning ' . $nummer);
            }
            $vaning = [
                'id' => self::nyttVaningId($vaningar),
                'namn' => mb_substr($namn, 0, 40),
                'bild' => null,
                'bilder' => [],
                'zonrutor' => [],
                'skapad' => nu(),
            ];
            $vaningar[] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);
            $tx->historik([
                'typ' => 'lokalplan_vaning_skapad',
                'kommentar' => 'Våningen "' . $vaning['namn'] . '" lades till i lokalplanen.',
            ]);
            $tx->spara();
            return $vaning;
        });
    }

    /** Byter namn på en våning. */
    public static function andraVaning(string $id, array $andringar, ?array $anvandare = null): array
    {
        return Las::under(function () use ($id, $andringar, $anvandare) {
            $tx = new Transaktion('lokalplan_vaning_andrad', $anvandare);
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            $hittad = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $id) {
                    $namn = trim((string) ($andringar['namn'] ?? $v['namn']));
                    if ($namn !== '') {
                        $vaningar[$i]['namn'] = mb_substr($namn, 0, 40);
                    }
                    // Får våningen placeras före de andra? (ordning i fliken)
                    $hittad = $vaningar[$i];
                }
            }
            if ($hittad === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);
            $tx->historik([
                'typ' => 'lokalplan_vaning_andrad',
                'kommentar' => 'Våningen bytte namn till "' . $hittad['namn'] . '".',
            ]);
            $tx->spara();
            return $hittad;
        });
    }

    /** Tar bort en våning med sina bilder och rutor. Sista våningen får vara kvar. */
    public static function taBortVaning(string $id, ?array $anvandare = null): void
    {
        Las::under(function () use ($id, $anvandare) {
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            if (count($vaningar) <= 1) {
                throw new AppFel('Sista våningen kan inte tas bort – ta bort hela lokalplanen i stället.');
            }
            $bort = null;
            $kvar = [];
            foreach ($vaningar as $v) {
                if ($v['id'] === $id) {
                    $bort = $v;
                } else {
                    $kvar[] = $v;
                }
            }
            if ($bort === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }
            $tx = new Transaktion('lokalplan_vaning_bort', $anvandare);
            self::skrivPlan(['vaningar' => $kvar, 'skapad' => $data['skapad'] ?? nu()]);
            $tx->historik([
                'typ' => 'lokalplan_vaning_bort',
                'kommentar' => 'Våningen "' . $bort['namn'] . '" togs bort ur lokalplanen.',
            ]);
            $tx->spara();
            self::stadBilder($bort);
        });
    }

    /* ----------------------------------------------------------------
     *  Bilder
     * ---------------------------------------------------------------- */

    /**
     * Laddar upp en bild till en våning.
     *
     * $somZon = true  → bilden blir en zon: ett foto av ett hörn av lokalen
     *                   som får en egen ruta på planen.
     * $somZon = false → bilden blir våningens planbild (ritning/fågelvy) och
     *                   ersätter den gamla. Zonrutorna ligger kvar.
     *
     * Returnerar ['vaning' => …, 'bild' => […]].
     */
    public static function sparaBild(string $vaningId, array $fil, bool $somZon = false,
                                     ?string $etikett = null, ?array $anvandare = null): array
    {
        $namn = self::taEmotFil($fil);
        $mapp = self::mapp();

        return Las::under(function () use ($vaningId, $namn, $somZon, $etikett, $anvandare) {
            $data = self::lasFil() ?? ['vaningar' => [], 'skapad' => nu()];
            $vaningar = self::normalisera($data);
            $index = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $vaningId) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                @unlink(self::mapp() . '/' . $namn);
                throw new AppFel('Våningen finns inte.', 404);
            }
            $vaning = $vaningar[$index];
            $gammalPlanbild = $vaning['bild'];
            $nyBild = ['fil' => $namn, 'lagd' => nu()];

            if ($somZon) {
                // Fotot blir en zon. Får egen ruta – läggs i ledigt utrymme.
                $zonId = self::skapaZonForFoto($etikett ?? ('Foto ' . (count($vaning['bilder']) + 1)), $anvandare);
                $nyBild['etikett'] = $etikett !== null && trim($etikett) !== ''
                    ? mb_substr(trim($etikett), 0, 40)
                    : ('Hörn ' . (count($vaning['bilder']) + 1));
                $nyBild['zon_id'] = $zonId;
                [$nyBild['x'], $nyBild['y'], $nyBild['b'], $nyBild['h']] =
                    self::ledigPlats($vaning['zonrutor'], $vaning['bilder']);
                $vaning['bilder'][] = $nyBild;
                $vaning['zonrutor'][] = [
                    'zon_id' => $zonId,
                    'x' => $nyBild['x'], 'y' => $nyBild['y'],
                    'b' => $nyBild['b'], 'h' => $nyBild['h'],
                ];
            } else {
                // Planbild: byt bakgrund, behåll alla zoner.
                $vaning['bild'] = $namn;
                $nyBild['etikett'] = 'Planbild';
            }
            $vaningar[$index] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);

            $tx = new Transaktion($somZon ? 'lokalplan_foto' : 'lokalplan_bild', $anvandare);
            $tx->historik([
                'typ' => $somZon ? 'lokalplan_foto' : 'lokalplan_bild',
                'kommentar' => $somZon
                    ? 'Ett foto lades till som zon på "' . $vaning['namn'] . '".'
                    : 'Ny planbild lades upp för "' . $vaning['namn'] . '".',
            ]);
            $tx->spara();

            // Gamla planbilden städas först nu, när den nya är på plats
            if (!$somZon && $gammalPlanbild !== null && $gammalPlanbild !== '' && $gammalPlanbild !== $namn) {
                @unlink(self::mapp() . '/' . basename((string) $gammalPlanbild));
            }
            return ['vaning' => $vaning, 'bild' => $nyBild];
        });
    }

    /**
     * "Nu är lokalen färdigfotad" – lägger alla foton som zoner på våningen
     * och sätter proportionerliga rutor. Användaren markerar klart, appen
     * sätter ihop. Rutorna går sedan att redigera som vanligt.
     */
    public static function fotograferaKlart(string $vaningId, ?array $anvandare = null): array
    {
        return Las::under(function () use ($vaningId, $anvandare) {
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            $index = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $vaningId) {
                    $index = $i;
                }
            }
            if ($index === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }
            $vaning = $vaningar[$index];
            $antal = count($vaning['bilder']);
            if ($antal === 0) {
                throw new AppFel('Det finns inga foton på våningen ännu.');
            }

            // Rutnät: ungefär kvadratiskt, samma storlek på alla rutor.
            $kolumner = (int) ceil(sqrt($antal));
            $rader = (int) ceil($antal / $kolumner);
            $marginal = 4.0;
            $mellanrum = 2.0;
            $ruteB = (100 - 2 * $marginal - ($kolumner - 1) * $mellanrum) / $kolumner;
            $ruteH = (100 - 2 * $marginal - ($rader - 1) * $mellanrum) / $rader;

            $rutor = [];
            foreach ($vaning['bilder'] as $i => $b) {
                $kol = $i % $kolumner;
                $rad = intdiv($i, $kolumner);
                $x = $marginal + $kol * ($ruteB + $mellanrum);
                $y = $marginal + $rad * ($ruteH + $mellanrum);
                $rutor[] = [
                    'zon_id' => $b['zon_id'],
                    'x' => round($x, 2), 'y' => round($y, 2),
                    'b' => round($ruteB, 2), 'h' => round($ruteH, 2),
                ];
            }
            $vaning['zonrutor'] = $rutor;
            // Foton som ligger på andra våningar behåller sina rutor –
            // här rör vi bara den här våningen.
            $vaningar[$index] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);

            $tx = new Transaktion('lokalplan_klart', $anvandare);
            $tx->historik([
                'typ' => 'lokalplan_klart',
                'kommentar' => $antal . ' foton sattes ihop till en skiss av "' . $vaning['namn'] . '".',
            ]);
            $tx->spara();
            return $vaning;
        });
    }

    /** Sparar zonrutorna för en våning (procent av ytan). */
    public static function sparaRutor(string $vaningId, array $rutor, ?array $anvandare = null): array
    {
        return Las::under(function () use ($vaningId, $rutor, $anvandare) {
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            $index = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $vaningId) {
                    $index = $i;
                }
            }
            if ($index === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }

            // En zon hör till en våning. Zoner som ligger på en annan våning
            // får inte flyttas hit av misstag.
            $andra = [];
            foreach ($vaningar as $i => $v) {
                if ($i === $index) {
                    continue;
                }
                foreach ($v['zonrutor'] as $r) {
                    $andra[$r['zon_id']] = true;
                }
            }
            $zonIder = [];
            foreach (Lagerplats::zoner() as $z) {
                $zonIder[$z['id']] = true;
            }

            $ren = [];
            foreach ($rutor as $r) {
                $zonId = (string) ($r['zon_id'] ?? '');
                if (!isset($zonIder[$zonId]) || isset($andra[$zonId])) {
                    continue;
                }
                // Kläm in rutan innanför ytan
                $x = max(0.0, min(99.9, (float) ($r['x'] ?? 0)));
                $y = max(0.0, min(99.9, (float) ($r['y'] ?? 0)));
                $b = max(2.0, min(100.0 - $x, (float) ($r['b'] ?? 10)));
                $h = max(2.0, min(100.0 - $y, (float) ($r['h'] ?? 10)));
                $ren[$zonId] = [
                    'zon_id' => $zonId,
                    'x' => round($x, 2), 'y' => round($y, 2),
                    'b' => round($b, 2), 'h' => round($h, 2),
                ];
            }

            $vaning = $vaningar[$index];
            $vaning['zonrutor'] = array_values($ren);
            // Fotona följer med sina zoner
            foreach ($vaning['bilder'] as $i => $b) {
                if (isset($ren[$b['zon_id']])) {
                    $vaning['bilder'][$i] = array_merge($b, [
                        'x' => $ren[$b['zon_id']]['x'], 'y' => $ren[$b['zon_id']]['y'],
                        'b' => $ren[$b['zon_id']]['b'], 'h' => $ren[$b['zon_id']]['h'],
                    ]);
                }
            }
            $vaningar[$index] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);

            $tx = new Transaktion('lokalplan_rutor', $anvandare);
            $tx->historik([
                'typ' => 'lokalplan_rutor',
                'kommentar' => count($ren) . ' zoner placerades på "' . $vaning['namn'] . '".',
            ]);
            $tx->spara();
            return $vaning;
        });
    }

    /** Tar bort ett foto från våningen, med dess zon. */
    public static function taBortFoto(string $vaningId, string $fil, ?array $anvandare = null): array
    {
        return Las::under(function () use ($vaningId, $fil, $anvandare) {
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            $index = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $vaningId) {
                    $index = $i;
                }
            }
            if ($index === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }
            $vaning = $vaningar[$index];
            $bort = null;
            $kvar = [];
            foreach ($vaning['bilder'] as $b) {
                if ($b['fil'] === $fil) {
                    $bort = $b;
                } else {
                    $kvar[] = $b;
                }
            }
            if ($bort === null) {
                throw new AppFel('Fotot finns inte på våningen.', 404);
            }
            $vaning['bilder'] = $kvar;
            $vaning['zonrutor'] = array_values(array_filter(
                $vaning['zonrutor'],
                fn ($r) => $r['zon_id'] !== $bort['zon_id']
            ));
            $vaningar[$index] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);

            $tx = new Transaktion('lokalplan_foto_bort', $anvandare);
            $tx->historik([
                'typ' => 'lokalplan_foto_bort',
                'kommentar' => 'Ett foto togs bort från "' . $vaning['namn'] . '".',
            ]);
            $tx->spara();

            @unlink(self::mapp() . '/' . basename($fil));
            // Zonen som fotot skapade ligger kvar som hyllzon – den kan
            // redan ha artiklar på sig, och zoner tas inte bort i den här
            // modellen. Den syns då som "oplacerad" i stället.
            return $vaning;
        });
    }

    /**
     * Placerar en befintlig zon (t.ex. en hyllzon) på våningens skiss.
     * Zonen får en ledig ruta – användaren kan sedan dra den på plats.
     */
    public static function placeraZon(string $vaningId, string $zonId, ?array $anvandare = null): array
    {
        return Las::under(function () use ($vaningId, $zonId, $anvandare) {
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            $index = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $vaningId) {
                    $index = $i;
                }
            }
            if ($index === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }
            $zon = Lagerplats::zon($zonId);
            if ($zon === null) {
                throw new AppFel('Zonen finns inte.', 404);
            }
            // Redan placerad någonstans?
            $placerade = self::placeradeZonIder();
            if (isset($placerade[$zonId])) {
                if ($placerade[$zonId] === $vaningId) {
                    return $vaningar[$index];   // redan på plats
                }
                throw new AppFel('Zonen ligger redan på en annan våning. Ta bort den där först.');
            }
            $vaning = $vaningar[$index];
            [$x, $y, $b, $h] = self::ledigPlats($vaning['zonrutor']);
            $vaning['zonrutor'][] = [
                'zon_id' => $zonId,
                'x' => round($x, 2), 'y' => round($y, 2),
                'b' => round($b, 2), 'h' => round($h, 2),
            ];
            $vaningar[$index] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);

            $tx = new Transaktion('lokalplan_rutor', $anvandare);
            $tx->historik([
                'typ' => 'lokalplan_rutor',
                'kommentar' => 'Zonen ' . $zon['kod'] . ' placerades på "' . $vaning['namn'] . '".',
            ]);
            $tx->spara();
            return $vaning;
        });
    }

    /** Tar bort zonen ur våningens yta (fotot ligger kvar i listan). */
    public static function taBortRuta(string $vaningId, string $zonId, ?array $anvandare = null): array
    {
        return Las::under(function () use ($vaningId, $zonId, $anvandare) {
            $data = self::lasFil();
            if ($data === null) {
                throw new AppFel('Det finns ingen lokalplan.');
            }
            $vaningar = self::normalisera($data);
            $index = null;
            foreach ($vaningar as $i => $v) {
                if ($v['id'] === $vaningId) {
                    $index = $i;
                }
            }
            if ($index === null) {
                throw new AppFel('Våningen finns inte.', 404);
            }
            $vaning = $vaningar[$index];
            $vaning['zonrutor'] = array_values(array_filter(
                $vaning['zonrutor'],
                fn ($r) => $r['zon_id'] !== $zonId
            ));
            $vaningar[$index] = $vaning;
            self::skrivPlan(['vaningar' => $vaningar, 'skapad' => $data['skapad'] ?? nu()]);

            $tx = new Transaktion('lokalplan_ruta_bort', $anvandare);
            $tx->historik([
                'typ' => 'lokalplan_ruta_bort',
                'kommentar' => 'En zon togs bort från "' . $vaning['namn'] . '".',
            ]);
            $tx->spara();
            return $vaning;
        });
    }

    /** Tar bort hela lokalplanen: alla våningar och alla bilder. */
    public static function taBort(?array $anvandare = null): void
    {
        $plan = self::plan();
        Las::under(function () use ($plan, $anvandare) {
            $tx = new Transaktion('lokalplan_bort', $anvandare);
            $fil = self::mapp() . '/plan.json';
            if (is_file($fil)) {
                @unlink($fil);
            }
            foreach ($plan['vaningar'] ?? [] as $v) {
                self::stadBilder($v);
            }
            $tx->historik([
                'typ' => 'lokalplan_bort',
                'kommentar' => 'Hela lokalplanen togs bort.',
            ]);
            $tx->spara();
        });
    }

    /* ----------------------------------------------------------------
     *  Interna hjälpare
     * ---------------------------------------------------------------- */

    private static function lasFil(): ?array
    {
        $fil = self::mapp() . '/plan.json';
        if (!is_file($fil)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($fil), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Gör om plan.json (gammalt eller nytt format) till en lista våningar.
     * Gammalt format: {"bild": …, "zonrutor": […] } = en enda våning.
     */
    private static function normalisera(array $data): array
    {
        $ut = [];
        if (isset($data['vaningar']) && is_array($data['vaningar'])) {
            foreach ($data['vaningar'] as $v) {
                if (!is_array($v) || empty($v['id'])) {
                    continue;
                }
                $ut[] = self::rensaVaning($v);
            }
            return $ut;
        }
        // Äldre plan: en bild, rutor i roten
        if (!empty($data['bild'])) {
            $ut[] = self::rensaVaning([
                'id' => 'V1',
                'namn' => 'Bottenvåning',
                'bild' => (string) $data['bild'],
                'bilder' => [],
                'zonrutor' => $data['zonrutor'] ?? [],
                'skapad' => (string) ($data['skapad'] ?? ''),
            ]);
        }
        return $ut;
    }

    private static function rensaVaning(array $v): array
    {
        $bilder = [];
        foreach ((array) ($v['bilder'] ?? []) as $b) {
            if (!is_array($b) || empty($b['fil'])) {
                continue;
            }
            $bilder[] = [
                'fil' => basename((string) $b['fil']),
                'etikett' => (string) ($b['etikett'] ?? ''),
                'zon_id' => (string) ($b['zon_id'] ?? ''),
                'x' => (float) ($b['x'] ?? 0), 'y' => (float) ($b['y'] ?? 0),
                'b' => (float) ($b['b'] ?? 20), 'h' => (float) ($b['h'] ?? 20),
            ];
        }
        $rutor = [];
        foreach ((array) ($v['zonrutor'] ?? []) as $r) {
            if (!is_array($r) || empty($r['zon_id'])) {
                continue;
            }
            $rutor[] = [
                'zon_id' => (string) $r['zon_id'],
                'x' => (float) ($r['x'] ?? 0), 'y' => (float) ($r['y'] ?? 0),
                'b' => (float) ($r['b'] ?? 20), 'h' => (float) ($r['h'] ?? 20),
            ];
        }
        return [
            'id' => (string) $v['id'],
            'namn' => (string) ($v['namn'] ?? 'Våning'),
            'bild' => isset($v['bild']) && $v['bild'] !== '' ? basename((string) $v['bild']) : null,
            'bilder' => $bilder,
            'zonrutor' => $rutor,
            'skapad' => (string) ($v['skapad'] ?? ''),
        ];
    }

    private static function nyttVaningId(array $vaningar): string
    {
        $tagna = [];
        foreach ($vaningar as $v) {
            $tagna[$v['id']] = true;
        }
        for ($i = 1; $i < 100; $i++) {
            $id = 'V' . $i;
            if (!isset($tagna[$id])) {
                return $id;
            }
        }
        return 'V' . bin2hex(random_bytes(2));
    }

    /**
     * Tar emot och sparar en uppladdad bild. Returnerar filnamnet.
     * Skalar ner och rätar upp efter EXIF på samma sätt som artikelbilderna.
     */
    private static function taEmotFil(array $fil): string
    {
        if (($fil['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AppFel('Ingen bild togs emot. Välj en fil och försök igen.');
        }
        if ((int) ($fil['size'] ?? 0) > self::MAX_BYTES) {
            throw new AppFel('Bilden är för stor (max ' . round(self::MAX_BYTES / 1024 / 1024) . ' MB).');
        }
        $tmp = (string) ($fil['tmp_name'] ?? '');
        $info = $tmp !== '' ? @getimagesize($tmp) : false;
        if ($info === false || !isset(self::TYPER[$info[2]])) {
            throw new AppFel('Bilden måste vara JPG, PNG eller WebP.');
        }
        $and = self::TYPER[$info[2]];
        $namn = 'lokal-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $and;
        $mapp = self::mapp();
        if (!is_dir($mapp) && !@mkdir($mapp, 0770, true) && !is_dir($mapp)) {
            throw new AppFel('Kunde inte skapa mappen för lokalplanen.', 500);
        }
        $mal = $mapp . '/' . $namn;
        // move_uploaded_file kräver en riktig HTTP-uppladdning; vid test och
        // import kan filen komma från en vanlig sökväg.
        $flyttad = @move_uploaded_file($tmp, $mal);
        if (!$flyttad) {
            $flyttad = @copy($tmp, $mal);
        }
        if (!$flyttad || !is_file($mal)) {
            throw new AppFel('Kunde inte spara bilden.', 500);
        }
        self::skalaNer($mal, (int) $info[0], (int) $info[1], (int) $info[2]);
        return $namn;
    }

    /**
     * Hittar en ledig ruta för ett nytt foto: går igenom ett rutnät och
     * väljer första platsen som inte krockar med en befintlig ruta.
     */
    private static function ledigPlats(array $rutor): array
    {
        $b = 28.0;
        $h = 24.0;
        for ($rad = 0; $rad < 6; $rad++) {
            for ($kol = 0; $kol < 4; $kol++) {
                $x = 4 + $kol * 24.0;
                $y = 4 + $rad * 16.0;
                if ($y + $h > 96) {
                    continue;
                }
                $krock = false;
                foreach ($rutor as $r) {
                    $överlappX = $x < ($r['x'] + $r['b']) && ($x + $b) > $r['x'];
                    $överlappY = $y < ($r['y'] + $r['h']) && ($y + $h) > $r['y'];
                    if ($överlappX && $överlappY) {
                        $krock = true;
                        break;
                    }
                }
                if (!$krock) {
                    return [$x, $y, $b, $h];
                }
            }
        }
        // Fullt: lägg ovanpå med förskjutning så den ändå går att greppa
        $n = count($rutor) % 8;
        return [4.0 + $n * 3, 4.0 + $n * 3, $b, $h];
    }

    /**
     * Skapar en zon för ett foto. Zonen blir en egen hyllzon i lagerplats-
     * modellen, så artiklar kan kopplas till den och QR-lappar kan skrivas ut.
     */
    private static function skapaZonForFoto(string $namn, ?array $anvandare): string
    {
        $befintliga = Lagerplats::zoner();
        $nummer = count($befintliga) + 1;
        $kod = 'F' . $nummer;
        // Koden måste vara unik
        $tagna = array_column($befintliga, 'kod');
        $i = $nummer;
        while (in_array($kod, $tagna, true)) {
            $i++;
            $kod = 'F' . $i;
            if ($i > 999) {
                throw new AppFel('Kunde inte skapa en kod för zonen.', 500);
            }
        }
        $zon = Lagerplats::skapaZon(
            mb_substr(trim($namn) !== '' ? trim($namn) : $kod, 0, 40),
            $kod,
            1,
            1,
            $anvandare
        );
        return (string) $zon['id'];
    }

    /** Raderar alla bildfiler som hör till en våning. */
    private static function stadBilder(array $vaning): void
    {
        if (!empty($vaning['bild'])) {
            @unlink(self::mapp() . '/' . basename((string) $vaning['bild']));
        }
        foreach ($vaning['bilder'] ?? [] as $b) {
            @unlink(self::mapp() . '/' . basename((string) ($b['fil'] ?? '')));
        }
    }

    private static function skrivPlan(array $plan): void
    {
        $mapp = self::mapp();
        if (!is_dir($mapp)) {
            @mkdir($mapp, 0770, true);
        }
        Json::skrivAtomiskt($mapp . '/plan.json', tdl_json($plan));
    }

    /** Skalar ner och rätar upp enligt EXIF så att bilden inte blir sidledes. */
    private static function skalaNer(string $sokvag, int $bredd, int $hojd, int $typ): void
    {
        if ($bredd <= 0 || $hojd <= 0) {
            return;
        }
        if (!function_exists('imagecreatetruecolor')) {
            return; // GD saknas – bilden får ligga som den är
        }
        $skala = min(1, self::MAX_SIDA / max($bredd, $hojd));
        $nyB = max(1, (int) round($bredd * $skala));
        $nyH = max(1, (int) round($hojd * $skala));

        $kalla = match ($typ) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sokvag),
            IMAGETYPE_PNG => @imagecreatefrompng($sokvag),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sokvag),
            default => false,
        };
        if ($kalla === false) {
            return;
        }

        // Rätar upp efter EXIF-orientering (kamerafoton ligger ofta sidledes)
        if ($typ === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sokvag);
            $o = (int) ($exif['Orientation'] ?? 1);
            $rotera = match ($o) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($rotera !== 0) {
                $kalla = imagerotate($kalla, $rotera, 0);
                if ($kalla !== false) {
                    $bredd = imagesx($kalla);
                    $hojd = imagesy($kalla);
                    $skala = min(1, self::MAX_SIDA / max($bredd, $hojd));
                    $nyB = max(1, (int) round($bredd * $skala));
                    $nyH = max(1, (int) round($hojd * $skala));
                }
            }
        }

        if ($skala >= 1 && $typ === IMAGETYPE_JPEG) {
            imagedestroy($kalla);
            return;
        }
        $ny = imagecreatetruecolor($nyB, $nyH);
        if ($ny === false) {
            imagedestroy($kalla);
            return;
        }
        imagecopyresampled($ny, $kalla, 0, 0, 0, 0, $nyB, $nyH, imagesx($kalla), imagesy($kalla));

        match ($typ) {
            IMAGETYPE_JPEG => imagejpeg($ny, $sokvag, 82),
            IMAGETYPE_PNG => imagepng($ny, $sokvag, 6),
            IMAGETYPE_WEBP => imagewebp($ny, $sokvag, 82),
            default => null,
        };
        imagedestroy($ny);
        imagedestroy($kalla);
    }
}
