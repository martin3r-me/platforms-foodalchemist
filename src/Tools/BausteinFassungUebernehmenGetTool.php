<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BausteinFolgeService;

/** Spec 82 Abo Konzepte/Pakete PR 5 · Welche Verweise des Teams stehen noch auf einer älteren Konzept-/Paket-Fassung (gehalten: Allergen-Änderung oder eingefroren)? */
class BausteinFassungUebernehmenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.baustein_fassungen.UEBERNEHMEN_GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Verweise des Teams (eigenes Konzept, Format, Foodbook, Speisekarte, Speiseplan, Angebot), die auf einer älteren Abo-Fassung eines Konzepts '
            .'oder Pakets stehen und nicht automatisch folgen: bei geändertem Allergenbild oder in einem eingefrorenen Angebot. art = concept | paket, familie_id = Arbeitszeile '
            .'des Master-Konzepts/-Pakets. Je Eintrag quelle + row_id für baustein_fassungen.UEBERNEHMEN_POST, von/nach Nummer, Grund, Allergen-Diff, Zwischenfassungen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'art' => ['type' => 'string', 'enum' => ['concept', 'paket']], 'familie_id' => ['type' => 'integer'],
        ], 'required' => ['art', 'familie_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $art = (string) ($arguments['art'] ?? '');
        if (! in_array($art, ['concept', 'paket'], true)) {
            return ToolResult::error('art muss concept oder paket sein.', 'VALIDATION_ERROR');
        }

        return ToolResult::success(['verweise' => app(BausteinFolgeService::class)->gehalten($team, $art, (int) ($arguments['familie_id'] ?? 0))]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'konzepte', 'pakete', 'fassung', 'allergen', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.baustein_fassungen.UEBERNEHMEN_POST', 'foodalchemist.concept_fassungen.GET'],
            'examples' => ['Welche meiner Ausgaben stehen noch auf der alten Fassung des Grill-Buffets?'],
        ];
    }
}
