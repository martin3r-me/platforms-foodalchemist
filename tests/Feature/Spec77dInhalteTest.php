<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\StandortInhalte;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 77d · Inhalte für Standorte. Standard: Unter-Team übernimmt alles. Haken aus: sieht nur Freigegebenes
 * (Sammlungen, Foodbook, Speiseplan, Speisekarte) samt Hülle Format → Konzept → Gericht → Basisrezept;
 * Stammdaten (GPs) bleiben sichtbar; Freigegebenes ist lesend, anpassen = eigene Kopie.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->inhalte = app(InhaltsFreigabeService::class);
    $this->reg = app(ToolRegistry::class);
    $r = $this->rootTeam;
    $this->basis = $this->makeRecipe($r, 'Brauner Fond', ['is_sales_recipe' => false]);
    $this->gericht = $this->makeRecipe($r, 'Rinderschmorbraten', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Brauner Fond')->update(['referenced_recipe_id' => $this->basis->id]);
    $this->andere = $this->makeRecipe($r, 'Geheimrezept', ['is_sales_recipe' => true]);
    $this->konzept = $this->makeConcept($r, 'Mittagstisch');
    $this->makeConceptSlot($this->konzept, ['sales_recipe_id' => $this->gericht->id]);
    $this->format = FoodAlchemistFormat::create(['team_id' => $r->id, 'name' => 'Kantine', 'status' => 'aktiv']);
    FoodAlchemistFormatSlot::create(['team_id' => $r->id, 'format_id' => $this->format->id, 'type' => 'concept', 'concept_id' => $this->konzept->id, 'position' => 0]);
    $this->kindKoch = $this->makeUser($this->childA, 'Kind Koch', 'member');
    $this->sieht = fn ($klasse, $team = null) => $klasse::visibleToTeam($team ?? $this->childA)->pluck('id')->map(fn ($v) => (int) $v)->all();
});

it('Standard übernimmt alles; Haken aus = nur Eigenes, Stammdaten bleiben; nur Admin des Oberteams schaltet', function () {
    expect(($this->sieht)(FoodAlchemistRecipe::class))->toContain($this->gericht->id, $this->andere->id);

    expect(fn () => $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->koch))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->inhalte->setzeErbtAlles($this->childA, $this->childB->id, false, $this->inhaber))->toThrow(\RuntimeException::class);
    $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);

    $eigen = $this->makeRecipe($this->childA, 'Standort-Spezial');
    expect(($this->sieht)(FoodAlchemistRecipe::class))->toBe([$eigen->id])
        ->and(($this->sieht)(FoodAlchemistConcept::class))->toBe([])
        ->and(($this->sieht)(FoodAlchemistGp::class))->toContain($this->gp['Mehl']->id);              // Stammdaten bleiben
    expect(($this->sieht)(FoodAlchemistRecipe::class, $this->childB))->toContain($this->gericht->id);   // Geschwister unberührt
    expect(($this->sieht)(FoodAlchemistRecipe::class, $this->rootTeam))->toContain($this->gericht->id)->not->toContain($eigen->id);   // Oberteam liest nach unten nur über die Team-Brille
});

it('Sammlung mit Format gibt die Hülle frei (Konzept, Gericht, Basisrezept) — sonst nichts; Änderungen ziehen nach', function () {
    $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    $sid = $this->inhalte->sammlungAnlegen($this->rootTeam, 'Grundsortiment', null, $this->koch);
    $this->inhalte->sammlungHinzu($this->rootTeam, $sid, 'format', [$this->format->id], $this->koch);
    expect(fn () => $this->inhalte->freigeben($this->rootTeam, 'sammlung', $sid, $this->childA->id, $this->koch))->toThrow(FaRechtFehltException::class);
    $this->inhalte->freigeben($this->rootTeam, 'sammlung', $sid, $this->childA->id, $this->inhaber);

    $rezepte = ($this->sieht)(FoodAlchemistRecipe::class);
    expect($rezepte)->toContain($this->gericht->id, $this->basis->id)->not->toContain($this->andere->id)
        ->and(($this->sieht)(FoodAlchemistConcept::class))->toBe([$this->konzept->id])
        ->and(($this->sieht)(FoodAlchemistFormat::class))->toBe([$this->format->id]);
    // Rohe Queries: gleiche Regel
    $roh = TeamScope::applyVisible(DB::table('foodalchemist_recipes'), 'foodalchemist_recipes.team_id', $this->childA)->pluck('id')->all();
    expect(array_map('intval', $roh))->not->toContain($this->andere->id)->toContain($this->gericht->id);
    // Referenzieren nicht freigegebener Inhalte geht nicht
    expect(fn () => TeamScope::referenz(FoodAlchemistRecipe::class, $this->andere->id, $this->childA, 'sales_recipe_id'))->toThrow(\RuntimeException::class);

    // Konzept bekommt ein weiteres Gericht → Hülle zieht nach
    $this->makeConceptSlot($this->konzept, ['sales_recipe_id' => $this->andere->id, 'position' => 2]);
    expect(($this->sieht)(FoodAlchemistRecipe::class))->toContain($this->andere->id);

    // Freigabe entziehen → wieder weg
    $fid = $this->inhalte->freigaben($this->rootTeam)[0]['id'];
    $this->inhalte->freigabeEntziehen($this->rootTeam, $fid, $this->inhaber);
    expect(($this->sieht)(FoodAlchemistRecipe::class))->toBe([]);
});

it('Ausgaben: nur eigene Foodbooks freigeben, nur an eigene Standorte; Foodbook zieht seine Gerichte mit', function () {
    $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    $fb = $this->makeFoodbook($this->rootTeam, 'Sommer');
    $this->makeFoodbookBlock($this->makeChapter($fb), ['sales_recipe_id' => $this->andere->id]);
    $fremd = $this->makeFoodbook($this->childB, 'Fremd');
    expect(fn () => $this->inhalte->freigeben($this->rootTeam, 'foodbook', $fremd->id, $this->childA->id, $this->inhaber))->toThrow(\RuntimeException::class, 'eigene Ausgaben');
    expect(fn () => $this->inhalte->freigeben($this->childA, 'foodbook', $fb->id, $this->childB->id, $this->inhaber))->toThrow(\RuntimeException::class);

    $this->inhalte->freigeben($this->rootTeam, 'foodbook', $fb->id, $this->childA->id, $this->inhaber);
    expect(($this->sieht)(\Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class))->toBe([$fb->id])
        ->and(($this->sieht)(FoodAlchemistRecipe::class))->toBe([$this->andere->id]);
});

it('Freigegebenes ist lesend; eigene Kopie merkt sich das Original und meldet Änderungen', function () {
    $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    $sid = $this->inhalte->sammlungAnlegen($this->rootTeam, 'Gerichte', null, $this->koch);
    $this->inhalte->sammlungHinzu($this->rootTeam, $sid, 'recipe', [$this->gericht->id], $this->koch);
    $this->inhalte->freigeben($this->rootTeam, 'sammlung', $sid, $this->childA->id, $this->inhaber);

    $this->actingAs($this->kindKoch);
    expect(fn () => app(RecipeService::class)->setStatus($this->childA, $this->gericht->id, 'approved'))->toThrow(\RuntimeException::class, 'anderen Team');

    $kopie = $this->inhalte->kopieAnlegen($this->childA, 'recipe', $this->gericht->id, $this->kindKoch);
    expect((int) $kopie->team_id)->toBe((int) $this->childA->id)->and((int) $kopie->kopie_von_id)->toBe($this->gericht->id)
        ->and($kopie->name)->toBe('Rinderschmorbraten')
        ->and($this->inhalte->originalGeaendert($kopie))->toBeFalse();
    expect(fn () => $this->inhalte->kopieAnlegen($this->childA, 'recipe', $kopie->id, $this->kindKoch))->toThrow(\RuntimeException::class, 'schon');

    $this->gericht->forceFill(['updated_at' => now()->addMinute()])->save();
    expect($this->inhalte->originalGeaendert($kopie->refresh()))->toBeTrue();

    // Nicht freigegebenes Format: unsichtbar, also auch keine Kopie
    expect(fn () => $this->inhalte->kopieAnlegen($this->childA, 'format', $this->format->id, $this->kindKoch))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->inhalte->sammlungHinzu($this->rootTeam, $sid, 'format', [$this->format->id], $this->koch);
    $fmt = $this->inhalte->kopieAnlegen($this->childA, 'format', $this->format->id, $this->kindKoch);

    // Rezept-Editor: Hinweis + Knopf „Eigene Kopie" am geerbten Rezept, Kopie wird geöffnet
    $this->actingAs($this->kindKoch);
    $m = Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::class)->call('oeffnen', $this->basis->id)
        ->assertSeeHtml('data-rezept-eigene-kopie')->call('eigeneKopieAnlegen')->assertSet('fehler', null);
    $neu = FoodAlchemistRecipe::where('team_id', $this->childA->id)->where('kopie_von_id', $this->basis->id)->first();
    expect($neu)->not->toBeNull();
    $m->assertSet('recipeId', $neu->id)->assertDontSeeHtml('data-rezept-eigene-kopie');
    expect(FoodAlchemistFormatSlot::where('format_id', $fmt->id)->pluck('concept_id')->all())->toBe([$this->konzept->id]);
});

it('Oberfläche und MCP: Einstellungen schalten und geben frei; Tools prüfen die Rolle', function () {
    $this->actingAs($this->inhaber);
    $sid = $this->inhalte->sammlungAnlegen($this->rootTeam, 'Grundsortiment', null, $this->inhaber);
    Livewire::test(StandortInhalte::class)
        ->assertSeeHtml('data-inhalte-standort="'.$this->childA->id.'"')
        ->call('erbtAllesSetzen', $this->childA->id, false)->assertSet('fehler', null)
        ->set('freigabeNeu.'.$this->childA->id, 'sammlung:'.$sid)
        ->call('freigeben', $this->childA->id)->assertSet('fehler', null)
        ->assertSeeHtml('data-inhalte-freigabe=');
    expect($this->inhalte->erbtAlles($this->childA))->toBeFalse();

    // Mehrfachauswahl im Rezept-Browser → Sammlung
    Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\Browser::class)
        ->set('auswahl', [$this->basis->id => true])->set('sammlungZiel', (string) $sid)
        ->assertSeeHtml('data-bulk-zu-sammlung')->call('zuSammlung')->assertSet('auswahl', []);
    expect(FoodAlchemistRecipe::visibleToTeam($this->childA)->pluck('id')->all())->toContain($this->basis->id);

    $get = $this->reg->get('foodalchemist.standort_inhalte.GET')->execute(['standort_team_id' => $this->childA->id], new ToolContext($this->koch, $this->rootTeam));
    expect($get->success)->toBeTrue()->and($get->data['freigaben'])->toHaveCount(1);
    $put = $this->reg->get('foodalchemist.standort_inhalte.PUT')->execute(['aktion' => 'erbt_alles', 'standort_team_id' => $this->childA->id, 'an' => true], new ToolContext($this->koch, $this->rootTeam));
    expect($put->errorCode)->toBe('FORBIDDEN');

    $s = $this->reg->get('foodalchemist.sammlungen.PUT')->execute(['aktion' => 'hinzufuegen', 'sammlung_id' => $sid, 'typ' => 'recipe', 'ids' => [$this->gericht->id, $this->andere->id]], new ToolContext($this->koch, $this->rootTeam));
    expect($s->success)->toBeTrue()->and($s->data['hinzugefuegt'])->toBe(2);
    expect(FoodAlchemistRecipe::visibleToTeam($this->childA)->pluck('id')->all())->toContain($this->andere->id);

    $k = $this->reg->get('foodalchemist.inhalte.KOPIE')->execute(['typ' => 'recipe', 'id' => $this->andere->id], new ToolContext($this->kindKoch, $this->childA));
    expect($k->success)->toBeTrue()->and($k->data['kopie_von_id'])->toBe($this->andere->id);
});

it('Picker: Ebenen Gerichte, Basisrezepte, Konzepte, Pakete, Formate — anhaken und gesammelt hinzufügen', function () {
    $this->actingAs($this->inhaber);
    $paket = \Platform\FoodAlchemist\Models\FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Fingerfood-Paket']);
    $sid = $this->inhalte->sammlungAnlegen($this->rootTeam, 'Grundsortiment', null, $this->inhaber);

    $lw = Livewire::test(StandortInhalte::class)->call('sammlungOeffnen', $sid)
        ->assertSeeHtml('data-sammlung-picker')
        ->assertSeeHtml('data-picker-eintrag="'.$this->gericht->id.'"')->assertDontSeeHtml('data-picker-eintrag="'.$this->basis->id.'"');   // Gerichte ≠ Basisrezepte
    $lw->set('ebene', 'basis')->assertSeeHtml('data-picker-eintrag="'.$this->basis->id.'"')->assertDontSeeHtml('data-picker-eintrag="'.$this->gericht->id.'"');
    $lw->set('ebene', 'gericht')->set('pickerAuswahl', [$this->gericht->id => true, $this->andere->id => true])->call('auswahlHinzu')->assertSet('fehler', null);
    $lw->set('ebene', 'paket')->assertSeeHtml('data-picker-eintrag="'.$paket->id.'"')->set('pickerAuswahl', [$paket->id => true])->call('auswahlHinzu')->assertSet('fehler', null);
    $lw->set('ebene', 'format')->set('pickerAuswahl', [$this->format->id => true])->call('auswahlHinzu');

    $objekte = collect($this->inhalte->sammlungen($this->rootTeam)[0]['objekte'])->map(fn ($o) => $o['typ'].':'.$o['id'])->sort()->values()->all();
    expect($objekte)->toBe(collect(['recipe:'.$this->gericht->id, 'recipe:'.$this->andere->id, 'paket:'.$paket->id, 'format:'.$this->format->id])->sort()->values()->all());
    // schon Enthaltenes ist markiert und nicht erneut anhakbar
    $lw->set('ebene', 'gericht')->assertSee('schon in der Sammlung');
    // Filter: nur noch nicht enthaltene blendet Enthaltenes aus; Status filtert
    $lw->set('nurNeue', true)->assertDontSeeHtml('data-picker-eintrag="'.$this->gericht->id.'"');
    $this->makeRecipe($this->rootTeam, 'Entwurf-Gericht', ['is_sales_recipe' => true, 'status' => 'draft']);
    $lw->set('nurNeue', false)->set('filterStatus', 'draft')->assertSee('Entwurf-Gericht')->assertDontSeeHtml('data-picker-eintrag="'.$this->gericht->id.'"');
    // im Controlling-Editor als Reiter
    Livewire::test(\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::class)->call('setTab', 'standorte')->assertSeeHtml('data-settings-standort-inhalte');
});

it('Kundengrenze: unter einem Master-Team endet Haupt-Team, Kontingent und Standorte beim Kunden', function () {
    // Kette: Root (= Master) → childA (Kunde) ; childB (Kunde)
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $rechte = app(\Platform\FoodAlchemist\Services\FaRechte::class);
    $enkel = \Platform\Core\Models\Team::create(['name' => 'Standort A1', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->childA->id]);

    expect($rechte->kundenHauptTeam($enkel)->id)->toBe($this->childA->id)
        ->and($rechte->kundenHauptTeam($this->childB)->id)->toBe($this->childB->id)
        ->and($rechte->kundenHauptTeam($this->rootTeam)->id)->toBe($this->rootTeam->id);
    expect(app(\Platform\FoodAlchemist\Services\StandortService::class)->unterTeamIds($this->rootTeam))->toBe([])   // Master hat keine Standorte
        ->and(app(\Platform\FoodAlchemist\Services\StandortService::class)->unterTeamIds($this->childA))->toBe([(int) $enkel->id]);

    config(['foodalchemist.master_team_id' => null]);
    expect($rechte->kundenHauptTeam($enkel)->id)->toBe($this->rootTeam->id);   // ohne Master wie bisher
});

it('Konzept- und Format-Editor: fremdes nur lesend mit Knopf „Eigene Kopie", Kopie wird geöffnet', function () {
    $this->actingAs($this->kindKoch);   // Standort übernimmt alles (Standard) → sieht Konzept + Format des Oberteams

    $k = Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)->call('oeffnen', 'concepts', $this->konzept->id)
        ->assertSeeHtml('data-eigene-kopie')->call('eigeneKopieAnlegen')->assertSet('fehler', null);
    $kKopie = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::where('team_id', $this->childA->id)->where('kopie_von_id', $this->konzept->id)->first();
    expect($kKopie)->not->toBeNull()->and($kKopie->name)->toBe('Mittagstisch');
    $k->assertSet('id', $kKopie->id)->assertDontSeeHtml('data-eigene-kopie');

    $f = Livewire::test(\Platform\FoodAlchemist\Livewire\Formate\Editor::class)->call('oeffnen', $this->format->id)
        ->assertSeeHtml('data-eigene-kopie')->call('eigeneKopieAnlegen')->assertSet('fehler', null);
    $fKopie = FoodAlchemistFormat::where('team_id', $this->childA->id)->where('kopie_von_id', $this->format->id)->first();
    expect($fKopie)->not->toBeNull();
    $f->assertSet('id', $fKopie->id)->assertDontSeeHtml('data-eigene-kopie');

    // Oberteam sieht am eigenen Konzept keinen Kopie-Knopf
    $this->actingAs($this->inhaber);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)->call('oeffnen', 'concepts', $this->konzept->id)->assertDontSeeHtml('data-eigene-kopie');
});
