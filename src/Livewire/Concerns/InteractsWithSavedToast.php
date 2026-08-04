<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

/**
 * Einheitliche, flüchtige „Gespeichert"-Rückmeldung für ALLE Save-Aktionen.
 *
 * Feuert das Browser-Event `fa-saved`, das die einmal in der Modul-Sidebar
 * gemountete Alpine-Insel (<x-foodalchemist::saved-toast />) auffängt und als
 * kurzen Toast (~2,5 s) zeigt. Bewusst KEIN platforms-notifications: das
 * geteilte Notice-System persistiert jede Meldung als DB-Zeile (Replay/Badge)
 * — falsch für „Meldung bei jedem Save". Hier: rein clientseitig, keine DB.
 *
 * Vertrag:
 *  - Bindestrich-Event `fa-saved` (das gebündelte Alpine 3.15 ignoriert
 *    `.dot`-Modifier — dotted Namen wie `modal.open` brauchen addEventListener,
 *    siehe components/modal.blade.php; `@fa-saved.window` greift dagegen sauber).
 *  - NUR auf dem Erfolgspfad aufrufen (nach erfolgreichem Persist, nie im catch,
 *    nie bei Pro-Zellen-/Keystroke-Autosaves — sonst Toast-Flackern).
 *  - Bei Save + Hard-Redirect zuerst toasten, dann redirecten.
 *  - protected → nicht per Client-Call auslösbar.
 */
trait InteractsWithSavedToast
{
    /** Erfolgs-Toast (grün). */
    protected function savedToast(?string $message = null): void
    {
        $this->dispatch('fa-saved', message: $message ?: 'Gespeichert', type: 'success');
    }

    /** Fehler-Toast (rot) über denselben Kanal — ersetzt die toten 'notify'-Dispatches. */
    protected function errorToast(string $message): void
    {
        $this->dispatch('fa-saved', message: $message, type: 'error');
    }
}
