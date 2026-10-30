<?php

namespace Platform\FoodAlchemist\Services\Stammdaten;

use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertPruefService;

/**
 * Standard-Prüfer des Stammdaten-Imports: die EINE Nährwert-Prüfung der Plattform ({@see NaehrwertPruefService}, #638). Rohwerte bleiben, wie sie in der Datei
 * stehen; eine eindeutige Einheiten-Probe (H6, „× 1000“) kommt als Befund mit Faktor und umgerechneten Werten, nicht als Änderung der Datei-Werte.
 */
class NaehrwertPruefServiceAdapter implements NaehrwertPruefer
{
    public function __construct(private readonly NaehrwertPruefService $service) {}

    public function pruefe(array $zeile, array $kontext = []): array
    {
        return array_map(fn ($b) => [
            'regel' => $b->regel, 'schwere' => $b->schwere, 'feld' => $b->feld, 'wert' => $b->wert, 'erwartet' => $b->erwartet,
            'vorschlag' => $b->vorschlag, 'text' => $b->grund . ($b->vorschlag['faktor'] ?? null ? ' (umgerechnet × ' . $b->vorschlag['faktor'] . ', Rohwerte bleiben)' : ''),
        ], $this->service->pruefe($zeile, [], $kontext));
    }
}
