<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpService;

/** M8-01: GP-Detail inkl. GL-01-Allergen-Aggregat — Tool → Services. */
class GpsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Grundprodukt im Detail: Stammdaten, Status, Taxonomie (Warengruppe/Sub-Kategorie), '
            . 'Zustand/Bio, Derivat-Herkunft (§11.2: is_derivat/derivat_von_gp_id/requires_la), Tags (tri-state), '
            . 'Lead-LA-Referenz und das ALL-MAXIMAL-Allergen-Aggregat (GL-01).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'GP-Id']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = app(GpService::class)->find((int) $arguments['id'], $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $tags = [];
        foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
            $tags[$tag] = $gp->{'tag_' . $tag};   // tri-state: null = unbewertet
        }

        $freiwillig = app(GpAggregateService::class)->freiwillig($gp);

        return ToolResult::success([
            'id' => $gp->id, 'name' => $gp->name,
            'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status,
            'main_ingredient_slug' => $gp->main_ingredient_slug, 'condition' => $gp->condition, 'bio' => $gp->bio,
            'commodity_group_code' => $gp->commodity_group_code, 'sub_category' => $gp->sub_category,
            'is_derivat' => (bool) $gp->is_derivat,
            'derivat_von_gp_id' => $gp->derivat_von_gp_id !== null ? (int) $gp->derivat_von_gp_id : null,
            'requires_la' => (bool) $gp->requires_la,
            'is_platzhalter' => (bool) $gp->is_platzhalter,
            'zusatz' => $gp->zusatz,
            'rezepturware' => $gp->rezepturware,   // §5 Ausnahme 3: null = nicht entschieden
            'marke_offen' => (bool) $gp->marke_offen,
            'lead_la_supplier_item_id' => $gp->lead_la_supplier_item_id,
            'tags' => $tags,
            // Non-Food (WG 16): Maße in mm/ml wie gespeichert, dazu die Größe in Betriebs-Einheiten (Ø 27 cm); sonst null
            'masse' => $gp->istNonFood() ? array_merge(array_map(fn ($v) => $v !== null ? (float) $v : null, $gp->only(\Platform\FoodAlchemist\Support\NonFoodMasse::FELDER)), ['groesse' => $gp->masseText(), 'serving_vehicle_vocab_id' => $gp->serving_vehicle_vocab_id !== null ? (int) $gp->serving_vehicle_vocab_id : null]) : null,
            'allergene' => app(GpAggregateService::class)->allergene($gp),
            // Freiwillige Angaben (Jod), getrennt von der Pflicht-Kennzeichnung; MAX über die Artikel des GP
            'freiwillig' => \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::freiwilligFuerMcp(fn ($f) => $freiwillig[$f] ?? null),
        ]);
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
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'detail', 'allergene', 'price'],
            'examples' => ['Zeig mir Details zu GP 123', 'Welche Allergene hat das Zanderfilet-GP?'],
        ];
    }
}
