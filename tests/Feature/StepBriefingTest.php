<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Briefing an den drei Schrittfolgen (2026-09-04) — Basisrezept-Zubereitung,
 * Gericht-Fertigstellen, Gericht-Anrichten.
 *
 * Das Briefing ist ein EINGABE-Werkzeug: der Koch sagt für DIESES Rezept, wie die
 * Schritte sein sollen. Es ist absichtlich transient — nicht am Rezept gespeichert,
 * nach der Übernahme geleert. Vier Dinge müssen deshalb halten:
 *
 *  1. gefüllt → es steht im Prompt-Kontext; leer → der Key fehlt ganz (Tokens)
 *  2. der übrige Kontext bleibt VOLLSTÄNDIG („trotzdem den Gesamtblick haben")
 *  3. der Knopf fragt den Prompt SEINER Ebene (`recipe.steps` vs `vk.plating`)
 *  4. nach der Übernahme ist es weg — eine alte Vorgabe darf nicht still mitwirken
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    // Spion statt Stub: wir wollen den Prompt SEHEN, nicht nur die Antwort setzen.
    // Der Gateway hängt den Kontext als JSON hinter „Kontext:" an — dieselbe Naht,
    // die der FakeAiProvider für sein Echo nutzt.
    $this->spion = function (array $werte) {
        $gesehen = new stdClass;
        $gesehen->user = '';
        app()->bind(FakeAiProvider::class, fn () => new class($werte, $gesehen) extends FakeAiProvider
        {
            public function __construct(private array $werte, private stdClass $gesehen) {}

            public function chat(array $messages, array $options = []): array
            {
                $this->gesehen->user = (string) (collect($messages)->where('role', 'user')->last()['content'] ?? '');

                return [
                    'content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'model' => 'fake-briefing',
                    'tool_calls' => null,
                ];
            }
        });

        return $gesehen;
    };
});

// ── 1 + 2 · Briefing im Kontext, Gesamtblick erhalten ────────────────────────

it('gibt das Briefing an den Prompt weiter — und behaelt den uebrigen Kontext', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $gesehen = ($this->spion)(['steps' => [['phase' => null, 'text' => 'Knochen roesten.']]]);

    Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', 'Ansatz kalt aufsetzen, nie kochen — nur ziehen lassen.')
        ->call('kiSchritte');

    $kontext = json_decode((string) (preg_match('/Kontext:\s*(\{.*\})/s', $gesehen->user, $m) ? $m[1] : '{}'), true) ?? [];

    // Die Vorgabe kommt an …
    expect($kontext['briefing'] ?? null)->toBe('Ansatz kalt aufsetzen, nie kochen — nur ziehen lassen.')
        // … und ersetzt nichts: Name, Typ, Zielsatz und Zutatenschlüssel stehen weiter drin.
        // Genau das war die Zusage („soll es beruecksichtigen, aber trotzdem den
        // Gesamtblick haben") — ein Briefing, das den Kontext verdrängt, wäre der Bug.
        ->and($kontext['name'] ?? null)->toBe('Kalbsfond')
        ->and($kontext['rezept_typ'] ?? null)->toBe('basisrezept')
        ->and($kontext)->toHaveKey('zubereitungsziel')
        ->and($kontext)->toHaveKey('rohwaren')
        ->and($kontext)->toHaveKey('schritte_bestand');
});

it('laesst den briefing-Key ganz weg, wenn nichts eingegeben wurde', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $gesehen = ($this->spion)(['steps' => [['phase' => null, 'text' => 'Knochen roesten.']]]);

    Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])->call('kiSchritte');

    $kontext = json_decode((string) (preg_match('/Kontext:\s*(\{.*\})/s', $gesehen->user, $m) ? $m[1] : '{}'), true) ?? [];

    // Ein leerer Schlüssel würde das Modell beschäftigen, ohne etwas zu sagen.
    expect($kontext)->not->toHaveKey('briefing');
});

it('trimmt Leerraum — Leerzeichen sind kein Briefing', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    $gesehen = ($this->spion)(['steps' => [['phase' => null, 'text' => 'Knochen roesten.']]]);

    Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', "  \n ")
        ->call('kiSchritte');

    $kontext = json_decode((string) (preg_match('/Kontext:\s*(\{.*\})/s', $gesehen->user, $m) ? $m[1] : '{}'), true) ?? [];

    expect($kontext)->not->toHaveKey('briefing');
});

// ── 3 · Der Knopf fragt den Prompt seiner Ebene ──────────────────────────────

it('fragt auf der Anrichte-Ebene vk.plating, auf der Produktions-Ebene recipe.steps', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Zander | Beurre blanc', ['is_sales_recipe' => true]);
    ($this->spion)(['steps' => [['phase' => null, 'text' => 'Filet tranchieren.']], 'preparation' => "1. Sauce als Spiegel.\n2. Filet mittig.\n3. Kraeuteroel.\n4. Uebergabe."]);

    Livewire::test(StepEditor::class, ['recipeId' => $gericht->id, 'ebene' => 'produktion'])->call('kiSchritte');
    Livewire::test(StepEditor::class, ['recipeId' => $gericht->id, 'ebene' => 'anrichten'])->call('kiSchritte');

    // Das Audit ist der Beleg: welcher Prompt-Key wurde tatsächlich gefahren.
    // Vorher lief der Knopf auf BEIDEN Ebenen gegen `recipe.steps` — die Anrichte-Instanz
    // bekam damit Fertigstellungs-Schritte, und der Anrichte-Knopf war deshalb aus.
    $features = DB::table('foodalchemist_ai_call_log')->orderBy('id')->pluck('feature')->all();

    expect($features)->toContain('recipe.steps')
        ->and($features)->toContain('vk.plating');
});

it('macht aus dem nummerierten Plating-Markdown einzelne Anrichte-Schritte', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Zander | Beurre blanc', ['is_sales_recipe' => true]);
    ($this->spion)(['preparation' => "## Aufbau\n1. Sauce als Spiegel angiessen.\n2. Filet mittig auflegen.\n3. Kraeuteroel in drei Punkten.\n4. An den Pass geben."]);

    $c = Livewire::test(StepEditor::class, ['recipeId' => $gericht->id, 'ebene' => 'anrichten'])
        ->call('kiSchritte');

    // `vk.plating` liefert EIN Markdown-Feld, `recipe.steps` ein Array — beide landen in
    // derselben Vorschlagsform, damit Vorschau und Übernahme-Knopf identisch bleiben.
    expect($c->get('kiVorschlag.steps'))->toHaveCount(4)
        ->and($c->get('kiVorschlag.steps.0.phase'))->toBe('Aufbau')
        ->and($c->get('kiVorschlag.steps.0.text'))->toBe('Sauce als Spiegel angiessen.');
});

it('schreibt die Anrichte-Uebernahme in die Anrichte-Ebene und deren Herkunftsfeld', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Zander | Beurre blanc', ['is_sales_recipe' => true]);
    ($this->spion)(['preparation' => "1. Sauce als Spiegel.\n2. Filet mittig auflegen."]);

    Livewire::test(StepEditor::class, ['recipeId' => $gericht->id, 'ebene' => 'anrichten'])
        ->call('kiSchritte')
        ->call('kiUebernehmen');

    $frisch = $gericht->fresh();

    // Ebene sauber getrennt: die Produktion bleibt leer, ihre Herkunft unangetastet.
    expect(FoodAlchemistRecipeStep::where('recipe_id', $gericht->id)->ebene('anrichten')->count())->toBe(2)
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $gericht->id)->ebene('produktion')->count())->toBe(0)
        ->and($frisch->plating_source)->toBe('ki')
        ->and($frisch->plating_ai_confidence)->not->toBeNull()
        // Hätte die Übernahme hier `preparation_source` gesetzt, wäre die Zubereitung als
        // „KI-erzeugt" markiert, obwohl niemand sie angefasst hat.
        ->and($frisch->preparation_source)->not->toBe('ki');
});

it('respektiert manuell gepflegtes Anrichten getrennt von der Zubereitung', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Zander | Beurre blanc', [
        'is_sales_recipe' => true, 'plating_source' => 'manual',
    ]);
    ($this->spion)(['preparation' => "1. Sauce als Spiegel."]);

    $c = Livewire::test(StepEditor::class, ['recipeId' => $gericht->id, 'ebene' => 'anrichten'])
        ->call('kiSchritte')
        ->call('kiUebernehmen');

    expect($c->get('fehler'))->toContain('Anrichten ist manuell gepflegt')
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $gericht->id)->ebene('anrichten')->count())->toBe(0);
});

// ── 4 · Nach der Übernahme ist die Vorgabe weg ───────────────────────────────

it('leert das Briefing nach der Uebernahme, behaelt es beim Verwerfen', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    ($this->spion)(['steps' => [['phase' => null, 'text' => 'Knochen roesten.']]]);

    // Übernehmen → Auftrag erledigt, Feld leer. Bliebe es stehen, würde es beim
    // nächsten ✨-Klick unbemerkt wieder mitwirken.
    $c = Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', 'Nur ziehen lassen.')
        ->call('kiSchritte')
        ->call('kiUebernehmen');

    expect($c->get('kiBriefing'))->toBe('')
        ->and($c->get('briefingOffen'))->toBeFalse();

    // Verwerfen → man will nachjustieren, die Vorgabe bleibt stehen.
    $c2 = Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', 'Nur ziehen lassen.')
        ->call('kiSchritte')
        ->call('kiVerwerfen');

    expect($c2->get('kiBriefing'))->toBe('Nur ziehen lassen.');
});

it('speichert das Briefing NICHT am Rezept', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');
    ($this->spion)(['steps' => [['phase' => null, 'text' => 'Knochen roesten.']]]);

    Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', 'Nur ziehen lassen.')
        ->call('kiSchritte')
        ->call('kiUebernehmen');

    // Gegenprobe zum Vorwurf „viertes Wissensfeld": in keiner Rezept-Spalte darf der
    // Briefing-Text auftauchen. Er beschreibt einen Auftrag, kein Rezeptmerkmal.
    $zeile = (array) DB::table('foodalchemist_recipes')->where('id', $rezept->id)->first();
    foreach ($zeile as $wert) {
        expect(is_string($wert) && str_contains($wert, 'Nur ziehen lassen'))->toBeFalse();
    }
});

// ── Diktat ───────────────────────────────────────────────────────────────────

it('haengt das Diktat an das Briefing an, statt es zu ersetzen', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'und die Sauce separat aufschlagen']);
    expect(app(SttServiceContract::class)->transcribe('BLOB'))->toBe('und die Sauce separat aufschlagen');

    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');

    // Echter Upload-Pfad: `set` löst den updated-Hook aus, der Hook delegiert.
    $c = Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', 'Nur ziehen lassen.')
        ->set('briefingAudio', UploadedFile::fake()->create('diktat.webm', 1, 'audio/webm'));

    expect($c->get('kiBriefing'))->toBe('Nur ziehen lassen. und die Sauce separat aufschlagen')
        ->and($c->get('briefingAudio'))->toBeNull()                  // Blob nach Übernahme freigegeben
        ->and($c->get('briefingOffen'))->toBeTrue();                 // Feld aufklappen, damit man gegenlesen kann
});

it('meldet ein leeres Diktat statt still nichts zu tun', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => '']);
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');

    $c = Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('briefingAudio', UploadedFile::fake()->create('diktat.webm', 1, 'audio/webm'));

    expect($c->get('fehler'))->toContain('Diktat war leer')
        ->and($c->get('kiBriefing'))->toBe('');
});

it('fasst ohne Blob nichts an', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Kalbsfond');

    $c = Livewire::test(StepEditor::class, ['recipeId' => $rezept->id])
        ->set('kiBriefing', 'Nur ziehen lassen.')
        ->call('briefingDiktatUebernehmen');

    expect($c->get('kiBriefing'))->toBe('Nur ziehen lassen.')
        ->and($c->get('fehler'))->toBeNull();
});

// ── Prompt-Kontrakt + Fläche ─────────────────────────────────────────────────

it('sagt beiden Schritt-Prompts zu, wie das Briefing zu behandeln ist', function () {
    $prompts = config('foodalchemist.prompts', []);

    foreach (['recipe.steps', 'vk.plating'] as $key) {
        $task = (string) ($prompts[$key]['task'] ?? '');

        expect($task)->toContain('BRIEFING')
            // Vorgabe ja — Kontext-Ersatz nein. Beides muss dort stehen, sonst kippt die
            // Zusage in die eine oder andere Richtung.
            ->and($task)->toContain('VORGABE')
            ->and($task)->toContain('ersetzt')
            // Und die Ebenen-Abgrenzung bleibt stärker als das Briefing.
            ->and($task)->toContain('gilt die Abgrenzung');
    }
});

it('traegt Briefing-Feld und Diktat-Knopf an der Schritt-Flaeche', function () {
    $editor = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/step-editor.blade.php');
    $diktat = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/partials/diktat-knopf.blade.php');

    expect($editor)->toContain('data-briefing-toggle')
        ->and($editor)->toContain('data-briefing-feld')
        ->and($editor)->toContain('partials.diktat-knopf')
        // Der KI-Knopf steht jetzt auf BEIDEN Ebenen — der alte Guard ist weg.
        ->and($editor)->toContain('data-ki-schritte')
        ->and($editor)->not->toContain('@if($ebene !== \\Platform\\FoodAlchemist\\Models\\FoodAlchemistRecipeStep::EBENE_ANRICHTEN)');

    // Der Baustein lädt in die Property, die die Komponente auch transkribiert.
    expect($diktat)->toContain('$wire.upload(@js($audio)')
        ->and($diktat)->toContain('data-diktat');
});
