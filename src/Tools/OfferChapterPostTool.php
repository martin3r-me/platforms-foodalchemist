<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\OfferCompositionService;

/**
 * #380 Composer · MCP-Lockstep: Kapitel im Angebot-Aufbau anlegen (auch verschachtelt via
 * parent_id, Position ans Ende). Spiegelt FoodbookKapitelPostTool, aber offer-scoped über
 * {@see OfferCompositionService::addKapitel}. Owner-Guard (D1) vor dem Write; nur team-eigene Angebote.
 */
class OfferChapterPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer_chapter.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Kapitel im Angebot-Composer an (parent_id für Unterkapitel, Position ans Ende). '
            . 'Optional: consumer_title (Kundentitel), claim, description, personen (Per-Kapitel-Pax; sonst erbt '
            . 'das Kapitel die Angebots-Pax), price_per_person (setzt price_mode=manuell — sonst auto-Aggregation '
            . 'aus den Blöcken). Angebot-Id via foodalchemist.angebote.GET/SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'offer_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'title' => ['type' => 'string', 'description' => 'Interner Kapitel-Titel.'],
                'parent_id' => ['type' => 'integer', 'description' => 'Übergeordnetes Kapitel für Verschachtelung.'],
                'consumer_title' => ['type' => 'string', 'description' => 'Kundenseitiger Titel, falls abweichend.'],
                'claim' => ['type' => 'string'],
                'description' => ['type' => 'string', 'description' => 'Hinführungstext (Kundensicht).'],
                'personen' => ['type' => 'integer', 'description' => 'Per-Kapitel-Pax; weglassen = erbt Angebots-Pax.'],
                'price_per_person' => ['type' => 'number', 'description' => 'Fix-Preis p. P.; weglassen = auto aus Blöcken.'],
            ],
            'required' => ['offer_id', 'title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $offerId = (int) ($arguments['offer_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $offerId, 'Angebot')) !== null) {
            return $guard;
        }
        $svc = app(OfferCompositionService::class);

        try {
            $in = ['title' => (string) $arguments['title']];
            if (isset($arguments['consumer_title'])) {
                $in['consumer_title'] = (string) $arguments['consumer_title'];
            }
            if (isset($arguments['price_per_person'])) {
                $in['price_mode'] = 'manuell';   // OfferChapter kennt auto|manuell (kein „fix")
            }
            $k = $svc->addKapitel($team, $offerId, $in, isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null);
            $extras = array_intersect_key($arguments, array_flip(['consumer_title', 'claim', 'description', 'personen', 'price_per_person']));
            if ($extras !== []) {
                $k = $svc->updateKapitel($team, $k->id, $extras);
            }
        } catch (ModelNotFoundException) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel' => [
            'id' => (int) $k->id,
            'title' => $k->title,
            'consumer_title' => $k->consumer_title,
            'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null,
            'position' => (int) $k->position,
            'price_mode' => $k->price_mode,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'kapitel', 'anlegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.offer_block.POST', 'foodalchemist.offer.INSERT_FORMAT', 'foodalchemist.angebote.GET'],
            'examples' => ['Füge Angebot 5 ein Kapitel „Empfang" hinzu'],
        ];
    }
}
