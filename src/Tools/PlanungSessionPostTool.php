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
            . 'Zukauf-Basisrezept: gp_id (+ optional zukauf_typ) legt die Planung „Zukauf-Basisrezept: <GP>“ an — die gekaufte Ware '
            . 'wird nicht nachgekocht, das Basisrezept führt genau diese Ware und beschreibt die Handgriffe. Jeder Lebensmittel-GP, '
            . 'nicht Non-Food (WG 16). Hat die Ware schon ein Zukauf-Basisrezept, entsteht KEINE Session (Antwort mit recipe_id); eine '
            . 'offene Zukauf-Planung desselben GP wird wiederverwendet (zukauf_typ setzt dann ihren Typ). Typ ohne Angabe aus der '
            . 'Regel basisrezept.zukauf.typ, sonst offen (dann zukauf_typ nachreichen, nie geraten). Go: planung_kaskade.START scope=rezept. '
            . 'Erzeugt KEINE Rezepte/Konzepte (das macht das „Go").';
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
                'gp_id' => ['type' => 'integer', 'description' => 'Zukauf-Basisrezept planen: gekaufte Ware (GP, team-sichtbar, nicht Non-Food). Ersetzt title/brief/trend_ids.'],
                'zukauf_typ' => ['type' => 'string', 'description' => 'Nur mit gp_id: Basisrezept-Typ aus dem Typ-Vokabular (Regel basisrezept.1.2.typ), z. B. Crunch, Öl. Ohne Angabe: Vorschlag aus der Warengruppe.'],
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
        if (! empty($arguments['gp_id'])) {
            return $this->zukauf($svc, $team, (int) $arguments['gp_id'], isset($arguments['zukauf_typ']) ? (string) $arguments['zukauf_typ'] : null);
        }

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

    /** Zukauf-Basisrezept planen — derselbe Einstieg wie der Knopf am GP ({@see PlanningSessionService::zukaufAusGp}). */
    private function zukauf(PlanningSessionService $svc, \Platform\Core\Models\Team $team, int $gpId, ?string $typ): ToolResult
    {
        if (! \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->whereKey($gpId)->exists()) {
            return ToolResult::error('Grundprodukt nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');   // Fehlertyp per Prüfung, nicht per Wortlaut
        }
        try {
            $e = $svc->zukaufAusGp($team, $gpId, $typ, 'mcp_gp');
        } catch (\RuntimeException $ex) {
            return ToolResult::error($ex->getMessage(), 'VALIDATION_ERROR');
        }
        if ($e['session'] === null) {
            return ToolResult::success([
                'zukauf_basisrezept_vorhanden' => true,
                'recipe_id' => (int) $e['recipe']->id,
                'recipe_name' => (string) $e['recipe']->name,
                'hinweis' => 'Zu dieser Ware gibt es schon ein Zukauf-Basisrezept — keine neue Planung angelegt.',
                'naechste_schritte' => ['Rezept ansehen/pflegen: foodalchemist.recipes.GET mit recipe_id (Rüst-/Regenerationszeit im Basisrezept).'],
            ]);
        }
        $s = $e['session'];

        return ToolResult::success([
            'id' => (int) $s->id,
            'session_id' => (int) $s->id,
            'title' => (string) $s->title,
            'status' => (string) $s->status,
            'brief' => (string) $s->brief,
            'neu' => $e['neu'],
            'zukauf' => ['gp_id' => (int) $s->source_gp_id, 'typ' => $e['typ'], 'typ_vorschlag' => $e['typ_vorschlag']],
            'naechste_schritte' => array_values(array_filter([
                $e['typ'] === null ? 'Typ fehlt: diesen Aufruf mit gp_id und zukauf_typ wiederholen (Typ-Vokabular, Regel basisrezept.1.2.typ).' : null,
                'Go: foodalchemist.planung_kaskade.START mit session_id=' . (int) $s->id . ' und scope=rezept (baut das Zukauf-Basisrezept mit genau dieser Ware).',
                'Status: foodalchemist.planung_kaskade.GET; Freigabe der Stufe: foodalchemist.planung_kaskade.FREIGABE.',
            ])),
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
