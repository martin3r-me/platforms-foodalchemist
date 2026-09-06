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
 * Spec 50 · C-7 — das Planungs-Gerüst eines Angebots in Kapitel materialisieren.
 *
 * Der Anlass: bis hierher endete jeder Weg ins Angebot bei einem einzigen Kapitel „Menü"
 * ({@see OfferCompositionService::defaultKapitel()}). Die Slot-Labels des Gerüsts
 * („Aperitif", „Hauptgang", „Dessert") wurden zwar geplant, kamen im Kundendokument aber
 * nie an — und die Vollständigkeits-Messung hatte am Angebot nichts, wogegen sie messen
 * konnte. Genau die Lücke, die das Foodbook seit Spec 19 nicht mehr hat.
 *
 * Idempotent: mehrfaches Aufrufen legt nichts doppelt an.
 */
class OfferStrukturAusGeruestTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer.STRUKTUR_AUS_GERUEST';
    }

    public function getDescription(): string
    {
        return 'Materialisiert das Planungs-Gerüst eines team-eigenen Angebots als Kapitel: je Gerüst-Slot '
            . 'ein Kapitel (Titel = Slot-Label), Slot-Ziele (target_count, price_anchor/min/max) wandern mit. '
            . 'Idempotent — bereits verknüpfte Slots bleiben unberührt. Ohne Gerüst passiert nichts '
            . '(kein_geruest=true); ein Gerüst entsteht über foodalchemist.angebot.PLAN_FROM_BRIEF.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'offer_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
            ],
            'required' => ['offer_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $offerId = (int) ($arguments['offer_id'] ?? 0);
        if ($offerId <= 0) {
            return ToolResult::error('offer_id fehlt.', 'VALIDATION_ERROR');
        }
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $offerId, 'Angebot')) !== null) {
            return $guard;
        }

        try {
            $ergebnis = app(OfferCompositionService::class)->strukturAusGeruest($team, $offerId);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $reife = app(\Platform\FoodAlchemist\Services\ReifeService::class)->kurz($team, 'angebot', $offerId);

        return ToolResult::success(
            ['offer_id' => $offerId] + $ergebnis + ($reife !== null ? ['reife' => $reife] : [])
        );
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'geruest', 'struktur', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.angebot.PLAN_FROM_BRIEF', 'foodalchemist.offer_chapter.POST', 'foodalchemist.reife.GET'],
            'examples' => ['Baue aus dem Gerüst von Angebot 8 die Kapitel.'],
        ];
    }
}
