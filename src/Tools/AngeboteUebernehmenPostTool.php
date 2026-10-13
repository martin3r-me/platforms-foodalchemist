<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;

/** Spec 82 §12 B3 · Neuere Fassung in ein eingefrorenes Angebot übernehmen. Bei geändertem Allergenbild nur Rolle Freigeben und mit bestaetigt=true. */
class AngeboteUebernehmenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.UEBERNEHMEN_POST';
    }

    public function getDescription(): string
    {
        return 'Übernimmt die neuere Fassung in das eingefrorene Angebot: Verweise auf die alte Fassung werden umgestellt, das Gericht rechnet neu, der Stand wird neu festgehalten. '
            .'Ändert die neue Fassung das Allergen- oder Zusatzstoffbild, braucht es bestaetigt=true (nach Durchsicht von angebote.UEBERNEHMEN_GET) und die Rolle Freigeben; Person und Zeitpunkt werden protokolliert. '
            .'Sonst genügt Kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'], 'zeile_id' => ['type' => 'integer'], 'familie_id' => ['type' => 'integer'],
            'bestaetigt' => ['type' => 'boolean', 'description' => 'Pflicht bei geändertem Allergenbild.'],
        ], 'required' => ['id', 'zeile_id', 'familie_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $id, 'Angebot')) !== null) {
            return $guard;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($id);
        try {
            $bericht = app(AngebotEinfrierService::class)->uebernehmen($team, $context->user, $angebot, (int) $arguments['zeile_id'], (int) $arguments['familie_id'], ! empty($arguments['bestaetigt']));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['bericht' => $bericht]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'angebot', 'einfrieren', 'fassung', 'allergen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.angebote.UEBERNEHMEN_GET', 'foodalchemist.angebote.EINFRIEREN_GET'],
            'examples' => ['Übernimm die neue Jus-Fassung in Angebot 5.'],
        ];
    }
}
