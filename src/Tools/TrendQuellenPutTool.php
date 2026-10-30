<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 93 A · Quelle ändern, neu prüfen (pruefen=true) oder Probelauf „Was käme durch?“ (probelauf=true, speichert nichts). */
class TrendQuellenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_quellen.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine Quelle des Trendradars (Kuratieren, nur eigenes Team): name, url, art, feed_typ, abruf_weg, rhythmus, Schwerpunkte, '
            .'stichworte_ein/aus (leere Liste = Team-Standard), aktiv. pruefen=true prüft die URL neu und setzt Weg und Typ (z. B. wenn eine '
            .'Übersicht kaputtgeht). probelauf=true holt Feed bzw. Übersicht und zeigt durchgelassen vs. gefiltert, speichert nichts.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'], 'url' => ['type' => 'string'],
            'art' => ['type' => 'string', 'enum' => array_keys(V::QUELLEN)],
            'feed_typ' => ['type' => 'string', 'enum' => array_keys(V::FEED_TYPEN)],
            'abruf_weg' => ['type' => 'string', 'enum' => array_keys(V::ABRUF_WEGE)],
            'rhythmus' => ['type' => 'string', 'enum' => array_keys(V::RHYTHMEN)],
            'schwerpunkt_kategorien' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(V::KATEGORIEN)]],
            'schwerpunkt_sparten' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(V::SPARTEN)]],
            'stichworte_ein' => ['type' => 'array', 'items' => ['type' => 'string']],
            'stichworte_aus' => ['type' => 'array', 'items' => ['type' => 'string']],
            'aktiv' => ['type' => 'boolean'],
            'pruefen' => ['type' => 'boolean', 'description' => 'URL neu prüfen und Weg/Typ neu wählen.'],
            'probelauf' => ['type' => 'boolean', 'description' => 'Nur anzeigen, was der Filter durchließe; speichert nichts.'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendQuellenService::class);
        $id = (int) ($arguments['id'] ?? 0);
        try {
            if (! empty($arguments['probelauf'])) {
                return ToolResult::success(['probelauf' => $svc->probelauf($team, $id)]);
            }
            $daten = array_diff_key($arguments, array_flip(['id', 'pruefen', 'probelauf']));
            $r = $svc->aendern($team, $id, $daten, ! empty($arguments['pruefen']), $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Quelle nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['quelle' => $svc->alsArray($r['quelle'], $team), 'pruefung' => $r['pruefung']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'quellen', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.trend_quellen.GET'],
            'examples' => ['Stell Quelle 3 auf monatlich.', 'Was käme bei Quelle 5 mit den aktuellen Stichworten durch?'],
        ];
    }
}
