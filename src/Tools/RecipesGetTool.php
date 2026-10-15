<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeService;

/** M8-01: Basisrezept-Detail inkl. Zutaten + GL-02-Aggregate. */
class RecipesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Basisrezept im Detail: Kopf, Zutaten (mit GP-/Sub-Rezept-Verknüpfung), '
            . 'Yield/EK-Aggregate (GL-02) und Allergen-Konfidenz. `ek_price_basis` nennt die '
            . 'Herkunft des EK (lead = gewählte Lieferantenartikel, avg = Lieferanten-Durchschnitt '
            . 'und damit eine Schätzung, mixed = teils, unknown = nicht nachvollziehbar). '
            . 'Zutaten-IDs und Garverluste sind für recipe_ingredients.PUT enthalten; Default-Posten für recipes.PUT. '
            . 'PDF direkt über foodalchemist.recipes.PDF, verfügbare Posten über production_stations.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $r = app(RecipeService::class)->detail($team, (int) $arguments['id']);
        if ($r === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden (Basis-Sicht).', 'NOT_FOUND');
        }

        // Spec 82 Stufe 3b (E4): Kosten aus Sicht DIESES Teams — Besitzer: Rezept-Spalten (unverändert), sonst
        // Team-Kosten-Cache (eigene Preise/Pins). Eine Fassung trägt nie Master-Kosten.
        $kosten = app(\Platform\FoodAlchemist\Services\KostenCacheService::class)->fuer($r, $team);
        $daten = [
            'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value,
            'description' => $r->description, 'yield_kg' => $r->yield_kg,
            'ek_total_eur' => $kosten['ek_summe'], 'ek_per_kg_eur' => $kosten['ek_per_kg'],
            // vollständig=false: ek_total_eur ist eine TEILSUMME (Zutaten ohne Preis) und nie als Preis zu verwenden
            'ek_vollstaendig' => $kosten['vollstaendig'], 'ek_quelle' => $kosten['quelle'],
            'ek_fehlend_anteil_anzahl' => $kosten['anteil_fehlend_anzahl'], 'ek_fehlend_anteil_masse' => $kosten['anteil_fehlend_masse'],
            'ek_fehlende_zutaten' => array_slice($kosten['fehlende'], 0, 20),
            // V-014: woher der EK kommt — `lead` = gewählte Artikel, `avg` = Lieferanten-
            // Durchschnitt (Schätzung!), `mixed` = teils, `unknown` = nicht nachvollziehbar,
            // NULL = kein EK oder vor Einführung des Feldes gerechnet. Ohne diese Angabe
            // liest jede KI einen gemittelten EK als entschieden.
            'ek_price_basis' => $kosten['basis'],
            'ek_price_basis_label' => \Platform\FoodAlchemist\Enums\EkPriceBasis::tryFrom((string) $kosten['basis'])?->label(),
            'allergens_confidence' => $r->allergens_confidence,
            // Freiwillige Angaben (Jod), getrennt von der Pflicht-Kennzeichnung (ALL-MAXIMAL über die Zutaten)
            'freiwillig' => \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::freiwilligFuerMcp(fn ($f) => $r->{"additive_{$f}"}),
            // MCP-Lockstep zum Produktionszeit-Feature: aktive Belegzeit (work/setup) + passive
            // Standzeit (= Durchlaufzeit) + Topf-Deckel je Koch-Vorgang. NULL = nicht gepflegt
            // (Deckel fällt dann auf Posten-/Team-/Code-Default zurück).
            'work_time_min' => $r->work_time_min,
            'setup_time_min' => $r->setup_time_min,
            'standzeit_min' => $r->standzeit_min,
            'batch_max_kg' => $r->batch_max_kg,
            'batch_max_pieces' => $r->batch_max_pieces,
            'default_station_id' => $r->default_station_id,
            'max_vorlauf_tage' => $r->max_vorlauf_tage,
            // Mögliche Teller-Rollen des Basisrezepts (Dominique 11.10.), Hauptrolle zuerst, mit Quelle.
            'rollen' => $r->rollen()->get(['rolle', 'source'])->map(fn ($x) => ['rolle' => $x->rolle, 'source' => $x->source])->all(),
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'id' => $z->id,
                'cooking_loss_pct' => $z->cooking_loss_pct,
                'cooking_loss_source' => $z->cooking_loss_source,
                'trimming_loss_pct' => $z->trimming_loss_pct,
                'quantity_max' => $z->quantity_max,
                'is_optional' => (bool) $z->is_optional,
                'note' => $z->note, 'role' => $z->role,
                'referenced_recipe_id' => $z->referenced_recipe_id,
                'quantity' => $z->quantity, 'unit' => $z->unit?->slug,
                'name' => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name,
                'gp_id' => $z->gp_id, 'sub_recipe_id' => $z->referenced_recipe_id,
            ])->all(),
        ];
        // M1: Darreichungen/Formen je Gericht (bei VK-Gerichten gefüllt, bei Basisrezepten leer).
        $presentations = $this->darreichungenSummary($r);
        if ($presentations !== []) {
            $daten['presentations'] = $presentations;
        }

        return ToolResult::success($daten);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'recipe', 'rezept', 'detail', 'zutaten', 'allergene'],
            'examples' => ['Zeig mir Rezept 456 mit Zutaten'],
        ];
    }
}
