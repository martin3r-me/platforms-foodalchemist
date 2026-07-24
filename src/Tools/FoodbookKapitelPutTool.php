<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E4.6): Kapitel-SOLL pflegen — Ziele (Menge/Preis/
 * Niveau/Servierform/Einsatzmoment/Ziel-Wareneinsatz), `pricing_mode` (paket|einzel|
 * gemischt) und die 1–n Zielgruppen (überschreiben den Foodbook-Default in der
 * Kaskade). Nur solange das Foodbook draft ist. Vokabular-Pflicht (Entscheidung 6):
 * serving_form_id/service_moment_id/zielgruppen referenzieren team-sichtbares
 * Vokabular per FK; freie Klassifikations-Strings sind nicht erlaubt.
 *
 * NICHT hier: `released_*`-Anlage-Spalten (setzt der Kapitel-Go, E7.3 — human-only,
 * ohne MCP-Trigger).
 */
class FoodbookKapitelPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt die SOLL-Ziele + Zielgruppen eines Kapitels (nur draft-Foodbook): '
            . 'target_count (Mengenziel), price_anchor/price_min/price_max (€ p. P.), niveau, '
            . 'serving_form_id/service_moment_id (Vokabular), pricing_mode (paket|einzel|gemischt), '
            . 'target_food_cost_pct (WE-Ziel), zielgruppen[] (setzt die Liste komplett). '
            . 'Optional auch Titel/Claim/Beschreibung. Anlage/Freigabe macht ein Mensch (Kapitel-Go, kein MCP).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kapitel_id' => ['type' => 'integer'],
                // Inhalt (Freitext erlaubt — echter Inhalt, keine Klassifikation)
                'title' => ['type' => 'string'],
                'consumer_title' => ['type' => 'string', 'description' => 'Kundenseitiger Titel, falls abweichend'],
                'claim' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                // SOLL-Ziele (M3)
                'target_count' => ['type' => 'integer', 'description' => 'Mengenziel (Anzahl Positionen im Kapitel-Rollup)'],
                'price_anchor' => ['type' => 'number', 'description' => 'Ziel-Preisanker € p. P.'],
                'price_min' => ['type' => 'number', 'description' => 'Untere Preisspanne € p. P.'],
                'price_max' => ['type' => 'number', 'description' => 'Obere Preisspanne € p. P.'],
                'niveau' => ['type' => 'string', 'description' => 'Kanonisches Niveau (kaskadiert an Konzepte via denormNiveauFuerConcept)'],
                'serving_form_id' => ['type' => 'integer', 'description' => 'Servierform (Vokabular; Scharnier zur Darreichungs-Auflösung)'],
                'service_moment_id' => ['type' => 'integer', 'description' => 'Einsatzmoment/Tagesablauf (Vokabular)'],
                'pricing_mode' => ['type' => 'string', 'enum' => FoodAlchemistFoodbookKapitel::PRICING_MODES, 'description' => 'paket | einzel | gemischt'],
                'target_food_cost_pct' => ['type' => 'number', 'description' => 'Ziel-Wareneinsatz in % (WE-Ampel-SOLL)'],
                'zielgruppen' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'IDs der Kapitel-Zielgruppen (1–n; SETZT die Liste komplett, [] = leeren). Via foodalchemist.zielgruppen.GET.',
                ],
            ],
            'required' => ['kapitel_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kap = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->whereKey((int) $arguments['kapitel_id'])->first();
        if ($kap === null) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $fb = $kap->foodbook;
        if ($fb === null) {
            return ToolResult::error('Kapitel ohne Foodbook.', 'NOT_FOUND');
        }
        if ((string) $fb->status !== 'draft') {
            return ToolResult::error("Foodbook hat Status \"{$fb->status}\" — via MCP ist nur draft editierbar.", 'ACCESS_DENIED');
        }

        // Vokabular-Pflicht (Entscheidung 6): FK/Enum VOR dem Write validieren.
        if (isset($arguments['pricing_mode'])
            && ! in_array((string) $arguments['pricing_mode'], FoodAlchemistFoodbookKapitel::PRICING_MODES, true)) {
            return ToolResult::error('pricing_mode muss paket|einzel|gemischt sein.', 'VALIDATION_ERROR');
        }
        if (isset($arguments['serving_form_id'])
            && ! FoodAlchemistServierform::visibleToTeam($team)->whereKey((int) $arguments['serving_form_id'])->exists()) {
            return ToolResult::error('serving_form_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (isset($arguments['service_moment_id'])
            && ! FoodAlchemistEinsatzmoment::visibleToTeam($team)->whereKey((int) $arguments['service_moment_id'])->exists()) {
            return ToolResult::error('service_moment_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $setztZielgruppen = array_key_exists('zielgruppen', $arguments);
        $zielgruppenIds = array_map('intval', (array) ($arguments['zielgruppen'] ?? []));
        if ($zielgruppenIds !== []
            && FoodAlchemistTargetGroup::visibleToTeam($team)->whereKey($zielgruppenIds)->count() !== count(array_unique($zielgruppenIds))) {
            return ToolResult::error('Mindestens eine zielgruppen-ID ist nicht sichtbar/vorhanden — via foodalchemist.zielgruppen.GET ermitteln.', 'NOT_FOUND');
        }

        $felder = array_intersect_key($arguments, array_flip([
            'title', 'consumer_title', 'claim', 'description',
            'target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
            'serving_form_id', 'service_moment_id', 'pricing_mode', 'target_food_cost_pct',
        ]));

        $svc = app(FoodbookService::class);
        try {
            if ($felder !== []) {
                $kap = $svc->updateKapitel($team, $kap->id, $felder);
            }
            if ($setztZielgruppen) {
                $svc->setKapitelZielgruppen($team, $kap->id, $zielgruppenIds);
            }
        } catch (\RuntimeException $e) {
            // ownedKapitel wirft bei geerbtem Kapitel (D1).
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        }

        $kap = $kap->fresh();

        return ToolResult::success(['kapitel' => [
            'id' => $kap->id,
            'title' => $kap->title,
            'parent_id' => $kap->parent_id,
            'target_count' => $kap->target_count !== null ? (int) $kap->target_count : null,
            'price_anchor' => $kap->price_anchor,
            'price_min' => $kap->price_min,
            'price_max' => $kap->price_max,
            'niveau' => $kap->niveau,
            'serving_form_id' => $kap->serving_form_id !== null ? (int) $kap->serving_form_id : null,
            'service_moment_id' => $kap->service_moment_id !== null ? (int) $kap->service_moment_id : null,
            'pricing_mode' => $kap->pricing_mode,
            'target_food_cost_pct' => $kap->target_food_cost_pct,
            'zielgruppen_ids' => $kap->targetGroups()->pluck('foodalchemist_target_groups.id')->map(fn ($v) => (int) $v)->all(),
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'ziele', 'zielgruppen', 'leitstelle', 'draft'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook_kapitel.POST', 'foodalchemist.zielgruppen.GET', 'foodalchemist.coverage.GET'],
            'examples' => ['Setze für Kapitel 8 das Mengenziel 6, Preisanker 24 € und pricing_mode paket'],
        ];
    }
}
