<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/** Phase C: Concept-Detail mit Slots (Gerichte/Pakete/Struktur) + Sektor-Eignung. */
class ConceptsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Gerichte-Konzept im Detail: Stammdaten (anlass, niveau, klasse, zielpreis) + '
            . 'alle Slots in Reihenfolge (type gericht|basisrezept|paket|Struktur, Gericht-Name, VK, Wording).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['concept_id' => ['type' => 'integer']],
            'required' => ['concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ConceptService::class);
        $c = $svc->detail($team, (int) $arguments['concept_id']);
        if ($c === null) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'concept' => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'class' => $c->class,
                'occasion' => $c->occasion, 'level' => $c->level, 'description' => $c->description,
                'target_price_per_person' => $c->target_price_per_person, 'season' => $c->season,
                'target_group' => $c->target_group, 'sektor_eignung' => $svc->sektorEignungSlugs($c),
            ],
            'slots' => $c->slots->map(fn ($s) => [
                'id' => $s->id, 'position' => $s->position, 'type' => $s->type, 'role' => $s->role,
                'title' => $s->title, 'wording' => $s->wording, 'is_pflicht' => (bool) $s->is_pflicht,
                'vk_recipe' => $s->gericht ? ['id' => $s->gericht->id, 'name' => $s->gericht->name, 'sales_net' => $s->gericht->sales_net] : null,
                'paket' => $s->paket ? ['id' => $s->paket->id, 'name' => $s->paket->name, 'price_per_person' => $s->paket->price_per_person] : null,
                'quantity' => $s->quantity, 'unit' => $s->unit?->slug,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'slots', 'detail'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.SEARCH', 'foodalchemist.concept_slots.POST', 'foodalchemist.kalkulation.GET'],
            'examples' => ['Zeig mir Konzept 42 mit allen Slots'],
        ];
    }
}
