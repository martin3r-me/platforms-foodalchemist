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

/**
 * Phase C: Canvas-Felder setzen (legt den Canvas bei Bedarf an). Nur Keys aus
 * dem festen Template des Typs — der Canvas ist Briefing-/Kontext-Ebene,
 * keine Verkaufsdaten (daher kein Draft-Guard nötig).
 */
class CanvasPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.canvas.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt Felder eines Canvas (food_dna|foodbook|concept|angebot; legt ihn bei Bedarf an). '
            . 'felder = {key: wert} — gültige Keys via foodalchemist.canvas.GET (Template). '
            . 'Leerer Wert löscht das Feld.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['food_dna', 'foodbook', 'concept', 'angebot']],
                'owner_id' => ['type' => 'integer', 'description' => 'Entity-ID (bei food_dna weglassen = eigenes Team)'],
                'felder' => ['type' => 'object', 'description' => 'Map key→Wert, Keys aus dem Template (canvas.GET)'],
            ],
            'required' => ['type', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(CanvasService::class);
        $type = (string) $arguments['type'];
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
                return ToolResult::error('Geerbtes/fremdes ' . ucfirst($type) . ' — Canvas pflegt nur das Besitzer-Team (D1).', 'ACCESS_DENIED');
            }
        }

        $erlaubt = array_column($svc->template($type)['felder'], 'key');
        $felder = (array) $arguments['felder'];
        $unbekannt = array_diff(array_keys($felder), $erlaubt);
        if ($unbekannt !== []) {
            return ToolResult::error('Unbekannte Canvas-Keys: ' . implode(', ', $unbekannt) . '. Gültig: ' . implode(', ', $erlaubt), 'VALIDATION_ERROR');
        }

        $canvas = $svc->canvasFor($team, $type, $ownerType, $ownerId);
        foreach ($felder as $key => $wert) {
            $svc->setSkalar($canvas, (string) $key, ($wert === '' || $wert === null) ? null : (string) $wert);
        }

        return ToolResult::success([
            'type' => $type, 'owner_id' => $ownerId,
            'gesetzt' => array_keys($felder),
            'werte' => $svc->werte($canvas->refresh()),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'canvas', 'food-dna', 'dna', 'briefing', 'update'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates', 'updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.canvas.GET'],
            'examples' => ['Trage ins Food-DNA-Canvas die Aromatik "mediterran, rauchig" ein'],
        ];
    }
}
