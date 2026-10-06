<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 60 · P4: Arten von Anker-Beziehungen neben der gemessenen Harmonie.
 *   Kontrast    abgeleitet: a braucht eine Achse, b liefert sie (Bedarf × Eigenschaft)
 *   Kombination „Verträgt" aus dem Dossier (Klassiker ohne geteilte Aromen)
 *   Konflikt    „Zerstört" aus dem Dossier (nur Zutaten, keine Verarbeitungsfehler)
 */
enum Kantenart: string
{
    case Kontrast = 'kontrast';
    case Kombination = 'kombination';
    case Konflikt = 'konflikt';
}
