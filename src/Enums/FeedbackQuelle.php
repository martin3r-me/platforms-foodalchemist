<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * R2.6 — Quelle eines Rezept-/Gericht-Feedbacks. Bewusst getrennt von der
 * KI-Sensorik-Bewertung (die bewertet das gegarte Gericht maschinell) und vom
 * ConcepterBewertungService (Menü-Qualitätsprüfung). Dies ist MENSCHLICHES
 * Feedback aus der Praxis:
 *  - Küche:  der Koch, der es kocht (Machbarkeit/Aufwand/Geschmack/Gäste-Reaktion) = Entwicklungs-Motor
 *  - Kunde:  Rückmeldung des Auftraggebers
 *  - Event:  Beobachtung vom Event (was lief, was blieb stehen)
 */
enum FeedbackQuelle: string
{
    case Kueche = 'kueche';
    case Kunde = 'kunde';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Kueche => 'Küche',
            self::Kunde => 'Kunde',
            self::Event => 'Event',
        };
    }

    /** Nur die Küche füllt die strukturierten Achsen (Entwicklungs-Kontext). */
    public function hatAchsen(): bool
    {
        return $this === self::Kueche;
    }
}
