<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/** M11-11: Foodbook-Detail (Kopf + Kapitel-Baum + Blöcke + aggregierter Angebotspreis). Read-only. */
class FoodbooksGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Foodbook im Detail: Kopf (Kunde, Pax, Status), Kapitel mit ihren Blöcken '
            . '(referenzierte Concepts bzw. Preis-/Text-Header) und den aggregierten Angebotspreis '
            . '(€/Person + Gesamt, live aus den Concepts gerechnet).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(FoodbookService::class);
        $fb = $svc->detail($team, (int) $arguments['id']);
        if ($fb === null) {
            return ToolResult::error('Foodbook nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'id' => $fb->id,
            'label' => $fb->label,
            'customer' => $fb->customer,
            'personen' => $fb->personen,
            'status' => $fb->status instanceof \BackedEnum ? $fb->status->value : $fb->status,
            'phase' => $fb->phase, // R4.3 Statusmaschine

            'price' => $svc->gesamt($team, $fb),
            'kapitel' => $fb->chapters->map(fn ($k) => [
                'id' => $k->id,
                'title' => $k->title,
                'parent_id' => $k->parent_id,
                'blocks' => $k->blocks->map(fn ($b) => [
                    'type' => $b->type,
                    'name' => $b->concept?->name ?? $b->label ?? $b->customer_text,
                    'price_per_person' => $b->concept?->price_per_person_cache ?? $b->price_value,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'foodbook', 'detail'],
            'examples' => ['Zeig mir das Foodbook 7'],
        ];
    }
}
