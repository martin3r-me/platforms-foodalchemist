{{--
    Modul-Sidebar — rendert dynamisch aus config('foodalchemist.sidebar')
    (EINE Quelle; M2-13-Abnahme-Fund: die Template-Version war hartkodiert,
    Lieferanten/Einstellungen fehlten deshalb in der Navigation).
--}}
@php($gruppen = config('foodalchemist.sidebar', []))

<div>
    {{-- Globaler „Gespeichert"-Toast — einmal hier gemountet (Sidebar liegt via
         platform::layouts.app auf jeder FA-Seite, überlebt Modal-Close/wire:navigate). --}}
    <x-foodalchemist::saved-toast />

    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-xs italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Food Alchemist
    </div>

    {{-- Phase C2: der Sprach-Agent — EINMAL global gemountet. Die Sidebar liegt via
         platform::layouts.app auf jeder FA-Seite, also gibt es genau eine Modal-Identität
         (`voice-modal`); jede Seite öffnet sie mit `voice-modal.oeffnen`.

         Bewusst hier und NICHT im `x-show="!collapsed"`-Block darunter: `x-show` setzt
         display:none und würde das geöffnete Modal mit ausblenden. Kein Teleport nötig —
         `x-ui-sidebar` trägt nur `relative` (kein transform/filter), das `fixed`-Modal
         bezieht sich also auf den Viewport; `overflow-y-auto` am <nav> klippt es nicht.
         Grenze, die bleibt: `x-ui-sidebar` rendert den Modul-Slot in einem `x-if` und
         nimmt ihn beim Einklappen aus dem DOM. Knopf und Modal teilen damit dieselbe
         Sichtbarkeit — kein Loch, aber der Grund, warum es keine eingeklappte Variante
         gibt (die wäre nie gerendert, wie FAs Icon-Leiste weiter unten). --}}
    @livewire('foodalchemist.voice-modal')

    {{-- Ebene 2 (D2): aktiver Betrieb — die Preis-Dimension der ganzen FA (nur ausgeklappt). --}}
    <div x-show="!collapsed" class="px-2">
        @livewire('foodalchemist.active-outlet-bar')

        {{-- Auf der Ebene des Betriebs-Wählers, weil das Mikrofon dieselbe Reichweite hat:
             übergeordnete Steuerung für den ganzen FoodAlchemist, nicht für eine Seite. --}}
        <button type="button" wire:click="$dispatch('voice-modal.oeffnen')"
                class="w-full mb-2 px-3 py-2 rounded-md flex items-center gap-2 text-xs font-medium
                       text-[var(--ui-secondary)] border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]"
                title="Sprachbefehl — steuert den ganzen FoodAlchemist (lesend frei, Änderungen als Vorschlag)"
                data-voice-global>
            @svg('heroicon-o-microphone', 'w-4 h-4')
            <span>Sprachbefehl</span>
        </button>
    </div>

    @foreach($gruppen as $gruppe)
        <x-ui-sidebar-list :label="$gruppe['group'] ?? ''">
            @foreach($gruppe['items'] ?? [] as $item)
                <x-ui-sidebar-item :href="route($item['route'])">
                    @svg($item['icon'] ?? 'heroicon-o-cube', 'w-4 h-4 text-[var(--ui-secondary)]')
                    <span class="ml-2 text-xs">{{ $item['label'] }}</span>
                </x-ui-sidebar-item>
            @endforeach
        </x-ui-sidebar-list>
    @endforeach

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            @foreach($gruppen as $gruppe)
                @foreach($gruppe['items'] ?? [] as $item)
                    <a href="{{ route($item['route']) }}" wire:navigate title="{{ $item['label'] }}"
                       class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                        @svg($item['icon'] ?? 'heroicon-o-cube', 'w-5 h-5')
                    </a>
                @endforeach
            @endforeach
        </div>
    </div>
</div>
