<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\BulkRunStatusService;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Services\IngestStatusService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H3c — die Quittung nach außen (V-055) und der Lauf, der nie ankommt (V-073).
 *
 * Fünf Zusicherungen:
 *  1. **Ein Lauf über eine leere Arbeitsmenge ist sofort fertig** — er hängt sonst für
 *     immer auf `running`, weil `zaehleFortschritt` (der einzige Schreiber von `done`)
 *     ohne Item nie läuft; die H3b-Alterschwelle stuft ihn dann fälschlich als
 *     *abgebrochen* ein.
 *  2. **Die 0 des Konsolen-Imports ist ein Platzhalter, keine leere Menge** — dieselbe
 *     Zahl, zwei Bedeutungen; würde die Leer-Regel hier greifen, wäre der Import beim
 *     Anlegen „fertig" und sein Scheitern nicht mehr buchbar.
 *  3. **`offen` ist das Entscheidungs-Feld** — nur solange es `true` ist, lohnt Warten;
 *     ein verwaister Lauf steht in der Spalte weiter auf `running`, zählt hier aber nicht.
 *  4. **D1** — ein Lauf ist ein Vorgang des eigenen Teams, kein vererbbarer Katalog-Satz:
 *     der Lauf eines Kind-Teams taucht nicht auf, und gezielt abgefragt ist er NOT_FOUND.
 *  5. **Eine Projektion, zwei Türen** — `ingest.STATUS` baut auf derselben Stelle auf;
 *     der Test hält seine Sicht gegen die allgemeine, statt sie daneben nachzubauen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->svc = app(BulkRunStatusService::class);

    $this->tool = fn (array $args = []) => $this->registry->get('foodalchemist.runs.GET')->execute($args, $this->kontext);
});

// ───────────────────────── V-073 · die leere Arbeitsmenge ─────────────────────────

it('H3c: ein Lauf mit Arbeitsmenge bleibt laufen (Freeze — die Regel greift nur bei leer)', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 3);

    expect($run->status)->toBe(BulkRunStatus::Running)
        ->and($run->context)->toBeNull()
        ->and($this->svc->zeile($run->fresh())['offen'])->toBeTrue();
});

it('H3c · V-073: ein Lauf über eine leere Arbeitsmenge wird angelegt UND sofort abgeschlossen', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 0, ['schritte' => ['wording']]);

    $frisch = FoodAlchemistBulkRun::findOrFail($run->id);
    expect($frisch->status)->toBe(BulkRunStatus::Done)
        // Der Klick ist passiert — die Buchhaltung bleibt vollständig, der Gegenstand steht.
        ->and($frisch->context['schritte'])->toBe(['wording'])
        ->and($frisch->context['hinweis'])->toBe(FoodAlchemistBulkRun::HINWEIS_LEERE_MENGE);

    $zeile = $this->svc->zeile($frisch);
    expect($zeile['offen'])->toBeFalse()
        ->and($zeile['verwaist'])->toBeFalse()
        // „nichts zu tun" ist von „hängt" unterscheidbar — genau das war V-073.
        ->and($zeile['hinweis'])->toBe(FoodAlchemistBulkRun::HINWEIS_LEERE_MENGE)
        ->and($zeile['gegenstand'])->toBe(['schritte' => ['wording']]);
});

it('H3c · V-073: die Alterschwelle diagnostiziert einen leeren Lauf nicht mehr als abgebrochen', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::EnrichGp, 0);
    $run->forceFill(['updated_at' => now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN + 1)])->saveQuietly();

    expect(FoodAlchemistBulkRun::findOrFail($run->id)->istVerwaist())->toBeFalse();
});

it('H3c · V-073: die 0 des Konsolen-Imports ist ein Platzhalter und wird nicht als leere Menge gelesen', function () {
    $runId = app(FileArticleImportService::class)->starteRun(
        $this->rootTeam->id, 0, null, ['datei' => 'hanos_q3.csv', 'quelle' => 'konsole'], umfangSteht: false
    );

    $run = FoodAlchemistBulkRun::findOrFail($runId);
    expect($run->status)->toBe(BulkRunStatus::Running)
        ->and($run->context)->not->toHaveKey('hinweis')
        // Der Fehl-Pfad greift nur auf `running` — ein vorschnelles `done` hätte ihn taub gemacht.
        ->and(FoodAlchemistBulkRun::markiereGescheitert($runId, 'Datei nicht lesbar'))->toBeTrue();
});

// ───────────────────────── V-055 · die allgemeine Quittung ─────────────────────────

it('H3c: das Tool ist registriert, read-only und ohne Pflicht-Argument', function () {
    $tool = $this->registry->get('foodalchemist.runs.GET');

    expect($tool)->not->toBeNull()
        ->and($tool->getMetadata()['read_only'])->toBeTrue()
        ->and($tool->getSchema()['required'])->toBe([])
        // Das Vokabular kommt aus dem Enum, nicht aus einer Handliste (Lehre V-020).
        ->and($tool->getSchema()['properties']['typ']['enum'])
        ->toBe(array_map(fn (BulkRunType $t) => $t->value, BulkRunType::cases()))
        ->and($tool->getDescription())->toContain('offen');
});

it('H3c: listet die Läufe des eigenen Teams über ALLE Lauf-Arten, neueste zuerst (D1)', function () {
    $a = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 5);
    $b = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Ingest, 12);
    FoodAlchemistBulkRun::starte($this->childA->id, BulkRunType::Review, 4);     // fremdes Team

    $res = ($this->tool)([]);
    $ids = array_column($res->data['laeufe'], 'run_id');

    expect($res->success)->toBeTrue()
        ->and($res->data['anzahl'])->toBe(2)
        ->and($ids)->toBe([$b->id, $a->id])
        ->and(array_column($res->data['laeufe'], 'umfang'))->toBe([12, 5])
        ->and(array_column($res->data['laeufe'], 'typ'))->toBe(['ingest', 'enrich'])
        // Der Import-Lauf kostet kein Provider-Geld, der Autopilot schon.
        ->and($res->data['laeufe'][0]['ki_lauf'])->toBeFalse()
        ->and($res->data['laeufe'][1]['ki_lauf'])->toBeTrue();
});

it('H3c: filtert auf Lauf-Art und auf die noch offenen', function () {
    FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 5);
    $ingest = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Ingest, 12);
    FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Ingest, 0);   // leer ⇒ sofort done

    expect(array_column(($this->tool)(['typ' => 'ingest'])->data['laeufe'], 'run_id'))->toHaveCount(2)
        ->and(array_column(($this->tool)(['typ' => 'ingest', 'nur_offene' => true])->data['laeufe'], 'run_id'))
        ->toBe([$ingest->id]);

    $fehler = ($this->tool)(['typ' => 'ingests']);
    expect($fehler->success)->toBeFalse()->and($fehler->errorCode)->toBe('VALIDATION_ERROR');
});

it('H3c: eine run_id des fremden Teams ist NOT_FOUND, nicht ein leeres Ergebnis', function () {
    $eigen = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 2, [], $this->user->id);
    $fremd = FoodAlchemistBulkRun::starte($this->childA->id, BulkRunType::Enrich, 2);

    $ok = ($this->tool)(['run_id' => $eigen->id]);
    expect($ok->success)->toBeTrue()
        ->and($ok->data['anzahl'])->toBe(1)
        ->and($ok->data['laeufe'][0]['ausgeloest_von'])->toBe($this->user->id);

    $nein = ($this->tool)(['run_id' => $fremd->id]);
    expect($nein->success)->toBeFalse()->and($nein->errorCode)->toBe('NOT_FOUND');
});

it('H3c: ein verwaister Lauf steht auf running, ist aber nicht offen', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 9);
    $run->forceFill(['updated_at' => now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN + 1)])->saveQuietly();

    $z = ($this->tool)(['run_id' => $run->id])->data['laeufe'][0];

    expect($z['status'])->toBe('running')      // die Spalte bleibt — kein Reaper ohne Beweis
        ->and($z['verwaist'])->toBeTrue()
        ->and($z['offen'])->toBeFalse()        // … der Client wartet trotzdem nicht weiter
        ->and($z['zustand'])->toBe('abgebrochen (keine Rückmeldung)');
});

it('H3c: ein gescheiterter Lauf nennt den Grund, und der Gegenstand bleibt der Gegenstand', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Ingest, 10, ['datei' => 'hanos_q3.csv']);
    FoodAlchemistBulkRun::markiereGescheitert($run->id, new RuntimeException('Datei nicht lesbar'));

    $z = ($this->tool)(['run_id' => $run->id])->data['laeufe'][0];

    expect($z['status'])->toBe('failed')
        ->and($z['offen'])->toBeFalse()
        ->and($z['fehler_grund'])->toBe('Datei nicht lesbar')
        ->and($z['fehler_klasse'])->toBe(RuntimeException::class)
        // Ausgang und Gegenstand sind zwei Aussagen — der Ausgang wird nicht doppelt geliefert.
        ->and($z['gegenstand'])->toBe(['datei' => 'hanos_q3.csv'])
        ->and($z['beendet'])->not->toBeNull();
});

it('H3c: ingest.STATUS ist dieselbe Projektion, nur mit Import-Vokabular (eine Wahrheit, zwei Türen)', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Ingest, 42, [
        'datei' => 'hanos_q3.csv', 'lieferant' => 'Hanos', 'supplier_id' => 7, 'quelle' => 'mcp',
    ]);
    $run->forceFill(['updated_at' => now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN + 1)])->saveQuietly();

    $allgemein = $this->svc->laeufe($this->rootTeam, $run->id)[0];
    $fachlich = app(IngestStatusService::class)->status($this->rootTeam)['laeufe'][0];

    expect($fachlich['run_id'])->toBe($allgemein['run_id'])
        ->and($fachlich['status'])->toBe($allgemein['status'])
        ->and($fachlich['status_label'])->toBe($allgemein['zustand'])
        ->and($fachlich['verwaist'])->toBe($allgemein['verwaist'])
        // Derselbe Wert, in der Sprache der jeweiligen Fläche benannt — und zwar der
        // Umfang, nicht der Fortschritt. Die Gleichheit allein wäre blind: sie hielte
        // auch, wenn BEIDE Türen auf dieselbe falsche Spalte zeigten (dieselbe
        // „Fixture bestätigt ihre Annahme"-Falle wie V-019/V-020) — darum die Zahl.
        ->and($allgemein['umfang'])->toBe(42)
        ->and($fachlich['zeilen'])->toBe($allgemein['umfang'])
        ->and($fachlich['datei'])->toBe('hanos_q3.csv')
        ->and($fachlich['lieferant_id'])->toBe(7)
        ->and($fachlich['ausgeloest_ueber'])->toBe('mcp');
});
