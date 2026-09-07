<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * KI-Kontext eines Rezepts/Gerichts — NACH der Erstellung (2026-09-06).
 *
 * Anlass: „In der UI sieht man das Wissen nie komplett." Der Kontext-Inspektor zeigte das
 * Wissens-Grounding nur im Generator-Modal, solange es offen war; danach war für ein Rezept
 * nicht mehr auffindbar, was der Generator gelesen hat. Der Call-Log hatte die Daten
 * (knowledge_used, prompt_parts), aber kein Ziel: `target_table/target_id` waren bei
 * Generator-Calls leer, weil das Rezept erst nach dem Call entsteht.
 *
 * Jetzt: der Generator hängt seinen Call ans Rezept (RecipeGeneratorService, nach dem Commit),
 * der Gateway persistiert die Kanäle (`knowledge_channels`) — und dieser Dienst baut daraus
 * das Bündel, das `x-foodalchemist::kontext-inspektor` versteht. Read-only, fail-safe:
 * kein Call → null → Panel bleibt aus.
 */
class RecipeKiKontextService
{
    /** Prompt-Keys, die ein Rezept ERSTELLEN (nur die zeigen wir als „Erstellung"). */
    public const GENERATOR_FEATURES = ['recipe.generator', 'vk.generator'];

    /**
     * @return array{kontext: array<string, mixed>, meta: array<string, mixed>}|null
     */
    public function fuerRezept(FoodAlchemistRecipe $rezept): ?array
    {
        // Team des Rezepts, nicht des Betrachters: der Log gehört dem erzeugenden Team; ein
        // Kind-Team, das das Rezept sieht, darf auch sehen, worauf es gebaut ist.
        $row = DB::table('foodalchemist_ai_call_log')
            ->where('team_id', (int) $rezept->team_id)
            ->where('target_table', 'foodalchemist_recipes')
            ->where('target_id', (int) $rezept->id)
            ->whereIn('feature', self::GENERATOR_FEATURES)
            ->whereNull('error')
            ->orderByDesc('id')
            ->first(['id', 'feature', 'model', 'tier', 'knowledge_used', 'prompt_chars', 'prompt_parts', 'tokens_in', 'tokens_out', 'tokens_cached', 'created_at',
                ...(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'knowledge_channels') ? ['knowledge_channels'] : [])]);
        if ($row === null) {
            return null;
        }

        $kanaele = is_string($row->knowledge_channels ?? null) ? (json_decode($row->knowledge_channels, true) ?: []) : [];
        if (! is_array($kanaele) || $kanaele === []) {
            // Alt-Zeile ohne Kanäle: die flache Audit-Liste ist besser als nichts — aber ehrlich
            // beschriftet (ein Kanal „Wissen", kein erfundener Kanon).
            $flach = is_string($row->knowledge_used) ? (json_decode($row->knowledge_used, true) ?: []) : [];
            $kanaele = is_array($flach) && $flach !== [] ? ['wissen' => array_values($flach)] : [];
        }

        return [
            'kontext' => [
                'wissen' => $kanaele,
                'templates' => [],
                'chars' => 0,
                'prompt' => $this->promptGroessen($row),
            ],
            'meta' => [
                'call_log_id' => (int) $row->id,
                'feature' => (string) $row->feature,
                'model' => $row->model !== null ? (string) $row->model : null,
                'tier' => $row->tier !== null ? (string) $row->tier : null,
                'erstellt_am' => $row->created_at !== null ? (string) $row->created_at : null,
                'tokens_in' => (int) ($row->tokens_in ?? 0),
                'tokens_out' => (int) ($row->tokens_out ?? 0),
                'dossiers' => array_sum(array_map(fn ($v) => is_array($v) ? count($v) : 0, $kanaele)),
            ],
        ];
    }

    /**
     * Spec 52/A1 — ALLE Modell-Aufrufe eines Rezepts, ohne Feature-Filter.
     *
     * {@see fuerRezept()} zeigt bewusst nur die Erstellung (`GENERATOR_FEATURES`). Für die
     * Grundlinien-Messung ist genau das das Problem: pro Rezept-Erstellung laufen drei bis vier
     * Aufrufe (Generator → `conformance.check` → Selbstheilung → Review), und der Inspektor
     * blendet drei davon aus. Wer wissen will, ob ein Anreicherungs-Schritt Wissen bekam, sieht
     * es dort nie.
     *
     * Solange es keine Lauf-ID gibt (Spec 52/C1), ist `target_table`/`target_id` die einzige
     * Klammer — und die trägt nur, was der Aufrufer selbst gesetzt hat. Fehlt eine Zeile hier,
     * heisst das also nicht „kein Aufruf", sondern „nicht ans Rezept gehängt". Deshalb gibt
     * diese Methode zurück, was sie findet, und behauptet keine Vollständigkeit.
     *
     * @return list<array<string, mixed>>
     */
    public function alleCallsFuerRezept(FoodAlchemistRecipe $rezept): array
    {
        $hatKanaele = \Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'knowledge_channels');

        $rows = DB::table('foodalchemist_ai_call_log')
            ->where('team_id', (int) $rezept->team_id)
            ->where('target_table', 'foodalchemist_recipes')
            ->where('target_id', (int) $rezept->id)
            ->orderBy('id')
            ->get(['id', 'feature', 'model', 'tier', 'knowledge_used', 'prompt_chars', 'prompt_parts',
                'tokens_in', 'tokens_out', 'tokens_cached', 'error', 'created_at',
                ...($hatKanaele ? ['knowledge_channels'] : [])]);

        return $rows->map(function ($row) {
            $kanaele = is_string($row->knowledge_channels ?? null) ? (json_decode($row->knowledge_channels, true) ?: []) : [];
            $flach = is_string($row->knowledge_used) ? (json_decode($row->knowledge_used, true) ?: []) : [];

            return [
                'call_log_id' => (int) $row->id,
                'feature' => (string) $row->feature,
                'erstellt_am' => $row->created_at !== null ? (string) $row->created_at : null,
                'fehler' => $row->error !== null ? (string) $row->error : null,
                'groessen' => $this->promptGroessen($row),
                'kanaele' => is_array($kanaele) ? $kanaele : [],
                'wissen_slugs' => is_array($flach) ? array_values($flach) : [],
            ];
        })->values()->all();
    }

    /**
     * Die ECHTEN Prompt-Größen aus der Messsonde (W3-5) — null, wenn die Sonde für diese
     * Zeile nichts hat (keine erfundenen Nullen).
     *
     * @return array<string, int>|null
     */
    public function promptGroessen(object $row): ?array
    {
        $teile = is_string($row->prompt_parts ?? null) ? (json_decode($row->prompt_parts, true) ?: []) : [];
        if (! is_array($teile) || $teile === []) {
            return null;
        }

        return [
            'chars' => (int) ($row->prompt_chars ?? 0),
            'huelle' => (int) ($teile['huelle'] ?? 0),
            'kanon' => (int) ($teile['kanon'] ?? 0),   // Welle 2: Kanon-Block (ersetzt bound je Prompt-Key)
            'bound' => (int) ($teile['bound'] ?? 0),
            'task' => (int) ($teile['task'] ?? 0),
            'retrieval' => (int) ($teile['retrieval'] ?? 0),
            'kontext' => (int) ($teile['kontext'] ?? 0),
            'dropped' => (int) ($teile['dropped'] ?? 0),
            'tokens_in' => (int) ($row->tokens_in ?? 0),
            'tokens_cached' => (int) ($row->tokens_cached ?? 0),
        ];
    }
}
