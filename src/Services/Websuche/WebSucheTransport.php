<?php

namespace Platform\FoodAlchemist\Services\Websuche;

/**
 * Austauschbarer Transport der Web-Recherche. Der Transport führt EINE Websuche-Anfrage über den Core-Contract aus
 * (kein eigener HTTP-Client) und liefert die geparste Modellantwort plus die TATSÄCHLICH gefundenen Quellen.
 *
 * Warum getrennt: Der Core wertet `include_web_search` heute nur im Streaming aus und gibt keine Quellen zurück
 * (siehe 00_INBOX/_Core_Wunsch_WebSearch_chat.md). Bis der Core `chat()` kann, ist der Standard-Transport
 * {@see NichtVerfuegbarTransport}; danach genügt ein Transport, der `AiGatewayService::propose` mit
 * `include_web_search` aufruft und `sources` des Providers zurückgibt.
 */
interface WebSucheTransport
{
    /**
     * @param  string  $anweisung  Aufgabe für das Modell (Rechercheauftrag)
     * @param  array   $kontext    Artikel-Kontext (Bezeichnung, EAN, Marke …)
     * @return array{antwort: array, quellen: list<array{url: string, title?: string}>}
     *
     * @throws \RuntimeException bei Fehler oder fehlender Unterstützung (Meldung ist für den Nutzer lesbar)
     */
    public function suche(string $anweisung, array $kontext): array;
}
