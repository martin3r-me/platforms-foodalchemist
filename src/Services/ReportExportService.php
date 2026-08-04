<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Zentrale Datenaufbereitung für druckbare/PDF-fähige Reports.
 *
 * Der Service rendert keine PDFs. Er liefert ein stabiles Datenpaket für HTML
 * und DomPDF. Vollkaskade bedeutet: Rezept/Gericht/Concept → Sub-Rezepte →
 * GP → Lead-Lieferantenartikel → aktueller Preis.
 */
class ReportExportService
{
    /** @return array<string, bool|string> */
    public function optionen(array $query, string $scope): array
    {
        $profil = (string) ($query['profil'] ?? 'produktion');
        if (! in_array($profil, ['kurz', 'produktion', 'kalkulation', 'voll'], true)) {
            $profil = 'produktion';
        }

        $defaults = match ($profil) {
            'kurz' => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => false, 'sensorik' => false,
                'produktion' => false, 'preise' => false, 'lieferanten' => false, 'kaskade' => false,
                'notizen' => false, 'intern' => false,
            ],
            'kalkulation' => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => false, 'sensorik' => false,
                'produktion' => false, 'preise' => true, 'lieferanten' => true, 'kaskade' => true,
                'notizen' => false, 'intern' => true,
            ],
            'voll' => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => true, 'sensorik' => true,
                'produktion' => true, 'preise' => true, 'lieferanten' => true, 'kaskade' => true,
                'notizen' => true, 'intern' => true,
            ],
            default => [
                'stammdaten' => true, 'zutaten' => true, 'steps' => true, 'sensorik' => false,
                'produktion' => true, 'preise' => false, 'lieferanten' => false, 'kaskade' => true,
                'notizen' => false, 'intern' => false,
            ],
        };

        if ($scope === 'concept' && $profil === 'produktion') {
            $defaults['preise'] = true;
        }

        foreach (array_keys($defaults) as $key) {
            if (array_key_exists($key, $query)) {
                $defaults[$key] = filter_var($query[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return ['profil' => $profil, ...$defaults];
    }

    /** @return array<string, mixed> */
    public function rezeptDaten(Team $team, int $id, array $optionen): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)
            ->with($this->recipeRelations())
            ->findOrFail($id);

        $baum = $this->recipeNode($recipe, $optionen, 0, []);

        return [
            'typ' => $recipe->is_sales_recipe ? 'gericht' : 'basisrezept',
            'titel' => $recipe->is_sales_recipe ? 'Gericht' : 'Basisrezept',
            'name' => $recipe->name,
            'optionen' => $optionen,
            'recipe' => $baum,
            'concept' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function conceptDaten(Team $team, int $id, array $optionen): array
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'category:id,name',
                'eventType:id,name',
                'servingForm:id,label',
                'serviceMoments:id,name',
                'seasons:id,name',
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.unit:id,slug,display_de',
                'slots.dish' => fn ($q) => $q->with($this->recipeRelations()),
                'slots.package.dishes.unit:id,slug,display_de',
                'slots.package.dishes.dish' => fn ($q) => $q->with($this->recipeRelations()),
            ])
            ->findOrFail($id);

        $slots = $concept->slots->map(function ($slot) use ($optionen) {
            $gerichte = collect();
            if ($slot->dish !== null) {
                $gerichte->push([
                    'quelle' => 'gericht',
                    'menge' => $this->mengeText($slot->quantity, $slot->unit),
                    'recipe' => $this->recipeNode($slot->dish, $optionen, 0, []),
                ]);
            }
            if ($slot->package !== null) {
                foreach ($slot->package->dishes as $paketGericht) {
                    if ($paketGericht->dish === null) {
                        continue;
                    }
                    $gerichte->push([
                        'quelle' => 'paket',
                        'paket' => $slot->package->name,
                        'menge' => $this->mengeText($paketGericht->quantity, $paketGericht->unit),
                        'recipe' => $this->recipeNode($paketGericht->dish, $optionen, 0, []),
                    ]);
                }
            }

            return [
                'id' => (int) $slot->id,
                'position' => (int) $slot->position,
                'role' => $slot->role,
                'title' => $slot->title,
                'type' => $slot->package_id !== null ? 'paket' : ($slot->sales_recipe_id !== null ? 'gericht' : 'leer'),
                'package' => $slot->package ? [
                    'id' => (int) $slot->package->id,
                    'name' => $slot->package->name,
                    'price_per_person' => $slot->package->price_per_person,
                    'ek_per_person' => $slot->package->ek_per_person,
                    'food_cost_percent' => $slot->package->food_cost_percent,
                ] : null,
                'gerichte' => $gerichte->values()->all(),
            ];
        })->values();

        return [
            'typ' => 'concept',
            'titel' => 'Concept',
            'name' => $concept->name,
            'optionen' => $optionen,
            'recipe' => null,
            'concept' => [
                'id' => (int) $concept->id,
                'name' => $concept->name,
                'consumer_name' => $concept->consumer_name,
                'occasion' => $concept->occasion,
                'level' => $concept->level,
                'status' => $concept->status,
                'description' => $concept->description,
                'price_per_person_cache' => $concept->price_per_person_cache,
                'ek_per_person_cache' => $concept->ek_per_person_cache,
                'work_time_min_cache' => $concept->work_time_min_cache,
                'category' => $concept->category?->name,
                'event_type' => $concept->eventType?->name,
                'serving_form' => $concept->servingForm?->label,
                'moments' => $concept->serviceMoments->pluck('name')->values()->all(),
                'seasons' => $concept->seasons->pluck('name')->values()->all(),
                'slots' => $slots->all(),
            ],
        ];
    }

    /** @return list<string> */
    private function recipeRelations(): array
    {
        return [
            'category:id,label',
            'dishClass:id,label',
            'dishMainGroup:id,label',
            'markupClass:id,label,code',
            'salesUnit:id,slug,display_de',
            'defaultStation:id,name,slug,group_name',
            'equipment:id,slug,name',
            'steps',
            'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
            'ingredients.unit:id,slug,display_de',
            'ingredients.gp.leadLa.supplier:id,name',
            'ingredients.gp.leadLa.prices' => fn ($q) => $q->orderByDesc('change_date')->orderByDesc('id')->limit(1),
            'ingredients.referencedRecipe',
        ];
    }

    /**
     * @param  array<int, true>  $visited
     * @return array<string, mixed>
     */
    private function recipeNode(FoodAlchemistRecipe $recipe, array $optionen, int $tiefe, array $visited): array
    {
        if (isset($visited[$recipe->id])) {
            return [
                'id' => (int) $recipe->id,
                'name' => $recipe->name,
                'zyklus' => true,
                'tiefe' => $tiefe,
            ];
        }
        $visited[$recipe->id] = true;

        if (! $recipe->relationLoaded('ingredients')) {
            $recipe->load($this->recipeRelations());
        }

        $sensorik = null;
        if ($optionen['sensorik'] ?? false) {
            try {
                $sensorik = app(SensorikService::class)->fuerRezept((int) $recipe->id);
            } catch (\Throwable) {
                $sensorik = ['leer' => true, 'fehler' => 'Sensorik konnte nicht gelesen werden.'];
            }
        }

        return [
            'id' => (int) $recipe->id,
            'name' => $recipe->name,
            'is_sales_recipe' => (bool) $recipe->is_sales_recipe,
            'status' => $recipe->status?->value ?? (string) $recipe->status,
            'tiefe' => $tiefe,
            'zyklus' => false,
            'description' => $recipe->description,
            'preparation' => $recipe->preparation,
            'notes_manual' => $recipe->notes_manual,
            'yield_kg' => $recipe->yield_kg,
            'yield_pieces' => $recipe->yield_pieces,
            'ek_total_eur' => $recipe->ek_total_eur,
            'ek_per_kg_eur' => $recipe->ek_per_kg_eur,
            'sales_net' => $recipe->sales_net,
            'food_cost_percent' => ((float) ($recipe->sales_net ?? 0) > 0 && $recipe->ek_total_eur !== null)
                ? round(((float) $recipe->ek_total_eur / (float) $recipe->sales_net) * 100, 2)
                : null,
            'sales_wording_standard' => $recipe->sales_wording_standard,
            'plating_text' => $recipe->plating_text,
            'category' => $recipe->category?->label,
            'dish_class' => $recipe->dishClass?->label,
            'dish_main_group' => $recipe->dishMainGroup?->label,
            'markup_class' => $recipe->markupClass?->label ?? $recipe->markupClass?->code,
            'sales_unit' => $recipe->salesUnit?->display_de ?? $recipe->salesUnit?->slug,
            'produktion' => [
                'production_depth' => $recipe->production_depth,
                'work_time_min' => $recipe->work_time_min,
                'temperature' => $recipe->temperature,
                'function' => $recipe->function,
                'default_station' => $recipe->defaultStation?->name,
                'setup_time_min' => $recipe->setup_time_min,
                'max_vorlauf_tage' => $recipe->max_vorlauf_tage,
                'batch_max_kg' => $recipe->batch_max_kg,
                'batch_max_pieces' => $recipe->batch_max_pieces,
                'equipment' => $recipe->equipment->pluck('name')->values()->all(),
            ],
            'steps' => $recipe->steps->sortBy('position')->values()->map(fn ($s) => [
                'position' => (int) $s->position,
                'phase' => $s->phase,
                'text' => $s->text,
            ])->all(),
            'sensorik' => $sensorik,
            'ingredients' => $recipe->ingredients->map(fn ($z) => $this->ingredientNode($z, $optionen, $tiefe, $visited))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function ingredientNode($z, array $optionen, int $tiefe, array $visited): array
    {
        $gp = $z->gp;
        $sub = $z->referencedRecipe;
        $lead = $gp?->leadLa;
        $price = $lead?->prices instanceof Collection ? $lead->prices->first() : null;

        return [
            'id' => (int) $z->id,
            'position' => (int) $z->position,
            'name' => $sub?->name ?? $gp?->name ?? $z->display_name ?? $z->raw_text,
            'raw_text' => $z->raw_text,
            'menge' => $this->mengeText($z->quantity, $z->unit),
            'quantity' => $z->quantity,
            'unit' => $z->unit?->display_de ?? $z->unit?->slug,
            'role' => $z->role,
            'type' => $sub !== null ? 'basisrezept' : ($gp !== null ? 'gp' : 'offen'),
            'gp' => $gp ? $this->gpNode($gp, $lead, $price) : null,
            'subrecipe' => ($sub !== null && ($optionen['kaskade'] ?? false))
                ? $this->recipeNode($sub, $optionen, $tiefe + 1, $visited)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function gpNode(FoodAlchemistGp $gp, $lead, $price): array
    {
        return [
            'id' => (int) $gp->id,
            'name' => $gp->name,
            'commodity_group_code' => $gp->commodity_group_code,
            'lead_la' => $lead ? [
                'id' => (int) $lead->id,
                'designation' => $lead->designation ?? $lead->name ?? null,
                'article_number' => $lead->article_number ?? null,
                'packaging_unit' => $lead->packaging_unit ?? null,
                'qty' => $lead->qty,
                'unit_code' => $lead->unit_code,
                'supplier' => $lead->supplier?->name,
                'price' => $price?->price,
                'price_partial' => $price?->price_partial,
            ] : null,
        ];
    }

    private function mengeText($quantity, $unit): ?string
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $quantity, 3, ',', '.'), '0'), ',')
            . ($unit ? ' ' . ($unit->display_de ?? $unit->slug) : '');
    }
}
