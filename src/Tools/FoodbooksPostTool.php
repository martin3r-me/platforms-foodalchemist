<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Phase B: Foodbook-Anlage aus dem LLM-Pfad — nativ FA (Architektur-
 * Entscheidung 2026-07-01: Foodbook/Konzepte leben NUR hier, kein
 * WaWi-Konflikt). Entsteht immer als status=draft.
 */
class FoodbooksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein neues Foodbook als ENTWURF an (status=draft), optional direkt mit Kapitel-Gerüst '
            . '(kapitel: Liste von Titeln). Inhalte danach: foodalchemist.foodbook_kapitel.POST für weitere/'
            . 'verschachtelte Kapitel, foodalchemist.foodbook_blocks.POST für Gerichte/Texte/Header pro Kapitel.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string', 'description' => 'Name des Foodbooks, z. B. "Sommerhochzeiten 2027"'],
                'jahr' => ['type' => 'integer'],
                'kunde' => ['type' => 'string', 'description' => 'Kunden-Name (Freitext; CRM-Link macht der Editor)'],
                'personen' => ['type' => 'integer', 'description' => 'Default-Pax für Preis-Kalkulationen'],
                'description' => ['type' => 'string'],
                'kapitel' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optionales Kapitel-Gerüst (Titel in Reihenfolge), z. B. ["Empfang", "Vorspeisen", "Hauptgänge"]',
                ],
            ],
            'required' => ['label'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(FoodbookService::class);

        try {
            $fb = $svc->create($team, [
                'label' => (string) $arguments['label'],
                'jahr' => $arguments['jahr'] ?? null,
                'kunde' => $arguments['kunde'] ?? null,
                'personen' => $arguments['personen'] ?? null,
                'description' => $arguments['description'] ?? null,
                'status' => 'draft',
            ]);
            $kapitel = [];
            foreach (array_values((array) ($arguments['kapitel'] ?? [])) as $titel) {
                $k = $svc->addKapitel($team, $fb->id, ['titel' => (string) $titel]);
                $kapitel[] = ['id' => $k->id, 'titel' => $k->titel];
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'foodbook' => ['id' => $fb->id, 'label' => $fb->label, 'status' => $fb->status, 'jahr' => $fb->jahr],
            'kapitel' => $kapitel,
            'note' => 'Entwurf: Freigabe/Kunden-Verknüpfung (CRM) macht ein Mensch im Editor.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'anlegen', 'draft', 'kapitel'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook_kapitel.POST', 'foodalchemist.foodbook_blocks.POST', 'foodalchemist.foodbook.GET'],
            'examples' => ['Lege ein Foodbook "Sommerhochzeiten 2027" mit Kapiteln Empfang/Vorspeisen/Hauptgänge an'],
        ];
    }
}
