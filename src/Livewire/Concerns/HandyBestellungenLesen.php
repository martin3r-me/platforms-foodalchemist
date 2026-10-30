<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\BestellListeService;
use Platform\FoodAlchemist\Support\HandyLesemodus;

/**
 * #1003 PR5 · Bestellungen am Handy (Orders\Index): nur der Reiter „Bestellungen“ — Karten je Bestellung, gruppiert nach
 * Liefertag, mit Suche und Zeitraum. Derselbe Filterweg wie am Computer und in orders.GET (BestellListeService::filtern,
 * team-sichtbar). Antippen öffnet das vorhandene Detail (ohne Editor — oeffnen() am Computer öffnet den Editor).
 * Liefertage, Lieferanten, Runden und alle Versand-/Auslöse-/Storno-Aktionen bleiben am Computer.
 * Die Komponente ruft handyBestellungen() am Anfang von render().
 */
trait HandyBestellungenLesen
{
    use HandyLesebereich;

    public function handyLeseMethoden(): array
    {
        return ['handyOeffnen', 'waehleZeitraum', 'aktualisiere'];
    }

    /** Auswahl fürs Detail, ohne den Bestell-Editor zu öffnen. */
    public function handyOeffnen(int $id): void
    {
        $this->selectedOrderId = $id;
    }

    private function handyBestellungenAktiv(): bool
    {
        return HandyLesemodus::anfrageVomHandy();
    }

    private function handyBestellungen()
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $liste = app(BestellListeService::class)->filtern($team, [
            'status' => $this->statusFilter, 'datumsbasis' => 'liefertag', 'von' => $this->von, 'bis' => $this->bis,
            'supplier_id' => $this->supplierFilter, 'production_order_id' => $this->productionFilter,
            'nur_mit_positionen' => $this->nurMitPositionen, 'nur_mit_klaerung' => $this->nurMitKlaerung, 'suche' => $this->suche,
        ])['liste'];

        return view('foodalchemist::livewire.handy.bestellungen', [
            'gruppen' => $liste->groupBy(fn ($o) => $o->desired_delivery_date?->toDateString() ?? '')
                ->map(fn ($items, $tag) => [
                    'label' => $tag === '' ? 'Ohne Liefertag' : Carbon::parse($tag)->locale('de')->isoFormat('dddd, DD.MM.YYYY'),
                    'bestellungen' => $items->values(),
                ]),
            'anzahl' => $liste->count(),
            'summe' => round((float) $liste->sum('total_net'), 2),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
