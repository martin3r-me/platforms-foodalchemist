<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 Paket B-1/B-2: die Schritte, deren Ziel KEINE Spalte am Rezept ist, sondern eine Zeile
 * in einer Nebentabelle — Regeneration (Gesamt-Zeile, Spec 51: gehört dem Basisrezept), Garverlust
 * je Zutat (Regelwerk §6 F6.5), Zutaten-Rollen am Gericht — plus Servier-Vehikel und Geschmack am
 * Gericht. Zu beweisen: die Lücke wird relational erkannt, der Vorschlag landet nur in LEEREN
 * Zeilen (Override-First zeilenweise), die Provenienz ist `ki`, und „kalt servieren" ist eine
 * Entscheidung, keine Lücke.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(BulkEnrichService::class);

    $this->geraetId = DB::table('foodalchemist_vocab_regeneration_devices')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'slug' => 'fixture_konvektomat',
        'name' => 'Konvektomat (Fixture)', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->vehikelId = DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'slug' => 'fixture_teller_tief',
        'name' => 'Teller tief (Fixture)', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-b1b2', 'name' => 'Jus: Rind', 'status' => 'draft',
    ]);
    $this->zutatA = $this->makeIngredient($this->basis, 'Rinderfond', $this->makeGp($this->rootTeam, 'Rinderfond-b1'), '2000', 1);
    $this->zutatB = $this->makeIngredient($this->basis, 'Schalotte', $this->makeGp($this->rootTeam, 'Schalotte-b1'), '200', 2);
    $this->zutatB->update(['cooking_loss_pct' => 10, 'cooking_loss_source' => 'manual']);   // schon entschieden

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-b1b2', 'name' => 'TEL: Rinderrücken | Jus',
        'status' => 'draft', 'is_sales_recipe' => true, 'sales_quantity_per_unit_g' => 180,
    ]);
    $this->vkZutatA = $this->makeIngredient($this->vk, 'Rinderrücken', $this->makeGp($this->rootTeam, 'Rinderruecken-b1'), '180', 1);
    $this->vkZutatB = $this->makeIngredient($this->vk, 'Jus', $this->makeGp($this->rootTeam, 'Jus-b1'), '50', 2);
    $this->vkZutatB->update(['role' => 'komponente']);                                        // schon entschieden

    // Fake antwortet je Aufgabe — Marker sind die STRUKTURELLEN Kontext-Keys der Prompts, nicht Prompt-Wörter.
    $geraetId = $this->geraetId;
    $vehikelId = $this->vehikelId;
    $ids = ['jus' => $this->zutatA->id, 'schalotte' => $this->zutatB->id, 'ruecken' => $this->vkZutatA->id, 'vkjus' => $this->vkZutatB->id];
    app()->singleton(FakeAiProvider::class, fn () => new class($geraetId, $vehikelId, $ids) extends FakeAiProvider
    {
        public function __construct(private int $geraetId, private int $vehikelId, private array $ids)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $user = collect($messages)->last()['content'];
            $werte = match (true) {
                str_contains($user, '"geraete"') => ['kalt' => false, 'geraet_id' => $this->geraetId, 'temp_c' => 140, 'duration_min' => 12, 'core_temp_c' => 65, 'note' => 'Abgedeckt regenerieren.'],
                // Garverlust: die Schalotte hat schon 10 → der Fake „vergisst" sie nicht, der Service muss sie schützen.
                str_contains($user, '"verluste"') => ['verluste' => [$this->ids['jus'] => 50, $this->ids['schalotte'] => 99]],
                // Rollen: die Jus hat schon `komponente` → der Fake versucht sie umzuschreiben.
                str_contains($user, '"vokabular"') => ['rollen' => [$this->ids['ruecken'] => 'aroma_treiber', $this->ids['vkjus'] => 'garnitur']],
                str_contains($user, '"vehikel"') => ['servier_vehikel_id' => $this->vehikelId],   // Registry-Schema vk.servier_vehikel
                str_contains($user, '"taste_direction"') => ['taste_direction' => 'herzhaft'],
                default => ['description' => 'Kräftige Jus.'],
            };

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.85, 'reasoning' => 'Fixture']), 'model' => 'fake-b1b2', 'usage' => []];
        }
    });
});

// ── Lücken-Erkennung ──────────────────────────────────────────────────────────────────

it('B-1/B-2: Regeneration und Garverlust sind Lücken des Basisrezepts — relational, nicht über eine Spalte', function () {
    $offen = $this->svc->luecken($this->basis, BulkEnrichService::SCHRITTE);
    expect($offen)->toContain('regeneration')->toContain('garverlust');

    // Gesamt-Zeile „kalt" (device NULL) = Entscheidung → regeneration ist keine Lücke mehr (Spec-51-Vertrag).
    $this->makeRegenerationRow($this->rootTeam, $this->basis);
    // Der letzte offene Verlust wird gesetzt → garverlust ist keine Lücke mehr.
    $this->zutatA->update(['cooking_loss_pct' => 50, 'cooking_loss_source' => 'manual']);

    $offen = $this->svc->luecken($this->basis->fresh(), BulkEnrichService::SCHRITTE);
    expect($offen)->not->toContain('regeneration')->not->toContain('garverlust');
});

it('B-1: eine Regenerations-Zeile einer KOMPONENTE (ingredient_id gesetzt) ist keine Gesamt-Entscheidung', function () {
    $this->makeRegenerationRow($this->rootTeam, $this->basis, ['ingredient_id' => $this->zutatA->id, 'component_label' => 'Fond']);

    expect($this->svc->luecken($this->basis, BulkEnrichService::SCHRITTE))->toContain('regeneration');
});

it('B-1: am Gericht sind Rollen, Servier-Vehikel und Geschmack Lücken — ohne offene Zutat keine Rollen-Lücke', function () {
    $offen = $this->svc->luecken($this->vk, BulkEnrichService::SCHRITTE_VK);
    expect($offen)->toContain('rollen')->toContain('servier_vehikel')->toContain('geschmack');

    $this->vkZutatA->update(['role' => 'komponente']);
    expect($this->svc->luecken($this->vk->fresh(), BulkEnrichService::SCHRITTE_VK))->not->toContain('rollen');
});

// ── Basisrezept: Regeneration + Garverlust ────────────────────────────────────────────

it('B-1: der Regenerations-Vorschlag wird als Gesamt-Zeile mit Provenienz ki übernommen — und ist danach keine Lücke', function () {
    $runId = $this->svc->starte($this->rootTeam, [$this->basis->id], ['regeneration']);
    expect($this->svc->status($this->rootTeam, $runId)->status)->toBe(BulkRunStatus::Done);

    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'regeneration')->first();
    expect($prop->status)->toBe('offen')
        ->and(DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->basis->id)->count())->toBe(0);   // GL-07: nichts auto-persistiert

    expect($this->svc->uebernehmen($this->rootTeam, $prop->id))->toBeTrue();

    $zeile = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->basis->id)->whereNull('deleted_at')->first();
    expect($zeile)->not->toBeNull()
        ->and($zeile->ingredient_id)->toBeNull()
        ->and($zeile->component_label)->toBe('Gesamt')
        ->and((int) $zeile->device_vocab_id)->toBe($this->geraetId)
        ->and((int) $zeile->temp_c)->toBe(140)
        ->and((int) $zeile->duration_min)->toBe(12)
        ->and($zeile->source)->toBe('ki')
        ->and((float) $zeile->ai_confidence)->toBe(0.85)
        ->and($this->svc->luecken($this->basis->fresh(), ['regeneration']))->toBe([]);
});

it('B-1: „kalt servieren" ist eine gültige Entscheidung — Gesamt-Zeile ohne Gerät', function () {
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['kalt' => true, 'geraet_id' => null, 'note' => 'Kalt anrichten.'], 'confidence' => 0.9]), 'model' => 'fake', 'usage' => []];
        }
    });

    $runId = $this->svc->starte($this->rootTeam, [$this->basis->id], ['regeneration']);
    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'regeneration')->first();
    expect($prop->status)->toBe('offen')
        ->and($this->svc->uebernehmen($this->rootTeam, $prop->id))->toBeTrue();

    $zeile = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->basis->id)->first();
    expect($zeile->device_vocab_id)->toBeNull()
        ->and($zeile->temp_c)->toBeNull()
        ->and($zeile->note)->toBe('Kalt anrichten.')
        ->and($zeile->source)->toBe('ki');
});

it('B-1: ein Gerät außerhalb des sichtbaren Katalogs oder weder kalt noch Gerät → Vorschlag leer', function () {
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['kalt' => false, 'geraet_id' => 999999, 'temp_c' => 120], 'confidence' => 0.9]), 'model' => 'fake', 'usage' => []];
        }
    });

    $runId = $this->svc->starte($this->rootTeam, [$this->basis->id], ['regeneration']);
    expect(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'regeneration')->value('status'))->toBe('leer');
});

it('B-1: Override-First — steht schon eine Gesamt-Zeile, wird der Vorschlag nicht übernommen und bleibt offen', function () {
    $runId = $this->svc->starte($this->rootTeam, [$this->basis->id], ['regeneration']);
    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'regeneration')->first();

    $manuell = $this->makeRegenerationRow($this->rootTeam, $this->basis);   // Mensch entscheidet zwischen Lauf und Review: kalt

    expect($this->svc->uebernehmen($this->rootTeam, $prop->id))->toBeFalse()
        ->and(DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->basis->id)->count())->toBe(1)
        ->and(DB::table('foodalchemist_recipe_regenerations')->find($manuell)->source)->toBe('manual')
        ->and(DB::table('foodalchemist_bulk_proposals')->find($prop->id)->status)->toBe('offen');
});

it('B-2: der Garverlust landet nur in LEEREN Zutaten-Zeilen, mit Provenienz ki, und der Yield wird nachgerechnet', function () {
    $runId = $this->svc->starte($this->rootTeam, [$this->basis->id], ['garverlust']);
    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'garverlust')->first();

    // Nur die offene Zeile steht im Vorschlag — die Schalotte (manuell 10) gar nicht.
    $wert = json_decode($prop->value, true);
    expect($prop->status)->toBe('offen')
        ->and(array_map('intval', array_keys($wert['verluste'])))->toBe([$this->zutatA->id])
        ->and((float) $wert['verluste'][$this->zutatA->id])->toBe(50.0);

    expect($this->svc->uebernehmen($this->rootTeam, $prop->id))->toBeTrue();

    $a = DB::table('foodalchemist_recipe_ingredients')->find($this->zutatA->id);
    $b = DB::table('foodalchemist_recipe_ingredients')->find($this->zutatB->id);
    expect((float) $a->cooking_loss_pct)->toBe(50.0)
        ->and($a->cooking_loss_source)->toBe('ki')
        ->and((float) $b->cooking_loss_pct)->toBe(10.0)                    // die manuelle Entscheidung bleibt
        ->and($b->cooking_loss_source)->toBe('manual')
        ->and($this->svc->luecken($this->basis->fresh(), ['garverlust']))->toBe([]);

    // Recompute: 2000 g × 0,5 + 200 g × 0,9 = 1,18 kg (F6.2 mit Zeilen-Verlust), nicht 2,2 kg.
    expect(round((float) $this->basis->fresh()->yield_kg, 2))->toBe(1.18);
});

it('B-2: ohne offene Zutat kein Provider-Call — der Vorschlag ist leer', function () {
    $this->zutatA->update(['cooking_loss_pct' => 50, 'cooking_loss_source' => 'manual']);
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->never();
    });

    $runId = app(BulkEnrichService::class)->starte($this->rootTeam, [$this->basis->id], ['garverlust']);
    expect(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'garverlust')->value('status'))->toBe('leer');
});

// ── Gericht: Rollen, Servier-Vehikel, Geschmack ───────────────────────────────────────

it('B-1: Rollen landen nur in Zeilen ohne Rolle — die manuelle Rolle der Jus überlebt den Fake', function () {
    $runId = $this->svc->starteVk($this->rootTeam, [$this->vk->id]);
    expect((int) $this->svc->status($this->rootTeam, $runId)->failed)->toBe(0);

    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'rollen')->first();
    expect($prop->status)->toBe('offen')
        ->and($this->svc->uebernehmen($this->rootTeam, $prop->id))->toBeTrue()
        ->and(DB::table('foodalchemist_recipe_ingredients')->find($this->vkZutatA->id)->role)->toBe('aroma_treiber')
        ->and(DB::table('foodalchemist_recipe_ingredients')->find($this->vkZutatB->id)->role)->toBe('komponente');
});

it('B-1: Servier-Vehikel kommt mit Lineage ki ans Gericht und in die Standard-Darreichung; Geschmack setzt das Enum', function () {
    $runId = $this->svc->starteVk($this->rootTeam, [$this->vk->id]);
    $props = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->whereIn('field', ['servier_vehikel', 'geschmack'])->get()->keyBy('field');
    expect($props['servier_vehikel']->status)->toBe('offen')
        ->and($props['geschmack']->status)->toBe('offen')
        ->and($this->vk->fresh()->serving_vehicle_vocab_id)->toBeNull();       // GL-07

    expect($this->svc->uebernehmen($this->rootTeam, $props['servier_vehikel']->id))->toBeTrue()
        ->and($this->svc->uebernehmen($this->rootTeam, $props['geschmack']->id))->toBeTrue();

    $r = $this->vk->fresh();
    expect((int) $r->serving_vehicle_vocab_id)->toBe($this->vehikelId)
        ->and($r->serving_vehicle_source)->toBe('ki')
        ->and((float) $r->serving_vehicle_ai_confidence)->toBe(0.85)
        ->and($r->taste_direction)->toBe('herzhaft');

    $standard = DB::table('foodalchemist_recipe_presentations')->where('recipe_id', $r->id)->where('is_standard', true)->first();
    expect($standard)->not->toBeNull()
        ->and((int) $standard->serving_vehicle_vocab_id)->toBe($this->vehikelId);
});

it('B-1: Servier-Vehikel — manuell gesetzt gewinnt, ein Vehikel außerhalb des Katalogs wird nicht übernommen', function () {
    $this->vk->update(['serving_vehicle_vocab_id' => $this->vehikelId, 'serving_vehicle_source' => 'manual']);
    expect($this->svc->luecken($this->vk->fresh(), ['servier_vehikel']))->toBe([]);   // gefüllt = keine Lücke

    // Direkter Lauf trotz gefülltem Feld (starteVk schneidet nicht) → Override-First greift beim Übernehmen.
    $runId = $this->svc->starte($this->rootTeam, [$this->vk->id], ['servier_vehikel']);
    $prop = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'servier_vehikel')->first();
    expect($this->svc->uebernehmen($this->rootTeam, $prop->id))->toBeFalse()
        ->and($this->vk->fresh()->serving_vehicle_source)->toBe('manual');
});

it('B-1: Rollen/Vehikel auf einem Basisrezept sind ein ehrlicher Fehler, keine stille Lücke', function () {
    $runId = $this->svc->starte($this->rootTeam, [$this->basis->id], ['rollen', 'servier_vehikel']);
    expect((int) $this->svc->status($this->rootTeam, $runId)->failed)->toBe(1)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->whereNotNull('error')->count())->toBe(2);
});
