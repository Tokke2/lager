<?php
/**
 * Aktivt arbetsställe – valet som görs innan arbetet börjar.
 *
 * Idén: den som jobbar i lagret står på ETT ställe under sitt pass. Därför ska
 * stället väljas en gång, inte tio gånger per dag i varje formulär. Valet sparas
 * i sessionen (inte i datalagringen – det hör till personen, inte till lagret)
 * och kan bytas när som helst.
 *
 * Viktigt: valet är en GENVÄG, aldrig en spärr. Varje formulär visar fortfarande
 * vilket ställe som används och går att ändra. Servern läser alltid det som
 * skickas in – den litar aldrig blint på sessionsvalet.
 */
declare(strict_types=1);

namespace TDL\Support;

final class Arbetsstalle
{
    public const NYCKEL = 'aktivt_plats_id';

    /**
     * "Valet är gjort" – skild från VILKET ställe som valdes.
     *
     * Behövs för administratörens "Arbeta överallt": då finns inget
     * plats-id, men valsidan ska ändå inte komma tillbaka. Utan den flaggan
     * blir arValt() false och porten visar valsidan i evighet.
     */
    public const GJORT = 'arbetsstalle_valt';

    /** Arbetsställen man får välja mellan (aktiva, utan systemposten). */
    public static function valbara(): array
    {
        $ut = [];
        foreach (\TDL\Domain\Lager::platser(true) as $p) {
            if (empty($p['system'])) {
                $ut[] = $p;
            }
        }
        return $ut;
    }

    /**
     * Det valda arbetsstället, eller null om inget val gjorts (eller om det
     * valda stället stängts sedan dess).
     */
    public static function aktivt(): ?array
    {
        $id = $_SESSION[self::NYCKEL] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }
        $plats = \TDL\Domain\Lager::plats($id);
        if ($plats === null || empty($plats['aktiv']) || !empty($plats['system'])) {
            // Stället har stängts – glöm valet i stället för att visa ett fel.
            // Flaggan måste också bort, annars står personen utan ställe utan
            // att få välja ett nytt.
            unset($_SESSION[self::NYCKEL], $_SESSION[self::GJORT]);
            return null;
        }
        return $plats;
    }

    public static function id(): string
    {
        return (string) (self::aktivt()['id'] ?? '');
    }

    /** Är ett arbetsställe valt? */
    public static function arValt(): bool
    {
        return self::aktivt() !== null;
    }

    /**
     * Har personen gjort sitt val för passet? Sant även för admin som valt
     * "arbeta överallt". Det är DEN HÄR porten ska fråga efter – inte arValt().
     */
    public static function valetGjort(): bool
    {
        // aktivt() först: den rensar både plats-id och flagga om stället
        // stängts. Frågar vi flaggan först hinner den svara ja på ett ställe
        // som inte längre finns – och då blir personen fast utan ställe.
        if (self::arValt()) {
            return true;
        }
        if (!empty($_SESSION[self::GJORT])) {
            // Flaggan satt men inget ställe valt = "arbeta överallt". Gäller
            // bara om det inte finns ett plats-id kvar som pekar på något
            // som försvunnit.
            return ($_SESSION[self::NYCKEL] ?? '') === '';
        }
        return false;
    }

    /**
     * Spara valet. Tom sträng betyder "inget valt" (admin som arbetar överallt).
     * Kräver ett konto – valet hör till inloggningen.
     */
    public static function valj(string $platsId): void
    {
        if ($platsId === '') {
            unset($_SESSION[self::NYCKEL]);
            $_SESSION[self::GJORT] = true;
            return;
        }
        $plats = \TDL\Domain\Lager::plats($platsId);
        if ($plats === null || empty($plats['aktiv']) || !empty($plats['system'])) {
            throw new AppFel('Arbetsstället går inte att välja.');
        }
        $_SESSION[self::NYCKEL] = $plats['id'];
        $_SESSION[self::GJORT] = true;
    }

    public static function glom(): void
    {
        unset($_SESSION[self::NYCKEL], $_SESSION[self::GJORT]);
    }

    /**
     * Standardvärde för ett platsfält: det valda stället, annars ett enda
     * valbart ställe, annars tomt.
     */
    public static function forvalt(): string
    {
        if (self::arValt()) {
            return self::id();
        }
        $valbara = self::valbara();
        return count($valbara) === 1 ? $valbara[0]['id'] : '';
    }

    /**
     * Text som förklarar varför ett val behövs – används i vyn när flera
     * arbetsställen finns men inget är valt.
     */
    public static function behovsText(): string
    {
        return 'Välj arbetsställe först. Då fylls det i automatiskt i inleverans, '
            . 'uttag, plocklista och inventering – och du slipper välja om för varje sak.';
    }
}
