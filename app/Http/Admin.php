<?php
/**
 * Adminpanel: varumärke/vitmärkning, systemstatus och underhåll.
 * Alla åtgärder kräver admin och kontrolleras på servern.
 */

declare(strict_types=1);

namespace TDL\Http;

use TDL\Support\AppFel;
use TDL\Support\Backupkontroll;
use TDL\Support\Auth;
use TDL\Support\Historik;
use TDL\Support\Installningar;
use TDL\Support\Json;
use TDL\Support\Konto;
use TDL\Support\Las;
use TDL\Support\Sakerhet;
use TDL\Support\Varumarke;

final class Admin
{
    public static function varumarke(): void
    {
        $konto = Auth::kravRoll('admin');
        Vyer::rendera('admin_varumarke', [
            'titel' => 'Varumärke',
            'konto' => $konto,
            'aktiv' => 'varumarke',
            'v' => Varumarke::alla(),
            'standard' => Varumarke::STANDARD,
        ]);
    }

    public static function varumarkeSpara(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Varumarke::spara($_POST, $konto);
        flash('ok', 'Varumärkesinställningarna sparades. Ladda om sidan för att se dem.');
        omdirigera('/admin/varumarke');
    }

    public static function varumarkeLogotyp(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        $typ = post('typ') === 'ikon' ? 'ikon' : 'logotyp';
        $fil = $_FILES['fil'] ?? null;
        if (!is_array($fil) || ($fil['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AppFel('Ingen fil laddades upp.');
        }
        if (($fil['size'] ?? 0) > 512 * 1024) {
            throw new AppFel('Filen är för stor (max 512 kB).');
        }
        if (!is_uploaded_file($fil['tmp_name'])) {
            throw new AppFel('Uppladdningen kunde inte verifieras.');
        }
        $svg = (string) file_get_contents($fil['tmp_name']);
        Varumarke::sparaLogotyp($svg, $typ, $konto);
        flash('ok', ucfirst($typ) . ' uppladdad och sanerad.');
        omdirigera('/admin/varumarke');
    }

    public static function varumarkeAterstall(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        Varumarke::aterstallLogotyp(post('typ') === 'ikon' ? 'ikon' : 'logotyp', $konto);
        flash('ok', 'Logotypen återställd till appens standard.');
        omdirigera('/admin/varumarke');
    }

    /**
     * Serverar den uppladdade logotypen. Filen ligger utanför webbroten;
     * här skickas den med rätt Content-Type och lång cachetid.
     */
    /**
     * Tryckoriginalen (SVG med banor, färdiga för tryckeri) packas i en zip och
     * skickas direkt från appen. Inget att leta upp på en server, ingen lös fil
     * att hålla reda på – den som ska beställa tryck får allt i en klump.
     */
    public static function tryckOriginal(): void
    {
        Auth::kravRoll('admin');
        $rot = TDL_ROT . '/brand';
        $filer = [];

        foreach (['ikon.svg', 'ikon-ljus.svg', 'ikon-monokrom.svg',
                  'marke.svg', 'marke-ljus.svg', 'marke-enfarg.svg',
                  'etikett-80x50.svg'] as $namn) {
            if (is_file($rot . '/' . $namn)) {
                $filer['brand/' . $namn] = $rot . '/' . $namn;
            }
        }
        if (is_dir($rot . '/tryck')) {
            foreach (glob($rot . '/tryck/*') ?: [] as $fil) {
                if (is_file($fil)) {
                    $filer['brand/tryck/' . basename($fil)] = $fil;
                }
            }
        }
        if (is_file($rot . '/ikoner/ikon.ico')) {
            $filer['brand/ikoner/ikon.ico'] = $rot . '/ikoner/ikon.ico';
        }
        foreach (glob($rot . '/ikoner/*.png') ?: [] as $fil) {
            $filer['brand/ikoner/' . basename($fil)] = $fil;
        }

        if (!class_exists(\ZipArchive::class)) {
            Svar::text('ZipArchive saknas på servern – kontakta den som driftar appen.', 500);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tdl-tryck-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($filer as $namn => $fil) {
            $zip->addFile($fil, $namn);
        }
        $zip->addFromString('LASMIG.txt', implode("\n", [
            'tryckoriginal',
            'Genererat ' . gmdate('Y-m-d H:i') . ' UTC av ' . (Auth::inloggad()['namn'] ?? ''),
            '',
            'Innehåll:',
            '  brand/tryck/marke-tryckark.svg          Färdigt A3-ark med alla varianter, mått och färger',
            '  brand/ikon*.svg, marke*.svg             Appikon och märke (inga bokstäver, ingen firma)',
            '  brand/etikett-80x50.svg                 Etikettmall 80 x 50 mm',
            '  brand/ikoner/                           PNG och .ico i alla storlekar',
            '',
            'Märket är fristående: inga bokstäver och inget företagsnamn, så det kan',
            'användas för vilken installation som helst – även efter vitmärkning.',
            'Alla SVG är fristående: inga typsnitt behöver installeras, alla mått är i mm.',
            'Öppnas i Illustrator, Inkscape eller Affinity. Se dokument/05 för tryckkrav.',
        ]) . "\n");
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="lager-tryckoriginal.zip"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
    }

    public static function logotypFil(string $typ): void
    {
        $fil = $typ === 'ikon' ? Varumarke::v('ikon_fil') : Varumarke::v('logotyp_fil');
        if ($fil === '' || !is_file($fil)) {
            // Fall tillbaka på appens standardikon
            $std = TDL_ROT . '/brand/' . ($typ === 'ikon' ? 'ikon.svg' : 'logotyp.svg');
            if (!is_file($std)) {
                \TDL\Support\Svar::ejFunnet('Ingen logotyp finns.');
                return;
            }
            $fil = $std;
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($fil);
        exit;
    }

    public static function system(): void
    {
        $konto = Auth::kravRoll('admin');

        $datafiler = [];
        foreach (array_keys(Json::FILER) as $nyckel) {
            $sokvag = Json::sokvag($nyckel);
            $datafiler[$nyckel] = [
                'finns' => is_file($sokvag),
                'storlek' => is_file($sokvag) ? filesize($sokvag) : 0,
                'poster' => null,
                'rattigheter' => is_file($sokvag) ? substr(sprintf('%o', fileperms($sokvag)), -4) : '–',
                'andrad' => is_file($sokvag) ? gmdate('Y-m-d H:i', filemtime($sokvag)) : '–',
            ];
            if (is_file($sokvag)) {
                $d = json_decode((string) file_get_contents($sokvag), true);
                $datafiler[$nyckel]['poster'] = is_array($d) && isset($d['poster']) ? count($d['poster']) : null;
            }
        }

        $historik = Json::las('historik');
        $kedjefel = Historik::verifiera($historik);

        $backuper = glob(TDL_BACKUP . '/*.zip') ?: [];
        usort($backuper, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $backupInfo = [];
        foreach (array_slice($backuper, 0, 10) as $b) {
            $backupInfo[] = ['namn' => basename($b), 'storlek' => filesize($b), 'tid' => gmdate('Y-m-d H:i', filemtime($b))];
        }

        $halso = \TDL\Support\HalsoKontroll::kor();

        Vyer::rendera('admin_system', [
            'titel' => 'Systemstatus',
            'konto' => $konto,
            'aktiv' => 'system',
            'halso' => $halso,
            'datafiler' => $datafiler,
            'kedjefel' => $kedjefel,
            'historikAntal' => count($historik['poster']),
            'backuper' => $backupInfo,
            'backupKatalog' => TDL_BACKUP,
            'journaler' => count(Las::oppnaJournaler()),
            'php' => PHP_VERSION,
            'kanns' => [
                'zip' => class_exists('ZipArchive'),
                'curl' => function_exists('curl_init'),
                'openssl' => extension_loaded('openssl'),
                'fsync' => function_exists('fsync'),
            ],
            'disk' => @disk_free_space(TDL_STORAGE),
            'sessioner' => count(glob(TDL_SESSION . '/sess_*') ?: []),
            'basUrl' => \TDL\Http\ArtikelQr::basUrl(),
            'basUrlFast' => is_file(TDL_STORAGE . '/adress.json'),
            'basUrlPublik' => \TDL\Http\ArtikelQr::adressArPublik(),
            'bildStatistik' => \TDL\Support\Bilder::statistik(),
            'installningar' => Installningar::alla(),
            'backupLage' => Backupkontroll::lage(),
            'backupMaxDygn' => (int) Installningar::hamta('backup_max_dygn'),
        ]);
    }

    /** Spara larm- och säkerhetsinställningar. */
    /** Hälsokontrollen – hela listan (förslag 20). */
    public static function halsa(): void
    {
        $konto = Auth::kravRoll('admin');
        Vyer::rendera('admin_halsa', [
            'titel' => 'Hälsokontroll',
            'konto' => $konto,
            'aktiv' => 'system',
            'halso' => \TDL\Support\HalsoKontroll::kor(),
        ]);
    }

    public static function systemInstallningar(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();

        $mail = trim(post('miniminiva_mail'));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            throw new AppFel('E-postadressen ser inte ut som en adress.');
        }
        $antal = max(0, (int) post('tva_person_grans', '0'));
        $varde = max(0, (int) post('tva_person_varde', '0'));
        $backupDygn = (int) post('backup_max_dygn', '2');
        $backupDygn = in_array($backupDygn, [1, 2, 3, 7, 14], true) ? $backupDygn : 2;

        Installningar::spara([
            'miniminiva_larm' => post('miniminiva_larm') === '1' ? 1 : 0,
            'miniminiva_per_mail' => post('miniminiva_per_mail') === '1' ? 1 : 0,
            'miniminiva_mail' => $mail,
            'tva_person_grans' => $antal,
            'tva_person_varde' => $varde,
            'backup_max_dygn' => $backupDygn,
            'skrivare' => \TDL\Support\Skrivare::finns($skrivare = (string) post('skrivare'))
                ? $skrivare
                : \TDL\Support\Skrivare::STANDARD,
        ]);
        \TDL\Support\HalsoKontroll::rensaCache();
        flash('ok', 'Inställningarna är sparade.');
        omdirigera('/admin/system');
    }

    public static function systemBackup(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        if (!class_exists(\ZipArchive::class)) {
            throw new AppFel('PHP-tillägget zip saknas på servern.');
        }
        $kod = 0;
        $ut = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TDL_ROT . '/bin/backup.php') . ' 2>&1', $ut, $kod);
        if ($kod !== 0) {
            throw new AppFel('Backupen misslyckades: ' . implode(' ', array_slice($ut, -3)));
        }
        \TDL\Support\HalsoKontroll::rensaCache();
        flash('ok', 'Backup skapad: ' . implode(' ', array_slice($ut, 0, 1)));
        omdirigera('/admin/system');
    }

    /** Sparar appens publika adress (används i artikel-QR). */
    public static function systemAdress(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        \TDL\Http\ArtikelQr::sparaBasUrl(post('bas_url'), $konto);
        flash('ok', 'Adressen sparades. Skriv ut nya artikel-QR om adressen ändrats.');
        omdirigera('/admin/system');
    }

    /** Byt QR-nyckel – gamla artikel-QR slutar fungera. */
    public static function systemQrNyckel(): void
    {
        $konto = Auth::kravRoll('admin');
        Sakerhet::kravCsrf();
        if (post('bekrafta') !== 'ja') {
            throw new AppFel('Bekräfta bytet genom att kryssa i rutan.');
        }
        \TDL\Http\ArtikelQr::bytNyckel($konto);
        flash('varning', 'Ny QR-nyckel skapad. Tidigare utskrivna artikel-QR fungerar inte längre – skriv ut nya.');
        omdirigera('/admin/system');
    }
}
