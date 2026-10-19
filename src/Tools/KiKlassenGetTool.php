<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung;
use Platform\FoodAlchemist\Services\Ai\KiKlassenEinstellung;

/** Spec 85: KI-Klasse je Arbeitsschritt lesen — wirksam, aus dem Code und ob eingestellt. Read-only, jedes Team. */
class KiKlassenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ki_klassen.GET';
    }

    public function getDescription(): string
    {
        return 'Listet je KI-Arbeitsschritt (Prompt-Key) die wirksame KI-Klasse (stark/mittel/leicht), die Klasse aus dem Code und '
            . 'ob sie eingestellt ist. Eingestellt wird global vom Admin im Master-Team (ki_klassen.PUT). Optional prompt_key für einen Schritt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'prompt_key' => ['type' => 'string', 'description' => 'nur dieser Arbeitsschritt (leer = alle)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $e = app(KiKlassenEinstellung::class);
        $key = trim((string) ($arguments['prompt_key'] ?? ''));
        $schritte = $key !== '' ? array_values(array_intersect($e->schritte(), [$key])) : $e->schritte();
        if ($key !== '' && $schritte === []) {
            return ToolResult::error("Unbekannter Arbeitsschritt „{$key}“.", 'VALIDATION_ERROR');
        }
        $eingestellt = $e->eingestellt();

        return ToolResult::success(['items' => array_map(fn ($k) => [
            'prompt_key' => $k, 'label' => Wissenssteuerung::schrittLabel($k),
            'klasse' => $e->klasseFuer($k), 'standard' => $e->standard($k), 'eingestellt' => isset($eingestellt[$k]),
        ], $schritte)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'ki', 'klasse', 'modell', 'konfiguration'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ki_klassen.PUT'],
            'examples' => ['Welche KI-Klasse nutzt recipe.generator?', 'Welche Arbeitsschritte sind abweichend vom Code eingestellt?'],
        ];
    }
}
