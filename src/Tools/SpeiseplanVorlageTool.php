<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Spec 57 · Paket 7: Speiseplan-Vorlage für Betriebe — freigeben/zurücknehmen (aktion=setzen),
 * Kopie für einen Betrieb anlegen (aktion=kopie) oder die Kopien einer Vorlage listen (aktion=liste).
 */
class SpeiseplanVorlageTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_vorlage.PUT';
    }

    public function getDescription(): string
    {
        return 'Vorlage für Betriebe (im eigenen Team): aktion=setzen mit ist_vorlage (true/false) gibt einen Plan als '
            . 'Vorlage frei; aktion=kopie mit outlet_id legt für einen Betrieb eine verknüpfte Kopie an (Entwurf); '
            . 'aktion=liste zeigt die Kopien mit offenen Änderungen. Abgleich über speiseplan_vorlage.ABGLEICH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id (die Vorlage).'],
                'aktion' => ['type' => 'string', 'enum' => ['setzen', 'kopie', 'liste']],
                'ist_vorlage' => ['type' => 'boolean', 'description' => 'Nur bei aktion=setzen.'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Nur bei aktion=kopie: Betrieb des eigenen Teams.'],
            ],
            'required' => ['plan_id', 'aktion'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }
        $svc = app(SpeiseplanService::class);
        try {
            return match ($arguments['aktion'] ?? '') {
                'setzen' => ToolResult::success(['plan_id' => $planId, 'ist_vorlage' => (bool) $svc->setzeVorlage($team, $planId, ($arguments['ist_vorlage'] ?? false) === true)->is_template]),
                'kopie' => ToolResult::success(['kopie' => (fn ($k) => ['id' => (int) $k->id, 'name' => $k->name, 'outlet_id' => (int) $k->outlet_id])($svc->betriebsKopieAnlegen($team, $planId, (int) ($arguments['outlet_id'] ?? 0)))]),
                'liste' => ToolResult::success(['plan_id' => $planId, 'kopien' => $svc->betriebsKopien($team, $planId)]),
                default => ToolResult::error('aktion muss setzen, kopie oder liste sein.', 'VALIDATION_ERROR'),
            };
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'vorlage', 'betrieb', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.speiseplan_vorlage.ABGLEICH', 'foodalchemist.speiseplaene.GET'],
            'examples' => ['Gib Speiseplan 3 als Vorlage frei und lege eine Kopie für Betrieb 7 an.'],
        ];
    }
}
