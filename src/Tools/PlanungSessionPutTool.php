<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningSessionService;

/**
 * Planungs-/Kreativ-Session ändern (Doppel-Diamant, Spec 08): title/brief/analysis und/oder
 * status (divergenz|konvergenz|erledigt) und/oder creative_mode. Team-scoped, nur Besitzer-Team.
 * Materialisierung („Go") bleibt human-only — dieses Tool ändert NUR die Session-Felder.
 */
class PlanungSessionPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_session.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine Planungs-Session: title/brief/analysis, status (divergenz|konvergenz|erledigt), '
            . 'creative_mode, generation_params (Richtungs-Regler/Leitplanken, Whitelist-gefiltert). '
            . 'Kein „Go" — Materialisierung zu Rezept/Concept bleibt human-only in der UI.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Session-ID (Pflicht)'],
                'title' => ['type' => 'string'],
                'brief' => ['type' => 'string'],
                'analysis' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['divergenz', 'konvergenz', 'erledigt']],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank']],
                'generation_params' => [
                    'type' => 'object',
                    'description' => 'Richtungs-Regler (Leitplanken) der Session — gegen die Whitelist gefiltert, ersetzt den '
                        . 'gesamten Regler-Satz (leere/leerwertige Auswahl → keine Regler). Keys u.a.: convenience, frische_erlaubt[], '
                        . 'bio_pref, level, sektor, diaet_hart, allergen_nogo[], aroma_kueche, aroma, pax, ziel_portion_g, saison, '
                        . 'ziel_we_pct, occasion, serviceform, kompositions_stil, ziel_vk_eur, ki_bilder, menue_typ, menue_gaenge, '
                        . 'menue_preis_min_pp/ziel_pp/max_pp, menue_quote_vegan_pct, menue_quote_vegetarisch_pct, menue_balance.',
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
        if (! isset($arguments['id'])) {
            return ToolResult::error('id ist Pflicht.', 'VALIDATION_ERROR');
        }
        $id = (int) $arguments['id'];
        $svc = app(PlanningSessionService::class);

        try {
            $felder = array_intersect_key($arguments, array_flip(['title', 'brief', 'analysis']));
            if ($felder !== []) {
                $svc->update($team, $id, $felder);
            }
            if (isset($arguments['status'])) {
                $svc->setStatus($team, $id, (string) $arguments['status']);
            }
            if (isset($arguments['creative_mode'])) {
                $svc->setCreativeMode($team, $id, (string) $arguments['creative_mode']);
            }
            if (isset($arguments['generation_params']) && is_array($arguments['generation_params'])) {
                $svc->setGenerationParams($team, $id, $arguments['generation_params']);
            }
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Session nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $session = $svc->get($team, $id);

        return ToolResult::success([
            'id' => $id,
            'title' => (string) ($session?->title ?? ''),
            'status' => (string) ($session?->status ?? ''),
            'creative_mode' => (string) ($session?->creative_mode ?? ''),
            'generation_params' => $session?->generation_params,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'planung', 'kreativ', 'session', 'doppel-diamant'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_session.GET', 'foodalchemist.planung_session.POST'],
            'examples' => ['Setze Planungs-Session 3 auf erledigt', 'Ändere den Brief von Session 5'],
        ];
    }
}
