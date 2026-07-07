<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * M7-06 / D-5 §4.4 + V-15: Bulk-Autopilot — der Job erzeugt VORSCHLÄGE in
 * die Review-Liste (nie Auto-Persistenz, GL-07); Übernahme einzeln/alle
 * bleibt interaktiv und respektiert Override-First. Schritte hier: die
 * implementierten Feld-KIs (description · kategorie · geschmack) — weitere
 * Orchestrator-Schritte docken über SCHRITTE an, sobald ihre Accept-Pfade
 * existieren (Registry-Prompts stehen seit M7-04).
 */
class BulkEnrichService
{
    public const SCHRITTE = ['description', 'category', 'geschmack'];

    /** GP-Bulk-Autopilot-Schritte (Feld-KIs mit vorhandenem Accept-Pfad). */
    public const SCHRITTE_GP = ['condition', 'tags', 'allergene', 'naehrwerte'];

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
            'type' => 'enrich', 'status' => 'running', 'total' => count($ids),
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
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $r->id, 'field' => $feld,
                    'value' => json_encode($vorschlag['value']),
                    'confidence' => $vorschlag['confidence'],
                    'reasoning' => $vorschlag['reasoning'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    'status' => $vorschlag['value'] === null || $vorschlag['value'] === '' ? 'leer' : 'offen',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                DB::table('foodalchemist_bulk_proposals')->insert([
                    'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $recipeId, 'field' => $feld,
                    'status' => 'leer', 'error' => mb_strimwidth($e->getMessage(), 0, 500),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->update([
            'done' => DB::raw('done + 1'),
            'failed' => DB::raw('failed + ' . ($fehler || $r === null ? 1 : 0)),
            'updated_at' => now(),
        ]);
        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->whereColumn('done', '>=', 'total')
            ->update(['status' => 'done', 'updated_at' => now()]);
    }

    /** @return array{wert: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int} */
    private function proposeFeld(Team $team, FoodAlchemistRecipe $r, string $feld): array
    {
        [$key, $kontext, $extract] = match ($feld) {
            'description' => ['recipe.description',
                ['name' => $r->name, 'description' => $r->description, 'zutaten' => $r->ingredients()->whereNull('deleted_at')->pluck('display_name')->all()],
                fn (array $w) => $w['description'] ?? null],
            'category' => ['recipe.category',
                ['name' => $r->name, 'category_id' => $r->category_id,
                    'kategorien' => FoodAlchemistRecipeCategory::orderBy('id')->limit(200)->pluck('label', 'id')->all()],
                fn (array $w) => $w['category_id'] ?? null],
            'geschmack' => ['recipe.geschmack',
                ['name' => $r->name, 'taste_direction' => $r->taste_direction],
                fn (array $w) => $w['taste_direction'] ?? null],
            default => throw new \RuntimeException("Unbekannter Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);

        return [
            'value' => $extract($p->werte),
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
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
        $wert = json_decode((string) $prop->value, true);

        $update = match ($prop->field) {
            'description' => $r->description_source === 'manual' ? null
                : ['description' => (string) $wert, 'description_source' => 'ki', 'description_ai_confidence' => $prop->confidence],
            'category' => $r->category_source === 'manual' || FoodAlchemistRecipeCategory::find((int) $wert) === null ? null
                : ['category_id' => (int) $wert, 'category_source' => 'ki', 'category_ai_confidence' => $prop->confidence],
            'geschmack' => in_array($wert, ['suess', 'herzhaft', 'neutral'], true)
                ? ['taste_direction' => $wert] : null,             // Auto-Apply-Ausnahme-Feld (GL-07 §4.3), kein Lineage-Trio
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

    // ── GP-Bulk-Autopilot (Pendant zum Rezept-Pfad, eigener Vorschlags-Speicher) ──

    /** Startet einen GP-Anreicherungs-Lauf (Job queued; Sandbox/Tests: sync). */
    public function starteGp(Team $team, array $gpIds, array $schritte = self::SCHRITTE_GP): int
    {
        $ids = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds)->pluck('id')->all();
        DB::table('foodalchemist_bulk_runs')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $team->id, 'user_id' => Auth::id(),
            'type' => 'enrich_gp', 'status' => 'running', 'total' => count($ids),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $runId = (int) DB::getPdo()->lastInsertId();

        \Platform\FoodAlchemist\Jobs\BulkEnrichGpJob::dispatch($runId, $team->id, $ids, $schritte);

        return $runId;
    }

    /** Job-Kern: ein GP × Schritte → Vorschläge (kein Fach-Write). */
    public function verarbeiteGp(Team $team, int $runId, int $gpId, array $schritte): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->with('commodity_group')->find($gpId);
        $fehler = false;
        foreach ($gp === null ? [] : $schritte as $feld) {
            try {
                $vorschlag = $this->proposeGpFeld($team, $gp, $feld);
                $leer = $vorschlag['value'] === null || $vorschlag['value'] === '' || $vorschlag['value'] === [];
                DB::table('foodalchemist_bulk_gp_proposals')->insert([
                    'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                    'team_id' => $team->id, 'run_id' => $runId, 'gp_id' => $gp->id, 'field' => $feld,
                    'value' => json_encode($vorschlag['value']),
                    'confidence' => $vorschlag['confidence'],
                    'reasoning' => $vorschlag['reasoning'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    'status' => $leer ? 'leer' : 'offen',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                DB::table('foodalchemist_bulk_gp_proposals')->insert([
                    'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                    'team_id' => $team->id, 'run_id' => $runId, 'gp_id' => $gpId, 'field' => $feld,
                    'status' => 'leer', 'error' => mb_strimwidth($e->getMessage(), 0, 500),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->update([
            'done' => DB::raw('done + 1'),
            'failed' => DB::raw('failed + ' . ($fehler || $gp === null ? 1 : 0)),
            'updated_at' => now(),
        ]);
        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->whereColumn('done', '>=', 'total')
            ->update(['status' => 'done', 'updated_at' => now()]);
    }

    /** @return array{wert: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int} */
    private function proposeGpFeld(Team $team, FoodAlchemistGp $gp, string $feld): array
    {
        $basis = ['name' => $gp->name, 'condition' => $gp->condition, 'commodity_group' => $gp->commodity_group?->name];

        [$key, $kontext, $extract] = match ($feld) {
            'condition' => ['gp.condition', ['name' => $gp->name, 'condition' => $gp->condition ?: null],
                fn (array $w) => $w['condition'] ?? null],
            'tags' => ['gp.tags', ['name' => $gp->name,
                'tags' => collect(FoodAlchemistGp::TAG_FIELDS)->mapWithKeys(fn ($t) => [$t => $gp->getAttribute("tag_{$t}")])->filter(fn ($v) => $v !== null)->all()],
                fn (array $w) => $w['tags'] ?? null],
            'allergene' => ['gp.allergene', $basis,
                fn (array $w) => $w['allergene'] ?? null],
            'naehrwerte' => ['gp.naehrwerte', $basis,
                fn (array $w) => array_intersect_key($w, array_flip(['kcal', 'protein_g', 'fat_g', 'carbs_g', 'salt_g'])) ?: null],
            default => throw new \RuntimeException("Unbekannter GP-Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_gps', 'target_id' => $gp->id]);

        return [
            'value' => $extract($p->werte),
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /** Review: EIN GP-Vorschlag übernehmen (Override-First, Lineage ki, Stempel). */
    public function uebernehmenGp(Team $team, int $proposalId): bool
    {
        $prop = DB::table('foodalchemist_bulk_gp_proposals')->where('id', $proposalId)->where('status', 'offen')->first();
        if ($prop === null) {
            return false;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($prop->gp_id);
        if ($gp === null || ! $gp->isOwnedBy($team)) {                 // D1: nur eigene GPs
            return false;
        }
        $wert = json_decode((string) $prop->value, true);
        $ok = false;

        if ($prop->field === 'condition') {
            $z = app(GpNamingService::class)->normalisiereZustand(is_array($wert) ? ($wert['condition'] ?? null) : $wert);
            if ($z !== null && in_array($z, GpNamingService::ZUSTAND_VOCAB, true) && $gp->condition_source !== 'manual') {
                $gp->update(['condition' => $z, 'condition_source' => 'ki', 'condition_ai_confidence' => $prop->confidence, 'condition_ai_reasoning' => $prop->reasoning]);
                $ok = true;
            }
        } elseif ($prop->field === 'tags' && is_array($wert) && $gp->tag_source !== 'manual') {
            $tagWerte = $wert['tags'] ?? $wert;
            $update = [];
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                if (array_key_exists($tag, $tagWerte)) {
                    $update["tag_{$tag}"] = (bool) $tagWerte[$tag];
                }
            }
            if ($update !== []) {
                $gp->update([...$update, 'tag_source' => 'ki', 'tag_ai_confidence' => $prop->confidence, 'tag_ai_reasoning' => $prop->reasoning, 'tag_aggregated_at' => now()]);
                $ok = true;
            }
        } elseif ($prop->field === 'allergene' && is_array($wert)) {
            $update = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $v = $wert['allergene'][$feld] ?? $wert[$feld] ?? null;
                // Override-First: nur setzen, wenn noch KEIN Override existiert (manuelle Werte bleiben)
                if (in_array($v, ['enthalten', 'spuren', 'nicht_enthalten'], true) && $gp->getAttribute("allergen_{$feld}") === null) {
                    $update["allergen_{$feld}"] = $v;
                }
            }
            if ($update !== []) {
                $gp->update([...$update, 'allergens_confidence' => $prop->confidence]);
                $ok = true;
            }
        } elseif ($prop->field === 'naehrwerte' && is_array($wert) && $gp->nutri_source !== 'manual') {
            $num = fn ($v) => is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : null;
            if ($num($wert['kcal'] ?? null) !== null) {                // kcal = Leit-Indikator (GL-08)
                $gp->update([
                    'nutri_kcal_per_100g' => $num($wert['kcal'] ?? null),
                    'nutri_protein_g_per_100g' => $num($wert['protein_g'] ?? null),
                    'nutri_fat_g_per_100g' => $num($wert['fat_g'] ?? null),
                    'nutri_carbs_g_per_100g' => $num($wert['carbs_g'] ?? null),
                    'nutri_salt_g_per_100g' => $num($wert['salt_g'] ?? null),
                    'nutri_source' => 'ki', 'nutri_ai_confidence' => $prop->confidence,
                ]);
                $ok = true;
            }
        }

        if (! $ok) {
            return false;                                              // Override-First / ungültig — Vorschlag bleibt offen
        }
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        DB::table('foodalchemist_bulk_gp_proposals')->where('id', $proposalId)->update(['status' => 'uebernommen', 'updated_at' => now()]);

        return true;
    }

    /** Review: »Alle übernehmen« eines GP-Runs — Override-First je Zeile. */
    public function alleUebernehmenGp(Team $team, int $runId): int
    {
        $n = 0;
        foreach (DB::table('foodalchemist_bulk_gp_proposals')->where('run_id', $runId)->where('status', 'offen')->orderBy('id')->pluck('id') as $id) {
            $n += $this->uebernehmenGp($team, (int) $id) ? 1 : 0;
        }

        return $n;
    }

    public function verwerfenGp(Team $team, int $proposalId): void
    {
        $prop = DB::table('foodalchemist_bulk_gp_proposals')->where('id', $proposalId)->first();
        if ($prop !== null && FoodAlchemistGp::visibleToTeam($team)->whereKey($prop->gp_id)->exists()) {
            DB::table('foodalchemist_bulk_gp_proposals')->where('id', $proposalId)->update(['status' => 'verworfen', 'updated_at' => now()]);
            $this->ki->stempleRejected($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        }
    }

    /** Offene GP-Vorschläge eines Runs (Review-Zähler). */
    public function offeneGpVorschlaege(Team $team, int $runId): int
    {
        return DB::table('foodalchemist_bulk_gp_proposals')->where('run_id', $runId)->where('team_id', $team->id)
            ->where('status', 'offen')->count();
    }

    /** GP-Vorschläge eines Runs fürs Review-Panel (mit Feld + Wert-Vorschau). */
    public function gpVorschlaege(Team $team, int $runId): \Illuminate\Support\Collection
    {
        return DB::table('foodalchemist_bulk_gp_proposals')->where('run_id', $runId)->where('team_id', $team->id)
            ->whereIn('status', ['offen', 'uebernommen'])->orderBy('field')->get();
    }
}
