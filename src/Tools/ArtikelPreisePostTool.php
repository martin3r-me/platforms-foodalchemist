<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;

/** MCP-Steuerbarkeit · D4: Preis für einen sichtbaren Lieferantenartikel anlegen — Listenpreis (Besitzer) oder Teampreis (Spec 82 3a). */
class ArtikelPreisePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_preise.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Preis (netto) für einen sichtbaren Lieferantenartikel an. Besitzer-Team: Listenpreis. Anderes Team: eigener Teampreis des Oberteams/Mandanten (gilt für dieses Team und seine Unter-Teams, der Listenpreis bleibt; Unter-Teams schreiben keine eigenen Preise). status optional: 0 Standard-EK, 2 Aktion.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (für das Team sichtbar).'],
                'preis' => ['type' => 'number', 'description' => 'Preis netto (≥ 0).'],
                'status' => ['type' => 'string', 'description' => 'Preisstatus (optional).'],
            ],
            'required' => ['id', 'preis'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! is_numeric($arguments['preis'] ?? null) || (float) $arguments['preis'] < 0) {
            return ToolResult::error('preis muss eine Zahl ≥ 0 sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($id)->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $item->isOwnedBy($team) && ! PriceService::darfTeampreisSchreiben($team)) {
            return ToolResult::error('Unter-Teams erben die Preise ihres Oberteams — eigene Preise je Unter-Team gibt es nicht (Spec 82 §3).', 'ACCESS_DENIED');
        }

        try {
            // Spec 82 3a: Besitzer → Listenpreis; sonst Teampreis (team_id = dieses Team, Listenpreis bleibt).
            $p = app(PriceService::class)->createTeamPrice($team, $item, (float) $arguments['preis'], (string) ($arguments['status'] ?? '0'));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'price_id' => (int) $p->id, 'price' => (float) $p->price,
            'team_id' => (int) $p->team_id, 'art' => $item->isOwnedBy($team) ? 'listenpreis' : 'teampreis']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'preis', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.artikel_preise.DELETE'],
            'examples' => ['Lege für Artikel 4711 den Preis 8,90 an.'],
        ];
    }
}
