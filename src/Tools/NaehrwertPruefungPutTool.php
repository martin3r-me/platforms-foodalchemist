<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertEntscheidService;

/** Modus der Nährwert-Prüfung umschalten (aus | trocken | scharf) — Plattform-Einstellung, nur FA-Admin im Master-Team. */
class NaehrwertPruefungPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.naehrwert_pruefung.PUT';
    }

    public function getDescription(): string
    {
        return 'Schaltet die Nährwert-Prüfung je Lieferantenartikel um: modus aus (GP-Nährwerte ohne Prüfung) | trocken (wie aus, nur Bericht) | scharf '
            .'(Artikel mit hartem Befund zählen nicht, eindeutige Einheiten-Fehler werden beim Rechnen umgerechnet, Signale „Nährwerte am Artikel prüfen“). '
            .'modus leer = Einstellung entfernen, dann gilt die env. Ein Notaus in der env bleibt stärker. Wirkt plattformweit; gespeicherte Rezept-Nährwerte '
            .'ändern sich erst beim nächsten Recompute. Vorher naehrwert_pruefung.GET (Trockenlauf) ansehen. Nur FA-Admin im Master-Team, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'modus' => ['type' => 'string', 'enum' => ['aus', 'trocken', 'scharf', ''], 'description' => 'aus | trocken | scharf; leer = Einstellung entfernen, die env gilt'],
        ], 'required' => ['modus']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (! array_key_exists('modus', $arguments)) {
            return ToolResult::error('modus fehlt (aus | trocken | scharf oder leer).', 'VALIDATION_ERROR');
        }

        return $this->ausfuehren($context, fn (Team $team) => app(NaehrwertEntscheidService::class)
            ->setzeModus($team, $context->user, $arguments['modus'] === null || $arguments['modus'] === '' ? null : (string) $arguments['modus']));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['naehrwerte', 'pruefung', 'plattform'], ['Schalte die Nährwert-Prüfung scharf.'], ['foodalchemist.naehrwert_pruefung.GET']), [
            'tags' => ['foodalchemist', 'naehrwerte', 'pruefung', 'plattform', 'write'],
        ]);
    }
}
