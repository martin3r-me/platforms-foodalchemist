<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 03 L7a — der Kaskaden-Motor der One-Shot-Vollerstellung.
 *
 * Bis hierher endete jede Generierung beim geerdeten Zutaten-Gerüst samt
 * Aggregation; die Anreicherung war ein SEPARATER Klick („✨ Alles anreichern")
 * mit Review-Liste dahinter. Dieser Service verkettet beides zu einem Durchlauf.
 *
 * Drei Entscheidungen tragen ihn:
 *
 * 1. **Keine Parallel-Implementierung.** Der Pass fährt die bestehende
 *    `BulkEnrichService`-Strecke — dieselben Prompts, derselben Vorschlags-Speicher
 *    (`foodalchemist_bulk_proposals`, damit jeder Wert auditierbar bleibt und in
 *    der Review-Queue auftaucht) und denselben Accept-Pfad `alleUebernehmen()`,
 *    in dem Override-First je Feld und die Klassen-Validierung schon stehen.
 *
 * 2. **Auto-Übernahme nur in LÜCKEN.** GL-07 verbietet Auto-Persistenz gegen
 *    menschliche Pflege — nicht das Füllen leerer Felder. Ein frisch generierter
 *    Draft hat per Konstruktion keine gepflegten Werte; `luecken()` schneidet die
 *    Schrittfolge trotzdem auf die tatsächlich leeren Ziel-Felder, damit die
 *    Kaskade (a) nichts überschreibt, was der Generator oder ein Mensch schon
 *    gesetzt hat, und (b) keinen Provider-Call für ein gefülltes Feld bezahlt.
 *    Was übrig bleibt, wird sofort übernommen — sonst wäre das Ergebnis eben
 *    nicht „voll", sondern wieder eine Aufgabenliste.
 *
 * 3. **Synchron im ohnehin asynchronen Kontext.** Aufrufer ist der
 *    `GenerateRecipeJob` (Queue, Timeout 300 s) bzw. ein MCP-Call. Darum
 *    `verarbeiteRezept()` direkt statt `starte()`: ein dispatchter `BulkEnrichJob`
 *    liefe auf demo parallel, und `alleUebernehmen()` fände noch keine Vorschläge.
 *
 * Graceful by construction: `verarbeiteRezept()` fängt jeden Schritt-Fehler
 * einzeln (Fehl-Zeile statt Abbruch), der äußere Catch nur noch Infrastruktur.
 * Ein Provider-Ausfall mitten in der Kaskade lässt das Rezept vollständig und
 * konsistent zurück — der Kern steht, die Reste bleiben Lücken.
 */
class RecipeOneShotService
{
    public function __construct(private BulkEnrichService $bulk)
    {
    }

    /**
     * Anreicherungs-Kaskade über ein einzelnes (gerade erzeugtes) Rezept.
     *
     * Die Ebenen-Wahl fällt am `is_sales_recipe`-Flag: ein Gericht bekommt die
     * VK-Schrittfolge (Beschreibung · Wording · Plating · Speisen-Klasse), ein
     * Basisrezept die Basis-Folge (Beschreibung · Kategorie · Geschmack). Damit
     * gibt es hier keinen zweiten Ebenen-Zweig — dieselbe Regel wie im
     * Rezept-Copilot.
     *
     * @return array{run_id: ?int, schritte: list<string>, uebersprungen: list<string>, uebernommen: int, offen: int, fehler: ?string}
     */
    public function anreichern(Team $team, FoodAlchemistRecipe $recipe): array
    {
        $alle = $recipe->is_sales_recipe ? BulkEnrichService::SCHRITTE_VK : BulkEnrichService::SCHRITTE;
        $schritte = $this->bulk->luecken($recipe, $alle);

        $ergebnis = [
            'run_id' => null,
            'schritte' => $schritte,
            'uebersprungen' => array_values(array_diff($alle, $schritte)),
            'uebernommen' => 0,
            'offen' => 0,
            'fehler' => null,
        ];
        if ($schritte === []) {
            return $ergebnis;                                  // nichts zu füllen — kein Lauf, kein Call
        }

        try {
            // Lauf-Zeile = Fortschritts-Anker (die UI pollt sie über `status()`)
            // UND Audit-Spur: die Vorschläge bleiben in der Review-Queue sichtbar,
            // auch wenn sie in derselben Sekunde übernommen wurden.
            $runId = $this->bulk->laufAnlegen($team, 1, $recipe->is_sales_recipe ? 'enrich_vk' : 'enrich');
            $ergebnis['run_id'] = $runId;

            $this->bulk->verarbeiteRezept($team, $runId, $recipe->id, $schritte);
            $ergebnis['uebernommen'] = $this->bulk->alleUebernehmen($team, $runId);
            $ergebnis['offen'] = $this->bulk->offeneVorschlaege($team, $runId);
        } catch (\Throwable $e) {
            // Nie werfen: das Rezept ist zu diesem Zeitpunkt fertig angelegt und
            // aggregiert. Ein gescheiterter Anreicherungs-Pass ist eine Lücke,
            // kein Grund, die Generierung als Fehler zu melden.
            $ergebnis['fehler'] = mb_strimwidth($e->getMessage(), 0, 300);
        }

        return $ergebnis;
    }
}
