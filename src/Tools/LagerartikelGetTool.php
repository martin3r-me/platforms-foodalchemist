<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerartikelService;

/** Spec 74 · Lagerartikel (Grundvorrat) mit Bestand, Mindest-/Sollbestand und Ampel. */
class LagerartikelGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lagerartikel.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Lagerartikel des Betriebs (Grundvorrat wie Gewürze, Öle): Bestand, Mindest- und Sollbestand '
            . '(kg/l/Stk) und Status leer | unter_min | ohne_min | ok. nur_unter_mindest=true liefert nur, was nachgefüllt werden muss. '
            . 'Lagerartikel gehen nicht über den Rezeptbedarf in die Bestellrunde, sondern über „Nachfüllen".';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['nur_unter_mindest' => ['type' => 'boolean']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(LagerartikelService::class);
        $liste = ! empty($arguments['nur_unter_mindest']) ? $svc->unterMindest($team) : $svc->liste($team);

        return ToolResult::success(['lagerartikel' => array_map(fn ($r) => array_diff_key($r, ['nachfuellen_basis' => 1]), $liste), 'anzahl' => count($liste)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'lager', 'lagerartikel', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.lagerartikel.PUT', 'foodalchemist.inventory.GET'],
            'examples' => ['Welche Gewürze sind kurz vor leer?', 'Zeig alle Lagerartikel unter Mindestbestand.'],
        ];
    }
}
