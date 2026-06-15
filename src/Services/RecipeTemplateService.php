<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\TemplateSlotHeuristics;

/**
 * D-5: Template-Instanziierung (Port der Tauri-`commands.rs` — `list_templates`,
 * `instantiate_template`; die KI-Füllung `ai_fill_template` ist als Hook in
 * seedFill()/matchOne() vorbereitet, läuft aber bis zur LLM-Anbindung
 * deterministisch). Ein Template = `is_template=1` + ≥1 Zutat an einem
 * Platzhalter-GP (`is_platzhalter=1`). Beim Instanziieren werden die gebundenen
 * Platzhalter-Zeilen auf konkrete GPs / Sub-Rezepte getauscht, der Rest 1:1
 * kopiert; ungebundene Platzhalter bleiben stehen (Instanz bleibt `draft`).
 *
 * Schreibrichtung: NUR der RecipeRecomputeService schreibt Aggregate — hier wird
 * er nach der Materialisierung angestoßen (wie create()/duplicate()).
 */
class RecipeTemplateService
{
    /** Alle parametrisierbaren Templates (für die Auswahl-Liste), team-sichtbar. */
    public function templates(Team $team): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('is_template', true)
            ->whereHas('ingredients', fn ($q) => $q->whereHas('gp', fn ($g) => $g->where('is_platzhalter', true)))
            ->orderBy('name')
            ->get(['id', 'name', 'yield_kg', 'n_zutaten_total']);
    }

    /**
     * Namens-Basis für den Vorschlag, Title-Case. Schneidet am ersten Doppelpunkt
     * (Diskriminator-Suffix) ab, sonst erstes Wort: "Cremeux: Grundrezept Frucht"
     * → "Cremeux", "GELEE GRUND" → "Gelee", "Espuma: Grund (warm)" → "Espuma".
     */
    public function baseName(FoodAlchemistRecipe $template): string
    {
        $raw = trim($template->name);
        $base = strstr($raw, ':', true);
        if ($base === false) {
            $base = preg_split('/\s+/', $raw)[0] ?? $raw;
        }
        $base = trim($base);

        return mb_strtoupper(mb_substr($base, 0, 1)) . mb_strtolower(mb_substr($base, 1));
    }

    /**
     * Platzhalter-Slots eines Templates = Zutaten-Zeilen an einem Platzhalter-GP.
     *
     * @return list<array{ingredient_id:int, placeholder_name:string, menge:float, einheit:string, raw_text:string}>
     */
    public function slotsFor(Team $team, int $templateId): array
    {
        $template = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('is_template', true)
            ->with(['ingredients.gp:id,name,is_platzhalter', 'ingredients.einheit:id,display_de'])
            ->findOrFail($templateId);

        $slots = [];
        foreach ($template->ingredients as $ri) {
            if ($ri->gp === null || ! $ri->gp->is_platzhalter) {
                continue;
            }
            $slots[] = [
                'ingredient_id' => (int) $ri->id,
                'placeholder_name' => (string) $ri->gp->name,
                'menge' => (float) $ri->menge,
                'einheit' => (string) ($ri->einheit?->display_de ?? ''),
                'raw_text' => (string) $ri->raw_text,
            ];
        }

        return $slots;
    }

    /**
     * Seed-Vorschläge pro Slot (deterministisch): Slot-Typ-Heuristik bestimmt den
     * Such-Seed, der Matcher bindet ihn. KI-Kopplung hängt sich später hier ein
     * (gleiche Rückgabe-Form, nur urteilsbasiert statt Seed).
     *
     * @return list<array{ingredient_id:int, placeholder_name:string, query:string, target:string, item_id:?int, item_name:?string, score:float}>
     */
    public function seedFill(Team $team, int $templateId, string $variant): array
    {
        $slots = $this->slotsFor($team, $templateId);
        $namen = array_map(fn ($s) => $s['placeholder_name'], $slots);
        $hatBody = TemplateSlotHeuristics::hatDedizErtenBody($namen);
        $hatTraeger = TemplateSlotHeuristics::hatGeschmackstraeger($namen);

        $fills = [];
        foreach ($slots as $s) {
            $seed = TemplateSlotHeuristics::seed($s['placeholder_name'], $variant, $hatBody, $hatTraeger);
            $treffer = $this->matchOne($team, $s['placeholder_name'], $seed, $hatBody);
            $fills[] = [
                'ingredient_id' => $s['ingredient_id'],
                'placeholder_name' => $s['placeholder_name'],
                'query' => $seed,
                'target' => $treffer['target'],
                'item_id' => $treffer['item_id'],
                'item_name' => $treffer['item_name'],
                'score' => $treffer['score'],
            ];
        }

        return $fills;
    }

    /**
     * Einen Slot gegen einen Suchtext matchen (Seed ODER manuelle Eingabe). Body-/
     * Flüssigkeit-als-Body-Slots suchen Sub-First, Träger GP-First.
     *
     * @return array{target:string, item_id:?int, item_name:?string, score:float}
     */
    public function matchOne(Team $team, string $placeholderName, string $query, bool $hatDedizErtenBody): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['target' => 'none', 'item_id' => null, 'item_name' => null, 'score' => 0.0];
        }
        $mode = TemplateSlotHeuristics::bevorzugtSub($placeholderName, $hatDedizErtenBody) ? 'sub_recipe_first' : 'gp_first';
        $m = app(IngredientMatchService::class)->matchIngredient($team, $query, null, $mode);

        if ($m['target'] === 'gp') {
            return ['target' => 'gp', 'item_id' => $m['gp_id'], 'item_name' => $m['gp_name'], 'score' => (float) $m['score']];
        }
        if ($m['target'] === 'sub_recipe') {
            return ['target' => 'sub_recipe', 'item_id' => $m['recipe_id'], 'item_name' => $m['recipe_name'], 'score' => (float) $m['score']];
        }

        return ['target' => 'none', 'item_id' => null, 'item_name' => null, 'score' => (float) ($m['score'] ?? 0.0)];
    }

    /**
     * Materialisiert eine Varianten-Instanz: Basis über RecipeService::create()
     * (Key-Kollision + Pipeline), Zutaten 1:1 kopiert, gebundene Platzhalter auf
     * konkrete GPs/Sub-Rezepte getauscht, danach echtes Recompute. Dedup auf Name.
     *
     * @param  array<int, array{gp_id?: ?int, referenced_recipe_id?: ?int}>  $bindings  keyed by template ingredient_id
     * @return array{id:int, created:bool, gebunden:int, slots:int}
     */
    public function instantiate(Team $team, int $templateId, string $name, array $bindings): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Instanz braucht einen Namen.');
        }

        $template = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('is_template', true)
            ->with(['ingredients.gp:id,name,is_platzhalter'])
            ->findOrFail($templateId);

        // Dedup: team-eigenes Basisrezept gleichen Namens wiederverwenden (idempotent).
        $existing = FoodAlchemistRecipe::where('team_id', $team->id)->basis()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($existing !== null) {
            return ['id' => (int) $existing->id, 'created' => false, 'gebunden' => 0, 'slots' => 0];
        }

        return DB::transaction(function () use ($team, $template, $name, $bindings) {
            $instanz = app(RecipeService::class)->create($team, [
                'name' => $name,
                'kategorie_id' => $template->kategorie_id,
                'herkunft' => $template->herkunft,
                'geschmacksrichtung' => $template->geschmacksrichtung,
                'fertigungstiefe' => $template->fertigungstiefe,
                'ist_verkaufsrezept' => false,
                'beschreibung' => $template->beschreibung,
            ]);
            // Lineage + template-fixe Felder (Bindemittel-Verhältnis/Zubereitung/Yield bleiben fix).
            $instanz->update([
                'instantiated_from_recipe_id' => $template->id,
                'zubereitung' => $template->zubereitung,
                'yield_kg_manual' => $template->yield_kg_manual,
                'last_modified_by' => 'template_instanz',
            ]);

            $slotIds = $template->ingredients
                ->filter(fn ($ri) => $ri->gp && $ri->gp->is_platzhalter)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $gebunden = 0;

            foreach ($template->ingredients as $ri) {
                $felder = [
                    ...$ri->only(['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
                        'menge', 'menge_max', 'einheit_vocab_id', 'putzverlust_pct', 'garverlust_pct',
                        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
                        'rolle', 'ist_wertgebend', 'rechen_modus']),
                    'team_id' => $team->id,
                ];

                $binding = in_array((int) $ri->id, $slotIds, true) ? ($bindings[(int) $ri->id] ?? null) : null;
                if ($binding !== null && ($swap = $this->resolveBinding($team, $binding, (int) $instanz->id)) !== null) {
                    $felder = [...$felder, ...$swap];
                    $gebunden++;
                }

                $instanz->ingredients()->create($felder);
            }

            app(RecipeRecomputeService::class)->recomputePipeline($instanz->id);

            return ['id' => (int) $instanz->id, 'created' => true, 'gebunden' => $gebunden, 'slots' => count($slotIds)];
        });
    }

    /**
     * Eine Bindung in Zutaten-Feld-Overrides auflösen. Sub-Rezept hat Vorrang
     * (eine Flüssigkeit kann eine Essenz/Suppe sein). null = Bindung ungültig →
     * Platzhalter-Zeile bleibt unverändert stehen.
     *
     * @param  array{gp_id?: ?int, referenced_recipe_id?: ?int}  $binding
     * @return ?array<string, mixed>
     */
    private function resolveBinding(Team $team, array $binding, int $instanzId): ?array
    {
        $refId = $binding['referenced_recipe_id'] ?? null;
        if ($refId !== null) {
            if ((int) $refId === $instanzId) {
                return null;  // Selbst-Referenz verhindern
            }
            $sub = FoodAlchemistRecipe::visibleToTeam($team)->basis()->find($refId);
            if ($sub === null) {
                return null;
            }

            return [
                'gp_id' => null,
                'referenced_recipe_id' => (int) $sub->id,
                'raw_text' => $sub->name,
                'display_name' => $sub->name,
                'match_method' => MatchMethod::OverrideSubrecipe,
                'match_confidence' => null,
            ];
        }

        $gpId = $binding['gp_id'] ?? null;
        if ($gpId !== null) {
            $gp = FoodAlchemistGp::visibleToTeam($team)->where('is_platzhalter', false)->find($gpId);
            if ($gp === null) {
                return null;  // existiert nicht / ist selbst Platzhalter → nicht binden
            }

            return [
                'gp_id' => (int) $gp->id,
                'referenced_recipe_id' => null,
                'raw_text' => $gp->name,
                'display_name' => $gp->name,
                'match_method' => MatchMethod::OverrideGp,
                'match_confidence' => null,
            ];
        }

        return null;
    }
}
