<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Speisekarte (Gastro-à-la-carte-Karte) als Entwurf anlegen. Rubriken/Positionen folgen separat. */
class SpeisekartenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarten.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Speisekarte als ENTWURF an (status=entwurf). Danach Rubriken via '
            . 'foodalchemist.speisekarte_rubrik.POST, Positionen via foodalchemist.speisekarte_positionen.POST. '
            . 'karten_typ ∈ alacarte|tageskarte|saisonkarte|getraenkekarte|weinkarte.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'karten_typ' => ['type' => 'string', 'enum' => ['alacarte', 'tageskarte', 'saisonkarte', 'getraenkekarte', 'weinkarte'], 'default' => 'alacarte'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optionaler Outlet/Betrieb-Anker'],
                'description' => ['type' => 'string'],
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

        try {
            $karte = app(SpeisekarteService::class)->create($team, [
                'name' => (string) $arguments['name'],
                'karten_typ' => $arguments['karten_typ'] ?? 'alacarte',
                'outlet_id' => $arguments['outlet_id'] ?? null,
                'description' => $arguments['description'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'speisekarte' => [
                'id' => $karte->id, 'name' => $karte->name, 'status' => $karte->status,
                'karten_typ' => $karte->karten_typ,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'gastronomie', 'anlegen', 'entwurf'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_rubrik.POST', 'foodalchemist.speisekarte_positionen.POST'],
            'examples' => ['Lege eine Speisekarte "Abendkarte" an'],
        ];
    }
}
