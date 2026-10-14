<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Spec 83 B3g · Posten pflegen: Sammelposten-Flag (Pass ohne eigenes Personal). Nur EIGENE Posten des Teams (D1: geerbte sind Vorlagen),
 * Rolle Admin wie die übrigen Einstellungen. Lockstep zur Posten-Verwaltung (Schalter "Sammelposten (Pass)").
 */
class ProductionStationsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_stations.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt am eigenen Posten des Teams das Merkmal ist_sammelposten (true = Sammelposten wie der Pass: ohne eigenes Personal, Kapazität und Last hängen an den '
            .'beteiligten Posten, keine Überlast-Warnung für ihn). Geerbte Posten sind Vorlagen und nicht änderbar. Die Team-Vorgabe "Posten für Gerichte" setzt team_settings.PUT (gerichte_station_id).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'ID des Postens (production_stations.GET)'],
            'ist_sammelposten' => ['type' => 'boolean'],
        ], 'required' => ['id', 'ist_sammelposten']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            app(FaRechte::class)->pruefeAngemeldet($team, FaRolle::Admin, 'Posten ändern');
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        }

        $posten = FoodAlchemistProductionStation::visibleToTeam($team)->find((int) ($arguments['id'] ?? 0));
        if ($posten === null) {
            return ToolResult::error('Posten nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $posten->isOwnedBy($team)) {
            return ToolResult::error('Geerbter Posten ist eine Vorlage — Pflege nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }
        if (! array_key_exists('ist_sammelposten', $arguments)) {
            return ToolResult::error('ist_sammelposten (true|false) angeben.', 'VALIDATION_ERROR');
        }
        $posten->ist_sammelposten = (bool) $arguments['ist_sammelposten'];
        $posten->save();

        return ToolResult::success(['posten' => ['id' => (int) $posten->id, 'name' => $posten->name, 'ist_sammelposten' => (bool) $posten->ist_sammelposten]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'produktion', 'posten', 'sammelposten', 'pass', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.production_stations.GET', 'foodalchemist.team_settings.PUT'],
            'examples' => ['Markiere den Posten Pass als Sammelposten.'],
        ];
    }
}
