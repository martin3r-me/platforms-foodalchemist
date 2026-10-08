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
            . 'Zustand, Warengruppe und dem Vorschlag aus Zustand+Warengruppe (bin_vorschlag). Mit gp_id (ohne location_id): '
            . 'das Lager EINES Grundprodukts — je Lagerort Bestand, Stammplatz und Vorschlag („Wo liegt die Sahne?").';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location_id' => ['type' => 'integer', 'description' => 'Lagerort-Id.'],
                'gp_id' => ['type' => 'integer', 'description' => 'Grundprodukt: dessen Lager über alle Lagerorte.'],
                'artikel' => ['type' => 'boolean', 'description' => 'Grundprodukte mit Stammplatz/Vorschlag mitliefern.'],
                'nur_ohne_stellplatz' => ['type' => 'boolean', 'description' => 'Nur Grundprodukte ohne Stammplatz.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(LagerEinrichtungService::class);
        if (! empty($arguments['gp_id']) && empty($arguments['location_id'])) {
            $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find((int) $arguments['gp_id']);
            if ($gp === null) {
                return ToolResult::error('Grundprodukt nicht gefunden.', 'NOT_FOUND');
            }

            return ToolResult::success(['gp_id' => $gp->id, 'name' => $gp->name, 'lagerorte' => array_map(fn ($o) => [
                'location_id' => $o['id'], 'lagerort' => $o['name'], 'standard' => $o['standard'], 'bestand' => $o['bestand_basis'],
                'bin_id' => $o['bin_id'], 'stellplatz' => $o['stellplatz'], 'vorschlag_bin_id' => $o['vorschlag'], 'vorschlag' => $o['vorschlag_name'],
            ], $svc->gpLager($team, $gp))]);
        }
        if (empty($arguments['location_id'])) {
            return ToolResult::error('location_id oder gp_id angeben.', 'VALIDATION_ERROR');
        }
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
