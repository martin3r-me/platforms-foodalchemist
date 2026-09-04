<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\Models\ContextFile;
use Platform\Core\Services\ImageGenerationService;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 27 Phase 2 — Schritt-Editor. Kern-Versprechen der Spec: das Foto klebt am
 * SCHRITT, nicht an einer Nummer (reorder-fest), ein Foto darf an mehreren Schritten
 * hängen, und der `preparation`-Spiegel ist nach jeder Aktion aktuell.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Editor-Rezept', ['preparation' => null]);

    $this->foto = fn (string $datei) => FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id,
        'pfad' => 'foodalchemist/rezepte/'.$this->rezept->id.'/'.$datei,
    ]);

    $this->editor = fn () => Livewire::test(StepEditor::class, ['recipeId' => $this->rezept->id]);
});

it('legt Schritte an und nummeriert fortlaufend, Abschnitt wird übernommen', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root A'));

    $lw = ($this->editor)()->call('schrittAnlegen');
    $erster = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();
    $lw->set('phasen.'.$erster->id, 'Mise en Place')->call('schrittAnlegen');

    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    expect($steps->pluck('position')->all())->toBe([1, 2])
        ->and($steps->pluck('phase')->all())->toBe(['Mise en Place', 'Mise en Place']);   // erbt den laufenden Abschnitt
});

it('Text tippen persistiert und zieht den preparation-Spiegel nach', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root B'));

    $lw = ($this->editor)()->call('schrittAnlegen');
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();

    $lw->set('texte.'.$s->id, 'Zwiebeln in Brunoise schneiden.');

    expect($s->fresh()->text)->toBe('Zwiebeln in Brunoise schneiden.')
        ->and($this->rezept->fresh()->preparation)->toBe('1. Zwiebeln in Brunoise schneiden.');
});

it('▲▼ und Drag-Drop sortieren um — die Fotos wandern mit dem Schritt', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root C'));

    app(RecipeStepService::class)->sync($this->rezept, [
        ['text' => 'eins'], ['text' => 'zwei'], ['text' => 'drei'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $f = ($this->foto)('an-drei.jpg');
    $steps[2]->photos()->attach($f->id, ['position' => 1]);

    // „drei" per ▲ nach oben
    ($this->editor)()->call('hoch', $steps[2]->id);
    $nach = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    expect($nach->pluck('text')->all())->toBe(['eins', 'drei', 'zwei'])
        ->and($nach[1]->id)->toBe($steps[2]->id)
        ->and($nach[1]->photos->pluck('id')->all())->toBe([$f->id]);   // ⬅ Foto klebt am Schritt

    // „eins" per Drag hinter „zwei" (letzte Zeile)
    ($this->editor)()->call('verschieben', $steps[0]->id, $steps[1]->id);
    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('text')->all())
        ->toBe(['drei', 'zwei', 'eins']);
});

it('verknüpft ein Foto per Klick an mehrere Schritte und löst es wieder (M:N)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root D'));

    app(RecipeStepService::class)->sync($this->rezept, [
        ['text' => 'eins'], ['text' => 'zwei'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $f = ($this->foto)('mep.jpg');

    $lw = ($this->editor)()
        ->call('fotoUmschalten', $steps[0]->id, $f->id)
        ->call('fotoUmschalten', $steps[1]->id, $f->id);

    expect($f->fresh()->steps->pluck('id')->all())->toBe([$steps[0]->id, $steps[1]->id]);

    // zweiter Klick auf denselben Schritt = lösen; das Foto bleibt im Pool
    $lw->call('fotoUmschalten', $steps[0]->id, $f->id);
    expect($f->fresh()->steps->pluck('id')->all())->toBe([$steps[1]->id])
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($f->id)->exists())->toBeTrue();
});

it('Upload mit aktivem Schritt legt das Foto in den Pool UND verlinkt es', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $this->actingAs($this->makeUser($this->rootTeam, 'Root E'));

    app(RecipeStepService::class)->sync($this->rezept, [['text' => 'eins']]);
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();

    ($this->editor)()
        ->call('poolOeffnen', $s->id)
        ->set('fotoUpload', UploadedFile::fake()->image('schritt.jpg'))
        ->set('fotoCaption', 'Brunoise')
        ->call('fotoHochladen')
        ->assertSet('fehler', null);

    $foto = FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->rezept->id)->firstOrFail();
    expect($foto->caption)->toBe('Brunoise')
        ->and($foto->context_file_id)->not->toBeNull()
        ->and($s->fresh()->photos->pluck('id')->all())->toBe([$foto->id]);
    Storage::disk('public')->assertExists($foto->pfad);
});

it('KI-Fotos erzeugt Bilder fuer alle Schritte ohne Foto und laesst bestehende Fotos stehen', function () {
    $user = $this->makeUser($this->rootTeam, 'Root KI-Foto');
    $this->actingAs($user);

    app(RecipeStepService::class)->sync($this->rezept, [
        ['phase' => 'Mise en Place', 'text' => 'Champignons putzen und schneiden.'],
        ['phase' => 'Garen', 'text' => 'Champignons heiß anbraten.'],
        ['phase' => 'Finish', 'text' => 'Mit Balsamico glacieren.'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $bestehend = ($this->foto)('echt.jpg');
    $steps[0]->photos()->attach($bestehend->id, ['position' => 1]);

    $lauf = 0;
    $rootTeamId = $this->rootTeam->id;
    $this->mock(ImageGenerationService::class, function ($mock) use (&$lauf, $user, $rootTeamId) {
        $mock->shouldReceive('generateAndStore')->twice()->andReturnUsing(function (
            string $prompt,
            string $contextType,
            int $contextId,
            int $userId,
            int $teamId,
            array $options,
        ) use (&$lauf, $user, $rootTeamId) {
            $lauf++;
            expect($prompt)->toContain('Photorealistic professional catering kitchen process photo')
                ->and($contextType)->toBe('foodalchemist.recipe')
                ->and($userId)->toBe($user->id)
                ->and($teamId)->toBe($rootTeamId)
                ->and($options)->toBe(['size' => '1024x1024', 'quality' => 'low']);

            $token = 'ki-step-'.$lauf.'-'.Str::random(8);
            $file = ContextFile::create([
                'token' => $token,
                'team_id' => $teamId,
                'user_id' => $userId,
                'context_type' => $contextType,
                'context_id' => $contextId,
                'disk' => 'public',
                'path' => "foodalchemist/rezepte/{$contextId}/{$token}.webp",
                'file_name' => "{$token}.webp",
                'original_name' => "{$token}.png",
                'mime_type' => 'image/webp',
                'file_size' => 1234,
                'width' => 1024,
                'height' => 1024,
                'keep_original' => false,
            ]);

            return ['id' => $file->id, 'revised_prompt' => $prompt];
        });
    });

    ($this->editor)()->call('kiFotos')->assertSet('fehler', null);

    $neu = FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->rezept->id)->orderBy('id')->get();
    expect($neu)->toHaveCount(3)
        ->and($steps[0]->fresh()->photos->pluck('id')->all())->toBe([$bestehend->id])
        ->and($steps[1]->fresh()->photos)->toHaveCount(1)
        ->and($steps[2]->fresh()->photos)->toHaveCount(1)
        ->and($steps[1]->fresh()->photos->first()->caption)->toBe('KI-Foto: Schritt 2');

    $logs = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.step_photos')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('team_id')->unique()->all())->toBe([$this->rootTeam->id])
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($logs->pluck('tier')->unique()->all())->toBe(['I'])
        ->and($logs->pluck('target_table')->unique()->all())->toBe(['foodalchemist_recipe_step_photos'])
        ->and($logs->whereNotNull('error'))->toHaveCount(0);
});

it('nutzt zentral getrennte Bild-Prompts fuer Basisrezept-Produktion und Gerichte-Service', function () {
    app(RecipeStepService::class)->sync($this->rezept, [
        ['phase' => 'Trocknen', 'text' => 'Masse dünn streichen oder aufspritzen und trocknen.'],
    ]);
    $basisStep = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();

    $gericht = $this->makeRecipe($this->rootTeam, 'Dessertteller', ['is_sales_recipe' => true]);
    app(RecipeStepService::class)->sync($gericht, [
        ['phase' => 'Anrichten', 'text' => 'Creme portionieren und mit Crumble abschließen.'],
    ]);
    $gerichtStep = FoodAlchemistRecipeStep::where('recipe_id', $gericht->id)->firstOrFail();

    $bilder = app(RecipeImageService::class);
    $basisPrompt = $bilder->schrittPrompt($this->rezept, $basisStep);
    $gerichtPrompt = $bilder->schrittPrompt($gericht, $gerichtStep);

    expect($basisPrompt)
        ->toContain('professional catering kitchen process photo')
        ->toContain('show only the first stated method')
        ->not->toContain('all recipe components are already professionally prepared')
        ->and($gerichtPrompt)
        ->toContain('restaurant kitchen service and plating process photo')
        ->toContain('all recipe components are already professionally prepared')
        ->toContain('Never show their production from raw ingredients');
});

// 2026-09-04: Die Rahmung ist von „Service + Regeneration + Anrichten in einem" auf die
// reine FERTIGSTELLUNG umgestellt (Regelwerk Verkaufsgerichte §3). Das Regenerations-Programm
// führt `vk.regeneration` strukturiert, der Teller-Aufbau `vk.plating` — vorher forderte
// dieser Prompt beides zusätzlich als Prosa an und erzeugte damit eine zweite Wahrheit.
it('rahmt KI-Schritte fuer Verkaufsgerichte als Fertigstellung statt Komponenten-Produktion', function () {
    $user = $this->makeUser($this->rootTeam, 'Root VK-Schritte');
    $this->actingAs($user);

    $komponente = $this->makeRecipe($this->rootTeam, 'Vanille-Topfencreme', [
        'preparation' => 'Topfencreme glatt ruehren und kaltstellen.',
    ]);
    $gericht = $this->makeRecipe($this->rootTeam, '[DES] Marillenknoedel | Vanille-Topfencreme | Crumble', [
        'is_sales_recipe' => true,
        'sales_quantity_per_unit_g' => 140,
        'sales_unit_count' => 1,
    ]);
    $this->makeIngredient($gericht, 'Vanille-Topfencreme', null, '60', 1)
        ->update(['referenced_recipe_id' => $komponente->id]);

    $this->mock(AiGatewayService::class, function ($mock) use ($komponente) {
        $mock->shouldReceive('propose')->once()->with('recipe.steps', Mockery::on(function (array $payload) use ($komponente) {
            return ($payload['rezept_typ'] ?? null) === 'gericht'
                && str_contains((string) ($payload['zubereitungsziel'] ?? ''), 'Ablauf der Fertigstellung')
                && str_contains((string) ($payload['hinweis'] ?? ''), 'Nicht neu herstellen')
                // Die Abgrenzung der Nachbar-Ebenen muss im Kontext ankommen, sonst
                // schreibt das Modell wieder °C/min in die Schritte.
                && str_contains((string) ($payload['hinweis'] ?? ''), 'NICHT in diese Schritte')
                && ($payload['komponenten'][0]['name'] ?? null) === $komponente->name
                && ($payload['komponenten'][0]['typ'] ?? null) === 'basisrezept'
                && ($payload['verkaufseinheit']['portion_g'] ?? null) === 140;
        }), Mockery::any())->andReturn(new AiProposal([
            'steps' => [
                ['phase' => 'Mise en Place', 'text' => 'Komponenten kalt bereitstellen.'],
                ['phase' => 'Anrichten', 'text' => 'Topfencreme und Knoedel portionieren und mit Crumble abschliessen.'],
            ],
        ], 0.91, 'Mock', [], 'vk-steps'));
    });

    Livewire::test(StepEditor::class, ['recipeId' => $gericht->id])
        ->call('kiSchritte')
        ->assertSet('fehler', null);
});

it('Schritt löschen nummeriert neu und lässt das Foto im Pool', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root F'));

    app(RecipeStepService::class)->sync($this->rezept, [
        ['text' => 'eins'], ['text' => 'zwei'], ['text' => 'drei'],
    ]);
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get();
    $f = ($this->foto)('an-zwei.jpg');
    $steps[1]->photos()->attach($f->id, ['position' => 1]);

    ($this->editor)()->call('schrittLoeschen', $steps[1]->id);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('position')->all())->toBe([1, 2])
        ->and($this->rezept->fresh()->preparation)->toBe("1. eins\n2. drei")
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($f->id)->exists())->toBeTrue()
        ->and($f->fresh()->steps)->toHaveCount(0);
});

it('Foto endgültig löschen entfernt es aus allen Schritten', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root G'));

    app(RecipeStepService::class)->sync($this->rezept, [['text' => 'eins']]);
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();
    $f = ($this->foto)('weg.jpg');
    $s->photos()->attach($f->id, ['position' => 1]);

    ($this->editor)()->call('fotoLoeschen', $f->id);

    expect(FoodAlchemistRecipeStepPhoto::whereKey($f->id)->exists())->toBeFalse()
        ->and($s->fresh()->photos)->toHaveCount(0);
});

it('Markdown-Import parst in Schritte und ersetzt den Bestand', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root H'));

    ($this->editor)()
        ->set('markdownImport', "## Garen\n1. Anbraten.\n2. Ablöschen.")
        ->call('markdownUebernehmen')
        ->assertSet('fehler', null);

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->pluck('text')->all())
        ->toBe(['Anbraten.', 'Ablöschen.'])
        ->and($this->rezept->fresh()->preparation)->toBe("## Garen\n1. Anbraten.\n2. Ablöschen.");
});

it('Markdown-Import ohne erkennbaren Schritt meldet den Fehler statt still zu schlucken', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root I'));

    ($this->editor)()->set('markdownImport', '   ')->call('markdownUebernehmen')
        ->assertSet('fehler', 'Im eingefügten Text war kein Schritt erkennbar.');

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->count())->toBe(0);
});

it('Endprodukt-Bild: genau eines je Rezept, zweiter Klick hebt auf', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root J'));

    $a = ($this->foto)('teller-a.jpg');
    $b = ($this->foto)('teller-b.jpg');

    $lw = ($this->editor)()->call('endproduktUmschalten', $a->id);
    expect($a->fresh()->is_result)->toBeTrue();

    // Zweites Foto markieren → das erste verliert die Markierung (max. 1 je Rezept)
    $lw->call('endproduktUmschalten', $b->id);
    expect($a->fresh()->is_result)->toBeFalse()
        ->and($b->fresh()->is_result)->toBeTrue();

    // Erneut dasselbe → Markierung aufgehoben, das Foto bleibt im Pool
    $lw->call('endproduktUmschalten', $b->id);
    expect($b->fresh()->is_result)->toBeFalse()
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($b->id)->exists())->toBeTrue();
});

it('Endprodukt-Bild darf gleichzeitig an einem Schritt hängen', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root K'));

    app(RecipeStepService::class)->sync($this->rezept, [['text' => 'Anrichten.']]);
    $s = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->firstOrFail();
    $f = ($this->foto)('anrichten.jpg');

    ($this->editor)()->call('fotoUmschalten', $s->id, $f->id)->call('endproduktUmschalten', $f->id);

    expect($f->fresh()->is_result)->toBeTrue()
        ->and($s->fresh()->photos->pluck('id')->all())->toBe([$f->id]);
});

it('D1: ein geerbtes Rezept ist lesbar, aber nicht schreibbar', function () {
    // Nutzer im Kind-Team, Rezept gehört dem Root-Team → sichtbar (Kette aufwärts), nicht editierbar.
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    ($this->editor)()
        ->call('schrittAnlegen')
        ->assertSet('fehler', 'Geerbtes Rezept — Zubereitung nur durchs Besitzer-Team (D1).');

    expect(FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->count())->toBe(0);
});
