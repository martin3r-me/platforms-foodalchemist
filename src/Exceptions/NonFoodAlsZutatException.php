<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Ein Non-Food-Grundprodukt (Warengruppe 16) darf keine Lebensmittel-Zutat sein. Verpackung und Zubehör gehören in den Block
 * „Zubehör & Verpackung“ am Rezept. Eigener Typ statt Satz-Vergleich: Aufrufer unterscheiden am Typ, nicht am Wortlaut.
 *
 * @param  list<string>  $namen  die abgelehnten Grundprodukte
 */
final class NonFoodAlsZutatException extends \RuntimeException
{
    /** @param  list<string>  $namen */
    public function __construct(public readonly array $namen)
    {
        parent::__construct('Non-Food ist keine Zutat und gehört in „Zubehör & Verpackung“: '.implode(', ', $namen).'.');
    }
}
