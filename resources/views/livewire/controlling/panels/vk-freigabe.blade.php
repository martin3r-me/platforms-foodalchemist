{{-- Spec 32 — VK-Batch-Freigabe. Trennt intern (Live-Marge) von außen (freigegebener Preis):
     ohne Freigabe sieht der Kunde weiter den alten Stand, egal wie der EK sich bewegt hat. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' €')

<div class="space-y-3" data-ctrl-vk-freigabe>
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <p class="text-[11px] text-gray-500 max-w-2xl">
            Der Verkaufspreis wird intern laufend nachgerechnet, nach außen gilt aber nur der
            freigegebene Stand. Hier wird freigegeben — bewusst als bewusster Schritt, damit kein
            EK-Sprung still beim Kunden landet.
            @if($schwelle !== null)
                Als „weggelaufen" zählt eine Abweichung ab {{ number_format((float) $schwelle, 1, ',', '.') }} %.
            @endif
        </p>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="alleAbgedriftet" class="{{ $btnGhostXs }}" @disabled(count($abgedriftet) === 0)>Alle abgedrifteten</button>
            <button type="button" wire:click="auswahlLeeren" class="{{ $btnGhostXs }}" @disabled(count($auswahl) === 0)>Auswahl leeren</button>
            <button type="button" wire:click="freigeben" wire:loading.attr="disabled"
                    wire:confirm="{{ count($auswahl) }} Preis(e) freigeben? Ab dann sieht der Kunde diesen Stand."
                    data-ctrl-vk-freigeben
                    class="{{ $btnPrimary }}" @disabled(count($auswahl) === 0)>{{ count($auswahl) }} freigeben</button>
        </div>
    </div>

    @if($hinweis)<p class="text-[11px] text-emerald-700" data-ctrl-vk-hinweis>{{ $hinweis }}</p>@endif
    @if($fehler)<p class="text-[11px] text-rose-700" data-ctrl-vk-fehler>{{ $fehler }}</p>@endif

    {{-- Weggelaufen: freigegeben, aber der Live-Preis hat sich über die Leitplanke entfernt. --}}
    <div>
        <h4 class="{{ $label }} mb-1">Weggelaufen ({{ count($abgedriftet) }})</h4>
        @if(count($abgedriftet) === 0)
            <p class="text-xs text-gray-500">Kein freigegebener Preis läuft der Kalkulation davon.</p>
        @else
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} w-8"></th>
                            <th class="{{ $th }} text-left">Gericht</th>
                            <th class="{{ $th }} text-right">freigegeben</th>
                            <th class="{{ $th }} text-right">aktuell</th>
                            <th class="{{ $th }} text-right">Abweichung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($abgedriftet as $z)
                            <tr class="{{ $tr }}" wire:key="vk-drift-{{ $z['presentation_id'] }}">
                                <td class="{{ $td }}">
                                    <input type="checkbox" wire:model.live="auswahl" value="{{ $z['presentation_id'] }}"
                                           data-ctrl-vk-pick="{{ $z['presentation_id'] }}" />
                                </td>
                                <td class="{{ $td }} text-gray-900">{{ $z['recipe_name'] }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $eur($z['published_net']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-900">{{ $eur($z['live_net']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums">
                                    <span class="{{ $pill }} {{ $z['richtung'] === 'erhoehen' ? $variantPill['warning'] : $variantPill['info'] }}">
                                        {{ $z['richtung'] === 'erhoehen' ? '+' : '−' }}{{ number_format((float) $z['delta_pct'], 1, ',', '.') }} %
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Erstfall: nie freigegeben. Ohne diese Liste wäre die Fläche in einem Betrieb,
         der noch nie freigegeben hat, dauerhaft leer — obwohl der ganze Katalog offen ist. --}}
    <div>
        <h4 class="{{ $label }} mb-1">Noch nie freigegeben ({{ count($neu) }})</h4>
        @if(count($neu) === 0)
            <p class="text-xs text-gray-500">Jeder bepreiste Verkaufsstand ist mindestens einmal freigegeben.</p>
        @else
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} w-8"></th>
                            <th class="{{ $th }} text-left">Gericht</th>
                            <th class="{{ $th }} text-right">aktueller Preis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($neu as $z)
                            <tr class="{{ $tr }}" wire:key="vk-neu-{{ $z['presentation_id'] }}">
                                <td class="{{ $td }}">
                                    <input type="checkbox" wire:model.live="auswahl" value="{{ $z['presentation_id'] }}"
                                           data-ctrl-vk-pick="{{ $z['presentation_id'] }}" />
                                </td>
                                <td class="{{ $td }} text-gray-900">{{ $z['recipe_name'] }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-900">{{ $eur($z['live_net']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
