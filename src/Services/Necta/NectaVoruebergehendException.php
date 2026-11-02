<?php

namespace Platform\FoodAlchemist\Services\Necta;

/**
 * Necta ist kurz gestört (HTTP 5xx oder Rate-Limit), die Anfrage selbst ist in Ordnung — dieselbe Seite später noch einmal holen lohnt sich.
 * Der Typ entscheidet über das Wiederholen, nicht der Wortlaut der Meldung. Gemessen io 29.10./31.10./02.11.2026: „HTTP 500: An unexpected
 * error occurred.“ an wechselnden Lieferanten, die Nächte dazwischen liefen durch.
 */
class NectaVoruebergehendException extends \RuntimeException {}
