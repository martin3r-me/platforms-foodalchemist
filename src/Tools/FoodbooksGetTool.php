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
            'bezeichnung' => $fb->bezeichnung,
            'kunde' => $fb->kunde,
            'personen' => $fb->personen,
            'status' => $fb->status instanceof \BackedEnum ? $fb->status->value : $fb->status,
            'preis' => $svc->gesamt($team, $fb),
            'kapitel' => $fb->kapitel->map(fn ($k) => [
                'id' => $k->id,
                'titel' => $k->titel,
                'parent_id' => $k->parent_id,
                'blocks' => $k->blocks->map(fn ($b) => [
                    'type' => $b->type,
                    'name' => $b->concept?->name ?? $b->bezeichnung ?? $b->kundentext,
                    'preis_pro_person' => $b->concept?->preis_pro_person_cache ?? $b->preis_wert,
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
