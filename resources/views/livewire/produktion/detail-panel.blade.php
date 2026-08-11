{{-- Spec 18 — Produktion: Cockpit-DetailPanel (v3-Design wie Recipes/Verkauf/Concepter) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-produktion-panel>
    @if($detail === null)
        <div class="text-center text-xs text-gray-500 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Produktionsauftrag in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug">{{ $detail['name'] ?: \Illuminate\Support\Carbon::parse($detail['production_date'])->format('d.m.Y') }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    @if($detail['editierbar'])
                        <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $detail['id'] }} })" class="{{ $btnGhostXs }}" data-produktion-bearbeiten>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                    @endif
                    <span class="{{ $pill }} font-medium {{ $variantPill[\Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($detail['status'])->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $detail['status_label'] }}</span>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 mt-1.5">
                {{ \Illuminate\Support\Carbon::parse($detail['production_date'])->format('d.m.Y') }}@if($detail['reference']) · {{ $detail['reference'] }}@endif
            </p>
        </div>

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        {{-- Cockpit-KPI-Karte --}}
        <div class="relative overflow-hidden {{ $card }} px-3.5 py-2.5" data-kpi-karte>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs">
                <span class="text-gray-500">Ansätze gesamt <span class="text-gray-900 font-medium tabular-nums">{{ rtrim(rtrim(number_format($detail['ansaetze_gesamt'], 2, ',', '.'), '0'), ',') }}</span></span>
                <span class="text-gray-500">Rezepte <span class="text-gray-900 font-medium tabular-nums">{{ count($detail['zeilen']) }}</span></span>
                <span class="text-gray-500">Portionen <span class="text-gray-900 font-medium tabular-nums">{{ $detail['portionen_gesamt'] }}</span></span>
                <span class="text-gray-500">Arbeitszeit <span class="text-gray-900 font-medium tabular-nums">{{ $detail['arbeitszeit_gesamt_min'] }} min</span></span>
            </div>

            {{-- Spec 30 E6 — Fortschritt ist der Leitwert, sobald die Produktion läuft. Vorher ist
                 er strukturell 0 % (abgehakt wird erst ab „in Arbeit") und wäre nur Rauschen. --}}
            @if($detail['status'] !== 'planned' && $detail['fortschritt']['gesamt'] > 0)
                @php($fs = $detail['fortschritt'])
                <div class="mt-2" data-panel-fortschritt>
                    <div class="flex items-baseline justify-between text-[11px] mb-1">
                        <span class="text-gray-500">Fortschritt</span>
                        <span class="text-gray-700 tabular-nums">
                            {{ $fs['erledigt'] }}/{{ $fs['gesamt'] }} erledigt
                            @if($fs['uebersprungen'] > 0) · {{ $fs['uebersprungen'] }} übersprungen @endif
                        </span>
                    </div>
                    <x-foodalchemist::meter :value="$fs['prozent']" :max="100" :tone="$fs['alle_erledigt'] ? 'success' : 'info'" />
                </div>
            @endif
        </div>

        {{-- Status-Buttons: NICHT an editierbar koppeln (das ist nur „geplant") — sonst
             verschwinden „Fertig melden"/„Stornieren" sobald die Produktion läuft. Sichtbar,
             solange der Auftrag dem Team gehört und ein Wechsel erlaubt ist (Guard im Service). --}}
        @if($detail['is_owned'] && count($erlaubteStatus) > 0)
            @php($statusAktion = ['in_progress' => 'Produktion starten', 'done' => 'Fertig melden', 'cancelled' => 'Stornieren'])
            <div class="flex flex-wrap gap-1.5">
                @foreach($erlaubteStatus as $z)
                    <button type="button" wire:click="setStatus('{{ $z->value }}')"
                        class="{{ in_array($z->value, ['in_progress', 'done'], true) ? $btnPrimary : $btnGhost }}"
                        @if($z->value === 'cancelled') onclick="return confirm('Produktion stornieren?')" @endif
                        {{-- Spec 30 E6: Fertigmelden mit offenen Zeilen wird NICHT blockiert, nur
                             nachgefragt — sonst kämpft die letzte Person der Schicht gegen die
                             Software. Offene Zeilen bleiben offen, kein Auto-Abhaken. --}}
                        @if($z->value === 'done' && $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'] > 0)
                            onclick="return confirm('{{ $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'] }} Zeile(n) sind noch nicht abgehakt. Trotzdem fertig melden?')"
                            data-produktion-done-offen="{{ $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'] }}"
                        @endif
                        data-produktion-status="{{ $z->value }}">{{ $statusAktion[$z->value] ?? $z->label() }}</button>
                @endforeach
            </div>
        @endif

        {{-- Ziele: was diese Produktion abdeckt + ob es an den Einkauf übergeben wurde --}}
        @if(count($detail['targets']) > 0)
            <x-foodalchemist::section title="Ziele" icon="heroicon-o-flag" :meta="count($detail['targets'])">
                <div class="space-y-1.5">
                    @foreach($detail['targets'] as $t)
                        @php($ueb = ! empty($zielUebergaben[$t['source_ref'] ?? '']))
                        <div class="flex items-center justify-between gap-2 text-[13px]" wire:key="ziel-{{ $loop->index }}">
                            <span class="text-gray-900">{{ $t['label'] ?? '—' }}</span>
                            @if($ueb)
                                <span class="{{ $pill }} font-medium {{ $variantPill['success'] }} shrink-0" title="an Bestellung übergeben" data-ziel-uebergeben="1">✓ übergeben</span>
                            @else
                                <span class="{{ $pill }} font-medium {{ $variantPill['secondary'] }} shrink-0" title="noch nicht an Bestellung übergeben" data-ziel-uebergeben="0">–</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-foodalchemist::section>
        @endif

        <x-foodalchemist::section title="Rezepte & Ansätze" icon="heroicon-o-list-bullet" :meta="count($detail['zeilen'])">
            <div class="space-y-2">
                @foreach($detail['zeilen'] as $z)
                    <div class="border-b border-black/5 last:border-0 pb-2" wire:key="pol-{{ $z['id'] }}">
                        <div class="flex items-baseline justify-between gap-2 text-[13px]">
                            <span class="font-medium text-gray-900">{{ $z['name'] }}</span>
                            @if($z['line_status'] !== 'open')
                                <span class="{{ $pill }} {{ $variantPill[$z['line_status'] === 'done' ? 'success' : 'secondary'] }} shrink-0"
                                      data-zeile-status="{{ $z['line_status'] }}">{{ $z['line_status_label'] }}</span>
                            @endif
                            <span class="text-gray-500 tabular-nums shrink-0">
                                {{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }} Ansätze
                                @if($z['portionen'] !== null) · {{ $z['portionen'] }} Port. @endif
                                @if($z['produzierte_menge_kg'] !== null) · {{ number_format($z['produzierte_menge_kg'], 2, ',', '.') }} kg @endif
                            </span>
                        </div>
                        @if($z['zubereitung'])<p class="text-[12px] text-gray-600 mt-0.5">{{ $z['zubereitung'] }}</p>@endif
                        @if($z['darreichung'])
                            <p class="text-[11px] text-gray-500 mt-0.5">
                                @foreach($z['darreichung'] as $k => $v)<span class="mr-2">{{ $k }}: {{ $v }}</span>@endforeach
                            </p>
                        @endif
                        @if($detail['editierbar'])
                            <input type="text" value="{{ $z['note'] }}" placeholder="Küchen-Notiz …"
                                wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                class="{{ $input }} !py-1 mt-1" data-produktion-notiz="{{ $z['id'] }}" />
                        @elseif($z['note'])
                            <p class="text-[11px] text-violet-600 mt-1">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5 inline-block align-middle') {{ $z['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-foodalchemist::section>

        {{-- Einkauf: Deckungsgrad (wie viele Ziele übergeben) + verknüpfte Bestellschienen --}}
        @if(count($detail['targets']) > 0)
            @php($zieleCount = count($detail['targets']))
            @php($uebergebenCount = collect($detail['targets'])->filter(fn ($t) => ! empty($zielUebergaben[$t['source_ref'] ?? '']))->count())
            <x-foodalchemist::section title="Einkauf" icon="heroicon-o-shopping-cart" :meta="$verknuepfteOrders->count()">
                @if(! empty($detail['einkauf_veraltet']))
                    <div class="mb-2" data-einkauf-veraltet="1">
                        <x-foodalchemist::alert tone="warning">Bestellung veraltet — Ziele wurden seit der letzten Übergabe geändert. Erneut „→ An Bestellung übergeben".</x-foodalchemist::alert>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-2 text-[12px] mb-2" data-einkauf-deckung="{{ $uebergebenCount }}/{{ $zieleCount }}">
                    <span class="text-gray-500">Deckungsgrad</span>
                    <span class="flex items-center gap-1.5">
                        <a href="{{ route('foodalchemist.orders.index', ['p' => $detail['id']]) }}" class="{{ $btnGhostXs }}" data-produktion-bestellungen-kontext>Bestellungen öffnen</a>
                        <span class="{{ $pill }} font-medium {{ $uebergebenCount === 0 ? $variantPill['secondary'] : ($uebergebenCount >= $zieleCount ? $variantPill['success'] : $variantPill['warning']) }}">
                            {{ $uebergebenCount }}/{{ $zieleCount }} Ziele übergeben
                        </span>
                    </span>
                </div>
                @if($verknuepfteOrders->isNotEmpty())
                    <div class="space-y-1">
                        @foreach($verknuepfteOrders as $o)
                            <a href="{{ route('foodalchemist.orders.index', ['o' => $o->id]) }}"
                               class="flex items-center justify-between gap-2 text-[13px] px-2 py-1.5 rounded-lg bg-black/[0.02] hover:bg-black/[0.04]"
                               data-produktion-bestellung-link="{{ $o->id }}">
                                <span class="text-gray-900">{{ $o->supplier?->name ?? '—' }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="text-gray-500 tabular-nums">{{ number_format((float) $o->total_net, 2, ',', '.') }} €</span>
                                    <span class="{{ $pill }} font-medium {{ $variantPill[$o->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o->status->label() }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-[12px] text-gray-500">Noch keine Bestellung — „→ An Bestellung übergeben" unten.</p>
                @endif
            </x-foodalchemist::section>
        @endif

        {{-- Spec 30 E5: Posten-Auslastung dieses Auftrags. „Nicht zugeteilt" steht bewusst mit
             drin — unverplante Arbeit darf nicht unsichtbar sein, nur weil sie an keinem
             Posten hängt. Die Lücke bei der Arbeitszeit wird ausgewiesen statt beschönigt. --}}
        @if(count($postenSummen) > 0)
            <x-foodalchemist::section title="Posten" icon="heroicon-o-users" data-panel-posten>
                <div class="space-y-1">
                    @foreach($postenSummen as $ps)
                        <div class="flex items-baseline gap-2 text-[11px]" wire:key="pps-{{ $ps['station_id'] ?? 0 }}">
                            <span class="{{ $ps['station_id'] === null ? 'text-gray-500 italic' : 'text-gray-800' }} flex-1 min-w-0 truncate">{{ $ps['station'] }}</span>
                            <span class="tabular-nums text-gray-600 shrink-0">{{ $ps['zeilen'] }} Zeilen</span>
                            <span class="tabular-nums text-gray-900 shrink-0 w-16 text-right">{{ $ps['arbeitszeit_min'] }} min</span>
                            @if($ps['ohne_zeit'] > 0)
                                <span class="text-amber-600 shrink-0" title="Diesen Zeilen fehlt die Arbeitszeit am Rezept — die Summe ist unvollständig.">{{ $ps['ohne_zeit'] }}⚠</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-foodalchemist::section>
        @endif

        @if(count($detail['warnungen']) > 0 || count($kapazitaetsWarnungen) > 0)
            <x-foodalchemist::section title="Warnungen" icon="heroicon-o-exclamation-triangle">
                @foreach($detail['warnungen'] as $w)
                    <x-foodalchemist::alert tone="warning">{{ $w }}</x-foodalchemist::alert>
                @endforeach
                {{-- Überlast meldet sich passiv und nur für Posten mit hinterlegter Kapazität. --}}
                @foreach($kapazitaetsWarnungen as $w)
                    <x-foodalchemist::alert tone="warning" data-panel-kapazitaet>{{ $w }}</x-foodalchemist::alert>
                @endforeach
            </x-foodalchemist::section>
        @endif

        <div class="flex flex-wrap gap-2 pt-2 border-t border-black/5">
            {{-- Handover ist auch während der Produktion sinnvoll (man bestellt oft erst nach dem Start);
                 nur bei fertig/storniert ausblenden. --}}
            @if($detail['is_owned'] && in_array($detail['status'], ['planned', 'in_progress'], true))
                <button type="button" wire:click="anBestellungUebergeben" class="{{ $btnGhost }}" data-produktion-uebergeben>→ An Bestellung übergeben</button>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                <a href="{{ route('foodalchemist.produktion.auftraege.dokument', ['order' => $detail['id']]) }}" target="_blank" class="{{ $btnGhost }}" title="Gebündelte interne Doku: Produktionsschein + Einkauf (Lieferant/Gebinde/EK)">@svg('heroicon-o-printer', 'w-3.5 h-3.5 inline-block align-middle') Doku (Produktion + Einkauf)</a>
            @endif
        </div>
    @endif
</div>
