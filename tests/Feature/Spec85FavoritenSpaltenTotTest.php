<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Services\GenerationContextService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 FAV-2 · Riegel „die GP-Spalten sind tot“: `is_favorite`/`favorite_rank` am GP stehen bis FAV-3 noch in der Tabelle, aber
 * keine Lesestelle darf sie mehr auswerten — Favoriten kommen nur aus dem Team-Overlay. Statt eines Grep-Tests (der Collection-`where`
 * und DB-Spalten nicht unterscheiden kann) prüft dieser Test das VERHALTEN: ein GP mit gesetzten Spalten, aber ohne Overlay-Zeile, ist
 * an jeder Lesestelle KEIN Favorit; derselbe GP mit Overlay-Zeile ist es (Gegenstück).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Kuratorin', 'owner'));
    $this->gp = $this->makeGp($this->rootTeam, 'TK-Spätzle');
    $this->gp->update(['status' => 'approved', 'tag_is_convenience' => true]);
    DB::table('foodalchemist_gps')->where('id', $this->gp->id)->update(['is_favorite' => true, 'favorite_rank' => 1,
        'is_derivat' => false, 'is_platzhalter' => false]);   // alte Spalten gesetzt
    DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => 'spaetzle',
        'display_de' => 'Spätzle', 'created_at' => now(), 'updated_at' => now()]);
    $this->anker = (int) DB::getPdo()->lastInsertId();
    DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'gp_id' => $this->gp->id, 'anchor_id' => $this->anker, 'role' => 'kern', 'created_at' => now(), 'updated_at' => now()]);

    $this->lesestellen = function (): array {
        $svc = app(FavoriteGpService::class);
        $recipe = $this->makeRecipe($this->rootTeam, 'Spätzle-Gericht ' . uniqid());
        $this->makeIngredient($recipe, 'Spätzle', $this->gp, '100');
        $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
        $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
            'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);
        $prompt = app(GenerationContextService::class)->forGeneration($this->rootTeam, 'xy', false, true);

        return [
            'prompt' => in_array('TK-Spätzle', collect($prompt['favorites']['treffer'] ?? [])->pluck('name')->all(), true),
            'suggest' => (bool) ($svc->suggest($this->rootTeam)->firstWhere('gp_id', $this->gp->id)['is_favorite'] ?? false),
            'current' => $svc->current($this->rootTeam)->contains('id', $this->gp->id),
            'verfuegbarkeit' => ($svc->verfuegbarkeit($this->rootTeam, [$this->gp->id])[$this->gp->id]['bucket'] ?? null) === 'fuehren',
            'planung_kandidat' => ! collect(app(PlanningCascadeService::class)->favoritKandidatenFuerStep($this->rootTeam, (int) $step->id))->contains('id', $this->gp->id),
            'zutaten_filter' => collect(Livewire::test(IngredientEditor::class)->instance()->browseKatalog(['nur_favoriten' => true], [], '')['gps']['items'])
                ->contains('name', 'TK-Spätzle'),
            'pairing' => (bool) (app(PairingService::class)->gpsForAnkerIds($this->rootTeam, [$this->anker])->firstWhere('id', $this->gp->id)?->is_favorite),
            'scope' => FoodAlchemistGp::query()->favoritenFuer($this->rootTeam)->whereKey($this->gp->id)->exists(),
        ];
    };
});

it('gesetzte GP-Spalten ohne Overlay-Zeile: an KEINER Lesestelle ein Favorit', function () {
    expect(($this->lesestellen)())->each->toBeFalse();
});

it('Gegenstück: mit Overlay-Zeile des Teams ist der GP an jeder Lesestelle Favorit', function () {
    app(FavoriteGpService::class)->pin($this->rootTeam, $this->gp, 1);

    expect(($this->lesestellen)())->each->toBeTrue();
});
