<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Rubrik (Gliederungsknoten, z. B. Vorspeisen/Hauptgänge) einer Speisekarte anlegen. */
class SpeisekarteRubrikPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_rubrik.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Rubrik (Gliederungsknoten) in einer Speisekarte an. parent_id für Unter-Rubriken '
            . '(z. B. Hauptgänge → Fleisch). art ∈ speisen|getraenke|menue|dessert|sonstiges (Layout-Hinweis).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'consumer_title' => ['type' => 'string', 'description' => 'Gast-/Druck-Titel (optional)'],
                'art' => ['type' => 'string', 'enum' => ['speisen', 'getraenke', 'menue', 'dessert', 'sonstiges'], 'default' => 'speisen'],
                'parent_id' => ['type' => 'integer', 'description' => 'Eltern-Rubrik für Verschachtelung'],
            ],
            'required' => ['speisekarte_id', 'title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $rubrik = app(SpeisekarteService::class)->addRubrik(
                $team,
                (int) $arguments['speisekarte_id'],
                [
                    'title' => (string) $arguments['title'],
                    'consumer_title' => $arguments['consumer_title'] ?? null,
                    'art' => $arguments['art'] ?? 'speisen',
                ],
                isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null,
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'rubrik' => [
                'id' => $rubrik->id, 'title' => $rubrik->title, 'art' => $rubrik->art,
                'parent_id' => $rubrik->parent_id, 'position' => $rubrik->position,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'rubrik', 'anlegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarten.POST', 'foodalchemist.speisekarte_positionen.POST'],
            'examples' => ['Lege in Speisekarte 3 die Rubrik "Hauptgänge" an'],
        ];
    }
}
