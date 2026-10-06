{{-- Spec 32 — VK-Batch-Freigabe. Trennt intern (Live-Marge) von außen (freigegebener Preis):
     ohne Freigabe sieht der Kunde weiter den alten Stand, egal wie der EK sich bewegt hat.

     fa-pass 2026-10-05: Tokens + Bausteine. Eine Hauptaktion („Preise freigeben", rechts), Auswahl-
     Hilfen als Nebenknöpfe, beide Listen als fa-table mit Zustandsfarben, Leerzustände sagen,
     dass alles in Ordnung ist. wire:-Bindungen und data-Marker unverändert. --}}
@php
    $nAuswahl = count($auswahl);
@endphp

<div class="flex flex-col gap-4" data-ctrl-vk-freigabe>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
            Der Verkaufspreis wird intern laufend nachgerechnet, nach außen gilt nur der freigegebene Stand.
            Hier wird bewusst freigegeben, damit kein Sprung im Einkaufspreis unbemerkt beim Kunden landet.
            @if($schwelle !== null)
                Als weggelaufen zählt eine Abweichung ab {{ number_format((float) $schwelle, 1, ',', '.') }} %.
            @endif
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <x-fa::button variant="ghost" size="sm" wire:click="alleAbgedriftet" :disabled="count($abgedriftet) === 0">Alle weggelaufenen wählen</x-fa::button>
            <x-fa::button variant="ghost" size="sm" wire:click="auswahlLeeren" :disabled="$nAuswahl === 0">Auswahl leeren</x-fa::button>
            <x-fa::button variant="primary" icon="heroicon-o-check-badge" wire:click="freigeben" wire:loading.attr="disabled"
                          wire:confirm="{{ $nAuswahl }} {{ $nAuswahl === 1 ? 'Preis' : 'Preise' }} freigeben? Ab dann sieht der Kunde diesen Stand."
                          data-ctrl-vk-freigeben :disabled="$nAuswahl === 0">
                {{ $nAuswahl === 0 ? 'Preise freigeben' : ($nAuswahl === 1 ? '1 Preis freigeben' : $nAuswahl . ' Preise freigeben') }}
            </x-fa::button>
        </div>
    </div>

    @if($hinweis)<x-fa::signal tone="ok" data-ctrl-vk-hinweis>{{ $hinweis }}</x-fa::signal>@endif
    @if($fehler)<x-fa::signal tone="crit" data-ctrl-vk-fehler>{{ $fehler }}</x-fa::signal>@endif

    {{-- Weggelaufen: freigegeben, aber der Live-Preis hat sich über die Leitplanke entfernt. --}}
    <x-fa::section variant="plain" title="Weggelaufen" icon="heroicon-o-arrow-trending-up"
                   :meta="count($abgedriftet) > 0 ? count($abgedriftet) . ' offen' : null"
                   description="Freigegebene Preise, von denen die aktuelle Kalkulation deutlich abweicht.">
        @if(count($abgedriftet) === 0)
            <x-fa::signal tone="ok">Kein freigegebener Preis läuft der Kalkulation davon.</x-fa::signal>
        @else
            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">Auswahl</span></th>
                            <th>Gericht</th>
                            <th class="num">Freigegeben</th>
                            <th class="num">Aktuell</th>
                            <th class="num">Abweichung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($abgedriftet as $z)
                            <tr wire:key="vk-drift-{{ $z['presentation_id'] }}" @if(in_array($z['presentation_id'], $auswahl)) aria-selected="true" @endif>
                                <td>
                                    <input type="checkbox" wire:model.live="auswahl" value="{{ $z['presentation_id'] }}"
                                           aria-label="{{ $z['recipe_name'] }} auswählen"
                                           class="w-4 h-4 accent-[var(--fa-accent)]"
                                           data-ctrl-vk-pick="{{ $z['presentation_id'] }}" />
                                </td>
                                <td>{{ $z['recipe_name'] }}</td>
                                <td class="num text-[var(--fa-ink-2)]">
                                    <x-fa::money :value="$z['published_net']" />
                                </td>
                                <td class="num font-semibold">
                                    <x-fa::money :value="$z['live_net']" />
                                </td>
                                <td class="num">
                                    <x-fa::badge :tone="$z['richtung'] === 'erhoehen' ? 'warn' : 'info'"
                                                 :icon="$z['richtung'] === 'erhoehen' ? 'heroicon-m-arrow-up' : 'heroicon-m-arrow-down'">
                                        {{ $z['richtung'] === 'erhoehen' ? '+' : '−' }}{{ number_format((float) $z['delta_pct'], 1, ',', '.') }} %
                                    </x-fa::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>

    {{-- Erstfall: nie freigegeben. Ohne diese Liste wäre die Fläche in einem Betrieb,
         der noch nie freigegeben hat, dauerhaft leer, obwohl der ganze Katalog offen ist. --}}
    <x-fa::section variant="plain" title="Noch nie freigegeben" icon="heroicon-o-clock"
                   :meta="count($neu) > 0 ? count($neu) . ' offen' : null"
                   description="Bepreiste Gerichte, deren Verkaufspreis der Kunde noch nicht sieht.">
        @if(count($neu) === 0)
            <x-fa::signal tone="ok">Jeder bepreiste Verkaufsstand ist mindestens einmal freigegeben.</x-fa::signal>
        @else
            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">Auswahl</span></th>
                            <th>Gericht</th>
                            <th class="num">Aktueller Preis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($neu as $z)
                            <tr wire:key="vk-neu-{{ $z['presentation_id'] }}" @if(in_array($z['presentation_id'], $auswahl)) aria-selected="true" @endif>
                                <td>
                                    <input type="checkbox" wire:model.live="auswahl" value="{{ $z['presentation_id'] }}"
                                           aria-label="{{ $z['recipe_name'] }} auswählen"
                                           class="w-4 h-4 accent-[var(--fa-accent)]"
                                           data-ctrl-vk-pick="{{ $z['presentation_id'] }}" />
                                </td>
                                <td>
                                    {{ $z['recipe_name'] }}
                                    {{-- Ein Gericht hat mehrere Darreichungen mit eigenem Preis;
                                         ohne die Form sind die Zeilen nicht auseinanderzuhalten. --}}
                                    @if($z['form'])<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">· {{ $z['form'] }}</span>@endif
                                </td>
                                <td class="num font-semibold">
                                    <x-fa::money :value="$z['live_net']" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>
</div>
