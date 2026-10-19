<?php

namespace Platform\FoodAlchemist\Services\Websuche;

/**
 * Austauschbarer Transport der Web-Recherche. Der Transport führt EINE Websuche-Anfrage über den Core-Contract aus
 * (kein eigener HTTP-Client) und liefert die geparste Modellantwort plus die TATSÄCHLICH gefundenen Quellen.
 *
 * Produktiv: {@see CoreWebSucheTransport} (Spec 85 I5-8, Core #935). Getrennt, damit Tests den Transport ersetzen können.
 */
interface WebSucheTransport
{
    /**
     * @param  string  $anweisung  Aufgabe für das Modell (der Core-Transport nimmt sie aus der Prompt-Registry)
     * @param  array   $kontext    Artikel-Kontext (Bezeichnung, EAN, Marke …)
     * @return array{antwort: array, quellen: list<array{url: string, title?: string}>}
     *
     * @throws \RuntimeException bei Fehler oder fehlender Unterstützung (Meldung ist für den Nutzer lesbar)
     */
    public function suche(string $anweisung, array $kontext): array;
}
