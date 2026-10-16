<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreisVerlaufService;

/** Einen Preis-Stand als Erfassungsfehler aus Linie, Trend und Statistik nehmen (nicht löschen) oder wieder einblenden — nur FA-Admins. */
class PreiseHistorieAusblendenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preise_historie.AUSBLENDEN';
    }

    public function getDescription(): string
    {
        return 'Blendet einen Preis-Stand eines Lieferantenartikels als Erfassungsfehler aus (aktion=ausblenden, Standard) oder wieder ein (aktion=einblenden). '
            .'Ausgeblendete Stände bleiben gespeichert, zählen aber nicht in Linie, Trend und Statistik und stehen in „Frühere Preise“ durchgestrichen mit Grund; '
            .'der Necta-Abgleich blendet sie nicht wieder ein. grund ist Pflicht. stand_id: aus foodalchemist.preise_verlauf.GET (ausgeblendete_staende, verdachts_staende) '
            .'bzw. der Historie; quelle historie (Standard) oder preis (abgelöste Preiszeile). Nur FA-Admins, nur für Artikel, die dem eigenen Team gehören (Master: Stamm-Artikel).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'la_id' => ['type' => 'integer', 'description' => 'Lieferantenartikel'],
                'stand_id' => ['type' => 'integer', 'description' => 'ID des Stands (Historie bzw. Preiszeile)'],
                'quelle' => ['type' => 'string', 'enum' => ['historie', 'preis']],
                'aktion' => ['type' => 'string', 'enum' => ['ausblenden', 'einblenden']],
                'grund' => ['type' => 'string', 'description' => 'Pflicht beim Ausblenden, z. B. „Erfassungsfehler beim Lieferanten“'],
            ],
            'required' => ['la_id', 'stand_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) ($arguments['la_id'] ?? 0));
        if ($item === null) {
            return ToolResult::error('Artikel nicht gefunden.', 'NOT_FOUND');
        }
        $quelle = (string) ($arguments['quelle'] ?? 'historie');
        $standId = (int) ($arguments['stand_id'] ?? 0);
        $svc = app(PreisVerlaufService::class);
        try {
            if (($arguments['aktion'] ?? 'ausblenden') === 'einblenden') {
                $svc->einblenden($team, $item, $quelle, $standId, $context->user?->id, FaRolle::Admin);
            } else {
                $svc->ausblenden($team, $item, $quelle, $standId, (string) ($arguments['grund'] ?? ''), $context->user?->id, FaRolle::Admin);
            }
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['la_id' => $item->id, 'stand_id' => $standId, 'quelle' => $quelle,
            'aktion' => ($arguments['aktion'] ?? 'ausblenden') === 'einblenden' ? 'eingeblendet' : 'ausgeblendet']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'preise', 'verlauf', 'historie', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true,
            'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.preise_verlauf.GET'],
            'examples' => ['Blende den Preis-Stand 4711 von Artikel 99 als Erfassungsfehler aus (63,82 statt 1,82 €).'],
        ];
    }
}
