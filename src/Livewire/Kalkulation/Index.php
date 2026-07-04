<?php

namespace Platform\FoodAlchemist\Livewire\Kalkulation;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * M12 / #379+ (Dominique 2026-06-16): Kalkulations-Werkstatt = Controlling-Zentrum.
 *
 * EIN zentraler Ort, an dem der F&B-Manager seine stehenden Kosten pflegt —
 * Personal/Stundensatz, Fixkosten (Strom, Nebenkosten, Logistik …), Aufschlag-
 * sätze und Marge — und von dem aus sie auf ALLE Kalkulationen ausrollen
 * (HK2, VK-Vorschlag, „Marge unter Ziel"-Signal). Der eigentliche Kosten-Editor
 * (vormals Einstellungen → Herstellkosten) ist hier eingebettet.
 *
 * Die reine, gerichts-/mengenbezogene Kalkulation (HK1 → HK2 → VK → DB) findet
 * im Concepter (Concepts) bzw. je Einzelgericht im Verkaufs-Browser statt — die
 * Werkstatt liefert nur die Regeln dafür.
 */
class Index extends Component
{
    /** Re-Render der Cockpit-Kacheln, sobald der eingebettete Editor speichert. */
    #[On('kosten-aktualisiert')]
    public function aktualisiert(): void
    {
        // no-op: löst nur das Re-Rendering der Summary-Kacheln aus.
    }

    public function render(KalkulationService $kalk, FixkostenService $fix, TeamSettingsService $settings)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        // #379+ Controlling-Kennzahlen: Σ Fixkosten/Monat + Food-Cost-Ziel → Break-even.
        // Break-even-Umsatz/Monat = Σ Fixkosten ÷ Deckungsbeitragsquote (= 1 − Wareneinsatzquote);
        // gastro-Standardformel, Planungs-Näherung (Ø-DB über das Food-Cost-Ziel).
        $fixMonat = array_sum($fix->summeJeBlock($team));
        $zielWe = $settings->zielWareneinsatzPct($team);
        $dbQuote = max(0.01, 1 - $zielWe / 100);

        return view('foodalchemist::livewire.kalkulation.index', [
            'zuschlag' => $kalk->hk2($team, 100) - 100, // effektiver HK2-Zuschlag in % (auf 100 € Wareneinsatz)
            'regeln' => [
                'marge_pct' => $settings->margePct($team),
                'stundensatz' => $settings->stundensatz($team),
                'schema' => collect($fix->aufgeloestesSchema($team))->filter(fn ($b) => $b['active'] ?? true)->values()->all(),
            ],
            'fixkostenMonat' => $fixMonat,
            'zielWe' => $zielWe,
            'breakEven' => $fixMonat > 0 ? $fixMonat / $dbQuote : 0.0,
            'mwst' => $settings->mwst($team),
        ])->layout('platform::layouts.app');
    }
}
