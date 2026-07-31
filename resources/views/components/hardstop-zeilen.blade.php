{{--
    Spec 03 L7b-2b — Hard-Stops mit WEGEN statt mit Text.

    Vorher stand hier je Lücke ein Satz („🔴 Kalbsjus — GP anlegen · 3 Shortlist-
    Kandidaten") und sonst nichts: die Aktion war ein Wort, die Kandidaten eine
    Zahl. Beides ist längst als Datum da (`offene[].primaer`, `offene[].shortlist`
    aus `RecipeGeneratorService`) — es wurde nur nicht angeboten.

    Drei Wege, bewusst ungleich (Begründung in HardstopResolveService):
      • Basisrezept anlegen  → legt an + verknüpft (Halbfabrikat-Fall)
      • Meintest du? …       → bindet einen Bestands-Treffer aus der Shortlist
      • Beschaffung anstoßen → KEIN GP-Write (LA-First: kein GP ohne LA), nur
        Sourcing-Wunsch; die Zeile bleibt danach ehrlich offen stehen.

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
                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                    @if(($offen['primaer'] ?? null) === 'basisrezept_anlegen')
                        <button type="button" wire:click="hardstopStubAnlegen({{ $idx }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5"
                                data-{{ $prefix }}hardstop-stub="{{ $idx }}">@svg('heroicon-o-puzzle-piece', 'w-3.5 h-3.5 inline-block align-middle') Basisrezept anlegen</button>
                    @else
                        <button type="button" wire:click="hardstopBeschaffen({{ $idx }})"
                                class="{{ $btnGhost }} text-[11px] py-0.5"
                                data-{{ $prefix }}hardstop-beschaffen="{{ $idx }}">@svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5 inline-block align-middle') Beschaffung anstoßen</button>
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
                </div>
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
