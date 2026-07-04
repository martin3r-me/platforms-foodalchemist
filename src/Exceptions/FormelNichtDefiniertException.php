<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * W-1 / D-6 §6: formula_type='deckungsbeitrag' hat keine definierte Formel —
 * der Vorschlags-Pfad wirft typisiert statt still falsch zu rechnen, bis der
 * D6-Entscheid steht (08_ENTSCHEIDUNGEN). CRUD/Stammdaten bleiben erlaubt.
 */
class FormelNichtDefiniertException extends \DomainException
{
}
