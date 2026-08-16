<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Et.4 (Eingabe-Reife) »Titel-/Namensvorschlag aus dem Brief« — Teil 2 (Service).
 *
 * Schlägt VOR der Generierung aus dem freien Tab-Briefing einen nüchternen,
 * §-konformen Titel vor (Contract in `5d3ccda`: `recipe.titel_vorschlag` §1-Syntax
 * bzw. `vk.titel_vorschlag` §4.4-Pipe-Syntax). Ebenen-getrennt wie `name_putzen`:
 * - `rezept`  → `recipe.titel_vorschlag`  (Basisrezept, «Typ: Bezeichnung»)
 * - `gericht` → `vk.titel_vorschlag`      (Gericht, «HG-Code: Hauptkomponente | …»)
 * - `concept` bleibt bewusst AUSSEN vor — dessen `name_claim` (via `concept.plan`)
 *   ist kreativ, nicht »nüchtern«.
 *
 * Reiner Vorschlag: nichts wird persistiert (der UI-Knopf in Teil 3 füllt den Titel
 * nur, wenn leer). Brief-leer-Guard + fail-soft (KI weg/Fehler → `null`, kein Wurf).
 */
class TitelVorschlagService
{
    /** Ebene → passender Titel-Prompt (Concept bewusst nicht gelistet). */
    private const PROMPT_JE_SCOPE = [
        'rezept' => 'recipe.titel_vorschlag',
        'gericht' => 'vk.titel_vorschlag',
    ];

    public function __construct(private readonly AiGatewayService $ki)
    {
    }

    /**
     * Liefert einen Titel-Kandidaten für den Scope aus dem Brief — oder `null`, wenn
     * kein Vorschlag möglich ist (unbekannter/nicht unterstützter Scope, leerer Brief,
     * KI nicht verfügbar oder leeres/ungültiges Ergebnis).
     */
    public function titelVorschlag(string $scope, string $brief): ?string
    {
        $promptKey = self::PROMPT_JE_SCOPE[$scope] ?? null;
        if ($promptKey === null) {
            return null;                       // concept + unbekannte Ebenen: kein nüchterner Titel
        }

        $brief = trim($brief);
        if ($brief === '') {
            return null;                       // Brief-leer-Guard: ohne Brief kein Vorschlag
        }

        try {
            $vorschlag = $this->ki->propose($promptKey, ['brief' => $brief]);
        } catch (\Throwable) {
            return null;                       // fail-soft: Provider-/Coverage-Fehler kippt nichts
        }

        $name = $vorschlag->werte['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }
}
