<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\Ai\KiKlassenEinstellung;

/** Spec 85: KI-Klasse eines Arbeitsschritts einstellen — global, nur Admin im Master-Team. klasse "standard" = zurück auf den Code. */
class KiKlassenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ki_klassen.PUT';
    }

    public function getDescription(): string
    {
        return 'Stellt die KI-Klasse (stark/mittel/leicht) EINES Arbeitsschritts ein; klasse=standard setzt auf die Klasse aus dem Code zurück. '
            . 'Gilt global für alle Teams. Nur im Master-Team mit Rolle Admin. Jede Änderung steht im Aktivitätsprotokoll.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'prompt_key' => ['type' => 'string', 'description' => 'Arbeitsschritt, z. B. recipe.generator'],
            'klasse' => ['type' => 'string', 'enum' => ['stark', 'mittel', 'leicht', 'standard']],
            'grund' => ['type' => 'string', 'description' => 'optional: warum'],
        ], 'required' => ['prompt_key', 'klasse']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $key = trim((string) ($arguments['prompt_key'] ?? ''));
        $klasse = trim((string) ($arguments['klasse'] ?? ''));
        $e = app(KiKlassenEinstellung::class);
        try {
            $e->setze($team, $key, $klasse === 'standard' ? null : $klasse, isset($arguments['grund']) ? (string) $arguments['grund'] : null);
        } catch (FaRechtFehltException $ex) {
            return ToolResult::error($ex->getMessage(), 'FORBIDDEN');
        } catch (\InvalidArgumentException $ex) {
            return ToolResult::error($ex->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['prompt_key' => $key, 'klasse' => $e->klasseFuer($key), 'standard' => $e->standard($key),
            'eingestellt' => isset($e->eingestellt()[$key])]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config', 'tags' => ['foodalchemist', 'ki', 'klasse', 'modell', 'kosten', 'konfiguration'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ki_klassen.GET'],
            'examples' => ['Stelle recipe.generator auf mittel', 'Setze recipe.title zurück auf den Standard'],
        ];
    }
}
