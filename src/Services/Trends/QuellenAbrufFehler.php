<?php

namespace Platform\FoodAlchemist\Services\Trends;

/** Spec 93 A · Abruf einer Trend-Quelle gescheitert; die Meldung landet in `letzter_fehler` der Quelle. */
class QuellenAbrufFehler extends \RuntimeException {}
