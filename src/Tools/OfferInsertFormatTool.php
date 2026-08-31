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
 * #380 Composer · MCP-Lockstep: ein Format als LEBENDES Kapitel in ein Angebot einfügen (Kapitel
 * mit format_id — Identität + Editionen rendern live aus dem Format). Spiegelt FoodbookInsertFormatTool,
 * offer-scoped über {@see OfferCompositionService::insertFormatKapitel}. Owner-Guard (D1) übers Angebot.
 */
class OfferInsertFormatTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer.INSERT_FORMAT';
    }

    public function getDescription(): string
    {
        return 'Fügt ein Format als lebendes Kapitel in ein team-eigenes Angebot ein (Kapitel mit format_id: '
            . 'Titel/Kundentitel/Claim/Hinführung + Editionen kommen live aus dem Format). Wie die Editionen in den '
            . 'Angebotspreis einfallen (additiv | alternativen) steuert danach der Editor. Angebot-Id via '
            . 'foodalchemist.angebote.GET, Format-Id via foodalchemist.formats.GET/SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'offer_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'format_id' => ['type' => 'integer', 'description' => 'Einzufügendes Format.'],
                'parent_id' => ['type' => 'integer', 'description' => 'Optionales Eltern-Kapitel.'],
            ],
            'required' => ['offer_id', 'format_id'],
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

        try {
            $kapitel = app(OfferCompositionService::class)->insertFormatKapitel(
                $team,
                $offerId,
                (int) $arguments['format_id'],
                isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null,
            );
        } catch (ModelNotFoundException) {
            return ToolResult::error('Angebot oder Format nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel' => [
            'id' => (int) $kapitel->id,
            'title' => $kapitel->title,
            'consumer_title' => $kapitel->consumer_title,
            'format_id' => (int) $kapitel->format_id,
            'format_price_mode' => $kapitel->format_price_mode,
            'position' => (int) $kapitel->position,
        ], 'note' => 'Format als lebendes Kapitel eingefügt — Editionen rendern live aus dem Format.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'format', 'kapitel', 'einfuegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.offer_chapter.POST', 'foodalchemist.formats.GET'],
            'examples' => ['Füge das Format CHEFS.CORNER als Kapitel in Angebot 5 ein'],
        ];
    }
}
