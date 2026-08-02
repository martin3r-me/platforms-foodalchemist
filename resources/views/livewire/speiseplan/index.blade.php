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
                <div class="mt-2 space-y-1">
                    @forelse($plaene as $p)
                        <button type="button" wire:key="sp-list-{{ $p->id }}" wire:click="waehle({{ $p->id }})" data-sp-zeile="{{ $p->id }}"
                            class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition-all {{ $selectedId === $p->id ? 'bg-violet-500/10 text-violet-700' : 'hover:bg-black/[0.03] text-gray-700' }}">
                            <div class="font-medium truncate">{{ $p->name }}</div>
                            <div class="text-[10px] text-gray-500">{{ $statusLabel[$p->status] ?? $p->status }} · {{ $p->cycle_weeks }} Wo. · {{ $p->entries_count }} Einträge</div>
                        </button>
                    @empty
                        <div class="px-2 py-6 text-center text-[11px] text-gray-500">Keine Pläne. Oben „+ Neuer Plan".</div>
                    @endforelse
                </div>
                <div class="pt-1">{{ $plaene->links() }}</div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechtes Detail-Panel (read-only Info) — konsistent zu Speisekarte/Foodbook --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-80" scope="activity_speiseplan" side="right" icon="heroicon-o-information-circle" :default-open="true">
            @if($plan)
                @include('foodalchemist::livewire.speiseplan.partials.detail', ['plan' => $plan])
            @else
                <div class="p-4 text-[11px] text-gray-400">Wähle links einen Plan, um Details zu sehen.</div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- Editor (Fullscreen-Dark, pro Plan) statt Master-Detail — geöffnet per speiseplan-editor.bearbeiten --}}
    <livewire:foodalchemist.speiseplan.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if(! $plan)
            <div class="relative overflow-hidden {{ $card }} p-10 text-center text-sm text-gray-500">
                <div class="{{ $cardAccent }}"></div>
                Wähle links einen Speiseplan oder lege einen neuen an.
            </div>
        @else
            {{-- Vorschau-Kopf: Aktionen. „Bearbeiten" öffnet den Fullscreen-Editor (Wochen-Matrix/Linien). --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold tracking-tight text-gray-900 truncate">{{ $plan->name }}</h1>
                    <p class="text-[11px] text-gray-500">{{ $plan->cycle_weeks }}-Wochen-Zyklus · {{ $plan->entries->count() }} Einträge</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="bearbeiten" class="{{ $btnPrimary }}" data-sp-bearbeiten>@svg('heroicon-o-pencil-square', 'w-4 h-4') Bearbeiten</button>
                    <a href="{{ route('foodalchemist.speiseplan.dokument', $plan->id) }}?mahlzeit={{ $vorschauMahlzeit }}" target="_blank" class="{{ $btnGhost }}">Aushang (Druck)</a>
                </div>
            </div>

            {{-- Aushang (Druck-Layout, read-only) --}}
            @include('foodalchemist::livewire.speiseplan.partials.vorschau', ['vorschau' => $vorschau])
        @endif
    </x-ui-page-container>
</x-ui-page>
