{{-- Spec 94 · Konfiguratoren: Liste, Zeilenklick öffnet den Konfigurator-Editor (Vollbild, wie im Angebot). --}}
@php
    $statusTon = ['entwurf' => 'neutral', 'live' => 'ok', 'archiv' => 'neutral'];
    $chip = 'inline-flex items-center h-7 px-3 rounded-full text-[length:var(--fa-text-sm)] font-medium border transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border-[var(--fa-accent-line)]';
    $chipAus = 'bg-[var(--fa-surface)] text-[var(--fa-ink-2)] border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Konfiguratoren" icon="heroicon-o-adjustments-horizontal" />
    </x-slot:navbar>

    <livewire:foodalchemist.angebote.konfigurator-editor key="konfiguratoren--editor" />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Konfiguratoren" subtitle="All-in-Preise je Concept und Pax-Paket als Kundenlink: Speisen aus dem Concepter, Personal, Equipment und Logistik aus den Leistungs-Vorlagen.">
            <x-slot:actions>
                <x-fa::button variant="ghost" icon="heroicon-m-wrench-screwdriver" :href="route('foodalchemist.angebote.leistung_vorlagen')">Leistungs-Vorlagen</x-fa::button>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu" data-konfigurator-neu>Neuer Konfigurator</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif

        <div class="flex flex-wrap items-center gap-3">
            <x-fa::input type="search" wire:model.live.debounce.300ms="suche" placeholder="Konfigurator suchen" class="w-full sm:w-80" aria-label="Konfigurator suchen" />
            <div role="group" aria-label="Status" class="flex flex-wrap items-center gap-1.5">
                <button type="button" wire:click="$set('statusFilter', '')" aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}" class="{{ $chip }} {{ $statusFilter === '' ? $chipAn : $chipAus }}">Alle</button>
                @foreach($statusWerte as $wert => $label)
                    <button type="button" wire:key="ks-{{ $wert }}" wire:click="$set('statusFilter', '{{ $wert }}')" aria-pressed="{{ $statusFilter === $wert ? 'true' : 'false' }}" class="{{ $chip }} {{ $statusFilter === $wert ? $chipAn : $chipAus }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="fa-surface overflow-hidden" data-konfigurator-tabelle>
            <table class="fa-table">
                <thead><tr><th class="w-full">Name</th><th>Status</th><th>Ort</th><th class="num">Konzepte</th><th class="num">Pakete</th><th>Gültig bis</th><th>Link</th></tr></thead>
                <tbody>
                    @forelse($liste as $k)
                        <x-foodalchemist::table-row :active="$selectedId === $k->id" wire:key="kf-{{ $k->id }}" wire:click="waehle({{ $k->id }})" data-konfigurator-zeile="{{ $k->id }}">
                            <td class="font-medium">{{ $k->name }}@if($k->kunden_titel && $k->kunden_titel !== $k->name)<div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $k->kunden_titel }}</div>@endif</td>
                            <td><x-fa::badge :tone="$statusTon[$k->status] ?? 'neutral'">{{ $statusWerte[$k->status] ?? $k->status }}</x-fa::badge></td>
                            <td class="text-[var(--fa-ink-2)]">{{ $k->ort ?: '–' }}</td>
                            <td class="num">{{ $k->haupt_count }}</td>
                            <td class="num">{{ $k->pakete_count }}</td>
                            <td class="tabular-nums text-[var(--fa-ink-2)]">{{ $k->gueltig_bis?->format('d.m.Y') ?? '–' }}</td>
                            <td>@if($k->presentation_enabled)<x-fa::badge tone="ok">Live</x-fa::badge>@else<span class="text-[var(--fa-ink-3)]">–</span>@endif</td>
                        </x-foodalchemist::table-row>
                    @empty
                        <tr><td colspan="7"><x-fa::empty icon="heroicon-o-adjustments-horizontal" title="Noch kein Konfigurator">Lege einen Konfigurator an, verknüpfe Concepts und übernimm Leistungen aus den Vorlagen.</x-fa::empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui-page-container>
</x-ui-page>
