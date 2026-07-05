<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/** Phase C: Gerichte-Konzept anlegen — immer status=draft (Aktivierung menschlich). */
class ConceptsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Gerichte-Konzept als ENTWURF an (status=draft). Slots (Gerichte/Pakete) danach '
            . 'via foodalchemist.concept_slots.POST. brief = KI-Arbeitsauftrag ans Konzept; zielpreis_pro_person '
            . 'für die Kalkulations-Leitplanke.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'occasion' => ['type' => 'string'],
                'level' => ['type' => 'string'],
                'class' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'brief' => ['type' => 'string', 'description' => 'KI-Brief: was soll das Konzept leisten'],
                'target_price_per_person' => ['type' => 'number'],
                'season' => ['type' => 'string'],
                'target_group' => ['type' => 'string'],
                'diet_requirement' => ['type' => 'string'],
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
        $svc = app(ConceptService::class);

        try {
            $c = $svc->create($team, [
                'name' => (string) $arguments['name'],
                'occasion' => $arguments['occasion'] ?? null,
                'level' => $arguments['level'] ?? null,
                'class' => $arguments['class'] ?? null,
                'status' => 'draft',
            ]);
            $extras = array_intersect_key($arguments, array_flip([
                'description', 'brief', 'target_price_per_person', 'season', 'target_group', 'diet_requirement',
            ]));
            if ($extras !== []) {
                $c = $svc->update($team, $c->id, $extras);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'concept' => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status],
            'note' => 'Entwurf: aktiv setzen macht ein Mensch im Concepter.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concept_slots.POST', 'foodalchemist.concepts.GET'],
            'examples' => ['Lege ein Konzept "Streetfood-Hochzeit" mit Zielpreis 45 € p. P. an'],
        ];
    }
}
