<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningSessionService;

/**
 * Planungs-/Kreativ-Session anlegen (Doppel-Diamant, Spec 08). Entweder aus dem Trendradar
 * (Spec 79: `trend_ids`/`inspiration_ids` → ein kombiniertes Briefing) oder aus
 * einem freien Brief (`title` + optional `brief`). Status immer `divergenz`, team-scoped.
 *
 * Die Session erzeugt NICHTS — Skizzen sammeln + „Go" (human-only, UI) materialisiert Rezept/
 * Gericht/Concept. Skizzen legt {@see KapitelIdeenPostTool} an (mit planning_session_id, optional).
 */
class PlanungSessionPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_session.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Planungs-Session an — aus dem Trendradar (trend_ids und/oder inspiration_ids: Trends, Hypes '
            . 'und Inspirationen werden zu EINEM Briefing kombiniert, max. 8) ODER aus freiem Brief (title + optional brief). '
            . 'Status=divergenz. '
            . 'Erzeugt KEINE Rezepte/Konzepte (das macht das „Go" in der UI).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titel (Pflicht, außer bei trend_ids/inspiration_ids)'],
                'brief' => ['type' => 'string', 'description' => 'Optionaler Start-Brief für die Erzeugung'],
                'trend_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Spec 79: Trends/Hypes aus foodalchemist.trends.GET'],
                'inspiration_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Spec 79: Inspirationen aus foodalchemist.fundstuecke.GET'],
                'fundstueck_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Spec 79: Fundstücke aus foodalchemist.fundstuecke.GET'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'default' => 'voll_kreativ'],
                'generation_params' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler (Leitplanken) der Session — gegen die Whitelist gefiltert, '
                        . 'vererben in den Kaskaden-Fan-out. Keys u.a.: convenience, frische_erlaubt[], bio_pref (bio|conventional|neutral), '
                        . 'level, sektor, diaet_hart, allergen_nogo[], aroma_kueche, aroma, pax, ziel_portion_g, saison, ziel_we_pct, '
                        . 'occasion, serviceform, kompositions_stil, ziel_vk_eur, ki_bilder (Schritt-/Produktfotos bei der Anreicherung), '
                        . 'complete_coverage (Step-by-Step/Sensorik/Equipment bei der Anreicherung, Default an; false = leicht, GP-Mint/EK bleibt), '
                        . 'menue_typ (menue|buffet), menue_gaenge, menue_preis_min_pp/ziel_pp/max_pp, menue_quote_vegan_pct, '
                        . 'menue_quote_vegetarisch_pct, menue_balance. Nicht-Whitelist-Keys werden verworfen.',
                    'additionalProperties' => true,
                ],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(PlanningSessionService::class);

        try {
            if (! empty($arguments['trend_ids']) || ! empty($arguments['inspiration_ids']) || ! empty($arguments['fundstueck_ids'])) {
                $inspirationen = app(\Platform\FoodAlchemist\Services\TrendService::class)->inspirationIdsAus($team, $arguments);
                $session = $svc->ausTrendradar($team, (array) ($arguments['trend_ids'] ?? []), $inspirationen, 'mcp_trendradar');
                if (isset($arguments['brief']) && trim((string) $arguments['brief']) !== '') {
                    $session = $svc->update($team, $session->id, ['brief' => $arguments['brief']]);
                }
            } else {
                $session = $svc->create($team, [
                    'title' => (string) ($arguments['title'] ?? ''),
                    'brief' => $arguments['brief'] ?? null,
                    'creative_mode' => $arguments['creative_mode'] ?? 'voll_kreativ',
                    'created_via' => 'mcp',
                ]);
            }
            if (isset($arguments['generation_params']) && is_array($arguments['generation_params'])) {
                $session = $svc->setGenerationParams($team, (int) $session->id, $arguments['generation_params']);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $session->id,
            'title' => (string) $session->title,
            'status' => (string) $session->status,
            'creative_mode' => (string) $session->creative_mode,
            'generation_params' => $session->generation_params,
            'brief' => (string) $session->brief,
            'source_trend_refs' => $session->source_trend_refs,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'planung', 'kreativ', 'session', 'trend', 'doppel-diamant'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_session.GET', 'foodalchemist.planung_session.PUT', 'foodalchemist.kapitel_ideen.POST'],
            'examples' => ['Starte eine Planung aus Trend 12 und Fundstück 40', 'Neue Planungs-Session „Sommer-Buffet"'],
        ];
    }
}
