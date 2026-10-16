<?php

namespace Platform\FoodAlchemist\Services\Produktpass;

use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Quelle für Produktdaten eines Lieferantenartikels: der gelesene Produktpass (`produktpass`) oder — später, von Peter —
 * die KI-Websuche (`websuche`). Beide liefern DIESELBE Form; Übernahme, Allergen-Bestätigung und Marker macht EINMAL der
 * {@see ProduktpassService}.
 *
 * Rückgabe von {@see self::liefere()} (alles optional, Fehlendes weglassen, nie raten):
 * [
 *   'quelle'    => 'produktpass'|'websuche',
 *   'felder'    => ['<schluessel>' => ['wert' => …, 'konfidenz' => 0.0–1.0, 'einheit' => ?, 'zitat' => ?, 'url' => ?]],
 *   'allergene' => ['<eu-allergen>' => ['wert' => 'enthalten|spuren|nicht_enthalten', 'konfidenz' => …, 'url' => ?]],
 *   'allergene_details' => ['<unterart>' => ['wert' => …, 'konfidenz' => …]],
 * ]
 * Schlüssel siehe {@see \Platform\FoodAlchemist\Support\ProduktpassFelder}. Der {@see ProduktpassNormalisierer} verwirft, was nicht passt.
 */
interface ProduktdatenQuelle
{
    /** 'produktpass' | 'websuche' */
    public function name(): string;

    /** @param array<string,mixed> $optionen */
    public function liefere(FoodAlchemistSupplierItem $la, array $optionen = []): array;
}
