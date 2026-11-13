<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Formular-Zustand der Darreichungs-Zeilen im Gericht-Modal (Reiter Kalkulation).
 *
 * Aus VkModal herausgelöst (Spec 85 §1), damit der Festpreis-Weg dazukommen konnte:
 *  - Zahlen stehen deutsch im Feld („3,90“ statt „3.9“). Der Service liest das Komma schon.
 *  - Ein fester Preis speichert beim Verlassen von Preis- ODER Begründungsfeld, sobald beide
 *    gefüllt sind (Dominique 13.11.2026). Fehlt eins, wird nicht gespeichert und kein Fehler
 *    gesetzt — die Zeile zeigt „Preis fehlt“ bzw. „Begründung fehlt“.
 *
 * Erwartet in der Komponente: $darForm, $darDeltaOffen, $darNeueForm, $recipeId,
 * darreichungSpeichern(), savedToast().
 */
trait HatDarreichungsFormular
{
    private function ladeDarreichungen(): void
    {
        $this->darForm = [];
        $this->darDeltaOffen = null;
        $this->darNeueForm = '';
        if ($this->recipeId === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        $defaultMarkupClassId = $team !== null
            ? app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->defaultMarkupClassId($team)
            : null;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::with('recipe')->where('recipe_id', $this->recipeId)->get() as $d) {
            $this->darForm[$d->id] = [
                'quantity_per_unit_g' => self::darZahl($d->quantity_per_unit_g),
                'unit_count' => self::darZahl($d->unit_count),
                'unit_vocab_id' => $d->unit_vocab_id,
                'markup_class_id' => $d->markup_class_id ?? $defaultMarkupClassId,
                'price_mode' => $d->price_mode,
                'sales_net' => self::darZahl($d->sales_net, 2),
                'vat_profile_key' => $d->vat_profile_key,
                'price_override_reason' => $d->price_override_reason,
                'price_override_expires_at' => $d->price_override_expires_at?->format('Y-m-d'),
                'vessel_gp_id' => $d->vessel_gp_id,
                'serving_vehicle_vocab_id' => $d->serving_vehicle_vocab_id,
                // Spec 83 B3i/B3l: Anrichten je Darreichung (Einheit aus der Form) + Pauschale je Auftragszeile
                'anrichten_wert' => self::darZahl($d->anrichten_wert),
                'anrichten_pauschal_min' => self::darZahl($d->anrichten_pauschal_min),
            ];
        }
    }

    /** Deutsch fürs Eingabefeld: feste Nachkommastellen (Preis) oder ohne überflüssige Nullen. */
    private static function darZahl(?float $wert, ?int $stellen = null): ?string
    {
        if ($wert === null) {
            return null;
        }
        if ($stellen !== null) {
            return number_format($wert, $stellen, ',', '');
        }

        return rtrim(rtrim(number_format($wert, 3, ',', ''), '0'), ',');
    }

    /**
     * Preis- oder Begründungsfeld einer festen Zeile verlassen (wire:change wie die übrigen Zeilenfelder,
     * damit Bearbeitungssperre und Handy-Lesemodus über den globalen call-Haken greifen).
     */
    public function darreichungFestpreisFeld(int $id): void
    {
        $zeile = $this->darForm[$id] ?? null;
        if ($zeile === null || ! in_array($zeile['price_mode'] ?? null, ['fixed', 'manuell'], true)
            || self::darFestpreisFehlt($zeile) !== null) {
            return;                                              // unvollständig: Hinweis an der Zeile, kein Fehler
        }
        $this->darreichungSpeichern($id);
        if ($this->fehler === null) {
            $this->savedToast('Festpreis gespeichert');
        }
    }

    /** „Preis fehlt“ / „Begründung fehlt“ für eine feste Zeile, sonst null. */
    public static function darFestpreisFehlt(array $zeile): ?string
    {
        return match (true) {
            trim((string) ($zeile['sales_net'] ?? '')) === '' => 'Preis fehlt',
            trim((string) ($zeile['price_override_reason'] ?? '')) === '' => 'Begründung fehlt',
            default => null,
        };
    }
}
