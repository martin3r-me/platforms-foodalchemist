<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\TeamScope;
use Symfony\Component\Uid\UuidV7;

/**
 * 05·P5 — Prozessanker-Parser (deterministisch, 0 LLM).
 *
 * Erdet die vier Prozess-/Kocharomen-Anker (roestaromen / karamell / rauch /
 * ferment) aus dem Zubereitungstext eines Rezepts (`preparation`, Fallback
 * `excel_raw_preparation`) — aber NUR wo ein echter Prozess-Marker im Text
 * steht. Kein Marker → kein Anker (keine Erfindung). Kalt-/Assembly-/Dip-/
 * Roh-Gerichte behalten korrekt 0 Prozessanker.
 *
 * Semantik gespiegelt aus dem Legacy-Gemini-Klassifikator (Vault-Skript
 * 216_prozess_anker_classify.py, SYSTEM_PROMPT) + den Seed-Design-Entscheidungen
 * (214/215): „Beurteile den PROZESS, nicht die Identität" (roher Lachs = kein
 * Anker; geräucherter Lachs = rauch), sowie „grill = roestaromen + rauch" und
 * „schmoren = roestaromen". Hoch-präzise gehalten: Ziel = Korrektheit, nicht
 * Vollabdeckung — mehrdeutige Prep-Texte bleiben dem KI-Rest (Etappe 2).
 *
 * Provenienz: parser-geschriebene Zeilen tragen `source='parser'`; nur eigene
 * parser-Zeilen werden neu berechnet/entfernt — manuelle/KI-/auto-Zeilen bleiben
 * unangetastet (mirrors 216: löscht nur eigene ai_inferred, nie manual).
 */
class ProcessAnchorService
{
    /** Die vier Prozessanker-Slugs im shared Anker-Vokabular (bestätigt: 216 VALID_SLUGS + PairingService::NICHT_ZUTAT_ANKER). */
    public const ANCHOR_SLUGS = ['roestaromen', 'karamell', 'rauch', 'ferment'];

    public const SOURCE = 'parser';

    /**
     * Slug → hoch-präzise Marker-Regexe (case-insensitiv, Unicode). Wortgrenzen
     * gesetzt, wo naive Teilstrings Fehltreffer erzeugen würden (z. B. „brauchen"
     * enthält „rauch"; „räuchern" ≠ „brauchen" durch das ä bzw. \b).
     *
     * @var array<string, list<string>>
     */
    public const PATTERNS = [
        // Maillard durch trockene Hitze: Anbraten/Rösten/Schmoren/Grillen/Sautieren/braune Fonds.
        'roestaromen' => [
            '/ger[öo]stet/iu',            // geröstet, geröstete, angeröstet
            '/\br[öo]sten\b/iu',          // rösten
            '/anr[öo]sten/iu',            // anrösten
            '/r[öo]staroma/iu',
            '/r[öo]ststoff/iu',
            '/\banbrat/iu',               // anbraten, anbrät
            '/\bangebrat/iu',             // angebraten
            '/\bgebraten\b/iu',           // (an)gebraten
            '/\bbraten\b/iu',             // braten (Ofen/Pfanne)
            '/\bschmor/iu',               // schmoren, schmort  → Design: schmoren = roestaromen
            '/geschmort/iu',
            '/sautier/iu',
            '/\bgrill/iu',                // grillen  → Design: grill = roestaromen (+ rauch, s.u.)
            '/gegrillt/iu',
            '/getoastet/iu',
            '/braune[rn]?\s+(fond|jus|butter)/iu', // brauner Fond/Jus, braune Butter (noisette)
        ],
        // Karamellisierung von Zucker.
        'karamell' => [
            '/\bkaramell/iu',             // karamell, karamellisiert, karamellsauce
            '/gebrannte[rn]?\s+zucker/iu',
        ],
        // Räucherung / Smoking / Pökeln / offene Flamme.
        'rauch' => [
            '/r[äa]ucher/iu',             // räuchern, geräuchert(e), räucherlachs
            '/ger[äa]uchert/iu',
            '/\brauch(ig|salz|paprika|aroma|noten?|geschmack)?\b/iu', // Rauch, rauchig … (nicht „brauchen": \b vor rauch)
            '/rauchpaprika/iu',
            '/piment[oó]n/iu',
            '/\bbbq\b/iu',
            '/barbecue/iu',
            '/gep[öo]kelt/iu',
            '/p[öo]keln/iu',
            '/\bsmok/iu',                 // smoked, smoking
            '/\bgrill/iu',                // grill = roestaromen + rauch (Char/offene Flamme)
            '/gegrillt/iu',
        ],
        // Fermentation / Gärung im Rezept präsent (Umami-tief).
        'ferment' => [
            '/\bferment/iu',              // ferment, fermentiert, fermentation
            '/\bmiso\b/iu',
            '/soja\s*so(?:ß|ss|s)e/iu',
            '/soja\s*sauce/iu',
            '/sojasauce/iu',
            '/fisch\s*sauce/iu',
            '/fischso(?:ß|ss)e/iu',
            '/nam\s*pla/iu',
            '/\bkimchi/iu',
            '/sauerkraut/iu',
            '/gochujang/iu',
            '/d[öo]enjang/iu',
            '/\btempeh\b/iu',
            '/worcester/iu',
            '/\bnatt[oō]\b/iu',
            '/\bmirin\b/iu',
        ],
    ];

    /**
     * Deterministische Analyse eines Zubereitungstextes → getroffene Anker-Slugs
     * mit den auslösenden Markern (für Provenienz/Review).
     *
     * @return array<string, list<string>>  slug => [matched keyword, …]
     */
    public function parse(?string $prep): array
    {
        $text = trim((string) $prep);
        if ($text === '') {
            return [];
        }

        $out = [];
        foreach (self::PATTERNS as $slug => $regexe) {
            foreach ($regexe as $rx) {
                if (preg_match($rx, $text, $m) === 1) {
                    $out[$slug][] = mb_strtolower(trim($m[0]));
                }
            }
        }

        // Marker deduplizieren
        foreach ($out as $slug => $marker) {
            $out[$slug] = array_values(array_unique($marker));
        }

        return $out;
    }

    /**
     * Erdet die Prozessanker EINES Rezepts. Idempotent: fügt fehlende parser-Anker
     * hinzu, entfernt eigene (source='parser') Anker, die nicht mehr getroffen
     * werden. Fremd-Quellen (manual/ki/auto) bleiben unberührt.
     *
     * @return array{recipe_id:int, matched:list<string>, added:list<string>, removed:list<string>, kept_foreign:list<string>}
     */
    public function groundRecipe(FoodAlchemistRecipe $recipe, bool $apply): array
    {
        $prep = $recipe->preparation ?: ($recipe->excel_raw_preparation ?? null);
        $treffer = $this->parse($prep);
        $matchedSlugs = array_keys($treffer);

        $anchorMap = $this->anchorSlugToId(); // slug => anchor_id
        $wanted = [];
        foreach ($matchedSlugs as $slug) {
            if (isset($anchorMap[$slug])) {
                $wanted[$anchorMap[$slug]] = $slug;
            }
        }

        // Ist-Zustand: anchor_id => source
        $ist = DB::table('foodalchemist_recipe_process_anchors')
            ->where('recipe_id', $recipe->id)
            ->whereNull('deleted_at')
            ->pluck('source', 'anchor_id');

        $added = [];
        $removed = [];
        $keptForeign = [];
        $insertRows = [];

        // hinzufügen: gewollter Anker, der noch gar nicht existiert
        foreach ($wanted as $anchorId => $slug) {
            $vorhandeneQuelle = $ist[$anchorId] ?? null;
            if ($vorhandeneQuelle === null) {
                $added[] = $slug;
                $insertRows[] = [
                    'uuid' => (string) UuidV7::generate(),
                    'team_id' => $recipe->team_id,
                    'recipe_id' => $recipe->id,
                    'anchor_id' => $anchorId,
                    'source' => self::SOURCE,
                    'ai_confidence' => null,
                    'ai_reasoning' => 'parser: ' . implode(', ', $treffer[$slug] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } elseif ($vorhandeneQuelle !== self::SOURCE) {
                $keptForeign[] = $slug; // schon vorhanden (fremd) — nicht doppeln
            }
        }

        // entfernen: eigene parser-Zeile, die nicht mehr getroffen wird
        $slugById = array_flip($anchorMap);
        foreach ($ist as $anchorId => $quelle) {
            if ($quelle === self::SOURCE && ! isset($wanted[$anchorId])) {
                $removed[] = $slugById[$anchorId] ?? (string) $anchorId;
            }
        }

        if ($apply && ($insertRows !== [] || $removed !== [])) {
            DB::transaction(function () use ($recipe, $insertRows, $wanted) {
                if ($insertRows !== []) {
                    DB::table('foodalchemist_recipe_process_anchors')->insert($insertRows);
                }
                // eigene, nicht mehr getroffene parser-Zeilen soft-deleten
                DB::table('foodalchemist_recipe_process_anchors')
                    ->where('recipe_id', $recipe->id)
                    ->where('source', self::SOURCE)
                    ->whereNull('deleted_at')
                    ->when($wanted !== [], fn ($q) => $q->whereNotIn('anchor_id', array_keys($wanted)))
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            });
        }

        return [
            'recipe_id' => $recipe->id,
            'matched' => $matchedSlugs,
            'added' => $added,
            'removed' => $removed,
            'kept_foreign' => $keptForeign,
        ];
    }

    /**
     * Bulk-Lauf über einen Rezept-Query. Gibt Aggregat-Statistik zurück.
     *
     * @param  callable(\Illuminate\Database\Eloquent\Builder):void|null  $scope  zusätzliche Query-Einschränkung
     * @return array{scanned:int, recipes_touched:int, added:int, removed:int, per_anchor:array<string,int>}
     */
    public function groundBulk(?Team $team, bool $apply, bool $missingOnly = false, ?int $limit = null, ?int $recipeId = null): array
    {
        $query = FoodAlchemistRecipe::query()->whereNull('deleted_at');

        if ($recipeId !== null) {
            $query->whereKey($recipeId);
        }
        if ($team !== null) {
            $query->whereIn('team_id', TeamScope::ancestryIds($team));
        }
        if ($missingOnly) {
            $query->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('foodalchemist_recipe_process_anchors AS pa')
                    ->whereColumn('pa.recipe_id', 'foodalchemist_recipes.id')
                    ->whereNull('pa.deleted_at');
            });
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $stats = ['scanned' => 0, 'recipes_touched' => 0, 'added' => 0, 'removed' => 0, 'per_anchor' => []];

        $query->orderBy('id')->chunk(500, function ($recipes) use (&$stats, $apply) {
            foreach ($recipes as $recipe) {
                $stats['scanned']++;
                $r = $this->groundRecipe($recipe, $apply);
                if ($r['added'] !== [] || $r['removed'] !== []) {
                    $stats['recipes_touched']++;
                }
                $stats['added'] += count($r['added']);
                $stats['removed'] += count($r['removed']);
                foreach ($r['added'] as $slug) {
                    $stats['per_anchor'][$slug] = ($stats['per_anchor'][$slug] ?? 0) + 1;
                }
            }
        });

        return $stats;
    }

    /** Ist-Abdeckung der parser-Anker (für --verify), team-scoped. */
    public function coverage(?Team $team): array
    {
        $recipes = FoodAlchemistRecipe::query()->whereNull('deleted_at')
            ->when($team !== null, fn ($q) => $q->whereIn('team_id', TeamScope::ancestryIds($team)))
            ->count();

        $base = DB::table('foodalchemist_recipe_process_anchors AS pa')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'pa.recipe_id')
            ->whereNull('pa.deleted_at')->whereNull('r.deleted_at')
            ->when($team !== null, fn ($q) => $q->whereIn('r.team_id', TeamScope::ancestryIds($team)));

        return [
            'recipes' => $recipes,
            'anchors_total' => (clone $base)->count(),
            'anchors_parser' => (clone $base)->where('pa.source', self::SOURCE)->count(),
            'recipes_with_anchor' => (clone $base)->distinct('pa.recipe_id')->count('pa.recipe_id'),
        ];
    }

    /** @return array<string,int>  slug => anchor_id (global ∪ NULL) */
    private function anchorSlugToId(): array
    {
        return DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('slug', self::ANCHOR_SLUGS)
            ->whereNull('deleted_at')
            ->pluck('id', 'slug')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
