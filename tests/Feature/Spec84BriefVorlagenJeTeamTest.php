<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 L1-B (Dominique 16.10.: Variante B) · Migration 2026_11_09_100000: die 6 Vorlagen ohne Team werden je Hauptteam
 * (parent NULL, nicht persönlich) kopiert, danach gelöscht. In der Test-DB lief die Migration vor den Teams (no-op), deshalb
 * ruft der Test sie nach dem Anlegen der Teams ausdrücklich noch einmal auf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();                                       // rootTeam (Hauptteam) + childA/childB (Unter-Teams)
    $this->mandant = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    $this->persoenlich = Team::create(['name' => 'Persönlich', 'user_id' => 1, 'personal_team' => true]);
    $this->migration = require \dirname(__DIR__, 2).'/database/migrations/2026_11_09_100000_brief_vorlagen_je_hauptteam.php';
    $this->slugs = fn (Team $t) => FoodAlchemistBriefTemplate::where('team_id', $t->id)->orderBy('slug')->pluck('slug')->all();
});

it('kopiert die 6 Vorlagen je Hauptteam und löscht die Zeilen ohne Team', function () {
    expect(FoodAlchemistBriefTemplate::whereNull('team_id')->count())->toBe(6);

    $this->migration->up();

    expect(FoodAlchemistBriefTemplate::withTrashed()->whereNull('team_id')->count())->toBe(0)
        ->and(($this->slugs)($this->rootTeam))->toHaveCount(6)
        ->and(($this->slugs)($this->mandant))->toHaveCount(6)
        ->and(($this->slugs)($this->childA))->toBe([])                 // Unter-Team: keine eigene Kopie
        ->and(($this->slugs)($this->persoenlich))->toBe([]);           // persönliches Team: keine Kopie
    $kopie = FoodAlchemistBriefTemplate::where('team_id', $this->mandant->id)->where('slug', 'catering_galadinner')->firstOrFail();
    expect($kopie->scope)->toBe('gericht')
        ->and($kopie->payload['regler']['occasion'])->toBe('dinner');
});

it('Mandant sieht und löscht nur die eigene Kopie; das Unter-Team liest die des Oberteams', function () {
    $this->migration->up();
    $svc = app(BriefTemplateService::class);
    $eigene = FoodAlchemistBriefTemplate::where('team_id', $this->mandant->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
    $fremde = FoodAlchemistBriefTemplate::where('team_id', $this->rootTeam->id)->firstOrFail();

    expect(array_map('intval', array_keys($svc->fuer($this->mandant, 'gericht'))))->toEqualCanonicalizing($eigene);
    expect(fn () => $svc->loeschen($this->mandant, (int) $fremde->id))->toThrow(RuntimeException::class);
    $svc->loeschen($this->mandant, $eigene[0]);
    expect(FoodAlchemistBriefTemplate::find($eigene[0]))->toBeNull()
        ->and(FoodAlchemistBriefTemplate::find($fremde->id))->not->toBeNull();

    expect($svc->fuer($this->childA, 'gericht'))->toHaveCount(6)->toHaveKey((string) $fremde->id);
});

it('eine offene Planung lädt die Kopie des eigenen Teams (keine Verweise auf die alten IDs)', function () {
    $this->migration->up();
    $kopie = FoodAlchemistBriefTemplate::where('team_id', $this->rootTeam->id)->where('slug', 'catering_empfang_flying')->firstOrFail();
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(PlanungIndex::class)
        ->call('briefVorlage', 'gericht', (string) $kopie->id)
        ->assertSet('eingabe.gericht.brief', $kopie->brief)
        ->assertSet('aktiveVorlage.gericht', (string) $kopie->id);
});

it('zweiter Lauf ändert nichts; ein Team mit vorhandenem Slug wird übersprungen', function () {
    // Mandant hat einen Slug schon (selbst angelegt) → keine Dublette, Unique team_id+slug hält
    FoodAlchemistBriefTemplate::create(['team_id' => $this->mandant->id, 'slug' => 'care_mittag', 'label' => 'Eigene Care-Vorlage',
        'scope' => 'gericht', 'brief' => 'Eigener Text.', 'payload' => [], 'sort_order' => 1, 'active' => true]);

    $this->migration->up();
    expect(($this->slugs)($this->mandant))->toHaveCount(6)
        ->and(FoodAlchemistBriefTemplate::where('team_id', $this->mandant->id)->where('slug', 'care_mittag')->value('label'))->toBe('Eigene Care-Vorlage');

    $vorher = FoodAlchemistBriefTemplate::withTrashed()->orderBy('id')->get(['id', 'team_id', 'slug', 'label'])->toArray();
    $this->migration->up();
    expect(FoodAlchemistBriefTemplate::withTrashed()->orderBy('id')->get(['id', 'team_id', 'slug', 'label'])->toArray())->toBe($vorher);
});
