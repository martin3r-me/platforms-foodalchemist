<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Platform\FoodAlchemist\Enums\AussageTyp;
use Platform\FoodAlchemist\Enums\Grundlage;

/**
 * Spec 60 · P6: eine Aussage der Kombinationslogik — typisiert, mit Grundlage und Satz.
 * Dieselbe Aussage landet in Oberfläche, MCP und Generator (keine zweite Formulierung).
 */
final class Aussage
{
    /**
     * @param  list<int>  $bestandteile  Rezept-IDs (Basisrezept/Gericht-Komponente) bzw. negative GP-Zeilen-IDs
     */
    public function __construct(
        public readonly AussageTyp $typ,
        public readonly Grundlage $grundlage,
        public readonly string $text,
        public readonly array $bestandteile = [],
        public readonly ?string $achse = null,
        public readonly ?float $wert = null,
    ) {}

    /** @return array{typ: string, grundlage: string, grundlage_label: string, text: string, bestandteile: list<int>, achse: ?string, wert: ?float} */
    public function toArray(): array
    {
        return [
            'typ' => $this->typ->value,
            'grundlage' => $this->grundlage->value,
            'grundlage_label' => $this->grundlage->label(),
            'text' => $this->text,
            'bestandteile' => $this->bestandteile,
            'achse' => $this->achse,
            'wert' => $this->wert,
        ];
    }
}
