<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Spec 57 · Paket 7: Abgleich einer Betriebs-Kopie mit ihrer Vorlage — anzeigen (ab heute) oder
 * Änderungen der Vorlage übernehmen (alle bzw. gewählte Zellen, confirm=true).
 */
class SpeiseplanVorlageAbgleichTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_vorlage.ABGLEICH';
    }

    public function getDescription(): string
    {
        return 'Abgleich Betriebs-Kopie ↔ Vorlage. aktion=anzeigen (Standard): abweichende Zellen ab heute mit art '
            . 'vorlage_geaendert | lokal_abweichend und neue Linien der Vorlage. aktion=uebernehmen (confirm=true): '
            . 'ersetzt die Zellen (zellen = Keys aus anzeigen; ohne zellen = alle vorlage_geaendert) durch die Vorlage.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kopie_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id der Betriebs-Kopie.'],
                'aktion' => ['type' => 'string', 'enum' => ['anzeigen', 'uebernehmen'], 'default' => 'anzeigen'],
                'zellen' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Zell-Keys aus aktion=anzeigen.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Pflicht bei aktion=uebernehmen.'],
            ],
            'required' => ['kopie_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['kopie_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $id, 'Speiseplan')) !== null) {
            return $guard;
        }
        $svc = app(SpeiseplanService::class);
        try {
            if (($arguments['aktion'] ?? 'anzeigen') === 'uebernehmen') {
                if (($arguments['confirm'] ?? false) !== true) {
                    return ToolResult::error('Übernehmen ersetzt Zellen der Kopie — confirm=true nötig.', 'CONFIRM_REQUIRED');
                }
                $zellen = isset($arguments['zellen']) && is_array($arguments['zellen']) ? array_map('strval', $arguments['zellen']) : null;

                return ToolResult::success(['kopie_id' => $id, 'uebernommene_zellen' => $svc->ausVorlageUebernehmen($team, $id, $zellen)]);
            }

            return ToolResult::success(['kopie_id' => $id] + $svc->vorlagenAbgleich($team, $id));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'vorlage', 'abgleich'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates', 'deletes', 'creates'],
            'related_tools' => ['foodalchemist.speiseplan_vorlage.PUT'],
            'examples' => ['Welche Änderungen der Vorlage fehlen der Kopie 12?', 'Übernimm alle Vorlage-Änderungen in Kopie 12 (confirm=true).'],
        ];
    }
}
