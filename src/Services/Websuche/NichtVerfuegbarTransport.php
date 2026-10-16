<?php

namespace Platform\FoodAlchemist\Services\Websuche;

/** Standard-Transport, solange der Core keine Websuche mit Quellen in `chat()` liefert: klare Meldung statt stiller Erfindung. */
class NichtVerfuegbarTransport implements WebSucheTransport
{
    public function suche(string $anweisung, array $kontext): array
    {
        throw new \RuntimeException('Die Web-Recherche ist noch nicht freigeschaltet: Der Core liefert für die Websuche noch keine belegbaren Quellen.');
    }
}
