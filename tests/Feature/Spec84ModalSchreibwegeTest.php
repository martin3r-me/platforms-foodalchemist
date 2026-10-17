<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 C5 (§5.6) · Die direkten Writes in Rezept-, GP- und VK-Modal laufen über Services: RecipeService::setzeFeld /
 * setzeDichteklasse / uebernehmeUeberarbeitung, SalesRecipeService::uebernehmeUeberarbeitung, GpService::setzeZustand /
 * setzeTags / setzeKalkDefaults. Der Eigentümer-Riegel sitzt im Service. Browser und MCP (recipes.REVISE,
 * verkaufsrezepte.REVISE) nehmen denselben Weg. Reine Strukturänderung: gleiche Werte, gleiche Lineage, gleiche Meldungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->eigen = $this->makeRecipe($this->rootTeam, 'Eigener Fond', ['description' => 'Original']);
    $this->kat = $this->makeRecipeCategory($this->rootTeam, 'C5-KAT');
    $this->svc = app(RecipeService::class);
});

// ── RecipeService::setzeFeld ──────────────────────────────────────────────────────────────────────────────────────────

it('Spec 84 C5 · setzeFeld Beschreibung: ki / manual / Reset mit Lineage, Override-First, Fremder abgewiesen', function () {
    $r = $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'description', 'ki', 'KI-Text', 0.8);
    expect([$r->description, $r->description_source, (float) $r->description_ai_confidence])->toBe(['KI-Text', 'ki', 0.8]);

    $r = $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'description', 'manual', 'Von Hand');
    expect([$r->description, $r->description_source, $r->description_ai_confidence])->toBe(['Von Hand', 'manual', null])
        ->and(fn () => $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'description', 'ki', 'Neu', 0.9))
        ->toThrow(\RuntimeException::class, 'manuell gepflegt');

    expect(fn () => $this->svc->setzeFeld($this->childA, $this->eigen->id, 'description', null))->toThrow(GeerbtNurLesenException::class)
        ->and($this->eigen->fresh()->description)->toBe('Von Hand');

    $r = $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'description', null);
    expect([$r->description, $r->description_source])->toBe([null, null]);
});

it('Spec 84 C5 · setzeFeld Zubereitung: nur Lineage, der Text (Spiegel der Schritte) bleibt', function () {
    $vorher = $this->eigen->fresh()->preparation;
    $r = $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'preparation', 'manual');
    expect([$r->preparation_source, $r->preparation])->toBe(['manual', $vorher]);
    $r = $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'preparation', null);
    expect([$r->preparation_source, $r->preparation])->toBe([null, $vorher]);
});

it('Spec 84 C5 · setzeFeld Kategorie: Referenz muss sichtbar sein, KI mit Begründung', function () {
    $r = $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'category', 'ki', $this->kat->id, 0.7, 'passt');
    expect([(int) $r->category_id, $r->category_source, $r->category_ai_reasoning])->toBe([$this->kat->id, 'ki', 'passt']);

    $fremdeKat = $this->makeRecipeCategory($this->childB, 'FREMD-KAT');
    expect(fn () => $this->svc->setzeFeld($this->rootTeam, $this->eigen->id, 'category', 'manual', $fremdeKat->id))
        ->toThrow(\RuntimeException::class, 'nicht sichtbar');
});

it('Spec 84 C5 · Rezept-Modal: Beschreibung/Kategorie/Zubereitung laufen über den Service — Besitzer ja, Kind-Team nein', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root'));
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->eigen->id)
        ->set('kiVorschlag.description', ['werte' => ['description' => 'KI-Beschreibung'], 'confidence' => 0.6, 'reasoning' => null])
        ->call('accept_beschreibung')
        ->assertSet('fehler', null)
        ->assertSet('form.description', 'KI-Beschreibung')
        ->call('manual_zubereitung');
    expect($this->eigen->fresh()->only(['description', 'description_source', 'preparation_source']))
        ->toBe(['description' => 'KI-Beschreibung', 'description_source' => 'ki', 'preparation_source' => 'manual']);

    $this->actingAs($this->makeUser($this->childA, 'Kind'));
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->eigen->id)
        ->call('clear_beschreibung')
        ->assertSet('fehler', 'Geerbt: das Rezept kann nur das Besitzer-Team ändern.');
    expect($this->eigen->fresh()->description)->toBe('KI-Beschreibung');
});

it('Spec 84 C5 · Überarbeitung: Modal und Service schreiben dasselbe Ergebnis (gleicher Weg wie recipes.REVISE)', function () {
    $zwilling = $this->makeRecipe($this->rootTeam, 'Zwilling Fond', ['description' => 'Original']);
    $werte = ['description' => 'Überarbeitet'];

    $this->actingAs($this->makeUser($this->rootTeam, 'Root'));
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->eigen->id)
        ->set('ueberarbeitung', ['werte' => $werte, 'confidence' => 0.75, 'match_vorschau' => []])
        ->call('ueberarbeitungUebernehmen')
        ->assertSet('fehler', null);
    $teile = $this->svc->uebernehmeUeberarbeitung($this->rootTeam, $zwilling->id, $werte, 0.75);

    $felder = ['description', 'description_source', 'description_ai_confidence'];
    expect($teile)->toBe(['description'])
        ->and($this->eigen->fresh()->only($felder))->toBe($zwilling->fresh()->only($felder));
    $quelle = file_get_contents((new ReflectionClass(\Platform\FoodAlchemist\Tools\RecipesReviseTool::class))->getFileName());
    expect($quelle)->toContain('->uebernehmeUeberarbeitung(')->not->toContain("'description_source' => 'ki'");   // Tool ohne eigenen Write
});

it('Spec 84 C5 · Dichteklasse über den Service: Besitzer ja (manual), Fremder abgewiesen', function () {
    expect($this->svc->setzeDichteklasse($this->rootTeam, $this->eigen->id, 'fest')->only(['dichteklasse', 'dichteklasse_source']))
        ->toBe(['dichteklasse' => 'fest', 'dichteklasse_source' => 'manual'])
        ->and(fn () => $this->svc->setzeDichteklasse($this->childA, $this->eigen->id, null))->toThrow(GeerbtNurLesenException::class)
        ->and($this->eigen->fresh()->dichteklasse)->toBe('fest');
});

// ── VK ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('Spec 84 C5 · VK-Überarbeitung über SalesRecipeService: Texte mit Lineage ki, manuell bleibt, Fremder abgewiesen; Modal = Service', function () {
    $g1 = $this->makeRecipe($this->rootTeam, '[HG] Gericht | Eins', ['is_sales_recipe' => true, 'plating_text' => 'Hand', 'plating_source' => 'manual']);
    $g2 = $this->makeRecipe($this->rootTeam, '[HG] Gericht | Zwei', ['is_sales_recipe' => true, 'plating_text' => 'Hand', 'plating_source' => 'manual']);
    $werte = ['description' => 'KI-VK-Text', 'plating_text' => 'KI-Anrichten'];

    $teile = app(SalesRecipeService::class)->uebernehmeUeberarbeitung($this->rootTeam, $g1->id, $werte, 0.5);
    expect($teile)->toBe(['description'])
        ->and($g1->fresh()->only(['description', 'description_source', 'plating_text']))->toBe(['description' => 'KI-VK-Text', 'description_source' => 'ki', 'plating_text' => 'Hand'])
        ->and(fn () => app(SalesRecipeService::class)->uebernehmeUeberarbeitung($this->childA, $g1->id, $werte, 0.5))->toThrow(GeerbtNurLesenException::class);

    $this->actingAs($this->makeUser($this->rootTeam, 'Root'));
    Livewire::test(VkModal::class)
        ->call('oeffnen', $g2->id)
        ->set('ueberarbeitung', ['werte' => $werte, 'confidence' => 0.5, 'match_vorschau' => []])
        ->call('ueberarbeitungUebernehmen')
        ->assertSet('fehler', null);
    $felder = ['description', 'description_source', 'description_ai_confidence', 'plating_text', 'plating_source'];
    expect($g2->fresh()->only($felder))->toBe($g1->fresh()->only($felder));
});

// ── GP ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('Spec 84 C5 · GpService Zustand/Tags/Kalk-Defaults: Besitzer ja, Override-First, Fremder abgewiesen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet C5');
    $gs = app(GpService::class);

    expect($gs->setzeZustand($this->rootTeam, $gp->id, 'ki', 'frisch', 0.9, 'Name')->only(['condition', 'condition_source']))
        ->toBe(['condition' => 'frisch', 'condition_source' => 'ki'])
        ->and($gs->setzeZustand($this->rootTeam, $gp->id, 'manual', 'TK')->condition_source)->toBe('manual')
        ->and(fn () => $gs->setzeZustand($this->rootTeam, $gp->id, 'ki', 'frisch'))->toThrow(\RuntimeException::class, 'manuell gepflegt')
        ->and(fn () => $gs->setzeZustand($this->childA, $gp->id, null))->toThrow(GeerbtNurLesenException::class, 'Geerbtes Katalog-GP');

    $tag = FoodAlchemistGp::TAG_FIELDS[0];
    expect($gs->setzeTags($this->rootTeam, $gp->id, 'ki', [$tag => true], 0.6))->toBeTrue()
        ->and($gp->fresh()->{"tag_{$tag}"})->toBeTrue()
        ->and($gp->fresh()->tag_source)->toBe('ki')
        ->and($gs->setzeTags($this->rootTeam, $gp->id, 'manual', [$tag => true]))->toBeFalse()   // keine Änderung → kein Write
        ->and(fn () => $gs->setzeTags($this->childA, $gp->id, null))->toThrow(GeerbtNurLesenException::class);

    $k = $gs->setzeKalkDefaults($this->rootTeam, $gp->id, ['cooking_loss_default_pct' => '12,5', 'piece_default_g' => '']);
    expect((float) $k->cooking_loss_default_pct)->toBe(12.5)->and($k->piece_default_g)->toBeNull()
        ->and(fn () => $gs->setzeKalkDefaults($this->childA, $gp->id, []))->toThrow(GeerbtNurLesenException::class);
});

it('Spec 84 C5 · GP-Modal: Zustand über den Service — Besitzer ja, Kind-Team mit der bisherigen Meldung abgewiesen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet Modal');
    $this->actingAs($this->makeUser($this->rootTeam, 'Root'));
    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('kiVorschlag.condition', ['werte' => ['condition' => 'frisch'], 'confidence' => 0.8, 'reasoning' => 'x'])
        ->call('accept_zustand')
        ->assertSet('fehler', null)
        ->assertSet('builder.condition', 'frisch');
    expect($gp->fresh()->condition_source)->toBe('ki');

    $this->actingAs($this->makeUser($this->childA, 'Kind'));
    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('clear_zustand')
        ->assertSet('fehler', 'Geerbtes Katalog-GP — Pflege nur durchs Besitzer-Team (D1).');
    expect($gp->fresh()->condition)->toBe('frisch');
});

it('Spec 84 C5 · Wächter: die drei Modale schreiben nicht mehr direkt', function () {
    foreach ([RecipeModal::class, GpModal::class, VkModal::class] as $klasse) {
        $code = file_get_contents((new ReflectionClass($klasse))->getFileName());
        expect(preg_match_all('/->update\(\[|->fill\(|DB::table\(\'foodalchemist_(recipes|gps)\'\)/', $code))->toBe(0, $klasse);
    }
});
