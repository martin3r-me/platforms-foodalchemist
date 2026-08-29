<?php

namespace Platform\FoodAlchemist\Livewire\Formate;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FormatService;

/**
 * Format-Modul (Phase B): Detail-Panel — Identität + Editionen + Preis-Range +
 * Marketing-Hero des gewählten Formats. Aktionen (Bearbeiten/Löschen) dispatchen
 * an Browser/Editor.
 */
class DetailPanel extends Component
{
    public ?int $selectedId = null;

    #[On('formate-selected')]
    public function zeige(?int $id): void
    {
        $this->selectedId = $id;
    }

    #[On('formate-gespeichert')]
    public function aktualisiere(): void
    {
        // Re-render mit frischen Daten (Auswahl bleibt).
    }

    public function bearbeiten(): void
    {
        if ($this->selectedId !== null) {
            $this->dispatch('formate-editor.oeffnen', id: $this->selectedId);
        }
    }

    public function loeschen(FormatService $formats): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $id = $this->selectedId;
        try {
            $formats->delete($this->team(), $id);
        } catch (\RuntimeException) {
            return;
        }
        $this->selectedId = null;
        $this->dispatch('formate-geloescht', id: $id);
    }

    public function render(FormatService $formats)
    {
        $format = $this->selectedId !== null ? $formats->detail($this->team(), $this->selectedId) : null;
        if ($this->selectedId !== null && $format === null) {
            $this->selectedId = null;
        }

        // #3: Cockpit — read-only Reduktion der Editions-Preise (Preisspanne + Ø €/P + Zählung),
        // KEINE eigene Formel/Recompute. Editionen = Alternativen → Range statt Summe.
        // Ebene 2: mit Brille der €/Gast je Edition live gegen den Betrieb (preisCockpit), sonst Cache.
        $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->current($this->team());
        $conceptSlots = $format !== null ? $format->slots->where('type', 'concept') : collect();
        $vks = $conceptSlots
            ->map(function ($s) use ($outlet) {
                $c = $s->concept;
                if ($c === null) {
                    return null;
                }
                return $outlet !== null
                    ? (float) app(\Platform\FoodAlchemist\Services\ConceptService::class)->preisCockpit($c, $outlet)['price_per_person']
                    : ($c->price_per_person_cache !== null ? (float) $c->price_per_person_cache : null);
            })
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->values();
        $cockpit = [
            'n_editionen' => $conceptSlots->count(),
            'n_struktur' => $format !== null ? $format->slots->whereIn('type', ['header', 'text', 'spacer'])->count() : 0,
            'min' => $vks->isEmpty() ? null : round((float) $vks->min(), 2),
            'max' => $vks->isEmpty() ? null : round((float) $vks->max(), 2),
            'avg' => $vks->isEmpty() ? null : round((float) $vks->avg(), 2),
        ];

        return view('foodalchemist::livewire.formate.detail-panel', [
            'format' => $format,
            // Range = min/max der (brillen-scharfen) Editions-Preise — spiegelt das Cockpit.
            'range' => ['min' => $cockpit['min'], 'max' => $cockpit['max']],
            'cockpit' => $cockpit,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
