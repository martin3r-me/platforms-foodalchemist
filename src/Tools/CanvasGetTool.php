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
        return 'Liest einen Canvas: type=food_dna (Team-Markenkern — owner_id weglassen), kunde_dna (owner_id = CRM-Firma) oder '
            . 'foodbook|concept|angebot (owner_id = jeweilige Entity). Liefert Template-Felder + Werte. '
            . 'PFLICHT vor Rezept-/Konzept-/Foodbook-Kreation: erst die Food DNA lesen (Leitbild, Aromatik, No-Gos).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['food_dna', 'kunde_dna', 'foodbook', 'concept', 'angebot']],
                'owner_id' => ['type' => 'integer', 'description' => 'Entity-ID (bei food_dna weglassen = eigenes Team; bei kunde_dna = CRM-Firma)'],
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
        $ownerType = match ($type) {
            'food_dna' => 'team',
            'kunde_dna' => 'crm_company',   // Spec 82 §7: Kunden-DNA (Ebene 2) war im MCP nicht erreichbar
            default => $type,
        };
        $ownerId = $type === 'food_dna' ? $team->id : (int) ($arguments['owner_id'] ?? 0);
        if ($ownerId === 0) {
            return ToolResult::error('owner_id ist Pflicht für diesen Canvas-Typ.', 'VALIDATION_ERROR');
        }
        // kunde_dna: owner_id = CRM-Firma. Nur Firmen des eigenen Haupt-Teams (wie die Einstellungs-Seite).
        if ($type === 'kunde_dna' && \Platform\FoodAlchemist\Support\CrmKunden::firma($team, $ownerId) === null) {
            return ToolResult::error("Firma {$ownerId} nicht sichtbar/vorhanden (Kundenverwaltung des Haupt-Teams).", 'NOT_FOUND');
        }
        $ownerModel = match ($type) {
            'foodbook' => FoodAlchemistFoodbook::class,
            'concept' => FoodAlchemistConcept::class,
            'angebot' => FoodAlchemistAngebot::class,
            default => null,
        };
        if ($ownerModel !== null && ! $ownerModel::visibleToTeam($team)->whereKey($ownerId)->exists()) {
            return ToolResult::error(ucfirst($type) . " {$ownerId} nicht sichtbar/vorhanden.", 'NOT_FOUND');
        }

        $canvas = $svc->find($type, $ownerType, $ownerId);
        $template = $svc->template($type);

        return ToolResult::success([
            'title' => $template['title'],
            'felder' => array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'group_name' => $f['group_name'] ?? null, 'type' => $f['type'], 'zusatz' => (bool) ($f['zusatz'] ?? false)], $svc->felder($type, $team)),
            'werte' => $canvas !== null ? $svc->werte($canvas, $team) : [],
            // Spec 82 §7: welche Skalar-Felder verbindlich sind (Repeatable: je Eintrag `is_binding` in `werte`)
            'verbindlich' => $canvas !== null ? $svc->verbindlichkeit($canvas) : [],
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
