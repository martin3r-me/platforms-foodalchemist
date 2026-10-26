<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Support\MasterTeam;

/** Marken-Nachlauf (Plan GP-Stamm 3g, read): Rezeptur-GPs ohne Marke, gruppiert nach unbekannten Kürzeln — wie die Seite Lieferanten → „Marke offen“. */
class MarkeOffenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.marke_offen.GET';
    }

    public function getDescription(): string
    {
        return 'Marken-Nachlauf (nur Master-Team): Rezeptur-GPs mit marke_offen, gruppiert nach unbekannten Kürzel-Kandidaten aus den Artikelbezeichnungen '
            . '(je Lieferant, Kürzel, Position anfang/ende) mit Anzahl GPs/Artikel, 2 Beispielartikeln und den GP-IDs; dazu Lieferanten mit Rolle „offen“, '
            . 'die an Rezepturware hängen. Zuordnen mit foodalchemist.marke_offen.ZUORDNEN. Read-only.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer', 'description' => 'nur Gruppen dieses Lieferanten'],
            'limit' => ['type' => 'integer', 'description' => 'höchstens so viele Gruppen (Standard 50, max. 500)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! MasterTeam::istMaster($team)) {
            return ToolResult::error('Den Marken-Nachlauf sieht nur das Master-Team.', 'ACCESS_DENIED');
        }
        $dienst = app(MarkeOffenService::class);
        $liste = $dienst->liste();
        $sid = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
        $gruppen = $sid !== null ? array_values(array_filter($liste['gruppen'], fn ($g) => $g['supplier_id'] === $sid)) : $liste['gruppen'];
        $limit = max(1, min(500, (int) ($arguments['limit'] ?? 50)));

        return ToolResult::success([
            'gps_offen' => $liste['gps_offen'], 'artikel_offen' => $liste['artikel_offen'], 'gruppen_gesamt' => count($gruppen),
            'gruppen' => array_map(fn ($g) => array_diff_key($g, ['schluessel' => true]), array_slice($gruppen, 0, $limit)),
            'lieferanten_rolle_offen' => $dienst->lieferantenOffenMitRezepturware(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'marke', 'kuerzel', 'rezepturware', 'nachlauf'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.marke_offen.ZUORDNEN', 'foodalchemist.supplier_marken.GET'],
            'examples' => ['Welche unbekannten Marken-Kürzel gibt es bei Chefs Culinar?', 'Wie viele Rezeptur-GPs haben noch keine Marke?'],
        ];
    }
}
