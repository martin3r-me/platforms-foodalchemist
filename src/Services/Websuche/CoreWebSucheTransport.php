<?php

namespace Platform\FoodAlchemist\Services\Websuche;

use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Spec 85 I5-8: Websuche über den Core-Contract — `AiGatewayService::propose` mit `include_web_search` (Core #935: `chat()`
 * liefert `sources`). KI-Klasse, Kill-Switch, Budget und Call-Log wie jeder andere FA-Aufruf; Klasse aus der Prompt-Registry
 * (`artikel.web_recherche` → stark). Kein eigener HTTP-Client.
 *
 * Meldungen statt Erfindung: sucht der Provider nicht im Web, wirft schon der Gateway (kein Aufruf); meldet er keine Quellen
 * (Core vor #935), wirft der Transport; „keine Quellen gefunden" entscheidet {@see \Platform\FoodAlchemist\Services\ArtikelWebRecherche}.
 */
class CoreWebSucheTransport implements WebSucheTransport
{
    public const PROMPT = 'artikel.web_recherche';

    public function __construct(private AiGatewayService $gateway)
    {
    }

    public function suche(string $anweisung, array $kontext): array
    {
        try {
            // EIN Aufruf (Websuche ist teuer: Suchtreffer zählen als Eingabe-Tokens); keine Re-Rolls bei Nicht-JSON
            $vorschlag = $this->gateway->propose(self::PROMPT, $kontext, ['include_web_search' => true, 'max_versuche' => 1]);
        } catch (\Platform\FoodAlchemist\Exceptions\KiAntwortKeinJsonException) {
            throw new \RuntimeException('Keine belastbaren Quellen gefunden: die Websuche lieferte keine auswertbare Antwort.');
        }
        if ($vorschlag->quellen === null) {
            throw new \RuntimeException('Websuche nicht verfügbar: der Core liefert keine Quellen (Core-Update nötig).');
        }

        return ['antwort' => $vorschlag->werte, 'quellen' => $vorschlag->quellen];
    }
}
