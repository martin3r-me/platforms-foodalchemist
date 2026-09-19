<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->unitG($this->rootTeam);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->runTool = fn ($name, $args = [], $ctx = null) => app(ToolRegistry::class)->get('foodalchemist.' . $name)->execute($args, $ctx ?? $this->ctx);
    $post = ($this->runTool)('recipes.POST', ['name' => 'Gemüse: Karotten', 'zutaten' => [
        ['name' => 'Karotte', 'gp_id' => $this->makeGp($this->rootTeam, 'Karotte')->id, 'quantity' => 1000, 'unit' => 'g', 'cooking_loss_pct' => 12],
    ]]);
    expect($post->success)->toBeTrue($post->error ?? '');
    $this->recipe = FoodAlchemistRecipe::findOrFail($post->data['recipe']['id']);
});

it('MCP meldet falsch platzierten Garverlust ohne Version oder Daten zu ändern', function () {
    $version = $this->recipe->version;
    $result = ($this->runTool)('recipes.PUT', ['recipe_id' => $this->recipe->id, 'cooking_loss_pct' => 12]);
    expect($result->errorCode)->toBe('VALIDATION_ERROR')
        ->and($result->error)->toContain('recipe_ingredients.PUT')
        ->and($this->recipe->fresh()->version)->toBe($version);
});

it('GET und Zutaten-PUT erhalten ID und Garverlust beim GP-Wechsel und erlauben explizites Löschen', function () {
    $get = ($this->runTool)('recipes.GET', ['id' => $this->recipe->id]);
    $row = $get->data['zutaten'][0];
    expect((float) $row['cooking_loss_pct'])->toBe(12.0);
    $id = $row['id'];
    $gp = $this->makeGp($this->rootTeam, 'Rapsöl');
    $input = ['id' => $id, 'name' => 'Rapsöl', 'gp_id' => $gp->id, 'quantity' => 1000, 'unit' => 'g'];
    $put = ($this->runTool)('recipe_ingredients.PUT', ['recipe_id' => $this->recipe->id, 'zutaten' => [$input]]);
    expect($put->success)->toBeTrue();
    $row = $this->recipe->ingredients()->sole();
    expect($row->id)->toBe($id)->and((float) $row->cooking_loss_pct)->toBe(12.0)
        ->and((int) $row->gp_id)->toBe((int) $gp->id)
        ->and((float) $this->recipe->fresh()->yield_kg)->toBe(0.88);
    $put = ($this->runTool)('recipe_ingredients.PUT', ['recipe_id' => $this->recipe->id, 'zutaten' => [$input + ['cooking_loss_pct' => null]]]);
    expect($put->success)->toBeTrue()->and($row->fresh()->cooking_loss_pct)->toBeNull();
});

it('MCP weist ungültige Garverluste und fremde Zutaten-IDs atomar zurück', function () {
    $input = ['id' => $this->recipe->ingredients()->sole()->id, 'name' => 'Karotte', 'quantity' => 1000, 'unit' => 'g'];
    foreach ([-1, 101, 'zwölf'] as $bad) {
        expect(($this->runTool)('recipe_ingredients.PUT', ['recipe_id' => $this->recipe->id, 'zutaten' => [$input + ['cooking_loss_pct' => $bad]]])->errorCode)->toBe('VALIDATION_ERROR');
    }
    $input['id'] = 999999;
    expect(($this->runTool)('recipe_ingredients.PUT', ['recipe_id' => $this->recipe->id, 'zutaten' => [$input]])->errorCode)->toBe('VALIDATION_ERROR')
        ->and((float) $this->recipe->ingredients()->sole()->cooking_loss_pct)->toBe(12.0);
    $child = new ToolContext($this->makeUser($this->childA), $this->childA);
    expect(($this->runTool)('recipe_ingredients.PUT', ['recipe_id' => $this->recipe->id, 'zutaten' => [$input]], $child)->success)->toBeFalse();
});

it('Postenkatalog ist team-scoped und MCP kann den Default setzen und lesen', function () {
    $station = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'name' => 'Entremetier', 'slug' => 'entremetier']);
    $foreign = FoodAlchemistProductionStation::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'slug' => 'fremd']);
    $inactive = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'name' => 'Inaktiv', 'slug' => 'inaktiv', 'is_inactive' => true]);
    $ids = array_column(($this->runTool)('production_stations.GET')->data['posten'], 'id');
    expect($ids)->toContain($station->id)->not->toContain($foreign->id, $inactive->id);
    expect(($this->runTool)('recipes.PUT', ['recipe_id' => $this->recipe->id, 'default_station_id' => $station->id])->success)->toBeTrue();
    expect((int) ($this->runTool)('recipes.GET', ['id' => $this->recipe->id])->data['default_station_id'])->toBe((int) $station->id);
    expect(($this->runTool)('recipes.PUT', ['recipe_id' => $this->recipe->id, 'default_station_id' => $foreign->id])->success)->toBeFalse();
});

it('Posten-KI nutzt fachlichen Kontext und erhält vorhandene Zuordnungen', function () {
    $station = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'name' => 'Entremetier', 'slug' => 'entremetier']);
    $foreign = FoodAlchemistProductionStation::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'slug' => 'fremd']);
    $this->mock(AiGatewayService::class, function ($mock) use ($station, $foreign) {
        $mock->shouldReceive('propose')->once()->with('recipe.posten', Mockery::on(function ($context) use ($station, $foreign) {
            $ids = array_column($context['posten'], 'id');
            return in_array($station->id, $ids) && ! in_array($foreign->id, $ids) && $context['name'] === $this->recipe->name;
        }), Mockery::any())->andReturn(new AiProposal(['station_id' => $station->id], 0.9, 'Gemüse gehört zum Entremetier', [], 'posten-test'));
    });
    $method = new ReflectionMethod(RecipeOneShotService::class, 'postenGlied');
    $svc = app(RecipeOneShotService::class);
    expect($method->invoke($svc, $this->rootTeam, $this->recipe)['status'])->toBe('aktualisiert')
        ->and((int) $this->recipe->fresh()->default_station_id)->toBe((int) $station->id);
    expect($method->invoke($svc, $this->rootTeam, $this->recipe->fresh())['status'])->toBe('beibehalten');
});

it('Posten-KI darf keine fremden IDs speichern', function () {
    FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'name' => 'Entremetier', 'slug' => 'entremetier']);
    $foreign = FoodAlchemistProductionStation::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'slug' => 'fremd']);
    $this->mock(AiGatewayService::class, function ($mock) use ($foreign) {
        $mock->shouldReceive('propose')->once()->andReturn(new AiProposal(['station_id' => $foreign->id], 0.9, 'Unzulässig', [], 'posten-test'));
    });
    $method = new ReflectionMethod(RecipeOneShotService::class, 'postenGlied');
    expect($method->invoke(app(RecipeOneShotService::class), $this->rootTeam, $this->recipe)['status'])->toBe('offen')
        ->and($this->recipe->fresh()->default_station_id)->toBeNull();
});

it('MCP liefert ein echtes Plattform-PDF ohne Browser und sperrt fremde Rezepte', function () {
    $pdf = ($this->runTool)('recipes.PDF', ['id' => $this->recipe->id, 'profil' => 'kurz', 'transport' => 'base64']);
    expect($pdf->success)->toBeTrue($pdf->error ?? '')
        ->and($pdf->data['mime_type'])->toBe('application/pdf');
    $bytes = base64_decode($pdf->data['content_base64'], true);
    expect($bytes)->toStartWith('%PDF-')->and(strlen($bytes))->toBe($pdf->data['size_bytes']);
    expect(rtrim($bytes))->toEndWith('%%EOF');
    $service = app(\Platform\FoodAlchemist\Services\ReportExportService::class);
    $data = $service->rezeptDaten($this->rootTeam, $this->recipe->id, $service->optionen(['profil' => 'kurz'], 'recipe'));
    expect(view('foodalchemist::dokumente.report', $data + ['istPdf' => true])->render())->toContain('Karotten')->toContain('draft');
    if (getenv('FA_MCP_PDF_SMOKE_PATH')) {
        file_put_contents(getenv('FA_MCP_PDF_SMOKE_PATH'), $bytes);
    }
    $foreign = FoodAlchemistRecipe::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'recipe_key' => 'pdf-fremd', 'status' => 'draft']);
    expect(($this->runTool)('recipes.PDF', ['id' => $foreign->id])->errorCode)->toBe('NOT_FOUND');
    $child = new ToolContext($this->makeUser($this->childA), $this->childA);
    expect(($this->runTool)('recipes.PDF', ['id' => $this->recipe->id, 'profil' => 'kurz'], $child)->success)->toBeTrue();
});


it('MCP-PDF-Download funktioniert ohne Login, aber nicht manipuliert oder abgelaufen', function () {
    $result = ($this->runTool)('recipes.PDF', ['id' => $this->recipe->id, 'profil' => 'kurz']);
    expect($result->success)->toBeTrue()->and($result->data)->not->toHaveKey('content_base64');
    $url = $result->data['download_url'];
    auth()->logout();
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    $this->get($url . '&profil=voll')->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
    $this->travelBack();
});


it('MCP-PDF-Filter überschreiben Profile und steuern den gerenderten Inhalt samt Hochrechnung', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id, 'position' => 1, 'phase' => 'Garen', 'text' => 'MCP-Schrittprobe: langsam dünsten.',
    ]);
    $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('output')->once()->andReturn("%PDF-1.7\n%%EOF");
    $captured = null;
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()
        ->with('foodalchemist::dokumente.report', Mockery::on(function ($data) use (&$captured) {
            $captured = $data;
            return true;
        }))->andReturn($pdf);
    $result = ($this->runTool)('recipes.PDF', [
        'id' => $this->recipe->id, 'profil' => 'kurz', 'transport' => 'base64',
        'steps' => true, 'zutaten' => false, 'preise' => false, 'ziel_kg' => 8.8,
    ]);
    expect($result->success)->toBeTrue($result->error ?? '')->and($result->data['optionen']['steps'])->toBeTrue();
    expect($captured['optionen']['steps'])->toBeTrue()
        ->and($captured['optionen']['zutaten'])->toBeFalse()
        ->and($captured['optionen']['preise'])->toBeFalse()
        ->and($captured['optionen']['sensorik'])->toBeFalse()
        ->and((float) $captured['hochrechnung']['faktor'])->toEqualWithDelta(10.0, 0.000001);
    expect(view('foodalchemist::dokumente.report', $captured)->render())
        ->toContain('MCP-Schrittprobe')->not->toContain('Zutaten / Komponenten');
});

it('MCP-PDF lehnt unbekannte Filter und ungültige Filterwerte vor dem Rendern ab', function () {
    foreach ([['priese' => false], ['preise' => 'nein'], ['ziel_kg' => -1], ['profil' => 'falsch']] as $filter) {
        expect(($this->runTool)('recipes.PDF', ['id' => $this->recipe->id] + $filter)->errorCode)->toBe('VALIDATION_ERROR');
    }
});


it('Reife-Check weist fehlenden Posten und den richtigen Garverlust-Endpunkt aus', function () {
    $this->recipe->ingredients()->update(['cooking_loss_pct' => null]);
    $adapter = app(\Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter::class);
    $result = $adapter->messe($this->rootTeam, $this->recipe->id);
    $gaps = collect($result['luecken'])->keyBy('code');
    expect($gaps['garverlust']['wie']['tool'])->toBe('foodalchemist.recipe_ingredients.PUT')
        ->and($gaps['default_station_id']['schwere'])->toBe('wichtig');
    $station = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'name' => 'Entremetier', 'slug' => 'entremetier']);
    $this->recipe->update(['default_station_id' => $station->id]);
    expect($adapter->messe($this->rootTeam, $this->recipe->id)['erfuellt'])->toContain('default_station_id');
    $this->recipe->update(['is_sales_recipe' => true, 'default_station_id' => null]);
    expect(array_column($adapter->messe($this->rootTeam, $this->recipe->id)['luecken'], 'code'))->not->toContain('default_station_id');
});
