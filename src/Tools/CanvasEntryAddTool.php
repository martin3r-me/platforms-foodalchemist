<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\CanvasService;

/** MCP-Steuerbarkeit · D12: einen Listen-Eintrag zu einem Canvas-Feld hinzufügen (Food-DNA-„Welten"). */
class CanvasEntryAddTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.canvas.ENTRY_ADD';
    }

    public function getDescription(): string
    {
        return 'Fügt einen Listen-Eintrag zu einem Canvas-Feld hinzu (type food_dna|foodbook|concept|angebot, '
            . 'key = Feld-Key aus dem Template via canvas.GET, value). Legt den Canvas bei Bedarf an.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['food_dna', 'foodbook', 'concept', 'angebot']],
                'owner_id' => ['type' => 'integer', 'description' => 'Entity-Id (bei food_dna weglassen = eigenes Team).'],
                'key' => ['type' => 'string', 'description' => 'Feld-Key (aus canvas.GET-Template).'],
                'value' => ['type' => 'string', 'description' => 'Eintrags-Wert.'],
            ],
            'required' => ['type', 'key', 'value'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(CanvasService::class);
        $type = (string) ($arguments['type'] ?? '');
        $ownerType = $type === 'food_dna' ? 'team' : $type;
        $ownerId = $type === 'food_dna' ? $team->id : (int) ($arguments['owner_id'] ?? 0);
        if ($ownerId === 0) {
            return ToolResult::error('owner_id ist Pflicht für diesen Canvas-Typ.', 'VALIDATION_ERROR');
        }
        $ownerModel = match ($type) {
            'foodbook' => FoodAlchemistFoodbook::class,
            'concept' => FoodAlchemistConcept::class,
            'angebot' => FoodAlchemistAngebot::class,
            default => null,
        };
        if ($ownerModel !== null) {
            $owner = $ownerModel::visibleToTeam($team)->whereKey($ownerId)->first();
            if ($owner === null) {
                return ToolResult::error(ucfirst($type) . " {$ownerId} nicht sichtbar/vorhanden.", 'NOT_FOUND');
            }
            if (! $owner->isOwnedBy($team)) {
                return ToolResult::error('Geerbtes/fremdes ' . ucfirst($type) . ' — Canvas pflegt nur das Besitzer-Team.', 'ACCESS_DENIED');
            }
        }
        $key = trim((string) ($arguments['key'] ?? ''));
        $erlaubt = array_column($svc->template($type)['felder'], 'key');
        if ($key === '' || ! in_array($key, $erlaubt, true)) {
            return ToolResult::error('Unbekannter Canvas-Key. Gültig: ' . implode(', ', $erlaubt), 'VALIDATION_ERROR');
        }
        $value = trim((string) ($arguments['value'] ?? ''));
        if ($value === '') {
            return ToolResult::error('value darf nicht leer sein.', 'VALIDATION_ERROR');
        }

        $canvas = $svc->canvasFor($team, $type, $ownerType, $ownerId);
        $entry = $svc->addEntry($canvas, $key, $value);

        return ToolResult::success(['type' => $type, 'owner_id' => $ownerId, 'key' => $key, 'entry_id' => (int) $entry->id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'canvas', 'dna', 'entry', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.canvas.ENTRY_REMOVE', 'foodalchemist.canvas.PUT'],
            'examples' => ['Füge zur Food-DNA-Welt „Signaturgerichte" den Eintrag „Coq au Vin" hinzu.'],
        ];
    }
}
