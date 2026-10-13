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
        'master_team_gesetzt' => 'Master-Team festgelegt',
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
        'abo_rezept_zurueckgezogen' => 'Abo-Rezept zurückgezogen', // Spec 82 §12 D
        'projekt_lizenz_uebernommen' => 'Projekt: Lizenz erteilt, Übernahme in den Mandanten',   // Spec 82 §9
        'paket_grundeinstellungen_gebucht' => 'Paket „Grundeinstellungen“ gebucht',   // Spec 82 §13 I
        'paket_grundeinstellungen_nachgezogen' => 'Paket „Grundeinstellungen“ nachgezogen (neue Vorlagen-Zeilen kopiert)',
        'plan_preis_gesetzt' => 'Gebühren-Preis gesetzt',
        'plan_preis_startwerte' => 'Gebühren-Startpreise angelegt',
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
