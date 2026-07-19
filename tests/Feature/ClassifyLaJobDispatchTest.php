<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\ClassifyLaJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Spec 16·S4 — On-demand-Klassifikation: der ASYNC-Vertrag (E3).
 * Verifiziert wird der Dispatch-Rand (Mint stößt Klassifikation an, nie inline)
 * + die Guard-Clauses. Die LLM-INHALTS-Ableitung (gp.suggest → Struktur-Felder)
 * ist provider-gated und hier bewusst NICHT geprüft.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(LaFirstGpService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkLa = fn (string $d) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => $d, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('stößt nach dem Mint eines frischen LA die Klassifikation an (async, nie inline)', function () {
    Queue::fake();
    $la = ($this->mkLa)('Sesampaste');

    $gp = $this->svc->mintFromLa($this->rootTeam, 'Sesampaste');

    expect($gp)->not->toBeNull();
    Queue::assertPushed(ClassifyLaJob::class, fn ($job) => $job->supplierItemId === $la->id
        && $job->teamId === $this->rootTeam->id);
});

it('stößt KEINE Klassifikation an, wenn ein bereits gemappter LA wiederverwendet wird', function () {
    Queue::fake();
    $bestand = $this->makeGp($this->rootTeam, 'Sesampaste: geröstet');
    $la = ($this->mkLa)('Sesampaste');
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $bestand->id,
    ]);

    $this->svc->mintFromLa($this->rootTeam, 'Sesampaste');

    Queue::assertNotPushed(ClassifyLaJob::class);
});

it('Job no-op ohne Absturz, wenn der LA nicht (mehr) existiert', function () {
    $job = new ClassifyLaJob(999999, $this->rootTeam->id);

    expect(fn () => $job->handle(
        app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class),
        app(\Platform\FoodAlchemist\Services\GpNamingService::class),
    ))->not->toThrow(\Throwable::class);

    expect(FoodAlchemistSupplierItemStructure::count())->toBe(0);
});
