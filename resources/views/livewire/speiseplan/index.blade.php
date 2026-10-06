{{-- Speiseplan-Browser (Spec 29 / Editor-Rollout) — fa-pass (2026-10-05), Bausteine <x-fa::…>.
     Liste links, Aushang-Vorschau in der Mitte, Info rechts. Geplant wird im Vollbild-Editor:
     Zeilen-Klick wählt, „Plan bearbeiten" / „Neuer Plan" öffnen ihn per speiseplan-editor.bearbeiten.
     wire:-Bindungen und data-Marker unverändert. --}}
@php
    $statusTon = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'info' => 'info'];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Speiseplan" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Speiseplan'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Speisepläne" width="w-72">
            <div class="p-3 flex flex-col gap-3">
                <div class="relative">
                    <label for="sp-suche" class="sr-only">Speisepläne durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="sp-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Plan suchen" class="pl-8" />
                </div>
                <x-fa::button variant="primary" icon="heroicon-m-plus" class="w-full" wire:click="neu" data-sp-neu>Neuer Plan</x-fa::button>
                <div class="flex flex-col gap-0.5">
                    @forelse($plaene as $p)
                        <button type="button" wire:key="sp-list-{{ $p->id }}" wire:click="waehle({{ $p->id }})" data-sp-zeile="{{ $p->id }}"
                                @if($selectedId === $p->id) aria-current="true" @endif
                                class="w-full text-left px-2.5 py-2 rounded-[var(--fa-radius-control)] transition-colors {{ $selectedId === $p->id ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}">
                            <span class="block text-[length:var(--fa-text-md)] font-medium truncate">{{ $p->name }}</span>
                            <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $p->statusWert()->label() }} · {{ $p->cycle_weeks }} {{ $p->cycle_weeks == 1 ? 'Woche' : 'Wochen' }} · {{ number_format($p->entries_count, 0, ',', '.') }} {{ $p->entries_count == 1 ? 'Eintrag' : 'Einträge' }}</span>
                        </button>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-calendar-days" title="Keine Pläne">{{ $search !== '' ? 'Kein Plan passt zur Suche.' : 'Mit „Neuer Plan" den ersten anlegen.' }}</x-fa::empty>
                    @endforelse
                </div>
                <div class="pt-1">{{ $plaene->links('foodalchemist::components.fa.pagination') }}</div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechtes Detail-Panel (nur lesend) — konsistent zu Speisekarte/Foodbook --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-80" scope="activity_speiseplan" side="right" icon="heroicon-o-information-circle" :default-open="true">
            @if($plan)
                @include('foodalchemist::livewire.speiseplan.partials.detail', ['plan' => $plan])
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-calendar-days" title="Kein Plan gewählt">Links einen Plan anklicken, dann erscheinen hier Einträge, Linien und offene Punkte.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- Editor (Vollbild, Werkbank-Modus, pro Plan) — geöffnet per speiseplan-editor.bearbeiten --}}
    <livewire:foodalchemist.speiseplan.editor />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        @if(! $plan)
            <section class="fa-surface">
                <x-fa::empty icon="heroicon-o-calendar-days" title="Kein Speiseplan gewählt">Links einen Speiseplan wählen oder mit „Neuer Plan" einen anlegen.</x-fa::empty>
            </section>
        @else
            <x-fa::page-header :title="$plan->name" :subtitle="$plan->cycle_weeks . '-Wochen-Zyklus · ' . number_format($plan->entries->count(), 0, ',', '.') . ' ' . ($plan->entries->count() == 1 ? 'Eintrag' : 'Einträge')">
                <x-slot:actions>
                    <x-fa::badge :tone="$statusTon[$plan->statusWert()->badgeVariant()] ?? 'neutral'">{{ $plan->statusWert()->label() }}</x-fa::badge>
                    <x-fa::button icon="heroicon-m-printer" :href="route('foodalchemist.speiseplan.dokument', $plan->id) . '?mahlzeit=' . $vorschauMahlzeit" target="_blank">Aushang drucken</x-fa::button>
                    <x-fa::button icon="heroicon-m-document-duplicate" wire:click="duplizieren" wire:confirm="Diesen Speiseplan mit allen Linien und Zellen als Kopie (Entwurf) anlegen?" data-sp-duplizieren>Plan duplizieren</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-m-pencil-square" wire:click="bearbeiten" data-sp-bearbeiten>Plan bearbeiten</x-fa::button>
                </x-slot:actions>
            </x-fa::page-header>

            {{-- Aushang (Druck-Layout, nur lesend) --}}
            @include('foodalchemist::livewire.speiseplan.partials.vorschau', ['vorschau' => $vorschau])
        @endif
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
