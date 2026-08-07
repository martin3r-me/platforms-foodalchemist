{{--
    Spec 03 L7b-2b — Hard-Stops mit WEGEN statt mit Text.

    Vorher stand hier je Lücke ein Satz („🔴 Kalbsjus — GP anlegen · 3 Shortlist-
    Kandidaten") und sonst nichts: die Aktion war ein Wort, die Kandidaten eine
    Zahl. Beides ist längst als Datum da (`offene[].primaer`, `offene[].shortlist`
    aus `RecipeGeneratorService`) — es wurde nur nicht angeboten.

    Drei Wege, bewusst ungleich (Begründung in HardstopResolveService):
      • Basisrezept anlegen  → legt an + verknüpft (Halbfabrikat-Fall)
      • Meintest du? …       → bindet einen Bestands-Treffer aus der Shortlist
      • Lieferantenartikel wählen → noch kein Write; danach vorhandenes oder
        neues GP bewusst bestätigen. Ohne Treffer bleibt Beschaffung möglich.

    Eine Fläche für beide Generator-Modals (wie oneshot-toggle/-ergebnis/stub-offen).
--}}
@props(['offene' => [], 'prefix' => '', 'aufgeklappt' => [], 'meldung' => null])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if(count($offene ?? []) > 0)
    <div class="mt-3 space-y-2" data-{{ $prefix }}generator-offene>
        <p class="{{ $label }}">Hard-Stops (Bestand-Lücken ohne Halbfabrikat-Marker):</p>
        @foreach($offene as $offen)
            @php($idx = (int) $offen['index'])
            <div class="rounded border border-gray-200 px-2 py-1.5" data-{{ $prefix }}hardstop="{{ $idx }}">
                <p class="text-[11px] text-gray-700 inline-flex items-center gap-1.5"><span class="inline-block w-1.5 h-1.5 rounded-full bg-rose-500 align-middle"></span> {{ $offen['text'] }}</p>
                {{-- Kohärenz-Gate: WARUM diese Zeile entdrahtet wurde (Regel = süß-in-herzhaft, KI = Kritiker-Urteil). --}}
                @if(!empty($offen['kritiker']))
                    @php($k = $offen['kritiker'])
                    <p class="text-[10px] mt-0.5 text-rose-700" data-{{ $prefix }}kritiker="{{ $idx }}">
                        @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle')
                        {{ ($k['quelle'] ?? '') === 'regel' ? 'Regel' : 'Kritiker' }}: «{{ $k['name'] }}» passt fachlich nicht — {{ $k['grund'] }}
                        <span class="text-gray-400">· {{ round((float) ($k['konfidenz'] ?? 0) * 100) }} %</span>
                    </p>
                @endif
                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                    @if(($offen['primaer'] ?? null) === 'basisrezept_anlegen')
                        <button type="button" wire:click="hardstopStubAnlegen({{ $idx }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5"
                                data-{{ $prefix }}hardstop-stub="{{ $idx }}">@svg('heroicon-o-puzzle-piece', 'w-3.5 h-3.5 inline-block align-middle') Basisrezept anlegen</button>
                    @else
                        @if(count($offen['la_kandidaten'] ?? []) === 0)
                            <button type="button" wire:click="hardstopBeschaffen({{ $idx }})"
                                    class="{{ $btnGhost }} text-[11px] py-0.5"
                                    data-{{ $prefix }}hardstop-beschaffen="{{ $idx }}">@svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5 inline-block align-middle') Keine Artikel gefunden – Beschaffung anstoßen</button>
                        @endif
                        <button type="button" wire:click="hardstopStubAnlegen({{ $idx }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5"
                                data-{{ $prefix }}hardstop-stub="{{ $idx }}">@svg('heroicon-o-puzzle-piece', 'w-3.5 h-3.5 inline-block align-middle') Doch Basisrezept</button>
                    @endif
                    @if(count($offen['shortlist'] ?? []) > 0)
                        <button type="button" wire:click="toggleShortlist({{ $idx }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5"
                                data-{{ $prefix }}hardstop-shortlist="{{ $idx }}">
                            @svg('heroicon-o-question-mark-circle', 'w-3.5 h-3.5 inline-block align-middle') Meintest du? ({{ count($offen['shortlist']) }})
                        </button>
                    @endif
                    {{-- Kohärenz-Gate: „Trotzdem verwenden" bindet genau das entdrahtete Objekt wieder (Override). --}}
                    @if(!empty($offen['kritiker']) && (int) ($offen['kritiker']['ziel_id'] ?? 0) > 0)
                        <button type="button"
                                wire:click="hardstopVerknuepfen({{ $idx }}, '{{ ($offen['kritiker']['target'] ?? '') === 'sub_recipe' ? 'sub' : 'gp' }}', {{ (int) $offen['kritiker']['ziel_id'] }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5" data-{{ $prefix }}kritiker-trotzdem="{{ $idx }}">
                            @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 inline-block align-middle') Trotzdem verwenden
                        </button>
                    @endif
                    {{-- Band-Gate: der abgewiesene FuzzyLow-Kandidat — „Meintest du?" mit Verknüpfen (Override). --}}
                    @if(!empty($offen['schwacher_treffer']) && (int) ($offen['schwacher_treffer']['id'] ?? 0) > 0)
                        @php($st = $offen['schwacher_treffer'])
                        <button type="button"
                                wire:click="hardstopVerknuepfen({{ $idx }}, '{{ ($st['target'] ?? '') === 'sub_recipe' ? 'sub' : 'gp' }}', {{ (int) $st['id'] }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5" data-{{ $prefix }}schwacher-treffer="{{ $idx }}">
                            @svg('heroicon-o-question-mark-circle', 'w-3.5 h-3.5 inline-block align-middle') Meintest du «{{ $st['name'] }}»? ({{ number_format((float) ($st['score'] ?? 0), 2, ',', '.') }})
                        </button>
                    @endif
                </div>
                @if(($offen['primaer'] ?? null) !== 'basisrezept_anlegen' && count($offen['la_kandidaten'] ?? []) > 0)
                    <div class="mt-2 space-y-1" data-{{ $prefix }}hardstop-la-kandidaten="{{ $idx }}">
                        <p class="text-[10px] font-medium text-gray-600">1. Lieferantenartikel wählen</p>
                        @foreach($offen['la_kandidaten'] as $la)
                            <button type="button" wire:click="hardstopLaWaehlen({{ $idx }}, {{ (int) $la['id'] }})"
                                    class="w-full text-left text-[11px] rounded border px-1.5 py-1 {{ (int) ($offen['selected_la_id'] ?? 0) === (int) $la['id'] ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200 hover:bg-[var(--ui-muted-5)]' }}"
                                    data-{{ $prefix }}hardstop-la="{{ (int) $la['id'] }}">
                                {{ $la['designation'] }}
                                @if(($la['supplier'] ?? '') !== '')<span class="text-gray-400">· {{ $la['supplier'] }}</span>@endif
                                @if(($la['gp_name'] ?? null) !== null)<span class="{{ $pill }} {{ $variantPill['success'] }}">GP: {{ $la['gp_name'] }}</span>@endif
                            </button>
                        @endforeach
                        <button type="button" wire:click="hardstopBeschaffen({{ $idx }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5" data-{{ $prefix }}hardstop-beschaffen="{{ $idx }}">Keiner passt – Beschaffung anstoßen</button>
                    </div>
                    @if(($offen['selected_la_id'] ?? null) !== null)
                        @php($selectedLa = collect($offen['la_kandidaten'])->first(fn ($la) => (int) $la['id'] === (int) $offen['selected_la_id']))
                        <div class="mt-2 space-y-1" data-{{ $prefix }}hardstop-gp-schritt="{{ $idx }}">
                            <p class="text-[10px] font-medium text-gray-600">2. Passendes GP bestätigen</p>
                            @if(($selectedLa['gp_id'] ?? null) !== null)
                                <button type="button" wire:click="hardstopLaGpBestaetigen({{ $idx }}, {{ (int) $selectedLa['gp_id'] }})"
                                        class="{{ $btnPrimary }} text-[11px] py-0.5">Vorhandenes GP „{{ $selectedLa['gp_name'] }}“ verwenden</button>
                            @else
                                @foreach(collect($offen['shortlist'] ?? [])->where('kind', 'gp') as $gp)
                                    <button type="button" wire:click="hardstopLaGpBestaetigen({{ $idx }}, {{ (int) $gp['id'] }})"
                                            class="{{ $btnGhost }} text-[11px] py-0.5">Mit GP „{{ $gp['name'] }}“ verknüpfen</button>
                                @endforeach
                                <button type="button" wire:click="hardstopLaGpBestaetigen({{ $idx }})"
                                        class="{{ $btnPrimary }} text-[11px] py-0.5" data-{{ $prefix }}hardstop-gp-neu="{{ $idx }}">Neues GP aus gewähltem Artikel anlegen</button>
                            @endif
                        </div>
                    @endif
                @endif
                @if(($aufgeklappt[$idx] ?? false) && count($offen['shortlist'] ?? []) > 0)
                    <div class="mt-1.5 space-y-1" data-{{ $prefix }}hardstop-kandidaten="{{ $idx }}">
                        @foreach($offen['shortlist'] as $kandidat)
                            <button type="button"
                                    wire:click="hardstopVerknuepfen({{ $idx }}, '{{ $kandidat['kind'] }}', {{ (int) $kandidat['id'] }})"
                                    class="w-full text-left text-[11px] text-gray-700 hover:bg-[var(--ui-muted-5)] rounded px-1.5 py-0.5">
                                <span class="{{ $pill }} {{ $kandidat['kind'] === 'gp' ? $variantPill['success'] : $variantPill['info'] }}">{{ $kandidat['kind'] === 'gp' ? 'GP' : 'Basisrezept' }}</span>
                                {{ $kandidat['name'] }}
                                <span class="text-gray-400">· {{ number_format((float) ($kandidat['score'] ?? 0), 2, ',', '.') }}</span>
                            </button>
                        @endforeach
                        <p class="text-[10px] text-gray-500">Unter der Auto-Schwelle geblieben — die Bindung ist deine Entscheidung (wird als Override vermerkt).</p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif

@if($meldung !== null)
    <p class="text-[11px] text-emerald-700 mt-2" data-{{ $prefix }}hardstop-meldung>✓ {{ $meldung }}</p>
@endif
