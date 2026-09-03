<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $this->actingAs($this->makeUser($this->rootTeam));
});

/*
 * W3-5: der WEG von der Messsonde bis in den Kontext-Inspektor.
 *
 * Die Zahlen lagen seit Welle 0 in `foodalchemist_ai_call_log.prompt_parts`, aber kein Blade las
 * sie. Der Inspektor zeigte allein `chars` aus contextFor — den Retrieval-Topf. Gemessen sind
 * das ~36.000 Zeichen, wo der Prompt ~77.500 hat: der Bound-Block (verbindliches Regelwerk,
 * grösster Posten), Task, Hüllen und Kontext-JSON fehlten in der Anzeige komplett.
 *
 * Das Blade-Rendern prüft KontextInspektorRenderTest. Hier geht es um die Verdrahtung: kommen die
 * Werte überhaupt am Kontext-Bündel an? Sie entstehen erst IM Gateway, können also nicht aus dem
 * vorbereiteten Kontext stammen — sie werden nach dem Call über die Call-Log-ID nachgezogen.
 *
 * ⚠ `callLogId` IMMER benannt übergeben. Positionell ist der 5. Parameter von `AiProposal`
 * `$model`, nicht `callLogId` (der ist der 7.). Mit der ID an der falschen Stelle blieb
 * `callLogId` null — und der Fail-soft-Test unten war dadurch aus dem FALSCHEN Grund grün.
 */
it('zieht die Prompt-Groessen ueber die Call-Log-ID in das Kontext-Buendel', function () {
    $logId = DB::table('foodalchemist_ai_call_log')->insertGetId([
        'team_id' => $this->rootTeam->id,
        'feature' => 'recipe.generator',
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'model' => 'fake',
        'prompt_chars' => 51008,
        'prompt_parts' => json_encode([
            'huelle' => 333, 'bound' => 28630, 'task' => 5024,
            'retrieval' => 11000, 'kontext' => 6021, 'dropped' => 13667,
        ]),
        'tokens_in' => 16778,
        'tokens_cached' => 3840,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->mock(AiGatewayService::class, function ($m) use ($logId) {
        $m->shouldReceive('propose')->andReturn(new AiProposal(
            ['name' => 'Fond: Test', 'zutaten' => [['text' => 'Wasser', 'quantity' => 1000, 'unit' => 'ml']]],
            0.9, 'Mock', [], callLogId: (int) $logId,
        ));
    });

    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Testfond');

    expect($r['kontext'])->toBeArray()
        ->and($r['kontext']['prompt'])->toBeArray()
        ->and($r['kontext']['prompt']['chars'])->toBe(51008)
        // Der grösste Posten — vorher unsichtbar.
        ->and($r['kontext']['prompt']['bound'])->toBe(28630)
        ->and($r['kontext']['prompt']['dropped'])->toBe(13667)
        ->and($r['kontext']['prompt']['tokens_cached'])->toBe(3840)
        // … und die alte Zahl bleibt daneben stehen, sie war ja nicht falsch, nur unvollständig.
        ->and($r['kontext'])->toHaveKey('chars');
});

it('bleibt fail-soft, wenn die Sonde nichts geschrieben hat', function () {
    // Eine Log-Zeile OHNE prompt_parts (z. B. vor der Migration). Kein `prompt`-Schlüssel statt
    // einer Tabelle voller Nullen — die würde Wissen behaupten, das wir nicht haben.
    $logId = DB::table('foodalchemist_ai_call_log')->insertGetId([
        'team_id' => $this->rootTeam->id, 'feature' => 'recipe.generator',
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'model' => 'fake',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->mock(AiGatewayService::class, function ($m) use ($logId) {
        $m->shouldReceive('propose')->andReturn(new AiProposal(
            ['name' => 'Fond: Ohne Sonde', 'zutaten' => [['text' => 'Wasser', 'quantity' => 500, 'unit' => 'ml']]],
            0.9, 'Mock', [], callLogId: (int) $logId,
        ));
    });

    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Testfond');

    expect($r['kontext'])->toBeArray()
        ->and($r['kontext']['prompt'] ?? null)->toBeNull();
});
