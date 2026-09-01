<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/** Überarbeitet nur den textlichen Gericht-Bauplan; erzeugt bewusst noch kein Rezept. */
class ReviseDishProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $stepId,
        public int $ideaId,
        public string $feedback,
    ) {}

    public function handle(AiGatewayService $ai, PlanningCascadeService $cascade): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $step = FoodAlchemistCascadeRunStep::find($this->stepId);
        $idee = FoodAlchemistDishIdea::where('team_id', $this->teamId)->find($this->ideaId);
        if ($team === null || $step === null || $idee === null || $cascade->istAbgebrochen((int) $step->cascade_run_id)) {
            return;
        }
        if ($user !== null) {
            Auth::login($user);
        }

        try {
            $proposal = $ai->propose('planning.dish_proposal_revise', [
                'bestehend' => [
                    'titel' => $idee->title,
                    'beschreibung' => $idee->description,
                    'komponenten' => (array) (($idee->source_meta ?? [])['komponenten'] ?? []),
                ],
                'feedback' => $this->feedback,
            ], ['target_table' => 'foodalchemist_dish_ideas', 'target_id' => $idee->id]);
            $w = $proposal->werte;
            $titel = trim((string) ($w['titel'] ?? '')) ?: (string) $idee->title;
            $komponenten = collect((array) ($w['komponenten'] ?? []))->filter('is_array')->map(function (array $k): ?array {
                $name = trim((string) ($k['name'] ?? ''));
                return $name === '' ? null : [
                    'name' => Str::limit($name, 120, ''),
                    'funktion' => $this->text($k['funktion'] ?? null),
                    'herstellung' => $this->text($k['herstellung'] ?? null),
                ];
            })->filter()->take(8)->values()->all();
            $meta = $idee->source_meta ?? [];
            $meta['komponenten'] = $komponenten;
            $meta['letztes_feedback'] = Str::limit($this->feedback, 500, '');
            $meta['revision_call_log_id'] = $proposal->callLogId;
            $idee->update([
                'title' => Str::limit($titel, 160, ''),
                'description' => $this->text($w['beschreibung'] ?? $idee->description),
                'source_meta' => $meta,
            ]);
            if (! $cascade->istAbgebrochen((int) $step->cascade_run_id)) {
                $step->update([
                    'label' => Str::limit($titel, 120, ''),
                    'status' => 'geplant',
                    'error' => null,
                    'context_snapshot' => [
                        'dish_idea_id' => (int) $idee->id,
                        'beschreibung' => (string) ($idee->description ?? ''),
                        'komponenten' => $komponenten,
                    ],
                ]);
                $cascade->recomputeRunStatus((int) $step->cascade_run_id);
            }
        } catch (\Throwable $e) {
            $step->update(['status' => 'geplant', 'error' => Str::limit('Überarbeitung fehlgeschlagen: '.$e->getMessage(), 500, '')]);
            $cascade->recomputeRunStatus((int) $step->cascade_run_id);
        }
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : Str::limit($text, 500, '');
    }
}
