<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistSignal;

/**
 * Signale-Cockpit — zentraler, dependency-freier PLANNER für den „KI erledigen
 * lassen"-Knopf. EINE Wahrheit für UI (welcher Knopf? welcher Text?) UND Executor
 * (SignalFixService dispatcht danach). Metrik-fein: ein SignalTyp (z. B.
 * datenqualitaet_gp_la) hat fixbare und nicht-fixbare Metriken.
 *
 * kind:
 *  - deterministic → automatischer Fixer (kein LLM), scoped auf die betroffenen
 *    Objekte; nach Erfolg schließt/aktualisiert sich das Signal.
 *  - assist        → EIN LLM-`propose()`-Call erzeugt einen Entwurf/Vorschlag
 *    (transient im Panel); keine Mutation, kein Auto-Close.
 *  - null          → kein Knopf (reine Urteilssache / externe Daten / echte Handlung).
 */
final class SignalCockpit
{
    /** Metrik-Key (payload['metrik']) → deterministischer Fixer (SignalFixService::applyFixer). */
    private const DETERMINISTIC = [
        'gp_allergen_konfidenz' => 'allergen',
        'gp_ohne_lead' => 'lead_la',
        'gp_lead_ohne_preis' => 'lead_la',
        'br_anker_fehlt' => 'recipe_anker',
        'vk_anker_fehlt' => 'recipe_anker',
        'gp_anker_fehlt' => 'gp_anker',
        'br_ek_null' => 'recompute',
        'br_ek_teil' => 'recompute',
        'vk_ek_null' => 'recompute',
        'vk_ek_teil' => 'recompute',
    ];

    /** SignalTyp-Wert → LLM-Prompt-Key (KI-Assistenz, ein propose()-Call). */
    private const ASSIST = [
        'preis_sprung_marge_impact' => 'signal.supplier_inquiry',
        'marge_unter_ziel' => 'signal.margin_levers',
        'wareneinsatz_ueber_ziel' => 'signal.margin_levers',
        'preis_anomalie' => 'price.plausi',
        'vk_anpassung_empfohlen' => 'signal.vk_release_advice',
        'servierform_unbestimmt' => 'signal.serving_form_suggest',
    ];

    private const PLAN_DET = [
        'allergen' => 'Allergen-Konfidenz aus den verknüpften Lieferantenartikeln deterministisch aggregieren und je GP '
            . 'persistieren (manuell/KI-kuratierte bleiben unberührt).',
        'lead_la' => 'Lead-Lieferantenartikel je GP neu wählen — aber nur setzen, wo er auf einen gültigen Preis auflöst; '
            . 'danach die nutzenden Rezepte neu rechnen. Echte Beschaffungs-Lücken bleiben offen.',
        'recipe_anker' => 'Flavor-Kern-Anker je Rezept aus Zutaten-/Rezeptnamen deterministisch auflösen und mappen — '
            . 'macht das Rezept im Pairing-Graph sichtbar.',
        'gp_anker' => 'Flavor-Kern-Anker je GP aus dem GP-Namen auflösen und mappen — macht den GP im Pairing-Graph sichtbar.',
        'recompute' => 'EK-Kette der betroffenen Rezepte neu rechnen. Rezepte, die weiter auf keinen Preis auflösen '
            . '(fehlende Lead-/Preisdaten), bleiben offen.',
    ];

    private const PLAN_ASSIST = [
        'signal.supplier_inquiry' => 'Lieferanten-Rückfrage-Entwurf erzeugen — betroffene Gerichte + günstigere Alternative '
            . 'als Argumentationshilfe („warum ist Artikel X so teuer?"). Umschalten des Lead-LA bleibt deine Entscheidung.',
        'signal.margin_levers' => 'Hebel-Vorschlag erzeugen — VK-Erhöhung auf die Zielmarge oder günstigere Warenkorb-'
            . 'Alternative. Die Entscheidung triffst du.',
        'price.plausi' => 'Ausreißer-Preise gegen den Warengruppen-Median einordnen (Tippfehler / Premium / echt). '
            . 'Vorschlag zur Sichtung — kein stiller Fix.',
        'signal.vk_release_advice' => 'Freigabe-Empfehlung für die abweichende Live-VK erzeugen — du bestätigst bewusst '
            . '(kein stiller Kunden-Preissprung).',
        'signal.serving_form_suggest' => 'Passende Servierform je Gericht vorschlagen (KI-Klassifikation nach Bauart) — '
            . 'du bestätigst.',
    ];

    /**
     * Plan für ein Signal — oder null (kein KI-Knopf).
     *
     * @return array{kind:string,flavorLabel:string,plan:string,fixer?:string,metrik?:string,prompt?:string}|null
     */
    public static function planFor(FoodAlchemistSignal $sig): ?array
    {
        $metrik = self::metrik($sig);

        $fixer = $metrik !== null ? (self::DETERMINISTIC[$metrik] ?? null) : null;
        if ($fixer !== null) {
            return ['kind' => 'deterministic', 'flavorLabel' => 'Auto-Fix', 'plan' => self::PLAN_DET[$fixer],
                'fixer' => $fixer, 'metrik' => $metrik];
        }

        $prompt = self::ASSIST[$sig->type->value] ?? null;
        if ($prompt !== null) {
            return ['kind' => 'assist', 'flavorLabel' => 'KI-Assistenz', 'plan' => self::PLAN_ASSIST[$prompt], 'prompt' => $prompt];
        }

        return null;
    }

    /** Effektiver Metrik-Key: payload['metrik'] (DataQuality) bzw. abgeleitet aus dem Detektor-Signal. */
    public static function metrik(FoodAlchemistSignal $sig): ?string
    {
        $pl = is_array($sig->payload) ? $sig->payload : [];
        if (! empty($pl['metrik'])) {
            return (string) $pl['metrik'];
        }
        // SignalDetektorService::datenqualitaetGpLa (GP ohne Lead) trägt kein metrik, aber stabilen dedup_key.
        if ($sig->dedup_key === 'datenqualitaet-gp-ohne-la') {
            return 'gp_ohne_lead';
        }

        return null;
    }
}
