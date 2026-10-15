<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\StammPaketService;

/** Spec 82 · Lieferanten-Freischaltung: alle Stamm-Lieferanten mit Freischaltung eines Mandanten (frisch aus dem globalen Stamm; nur Plattform-Admin). */
class StammPaketLieferantenGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.stamm_paket_lieferanten.GET';
    }

    public function getDescription(): string
    {
        return 'Alle Lieferanten des globalen Stamm-Pakets mit Artikelzahl und Freischaltung (frei) für einen Mandanten. Neue globale Lieferanten erscheinen sofort, unfreigeschaltet. '
            .'Standard nach Paketbuchung: keine Lieferanten frei. Optional suche (Namensteil). Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer'], 'suche' => ['type' => 'string']], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(StammPaketService::class);
            $svc->nurPlattformAdminOeffentlich($context->user, 'Stamm-Lieferanten einsehen');

            return $svc->lieferanten(Team::findOrFail((int) ($arguments['team_id'] ?? 0)), (string) ($arguments['suche'] ?? ''));
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['stamm_paket'], ['Welche Lieferanten sind für Soulkitchen freigeschaltet?'], ['foodalchemist.stamm_paket_lieferanten.PUT']), [
            'tags' => ['foodalchemist', 'stamm_paket', 'plattform', 'lieferanten'],
        ]);
    }
}
