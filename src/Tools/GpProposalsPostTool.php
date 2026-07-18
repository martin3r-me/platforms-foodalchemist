<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpProposalService;

/**
 * 07·M4 (reframed): BESCHAFFUNGS-WUNSCH erfassen (Sourcing-Backlog) — erzeugt NIE
 * einen GP. NUR als LETZTE Stufe nutzen: erst foodalchemist.gps.MATCH (Bestand),
 * dann foodalchemist.gps.MINT_FROM_LA (LA-First-Mint). Erst wenn KEINE passende
 * LA existiert, fehlt Stammdaten → hier den Wunsch „Artikel beschaffen/anlegen"
 * hinterlegen. Kein „GP wartet auf Freigabe". Dedup über Namen (idempotent).
 */
class GpProposalsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_proposals.POST';
    }

    public function getDescription(): string
    {
        return 'Hinterlegt einen BESCHAFFUNGS-WUNSCH (Sourcing-Backlog: „Artikel beschaffen/anlegen") — '
            . 'KEIN GP-Write. NUR als letzte Stufe nutzen, wenn foodalchemist.gps.MATCH keinen Treffer UND '
            . 'foodalchemist.gps.MINT_FROM_LA keine passende LA fand (minted=false). Dann fehlt Stammdaten, '
            . 'kein Kurations-Klick. Gleicher Name + offener Wunsch → gibt den bestehenden zurück (created=false).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Vorgeschlagener GP-Name, möglichst Regelwerk-Schema "Produktname: Eigenschaft, Zustand"'],
                'main_ingredient_slug' => ['type' => 'string'],
                'commodity_group' => ['type' => 'string', 'description' => 'Warengruppen-Vermutung (final entscheidet die Kuration)'],
                'condition' => ['type' => 'string', 'enum' => ['frisch', 'tk', 'trocken', 'konserviert']],
                'kontext' => ['type' => 'string', 'description' => 'Wofür gebraucht (Rezept/Foodbook, Menge, Anlass)'],
                'source_kind' => ['type' => 'string', 'enum' => ['recipe', 'foodbook', 'canvas', 'sonstiges']],
                'source_id' => ['type' => 'integer'],
                'reasoning' => ['type' => 'string', 'description' => 'Warum reichte kein vorhandener GP (beste Kandidaten + warum unpassend)'],
            ],
            'required' => ['name', 'reasoning'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (trim((string) $arguments['name']) === '') {
            return ToolResult::error('name darf nicht leer sein.', 'VALIDATION_ERROR');
        }

        $ergebnis = app(GpProposalService::class)->propose($team, $arguments, $context->user?->id);
        $p = $ergebnis['proposal'];

        return ToolResult::success([
            'created' => $ergebnis['created'],
            'sourcing_request' => [
                'id' => $p->id, 'name' => $p->name, 'status' => $p->status,
                'commodity_group' => $p->commodity_group, 'condition' => $p->condition,
            ],
            'note' => 'Beschaffungs-Wunsch erfasst (Sourcing-Backlog): Auftrag an Einkauf/WaWi, den Artikel '
                . 'anzulegen. Es entsteht KEIN GP — sobald eine LA existiert, mintet foodalchemist.gps.MINT_FROM_LA '
                . 'daraus LA-First ein GP.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'sourcing', 'beschaffung', 'backlog', 'la-first'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MINT_FROM_LA', 'foodalchemist.gps.MATCH'],
            'examples' => ['Für "Ruby-Schokolade" gibt es weder GP noch LA — erfasse den Beschaffungs-Wunsch (Artikel anlegen)'],
        ];
    }
}
