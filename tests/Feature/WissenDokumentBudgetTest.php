<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    config()->set('foodalchemist.ai.knowledge_budget', ['test.dossierbudget' => 10000]);
    $this->contents = [];
    foreach (['a', 'b', 'c'] as $key) {
        $slug = "linsen-{$key}";
        // Trenner und Überschriften innerhalb einer Quelle sind keine Dossiergrenzen.
        $content = "Anfang {$key}\n\n---\n\n## DATEN: innen\n\n| Größe | Menge |\n| --- | --- |\n| Portion | 80 g |\nEnde {$key}";
        $this->contents[$slug] = $content;
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => 'budgetprobe', 'content_md' => $content, 'version' => 1,
            'content_hash' => hash('sha256', $content), 'char_count' => mb_strlen($content),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'test.dossierbudget', 'category' => 'budgetprobe',
        'mode' => 'discovery', 'max_docs' => 3, 'max_chars_per_doc' => 4000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('B2: übermittelt zwei vollständige Dossiers und protokolliert das dritte samt Trenner', function () {
    $service = app(KnowledgeContextService::class);
    $full = $service->contextFor(null, 'test.dossierbudget', 'linsen');
    $last = $full['files_used'][2];
    $lastSlug = explode('@', $last)[0];
    $lastText = "\n\n---\n\n## BUDGETPROBE: {$lastSlug}\n\n".$this->contents[$lastSlug];
    $budget = $full['total_chars'] - mb_strlen($lastText);
    $context = $service->contextFor(null, 'test.dossierbudget', 'linsen', null, [], ['_max_chars' => $budget]);

    expect($context['block'])->toBe(mb_substr($full['block'], 0, $budget))
        ->and($context['files_used'])->toBe(array_slice($full['files_used'], 0, 2))
        ->and($context['files_dropped'])->toBe([$last])
        ->and($context['used_by_category']['budgetprobe'])->toBe($context['files_used'])
        ->and($context['herkunft'][$lastSlug]['sent'])->toBe(0)
        ->and($context['built_chars'])->toBe($full['total_chars'])
        ->and($context['dropped_chars'])->toBe(mb_strlen($lastText))
        ->and($context['total_chars'])->toBe($budget);
    foreach ($context['files_used'] as $file) {
        expect($context['block'])->toContain($this->contents[explode('@', $file)[0]]);
    }
    // Dieselbe Service-Instanz muss im nächsten Aufruf wieder alles liefern.
    expect($service->contextFor(null, 'test.dossierbudget', 'linsen'))->toBe($full);
});

it('B2: liefert bei zu kleinem Budget weder Fragment noch verwaiste Kanalüberschrift', function () {
    $context = app(KnowledgeContextService::class)->contextFor(null, 'test.dossierbudget', 'linsen', null, [], ['_max_chars' => 1]);
    expect($context['block'])->toBe('')
        ->and($context['files_used'])->toBe([])
        ->and($context['used_by_category'])->toBe([])
        ->and($context['files_dropped'])->toHaveCount(3)
        ->and($context['total_chars'])->toBe(0)
        ->and($context['dropped_chars'])->toBe($context['built_chars']);
});

it('B2: lässt nach einem zu großen Dossier eine kleinere vollständige Quelle zu', function () {
    $large = str_repeat('Großes Dossier. ', 100);
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'linsen-a')->update(['content_md' => $large]);
    $context = app(KnowledgeContextService::class)->contextFor(null, 'test.dossierbudget', 'linsen', null, [], ['_max_chars' => 400]);
    expect($context['files_used'])->not->toContain('linsen-a@v1')
        ->toContain('linsen-b@v1', 'linsen-c@v1')
        ->and($context['block'])->toContain($this->contents['linsen-b'], $this->contents['linsen-c'])
        ->not->toContain('Großes Dossier')
        ->and($context['total_chars'])->toBeLessThanOrEqual(400);
});

it('reserviert spätere Pflichtquellen bevor früheres Suchwissen das Budget belegt', function () {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'pflicht-tabelle', 'title' => 'Pflicht',
        'category' => 'budgetpflicht', 'content_md' => str_repeat('Pflicht. ', 30), 'version' => 1,
        'content_hash' => hash('sha256', 'pflicht'), 'char_count' => 270, 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'test.dossierbudget', 'category' => 'budgetpflicht',
        'mode' => 'always', 'max_docs' => 1, 'max_chars_per_doc' => 4000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config()->set('foodalchemist.ai.knowledge_budget', ['test.dossierbudget' => 400]);
    $context = app(KnowledgeContextService::class)->contextFor(null, 'test.dossierbudget', 'linsen');
    expect($context['files_used'])->toBe(['pflicht-tabelle@v1'])
        ->and($context['block'])->toContain(str_repeat('Pflicht. ', 30))
        ->and($context['files_dropped'])->toHaveCount(3)
        ->and($context['required_chars'])->toBe($context['total_chars'])
        ->and($context['total_chars'])->toBeLessThanOrEqual(400);
});

it('stoppt bei zu großer Pflicht statt eine halbe Tabelle oder einen stillen Überlauf zu liefern', function () {
    DB::table('foodalchemist_knowledge_routings')->where('feature', 'test.dossierbudget')->update(['mode' => 'always']);
    config()->set('foodalchemist.ai.knowledge_budget', ['test.dossierbudget' => 10]);
    $service = app(KnowledgeContextService::class);
    expect(fn () => $service->contextFor(null, 'test.dossierbudget', 'linsen'))
        ->toThrow(\Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded::class, 'Wissensbudget für «test.dossierbudget» zu klein');
    $measurement = $service->pflichtBudgetFuer(null, 'test.dossierbudget');
    expect($measurement['ok'])->toBeFalse()
        ->and($measurement['required_chars'])->toBeGreaterThan(10)
        ->and($measurement['budget'])->toBe(10);
});

it('zählt große optionale Kandidatenmengen nicht als Pflichtverletzung', function () {
    config()->set('foodalchemist.ai.knowledge_budget', ['test.dossierbudget' => 10]);
    expect(app(KnowledgeContextService::class)->pflichtBudgetFuer(null, 'test.dossierbudget'))
        ->toBe(['required_chars' => 0, 'budget' => 10, 'ok' => true]);
});
