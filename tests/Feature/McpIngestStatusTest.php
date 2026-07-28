<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\IngestStatusService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 13 · S3 — `ingest.STATUS`, die Lese-Fläche des Katalog-Ingests.
 *
 * Drei Dinge zählen hier:
 *  1. **Lücke heißt „keine Aussage", nicht „keine Zeile"** — eine Allergen-Zeile mit
 *     lauter NULL ist keine Angabe und muss als Lücke durchschlagen (GL-01).
 *  2. **Das Delta ist die R2.1-Zahl** (aktuell gegen Vorgänger), kein zweiter Trend-Begriff.
 *  3. **D1** — Katalog-Sichtbarkeit läuft die Team-Kette AUFWÄRTS: ein Artikel des
 *     Kind-Teams darf im Status des Root-Teams nicht auftauchen (#504-Muster).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->svc = app(IngestStatusService::class);
    $this->preise = app(PriceService::class);

    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);

    $this->artikel = fn (string $nr, string $name, ?int $teamId = null) => FoodAlchemistSupplierItem::create([
        'team_id' => $teamId ?? $this->rootTeam->id,
        'supplier_id' => $this->supplier->id,
        'article_number' => $nr,
        'designation' => $name,
        'unit_code' => 'kg',
    ]);

    $this->lauf = function (string $typ, int $total, int $done, int $failed, ?int $teamId = null, ?array $context = null) {
        $run = FoodAlchemistBulkRun::starte($teamId ?? $this->rootTeam->id, BulkRunType::from($typ), $total, $context ?? []);
        $run->update(['status' => BulkRunStatus::Done, 'done' => $done, 'failed' => $failed]);

        return $run;
    };
});

it('S3: das Tool ist registriert und read-only (der Import bleibt artisan)', function () {
    $tool = $this->registry->get('foodalchemist.ingest.STATUS');

    expect($tool)->not->toBeNull()
        ->and($tool->getMetadata()['read_only'])->toBeTrue()
        ->and($tool->getSchema()['required'])->toBe([])
        ->and($tool->getDescription())->toContain('foodalchemist:import-articles');
});

it('S3: listet nur die Ingest-Läufe des eigenen Teams, neueste zuerst', function () {
    ($this->lauf)('ingest', 10, 9, 1);
    ($this->lauf)('enrich', 5, 5, 0);                              // andere Lauf-Art
    ($this->lauf)('ingest', 4, 4, 0, $this->childA->id);           // fremdes Team
    ($this->lauf)('ingest', 7, 7, 0);

    $res = $this->registry->get('foodalchemist.ingest.STATUS')->execute([], $this->kontext);
    $laeufe = $res->data['laeufe'];

    expect($res->success)->toBeTrue()
        ->and($laeufe)->toHaveCount(2)
        ->and($laeufe[0]['zeilen'])->toBe(7)                        // neueste zuerst
        ->and($laeufe[1]['fehler'])->toBe(1);
});

it('S3/H3a: benennt den Gegenstand des Laufs — und erfindet ihn nicht, wo er fehlt', function () {
    // V-047: bis 22·H3a konnte das Tool Läufe zählen und datieren, nicht benennen.
    ($this->lauf)('ingest', 3, 3, 0, null, null);                   // Alt-Lauf ohne Kontext
    ($this->lauf)('ingest', 12, 12, 0, null, [
        'datei' => 'hanos_q3.csv', 'supplier_id' => $this->supplier->id,
        'lieferant' => 'Hanos', 'apply' => true, 'quelle' => 'mcp',
    ]);

    $laeufe = $this->registry->get('foodalchemist.ingest.STATUS')->execute([], $this->kontext)->data['laeufe'];

    expect($laeufe[0]['datei'])->toBe('hanos_q3.csv')               // neuester zuerst
        ->and($laeufe[0]['lieferant'])->toBe('Hanos')
        ->and($laeufe[0]['lieferant_id'])->toBe((int) $this->supplier->id)
        ->and($laeufe[0]['ausgeloest_ueber'])->toBe('mcp')
        ->and($laeufe[0]['status_label'])->toBe('abgeschlossen')
        // Der Alt-Lauf bleibt ehrlich leer statt einen Dateinamen zu raten.
        ->and($laeufe[1]['datei'])->toBeNull()
        ->and($laeufe[1]['lieferant'])->toBeNull();
});

it('S3: zählt die vier Lücken-Arten und nennt Beispiele', function () {
    $nackt = ($this->artikel)('70012', 'Zanderfilet');
    $voll = ($this->artikel)('70013', 'Butter');

    $this->preise->createFor($this->rootTeam, $voll, 8.50);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $voll->id, 'gp_id' => $this->makeGp($this->rootTeam, 'Butter')->id,
    ]);
    FoodAlchemistItemAllergen::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $voll->id, 'allergen_milk' => 'enthalten',
    ]);
    FoodAlchemistItemNutritional::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $voll->id, 'energy_kcal' => 740,
    ]);

    $status = $this->svc->status($this->rootTeam);

    expect($status['artikel_sichtbar'])->toBe(2)
        ->and($status['luecken']['ohne_preis']['anzahl'])->toBe(1)
        ->and($status['luecken']['ohne_gp']['anzahl'])->toBe(1)
        ->and($status['luecken']['ohne_allergene']['anzahl'])->toBe(1)
        ->and($status['luecken']['ohne_naehrwerte']['anzahl'])->toBe(1)
        ->and($status['luecken']['ohne_preis']['beispiele'][0]['item_id'])->toBe($nackt->id)
        ->and($status['luecken']['ohne_preis']['beispiele'][0]['supplier'])->toBe('Hanos');
});

it('S3: eine Allergen-Zeile mit lauter NULL ist eine Lücke, keine Aussage', function () {
    $item = ($this->artikel)('70012', 'Zanderfilet');
    FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id]);
    FoodAlchemistItemNutritional::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id]);

    $status = $this->svc->status($this->rootTeam);

    expect($status['luecken']['ohne_allergene']['anzahl'])->toBe(1)
        ->and($status['luecken']['ohne_naehrwerte']['anzahl'])->toBe(1);
});

it('S3: ein blockierter Preis ist kein aktiver EK', function () {
    $item = ($this->artikel)('70012', 'Zanderfilet');
    $this->preise->createFor($this->rootTeam, $item, 12.0)->update(['is_blocked' => true]);

    expect($this->svc->status($this->rootTeam)['luecken']['ohne_preis']['anzahl'])->toBe(1);
});

it('S3: Preis-Deltas messen aktuell gegen Vorgänger — dieselbe Zahl wie der R2.1-Alarm', function () {
    $item = ($this->artikel)('70012', 'Zanderfilet');
    $this->preise->createFor($this->rootTeam, $item, 20.0);
    $this->preise->createFor($this->rootTeam, $item, 40.0);

    $deltas = $this->svc->status($this->rootTeam)['preis_deltas'];

    expect($deltas['bewegte_artikel'])->toBe(1)
        ->and($deltas['gestiegen'])->toBe(1)
        ->and($deltas['gefallen'])->toBe(0)
        ->and($deltas['abgeschnitten'])->toBeFalse()
        ->and($deltas['top'][0]['item_id'])->toBe($item->id)
        ->and($deltas['top'][0]['delta_pct'])->toBe(100.0)
        ->and($deltas['top'][0]['designation'])->toBe('Zanderfilet');
});

it('S3: Preis-Zeilen ausserhalb des Zeitfensters zählen nicht als Bewegung', function () {
    $item = ($this->artikel)('70012', 'Zanderfilet');
    $this->preise->createFor($this->rootTeam, $item, 20.0);
    $this->preise->createFor($this->rootTeam, $item, 40.0);
    DB::table('foodalchemist_prices')->update(['created_at' => now()->subDays(90)]);

    expect($this->svc->status($this->rootTeam, tage: 30)['preis_deltas']['bewegte_artikel'])->toBe(0);
});

it('S3 · D1: Artikel eines Kind-Teams tauchen im Status des Root-Teams nicht auf', function () {
    ($this->artikel)('90001', 'Kind-Artikel', $this->childA->id);

    $status = $this->svc->status($this->rootTeam);

    expect($status['artikel_sichtbar'])->toBe(0)
        ->and($status['luecken']['ohne_preis']['anzahl'])->toBe(0);
});

it('S3: ein nicht sichtbarer Lieferant ist ein Fehler, kein leeres Ergebnis', function () {
    $fremd = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Fremd-Lieferant']);

    $res = $this->registry->get('foodalchemist.ingest.STATUS')
        ->execute(['supplier_id' => $fremd->id], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('nicht sichtbar');
});
