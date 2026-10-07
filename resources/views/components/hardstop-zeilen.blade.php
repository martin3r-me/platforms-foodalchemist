{{--
    Spec 03 L7b-2b — offene Zutaten mit WEGEN statt mit Text.

    Vorher stand hier je Lücke ein Satz („Kalbsjus — GP anlegen · 3 Kandidaten") und sonst
    nichts. Die Aktion und die Kandidaten sind längst als Datum da (`offene[].primaer`,
    `offene[].shortlist` aus `RecipeGeneratorService`) — hier werden sie angeboten.

    Drei Wege, bewusst ungleich (Begründung in HardstopResolveService):
      • Basisrezept anlegen  → legt an + verknüpft (Halbfabrikat-Fall)
      • Meintest du? …       → bindet einen Bestands-Treffer aus der Shortlist
      • Lieferantenartikel wählen → noch kein Write; danach vorhandenes oder
        neues GP bewusst bestätigen. Ohne Treffer bleibt Beschaffung möglich.

    Eine Fläche für beide Generator-Modals (wie oneshot-toggle/-ergebnis/stub-offen).
    fa-pass (2026-10-05): Tokens + Bausteine; Props, wire:click und data-Marker unverändert.
--}}
@props(['offene' => [], 'prefix' => '', 'aufgeklappt' => [], 'meldung' => null])
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $schrittTitel = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    // Knöpfe mit dynamischem Marker-Namen (data-<prefix>…) gehen nicht über x-fa::button (Blade kann
    // keine berechneten Attributnamen an Komponenten) — gleiche Optik wie x-fa::button size=sm.
    $knopfBasis = 'inline-flex items-center justify-center gap-1.5 h-7 px-2.5 whitespace-nowrap font-medium text-[length:var(--fa-text-sm)] rounded-[var(--fa-radius-control)] transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none';
    $knopf = $knopfBasis . ' bg-[var(--fa-surface)] text-[var(--fa-ink)] border border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)]';
    $knopfLeise = $knopfBasis . ' bg-transparent text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    $wahlKnopf = 'flex w-full flex-wrap items-center gap-1.5 rounded-[var(--fa-radius-control)] border px-2.5 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] transition-colors';
@endphp

@if(count($offene ?? []) > 0)
    <div class="mt-3 flex flex-col gap-2" data-{{ $prefix }}generator-offene>
        <p class="flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
            @svg('heroicon-o-exclamation-circle', 'w-[18px] h-[18px] text-[var(--fa-crit)]')
            Offene Zutaten
            <span class="font-normal {{ $leise }}">nicht im Bestand, vor dem Freigeben lösen</span>
        </p>
        @foreach($offene as $offen)
            @php
                $idx = (int) ($offen['index'] ?? -1);
            @endphp
            {{-- Spec 41 FIX-4-(A): Dubletten-Treffer (rezept-/gericht-weit) als eigene Info-Karte —
                 „existiert bereits als X" (nie stilles Duplizieren, DF-1). Bewusst OHNE Knöpfe
                 (keine Bestandslücke); der Mensch entscheidet von Hand. --}}
            @if(!empty($offen['dedup_kollision']))
                @php
                    $dk = $offen['dedup_kollision'];
                @endphp
                <div wire:key="{{ $prefix }}dedup-{{ $idx }}" class="rounded-[var(--fa-radius-control)] bg-[var(--fa-warn-soft)] px-3 py-2" data-{{ $prefix }}dedup="{{ (int) ($dk['existing_id'] ?? 0) }}">
                    <p class="flex items-start gap-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                        @svg('heroicon-m-document-duplicate', 'w-4 h-4 mt-0.5 shrink-0 text-[var(--fa-warn)]')
                        <span>«{{ $offen['text'] }}»: {{ $dk['hinweis'] ?? 'existiert bereits im Bestand' }}</span>
                    </p>
                </div>
                @continue
            @endif
            <div wire:key="{{ $prefix }}hardstop-{{ $idx }}" class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2.5" data-{{ $prefix }}hardstop="{{ $idx }}">
                <p class="flex items-center gap-2 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]"><span class="inline-block w-2 h-2 shrink-0 rounded-full bg-[var(--fa-crit)]" aria-hidden="true"></span>{{ $offen['text'] }}</p>
                {{-- Kohärenz-Prüfung: WARUM diese Zeile gelöst wurde (Regel = süß in herzhaft, KI = Urteil der Prüfung). --}}
                @if(!empty($offen['kritiker']))
                    @php
                        $k = $offen['kritiker'];
                    @endphp
                    <p class="flex items-start gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-crit)]" data-{{ $prefix }}kritiker="{{ $idx }}">@svg('heroicon-m-exclamation-circle', 'w-4 h-4 mt-px shrink-0')
                        <span>{{ ($k['quelle'] ?? '') === 'regel' ? 'Regel' : 'Prüfung' }}: «{{ $k['name'] }}» passt fachlich nicht. {{ $k['grund'] }}
                            <span class="font-normal text-[var(--fa-ink-3)] tabular-nums">· {{ round((float) ($k['konfidenz'] ?? 0) * 100) }} % sicher</span></span>
                    </p>
                @endif
                {{-- Spec 41 FIX-5: Garnitur ohne Treffer im Katalog → Namen prüfen (evtl. erfunden, vgl. »Adji Kresse«). --}}
                @if(!empty($offen['namens_warnung']))
                    <p class="flex items-start gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-warn)]" data-{{ $prefix }}namenswarnung="{{ $idx }}">@svg('heroicon-m-exclamation-triangle', 'w-4 h-4 mt-px shrink-0')<span>Name nicht im Grundprodukt-Katalog, bitte prüfen. Die Bezeichnung ist eventuell erfunden.</span></p>
                @endif
                <div class="flex flex-wrap items-center gap-1.5">
                    @if(($offen['primaer'] ?? null) === 'basisrezept_anlegen')
                        <button type="button" class="{{ $knopf }}" wire:click="hardstopStubAnlegen({{ $idx }})" data-{{ $prefix }}hardstop-stub="{{ $idx }}">@svg('heroicon-o-puzzle-piece', 'w-3.5 h-3.5 shrink-0')Basisrezept anlegen</button>
                    @else
                        @if(count($offen['la_kandidaten'] ?? []) === 0)
                            <button type="button" class="{{ $knopf }}" wire:click="hardstopBeschaffen({{ $idx }})" title="Kein Lieferantenartikel gefunden" data-{{ $prefix }}hardstop-beschaffen="{{ $idx }}">@svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5 shrink-0')Keine Artikel gefunden: Beschaffung anstoßen</button>
                        @endif
                        <button type="button" class="{{ $knopfLeise }}" wire:click="hardstopStubAnlegen({{ $idx }})" title="Statt eines Artikels ein eigenes Basisrezept anlegen" data-{{ $prefix }}hardstop-stub="{{ $idx }}">@svg('heroicon-o-puzzle-piece', 'w-3.5 h-3.5 shrink-0')Doch Basisrezept</button>
                    @endif
                    @if(count($offen['shortlist'] ?? []) > 0)
                        <button type="button" class="{{ $knopfLeise }}" wire:click="toggleShortlist({{ $idx }})" data-{{ $prefix }}hardstop-shortlist="{{ $idx }}">@svg('heroicon-o-question-mark-circle', 'w-3.5 h-3.5 shrink-0')Meintest du? ({{ count($offen['shortlist']) }})@svg(($aufgeklappt[$idx] ?? false) ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down', 'w-3.5 h-3.5 shrink-0')</button>
                    @endif
                    {{-- Kohärenz-Prüfung: „Trotzdem verwenden" bindet genau das gelöste Objekt wieder (Ausnahme). --}}
                    @if(!empty($offen['kritiker']) && (int) ($offen['kritiker']['ziel_id'] ?? 0) > 0)
                        <button type="button" class="{{ $knopfLeise }}" wire:click="hardstopVerknuepfen({{ $idx }}, '{{ ($offen['kritiker']['target'] ?? '') === 'sub_recipe' ? 'sub' : 'gp' }}', {{ (int) $offen['kritiker']['ziel_id'] }})" data-{{ $prefix }}kritiker-trotzdem="{{ $idx }}">@svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 shrink-0')Trotzdem verwenden</button>
                    @endif
                    {{-- Schwacher Treffer unter der Schwelle — „Meintest du?" mit Verknüpfen (Ausnahme). --}}
                    @if(!empty($offen['schwacher_treffer']) && (int) ($offen['schwacher_treffer']['id'] ?? 0) > 0)
                        @php
                            $st = $offen['schwacher_treffer'];
                        @endphp
                        <button type="button" class="{{ $knopfLeise }}" wire:click="hardstopVerknuepfen({{ $idx }}, '{{ ($st['target'] ?? '') === 'sub_recipe' ? 'sub' : 'gp' }}', {{ (int) $st['id'] }})" title="Übereinstimmung {{ round((float) ($st['score'] ?? 0) * 100) }} %" data-{{ $prefix }}schwacher-treffer="{{ $idx }}">@svg('heroicon-o-question-mark-circle', 'w-3.5 h-3.5 shrink-0')Meintest du «{{ $st['name'] }}»?</button>
                    @endif
                </div>
                @if(($offen['primaer'] ?? null) !== 'basisrezept_anlegen' && count($offen['la_kandidaten'] ?? []) > 0)
                    <div class="flex flex-col gap-1 pt-1" data-{{ $prefix }}hardstop-la-kandidaten="{{ $idx }}">
                        <p class="{{ $schrittTitel }}">1. Lieferantenartikel wählen</p>
                        @foreach($offen['la_kandidaten'] as $la)
                            @php
                                $gewaehlt = (int) ($offen['selected_la_id'] ?? 0) === (int) $la['id'];
                            @endphp
                            <button type="button" wire:click="hardstopLaWaehlen({{ $idx }}, {{ (int) $la['id'] }})" aria-pressed="{{ $gewaehlt ? 'true' : 'false' }}"
                                    class="{{ $wahlKnopf }} {{ $gewaehlt ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}"
                                    data-{{ $prefix }}hardstop-la="{{ (int) $la['id'] }}">
                                @if($gewaehlt)@svg('heroicon-m-check-circle', 'w-4 h-4 shrink-0 text-[var(--fa-accent)]')@endif
                                <span class="min-w-0">{{ $la['designation'] }}</span>
                                @if(($la['supplier'] ?? '') !== '')<span class="text-[var(--fa-ink-3)]">· {{ $la['supplier'] }}</span>@endif
                                @if(($la['gp_name'] ?? null) !== null)<x-fa::badge tone="ok">Grundprodukt: {{ $la['gp_name'] }}</x-fa::badge>@endif
                            </button>
                        @endforeach
                        <div>
                            <button type="button" class="{{ $knopfLeise }}" wire:click="hardstopBeschaffen({{ $idx }})" data-{{ $prefix }}hardstop-beschaffen="{{ $idx }}">@svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5 shrink-0')Keiner passt: Beschaffung anstoßen</button>
                        </div>
                    </div>
                    @if(($offen['selected_la_id'] ?? null) !== null)
                        @php
                            $selectedLa = collect($offen['la_kandidaten'])->first(fn ($la) => (int) $la['id'] === (int) $offen['selected_la_id']);
                        @endphp
                        <div class="flex flex-col gap-1 pt-1" data-{{ $prefix }}hardstop-gp-schritt="{{ $idx }}">
                            <p class="{{ $schrittTitel }}">2. Passendes Grundprodukt bestätigen</p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if(($selectedLa['gp_id'] ?? null) !== null)
                                    <x-fa::button size="sm" icon="heroicon-o-check" wire:click="hardstopLaGpBestaetigen({{ $idx }}, {{ (int) $selectedLa['gp_id'] }})">Vorhandenes Grundprodukt „{{ $selectedLa['gp_name'] }}“ verwenden</x-fa::button>
                                @else
                                    @foreach(collect($offen['shortlist'] ?? [])->where('kind', 'gp') as $gp)
                                        <x-fa::button size="sm" wire:click="hardstopLaGpBestaetigen({{ $idx }}, {{ (int) $gp['id'] }})">Mit Grundprodukt „{{ $gp['name'] }}“ verknüpfen</x-fa::button>
                                    @endforeach
                                    <button type="button" class="{{ $knopf }}" wire:click="hardstopLaGpBestaetigen({{ $idx }})" data-{{ $prefix }}hardstop-gp-neu="{{ $idx }}">@svg('heroicon-o-plus', 'w-3.5 h-3.5 shrink-0')Neues Grundprodukt aus gewähltem Artikel anlegen</button>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
                @if(($aufgeklappt[$idx] ?? false) && count($offen['shortlist'] ?? []) > 0)
                    <div class="flex flex-col gap-1 pt-1" data-{{ $prefix }}hardstop-kandidaten="{{ $idx }}">
                        @foreach($offen['shortlist'] as $kandidat)
                            <button type="button"
                                    wire:click="hardstopVerknuepfen({{ $idx }}, '{{ $kandidat['kind'] }}', {{ (int) $kandidat['id'] }})"
                                    class="{{ $wahlKnopf }} border-transparent hover:bg-[var(--fa-hover)]">
                                <x-fa::badge :tone="$kandidat['kind'] === 'gp' ? 'ok' : 'info'">{{ $kandidat['kind'] === 'gp' ? 'Grundprodukt' : 'Basisrezept' }}</x-fa::badge>
                                <span class="min-w-0">{{ $kandidat['name'] }}</span>
                                <span class="ml-auto text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]">{{ round((float) ($kandidat['score'] ?? 0) * 100) }} %</span>
                            </button>
                        @endforeach
                        <p class="{{ $leise }}">Unter der Schwelle für automatisches Verknüpfen geblieben. Die Zuordnung ist deine Entscheidung und wird als Ausnahme vermerkt.</p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif

@if($meldung !== null)
    <p class="mt-2 flex items-start gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ok)]" data-{{ $prefix }}hardstop-meldung>@svg('heroicon-m-check-circle', 'w-4 h-4 mt-px shrink-0')<span>{{ $meldung }}</span></p>
@endif
