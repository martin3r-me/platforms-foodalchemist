<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController;
use Platform\FoodAlchemist\Jobs\ProduktpassLesenJob;
use Platform\FoodAlchemist\Livewire\Preislisten\LieferantenDokumente;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLinkEreignis as Ereignis;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\LieferantenDokumentService;
use Platform\FoodAlchemist\Services\LieferantenLinkService;
use Platform\FoodAlchemist\Support\UploadScanner;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\LieferantenDokumentEntscheidenTool;
use Platform\FoodAlchemist\Tools\LieferantenDokumentGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Lieferanten-Link, Stufe 2c (Kurator 30.10.2026): Dokumente je Artikel über den Link, Prüflisten-Zeile, Übernehmen = Produktpass-Weg. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    config(['foodalchemist.master_team_id' => $this->team->id]);
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->svc = app(LieferantenLinkService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    $this->mehl = FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'article_number' => 'A-1', 'designation' => 'Mehl 25 kg']);
    $this->oel = FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->chefs->id, 'article_number' => 'C-1', 'designation' => 'Öl']);
    $this->link = $this->svc->erzeugen($this->team, $this->admin, $this->hanos);
    $this->pdf = fn (string $name = 'datenblatt.pdf', string $zusatz = '') => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n{$zusatz}%%EOF\n");
    $this->png = fn (string $name = 'foto.png') => UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $this->hoch = fn (UploadedFile $datei, string $nr = 'A-1', ?string $token = null) => app(LieferantenPortalController::class)->dokument(
        $token ?? $this->link['token'], Request::create('/l/x/dokument', 'POST', ['artikelnummer' => $nr], [], ['datei' => $datei], ['REMOTE_ADDR' => '203.0.113.9']),
        $this->svc, app(LieferantenDokumentService::class));
});

it('Upload: Datei liegt privat (nicht öffentlich), Prüflisten-Zeile mit Artikel, KEIN Produktpass und keine KI vor der Übernahme', function () {
    $r = ($this->hoch)(($this->pdf)());

    $lauf = Lauf::first();
    $z = Zeile::first();
    expect($r->getSession()->get('portal_meldung'))->toContain('angekommen')
        ->and($lauf->art)->toBe(Lauf::ART_DOKUMENTE)->and($lauf->herkunft)->toBe(Lauf::HERKUNFT_LIEFERANTEN_LINK)->and($lauf->link_id)->toBe($this->link['link']->id)
        ->and($z->status)->toBe(Zeile::STATUS_TREFFER)->and($z->daten['supplier_item_id'])->toBe($this->mehl->id)
        ->and(Storage::disk('local')->exists($z->daten['pfad']))->toBeTrue()->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(FoodAlchemistProduktpass::count())->toBe(0);
    Queue::assertNotPushed(ProduktpassLesenJob::class);
    expect(Ereignis::where('art', 'upload')->count())->toBe(1);
});

it('Dateiprüfung: nur PDF/JPG/PNG, Endung + Kopf + Mime müssen passen, keine aktiven PDF-Inhalte, Größe, Scanner', function () {
    $svc = app(LieferantenDokumentService::class);
    $fall = [
        'exe' => [UploadedFile::fake()->createWithContent('a.exe', 'MZ'), 'Dateiformat'],
        'webp' => [UploadedFile::fake()->createWithContent('a.webp', 'RIFF'), 'Dateiformat'],
        'php als pdf' => [UploadedFile::fake()->createWithContent('a.pdf', '<?php system($_GET[1]);'), 'passt nicht'],
        'pdf als png' => [($this->pdf)('a.png'), 'passt nicht'],
        'png ohne Bild' => [UploadedFile::fake()->createWithContent('a.png', "\x89PNG\r\n\x1A\nkaputt"), 'passt nicht'],
        'js im pdf' => [($this->pdf)('a.pdf', "2 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\n"), 'aktiven Inhalten'],
        'einbettung' => [($this->pdf)('a.pdf', "3 0 obj << /Type /EmbeddedFile >> endobj\n"), 'aktiven Inhalten'],
        'leer' => [UploadedFile::fake()->createWithContent('a.pdf', ''), 'leer'],
    ];
    foreach ($fall as $name => [$datei, $text]) {
        expect(fn () => $svc->pruefeDatei($datei))->toThrow(\RuntimeException::class, $text);
    }
    expect(fn () => $svc->pruefeDatei(UploadedFile::fake()->createWithContent('gross.pdf', "%PDF-1.4\n" . str_repeat('x', 16 * 1024 * 1024))))->toThrow(\RuntimeException::class, 'größer');
    expect($svc->pruefeDatei(($this->pdf)()))->toBe(['pdf', 'application/pdf'])->and($svc->pruefeDatei(($this->png)()))->toBe(['png', 'image/png']);

    $this->app->bind(UploadScanner::class, fn () => new class implements UploadScanner {
        public function pruefe(string $pfad, string $endung): ?string
        {
            return 'Befund';
        }
    });
    $r = ($this->hoch)(($this->pdf)());
    expect($r->getSession()->get('portal_fehler'))->toContain('Sicherheitsprüfung')->and(Lauf::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('Fence: Artikelnummer eines ANDEREN Lieferanten ergibt keine Zuordnung, Übernehmen scheitert auch bei manipulierter Zeile', function () {
    $r = ($this->hoch)(($this->pdf)(), 'C-1');
    $z = Zeile::first();
    expect($r->getSession()->get('portal_meldung'))->toContain('keinen Artikel')->and($z->status)->toBe(Zeile::STATUS_OHNE_TREFFER)->and($z->daten['supplier_item_id'])->toBeNull();

    // manipuliert: Zeile zeigt auf Artikel eines anderen Lieferanten
    $z->forceFill(['status' => Zeile::STATUS_TREFFER, 'daten' => array_merge($z->daten, ['supplier_item_id' => $this->oel->id])])->save();
    $b = app(LieferantenDokumentService::class)->entscheide($this->team, $this->admin, [$z->id], 'uebernehmen');
    expect($b['uebernommen'])->toBe(0)->and($b['fehler'][0])->toContain('keinen Artikel dieses Lieferanten')->and(FoodAlchemistProduktpass::count())->toBe(0);
});

it('Übernehmen geht über den Produktpass-Weg: Version angelegt, KI-Lesen eingereiht, Staging-Datei weg, Zeile+Lauf abgeschlossen, Protokoll', function () {
    ($this->hoch)(($this->pdf)());
    $z = Zeile::first();
    $pfad = $z->daten['pfad'];

    $b = app(LieferantenDokumentService::class)->entscheide($this->team, $this->admin, [$z->id], 'uebernehmen');

    expect($b)->toMatchArray(['uebernommen' => 1, 'verworfen' => 0, 'fehler' => []])
        ->and(FoodAlchemistProduktpass::where('supplier_item_id', $this->mehl->id)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($pfad))->toBeFalse()
        ->and($z->fresh()->status)->toBe(Zeile::STATUS_ANGEWENDET)->and($z->fresh()->entscheidung)->toBe(Zeile::ENTSCHEIDUNG_UEBERNEHMEN)
        ->and(Lauf::first()->status)->toBe('applied')
        ->and(Ereignis::where('art', 'dokument_uebernommen')->count())->toBe(1)
        ->and(app(LieferantenDokumentService::class)->offene($this->team))->toHaveCount(0);
    Queue::assertPushed(ProduktpassLesenJob::class);
});

it('Verwerfen löscht die Datei, es entsteht kein Produktpass', function () {
    ($this->hoch)(($this->png)());
    $z = Zeile::first();

    $b = app(LieferantenDokumentService::class)->entscheide($this->team, $this->admin, [$z->id], 'verwerfen');

    expect($b['verworfen'])->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([])->and(FoodAlchemistProduktpass::count())->toBe(0)
        ->and($z->fresh()->entscheidung)->toBe(Zeile::ENTSCHEIDUNG_VERWERFEN)->and(Lauf::first()->status)->toBe('verworfen')
        ->and(Ereignis::where('art', 'dokument_verworfen')->count())->toBe(1);
});

it('Rechte: Betrachter darf nicht entscheiden; ein fremdes Team sieht nichts', function () {
    ($this->hoch)(($this->pdf)());
    $leser = $this->makeUser($this->team, 'Leser', 'viewer');
    expect(fn () => app(LieferantenDokumentService::class)->entscheide($this->team, $leser, [Zeile::first()->id], 'uebernehmen'))->toThrow(FaRechtFehltException::class);

    $fremd = \Platform\Core\Models\Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    expect(app(LieferantenDokumentService::class)->offene($fremd))->toHaveCount(0);
    $fremderNutzer = $this->makeUser($fremd, 'Fremd', 'owner');
    $b = app(LieferantenDokumentService::class)->entscheide($fremd, $fremderNutzer, [Zeile::first()->id], 'uebernehmen');
    expect($b['uebernommen'])->toBe(0)->and(FoodAlchemistProduktpass::count())->toBe(0);
});

it('Bereich „dokumente“ nicht freigegeben ⇒ abgelehnt + protokolliert; widerrufener Link ⇒ 410', function () {
    $nur = $this->svc->erzeugen($this->team, $this->admin, $this->hanos, ['bereiche' => ['preisliste']]);
    $r = ($this->hoch)(($this->pdf)(), 'A-1', $nur['token']);
    expect($r->getSession()->get('portal_fehler'))->toContain('nicht freigegeben')->and(Lauf::count())->toBe(0)
        ->and(Ereignis::where('link_id', $nur['link']->id)->where('art', 'abgelehnt')->count())->toBe(1);

    $this->svc->widerrufen($this->team, $this->admin, $this->link['link']);
    expect(($this->hoch)(($this->pdf)())->getStatusCode())->toBe(410);
});

it('Badge „vom Lieferanten“: im Dokumente-Panel und in den Lauf-Listen der Preislisten', function () {
    ($this->hoch)(($this->pdf)());
    Livewire::test(LieferantenDokumente::class)->assertSeeHtml('data-lieferanten-dokument')->assertSeeHtml('data-lauf-vom-lieferanten')->assertSee('Übernehmen')
        ->call('entscheiden', Zeile::first()->id, 'uebernehmen')->assertSee('Dokument übernommen');

    Lauf::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'filename' => 'liste.csv', 'status' => 'staged', 'art' => 'preisliste',
        'herkunft' => Lauf::HERKUNFT_LIEFERANTEN_LINK, 'link_id' => $this->link['link']->id, 'counts' => ['zeilen' => 1]]);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Preislisten\Index::class)->assertSeeHtml('data-lauf-vom-lieferanten');
});

it('MCP: GET listet offene Dokumente, ENTSCHEIDEN übernimmt; Betrachter bekommt FORBIDDEN', function () {
    ($this->hoch)(($this->pdf)());
    $ctx = new ToolContext($this->admin, $this->team);
    $liste = (new LieferantenDokumentGetTool)->execute([], $ctx);
    expect($liste->success)->toBeTrue()->and($liste->data['dokumente'])->toHaveCount(1)->and($liste->data['dokumente'][0]['zuordenbar'])->toBeTrue();

    $leser = $this->makeUser($this->team, 'Leser2', 'viewer');
    expect((new LieferantenDokumentGetTool)->execute([], new ToolContext($leser, $this->team))->success)->toBeFalse();
    expect((new LieferantenDokumentEntscheidenTool)->execute(['zeilen_ids' => [Zeile::first()->id], 'entscheidung' => 'uebernehmen'], new ToolContext($leser, $this->team))->success)->toBeFalse();

    $r = (new LieferantenDokumentEntscheidenTool)->execute(['zeilen_ids' => [Zeile::first()->id], 'entscheidung' => 'uebernehmen'], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['uebernommen'])->toBe(1);
});
