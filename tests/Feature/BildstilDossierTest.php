<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\ContextFile;
use Platform\Core\Services\ImageGenerationService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

/**
 * Spec 53 Paket Bildstil-Dossier — der Bild-Dienst geht nie durch `AiGatewayService::propose()`
 * (Core-Bilddienst direkt), darum zieht heute kein Kanon. `KnowledgeContextService::kanonTextFuer()`
 * + `RecipeImageService::stilBlock()` schliessen den Anschluss NUR über Pflicht-Kanon-Zeilen
 * (kein Discovery, kein Budget-Riegel — die einzige Grenze ist die Kappung ans Bilddienst-Limit).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dienst = app(RecipeImageService::class);
});

function bindeBildDossier(int $teamId, string $promptKey, string $slug, string $md): void
{
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'team_id' => $teamId,
        'slug' => $slug,
        'title' => 'Bildstil '.$slug,
        'category' => 'regelwerk',
        'content_md' => $md,
        'version' => 1,
        'content_hash' => hash('sha256', $slug),
        'char_count' => mb_strlen($md),
        'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('kanonTextFuer: ohne Bindung null + leere files_used', function () {
    $block = app(KnowledgeContextService::class)->kanonTextFuer($this->rootTeam, RecipeImageService::FEATURE_PRODUKTFOTO);

    expect($block['text'])->toBeNull()->and($block['files_used'])->toBe([]);
});

it('kanonTextFuer: mit Pflicht-Bindung liefert Text + files_used (slug@version)', function () {
    bindeBildDossier((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-produktfoto', 'dark slate worktop, matte white porcelain, daylight from the left.');
    $this->kanonZeile((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-produktfoto');

    $block = app(KnowledgeContextService::class)->kanonTextFuer($this->rootTeam, RecipeImageService::FEATURE_PRODUKTFOTO);

    expect($block['text'])->toContain('dark slate worktop')
        ->and($block['files_used'])->toBe(['bildstil-produktfoto@v1']);
});

it('kanonTextFuer: „wenn_platz"-Zeilen zaehlen NICHT (nur Pflicht, kein Budget-Riegel noetig)', function () {
    bindeBildDossier((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-optional', 'Optionaler Zusatztext.');
    $this->kanonZeile((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-optional', 'wenn_platz');

    $block = app(KnowledgeContextService::class)->kanonTextFuer($this->rootTeam, RecipeImageService::FEATURE_PRODUKTFOTO);

    expect($block['text'])->toBeNull()->and($block['files_used'])->toBe([]);
});

it('stilBlock: ohne Bindung bleibt der Fallback stilAnker + Style-Satz — unveraendert', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Ohne Dossier');

    $block = $this->dienst->stilBlock($this->rootTeam, $recipe, RecipeImageService::FEATURE_PRODUKTFOTO);

    expect($block)->toBe($this->dienst->stilAnker($recipe));
});

it('stilBlock: mit Bindung ERSETZT der Dossier-Text den stilAnker vollstaendig', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Mit Dossier');
    bindeBildDossier((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-produktfoto-2', 'Curated visual identity: brushed steel, warm light.');
    $this->kanonZeile((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-produktfoto-2');

    $filesUsed = [];
    $block = $this->dienst->stilBlock($this->rootTeam, $recipe, RecipeImageService::FEATURE_PRODUKTFOTO, filesUsed: $filesUsed);

    expect($block)->toContain('Curated visual identity: brushed steel, warm light.')
        ->not->toContain($this->dienst->stilAnker($recipe))
        ->and($filesUsed)->toBe(['bildstil-produktfoto-2@v1']);
});

it('stilBlock: Kappung an der letzten Absatzgrenze, nie mitten im Satz', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Langes Dossier');
    $absatz1 = str_repeat('A', 3000);
    $absatz2 = str_repeat('B', 3000);
    $md = $absatz1."\n\n".$absatz2;
    bindeBildDossier((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-lang', $md);
    $this->kanonZeile((int) $this->rootTeam->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-lang');

    $block = $this->dienst->stilBlock($this->rootTeam, $recipe, RecipeImageService::FEATURE_PRODUKTFOTO);

    expect(mb_strlen($block))->toBeLessThanOrEqual(4000)
        ->and($block)->toContain($absatz1)
        ->not->toContain('BBB');
});

it('produktFoto: das gebundene Dossier landet in knowledge_used des Call-Logs', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $team = $this->rootTeam;
    $user = $this->makeUser($team);
    $this->actingAs($user);
    $recipe = $this->makeRecipe($team, 'Produktfoto-Dossier-Test');
    bindeBildDossier((int) $team->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-log-test', 'Stil-Dossier fuer den Call-Log-Test.');
    $this->kanonZeile((int) $team->id, RecipeImageService::FEATURE_PRODUKTFOTO, 'bildstil-log-test');

    $this->mock(ImageGenerationService::class, function ($mock) use ($team, $user) {
        $mock->shouldReceive('generateAndStore')->once()->andReturnUsing(function (
            string $prompt, string $contextType, int $contextId, int $userId, int $teamId, array $options,
        ) {
            $token = 'ki-produktfoto-'.Str::random(8);
            $file = ContextFile::create([
                'token' => $token, 'team_id' => $teamId, 'user_id' => $userId,
                'context_type' => $contextType, 'context_id' => $contextId,
                'disk' => 'public', 'path' => "foodalchemist/rezepte/{$contextId}/{$token}.webp",
                'file_name' => "{$token}.webp", 'original_name' => "{$token}.png",
                'mime_type' => 'image/webp', 'file_size' => 1234, 'width' => 1024, 'height' => 1024,
                'keep_original' => false,
            ]);

            return ['id' => $file->id, 'revised_prompt' => $prompt];
        });
    });

    $this->dienst->produktFoto($team, $recipe);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', RecipeImageService::FEATURE_PRODUKTFOTO)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and(json_decode((string) $log->knowledge_used, true))->toBe(['bildstil-log-test@v1']);
});
