<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpProposalService;

/**
 * Phase 0: NEW-GP-Vorschlag — STAGING-ONLY. Erzeugt NIE einen GP; die Queue
 * wird in der WaWi-LA-First-Kuration abgearbeitet, der fertige GP kommt per
 * Einbahn-Sync. Dedup über normalisierten Namen (idempotent).
 */
class GpProposalsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_proposals.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen NEW-GP-Vorschlag in der Staging-Queue an (KEIN GP-Write — Kuration läuft '
            . 'extern über LA-First). Nur nutzen, wenn foodalchemist.gps.MATCH keinen brauchbaren '
            . 'Treffer lieferte. Gleicher Name + offener Vorschlag → gibt den bestehenden zurück (created=false).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Vorgeschlagener GP-Name, möglichst Regelwerk-Schema "Produktname: Eigenschaft, Zustand"'],
                'hauptzutat_slug' => ['type' => 'string'],
                'warengruppe' => ['type' => 'string', 'description' => 'Warengruppen-Vermutung (final entscheidet die Kuration)'],
                'zustand' => ['type' => 'string', 'enum' => ['frisch', 'tk', 'trocken', 'konserviert']],
                'kontext' => ['type' => 'string', 'description' => 'Wofür gebraucht (Rezept/Foodbook, Menge, Anlass)'],
                'quelle_kind' => ['type' => 'string', 'enum' => ['recipe', 'foodbook', 'canvas', 'sonstiges']],
                'quelle_id' => ['type' => 'integer'],
                'begruendung' => ['type' => 'string', 'description' => 'Warum reichte kein vorhandener GP (beste Kandidaten + warum unpassend)'],
            ],
            'required' => ['name', 'begruendung'],
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
            'proposal' => [
                'id' => $p->id, 'name' => $p->name, 'status' => $p->status,
                'warengruppe' => $p->warengruppe, 'zustand' => $p->zustand,
            ],
            'hinweis' => 'Staging-only: Der GP entsteht erst nach Kuration (LA-First, WaWi) und Sync.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'proposal', 'staging', 'la-first'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MATCH'],
            'examples' => ['Schlage einen neuen GP "Yuzu-Saft: konserviert" vor, weil kein Zitrus-GP passt'],
        ];
    }
}
