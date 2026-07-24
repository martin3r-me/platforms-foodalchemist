<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E3.5): Zielgruppen-Vokabular lesen.
 * Eigenes FA-Vokabular (Entscheidung 4) — z. B. Tagungsgast/Bankett-Gast/VIP-Gala.
 * Ein Foodbook wählt daraus 1–n Default-Zielgruppen, ein Kapitel 1–n; beim
 * Kapitel-Go wird das aufgelöste Set aufs Konzept gestempelt. Read-only, team-scoped.
 */
class ZielgruppenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.zielgruppen.GET';
    }

    public function getDescription(): string
    {
        return 'Listet das team-sichtbare Zielgruppen-Vokabular (id, name, description, aktiv). '
            . 'Zielgruppen sind das FA-eigene Publikums-Vokabular (Tagungsgast, Bankett-Gast, VIP-Gala …); '
            . 'ein Foodbook/Kapitel wählt daraus 1–n. Neue Einträge via foodalchemist.zielgruppen.POST.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => ['type' => 'boolean', 'default' => true, 'description' => 'false = nur aktive Zielgruppen'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(FoodbookService::class);
        $zeilen = $svc->zielgruppenListe($team, (bool) ($arguments['include_inactive'] ?? true))
            ->map(fn ($z) => [
                'id' => $z->id,
                'name' => $z->name,
                'description' => $z->description,
                'sort_order' => (int) $z->sort_order,
                'is_inactive' => (bool) $z->is_inactive,
                'is_owned' => $z->isOwnedBy($team),
            ])->values()->all();

        return ToolResult::success(['zielgruppen' => $zeilen, 'total' => count($zeilen)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'foodbook', 'zielgruppen', 'vokabular', 'leitstelle'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.zielgruppen.POST', 'foodalchemist.foodbooks.POST', 'foodalchemist.foodbook.GET'],
            'examples' => ['Welche Zielgruppen kennt das System?', 'Zeig mir die verfügbaren Publikums-Vokabeln'],
        ];
    }
}
