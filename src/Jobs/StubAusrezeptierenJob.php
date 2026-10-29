<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * Import-Unterrezept ausrezeptieren (Dominique 29.10.): der Import legt für eine Basiskomponente ohne Bestand („Aroma: Nussbutter",
 * „Geflügelfond") eine leere Hülle (status `stub`) an. Dieser Job füllt sie mit dem Basisrezept-Generator — Zutaten, Zubereitung,
 * Matching —, ohne ein Duplikat anzulegen (`stub_fuellen_id`). Das Rezept bleibt ENTWURF (`draft`): freigegeben wird von Hand.
 *
 * Fail-soft: scheitert der Lauf, bleibt die Hülle stehen (Signal «rezept_sub_stub_offen» führt sie weiter). Kein Auto-Retry (KI-Kosten).
 */
class StubAusrezeptierenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $timeout = 540;

    public int $tries = 1;

    /** @param array<string, mixed> $parameter Leitplanken des Imports (convenience, frische, bio, diaet_hart …) */
    public function __construct(
        public int $teamId,
        public int $userId,
        public int $stubId,
        public ?int $elternId = null,
        public array $parameter = [],
    ) {}

    /** Brief für den Generator: der Name der Hülle plus der Zweck im Eltern-Rezept (damit „Nussbutter" zum Püree passt). */
    public static function brief(string $stubName, ?string $elternName): string
    {
        return 'Unterrezept «' . $stubName . '»'
            . ($elternName !== null && $elternName !== '' ? ' für das Rezept «' . $elternName . '»' : '')
            . '. Nur dieses Unterrezept erzeugen, nüchtern und nach Basisrezept-Regelwerk.';
    }

    /**
     * Die Hülle, die der Generator befüllen soll (Parameter `stub_fuellen_id`): nur ein EIGENER Stub ohne Zutaten, nie im Gericht-Modus —
     * alles andere läuft den normalen Weg (neues Rezept).
     *
     * @param  array<string, mixed>  $parameter
     */
    public static function huelle(Team $team, array $parameter, bool $vkModus): ?FoodAlchemistRecipe
    {
        if ($vkModus || ! isset($parameter['stub_fuellen_id'])) {
            return null;
        }
        $stub = FoodAlchemistRecipe::query()->where('team_id', $team->id)->where('status', 'stub')->find((int) $parameter['stub_fuellen_id']);

        return $stub !== null && ! $stub->ingredients()->whereNull('deleted_at')->exists() ? $stub : null;
    }

    /** Hülle wird Entwurf: Kopf aus dem KI-Vorschlag; Zutaten/Schritte schreibt der Generator danach wie bei jedem neuen Rezept. */
    public static function alsEntwurf(FoodAlchemistRecipe $stub, array $kiRezept, ?string $createdVia): FoodAlchemistRecipe
    {
        $stub->update([
            'status' => 'draft',
            'created_via' => $createdVia ?? 'import_ki_unterrezept',
            'description' => $kiRezept['description'] ?? null,
            'taste_direction' => in_array($kiRezept['taste_direction'] ?? null, \Platform\FoodAlchemist\Services\RecipeService::TASTE_DIRECTIONS, true) ? $kiRezept['taste_direction'] : null,
        ]);

        return $stub;
    }

    public function handle(RecipeGeneratorService $generator): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $stub = $team === null ? null : FoodAlchemistRecipe::query()->where('team_id', $this->teamId)->where('status', 'stub')->find($this->stubId);
        if ($team === null || $user === null || $stub === null) {
            return;   // nichts (mehr) zu füllen: schon ausrezeptiert, gelöscht oder fremd
        }
        Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch/DNA/Call-Log)

        $eltern = $this->elternId !== null ? FoodAlchemistRecipe::query()->where('team_id', $this->teamId)->find($this->elternId) : null;
        try {
            $generator->generiere(
                $team,
                self::brief((string) $stub->name, $eltern?->name),
                array_merge(array_intersect_key($this->parameter, array_flip(['convenience', 'frische', 'bio', 'diaet_hart', 'allergen_nogo', 'niveau', 'sektor'])), [
                    'stub_fuellen_id' => (int) $stub->id,
                    'titel_vorgabe' => (string) $stub->name,   // der Name der Hülle gilt, nicht der KI-Vorschlag
                ]),
                createdVia: 'import_ki_unterrezept',
            );
        } catch (\Throwable $e) {
            Log::warning('[StubAusrezeptierenJob] Ausrezeptieren fehlgeschlagen', ['stub' => $this->stubId, 'error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('[StubAusrezeptierenJob] harter Abbruch', ['stub' => $this->stubId, 'error' => $e->getMessage()]);
    }
}
