<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Format im Detail — Identität + Editionen + Bildwelt + Preis-Range. */
class FormatsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Format im Detail: Identität (name, consumer_name, claim, story, origin), '
            . 'die zugeordneten Editionen (Concepts) in Reihenfolge, die Marketing-Bildwelt und die '
            . 'read-only Preis-Range (Min–Max €/Person über die Editionen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['format_id' => ['type' => 'integer']],
            'required' => ['format_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(FormatService::class);
        $f = $svc->detail($team, (int) $arguments['format_id']);
        if ($f === null) {
            return ToolResult::error('Format nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'format' => [
                'id' => $f->id, 'name' => $f->name, 'consumer_name' => $f->consumer_name,
                'claim' => $f->claim, 'story' => $f->story, 'origin' => $f->origin,
                'customer' => $f->customer, 'status' => $f->status,
                'price_range' => $f->priceRange(),
            ],
            'editions' => $f->editions->map(fn ($e) => [
                'concept_id' => $e->id, 'name' => $e->name, 'consumer_name' => $e->consumer_name,
                'status' => $e->status, 'position' => (int) $e->format_position,
                'price_per_person' => $e->price_per_person_cache !== null ? (float) $e->price_per_person_cache : null,
            ])->all(),
            'images' => $f->images->map(fn ($i) => [
                'id' => $i->id, 'caption' => $i->caption, 'is_hero' => (bool) $i->is_hero,
                'sort_order' => (int) $i->sort_order, 'url' => $i->url(),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'detail', 'editionen'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.LIST', 'foodalchemist.format_editions.POST'],
            'examples' => ['Zeig mir Format 3 mit allen Editionen'],
        ];
    }
}
