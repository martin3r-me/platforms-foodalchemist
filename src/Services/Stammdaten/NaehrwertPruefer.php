<?php

namespace Platform\FoodAlchemist\Services\Stammdaten;

/**
 * Austauschbare Nährwert-Prüfung für Stammdaten-Zeilen (Kurator 29.10.2026: es soll nur EINE Prüfung geben). Das Format folgt dem der
 * GP-Session (`Naehrwerte\NaehrwertPruefService::pruefe` → NaehrwertBefund: regel, schwere hart|hinweis, feld, wert, erwartet, vorschlag),
 * damit später nur ein Adapter gebunden wird. Prüft nur Werte, schreibt und blockiert nichts.
 */
interface NaehrwertPruefer
{
    /**
     * @param  array<string, float>  $zeile  Kern-Nährwerte je 100 g/ml (energy_kcal, energy_kj, protein, fat, saturated_fat, carbs_absorbable, sugar, sodium[mg])
     * @return list<array{regel: string, schwere: string, feld: ?string, wert: mixed, erwartet: mixed, vorschlag: mixed, text: string}>  leer = unauffällig
     */
    public function pruefe(array $zeile, ?string $warengruppe = null, ?string $sub = null): array;
}
