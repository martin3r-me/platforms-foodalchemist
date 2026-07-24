<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E3.5): neue Zielgruppe ins Vokabular aufnehmen.
 * Immer team-eigen (das Besitzer-Team pflegt sein Vokabular); Dedup gegen den
 * eigenen Bestand. Vokabular-Pflicht (Entscheidung 6): Foodbook/Kapitel referenzieren
 * Zielgruppen NUR per FK — deshalb entstehen sie hier, nicht als Freitext am Konzept.
 */
class ZielgruppenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.zielgruppen.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine neue Zielgruppe im Vokabular an (team-eigen), z. B. "Tagungsgast" oder "VIP-Gala". '
            . 'Dedup gegen den eigenen Bestand (doppelter Name = VALIDATION_ERROR). '
            . 'Danach als Default am Foodbook (foodalchemist.foodbooks.POST → zielgruppen[]) oder pro Kapitel wählbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name der Zielgruppe, z. B. "Bankett-Gast"'],
                'description' => ['type' => 'string', 'description' => 'Optionale Beschreibung / Abgrenzung'],
                'sort_order' => ['type' => 'integer', 'description' => 'Sortier-Reihung (Default 100)'],
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
        $svc = app(FoodbookService::class);

        try {
            $zg = $svc->zielgruppeAnlegen($team, [
                'name' => (string) $arguments['name'],
                'description' => $arguments['description'] ?? null,
                'sort_order' => $arguments['sort_order'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['zielgruppe' => [
            'id' => $zg->id,
            'name' => $zg->name,
            'description' => $zg->description,
            'sort_order' => (int) $zg->sort_order,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'zielgruppen', 'vokabular', 'anlegen', 'leitstelle'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.zielgruppen.GET', 'foodalchemist.foodbooks.POST'],
            'examples' => ['Lege eine neue Zielgruppe "VIP-Gala" an'],
        ];
    }
}
