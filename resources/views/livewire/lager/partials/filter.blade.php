{{-- Spec 66b · Filterleiste (Bestand, Zählliste, Einrichten). Erwartet: $plaetze, $warengruppen;
     optional: $lieferanten, $mitLagerort (+ $orte), $mitStatus, $mitLadenhueter, $mitOhnePreis, $mitPlatzOrt (Platzname mit Lagerort). --}}
@php
    $aktiv = collect($filter)->filter(fn ($v) => $v !== '' && $v !== false && $v !== null)->count();
    $haken = 'w-4 h-4 rounded accent-[var(--fa-accent)]';
@endphp
<div class="flex flex-wrap items-center gap-2" data-lager-filter>
    @if(! empty($mitLagerort))
        <x-fa::select wire:model.live="lagerortId" size="sm" placeholder="Alle Lagerorte" :options="$orte->pluck('name', 'id')" class="w-48" aria-label="Lagerort" />
    @endif
    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="filter.suche" placeholder="Grundprodukt suchen" class="w-56" aria-label="Suche" />
    <x-fa::select wire:model.live="filter.stellplatz" size="sm" class="w-48" aria-label="Stellplatz" data-filter-stellplatz>
        <option value="">Alle Stellplätze</option>
        <option value="ohne">Ohne Stellplatz</option>
        @foreach($plaetze as $p)
            <option value="{{ $p->id }}">{{ ! empty($mitPlatzOrt) && $p->location ? $p->location->name . ' · ' : '' }}{{ $p->name }}</option>
        @endforeach
    </x-fa::select>
    <x-fa::select wire:model.live="filter.zustand" size="sm" class="w-36" placeholder="Jeder Zustand" :options="['frisch' => 'frisch', 'TK' => 'TK', 'trocken' => 'trocken', 'konserviert' => 'konserviert']" aria-label="Zustand" />
    <x-fa::select wire:model.live="filter.warengruppe" size="sm" class="w-52" placeholder="Alle Warengruppen" :options="$warengruppen" aria-label="Warengruppe" />
    @if(! empty($lieferanten))
        <x-fa::select wire:model.live="filter.lieferant" size="sm" class="w-48" placeholder="Alle Lieferanten" :options="$lieferanten" aria-label="Lieferant" />
    @endif
    @if(! empty($mitStatus))
        <x-fa::select wire:model.live="filter.status" size="sm" class="w-44" placeholder="Alle Positionen" :options="['offen' => 'Noch nicht gezählt', 'gezaehlt' => 'Gezählt', 'differenz' => 'Differenz über 10 %']" aria-label="Status" data-filter-status />
    @endif
    @if(! empty($mitOhnePreis))
        <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]"><input type="checkbox" wire:model.live="filter.ohne_preis" class="{{ $haken }}" /> Ohne Preis</label>
    @endif
    @if(! empty($mitLadenhueter))
        <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="Seit 90 Tagen keine Lagerbewegung"><input type="checkbox" wire:model.live="filter.ladenhueter" class="{{ $haken }}" /> Ladenhüter</label>
    @endif
    @if($aktiv > 0)
        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="filterZuruecksetzen">Filter zurücksetzen</x-fa::button>
    @endif
</div>
