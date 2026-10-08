<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/** Spec 66b · Stellplätze eines Lagerorts + Grundprodukte mit Stammplatz und Vorschlag lesen. */
class StorageBinsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.storage_bins.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Stellplätze eines Lagerorts (id, name, zone kuehl|tk|trocken|getraenke|sonstig, Laufweg-Reihenfolge, '
            . 'Anzahl Grundprodukte). Mit artikel=true zusätzlich die Grundprodukte des Lagerorts mit Stammplatz (bin_id), '
            . 'Zustand, Warengruppe und dem Vorschlag aus Zustand+Warengruppe (bin_vorschlag).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location_id' => ['type' => 'integer', 'description' => 'Lagerort-Id.'],
                'artikel' => ['type' => 'boolean', 'description' => 'Grundprodukte mit Stammplatz/Vorschlag mitliefern.'],
                'nur_ohne_stellplatz' => ['type' => 'boolean', 'description' => 'Nur Grundprodukte ohne Stammplatz.'],
            ],
            'required' => ['location_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(LagerEinrichtungService::class);
        $ort = (int) ($arguments['location_id'] ?? 0);
        try {
            $out = ['location_id' => $ort, 'stellplaetze' => $svc->stellplaetze($team, $ort)->map(fn ($b) => [
                'bin_id' => $b->id, 'name' => $b->name, 'zone' => $b->zone, 'reihenfolge' => $b->sort_order, 'grundprodukte' => $b->zuordnungen_count,
            ])->values()->all()];
            if (! empty($arguments['artikel'])) {
                $artikel = $svc->artikel($team, $ort);
                if (! empty($arguments['nur_ohne_stellplatz'])) {
                    $artikel = array_values(array_filter($artikel, fn ($r) => $r['bin_id'] === null));
                }
                $out['artikel'] = $artikel;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Lagerort nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lager', 'stellplatz'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.storage_bins.POST', 'foodalchemist.storage_bins.ASSIGN'],
            'examples' => ['Welche Stellplätze hat das Hauptlager?', 'Welche Grundprodukte haben noch keinen Stellplatz?'],
        ];
    }
}
