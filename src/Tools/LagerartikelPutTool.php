<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerartikelService;

/** Spec 74 · Grundprodukt als Lagerartikel setzen/entfernen, Mindest- und Sollbestand pflegen. */
class LagerartikelPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lagerartikel.PUT';
    }

    public function getDescription(): string
    {
        return 'Markiert Grundprodukte als Lagerartikel (Grundvorrat: Gewürze, Öle, Salz …) oder entfernt die Markierung. '
            . 'gp_ids: Liste; ist_lagerartikel (Standard true); mindestbestand und sollbestand in kg/l/Stk (nur bei genau einem gp_id). '
            . 'Unter Mindestbestand wird in der Bestellrunde auf den Sollbestand nachgefüllt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'ist_lagerartikel' => ['type' => 'boolean'],
            'mindestbestand' => ['type' => ['number', 'string', 'null']], 'sollbestand' => ['type' => ['number', 'string', 'null']],
        ], 'required' => ['gp_ids']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($arguments['gp_ids'] ?? []))));
        if ($ids === []) {
            return ToolResult::error('gp_ids fehlt.', 'VALIDATION_ERROR');
        }
        $ist = ! array_key_exists('ist_lagerartikel', $arguments) || (bool) $arguments['ist_lagerartikel'];
        $mitMengen = count($ids) === 1;
        try {
            foreach ($ids as $id) {
                app(LagerartikelService::class)->setzen($team, $id, $ist,
                    $mitMengen ? ($arguments['mindestbestand'] ?? null) : null, $mitMengen ? ($arguments['sollbestand'] ?? null) : null);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Grundprodukt nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['gesetzt' => count($ids), 'ist_lagerartikel' => $ist]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'lager', 'lagerartikel', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates'],
            'related_tools' => ['foodalchemist.lagerartikel.GET'],
            'examples' => ['Salz als Lagerartikel, Mindestbestand 2 kg, auffüllen auf 10 kg.', 'Markiere alle Gewürze als Lagerartikel.'],
        ];
    }
}
