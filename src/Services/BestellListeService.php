<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;

/**
 * Spec 91 PR 4 · Ein Filterweg für den Bestell-Browser (Orders\Index) und foodalchemist.orders.GET.
 *
 * Vorher filterte nur die Livewire-Komponente nach Lieferant, Produktion, Positionen, Klärung und Suche; orders.GET kannte
 * davon nichts. OrderService steht am Größen-Bestand (Spec 85), daher eine eigene Klasse.
 */
class BestellListeService
{
    public function __construct(private OrderService $orders) {}

    /**
     * Schlüssel: status, datumsbasis, von, bis, supplier_id, production_order_id, nur_mit_positionen, nur_mit_klaerung, suche.
     *
     * @return array{roh: Collection, herkunft: Collection, liste: Collection}  roh = nur Status/Datum (Auswahllisten),
     *                                                                          herkunft = je Bestellung, liste = alle Filter
     */
    public function filtern(Team $team, array $f): array
    {
        $roh = $this->orders->listForTeam($team, ($f['status'] ?? '') !== '' ? (string) $f['status'] : null, [
            'datumsbasis' => $f['datumsbasis'] ?? 'liefertag',
            'von' => ($f['von'] ?? null) ?: null,
            'bis' => ($f['bis'] ?? null) ?: null,
        ]);

        $herkunft = $roh->mapWithKeys(fn ($o) => [(int) $o->id => $this->orders->herkunftAggregat(
            $o->lines->flatMap(fn ($l) => array_keys((array) ($l->source_contributions ?? [])))->values()->all(),
        )]);
        // #6 N+1: EIN whereIn ueber alle Bestellungen statt je Order ein ProductionOrder-Query.
        $herkunft = collect($this->orders->herkunftMitProduktionsnamenBulk($team, $herkunft->all()));

        $lieferant = ! empty($f['supplier_id']) ? (int) $f['supplier_id'] : null;
        $produktion = ! empty($f['production_order_id']) ? (int) $f['production_order_id'] : null;
        $suche = trim(mb_strtolower((string) ($f['suche'] ?? '')));
        $liste = $roh
            ->when($lieferant !== null, fn ($c) => $c->filter(fn ($o) => (int) $o->supplier_id === $lieferant))
            ->when($produktion !== null, fn ($c) => $c->filter(fn ($o) => collect($herkunft[(int) $o->id] ?? [])
                ->contains(fn ($h) => (int) ($h['production_order_id'] ?? 0) === $produktion)))
            ->when(! empty($f['nur_mit_positionen']), fn ($c) => $c->filter(fn ($o) => $o->lines->count() > 0))
            ->when(! empty($f['nur_mit_klaerung']), fn ($c) => $c->filter(fn ($o) => $this->orders->orderWarnings($o) !== []))
            ->when($suche !== '', fn ($c) => $c->filter(fn ($o) => str_contains($this->suchtext($o, $herkunft), $suche)));

        return ['roh' => $roh, 'herkunft' => $herkunft, 'liste' => $liste->values()];
    }

    /** Kopf, Herkunft und alle Positionstexte einer Bestellung, kleingeschrieben. */
    private function suchtext($o, Collection $herkunft): string
    {
        $positionen = $o->lines
            ->map(fn ($l) => implode(' ', [
                $l->designation ?? '',
                $l->article_number ?? '',
                $l->packaging_unit ?? '',
                $l->supplierItem?->designation ?? '',
                $l->supplierItem?->article_number ?? '',
                $l->gp?->name ?? '',
                $l->note ?? '',
                $l->received_note ?? '',
                $l->invoice_note ?? '',
                $l->claim_note ?? '',
            ]))
            ->implode(' ');

        return mb_strtolower(implode(' ', [
            'ord-'.(int) $o->id,
            '#'.(int) $o->id,
            $o->supplier?->name ?? '',
            $o->reference ?? '',
            $o->supplier_order_number ?? '',
            $o->invoice_number ?? '',
            $o->supplier_confirmation_note ?? '',
            $o->invoice_note ?? '',
            $o->payment_note ?? '',
            $o->approval_note ?? '',
            $o->note ?? '',
            collect($herkunft[(int) $o->id] ?? [])->pluck('label')->implode(' '),
            $positionen,
        ]));
    }
}
