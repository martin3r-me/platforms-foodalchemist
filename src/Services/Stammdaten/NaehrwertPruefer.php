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
     * @param  array{wg?: ?string, sub?: ?string, name?: ?string, bezeichnung?: ?string}  $kontext
     * @return list<array{regel: string, schwere: string, // hart | hinweis | umrechnung
     *                     feld: ?string, wert: mixed, erwartet: mixed, vorschlag: mixed, text: string}>  leer = unauffällig
     */
    public function pruefe(array $zeile, array $kontext = []): array;
}
