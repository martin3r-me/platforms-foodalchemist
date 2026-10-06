{{-- M4-14: Basisrezept-Generator — Beschreibung + Richtung → Rezept aus dem Bestand.
     fa-pass (2026-10-05): Bausteine + Tokens, Chips wie x-fa::choice (Klick-Logik togglePill bleibt),
     eine Hauptaktion unten rechts. Ablauf unverändert: Eingabe → läuft (Poll) → Ergebnis → Freigeben. --}}
@php
    $chip = 'inline-flex items-center gap-1 h-[30px] px-3 rounded-full border text-[length:var(--fa-text-md)] transition-colors';
    $chipAn = 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)] hover:border-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $feldTitel = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
@endphp

<x-foodalchemist::modal name="generator-modal" title="Basisrezept erstellen" size="max-w-2xl">
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-generator-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    @if($laeuft && $ergebnis === null)
        {{-- Async (2026-07-20): Erstellung läuft im Hintergrund, die Ansicht fragt alle 2 s nach (kein Abbruch durch Zeitlimit) --}}
        <div wire:poll.2s="pruefeErgebnis" class="py-8 flex flex-col items-center gap-3" data-generator-laeuft>
            <div class="flex items-center gap-3 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                <svg class="animate-spin h-5 w-5 text-[var(--fa-accent)]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span data-generator-fortschritt>{{ $fortschritt ?? 'Rezept wird erstellt. Das Fenster kann offen bleiben.' }}</span>
            </div>
            {{-- Phase 0 Watchdog: dauert es zu lange → sichtbarer Hinweis statt endlosem Kreisel --}}
            @if($hinweis !== null)
                <x-fa::notice tone="warn" class="w-full" data-generator-hinweis>{{ $hinweis }}</x-fa::notice>
            @endif
        </div>
    @elseif($ergebnis === null)
        <x-foodalchemist::modal-section title="Beschreibung">
            <x-fa::field for="generator-beschreibung" hint="Was soll es werden, wofür wird es verwendet? Je genauer, desto passender das Rezept.">
                <x-fa::textarea id="generator-beschreibung" wire:model="description" rows="3" data-generator-description
                    placeholder="z. B. Dunkle Rotwein-Schalotten-Reduktion als Saucenbasis für Schmorgerichte" />
            </x-fa::field>
        </x-foodalchemist::modal-section>

        {{-- R5: Richtung als Chip-Gruppen mit Erklärung zur jeweils gewählten Option --}}
        <x-foodalchemist::modal-section title="Richtung (optional)">
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-x-6 gap-y-5" data-generator-parameter>
                @foreach(\Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal::RICHTUNGEN as $g)
                    <div class="flex flex-col gap-1.5 min-w-0" data-richtung="{{ $g['field'] }}">
                        <p class="{{ $feldTitel }}">{{ $g['label'] }}</p>
                        <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="{{ $g['label'] }}">
                            @foreach($g['optionen'] as $wert => $lbl)
                                @php $an = $parameter[$g['field']] === $wert; @endphp
                                <button type="button" wire:click="togglePill('{{ $g['field'] }}', '{{ $wert }}')" role="radio" aria-checked="{{ $an ? 'true' : 'false' }}"
                                        class="{{ $chip }} {{ $an ? $chipAn : $chipAus }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        @if(($g['hint'][$parameter[$g['field']]] ?? '') !== '')
                            <p class="{{ $leise }}">{{ $g['hint'][$parameter[$g['field']]] }}</p>
                        @endif
                    </div>
                @endforeach

                <div class="min-w-0" data-richtung="aroma">
                    <x-fa::field label="Aroma-Richtung" for="generator-aroma" :hint="$parameter['aroma'] === '' ? 'Ohne Vorgabe wählt die KI passend zur Beschreibung.' : null">
                        <x-fa::input id="generator-aroma" wire:model="parameter.aroma" placeholder="frei, z. B. rauchig-karamellig, mediterran" />
                    </x-fa::field>
                </div>

                <div class="min-w-0" data-richtung="sektor">
                    <x-fa::choice name="parameter.sektor" :live="false" label="Sektor (Verpflegung)" :options="[
                        '' => 'Egal',
                        'betriebsgastronomie' => 'Betriebsgastronomie',
                        'catering' => 'Catering und Event',
                        'restaurant' => 'Restaurant',
                        'care' => 'Care und Klinik',
                        'schule_kita' => 'Schule und Kita',
                    ]" />
                </div>

                {{-- 06·H4: Favoriten-Modus nur auf Wunsch (aus = keine Einengung) --}}
                <div class="flex flex-col gap-1.5 min-w-0" data-richtung="favoriten">
                    <label class="flex items-start gap-2 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] cursor-pointer">
                        <input type="checkbox" wire:model.live="useFavoritesList" class="mt-0.5 accent-[var(--fa-accent)]" data-generator-favoriten />
                        <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-star', 'w-4 h-4 text-[var(--fa-ink-3)]') Auf Basis meiner Favoriten bauen</span>
                    </label>
                    <p class="{{ $leise }}">Bevorzugt deine Lieblings-Grundprodukte, schließt andere aber nicht aus. Aus heißt freie Auswahl.</p>
                    <label x-show="$wire.useFavoritesList" class="flex items-center gap-2 ml-6 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer">
                        <input type="checkbox" wire:model="favoritesConvenienceOnly" class="accent-[var(--fa-accent)]" data-generator-favoriten-conv /> Nur Convenience-Favoriten
                    </label>
                </div>

                {{-- Spec 03 L7b: Erstellen und Anreichern in einem Durchlauf (auf Wunsch) --}}
                <x-foodalchemist::oneshot-toggle marker="generator" schritte="Beschreibung, Kategorie, Geschmacksrichtung" />

                <div class="col-span-full flex flex-col gap-1.5" data-richtung="diaet">
                    <p class="{{ $feldTitel }}">Ernährungsform <span class="font-normal text-[var(--fa-ink-3)]">(Mehrfachauswahl, wird streng eingehalten)</span></p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                            @php $an = in_array($wert, $parameter['diaet_hart'], true); @endphp
                            <button type="button" wire:click="togglePill('diaet_hart', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                    class="{{ $chip }} {{ $an ? $chipAn : $chipAus }}">@if($an)@svg('heroicon-m-check', 'w-3.5 h-3.5')@endif{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-foodalchemist::modal-section>
    @else
        @php
            $stat = $ergebnis['statistik'] ?? [];
            $entdrahtet = (int) data_get($ergebnis, 'statistik.kritiker.entdrahtet', 0);
            $koh = $stat['kohaerenz'] ?? null;
        @endphp
        <x-foodalchemist::modal-section title="Ergebnis">
            <p class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]" data-generator-ergebnis>{{ $ergebnis['name'] }}</p>
            <div class="flex flex-wrap gap-1.5 mt-2">
                <x-fa::badge tone="ok">{{ $stat['bestand_gp'] }} {{ (int) $stat['bestand_gp'] === 1 ? 'Grundprodukt' : 'Grundprodukte' }} aus dem Bestand</x-fa::badge>
                <x-fa::badge tone="info">{{ $stat['bestand_sub'] }} {{ (int) $stat['bestand_sub'] === 1 ? 'Unterrezept' : 'Unterrezepte' }}</x-fa::badge>
                <x-fa::badge :tone="(int) $stat['stub_neu'] > 0 ? 'warn' : 'neutral'">{{ $stat['stub_neu'] }} neue {{ (int) $stat['stub_neu'] === 1 ? 'Rezept-Hülle' : 'Rezept-Hüllen' }}</x-fa::badge>
                <x-fa::badge :tone="$stat['offen'] > 0 ? 'crit' : 'neutral'">{{ $stat['offen'] }} offen</x-fa::badge>
                {{-- Kohärenz-Prüfung: gelöste unpassende Zutaten + (nur mit Abdeckung) Aroma-Zusammenhalt --}}
                @if($entdrahtet > 0)
                    <x-fa::badge tone="crit" data-generator-kritiker>{{ $entdrahtet }} unpassende {{ $entdrahtet === 1 ? 'Zutat' : 'Zutaten' }} gelöst</x-fa::badge>
                @endif
                {{-- Spec 60: Prüfung mit der Kombinationslogik (dieselbe, die den Plan gebaut hat) --}}
                @php
                    $komb = $ergebnis['statistik']['kombination'] ?? null;
                @endphp
                @if(is_array($komb) && isset($komb['zusammenfassung']))
                    <x-fa::badge data-generator-kombination>{{ $komb['zusammenfassung'] }}</x-fa::badge>
                @endif
            </div>
            <x-foodalchemist::hardstop-zeilen :offene="$ergebnis['offene']" prefix=""
                                             :aufgeklappt="$hardstopOffenIndex" :meldung="$hardstopMeldung" />
            <x-foodalchemist::stub-offen :stubs="$stat['stubs'] ?? []" />
            <x-foodalchemist::kontext-inspektor :kontext="$ergebnis['kontext'] ?? null" />
            @if($laeuft)
                <p wire:poll.2s="pruefeErgebnis" class="mt-3 inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]" data-generator-anreicherung-laeuft>
                    @svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Rezept gespeichert. Vollanreicherung läuft separat …
                </p>
            @endif
            <x-foodalchemist::oneshot-ergebnis :anreicherung="$anreicherung" />
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <x-fa::button variant="ghost" wire:click="$dispatch('modal.close', { name: 'generator-modal' })">{{ $ergebnis === null ? 'Abbrechen' : 'Schließen' }}</x-fa::button>
        {{-- Sichtbar machen, WAS rauskommt: volle Rezept-Ansicht im Editor --}}
        @if($ergebnis !== null)
            <x-fa::button icon="heroicon-o-eye" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ (int) ($ergebnis['recipe_id'] ?? 0) }} })" data-generator-ansehen>Rezept ansehen</x-fa::button>
        @endif
        @if($laeuft)
            <x-fa::button variant="primary" disabled data-generator-laeuft-btn>
                @svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') {{ $ergebnis === null ? 'Wird erstellt' : 'Wird angereichert' }} …
            </x-fa::button>
        @elseif($ergebnis === null)
            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="generieren" wire:loading.attr="disabled" data-generator-start>Rezept erstellen</x-fa::button>
        @elseif(!$freigegeben)
            @php $nOffen = count($ergebnis['offene'] ?? []); @endphp
            <x-fa::button variant="primary" icon="heroicon-o-check" wire:click="generatorFreigeben" :disabled="$nOffen > 0"
                title="{{ $nOffen > 0 ? 'Erst die offenen Zutaten oben lösen' : 'Rezept freigeben' }}" data-generator-freigeben>Rezept freigeben</x-fa::button>
        @endif
    </x-slot:footer>
</x-foodalchemist::modal>
