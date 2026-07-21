<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Signale-Cockpit — reine PRÄSENTATIONS-Metadaten für den „KI erledigen lassen"-
 * Steuer-Rahmen (#378-Folge). KEINE Logik, kein DB-/Service-Zugriff: nur die
 * Zuordnung, welcher Signaltyp einen KI-Knopf zeigt, in welcher Ausprägung
 * (Auto-Fix vs. KI-Assistenz) und mit welchem „so würde die KI das angehen"-Text.
 *
 * Die eigentlichen Fixer/Assistenzen sind bewusst NACHGELAGERT (Ausführen im UI
 * noch deaktiviert). Wenn sie landen, werden sie über die bestehenden Services/
 * Commands verdrahtet (Allergen-Backfill, Lead-LA-Repick → Recompute, Lieferanten-
 * Rückfrage-Entwurf …) — dann greift auch der MCP-Lockstep. Bis dahin ist diese
 * Klasse die einzige Stelle, die das Cockpit „weiß".
 *
 * Belegung aus der Fix-Weg-Erkundung:
 *  - fix    = deterministischer Auto-Fixer existiert bereits als Command
 *  - assist = Geschäfts-/Sourcing-Urteil → KI kann nur Entwurf/Vorschlag liefern
 *  - null   = kein KI-Knopf (reine Urteilssache / externe Daten / echte Handlung)
 */
final class SignalCockpit
{
    /**
     * KI-Affordance je Signaltyp (SignalTyp::value) — oder null, wenn kein KI-Knopf.
     *
     * @return array{flavor:string,flavorLabel:string,plan:string}|null
     */
    public static function kiAffordance(string $typ): ?array
    {
        return self::map()[$typ] ?? null;
    }

    /** @return array<string,array{flavor:string,flavorLabel:string,plan:string}> */
    private static function map(): array
    {
        $fix = fn (string $plan) => ['flavor' => 'fix', 'flavorLabel' => 'Auto-Fix', 'plan' => $plan];
        $assist = fn (string $plan) => ['flavor' => 'assist', 'flavorLabel' => 'KI-Assistenz', 'plan' => $plan];

        return [
            // ── Auto-Fix (deterministische Fixer als Command vorhanden) ──────────
            'datenqualitaet_gp_la' => $fix(
                'Allergen-Konfidenz und Lead-Lieferantenartikel deterministisch aus den verknüpften '
                . 'Artikeln nachziehen (gp-allergen-backfill / lead-la-repick) und neu rechnen. Nur der '
                . 'auflösbare Teil — echte Beschaffungs-Lücken (GP ohne bepreisten LA) bleiben offen.'
            ),
            'ek_kette_unvollstaendig' => $fix(
                'Lead-Lieferantenartikel dort neu wählen, wo er auf einen Preis auflöst, und die EK-Kette '
                . 'neu berechnen (lead-la-repick → recompute). Verbleibende GPs ohne bepreisten LA sind '
                . 'eine echte Sourcing-Lücke und bleiben stehen.'
            ),

            // ── KI-Assistenz (Entwurf/Vorschlag — Entscheidung bleibt beim Menschen) ─
            'preis_sprung_marge_impact' => $assist(
                'Betroffene Gerichte + berechnete günstigere Alternative zusammenstellen und einen '
                . 'Lieferanten-Rückfrage-Entwurf erzeugen („warum ist Artikel X so teuer?"). Das Umschalten '
                . 'des Lead-Lieferantenartikels bleibt deine Sourcing-Entscheidung.'
            ),
            'preis_anomalie' => $assist(
                'Ausreißer-Preise gegen den Warengruppen-Median prüfen und je Fall einordnen '
                . '(Tippfehler / Premium / echt). Vorschlag zur Sichtung — kein stiller Fix.'
            ),
            'marge_unter_ziel' => $assist(
                'Hebel vorschlagen: VK-Erhöhung auf die Zielmarge oder günstigere Warenkorb-Alternative. '
                . 'Die Preis-/Rezept-Entscheidung triffst du.'
            ),
            'wareneinsatz_ueber_ziel' => $assist(
                'Wareneinsatz-Treiber je Gericht aufschlüsseln und Einspar-Optionen vorschlagen. '
                . 'Die Umsetzung bleibt Geschäftsentscheidung.'
            ),
            'vk_anpassung_empfohlen' => $assist(
                'Freigabe-Batch der abweichenden Live-VK zusammenstellen — du bestätigst bewusst '
                . '(kein stiller Kunden-Preissprung).'
            ),
            'anker_fehlt' => $assist(
                'Flavor-/Kern-Anker vorschlagen — deterministisches Namens-Matching gegen den Anker-Katalog, '
                . 'unklare Fälle per KI (Aroma-Profil). Du bestätigst, dann werden die GPs/Rezepte im '
                . 'Pairing-Graph sichtbar.'
            ),
            'servierform_unbestimmt' => $assist(
                'Passende Servierform je Gericht vorschlagen (KI-Klassifikation nach Bauart) — du bestätigst, '
                . 'dann steht die Standard-Darreichung statt „unbestimmt".'
            ),
        ];
    }
}
