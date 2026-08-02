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
 *  - navigate      → 22·H4b/V-033: **kein Knopf, aber ein Weg.** Der Mensch geht selbst
 *    hin; der Plan-Satz sagt wohin und was dort zu entscheiden ist. Kein Executor —
 *    `SignalFixService`/`SignaleFixTool` lehnen ihn genauso ab wie „gar kein Plan".
 *  - null          → kein Weg im System (reine Urteilssache / Ursache ausserhalb). Die
 *    **Begründung** dafür ist trotzdem hinterlegt (`OHNE_WEG` → `ohneWegGrund()`), damit
 *    „nichts zu tun" und „hier hat nur niemand nachgedacht" unterscheidbar bleiben.
 *
 * ⚠️ `planFor() !== null` heißt seit 22·H4b **nicht mehr** „es gibt einen KI-Knopf" —
 * dafür ist `kiPlan()` da (nur die zwei ausführbaren Arten). Jede Fläche, die einen
 * Knopf rendert oder einen Executor anstößt, fragt `kiPlan()`; wer nur erklärt,
 * fragt `planFor()`.
 */
final class SignalCockpit
{
    /** Metrik-Key (payload['metrik']) → deterministischer Fixer (SignalFixService::applyFixer). */
    private const DETERMINISTIC = [
        'gp_allergen_konfidenz' => 'allergen',
        // H2c/V-014: von den drei Beschaffungs-Lagen (DataQualityService::gpLage) trägt nur
        // `kein_lead` einen Knopf — dort liegt ein bepreister Artikel, den der Fixer setzen
        // KANN. `gp_kein_la` (Einkauf) und `gp_kein_preis` (Preispflege) stehen bewusst
        // nicht hier: ein Knopf, der nichts setzen kann, verspricht eine Reparatur und
        // bewegt keine Zahl. Vorher hing er an der lumped Metrik `gp_ohne_lead` und griff
        // über alle drei Lagen.
        'gp_kein_lead' => 'lead_la',
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
        // Spec 21 Tranche A: nur Kategorie (und später Naming) bekommen KI-Assistenz — beides sind
        // Zuordnungs-Vorschläge, die ein Modell aus dem Namen ableiten kann. Die übrigen Tranche-A-Typen
        // bleiben bewusst knopflos: „ohne Zubereitung"/„Mengen-Lücke" braucht Küchen-Wissen am Einzelfall
        // (kein Sammel-propose über 15 Beispiele), „ungemappte Zutaten" läuft über den bestehenden
        // Match-Pfad im Rezept, „verwaist"/„ein Zutat" sind reine Entscheidungen.
        'rezept_kategorie_problem' => 'signal.recipe_category_suggest',
        'rezept_naming_regelwerk' => 'signal.recipe_naming_suggest',
        // Spec 21 Tranche B (`rezept_plausi_ki`, `rezept_gericht_vs_komponente`) steht
        // bewusst NICHT hier — weder als
        // Fixer noch als Assist. Hinter dem Signal liegt bereits ein KI-Urteil je Befund
        // (`foodalchemist_recipe_findings`); ein Sammel-`propose()` darüber wäre ein
        // zweites Urteil über dasselbe und würde am Ende gegen die abgelegten Befunde
        // laufen. Der Weg ist die Objekt-Liste im Panel: sie öffnet das Rezept mit
        // aufgeklappten Befunden, entschieden wird einzeln (SignalTyp::istKiUrteil).
        // Für den Bauart-Befund gilt das doppelt: seine „Übernahme" wäre ein Kippen von
        // `is_sales_recipe` samt Taxonomie und Verkaufs-Facetten — kein Sammel-Knopf.
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
        'signal.recipe_category_suggest' => 'Passende Kategorie je Rezept vorschlagen (Bauart-Logik „wie gebaut", nicht '
            . '„wo eingesetzt"); stillgelegte Hauptgruppen bleiben ausgeschlossen. Vorschlag zur Sichtung — die '
            . 'Zuordnung setzt du.',
        'signal.recipe_naming_suggest' => 'Regelkonformen Namen je auffälligem Rezept vorschlagen (VK-Gericht: '
            . '[HG]-Präfix + Pipe-Skelett aus den Kern-Bausteinen; Basisrezept: „Typ: Bezeichnung"). Nur ein '
            . 'Vorschlag — umbenannt wird nichts automatisch, weil der Name in Angeboten und Foodbooks hängt.',
    ];

    /**
     * 22·H4b/V-033 — Typen, deren Antwort **an der Metrik** hängt und nicht am Typ: sie
     * tragen fixbare und nicht-fixbare Lagen nebeneinander. Der Registry-Test prüft sie
     * deshalb nicht typ-grob, sondern über die echten Metrik-Deskriptoren
     * (`DataQualityService::messeAlleEbenen`) — jede einzelne Lage muss auflösen.
     */
    private const METRIK_FEIN = [
        'datenqualitaet_gp_la',
        'anker_fehlt',
        'ek_kette_unvollstaendig',
    ];

    /**
     * Metrik-Key → Weg-Satz, wo kein Fixer greifen kann (V-033, metrik-fein wie DETERMINISTIC).
     * Gilt für die Lagen der METRIK_FEIN-Typen, die per Konstruktion nichts zu setzen haben.
     */
    private const NAVIGATE_METRIK = [
        // H2c/V-014: die zwei Beschaffungs-Lagen ohne Hebel — hier fehlt Ware bzw. ein
        // Preis, nicht eine Auswahl. Ein Knopf verspräche eine Reparatur (deshalb steht
        // er nicht in DETERMINISTIC); ein Satz sagt, wo die Reparatur wirklich passiert.
        'gp_kein_la' => 'Beschaffung: dem Grundprodukt einen Lieferantenartikel zuordnen (Grundprodukte → GP → Artikel) '
            . 'bzw. den Artikel beim Lieferanten listen lassen. Ein Auto-Fix kann hier nichts wählen — es gibt noch nichts zu wählen.',
        'gp_kein_preis' => 'Preispflege: für die verknüpften Artikel eine gültige Preiszeile hinterlegen '
            . '(Lieferanten → Artikel → Preise) oder den Katalog-Import laufen lassen. Danach greift der Lead-LA-Auto-Fix.',
        'gp_tentative_genutzt' => 'Den tentativen GP kuratieren und auf „approved" heben (Grundprodukte → Review) oder '
            . 'die Rezept-Zutat auf einen bestehenden approved GP umhängen — beides ist eine Kuratierungs-Entscheidung.',
    ];

    /**
     * SignalTyp-Wert → Weg-Satz (V-033, Fall b: „der Weg führt woanders hin und ist konkret
     * benennbar"). Kein Executor, kein Knopf — der Satz benennt Ort und Entscheidung.
     * Bewusst ohne harte Sprungziele im Text: die Objekt-Liste im Panel springt bereits,
     * ein zweiter, per Hand gepflegter Pfad würde davon wegdriften.
     */
    private const NAVIGATE = [
        'veraltete_preise' => 'Preise am Lieferantenartikel pflegen (Lieferanten → Artikel → Preise) oder den '
            . 'Katalog-Import laufen lassen — mit der nächsten gültigen Preiszeile verschwindet der Befund.',
        'naehrwert_plausi' => 'Die gemeldete Nährwertangabe am Artikel gegen das Etikett prüfen und korrigieren '
            . '(Lieferanten → Artikel); die Rezept-Aggregate ziehen beim nächsten Recompute nach.',
        'widerspruch_wissen_graph' => 'R&D-Frage im Wissens-Modul: trägt der Beleg die Paarung, fehlt dem Anker-Graph '
            . 'eine Kante — trägt er sie nicht, ist die Behauptung im Dokument zu streichen. Entschieden wird am Dokument.',
        'sortiments_luecke' => 'Beschaffungs-Entscheidung: einen tragenden GP für das gewünschte Aroma anlegen bzw. '
            . 'beim Lieferanten anfragen (Grundprodukte / Lieferanten). Bis dahin bleibt die Kreativ-Idee ungeerdet.',
        // Trendradar-Automatisierung: ein proaktiver Info-Vorschlag (kein Datenmangel) — der Weg
        // führt in den Concepter, wo die erzeugten Entwürfe geprüft werden.
        'trend_konzept_vorschlag' => 'Die automatisch aus den Top-Trends erzeugten Konzept-Entwürfe im Concepter '
            . 'prüfen (Concepter → Entwürfe) — verfeinern oder verwerfen. Ein Vorschlag, keine Datenlücke.',
        // Spec 21 Tranche A — Küchen-Wissen am Einzelfall. Kein Sammel-propose (s. ASSIST),
        // aber sehr wohl ein benennbarer Ort.
        'rezept_ohne_zubereitung' => 'Zubereitung am Rezept ergänzen (Basisrezepte → Rezept → Zubereitung) — '
            . 'Küchen-Wissen am Einzelfall, deshalb kein Sammel-Vorschlag.',
        'rezept_mengen_luecke' => 'Fehlende Mengen an den Zutaten des Rezepts nachtragen; erst danach sind Ausbeute, '
            . 'EK und Nährwerte belastbar.',
        'rezept_yield_implausibel' => 'Ausbeute und Einsatzmasse am Rezept gegenprüfen — entweder die Mengen stimmen '
            . 'nicht oder die Ausbeute ist falsch gepflegt. Beides wird am Rezept entschieden.',
        'rezept_ein_zutat' => 'Am Rezept entscheiden: fehlende Zutaten ergänzen oder die Ein-Zutat-Rezeptur als '
            . 'gewollte Einzel-Komponente bestätigen.',
        'rezept_dublette' => 'Die beiden Rezepte nebeneinander öffnen und eines ausmustern oder umbenennen. Kein '
            . 'Automatismus: der Name hängt in Angeboten und Foodbooks.',
        'rezept_allergen_unbelastbar' => 'Die Allergen-Angaben der unbelastbaren Zutaten auf GP-/Artikel-Ebene klären; '
            . 'danach rechnet der Recompute das Rezept-Aggregat neu.',
        'rezept_zutaten_ungemappt' => 'Die ungemappten Zutaten im Rezept auf einen GP ziehen (Match-Pfad im '
            . 'Rezept-Editor) — ohne Mapping trägt die Zeile weder Preis noch Allergen.',
        'rezept_sub_stub_offen' => 'Den automatisch angelegten Sub-Rezept-Stub ausfüllen oder die Zutat auf ein '
            . 'bestehendes Rezept umhängen (Basisrezepte → Entwürfe).',
        // Tranche B — hinter dem Signal liegt bereits ein KI-Urteil je Befund (S5a/S5b).
        // Genau darum kein zweites Sammel-Urteil, aber der Weg ist eindeutig: die Objekt-
        // Liste öffnet das Rezept mit aufgeklappten Befunden (SignalTyp::istKiUrteil).
        'rezept_plausi_ki' => 'Am Rezept entscheiden: die abgelegten KI-Befunde je Zeile übernehmen oder verwerfen — '
            . 'die Objekt-Liste öffnet das Rezept direkt mit den Befunden.',
        'rezept_gericht_vs_komponente' => 'Am Rezept entscheiden, ob es ein Gericht oder eine Komponente ist. Die '
            . 'Umstellung kippt Taxonomie und Verkaufs-Facetten mit — deshalb einzeln und von Hand.',
        // Tranche C — der Concepter ist der Ort, an dem ein Konzept überhaupt entsteht.
        'konzept_slot_luecke' => 'Den unbesetzten Pflicht-Slot im Concepter belegen — oder das Planungs-Gerüst '
            . 'anpassen, wenn der Slot so nicht mehr gemeint ist.',
        'konzept_ohne_wording' => 'Kunden-Wording am Konzept schreiben (Concepter → Wording); ohne Wording ist das '
            . 'Konzept intern vollständig, aber nicht zeigbar.',
        'konzept_preisband_verletzt' => 'Im Concepter einen Gang tauschen oder das Preisband im Planungs-Gerüst '
            . 'anpassen — welche Seite nachgibt, ist eine Angebots-Entscheidung.',
        'konzept_regel_verletzt' => 'Im Concepter den Verstoß auflösen (Kandidat tauschen) oder die Regel im Gerüst '
            . 'korrigieren, falls sie so nicht gemeint war.',
        'konzept_dramaturgie' => 'Im Concepter prüfen, ob die wiederholte Hauptzutat gewollt ist (Themen-Menü) — '
            . 'sonst einen der betroffenen Gänge tauschen.',
        // Tranche D — das Kundendokument selbst.
        'foodbook_kapitel_leer' => 'Das Kapitel im Foodbook mit Gerichten belegen (Foodbook → Kapitel) oder das '
            . 'Kapitel streichen, wenn es nicht mehr gebraucht wird.',
        'foodbook_skizze_ungeerdet' => 'Die Kreativ-Skizze im Foodbook erden — auf ein reales Gericht ziehen oder '
            . 'verwerfen. Nach dem Kapitel-Go gehört keine unbelegte Skizze mehr ins Buch.',
        'foodbook_ziel_verfehlt' => 'Belegung im Foodbook oder das Kapitel-Ziel im Planungs-Gerüst angleichen — das '
            . 'Soll stammt aus dem Gerüst, nicht aus dem Buch.',
        'foodbook_stale' => 'Das Foodbook gegen den freigegebenen VK-Stand aktualisieren. Bei einem Buch, das schon '
            . 'draußen ist, ist das eine bewusste Freigabe — kein stiller Preissprung beim Kunden.',
        'foodbook_kapitel_ohne_text' => 'Hinführung am Kapitel schreiben (Foodbook → Kapitel → Text) — das Kapitel ist '
            . 'druckbar, nur nicht ausformuliert.',
        // Tranche E — Meta-Signal über die Zeitreihe.
        'qualitaet_drift' => 'Meta-Signal: behoben wird nicht hier, sondern in der Zeile des gestiegenen Zählers. '
            . 'Der Verlauf im Panel zeigt, seit wann es aufwärts geht.',
    ];

    /**
     * SignalTyp-Wert → ausdrückliche Begründung, warum es **keinen** Weg im System gibt
     * (V-033, Fälle a und c). Kein Plan, aber auch kein Schweigen: der Registry-Test
     * verlangt für jeden Typ Fixer, Assist, `navigate` oder genau diesen Eintrag.
     */
    private const OHNE_WEG = [
        'rezept_verwaist' => 'Urteilssache ohne Hebel: ein Rezept ohne Verwendung ist kein Fehler. Behalten '
            . '(Archiv, Saison, Baustein) oder ausmustern — beides ist eine Entscheidung, keine Reparatur.',
        'vertragsfrist_faellig' => 'Die Ursache liegt ausserhalb der App: die Frist läuft beim Lieferanten. Kündigen, '
            . 'verlängern oder neu verhandeln passiert am Vertrag — im System ist nichts zu korrigieren.',
    ];

    private const PLAN_NAVIGATE_LABEL = 'Weg zum Fix';

    /**
     * Plan für ein Signal — Auto-Fix, KI-Assistenz, Weg-Beschreibung (`navigate`) oder null.
     *
     * ⚠️ Nicht als „gibt es einen Knopf?" lesen — dafür `kiPlan()`. `navigate` ist ein
     * Erklär-Plan ohne Executor.
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

        // Metrik-fein vor typ-grob — dieselbe Reihenfolge wie oben: die Lage weiß mehr
        // über den Weg als der Typ (ein `datenqualitaet_gp_la` ohne LA ist Einkauf,
        // eines ohne Preis ist Preispflege).
        $weg = ($metrik !== null ? (self::NAVIGATE_METRIK[$metrik] ?? null) : null)
            ?? (self::NAVIGATE[$sig->type->value] ?? null);
        if ($weg !== null) {
            return ['kind' => 'navigate', 'flavorLabel' => self::PLAN_NAVIGATE_LABEL, 'plan' => $weg];
        }

        return null;
    }

    /**
     * Der **ausführbare** Plan — genau die zwei Arten, hinter denen ein Executor steht
     * (`SignalFixService::execute`/`assist`). Eine Stelle für die Frage „gibt es hier
     * einen KI-Knopf?", damit die vier Flächen, die sie stellen, nicht je eine eigene
     * Antwort bauen (vor 22·H4b war das schlicht `planFor() !== null`).
     *
     * @return array{kind:string,flavorLabel:string,plan:string,fixer?:string,metrik?:string,prompt?:string}|null
     */
    public static function kiPlan(FoodAlchemistSignal $sig): ?array
    {
        $plan = self::planFor($sig);

        return $plan !== null && in_array($plan['kind'], ['deterministic', 'assist'], true) ? $plan : null;
    }

    /**
     * Begründung, warum dieses Signal keinen Weg im System hat (V-033, Fälle a/c) —
     * oder null, wenn es einen gibt bzw. der Typ nichts hinterlegt hat. Die Fläche zeigt
     * sie **statt** des Plan-Kastens: „hier ist nichts zu tun" ist eine Aussage,
     * ein leerer Bereich ist keine.
     */
    public static function ohneWegGrund(FoodAlchemistSignal $sig): ?string
    {
        if (self::planFor($sig) !== null) {
            return null;
        }

        return self::OHNE_WEG[$sig->type->value] ?? null;
    }

    /**
     * §9-Registry für den Test: welche Typen sind typ-grob eingeordnet, welche metrik-fein?
     * Bewusst als Datenrückgabe und nicht als Test-Konstante — eine Registry, die im Test
     * nachgebaut wird, prüft die Kopie statt das Original.
     *
     * @return array{assist:list<string>,navigate:list<string>,ohne_weg:list<string>,metrik_fein:list<string>}
     */
    public static function wegRegistry(): array
    {
        return [
            'assist' => array_keys(self::ASSIST),
            'navigate' => array_keys(self::NAVIGATE),
            'ohne_weg' => array_keys(self::OHNE_WEG),
            'metrik_fein' => self::METRIK_FEIN,
        ];
    }

    /** Effektiver Metrik-Key: payload['metrik'] (DataQuality) bzw. abgeleitet aus dem Detektor-Signal. */
    public static function metrik(FoodAlchemistSignal $sig): ?string
    {
        $pl = is_array($sig->payload) ? $sig->payload : [];
        if (! empty($pl['metrik'])) {
            return (string) $pl['metrik'];
        }
        // SignalDetektorService::datenqualitaetGpLa (GP ohne Lead) trägt kein metrik, aber stabilen dedup_key.
        //
        // H2c/V-014: die Ableitung zeigt auf die **fixbare** Lage. Das Signal selbst nennt
        // weiterhin den ganzen Befund (LAs fehlen ODER Lead fehlt) — der Knopf daran darf
        // aber nur den Teil anfassen, in dem er etwas setzen kann. Die Verschiebung ist
        // eine Verengung des Schreib-Satzes, nie eine Erweiterung.
        if ($sig->dedup_key === 'datenqualitaet-gp-ohne-la') {
            return 'gp_kein_lead';
        }

        return null;
    }
}
