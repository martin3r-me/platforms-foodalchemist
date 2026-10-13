<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;

/**
 * Spec 82 §13 B1/B4 — Auskunft über Abos, die an einem Stamm-Paket oder am Master-Team hängen.
 *
 * Heute gibt es noch keine Abo-Tabelle (Spec 82 Schritt 7), die Antworten sind deshalb leer. Diese Klasse ist die
 * eine Schnittstelle, die Stamm-Paket-Abbestellung und Master-Wechsel für ihre Wirkungs-Vorschau fragen. Mit den Abos
 * wird sie gefüllt, die Aufrufer ändern sich nicht. Im Test lässt sie sich per Container ersetzen.
 */
class AboAuskunft
{
    /**
     * Abos des Mandanten, die ein gebuchtes Stamm-Paket voraussetzen (Entscheidung 1 = C) und ohne es ungültig würden.
     *
     * @return list<array{id:int, sammlung:string, master_team_id:int}>
     */
    public function abosAmStammPaket(Team $mandant): array
    {
        return [];
    }

    /** Gibt es das Abo-Modell schon? Solange nicht (Spec 82 Schritt 7), entfällt „Abos kündigen“ beim Beenden eines Mandanten. */
    public function verfuegbar(): bool
    {
        return false;
    }

    /**
     * Alle Abos des Mandanten (Beenden: werden gekündigt, genutzte Rezepte als tiefe Kopie, §12 D).
     *
     * @return list<array{id:int, sammlung:string, master_team_id:int}>
     */
    public function abosDesMandanten(Team $mandant): array
    {
        return [];
    }

    /**
     * Abos, deren Sichtbarkeit am Master-Team hängt (ein Master-Wechsel ändert sie alle).
     *
     * @return list<array{id:int, mandant_team_id:int, sammlung:string}>
     */
    public function abosAmMaster(?int $masterTeamId): array
    {
        return [];
    }
}
