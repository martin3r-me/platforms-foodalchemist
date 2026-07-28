<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\ImportArticlesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\ArticleImportTriggerService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 13 · S3b — `ingest.IMPORT`, die Auslösung des Kanal-B-Imports.
 *
 * Der Teilschritt ist zur Hälfte eine Sicherheits-Frage, darum liegt das Gewicht der
 * Tests dort:
 *  1. **Nur der Ablage-Ordner.** Pfad-Anteile, `..`, absolute Pfade und fremde Endungen
 *     werden abgelehnt — ein Tool mit freiem Datei-Parameter wäre ein Lese-Zugriff auf
 *     das Server-Dateisystem.
 *  2. **Trockenlauf ist Default.** Ohne `apply` entsteht kein Artikel und keine Lauf-Zeile.
 *  3. **Scharf läuft als Job** und hinterlässt die Quittung, die `ingest.STATUS` liest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = $this->registry->get('foodalchemist.ingest.IMPORT');
    $this->trigger = app(ArticleImportTriggerService::class);

    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);

    // Datei im echten Ablage-Ordner ablegen — genau das ist die Vorbedingung des Tools.
    $this->ablegen = function (string $name, array $zeilen) {
        $pfad = $this->trigger->ordner() . '/' . $name;
        file_put_contents($pfad, implode("\n", $zeilen) . "\n");

        return $pfad;
    };
});

afterEach(function () {
    foreach (glob(app(ArticleImportTriggerService::class)->ordner() . '/fa_s3b_*') ?: [] as $pfad) {
        @unlink($pfad);
    }
});

it('S3b: das Tool ist registriert, schreibend und nennt den Ablage-Ordner', function () {
    expect($this->tool)->not->toBeNull()
        ->and($this->tool->getMetadata()['read_only'])->toBeFalse()
        ->and($this->tool->getMetadata()['confirmation_required'])->toBeTrue()
        ->and($this->tool->getSchema()['required'])->toBe([])
        ->and($this->tool->getSchema()['properties']['apply']['default'])->toBeFalse()
        ->and($this->tool->getDescription())->toContain('storage/app/foodalchemist/import');
});

it('S3b: ohne Dateinamen listet das Tool die bereitliegenden Dateien', function () {
    ($this->ablegen)('fa_s3b_liste.csv', ['Artikel-Nr;Bezeichnung', '1;Butter']);

    $res = $this->tool->execute([], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['ordner'])->toBe('foodalchemist/import')
        ->and(array_column($res->data['dateien'], 'datei'))->toContain('fa_s3b_liste.csv');

    $eintrag = collect($res->data['dateien'])->firstWhere('datei', 'fa_s3b_liste.csv');
    expect($eintrag['zeilen_geschaetzt'])->toBe(1);   // Kopfzeile zählt nicht mit
});

it('S3b: Pfad-Anteile, Verzeichnis-Wechsel und fremde Endungen werden abgelehnt', function () {
    foreach (['../.env', '/etc/passwd', 'unter/ordner.csv', '.env', 'liste.xlsx', 'liste.php'] as $eingabe) {
        $res = $this->tool->execute(['datei' => $eingabe, 'supplier_id' => $this->supplier->id], $this->kontext);

        expect($res->success)->toBeFalse()
            ->and($res->errorCode)->toBe('VALIDATION_ERROR');
    }
});

it('S3b: eine Datei außerhalb des Ablage-Ordners wird nicht gelesen — die Meldung nennt den Ordner', function () {
    $fremd = sys_get_temp_dir() . '/fa_s3b_fremd.csv';
    file_put_contents($fremd, "Artikel-Nr;Bezeichnung\n1;Butter\n");

    $res = $this->tool->execute(['datei' => 'fa_s3b_fremd.csv', 'supplier_id' => $this->supplier->id], $this->kontext);
    @unlink($fremd);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('foodalchemist/import');
});

it('S3b: ein Dateiname ohne supplier_id ist ein Parameter-Fehler (die Datei sagt nicht, wem sie gehört)', function () {
    ($this->ablegen)('fa_s3b_ohne.csv', ['Artikel-Nr;Bezeichnung', '1;Butter']);

    $res = $this->tool->execute(['datei' => 'fa_s3b_ohne.csv'], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('supplier_id');
});

it('S3b: ein Lieferant außerhalb der Team-Kette ist NOT_FOUND, nicht ein leerer Lauf (D1)', function () {
    ($this->ablegen)('fa_s3b_d1.csv', ['Artikel-Nr;Bezeichnung', '1;Butter']);
    $fremd = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Kind-Lieferant']);

    $res = $this->tool->execute(['datei' => 'fa_s3b_d1.csv', 'supplier_id' => $fremd->id], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('NOT_FOUND');
});

it('S3b: der Trockenlauf ist Default — er berichtet, schreibt aber nichts (kein Artikel, keine Lauf-Zeile)', function () {
    ($this->ablegen)('fa_s3b_dry.csv', [
        'Artikel-Nr;Bezeichnung;Gebindemenge;Einheit;Preis',
        '70012;Zanderfilet mit Haut;2,5;Kilogramm;37,99',
    ]);

    $res = $this->tool->execute(['datei' => 'fa_s3b_dry.csv', 'supplier_id' => $this->supplier->id], $this->kontext);

    expect($res->success)->toBeTrue();
    $d = $res->data;
    expect($d['modus'])->toBe('trockenlauf')
        ->and($d['geschrieben'])->toBeFalse()
        ->and($d['zeilen'])->toBe(1)
        ->and($d['bilanz']['neu'])->toBe(1)
        ->and($d['preise']['neu'])->toBe(1)
        ->and($d['befunde'])->toHaveCount(1)
        ->and($d['naechster_schritt'])->toContain('apply=true')
        ->and(FoodAlchemistSupplierItem::where('article_number', '70012')->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_bulk_runs')->where('type', 'ingest')->count())->toBe(0);
});

it('S3b: unveränderte Zeilen ohne Bewegung erscheinen nicht in der Vorschau (dasselbe Prädikat wie die Konsole)', function () {
    FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Butter', 'unit_code' => 'kg',
    ]);
    ($this->ablegen)('fa_s3b_still.csv', ['Artikel-Nr;Bezeichnung', '70012;Butter']);

    $res = $this->tool->execute(['datei' => 'fa_s3b_still.csv', 'supplier_id' => $this->supplier->id], $this->kontext);

    expect($res->data['bilanz']['unveraendert'])->toBe(1)
        ->and($res->data['befunde'])->toBe([])
        ->and($res->data['befunde_gesamt'])->toBe(0);
});

it('S3b: apply=true reiht einen Job ein und liefert die run_id samt Quittung auf ingest.STATUS', function () {
    Bus::fake();
    ($this->ablegen)('fa_s3b_scharf.csv', [
        'Artikel-Nr;Bezeichnung;Preis',
        '70012;Zanderfilet;37,99',
    ]);

    $res = $this->tool->execute([
        'datei' => 'fa_s3b_scharf.csv', 'supplier_id' => $this->supplier->id, 'apply' => true,
    ], $this->kontext);

    expect($res->success)->toBeTrue();
    $d = $res->data;
    expect($d['modus'])->toBe('scharf')
        ->and($d['status'])->toBe('running')
        ->and($d['zeilen'])->toBe(1)
        ->and($d['run_id'])->toBeGreaterThan(0)
        ->and($d['quittung'])->toContain('ingest.STATUS');

    // Lauf-Zeile ist offen, trägt die Zeilenzahl und den Auslöser (Trigger = menschlich)
    $lauf = \Platform\FoodAlchemist\Models\FoodAlchemistBulkRun::findOrFail($d['run_id']);
    expect($lauf->type->value)->toBe('ingest')
        ->and($lauf->status->value)->toBe('running')
        ->and((int) $lauf->total)->toBe(1)
        ->and((int) $lauf->user_id)->toBe((int) $this->user->id)
        // 22·H3a / V-047: der Lauf nennt seinen Gegenstand, nicht nur seine Zähler.
        ->and($lauf->context['datei'])->toBe('fa_s3b_scharf.csv')
        ->and((int) $lauf->context['supplier_id'])->toBe((int) $this->supplier->id)
        ->and($lauf->context['quelle'])->toBe('mcp');

    // Nichts geschrieben, solange der Job nicht gelaufen ist — der Tool-Call importiert nicht selbst
    expect(FoodAlchemistSupplierItem::where('article_number', '70012')->exists())->toBeFalse();
    Bus::assertDispatched(ImportArticlesJob::class, fn ($job) => $job->runId === $d['run_id']
        && $job->teamId === $this->rootTeam->id
        && $job->supplierId === (int) $this->supplier->id);
});

it('S3b: der Job schreibt über dieselbe Strecke wie das Kommando und schließt den Lauf ab', function () {
    Bus::fake();
    ($this->ablegen)('fa_s3b_job.csv', [
        'Artikel-Nr;Bezeichnung;Gebindemenge;Einheit;Preis',
        '70013;Butter;10;Kilogramm;8,50',
    ]);

    $res = $this->tool->execute([
        'datei' => 'fa_s3b_job.csv', 'supplier_id' => $this->supplier->id, 'apply' => true,
    ], $this->kontext);
    $runId = $res->data['run_id'];

    // Job direkt fahren (die Queue selbst ist nicht Gegenstand des Tests)
    app()->call([new ImportArticlesJob(
        $runId, $this->rootTeam->id, (int) $this->supplier->id,
        $this->trigger->ordner() . '/fa_s3b_job.csv'
    ), 'handle']);

    $item = FoodAlchemistSupplierItem::where('article_number', '70013')->first();
    expect($item)->not->toBeNull()
        ->and((float) $item->prices()->first()->price)->toBe(8.5);

    $lauf = DB::table('foodalchemist_bulk_runs')->where('id', $runId)->first();
    expect($lauf->status)->toBe('done')
        ->and((int) $lauf->done)->toBe(1)
        ->and((int) $lauf->failed)->toBe(0);
});

it('S3b: stirbt der Job, steht der Lauf auf failed statt für immer auf running', function () {
    Bus::fake();
    ($this->ablegen)('fa_s3b_fail.csv', ['Artikel-Nr;Bezeichnung', '70014;Salz']);

    $runId = $this->tool->execute([
        'datei' => 'fa_s3b_fail.csv', 'supplier_id' => $this->supplier->id, 'apply' => true,
    ], $this->kontext)->data['run_id'];

    (new ImportArticlesJob($runId, $this->rootTeam->id, (int) $this->supplier->id, 'egal.csv'))
        ->failed(new RuntimeException('Testfall'));

    expect(DB::table('foodalchemist_bulk_runs')->where('id', $runId)->value('status'))->toBe('failed');
});

it('S3b: eine zu große Datei bekommt keine synchrone Vorschau, sondern den Weg genannt', function () {
    $zeilen = ['Artikel-Nr;Bezeichnung'];
    for ($i = 0; $i < ArticleImportTriggerService::MAX_VORSCHAU_ZEILEN + 5; $i++) {
        $zeilen[] = "A{$i};Artikel {$i}";
    }
    ($this->ablegen)('fa_s3b_gross.csv', $zeilen);

    $res = $this->tool->execute(['datei' => 'fa_s3b_gross.csv', 'supplier_id' => $this->supplier->id], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('foodalchemist:import-articles');
});

it('S3b: eine Datei ohne Datenzeile wird nicht scharf gestartet (kein Lauf über nichts)', function () {
    ($this->ablegen)('fa_s3b_leer.csv', ['Artikel-Nr;Bezeichnung']);

    $res = $this->tool->execute([
        'datei' => 'fa_s3b_leer.csv', 'supplier_id' => $this->supplier->id, 'apply' => true,
    ], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and(DB::table('foodalchemist_bulk_runs')->where('type', 'ingest')->count())->toBe(0);
});
