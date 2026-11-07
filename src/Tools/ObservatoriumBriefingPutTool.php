<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ObservatoriumBriefingService as BP;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;

/** „Mein Briefing“ einstellen oder löschen — nur die eigene Einstellung des angemeldeten Nutzers. */
class ObservatoriumBriefingPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.observatorium_briefing.PUT';
    }

    public function getDescription(): string
    {
        return 'Stellt „Mein Briefing“ im Gastro-Observatorium für den angemeldeten Nutzer ein (ersetzt die ganze Einstellung): branchen (Liste der '
            .'Schlüssel, leer = alle), region (DE|AT|CH, leer = alle), tage (7|30|90, Standard 7), themen (bis 10 Suchbegriffe, ODER). '
            .'loeschen=true entfernt die Einstellung. Branchen-Schlüssel: '.implode(', ', array_keys(ObservatoriumInhalt::BRANCHEN)).'.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'branchen' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(ObservatoriumInhalt::BRANCHEN)]],
            'region' => ['type' => 'string', 'enum' => ['', 'DE', 'AT', 'CH']],
            'tage' => ['type' => 'string', 'enum' => array_keys(BP::TAGE)],
            'themen' => ['type' => 'array', 'items' => ['type' => 'string']],
            'loeschen' => ['type' => 'boolean'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null || $context->user === null) {
            return ToolResult::error('Kein Team oder Nutzer im Kontext.', 'NO_TEAM');
        }
        $svc = app(BP::class);
        if (! empty($arguments['loeschen'])) {
            $svc->loeschen($team, (int) $context->user->id);

            return ToolResult::success(['einstellung' => null]);
        }
        try {
            $p = $svc->speichern($team, (int) $context->user->id, $arguments);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['einstellung' => ['branchen' => $p->branchen ?? [], 'region' => $p->region, 'tage' => $p->tage,
            'themen' => $p->themen ?? [], 'kurz' => BP::zusammenfassung($p)]]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['foodalchemist', 'observatorium', 'briefing', 'write'], 'read_only' => false,
            'idempotent' => true, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'], 'related_tools' => ['foodalchemist.observatorium_briefing.GET'],
            'examples' => ['Stell mein Briefing auf Catering und Events in Deutschland, letzte 7 Tage, Themen Protein und alkoholfrei.']];
    }
}
