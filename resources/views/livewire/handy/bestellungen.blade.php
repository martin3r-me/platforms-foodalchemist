{{-- #1003 PR5 · Bestellungen am Handy (Concerns\HandyBestellungenLesen): Karten je Bestellung nach Liefertag, Suche und Zeitraum;
     Antippen öffnet das vorhandene Detail (Orders\DetailPanel) über die ganze Breite. Nur lesen: keine Runden, kein Versand,
     kein Auslösen, kein Stornieren, kein Editor. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zeitraeume = ['' => 'Alle', 'heute' => 'Heute', 'woche' => 'Diese Woche', 'naechste' => 'Nächste Woche'];
    $statusTon = ['draft' => 'neutral', 'sent' => 'info', 'confirmed' => 'accent', 'delivered' => 'ok', 'cancelled' => 'neutral'];
    $liste = 'flex flex-col rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]';
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Bestellungen" icon="heroicon-o-shopping-cart" />
    </x-slot:navbar>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Bestellung" width="w-96" :maxWidth="760" scope="activity_orders" side="right" :auswahl="(string) $selectedOrderId">
            <livewire:foodalchemist.orders.detail-panel :order-id="$selectedOrderId" :key="'order-detail-'.($selectedOrderId ?? 'empty')" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 h-full overflow-y-auto px-4 py-4 space-y-3" data-fa-handy-bestellungen>
        <div class="flex items-baseline justify-between gap-3">
            <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">Bestellungen</h1>
            <span class="{{ $leise }} tabular-nums">{{ $anzahl }} · {{ number_format($summe, 2, ',', '.') }} € netto</span>
        </div>
        <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Bestellung oder Lieferant suchen" aria-label="Bestellungen durchsuchen"
               class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>
        <div class="flex flex-wrap gap-1.5" data-fa-handy-zeitraum>
            @foreach($zeitraeume as $key => $lbl)
                <button type="button" wire:click="waehleZeitraum('{{ $key }}')" wire:key="hz-{{ $key }}" aria-pressed="{{ $zeitraum === $key ? 'true' : 'false' }}"
                        class="inline-flex items-center min-h-11 px-3.5 rounded-full border text-[length:var(--fa-text-md)] font-medium {{ $zeitraum === $key ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]' : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)]' }}">{{ $lbl }}</button>
            @endforeach
        </div>

        @forelse($gruppen as $tag => $g)
            <section class="space-y-1.5" wire:key="hbg-{{ $tag ?: 'ohne' }}">
                <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink-2)]">{{ $g['label'] }}</h2>
                <ul class="{{ $liste }}">
                    @foreach($g['bestellungen'] as $o)
                        @php $status = $o->status instanceof \Platform\FoodAlchemist\Enums\OrderStatus ? $o->status : \Platform\FoodAlchemist\Enums\OrderStatus::from((string) $o->status); @endphp
                        <li wire:key="hb-{{ $o->id }}">
                            <button type="button" wire:click="handyOeffnen({{ $o->id }})" x-on:click="$store.ui?.mSet('activity_orders', 'open', true)"
                                    class="flex w-full items-center gap-3 px-4 py-3 min-h-[60px] text-left {{ $selectedOrderId === $o->id ? 'bg-[var(--fa-accent-soft)]' : '' }}" data-fa-handy-karte="{{ $o->id }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $o->supplier?->name ?? 'Ohne Lieferant' }}</span>
                                    <span class="block truncate {{ $leise }}">ord-{{ $o->id }} · {{ $o->lines->count() }} {{ $o->lines->count() === 1 ? 'Position' : 'Positionen' }}</span>
                                </span>
                                <span class="shrink-0 flex flex-col items-end gap-1">
                                    <span class="text-[length:var(--fa-text-md)] font-medium tabular-nums text-[var(--fa-ink-2)]">{{ number_format((float) $o->total_net, 2, ',', '.') }} €</span>
                                    <x-fa::badge :tone="$statusTon[$status->value] ?? 'neutral'">{{ ucfirst($status->label()) }}</x-fa::badge>
                                </span>
                                @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <x-fa::empty compact icon="heroicon-o-shopping-cart" title="Keine Bestellungen">Anderen Zeitraum oder andere Suche versuchen.</x-fa::empty>
        @endforelse
    </div>
</x-ui-page>
