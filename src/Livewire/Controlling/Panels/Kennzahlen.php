<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Die ausgerollten Kalkulations-Kennzahlen als Kontext: Zielmarge, Zielwareneinsatz,
 * effektiver HK2-Zuschlag, Fixkosten-Schema, Break-even, MwSt.
 *
 * Der Regel-EDITOR (Zuschläge/Fixkosten/Marge) lebt seit #502 in den Einstellungen →
 * Herstellkosten und bleibt dort — hier steht, was daraus folgt, plus der Sprung dorthin.
 * Die gerichts-/mengenbezogene Kalkulation (HK1 → HK2 → VK → DB) findet im Concepter bzw.
 * je Einzelgericht im Verkaufs-Browser statt.
 *
 * **Spec 32:** war bis 2026-08-02 die Seite `/kalkulation` (Livewire `Kalkulation\Index`),
 * die neben diesen Kacheln auch die Preissimulation trug. Die Simulation hat im Controlling
 * einen eigenen Tab bekommen; hier bleiben die Kennzahlen. Kein `x-ui-page`/`->layout()` mehr.
 *
 * Der Break-even wird bewusst mit derselben Formel gerechnet wie im KPI-Kopf des Cockpits
 * ({@see \Platform\FoodAlchemist\Livewire\Controlling\Cockpit}) — zwei Break-even-Zahlen
 * im selben Modul wären ein Widerspruch, den niemand auflösen kann.
 */
class Kennzahlen extends Component
{
    /** Zielwareneinsatz in % — inline setzbar (s. {@see self::zieleSpeichern}). */
    public string $zielWe = '';

    /** Zielmarge in %. */
    public string $marge = '';

    public ?string $meldung = null;

    public function mount(TeamSettingsService $settings): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->zielWe = (string) $settings->zielWareneinsatzPct($team);
        $this->marge = (string) $settings->margePct($team);
    }

    /** Re-Render der Kennzahlen-Kacheln nach Regel-Änderung (Einstellungen). */
    #[On('kosten-aktualisiert')]
    public function aktualisiert(): void
    {
        // no-op: löst nur das Re-Rendering der Summary-Kacheln aus.
    }

    /**
     * Spec 32 — die zwei Zielwerte, gegen die im Controlling ständig gemessen wird, direkt hier
     * setzen: Zielwareneinsatz und Zielmarge.
     *
     * Bewusst NUR diese zwei. Das vollständige Zuschlagsschema (Blöcke, Bezugsbasen, Fixkosten)
     * bleibt in den Einstellungen → Herstellkosten — es zweimal bedienbar zu machen hieße, zwei
     * Formulare auf dieselben Spalten zu setzen. Wer die Ampel verstellt, während er sie liest,
     * braucht dafür aber keinen Seitenwechsel.
     *
     * Geschrieben wird über denselben `TeamSettingsService::update`-Pfad wie dort, inklusive
     * `kosten-aktualisiert` — sonst zeigten Nachbar-Flächen weiter den alten Stand.
     */
    public function zieleSpeichern(TeamSettingsService $settings): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        $this->validate([
            'zielWe' => 'required|numeric|min:1|max:99',
            'marge' => 'required|numeric|min:0|max:500',
        ], [], ['zielWe' => 'Ziel-Wareneinsatz', 'marge' => 'Zielmarge']);

        $settings->update($team, [
            'target_food_cost_pct' => (float) str_replace(',', '.', $this->zielWe),
            'margin_pct' => (float) str_replace(',', '.', $this->marge),
        ]);

        $this->meldung = 'Zielwerte gespeichert.';
        $this->dispatch('kosten-aktualisiert');
    }

    public function render(KalkulationService $kalk, FixkostenService $fix, TeamSettingsService $settings, MargeService $marge)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        // #379+ Controlling-Kennzahlen: Σ Fixkosten/Monat + Food-Cost-Ziel → Break-even.
        // Break-even-Umsatz/Monat = Σ Fixkosten ÷ Deckungsbeitragsquote (= 1 − Wareneinsatzquote);
        // gastro-Standardformel, Planungs-Näherung (Ø-DB über das Food-Cost-Ziel).
        $fixMonat = array_sum($fix->summeJeBlock($team));
        $zielWe = $settings->zielWareneinsatzPct($team);

        return view('foodalchemist::livewire.controlling.panels.kennzahlen', [
            'zuschlag' => $kalk->hk2($team, 100) - 100, // effektiver HK2-Zuschlag in % (auf 100 € Wareneinsatz)
            'regeln' => [
                'marge_pct' => $settings->margePct($team),
                'stundensatz' => $settings->stundensatz($team),
                'schema' => collect($fix->aufgeloestesSchema($team))->filter(fn ($b) => $b['active'] ?? true)->values()->all(),
            ],
            'fixkostenMonat' => $fixMonat,
            'zielWe' => $zielWe,
            'breakEven' => $marge->breakEven($fixMonat, $zielWe),
            'mwst' => $settings->mwst($team),
        ]);
    }
}
