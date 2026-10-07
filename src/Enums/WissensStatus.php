<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 60 · P4: Status von Anker-Wissen und abgeleiteten Beziehungen. Dossier-Inhalt ist
 * KI-Entwurf, bis ein Mensch ihn freigibt; gemessen ist allein Inspire.
 */
enum WissensStatus: string
{
    case Entwurf = 'entwurf';
    case Geprueft = 'geprueft';
    case Verworfen = 'verworfen';
}
