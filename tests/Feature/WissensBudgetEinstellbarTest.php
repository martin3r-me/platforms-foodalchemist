<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Das Wissensbudget wird einstellbar.
 *
 * Die Wissens-Steuerung zeigte je Arbeitsschritt „Σ Pflicht / Budget" — und wer sah, dass ein
 * Schritt an seiner Grenze steht, konnte nichts tun ausser einen Deploy bestellen. Das Budget
 * ist der Hebel fuer Kosten gegen Qualitaet; er gehoert dem Betreiber.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.knowledge_budget.PUT');
    KnowledgeBudget::vergiss();
});

afterEach(fn () => KnowledgeBudget::vergiss());

it('★ ohne Eintrag gilt die Config unveraendert — die Tabelle aendert fuer sich nichts', function () {
    config(['foodalchemist.ai.knowledge_budget' => ['recipe.generator' => 48000, 'default' => 16200]]);

    expect(KnowledgeBudget::forKey('recipe.generator'))->toBe(48000)
        ->and(KnowledgeBudget::forKey('irgendwas.anderes'))->toBe(16200)
        ->and(KnowledgeBudget::eingestellt())->toBe([]);
});

it('der eingestellte Wert gewinnt gegen die Config', function () {
    config(['foodalchemist.ai.knowledge_budget' => ['recipe.generator' => 48000]]);

    KnowledgeBudget::setze('recipe.generator', 52000);

    expect(KnowledgeBudget::forKey('recipe.generator'))->toBe(52000)
        ->and(KnowledgeBudget::standardFuer('recipe.generator'))->toBe(48000);   // Standard bleibt lesbar
});

it('★ zuruecksetzen ist ein eigener Zustand, nicht die Zahl 0', function () {
    config(['foodalchemist.ai.knowledge_budget' => ['recipe.generator' => 48000]]);
    KnowledgeBudget::setze('recipe.generator', 52000);

    KnowledgeBudget::setze('recipe.generator', null);

    expect(KnowledgeBudget::forKey('recipe.generator'))->toBe(48000)
        ->and(DB::table('foodalchemist_knowledge_budgets')->count())->toBe(0);
});

it('der Alt-Schluessel ai_generate_recipe zeigt auf denselben Eintrag wie recipe.generator', function () {
    // Sonst haette derselbe Call zwei Budgets, je nachdem wer fragt — genau der
    // Schluesselraum-Bruch, den Spec 52 abgebaut hat.
    KnowledgeBudget::setze('ai_generate_recipe', 51000);

    expect(KnowledgeBudget::forKey('recipe.generator'))->toBe(51000)
        ->and(DB::table('foodalchemist_knowledge_budgets')->value('prompt_key'))->toBe('recipe.generator');
});

it('weist 0 und negative Werte ab', function () {
    expect(fn () => KnowledgeBudget::setze('recipe.generator', 0))->toThrow(RuntimeException::class);
});

it('MCP: setzt ein Budget und listet die Abweichungen', function () {
    $res = $this->tool->execute(['prompt_key' => 'recipe.review', 'max_chars' => 22000], $this->kontext);
    expect($res->success)->toBeTrue()->and($res->data['quelle'])->toBe('eingestellt');

    $liste = $this->tool->execute([], $this->kontext);
    expect($liste->data['eingestellt'])->toBe(['recipe.review' => 22000]);
});

it('★ MCP warnt, wenn das Budget unter die Pflichtmenge faellt — statt den Schritt still stillzulegen', function () {
    // Pflichtwissen wird NIE gekappt: passt es nicht, bricht der Aufbau zur Laufzeit ab. Ohne
    // diese Vorwarnung waere das eine Einstellung, die den Schritt beim naechsten Aufruf toetet,
    // und niemand braechte es mit dieser Zahl in Verbindung.
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    $doc = DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null, 'slug' => 'regel-gross',
        'title' => 'Grosse Regel', 'category' => 'regelwerk', 'content_md' => str_repeat('x', 9000),
        'version' => 1, 'content_hash' => hash('sha256', 'g'), 'char_count' => 9000,
        'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_canon')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 10,
        'knowledge_document_id' => $doc, 'mode' => 'pflicht', 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->tool->execute(['prompt_key' => 'test.key', 'max_chars' => 500], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('Pflichtmenge')
        ->and(KnowledgeBudget::eingestellt())->toBe([]);      // nichts geschrieben

    // ...aber bewusst ueberstimmbar — der Betreiber darf entscheiden.
    $trotzdem = $this->tool->execute(['prompt_key' => 'test.key', 'max_chars' => 500, 'trotzdem' => true], $this->kontext);
    expect($trotzdem->success)->toBeTrue();
});

it('★ ein Fremd-Team darf das Budget nicht aendern — es gilt fuer ALLE Mandanten', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $fremd = $this->makeUser($this->childA, 'Kind');

    $res = $this->tool->execute(['prompt_key' => 'recipe.review', 'max_chars' => 99000],
        new ToolContext($fremd, $this->childA));

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('FORBIDDEN');
});
