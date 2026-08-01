<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Speisekarte duplizieren (Wechsel-/Saison-/Tageskarte aus einer Basis). */
class SpeisekartenDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarten.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Dupliziert eine Speisekarte inkl. Rubrik-Baum + Positionen als neuen ENTWURF '
            . '(z. B. Sommerkarte aus der Standardkarte ableiten). name/karten_typ optional überschreibbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'Name der Kopie (Default: „… (Kopie)")'],
                'karten_typ' => ['type' => 'string', 'enum' => ['alacarte', 'tageskarte', 'saisonkarte', 'getraenkekarte', 'weinkarte']],
            ],
            'required' => ['speisekarte_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $overrides = array_intersect_key($arguments, array_flip(['name', 'karten_typ']));

        try {
            $neu = app(SpeisekarteService::class)->dupliziere($team, (int) $arguments['speisekarte_id'], $overrides);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'speisekarte' => [
                'id' => $neu->id, 'name' => $neu->name, 'status' => $neu->status,
                'karten_typ' => $neu->karten_typ, 'rubriken' => $neu->sections()->count(),
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'duplizieren', 'wechselkarte'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarten.POST'],
            'examples' => ['Dupliziere Speisekarte 3 als "Sommerkarte 2026"'],
        ];
    }
}
