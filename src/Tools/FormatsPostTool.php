<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Format anlegen — immer status=draft (Aktivierung menschlich). */
class FormatsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Format (Marken-/Themen-Container über den Konzepten) als ENTWURF an (status=draft). '
            . 'Editionen (bestehende Konzepte) danach via foodalchemist.format_editions.POST zuordnen. '
            . 'origin ∈ eigen|gruppe|kunde (kunde = Kunden-IP, nie fremd wiederverwenden).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'consumer_name' => ['type' => 'string'],
                'claim' => ['type' => 'string'],
                'story' => ['type' => 'string', 'description' => 'Marken-Story (Marketing-Text)'],
                'origin' => ['type' => 'string', 'enum' => ['eigen', 'gruppe', 'kunde']],
                'customer' => ['type' => 'string', 'description' => 'bei origin=kunde: Besitzer-Kunde'],
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
            $f = app(FormatService::class)->create($team, array_intersect_key($arguments, array_flip([
                'name', 'consumer_name', 'claim', 'story', 'origin', 'customer',
            ])) + ['status' => 'draft']);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'format' => ['id' => $f->id, 'name' => $f->name, 'status' => $f->status, 'origin' => $f->origin],
            'note' => 'Entwurf: aktiv setzen macht ein Mensch im Formate-Modul. Editionen via foodalchemist.format_editions.POST.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.format_editions.POST', 'foodalchemist.formats.GET'],
            'examples' => ['Lege ein Format "CHEFS.CORNER – WORLD ON A PLATE" an'],
        ];
    }
}
