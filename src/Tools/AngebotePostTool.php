<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * Phase C: Angebot anlegen — startet im Eingangs-Status (anfrage). Status-
 * Fortschritt (angebot/gewonnen/…) + CRM-Verknüpfung macht ein Mensch.
 * Optional direkt mit angebots-lokalen Concepts (Namen).
 */
class AngebotePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Angebot im Eingangs-Status (anfrage) an, optional direkt mit angebots-lokalen '
            . 'Concepts (concepts: Liste von Namen — danach via foodalchemist.concept_slots.POST befüllen). '
            . 'Status-Wechsel und CRM-Kunde macht ein Mensch im Editor.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'z. B. "Sommerfest Musterfirma 2027"'],
                'anlass' => ['type' => 'string'],
                'personen' => ['type' => 'integer'],
                'concepts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Namen angebots-lokaler Concepts'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(AngebotService::class);

        try {
            $a = $svc->create($team, [
                'name' => (string) $arguments['name'],
                'anlass' => $arguments['anlass'] ?? null,
                'personen' => $arguments['personen'] ?? null,
            ]);
            $concepts = [];
            foreach (array_values((array) ($arguments['concepts'] ?? [])) as $name) {
                $c = $svc->neuesConcept($team, $a->id, (string) $name);
                $concepts[] = ['id' => $c->id, 'name' => $c->name];
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'angebot' => ['id' => $a->id, 'name' => $a->name,
                'status' => $a->status instanceof \BackedEnum ? $a->status->value : $a->status],
            'concepts' => $concepts,
            'hinweis' => 'Status-Fortschritt + CRM-Verknüpfung macht ein Mensch.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'anfrage', 'anlegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concept_slots.POST', 'foodalchemist.angebote.GET'],
            'examples' => ['Lege ein Angebot "Sommerfest ACME, 120 Pax" mit Concepts Empfang + Dinner an'],
        ];
    }
}
