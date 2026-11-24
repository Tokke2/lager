<?php
/**
 * Bilder till artiklar.
 *
 * Sparas som filer i storage/bilder/<artikel-id>/<slumpad>.webp|jpg|png och
 * metadata i artikelns eget fält "bilder" (lista med filnamn, bildtext och tid).
 * Ingen bild lagras i artikeln som base64 – JSON-filerna ska förbli små och
 * snabba att läsa.
 *
 * Tillåtna format kontrolleras med getimagesize(), inte med filändelsen.
 * Max 8 MB per bild och 12 bilder per artikel. Uppladdade bilder skalas ner
 * till max 1600 px på längsta sidan om GD finns tillgängligt.
 */

declare(strict_types=1);

namespace TDL\Support;

use TDL\Domain\Lager;

final class Bilder
{
    public const MAX_BILDER = 12;
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_SIDA = 1600;

    private const TYPER = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public static function katalog(string $artikelId): string
    {
        $id = preg_replace('/[^A-Z0-9]/', '', strtoupper($artikelId)) ?: '';
        if ($id === '') {
            throw new AppFel('Ogiltigt artikel-id.');
        }
        return TDL_STORAGE . '/bilder/' . $id;
    }

    /** @return list<array{fil:string,text:string,skapad:string}> */
    public static function forArtikel(array $artikel): array
    {
        $lista = $artikel['bilder'] ?? [];
        return is_array($lista) ? array_values($lista) : [];
    }

    /**
     * Adress till en bild. Signerad och med sessions-id när det behövs, så att
     * bilden alltid går att hämta – både i vanlig drift och i förhands­
     * granskningsläget där cookies saknas.
     */
    public static function url(string $artikelId, string $fil): string
    {
        return \TDL\Http\ArtikelQr::bildUrl($artikelId, $fil);
    }

    /** Fullständig sökväg till en bild som hör till artikeln, eller null. */
    public static function sokvag(string $artikelId, string $fil): ?string
    {
        $katalog = self::katalog($artikelId);
        $namn = basename($fil);
        if (!preg_match('/^[a-z0-9]{6,}\.(jpg|png|gif|webp)$/', $namn)) {
            return null;
        }
        $fil = $katalog . '/' . $namn;
        return is_file($fil) ? $fil : null;
    }

    /**
     * Skapar (eller återanvänder) en nedskalad kopia av en bild.
     *
     * Varför: en artikelbild på 1600 px väger och avkodas som flera
     * megapixlar, men visas i 130 px-rutor i listan. Att skicka originalet
     * dit kostar data, minne och batteri – särskilt på telefon. Miniatyrerna
     * cachas på disk och skapas bara en gång per bild och bredd.
     *
     * Returnerar sökvägen till miniatyren, originalet om bilden redan är
     * mindre, eller null om GD saknas.
     */
    public static function miniatyr(string $sokvag, int $bredd): ?string
    {
        if ($bredd < 80 || $bredd > 1200 || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $info = @getimagesize($sokvag);
        if ($info === false || !isset(self::TYPER[$info[2]])) {
            return null;
        }
        [$andelse] = self::TYPER[$info[2]];
        $bas = preg_replace('/\.[a-z]+$/i', '', $sokvag);
        $mal = $bas . '.w' . $bredd . '.' . $andelse;
        $kallaTid = (int) @filemtime($sokvag);
        if (is_file($mal) && (int) @filemtime($mal) >= $kallaTid) {
            return $mal;
        }
        // Skala aldrig upp – då blir bilden suddig och större än originalet.
        if (max((int) $info[0], (int) $info[1]) <= $bredd) {
            return $sokvag;
        }

        $bild = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sokvag),
            IMAGETYPE_PNG => @imagecreatefrompng($sokvag),
            IMAGETYPE_GIF => @imagecreatefromgif($sokvag),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sokvag) : false,
            default => false,
        };
        if (!$bild) {
            return null;
        }
        $breddKalla = imagesx($bild);
        $hojdKalla = imagesy($bild);
        $skala = $bredd / max(1, max($breddKalla, $hojdKalla));
        $nyB = max(1, (int) round($breddKalla * $skala));
        $nyH = max(1, (int) round($hojdKalla * $skala));
        $ny = imagecreatetruecolor($nyB, $nyH);
        if ($andelse === 'png' || $andelse === 'webp') {
            imagealphablending($ny, false);
            imagesavealpha($ny, true);
        }
        imagecopyresampled($ny, $bild, 0, 0, 0, 0, $nyB, $nyH, $breddKalla, $hojdKalla);
        imagedestroy($bild);

        // Lätt oskarp mask: nedskalning mjukar upp kanter. Bara när bilden
        // krympts ordentligt, så att brus inte förstärks i onödan.
        if ($skala < 0.66 && function_exists('imageconvolution')) {
            @imageconvolution($ny, [
                [0.0, -0.25, 0.0],
                [-0.25, 2.0, -0.25],
                [0.0, -0.25, 0.0],
            ], 1.0, 0.0);
        }

        $ok = match ($andelse) {
            'jpg' => imagejpeg($ny, $mal, 88),
            'png' => imagepng($ny, $mal, 7),
            'gif' => imagegif($ny, $mal),
            'webp' => function_exists('imagewebp') ? imagewebp($ny, $mal, 88) : false,
            default => false,
        };
        imagedestroy($ny);
        if (!$ok) {
            @unlink($mal);
            return null;
        }
        @chmod($mal, 0600);
        return $mal;
    }

    public static function mimeTyp(string $sokvag): string
    {
        $info = @getimagesize($sokvag);
        if ($info === false || !isset(self::TYPER[$info[2]])) {
            return 'application/octet-stream';
        }
        return self::TYPER[$info[2]][1];
    }

    /**
     * Sparar en uppladdad bild. Returnerar metadataraden som ska läggas i artikeln.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $fil
     */
    public static function spara(array $fil, string $artikelId, string $text = ''): array
    {
        if (($fil['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new AppFel('Ingen bild valdes.');
        }
        if (($fil['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new AppFel('Bilden kunde inte laddas upp (' . (int) $fil['error'] . ').');
        }
        if ((int) ($fil['size'] ?? 0) > self::MAX_BYTES) {
            throw new AppFel('Bilden är för stor. Max 8 MB per bild.');
        }
        $tmp = (string) ($fil['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new AppFel('Uppladdningen kunde inte verifieras.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TYPER[$info[2]])) {
            throw new AppFel('Filen är ingen bild i formatet JPG, PNG, GIF eller WebP.');
        }
        [$andelse] = self::TYPER[$info[2]];

        $katalog = self::katalog($artikelId);
        if (!is_dir($katalog) && !@mkdir($katalog, 0700, true) && !is_dir($katalog)) {
            throw new AppFel('Kunde inte skapa bildkatalogen.');
        }

        $namn = bin2hex(random_bytes(8)) . '.' . $andelse;
        $mal = $katalog . '/' . $namn;

        if (!self::skalaNer($tmp, $mal, $info[2])) {
            if (!move_uploaded_file($tmp, $mal)) {
                throw new AppFel('Kunde inte spara bilden.');
            }
        }
        @chmod($mal, 0600);

        return [
            'fil' => $namn,
            'text' => mb_substr(trim($text), 0, 120),
            'skapad' => nu(),
        ];
    }

    /** Skalar ner med GD om möjligt. Returnerar false om GD saknas eller misslyckas. */
    private static function skalaNer(string $fran, string $till, int $typ): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        $bild = match ($typ) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($fran),
            IMAGETYPE_PNG => @imagecreatefrompng($fran),
            IMAGETYPE_GIF => @imagecreatefromgif($fran),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fran) : false,
            default => false,
        };
        if (!$bild) {
            return false;
        }
        $bredd = imagesx($bild);
        $hojd = imagesy($bild);
        $skala = min(1, self::MAX_SIDA / max($bredd, $hojd));
        if ($skala < 1) {
            $nyB = max(1, (int) round($bredd * $skala));
            $nyH = max(1, (int) round($hojd * $skala));
            $ny = imagecreatetruecolor($nyB, $nyH);
            if ($typ === IMAGETYPE_PNG || $typ === IMAGETYPE_WEBP) {
                imagealphablending($ny, false);
                imagesavealpha($ny, true);
            }
            imagecopyresampled($ny, $bild, 0, 0, 0, 0, $nyB, $nyH, $bredd, $hojd);
            imagedestroy($bild);
            $bild = $ny;
        }
        $ok = match ($typ) {
            IMAGETYPE_JPEG => imagejpeg($bild, $till, 85),
            IMAGETYPE_PNG => imagepng($bild, $till, 7),
            IMAGETYPE_GIF => imagegif($bild, $till),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($bild, $till, 85) : false,
            default => false,
        };
        imagedestroy($bild);
        if (!$ok) {
            return false;
        }
        @unlink($fran);
        return true;
    }

    /** Tar bort en bild från disk och returnerar nya bildlistan. */
    public static function taBort(array $artikel, string $fil): array
    {
        $kvar = [];
        foreach (self::forArtikel($artikel) as $bild) {
            if (($bild['fil'] ?? '') === $fil) {
                $sokvag = self::sokvag($artikel['id'], $fil);
                if ($sokvag !== null) {
                    @unlink($sokvag);
                }
                continue;
            }
            $kvar[] = $bild;
        }
        return $kvar;
    }

    /** Antal platser i lagringen (används för informationsruta i admin). */
    public static function statistik(): array
    {
        $rot = TDL_STORAGE . '/bilder';
        $antal = 0;
        $bytes = 0;
        if (is_dir($rot)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rot, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    $antal++;
                    $bytes += $f->getSize();
                }
            }
        }
        return ['antal' => $antal, 'bytes' => $bytes];
    }
}
