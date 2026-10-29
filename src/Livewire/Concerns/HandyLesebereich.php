<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

/**
 * #1003 Handy-Lesemodus, serverseitig: Eine Komponente mit diesem Trait ist am Handy ein Lese-Bereich. Kommt eine
 * Livewire-Anfrage vom Handy (Kopfzeile X-FA-Handy: 1), läuft nur, was handyLeseMethoden() nennt oder in
 * config('foodalchemist.handy.aendern_frei') freigeschaltet ist; alles andere weist Support\HandyLesemodus ab.
 * Ausblenden allein wäre nur Optik.
 */
trait HandyLesebereich
{
    /** Methoden, die am Handy nur lesen (aufklappen, Zeitraum wählen …) und deshalb immer laufen dürfen. */
    public function handyLeseMethoden(): array
    {
        return [];
    }
}
