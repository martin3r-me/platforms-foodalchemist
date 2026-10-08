<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TripleMatchService;

/** Spec 75b · Preis-Toleranz des Triple Match setzen (FA-Admin). */
class TripleMatchPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.triple_match.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die Preis-Toleranz des Triple Match (nur FA-Admin): toleranz_pct (% vom Bestellpreis) und/oder toleranz_eur (€ je Gebinde); '
            .'das Großzügigere gilt. Leer/null = Standard (0,5 % bzw. 0,05 €). Antwort: wirksame Toleranz.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'toleranz_pct' => ['type' => ['number', 'string', 'null']], 'toleranz_eur' => ['type' => ['number', 'string', 'null']],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $tm = app(TripleMatchService::class);
            if (array_key_exists('toleranz_pct', $arguments) || array_key_exists('toleranz_eur', $arguments)) {
                $z = fn ($v) => $v === null || $v === '' ? null : (float) str_replace(',', '.', (string) $v);
                $alt = $tm->toleranz($team);
                $tm->setzeToleranz($team, array_key_exists('toleranz_pct', $arguments) ? $z($arguments['toleranz_pct']) : $alt['pct'],
                    array_key_exists('toleranz_eur', $arguments) ? $z($arguments['toleranz_eur']) : $alt['eur'], $userId);
            }

            return ['toleranz' => $tm->toleranz($team)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['triple_match', 'toleranz', 'write'], ['Preisabweichungen bis 1 % sollen als passend gelten.'], ['foodalchemist.triple_match.GET']);
    }
}
