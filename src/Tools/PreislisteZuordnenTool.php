<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;

/**
 * Eigene Lieferanten-Datei ohne Vorlage (Kurator 29.10.2026): ein Lauf im Status „zuordnung_noetig“ bekommt seine Spaltenzuordnung und wird dann
 * geprüft/abgeglichen. Die Zuordnung wird je Lieferant gemerkt (merken) und beim nächsten Upload desselben Lieferanten automatisch angewendet.
 */
class PreislisteZuordnenTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preisliste.ZUORDNEN';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Spalten einer hochgeladenen Lieferanten-Datei zu, wenn preisliste.UPLOAD den Lauf als „zuordnung_noetig“ angelegt hat (Kopfzeile + erste Zeilen stehen in preisliste_laeufe.GET mit run_id). '
            .'mapping {zielfeld: Überschrift|Spaltenindex}: bei art=preisliste die Felder supplier, article_number, ean, designation, unit, price, valid_from; bei art=stammdaten die Vorlagen-Überschriften '
            .'(z. B. "Bezeichnung", "Artikelnummer", "Nettopreis", "Allergen Milch", "Nährwert kcal", siehe preisliste.VORLAGE_GET art=stammdaten). merken=true (Standard) speichert die Zuordnung für diesen Lieferanten und wendet sie beim nächsten Upload an '
            .'(mit Hinweis, wenn sich die Spalten geändert haben). Schreibt weder Preise noch Artikel — es entsteht die normale Prüfliste (neuer lauf_id). Nur das Haupt-Team des Kunden mit Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'run_id' => ['type' => 'integer', 'description' => 'Lauf im Status zuordnung_noetig'],
            'mapping' => ['type' => 'object', 'description' => '{zielfeld: Überschrift|Spaltenindex}'],
            'merken' => ['type' => 'boolean', 'description' => 'Zuordnung je Lieferant merken (Standard true)'],
        ], 'required' => ['run_id', 'mapping']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $liste = app(PreislistenImportService::class);
            if (! $liste->darfImportieren($team, $context->user)) {
                throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Datei zuordnen (nur das Haupt-Team des Kunden)');
            }
            $lauf = $liste->lauf($team, (int) ($arguments['run_id'] ?? 0)) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $mapping = (array) ($arguments['mapping'] ?? []);
            $merken = (bool) ($arguments['merken'] ?? true);

            $neu = $lauf->art === Lauf::ART_STAMMDATEN
                ? app(StammdatenImportService::class)->zuordnen($team, $lauf, $mapping, $context->user?->id, $merken)
                : $liste->zuordnen($team, $lauf, $mapping, $context->user?->id, $merken);

            return ['lauf_id' => $neu->id, 'art' => $neu->art, 'zaehler' => $neu->counts, 'zuordnung_gemerkt' => $merken,
                'hinweis' => 'Nur geprüft/abgeglichen, nichts im Bestand geändert. Die Prüfliste steht in preisliste_laeufe.GET (run_id).'];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['preisliste'], ['Die Datei von Hanos hat andere Spaltennamen — ordne sie zu.'], ['foodalchemist.preisliste.UPLOAD', 'foodalchemist.preisliste_laeufe.GET']), [
            'tags' => ['foodalchemist', 'preisliste', 'einkauf', 'stammdaten', 'write'],
        ]);
    }
}
