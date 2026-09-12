<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Services\Knowledge\SteuerungSicherungService;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Die dritte Sicherung. Drei Dinge entscheiden, welches Wissen ein Schritt bekommt: Kanon
 * („was muss"), Routings („was darf gesucht werden") und Budget („wie viel passt"). Die
 * ersten beiden waren gesichert, die anderen nicht — am 2026-09-12 lagen 41 frisch angelegte
 * Arten-Routings und zwei Budget-Werte ausschliesslich in der Datenbank.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dienst = app(SteuerungSicherungService::class);
    $this->datei = sys_get_temp_dir().'/steuerung-test-'.uniqid().'.json';
    KnowledgeBudget::vergiss();
});

afterEach(function () {
    @unlink($this->datei);
    KnowledgeBudget::vergiss();
});

it('★ der Rueckweg traegt: Routing und Budget loeschen, Datei einspielen, Stand ist zurueck', function () {
    // Der Daseinszweck. Ein Export, der sich nicht einspielen laesst, ist kein Backup.
    app(KnowledgeRoutingService::class)->setArt('recipe.generator', 'fachwissen', 'discovery', 9, null);
    KnowledgeBudget::setze('recipe.generator', 52000);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt()));

    DB::table('foodalchemist_knowledge_routings')->where('feature', 'recipe.generator')->whereNotNull('art')->delete();
    DB::table('foodalchemist_knowledge_budgets')->delete();
    KnowledgeBudget::vergiss();

    $e = $this->dienst->spielEin($this->dienst->lade($this->datei), apply: true);

    expect($e['fehler'])->toBe([]);
    $zeile = DB::table('foodalchemist_knowledge_routings')->where('feature', 'recipe.generator')->where('art', 'fachwissen')->first();
    expect($zeile)->not->toBeNull()
        ->and((int) $zeile->max_docs)->toBe(9)
        ->and(KnowledgeBudget::forKey('recipe.generator'))->toBe(52000);
});

it('★ die Vorschau schreibt nichts', function () {
    app(KnowledgeRoutingService::class)->setArt('vk.generator', 'referenz', 'discovery', 3, null);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt()));
    DB::table('foodalchemist_knowledge_routings')->where('feature', 'vk.generator')->whereNotNull('art')->delete();

    $e = $this->dienst->spielEin($this->dienst->lade($this->datei), apply: false);

    expect($e['routings'])->toBeGreaterThan(0)
        ->and(DB::table('foodalchemist_knowledge_routings')->where('feature', 'vk.generator')->whereNotNull('art')->count())->toBe(0);
});

it('★ sichert nur ABWEICHENDE Budgets — sonst friert die Datei eine Config ein, die sich aendern darf', function () {
    config(['foodalchemist.ai.knowledge_budget' => ['recipe.review' => 18000]]);

    expect($this->dienst->budgets())->toBe([]);

    KnowledgeBudget::setze('recipe.review', 22000);
    expect($this->dienst->budgets())->toBe([['prompt_key' => 'recipe.review', 'max_chars' => 22000]]);
});

it('★ eine alte Datei nimmt nichts zurueck, was seitdem dazukam', function () {
    // Dieselbe Regel wie bei den Aliasen: eine Sicherung darf keine Entscheidung loeschen,
    // von der sie nichts weiss.
    file_put_contents($this->datei, json_encode($this->dienst->inhalt()));
    app(KnowledgeRoutingService::class)->setArt('recipe.steps', 'fachwissen', 'discovery', 4, null);

    $this->dienst->spielEin($this->dienst->lade($this->datei), apply: true);

    expect(DB::table('foodalchemist_knowledge_routings')->where('feature', 'recipe.steps')->where('art', 'fachwissen')->count())->toBe(1);
});

it('trennt „nur live" von „nur in der Datei"', function () {
    file_put_contents($this->datei, json_encode($this->dienst->inhalt()));
    app(KnowledgeRoutingService::class)->setArt('recipe.review', 'fachwissen', 'discovery', 2, null);

    $ab = $this->dienst->abgleich($this->dienst->lade($this->datei));

    // Der Schluessel ist feature|art|category — eine Arten-Zeile hat die Kategorie leer.
    expect($ab['nur_live'])->toContain('recipe.review|fachwissen|')
        ->and($ab['nur_datei'])->toBe([]);
});

it('weist eine Zeile mit art UND category ab — genau eines ist Pflicht', function () {
    file_put_contents($this->datei, json_encode(['routings' => [
        ['feature' => 'x', 'art' => 'fachwissen', 'category' => 'domain', 'mode' => 'discovery'],
    ]]));

    expect(fn () => $this->dienst->lade($this->datei))->toThrow(InvalidArgumentException::class, 'genau eines');
});

it('weist einen unbekannten Modus ab, statt ihn halb einzuspielen', function () {
    file_put_contents($this->datei, json_encode(['routings' => [
        ['feature' => 'x', 'category' => 'domain', 'mode' => 'quatsch'],
    ]]));

    expect(fn () => $this->dienst->lade($this->datei))->toThrow(InvalidArgumentException::class, 'quatsch');
});

it('das Kommando schreibt die Datei und zaehlt die Arten-Routings', function () {
    app(KnowledgeRoutingService::class)->setArt('recipe.generator', 'fachwissen', 'discovery', 5, null);

    $this->artisan('foodalchemist:wissen-steuerung-sicherung', ['richtung' => 'export', '--datei' => $this->datei])
        ->expectsOutputToContain('nach Art')->assertSuccessful();

    $inhalt = json_decode((string) file_get_contents($this->datei), true);
    expect(collect($inhalt['routings'])->firstWhere('art', 'fachwissen'))->not->toBeNull();
});
