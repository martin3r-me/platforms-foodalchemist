<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertPruefLaufService;

/** Trockenlauf der Nährwert-Prüfung je Lieferantenartikel — Zahlen je Regel, Lieferant und Umrechnungs-Faktor, nur lesend. */
class NaehrwertPruefungGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.naehrwert_pruefung.GET';
    }

    public function getDescription(): string
    {
        return 'Trockenlauf der Nährwert-Prüfung über die Lieferantenartikel der GPs, die das Team sieht: Artikel geprüft, markiert (harter Befund → zählt '
            .'im Modus scharf nicht in den GP-Nährwerten), umgerechnet (eindeutige Einheiten-Probe × 1000 / × 100), nur Hinweis, freigegeben; '
            .'je Regel (H1–H6, W1–W6, A) mit Beispielen, markiert je Lieferant, Umrechnungen je Lieferant und Faktor. Zeigt auch den aktuellen modus '
            .'(aus | trocken | scharf). Nur lesend. limit = Beispiele je Regel (Standard 5, max. 50), gp_ids grenzt ein. Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'limit' => ['type' => 'integer', 'description' => 'Beispiele je Regel (Standard 5, max. 50)'],
            'gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'nur diese GPs (optional)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            app(FaRechte::class)->pruefe($context->user, $team, FaRolle::Kuratieren, 'Nährwert-Prüfung lesen');
            $limit = max(1, min(50, (int) ($arguments['limit'] ?? 5)));
            $gpIds = isset($arguments['gp_ids']) && is_array($arguments['gp_ids']) ? array_map('intval', $arguments['gp_ids']) : null;

            return app(NaehrwertPruefLaufService::class)->lauf($team, $limit, $gpIds)['bericht'];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['naehrwerte', 'pruefung'], ['Wie viele Artikel würde die Nährwert-Prüfung markieren?'], ['foodalchemist.naehrwerte_vorschau.GET']), [
            'tags' => ['foodalchemist', 'naehrwerte', 'pruefung', 'trockenlauf'],
        ]);
    }
}
