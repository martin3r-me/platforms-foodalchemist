<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Konformitäts-Adapter für Rezepte — deckt BEIDE Sichten ab (Basisrezept UND
 * Verkaufsgericht), weil beide dieselbe Tabelle sind; `is_sales_recipe` wählt
 * die Regelwerke. Die Kontext-Felder spiegeln bewusst {@see RecipeReviewService::kontext}
 * (bewährte Accessor-Kette), erweitert um Grounding-/Sub-Rezept-Marker, die für
 * die §-Prüfung (Naming, Sub-Rezept-Regel, Default-GPs) tragen.
 */
class RecipeConformanceAdapter implements ConformanceAdapter
{
    public function artifactType(): string
    {
        return 'recipe';                                              // Basisrezept UND VK — dieselbe Tabelle
    }

    public function unterstuetztHeilung(): bool
    {
        return true;                                                 // Freitext-Revise via recipe.ueberarbeiten
    }

    public function pruefauftrag(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }
        $vk = (bool) $r->is_sales_recipe;

        $kontext = [
            'artefakt_typ' => $vk ? 'Verkaufsgericht (VK)' : 'Basisrezept/Komponente',
            'name' => $r->name,
            'kategorie' => $r->kategorie?->label,
            'beschreibung' => $r->description,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'menge' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
                'geerdet' => $z->gp_id !== null || $z->referenced_recipe_id !== null,
                'ist_sub_rezept' => $z->referenced_recipe_id !== null,
            ])->values()->all(),
        ];

        if ($vk) {
            // Verkaufs-Facetten sind Prüf-MASSSTAB (passt Name/Klasse zur §-Regel?),
            // gespiegelt aus dem Review-Kontext — kein Schreibziel.
            $kontext['speisen_klasse'] = $r->dishClass?->label;
            $kontext['diaetform'] = $r->dishClass?->diet_form;
            $kontext['portion_g'] = $r->sales_quantity_per_unit_g;
            $kontext['verkaufseinheiten'] = $r->sales_unit_count;
        } else {
            $kontext['ansatz_kg'] = $r->yield_kg_manual ?? $r->yield_kg;
            $kontext['ansatz_stueck'] = $r->yield_pieces;
        }

        // Basisrezept → Basisrezepte-Regelwerk (§-Dossiers). VK → zusätzlich das
        // Verkaufsgerichte-Regelwerk (Einzel-Dossier, kein §-Split). Volle §-Texte
        // lädt der ConformanceService anhand dieser Slug-Präfixe.
        $praefixe = ['regelwerk-basisrezepte-'];
        if ($vk) {
            $praefixe[] = 'regelwerk.regelwerk_verkaufsgerichte';
        }

        return [
            'kontext' => $kontext,
            'regelwerk_praefixe' => $praefixe,
            'target_table' => 'foodalchemist_recipes',
        ];
    }

    /**
     * Selbstheil-Runde: EIN Freitext-Revise (`recipe.ueberarbeiten`) nach der Verstoß-
     * Direktive, angewendet über DIESELBE Strecke wie der manuelle „✨ KI-Überarbeiten"
     * (syncZeilen → syncIngredients mit #508-Grounding; Texte mit Lineage ki, Override-
     * First). Gespiegelt aus {@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::ueberarbeitungUebernehmen}
     * — ohne den Livewire-Zustand. Best-effort: liefert die KI nichts Verwertbares,
     * bleibt das Rezept unangetastet und der Verstoß steht danach als Hinweis.
     */
    public function revise(Team $team, int $id, string $direktive): void
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return;
        }

        $vorschlag = app(AiGatewayService::class)->propose('recipe.ueberarbeiten', [
            'anweisung' => $direktive,
            'name' => $r->name,
            'description' => $r->description,
            'preparation' => $r->preparation,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'id' => $z->id,
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'quantity' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
            ])->values()->all(),
        ]);

        $werte = $vorschlag->werte;
        $conf = max(0.0, min(1.0, $vorschlag->confidence));

        // Zutaten: Voll-Ersatz über den geteilten Revise-Pfad (#508-Grounding hängt dran).
        if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
            $zeilen = app(RecipeReviseService::class)->syncZeilen($r, $werte['zutaten']);
            if ($zeilen !== []) {
                app(RecipeService::class)->syncIngredients($team, $id, $zeilen);
            }
        }

        // Texte: direkter Write mit Lineage ki — manuell gepflegte Felder bleiben unangetastet.
        $frisch = $r->fresh();
        if (is_string($werte['description'] ?? null) && trim($werte['description']) !== '' && $frisch->description_source !== 'manual') {
            $frisch->update([
                'description' => $werte['description'],
                'description_source' => 'ki',
                'description_ai_confidence' => $conf,
            ]);
        }
        if (is_string($werte['preparation'] ?? null) && trim($werte['preparation']) !== '' && $frisch->preparation_source !== 'manual') {
            $frisch->update(['preparation_source' => 'ki', 'preparation_ai_confidence' => $conf]);
            app(RecipeStepService::class)->ausMarkdown($frisch, $werte['preparation'], ueberschreiben: true);
        }
    }
}
