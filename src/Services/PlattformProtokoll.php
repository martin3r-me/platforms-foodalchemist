<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\User;

/**
 * Spec 82 §13 C4 — Protokoll aller Plattform-Admin-Aktionen (Stamm-Paket, Master-Team, Mandanten, später Gebühren, Abos)
 * über das vorhandene activity-log.
 *
 * Das echte Paket `platform-activity-log` kennt KEINE globale Funktion `activity()`. Es liefert das Trait `LogsActivity`
 * (schreibt created/updated/deleted automatisch und bietet `logActivity()` für eigene Einträge) und das Modell
 * `ActivityLogActivity`, polymorph am Modell. Deshalb schreibt dieses Protokoll immer an ein **Subjekt-Modell**, das `LogsActivity` trägt
 * (der Optin, die Einstellung, die Mandanten-Zeile). Ein früherer Stand rief `activity()` und schrieb in Produktion nichts.
 *
 * Ohne Subjekt oder mit einem Modell ohne `logActivity()` (Sandbox-Stub) passiert nichts und nichts bricht: Das Protokoll darf
 * die Steueraktion nie verhindern. Die Zeilen der jeweiligen Tabelle tragen zusätzlich Wer und Wann.
 */
class PlattformProtokoll
{
    /** Klartext je Aktion (die Aktion selbst steht zusätzlich in den Metadaten). */
    private const TEXTE = [
        'stamm_paket_gebucht' => 'Stamm-Paket gebucht',
        'stamm_paket_abbestellt' => 'Stamm-Paket abbestellt',
        'stamm_lieferanten_gesetzt' => 'Stamm-Lieferanten freigeschaltet',   // Spec 82: Lieferanten je Mandant
        'master_team_gesetzt' => 'Master-Team festgelegt',
        'naehrwert_pruefung_modus_gesetzt' => 'Nährwert-Prüfung: Modus geändert',
        'mandant_modus_geaendert' => 'Mandanten-Modus geändert',
        'mandant_gepflegt' => 'Mandant gepflegt',
        'mandant_beenden_gestartet' => 'Mandant beenden: Lauf gestartet',
        'mandant_beenden_abgebrochen' => 'Mandant beenden: Lauf abgebrochen',
        'mandant_beenden_schritt' => 'Mandant beenden: Schritt ausgeführt',
        'mandant_beenden_abgeschlossen' => 'Mandant beenden: Lauf abgeschlossen (Team gesperrt)',
        'mandant_entsperrt' => 'Mandant entsperrt (Status zurück auf aktiv)',
        'abo_vergeben' => 'Abo vergeben',                    // Spec 82 §12 A1 (Kurator-Aufsatz)
        'fassung_veroeffentlicht' => 'Fassung veröffentlicht', // Spec 82 §12 B
        'rezept_fassung_gefolgt' => 'Eigene Rezepte folgen der neuen Abo-Fassung',   // Spec 82 §12 B / 4d
        'rezept_fassung_uebernommen' => 'Rezept: neuere Fassung übernommen',
        'angebot_fassung_uebernommen' => 'Angebot: neuere Fassung übernommen',   // Spec 82 §12 B3
        'abo_gekuendigt' => 'Abo gekündigt',                  // Spec 82 §12 D
        'abo_rezept_zurueckgezogen' => 'Abo-Rezept zurückgezogen', // Spec 82 §12 D (Bestand)
        'abo_objekt_zurueckgezogen' => 'Abo-Objekt zurückgezogen', // Spec 82 Abo Konzepte/Pakete PR 6: Rezept, Paket oder Konzept
        'projekt_lizenz_uebernommen' => 'Projekt: Lizenz erteilt, Übernahme in den Mandanten',   // Spec 82 §9
        'paket_grundeinstellungen_gebucht' => 'Paket „Grundeinstellungen“ gebucht',   // Spec 82 §13 I
        'paket_grundeinstellungen_nachgezogen' => 'Paket „Grundeinstellungen“ nachgezogen (neue Vorlagen-Zeilen kopiert)',
        'preis_stand_ausgeblendet' => 'Preis-Stand als Fehler ausgeblendet',   // Preisverlauf: Erfassungsfehler aus Linie/Trend
        'preis_stand_eingeblendet' => 'Preis-Stand wieder eingeblendet',
        'plan_preis_gesetzt' => 'Gebühren-Preis gesetzt',
        'plan_preis_startwerte' => 'Gebühren-Startpreise angelegt',
        'support_zugang_erteilt' => 'Support-Zugang erteilt',          // Spec 84 §4.4 (E3)
        'support_zugang_widerrufen' => 'Support-Zugang widerrufen',
        'support_zugriff' => 'Zugriff im Support-Zugang',
        'marke_kuerzel_zugeordnet' => 'Marken-Kürzel zugeordnet (Marken-Nachlauf)',   // Plan GP-Stamm 3g
        'gp_marke_nachgerendert' => 'GP-Name mit Marke neu gerendert (Marken-Nachlauf)',
        'marke_kuerzel_gesperrt' => 'Wort als „Kein Kürzel“ gesperrt (Marken-Nachlauf)',
        'marke_zusammengefuehrt' => 'Marke für alle Lieferanten zusammengeführt',
    ];

    /**
     * @param  array<string,mixed>  $daten
     * @param  Model|null  $subjekt  Modell mit LogsActivity, an dem der Eintrag hängt (auch ein eben gelöschtes)
     */
    public function schreibe(string $aktion, array $daten, ?User $user, ?Model $subjekt = null): void
    {
        if ($subjekt === null || ! method_exists($subjekt, 'logActivity')) {
            return;
        }
        try {
            $subjekt->logActivity(self::TEXTE[$aktion] ?? $aktion, $daten + ['aktion' => $aktion, 'akteur_id' => $user?->id]);
        } catch (\Throwable) {
            // Das Protokoll darf die Steueraktion nie verhindern.
        }
    }
}
