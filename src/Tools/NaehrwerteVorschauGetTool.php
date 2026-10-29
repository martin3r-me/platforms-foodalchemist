<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\NaehrwertVorschauService;

/** Vorschau der Nährwert-Umstellung (Nullen echt, Leer-Guard überall, Median) — vorher/nachher je Achse, nur lesend. */
class NaehrwerteVorschauGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.naehrwerte_vorschau.GET';
    }

    public function getDescription(): string
    {
        return 'Vorschau der GP-Nährwert-Regel „Nullen echt, Median“: je Achse vorher (bis 10/2026) und nachher (jetzt), getrennt nach Erdung '
            .'(salzig/süß/fettig, Gehalt g/100 g) und Rezept-Nährwerten (kcal, Fett, ges. Fett, KH, Zucker, Eiweiß, Salz). '
            .'Je Achse: neu gemessen, entfällt, sinkt, steigt (ab schwelle_pct, Standard 20), größte Sprünge und Beispiele. '
            .'Dazu die Rezept-Wirkung: GPs mit geändertem Rezeptwert, Rezepte mit betroffener Zutat, GPs, deren kcal von 0 auf unbekannt wechselt. '
            .'Nur lesend, rechnet nichts neu. Beschränkt auf GPs, die das Team sieht; gp_ids grenzt weiter ein. Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'schwelle_pct' => ['type' => 'integer', 'description' => 'relative Änderung in %, ab der ein Wert als geändert zählt (Standard 20, 1–100)'],
            'limit' => ['type' => 'integer', 'description' => 'Zeilen je Liste (Standard 10, max. 50)'],
            'gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'nur diese GPs (optional)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            app(FaRechte::class)->pruefe($context->user, $team, FaRolle::Kuratieren, 'Nährwert-Vorschau lesen');
            $schwelle = max(1, min(100, (int) ($arguments['schwelle_pct'] ?? 20))) / 100;
            $limit = max(1, min(50, (int) ($arguments['limit'] ?? 10)));
            $gpIds = isset($arguments['gp_ids']) && is_array($arguments['gp_ids']) ? array_map('intval', $arguments['gp_ids']) : null;

            return app(NaehrwertVorschauService::class)->bericht($team, $schwelle, $limit, $gpIds);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['naehrwerte', 'vorschau'], ['Was ändert die Nährwert-Umstellung an Erdung und Rezepten?'], ['foodalchemist.gps.GET']), [
            'tags' => ['foodalchemist', 'gps', 'naehrwerte', 'vorschau'],
        ]);
    }
}
