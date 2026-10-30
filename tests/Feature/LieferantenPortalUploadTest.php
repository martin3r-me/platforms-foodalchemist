<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLinkEreignis as Ereignis;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\LieferantenLinkService;
use Platform\FoodAlchemist\Services\LieferantenUploadBenachrichtigung;
use Platform\FoodAlchemist\Services\LieferantenUploadService;
use Platform\FoodAlchemist\Support\UploadScanner;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\Notifications\NotificationDispatcher;
use Platform\Notifications\NotificationTypeRegistry;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Lieferanten-Link, Stufe 2b (Kurator 29.10.2026): Portal-Upload, Dateiprüfung, Prüfliste, Benachrichtigung. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->svc = app(LieferantenLinkService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'article_number' => 'A-1', 'designation' => 'Mehl 25 kg']);
    $this->link = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    Cache::flush();
    $this->csv = fn (string $inhalt, string $name = 'preise.csv') => UploadedFile::fake()->createWithContent($name, $inhalt);
    $this->hoch = fn (string $bereich, UploadedFile $datei, ?string $token = null) => app(LieferantenPortalController::class)->upload(
        $token ?? $this->link['token'],
        Request::create('/l/x/upload', 'POST', ['bereich' => $bereich], [], ['datei' => $datei], ['REMOTE_ADDR' => '203.0.113.9']),
        $this->svc, app(LieferantenUploadService::class));
});

it('Preisliste: Upload landet als Lauf mit Herkunft „lieferanten_link“ in der Prüfliste, der Bestand bleibt unberührt', function () {
    $antwort = ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;12,50\n"));

    $lauf = Lauf::first();
    expect($antwort->getSession()->get('portal_meldung'))->toContain('angekommen')
        ->and($lauf->herkunft)->toBe(Lauf::HERKUNFT_LIEFERANTEN_LINK)->and($lauf->link_id)->toBe($this->link['link']->id)->and($lauf->supplier_id)->toBe($this->hanos->id)
        ->and(Zeile::where('run_id', $lauf->id)->count())->toBe(1)
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistPrice::query()->count())->toBe(0)   // nichts im Bestand
        ->and(Ereignis::where('link_id', $this->link['link']->id)->where('art', 'upload')->count())->toBe(1)
        ->and($this->link['link']->fresh()->upload_count)->toBe(1);
});

it('Stammdaten: Upload landet als Lauf der Art stammdaten, Herkunft und Link gesetzt', function () {
    ($this->hoch)('stammdaten', ($this->csv)("Artikel-Nr;Bezeichnung;Preis netto\nA-1;Mehl 25 kg;12,50\nB-9;Neuer Artikel;3,00\n", 'stamm.csv'));

    $lauf = Lauf::first();
    expect($lauf->art)->toBe(Lauf::ART_STAMMDATEN)->and($lauf->herkunft)->toBe(Lauf::HERKUNFT_LIEFERANTEN_LINK)->and($lauf->link_id)->toBe($this->link['link']->id);
});

it('Bereich nicht freigegeben ⇒ abgelehnt und protokolliert, kein Lauf', function () {
    $nur = $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['bereiche' => ['dokumente']]);
    $r = ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;1\n"), $nur['token']);

    expect($r->getSession()->get('portal_fehler'))->toContain('nicht freigegeben')->and(Lauf::count())->toBe(0)
        ->and(Ereignis::where('link_id', $nur['link']->id)->where('art', 'abgelehnt')->count())->toBe(1);
});

it('Dateiprüfung: Makro-Formate, falsche Endung, leere, binäre und zu große Dateien werden abgelehnt', function () {
    $svc = app(LieferantenUploadService::class);
    $fall = [
        'xlsm' => [($this->csv)('x', 'a.xlsm'), 'Makros'],
        'exe' => [($this->csv)('MZ', 'a.exe'), 'Dateiformat'],
        'php' => [($this->csv)('<?php echo 1;', 'a.php'), 'Dateiformat'],
        'leer' => [($this->csv)('', 'a.csv'), 'leer'],
        'binaer' => [($this->csv)("a;b\n\0\1\2", 'a.csv'), 'CSV-Tabelle'],
        'pdf als csv' => [($this->csv)("%PDF-1.4 ...", 'a.csv'), 'CSV-Tabelle'],
        'html als csv' => [($this->csv)('<html><script>alert(1)</script>', 'a.csv'), 'CSV-Tabelle'],
        'xlsx ohne zip' => [($this->csv)('kein zip', 'a.xlsx'), 'keine gültige'],
    ];
    foreach ($fall as $name => [$datei, $text]) {
        expect(fn () => $svc->pruefeDatei($datei))->toThrow(\RuntimeException::class, $text);
    }
    $gross = UploadedFile::fake()->createWithContent('gross.csv', str_repeat("a;b;c\n", 11 * 1024 * 1024 / 6));
    expect(fn () => $svc->pruefeDatei($gross))->toThrow(\RuntimeException::class, 'größer');
    expect($svc->pruefeDatei(($this->csv)("Artikelnummer;Preis netto\nA-1;1\n")))->toBe('csv');
});

it('Excel mit Makro-Teil oder externer Verknüpfung wird abgelehnt, saubere xlsx-Struktur angenommen', function () {
    $zip = function (array $teile) {
        $pfad = tempnam(sys_get_temp_dir(), 'fa-x') . '.xlsx';
        $z = new \ZipArchive;
        $z->open($pfad, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($teile as $n => $inhalt) {
            $z->addFromString($n, $inhalt);
        }
        $z->close();

        return new UploadedFile($pfad, 'liste.xlsx', null, null, true);
    };
    $svc = app(LieferantenUploadService::class);
    expect(fn () => $svc->pruefeDatei($zip(['[Content_Types].xml' => '<x/>', 'xl/vbaProject.bin' => 'x'])))->toThrow(\RuntimeException::class, 'Makros');
    expect(fn () => $svc->pruefeDatei($zip(['[Content_Types].xml' => '<x/>', 'xl/externalLinks/externalLink1.xml' => 'x'])))->toThrow(\RuntimeException::class, 'Makros');
    expect($svc->pruefeDatei($zip(['[Content_Types].xml' => '<x/>', 'xl/workbook.xml' => '<w/>'])))->toBe('xlsx');
});

it('Scanner ist austauschbar: lehnt er ab, kommt kein Lauf zustande', function () {
    $this->app->bind(UploadScanner::class, fn () => new class implements UploadScanner {
        public function pruefe(string $pfad, string $endung): ?string
        {
            return 'Befund';
        }
    });
    $r = app(LieferantenPortalController::class)->upload($this->link['token'], Request::create('/', 'POST', ['bereich' => 'preisliste'], [], ['datei' => ($this->csv)("Artikelnummer;Preis netto\nA-1;1\n")]),
        $this->svc, app(LieferantenUploadService::class));

    expect($r->getSession()->get('portal_fehler'))->toContain('Sicherheitsprüfung')->and(Lauf::count())->toBe(0);
});

it('Formeln werden neutralisiert, echte Zahlen bleiben', function () {
    expect(LieferantenUploadService::entschaerfe('=HYPERLINK("http://x")'))->toBe("'=HYPERLINK(\"http://x\")")
        ->and(LieferantenUploadService::entschaerfe('@SUM(A1)'))->toStartWith("'@")
        ->and(LieferantenUploadService::entschaerfe('+cmd|calc'))->toStartWith("'+")
        ->and(LieferantenUploadService::entschaerfe('-5,50'))->toBe('-5,50')
        ->and(LieferantenUploadService::entschaerfe('Mehl'))->toBe('Mehl');

    ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Bezeichnung;Preis netto\nA-1;=1+1;12,50\n"));
    expect(Zeile::first()->designation)->toBe("'=1+1");
});

it('Zeilen eines anderen Lieferanten werden verworfen (der Link gilt nur für den eigenen Lieferanten)', function () {
    FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->chefs->id, 'article_number' => 'C-1', 'designation' => 'Öl']);
    ($this->hoch)('preisliste', ($this->csv)("Lieferant;Artikelnummer;Preis netto\nHanos;A-1;12,50\nChefs Culinar;C-1;9,00\n"));

    $lauf = Lauf::first();
    $fremd = Zeile::where('run_id', $lauf->id)->where('supplier_id', $this->chefs->id)->first();
    expect($fremd->status)->toBe(Zeile::STATUS_FEHLER)->and($fremd->reason)->toContain('nicht zu deinem Lieferanten')
        ->and(Zeile::where('run_id', $lauf->id)->where('supplier_id', $this->hanos->id)->value('status'))->toBe(Zeile::STATUS_TREFFER)
        ->and($lauf->counts['fremder_lieferant'])->toBe(1);
});

it('Eigene Datei ohne Vorlage: Lauf „Zuordnung nötig“ mit Herkunft und Link; nach dem Zuordnen bleibt beides erhalten', function () {
    $r = ($this->hoch)('preisliste', ($this->csv)("Art;Betrag\nA-1;12,50\n"));
    $lauf = Lauf::first();
    expect($r->getSession()->get('portal_meldung'))->toContain('Spalten')->and($lauf->status)->toBe(Lauf::STATUS_ZUORDNUNG_NOETIG)->and($lauf->herkunft)->toBe(Lauf::HERKUNFT_LIEFERANTEN_LINK);

    $neu = app(\Platform\FoodAlchemist\Services\PreislistenImportService::class)->zuordnen($this->team, $lauf, ['article_number' => 'Art', 'price' => 'Betrag'], $this->admin->id);
    expect($neu->herkunft)->toBe(Lauf::HERKUNFT_LIEFERANTEN_LINK)->and($neu->link_id)->toBe($this->link['link']->id);
});

it('Widerrufener oder abgelaufener Link nimmt nichts an (410), es entsteht kein Lauf', function () {
    $this->svc->widerrufen($this->team, $this->admin, $this->link['link']);
    $r = ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;1\n"));

    expect($r->getStatusCode())->toBe(410)->and(Lauf::count())->toBe(0);
});

it('Route: Rate-Limit je Token + IP ist an der Upload-Route, Name gehört in den Bereich „frei“', function () {
    $route = app('router')->getRoutes()->getByName('foodalchemist.presentation.lieferanten_portal_upload');
    expect($route)->not->toBeNull()->and($route->methods())->toContain('POST')->and($route->gatherMiddleware())->toContain('throttle:fa-lieferanten-upload');

    $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('fa-lieferanten-upload');
    $r = Request::create('/l/' . $this->link['token'] . '/upload', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
    $r->setRouteResolver(fn () => (new \Illuminate\Routing\Route('POST', '/l/{token}/upload', []))->bind($r));
    $limit = $limiter($r);
    expect($limit->maxAttempts)->toBe(10)->and($limit->key)->toContain('203.0.113.9');
});

// ---- Benachrichtigung ----
/** Fängt Dispatches ab, statt zu senden. */
function fangeDispatcher(): object
{
    $fang = new class extends NotificationDispatcher {
        public array $aufrufe = [];

        public bool $wirf = false;

        public function dispatch(string $notificationType, array $data, \Illuminate\Support\Collection|array $recipients): void
        {
            if ($this->wirf) {
                throw new \RuntimeException('Versand kaputt');
            }
            $this->aufrufe[] = ['typ' => $notificationType, 'daten' => $data, 'user_ids' => collect($recipients)->pluck('id')->all()];
        }
    };
    app()->instance(NotificationDispatcher::class, $fang);

    return $fang;
}

it('Typ foodalchemist.lieferant.upload ist in der Core-Registry angemeldet', function () {
    expect(NotificationTypeRegistry::get(LieferantenUploadBenachrichtigung::TYP))->toMatchArray(['group' => 'foodalchemist'])->and(LieferantenUploadBenachrichtigung::TYP)->toBe('foodalchemist.lieferant.upload');
});

it('Benachrichtigung: geht an Kuratieren-Mitglieder des Link-Teams, nicht an Betrachter und nicht an ein fremdes Team', function () {
    $fang = fangeDispatcher();
    $kurator = $this->makeUser($this->team, 'Kuratorin', 'member');
    $betrachter = $this->makeUser($this->team, 'Gast', 'viewer');
    $fremdTeam = \Platform\Core\Models\Team::create(['name' => 'Fremdes Team', 'user_id' => 1, 'personal_team' => false]);
    $fremd = $this->makeUser($fremdTeam, 'Fremd', 'owner');

    ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;12,50\n"));

    expect($fang->aufrufe)->toHaveCount(1)->and($fang->aufrufe[0]['typ'])->toBe('foodalchemist.lieferant.upload')
        ->and($fang->aufrufe[0]['user_ids'])->toContain($this->admin->id)->toContain($kurator->id)
        ->not->toContain($betrachter->id)->not->toContain($fremd->id)
        ->and($fang->aufrufe[0]['daten']['team_id'])->toBe($this->team->id)->and($fang->aufrufe[0]['daten']['title'])->toContain('Hanos');
});

it('Benachrichtigung ist gebündelt: im Zeitfenster höchstens ein Hinweis je Lieferant, das Protokoll hat jeden Upload', function () {
    $fang = fangeDispatcher();
    foreach ([1, 2, 3] as $i) {
        ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;{$i}\n"));
    }
    expect($fang->aufrufe)->toHaveCount(1)->and(Ereignis::where('art', 'upload')->count())->toBe(3);

    // anderer Lieferant ⇒ eigener Hinweis
    $andere = $this->svc->erzeugen($this->team, $this->admin, $this->chefs);
    ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nC-1;1\n"), $andere['token']);
    expect($fang->aufrufe)->toHaveCount(2);
});

it('Rückfall: scheitert der Versand, wird der Fehler geloggt und protokolliert, der Upload gelingt trotzdem; danach darf es erneut versucht werden', function () {
    $fang = fangeDispatcher();
    $fang->wirf = true;
    Log::spy();

    $r = ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;12,50\n"));

    expect($r->getSession()->get('portal_meldung'))->toContain('angekommen')->and(Lauf::count())->toBe(1)
        ->and(Ereignis::where('art', 'upload')->count())->toBe(1)
        ->and(Ereignis::where('art', 'benachrichtigung_fehlgeschlagen')->count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Benachrichtigung fehlgeschlagen'))->once();

    $fang->wirf = false;
    ($this->hoch)('preisliste', ($this->csv)("Artikelnummer;Preis netto\nA-1;13\n"));
    expect($fang->aufrufe)->toHaveCount(1);
});
