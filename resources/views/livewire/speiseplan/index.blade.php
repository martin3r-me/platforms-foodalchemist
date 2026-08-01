{{-- Speiseplan-Browser (Spec 29 / Editor-Rollout) — Übersichts-Liste; Planen im Fullscreen-Editor.
     Zeilen-Klick / „+ Neuer Plan" öffnen den Editor per speiseplan-editor.bearbeiten. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'])
@php($statusVariant = ['draft' => 'secondary', 'active' => 'success', 'archiviert' => 'secondary'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Speiseplan" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Speiseplan'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Speisepläne" width="w-72">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Plan suchen …" class="{{ $input }}" />
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center" data-sp-neu>+ Neuer Plan</button>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Editor (Fullscreen-Dark, pro Plan) statt Master-Detail — geöffnet per speiseplan-editor.bearbeiten --}}
    <livewire:foodalchemist.speiseplan.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="max-h-[70vh] overflow-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} w-full text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                            <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Status</th>
                            <th class="{{ $th }} text-right sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Zyklus</th>
                            <th class="{{ $th }} text-right sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Einträge</th>
                            <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Start</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($plaene as $p)
                            <x-foodalchemist::table-row wire:key="sp-{{ $p->id }}" wire:click="waehle({{ $p->id }})" data-sp-zeile="{{ $p->id }}">
                                <td class="{{ $td }} font-medium text-gray-900">{{ $p->name }}</td>
                                <td class="{{ $td }}">
                                    <span class="{{ $pill }} {{ $variantPill[$statusVariant[$p->status] ?? 'secondary'] }}">{{ $statusLabel[$p->status] ?? $p->status }}</span>
                                </td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-600">{{ $p->cycle_weeks }} Wo.</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-600">{{ $p->entries_count }}</td>
                                <td class="{{ $td }} text-gray-600">{{ $p->start_date ? $p->start_date->format('d.m.Y') : '—' }}</td>
                            </x-foodalchemist::table-row>
                        @empty
                            <tr wire:key="sp-empty"><td colspan="5" class="px-3 py-10 text-center text-sm text-gray-500">Keine Speisepläne. Links „+ Neuer Plan".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div>{{ $plaene->links() }}</div>
    </x-ui-page-container>
</x-ui-page>
