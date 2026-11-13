<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Concepter-Paket (Entwurf) löschen. */
class PaketeDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.DELETE';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Löscht ein team-eigenes Paket im Entwurf (Soft-Delete). Erfordert confirm=true. '
            . 'Eingebettet in Konzepten? Vorher dort entfernen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $paket = $this->paketConcept($team, (int) ($arguments['id'] ?? 0));
        if ($paket instanceof ToolResult) {
            return $paket;
        }
        $svc = app(ConceptService::class);
        $in = $svc->eingebettetInConcepts($team, $paket->id);
        if ($in->isNotEmpty()) {
            return ToolResult::error('Paket ist in ' . $in->count() . ' Konzept(en) eingehängt. Zuerst dort entfernen (concept_slots.DELETE).', 'HAS_REFERENCES');
        }

        try {
            $svc->delete($team, $paket->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $paket->id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.pakete.PUT'],
            'examples' => ['Lösche Paket 12 (confirm=true).'],
        ];
    }
}
