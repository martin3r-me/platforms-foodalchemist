<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Services\TeamContext;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Merkt sich, welches Team der Job beim Ausführen sieht (Spec 84 §3.5 Test 2/5/6). */
final class Spec84TeamMerkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<?int> */
    public static array $gesehen = [];

    public function handle(): void
    {
        self::$gesehen[] = FaTeam::aktuellId();
    }
}

/** Minimal-Tool: merkt sich FaTeam beim Ausführen (Spec 84 §3.5 Test 8). */
final class Spec84TeamMerkTool implements ToolContract
{
    /** @var list<?int> */
    public static array $gesehen = [];

    public function getName(): string { return 'foodalchemist.spec84_team_merk.GET'; }

    public function getDescription(): string { return 'Test-Tool'; }

    public function getSchema(): array { return ['type' => 'object', 'properties' => []]; }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        self::$gesehen[] = FaTeam::aktuellId();

        return ToolResult::success([]);
    }
}

/**
 * Spec 84 A-1 · FaTeam ist die eine Stelle für „welches Team betrachtet gerade?“. Vorrang: ausdrücklich (imTeam / Job-Payload)
 * > Core-Override (MCP) > UI-Team. Jobs tragen das Team, das beim Versand galt (dehydrating-Hook).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Chef Root', 'owner');   // UI-Team = rootTeam
    Spec84TeamMerkJob::$gesehen = [];
    Spec84TeamMerkTool::$gesehen = [];
    TeamContext::clear();
});

afterEach(fn () => TeamContext::clear());

it('ohne Nutzer und ohne Kontext: kein Team', function () {
    expect(FaTeam::aktuell())->toBeNull()->and(FaTeam::ausdruecklich())->toBeFalse();
});

it('Vorrang: UI-Team < Core-Override < imTeam; imTeam stellt danach zurück, auch verschachtelt und bei Ausnahme', function () {
    $this->actingAs($this->user);
    expect(FaTeam::aktuellId())->toBe($this->rootTeam->id);

    TeamContext::setTeam($this->childA);                                              // MCP nach core.team.switch
    expect(FaTeam::aktuellId())->toBe($this->childA->id);

    FaTeam::imTeam($this->childB, function () {
        expect(FaTeam::aktuellId())->toBe($this->childB->id);
        FaTeam::imTeam($this->rootTeam, fn () => expect(FaTeam::aktuellId())->toBe($this->rootTeam->id));
        expect(FaTeam::aktuellId())->toBe($this->childB->id);
    });
    expect(FaTeam::aktuellId())->toBe($this->childA->id);

    expect(fn () => FaTeam::imTeam($this->childB, fn () => throw new RuntimeException('Abbruch')))->toThrow(RuntimeException::class);
    expect(FaTeam::aktuellId())->toBe($this->childA->id)->and(FaTeam::ausdruecklich())->toBeFalse();
});

it('imTeam(null) heißt ausdrücklich ohne Team — auch wenn ein Nutzer angemeldet ist', function () {
    $this->actingAs($this->user);

    FaTeam::imTeam(null, fn () => expect(FaTeam::aktuell())->toBeNull()->and(FaTeam::ausdruecklich())->toBeTrue());
    expect(FaTeam::aktuellId())->toBe($this->rootTeam->id);
});

it('Transport: der Payload trägt das beim Versand geltende Team (UI, Override, imTeam), der laufende Request bleibt unberührt', function () {
    $this->actingAs($this->user);
    $hidden = fn () => unserialize(Context::dehydrate()['hidden'][FaTeam::SCHLUESSEL]);

    expect($hidden())->toBe($this->rootTeam->id)
        ->and(FaTeam::ausdruecklich())->toBeFalse();                                 // dehydrating schreibt nur in die Kopie

    TeamContext::setTeam($this->childA);
    expect($hidden())->toBe($this->childA->id);

    FaTeam::imTeam($this->childB, fn () => expect($hidden())->toBe($this->childB->id));
});

it('Test 2/5: Job rechnet auf das Versand-Team — nach Override (MCP) und je Team aus einem Befehl', function () {
    $this->actingAs($this->user);

    TeamContext::setTeam($this->childA);                                              // MCP nach team.switch
    FaTeam::imTeam(FaTeam::aktuell(), fn () => Spec84TeamMerkJob::dispatch());        // so versendet die Tool-Hülle
    TeamContext::clear();

    foreach ([$this->childA, $this->childB] as $team) {                               // Befehl verteilt an zwei Teams
        FaTeam::imTeam($team, fn () => Spec84TeamMerkJob::dispatch());
    }

    expect(Spec84TeamMerkJob::$gesehen)->toBe([$this->childA->id, $this->childA->id, $this->childB->id])
        ->and(FaTeam::aktuellId())->toBe($this->rootTeam->id);                       // Request danach wieder beim UI-Team
});

it('Test 6: Worker — ein Job ohne Team-Payload erbt nichts vom vorigen Job', function () {
    // Simuliert den Worker: Laravel hydratisiert je Job aus dem Payload (vorher flush), scoped Instanzen werden vergessen.
    Context::hydrate(['data' => [], 'hidden' => [FaTeam::SCHLUESSEL => serialize($this->childA->id)]]);
    app()->forgetScopedInstances();
    expect(FaTeam::aktuellId())->toBe($this->childA->id);

    Context::hydrate(null);                                                           // nächster Job ohne Payload-Kontext
    app()->forgetScopedInstances();
    expect(FaTeam::aktuellId())->toBeNull();
});

it('Test 8: Tool-Loop — jedes Tool rechnet auf sein Team, danach steht der Request wieder auf dem UI-Team', function () {
    $this->actingAs($this->user);
    $huelle = new FaRechteToolHuelle(new Spec84TeamMerkTool());

    $huelle->execute([], new ToolContext($this->user, $this->childA));
    $huelle->execute([], new ToolContext($this->user, $this->childB));

    expect(Spec84TeamMerkTool::$gesehen)->toBe([$this->childA->id, $this->childB->id])
        ->and(FaTeam::aktuellId())->toBe($this->rootTeam->id)
        ->and(FaTeam::ausdruecklich())->toBeFalse();
});
