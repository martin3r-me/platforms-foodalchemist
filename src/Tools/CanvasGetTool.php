<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\CanvasService;

/**
 * Phase C: Canvas lesen — Food DNA (Team-Markenkern) + Briefing-Canvases an
 * Foodbook/Concept/Angebot. VOR jeder Kreation die Food DNA laden (verbindlicher
 * Stil-/Geschmacks-Rahmen des Mandanten).
 */
class CanvasGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.canvas.GET';
    }

    public function getDescription(): string
    {
        return 'Liest einen Canvas: type=food_dna (Team-Markenkern — owner_id weglassen) oder '
            . 'foodbook|concept|angebot (owner_id = jeweilige Entity). Liefert Template-Felder + Werte. '
            . 'PFLICHT vor Rezept-/Konzept-/Foodbook-Kreation: erst die Food DNA lesen (Leitbild, Aromatik, No-Gos).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['food_dna', 'foodbook', 'concept', 'angebot']],
                'owner_id' => ['type' => 'integer', 'description' => 'Entity-ID (bei food_dna weglassen = eigenes Team)'],
            ],
            'required' => ['type'],
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

        $canvas = $svc->find($type, $ownerType, $ownerId);
        $template = $svc->template($type);

        return ToolResult::success([
            'titel' => $template['titel'],
            'felder' => array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'gruppe' => $f['gruppe'] ?? null, 'typ' => $f['typ']], $template['felder']),
            'werte' => $canvas !== null ? $svc->werte($canvas) : [],
            'existiert' => $canvas !== null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'canvas', 'food-dna', 'dna', 'markenkern', 'briefing'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.canvas.PUT', 'foodalchemist.recipes.POST', 'foodalchemist.concepts.POST'],
            'examples' => ['Wie lautet die Food DNA des Teams?', 'Lies den Briefing-Canvas von Foodbook 12'],
        ];
    }
}
