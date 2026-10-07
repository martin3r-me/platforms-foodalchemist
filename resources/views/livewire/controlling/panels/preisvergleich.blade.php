{{-- Einkauf E3 — Cross-Lieferanten-Preisvergleich (Such-first).
     Spec 32: von der eigenen Seite `/einkauf` zum Panel im Controlling-Tab „Preise";
     Seiten-Hülle (x-ui-page/navbar/actionbar) entfällt, Titel trägt jetzt der Tab.

     fa-pass 2026-10-05: Tokens + Bausteine. Filterzeile mit Label oben, Tabelle als fa-table,
     Lieferanten als Zustands-Badges (günstigster grün, teuerster rot), Bezug mit Symbol statt
     Emoji, Zeilen-Aktionen als klare Verb-Knöpfe. wire:-Bindungen und data-Marker unverändert. --}}
@php
    $preis = fn ($v) => $v === null ? '–' : number_format((float) $v, 2, ',', '.') . ' €';
@endphp

<div class="flex flex-col gap-4" data-ctrl-preisvergleich>
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
        Je Grundprodukt der günstigste und der teuerste Lieferant, dazu woher heute bezogen wird.
        Von hier aus lässt sich der günstigste Artikel bestellen oder dauerhaft als Bezugsquelle setzen.
    </p>

    {{-- Filterleiste --}}
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,13rem),1fr))] items-end gap-3">
        <x-fa::field label="Grundprodukt suchen" for="ctrl-pv-q">
            <x-fa::input type="search" id="ctrl-pv-q" wire:model.live.debounce.300ms="q" placeholder="z. B. Kartoffel, Rind" data-einkauf-suche />
        </x-fa::field>
        <x-fa::field label="Warengruppe" for="ctrl-pv-wg">
            <x-fa::select id="ctrl-pv-wg" wire:model.live="wgCode" placeholder="Alle Warengruppen">
                @foreach($warengruppen as $wg)
                    <option value="{{ $wg->code }}">{{ $wg->name }}</option>
                @endforeach
            </x-fa::select>
        </x-fa::field>
        <x-fa::field label="Lieferant" for="ctrl-pv-sup">
            <x-fa::select id="ctrl-pv-sup" wire:model.live="supplierId" placeholder="Alle Lieferanten">
                @foreach($lieferanten as $l)
                    <option value="{{ $l->id }}">{{ $l->name }}</option>
                @endforeach
            </x-fa::select>
        </x-fa::field>
        <div class="flex items-center h-9">
            <label class="fa-chip">
                <input type="checkbox" wire:model.live="mitRabatt" class="sr-only peer" data-einkauf-rabatt />
                <span>Mit Rückvergütung rechnen</span>
            </label>
        </div>
    </div>
    @if($mitRabatt)
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Preise als effektiver Nettopreis nach Rückvergütung (rückwirkender Jahresbonus), nicht der gebuchte Bestellpreis.
        </p>
    @endif

    @if($hinweis)<x-fa::notice tone="ok" data-einkauf-hinweis>{{ $hinweis }}</x-fa::notice>@endif
    @if($fehler)<x-fa::notice tone="crit" data-einkauf-fehler>{{ $fehler }}</x-fa::notice>@endif

    @if(! $aktiv)
        {{-- Leerzustand (Such-first) --}}
        <div class="rounded-[var(--fa-radius-surface)] border border-dashed border-[var(--fa-line-strong)]">
            <x-fa::empty icon="heroicon-o-magnifying-glass" title="Preise über alle Lieferanten vergleichen">
                Grundprodukt suchen oder nach Warengruppe oder Lieferant filtern. Je Produkt erscheinen der günstigste
                und der teuerste Lieferant mit der Preisspanne.
            </x-fa::empty>
        </div>
    @elseif(count($zeilen) === 0)
        <x-fa::empty compact icon="heroicon-o-currency-euro" title="Keine bepreisten Treffer">
            Für diese Auswahl gibt es keine Grundprodukte mit Vergleichspreis. Filter lockern oder anders suchen.
        </x-fa::empty>
    @else
        <div class="overflow-x-auto rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
            <table class="fa-table" data-einkauf-tabelle>
                <thead>
                    <tr>
                        <th>Grundprodukt</th>
                        <th>Warengruppe</th>
                        <th>Günstigster</th>
                        <th class="num">€ je Einheit</th>
                        <th>Teuerster</th>
                        <th class="num">€ je Einheit</th>
                        <th class="num">Spanne</th>
                        <th class="num">Lieferanten</th>
                        <th>Heutiger Bezug</th>
                        @if($supplierId)<th class="num">Gewählter Lieferant</th>@endif
                        <th class="num"><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($zeilen as $z)
                        <tr wire:key="einkauf-{{ $z['gp_id'] }}">
                            <td class="font-medium">{{ $z['name'] }}</td>
                            <td class="text-[var(--fa-ink-2)]">{{ $z['wg'] ?: '–' }}</td>
                            <td><x-fa::badge tone="ok">{{ $z['guenstigster_supplier'] }}</x-fa::badge></td>
                            <td class="num font-semibold text-[var(--fa-ok)]">{{ $preis($z['guenstigster_preis']) }}</td>
                            <td><x-fa::badge tone="crit">{{ $z['teuerster_supplier'] }}</x-fa::badge></td>
                            <td class="num text-[var(--fa-crit)]">{{ $preis($z['teuerster_preis']) }}</td>
                            <td class="num text-[var(--fa-ink-2)]">{{ $z['spanne_pct'] !== null ? '+' . number_format($z['spanne_pct'], 0, ',', '.') . ' %' : '–' }}</td>
                            <td class="num text-[var(--fa-ink-2)]">{{ $z['n'] }}</td>
                            {{-- Spec 32: woher wird heute wirklich bezogen? Ohne diese Spalte ist der
                                 Preisvergleich eine Marktbeobachtung ohne Bezug zur eigenen Kalkulation. --}}
                            <td>
                                @if($z['lead_supplier'] === null)
                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kein Bezug gesetzt</span>
                                @elseif($z['lead_ist_guenstigster'])
                                    <x-fa::badge tone="ok" icon="heroicon-m-check" title="Bezugsquelle ist bereits die günstigste">{{ $z['lead_supplier'] }}</x-fa::badge>
                                @else
                                    <x-fa::badge tone="warn" title="Es wird nicht vom günstigsten Lieferanten bezogen">{{ $z['lead_supplier'] }}</x-fa::badge>
                                @endif
                            </td>
                            @if($supplierId)
                                <td class="num {{ $z['filter_supplier_ist_guenstigster'] ? 'text-[var(--fa-ok)] font-semibold' : '' }}">
                                    @if($z['filter_supplier_preis'] === null)
                                        <span class="text-[var(--fa-ink-3)]">Kein Preis</span>
                                    @else
                                        <span class="inline-flex items-center gap-1">
                                            {{ $preis($z['filter_supplier_preis']) }}
                                            @if($z['filter_supplier_ist_guenstigster'])<span title="Günstigster Lieferant">@svg('heroicon-m-star', 'w-3.5 h-3.5')</span>@endif
                                        </span>
                                    @endif
                                </td>
                            @endif
                            <td class="num">
                                <div class="inline-flex items-center gap-1.5">
                                    <x-fa::button size="sm" icon="heroicon-o-shopping-cart"
                                                  wire:click="uebernehmen({{ $z['guenstigster_la_id'] }})"
                                                  title="Günstigsten Lieferantenartikel in die Bestellung übernehmen (1 Gebinde)">Bestellen</x-fa::button>
                                    {{-- Der eigentliche Controlling-Hebel: nicht einmal günstig einkaufen,
                                         sondern dauerhaft von dort beziehen. Nur zeigen, wenn es etwas ändert. --}}
                                    @unless($z['lead_ist_guenstigster'])
                                        <x-fa::button size="sm" variant="ai" icon="heroicon-o-arrows-right-left"
                                                      wire:click="bezugsquelleSetzen({{ $z['gp_id'] }}, {{ $z['guenstigster_la_id'] }})"
                                                      wire:confirm="Bezugsquelle für „{{ $z['name'] }}“ auf {{ $z['guenstigster_supplier'] }} umstellen? Der Einkaufspreis wird in allen Rezepten nachgerechnet."
                                                      wire:loading.attr="disabled"
                                                      data-ctrl-bezug="{{ $z['gp_id'] }}"
                                                      title="Grundprodukt dauerhaft von diesem Lieferanten beziehen">Bezug umstellen</x-fa::button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($gekappt)
            <x-fa::signal tone="warn">Nur die ersten {{ $max }} Treffer gezeigt. Enger filtern für den Rest.</x-fa::signal>
        @endif
    @endif
</div>
