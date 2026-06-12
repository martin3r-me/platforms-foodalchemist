<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * M7-06 / D-5 §4.4 + V-15: Bulk-Autopilot — der Job erzeugt VORSCHLÄGE in
 * die Review-Liste (nie Auto-Persistenz, GL-07); Übernahme einzeln/alle
 * bleibt interaktiv und respektiert Override-First. Schritte hier: die
 * implementierten Feld-KIs (beschreibung · kategorie · geschmack) — weitere
 * Orchestrator-Schritte docken über SCHRITTE an, sobald ihre Accept-Pfade
 * existieren (Registry-Prompts stehen seit M7-04).
 */
class BulkEnrichService
{
    public const SCHRITTE = ['beschreibung', 'kategorie', 'geschmack'];

    public function __construct(private AiGatewayService $ki)
    {
    }

    /** Startet einen Run (Job ist queued; Sandbox/Tests: sync). */
    public function starte(Team $team, array $recipeIds, array $schritte = self::SCHRITTE): int
    {
        $ids = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $recipeIds)->pluck('id')->all();
        DB::table('foodalchemist_bulk_runs')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $team->id, 'user_id' => Auth::id(),
            'typ' => 'enrich', 'status' => 'running', 'total' => count($ids),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $runId = (int) DB::getPdo()->lastInsertId();

        \Platform\FoodAlchemist\Jobs\BulkEnrichJob::dispatch($runId, $team->id, $ids, $schritte);

        return $runId;
    }

    /** Job-Kern: ein Rezept × Schritte → Vorschläge (kein Fach-Write). */
    public function verarbeiteRezept(Team $team, int $runId, int $recipeId, array $schritte): void
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        $fehler = false;
        foreach ($r === null ? [] : $schritte as $feld) {
            try {
                $vorschlag = $this->proposeFeld($team, $r, $feld);
                DB::table('foodalchemist_bulk_proposals')->insert([
                    'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $r->id, 'feld' => $feld,
                    'wert' => json_encode($vorschlag['wert']),
                    'confidence' => $vorschlag['confidence'],
                    'begruendung' => $vorschlag['begruendung'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    'status' => $vorschlag['wert'] === null || $vorschlag['wert'] === '' ? 'leer' : 'offen',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                DB::table('foodalchemist_bulk_proposals')->insert([
                    'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $recipeId, 'feld' => $feld,
                    'status' => 'leer', 'fehler' => mb_strimwidth($e->getMessage(), 0, 500),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->update([
            'done' => DB::raw('done + 1'),
            'fehler' => DB::raw('fehler + ' . ($fehler || $r === null ? 1 : 0)),
            'updated_at' => now(),
        ]);
        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->whereColumn('done', '>=', 'total')
            ->update(['status' => 'done', 'updated_at' => now()]);
    }

    /** @return array{wert: mixed, confidence: ?float, begruendung: ?string, call_log_id: ?int} */
    private function proposeFeld(Team $team, FoodAlchemistRecipe $r, string $feld): array
    {
        [$key, $kontext, $extract] = match ($feld) {
            'beschreibung' => ['recipe.beschreibung',
                ['name' => $r->name, 'beschreibung' => $r->beschreibung, 'zutaten' => $r->ingredients()->whereNull('deleted_at')->pluck('display_name')->all()],
                fn (array $w) => $w['beschreibung'] ?? null],
            'kategorie' => ['recipe.kategorie',
                ['name' => $r->name, 'kategorie_id' => $r->kategorie_id,
                    'kategorien' => FoodAlchemistRecipeCategory::orderBy('id')->limit(200)->pluck('bezeichnung', 'id')->all()],
                fn (array $w) => $w['kategorie_id'] ?? null],
            'geschmack' => ['recipe.geschmack',
                ['name' => $r->name, 'geschmacksrichtung' => $r->geschmacksrichtung],
                fn (array $w) => $w['geschmacksrichtung'] ?? null],
            default => throw new \RuntimeException("Unbekannter Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);

        return [
            'wert' => $extract($p->werte),
            'confidence' => $p->confidence,
            'begruendung' => $p->begruendung,
            'call_log_id' => $p->callLogId,
        ];
    }

    /** Review: EIN Vorschlag übernehmen (Override-First, Lineage ki, Stempel). */
    public function uebernehmen(Team $team, int $proposalId): bool
    {
        $prop = DB::table('foodalchemist_bulk_proposals')->where('id', $proposalId)->where('status', 'offen')->first();
        if ($prop === null) {
            return false;
        }
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($prop->recipe_id);
        if ($r === null) {
            return false;
        }
        $wert = json_decode((string) $prop->wert, true);

        $update = match ($prop->feld) {
            'beschreibung' => $r->beschreibung_quelle === 'manual' ? null
                : ['beschreibung' => (string) $wert, 'beschreibung_quelle' => 'ki', 'beschreibung_ai_confidence' => $prop->confidence],
            'kategorie' => $r->kategorie_quelle === 'manual' || FoodAlchemistRecipeCategory::find((int) $wert) === null ? null
                : ['kategorie_id' => (int) $wert, 'kategorie_quelle' => 'ki', 'kategorie_ai_confidence' => $prop->confidence],
            'geschmack' => in_array($wert, ['suess', 'herzhaft', 'neutral'], true)
                ? ['geschmacksrichtung' => $wert] : null,             // Auto-Apply-Ausnahme-Feld (GL-07 §4.3), kein Lineage-Trio
            default => null,
        };
        if ($update === null) {
            return false;                                            // Override-First / ungültig — Vorschlag bleibt offen
        }

        $r->update($update);
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        DB::table('foodalchemist_bulk_proposals')->where('id', $proposalId)->update(['status' => 'uebernommen', 'updated_at' => now()]);

        return true;
    }

    /** Review: »Alle übernehmen« eines Runs — Override-First gilt je Zeile. */
    public function alleUebernehmen(Team $team, int $runId): int
    {
        $n = 0;
        foreach (DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('status', 'offen')->orderBy('id')->pluck('id') as $id) {
            $n += $this->uebernehmen($team, (int) $id) ? 1 : 0;
        }

        return $n;
    }

    public function verwerfen(Team $team, int $proposalId): void
    {
        $prop = DB::table('foodalchemist_bulk_proposals')->where('id', $proposalId)->first();
        if ($prop !== null && FoodAlchemistRecipe::visibleToTeam($team)->whereKey($prop->recipe_id)->exists()) {
            DB::table('foodalchemist_bulk_proposals')->where('id', $proposalId)->update(['status' => 'verworfen', 'updated_at' => now()]);
            $this->ki->stempleRejected($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        }
    }

    /** Fortschritts-Polling (Browser-Pill). */
    public function status(Team $team, int $runId): ?object
    {
        return DB::table('foodalchemist_bulk_runs')->where('id', $runId)->where('team_id', $team->id)->first();
    }

    public function offeneVorschlaege(Team $team, int $runId): int
    {
        return DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('team_id', $team->id)
            ->where('status', 'offen')->count();
    }
}
