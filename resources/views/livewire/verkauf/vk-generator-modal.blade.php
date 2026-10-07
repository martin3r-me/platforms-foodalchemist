{{-- M6-06: Gericht-Generator (VK-Generator v1) — Beschreibung + Rahmen + Richtung, Bestand zuerst.
     fa-pass: nur --fa-*-Tokens und x-fa-Bausteine (hell UND Werkbank-Modus stimmen).

     Häufigste Arbeit: eine Beschreibung tippen und generieren. Deshalb steht die Beschreibung oben,
     danach der Rahmen des Einsatzes (Anlass, Service, Ziel-VK), zuletzt die feinere Richtung.
     Eine Hauptaktion im Fuß: Generieren → (läuft) → Gericht freigeben. --}}
@php
    $chip = 'inline-flex items-center h-7 px-3 rounded-full border text-[length:var(--fa-text-md)] whitespace-nowrap transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:border-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]';
    $gruppe = 'mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $hilfe = 'mt-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $offene = count($ergebnis['offene'] ?? []);
@endphp

<x-foodalchemist::modal name="vk-generator-modal" title="Gericht generieren" size="max-w-2xl">
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-vk-generator-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    @if($laeuft && $ergebnis === null)
        {{-- L7b: Generierung läuft im Queue-Job, UI pollt das Ergebnis (kein Web-Timeout/502) --}}
        <div wire:poll.2s="pruefeErgebnis" class="py-10 flex flex-col items-center gap-3" data-vk-generator-laeuft>
            <span class="h-8 w-8 rounded-full border-2 border-[var(--fa-accent-line)] border-t-[var(--fa-accent)] animate-spin" aria-hidden="true"></span>
            <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-2)] text-center" data-vk-generator-fortschritt>{{ $fortschritt ?? 'Gericht wird generiert. Das Fenster kann offen bleiben.' }}</p>
            {{-- Phase 0 Watchdog: pending zu lange → sichtbarer Hinweis statt endlosem Spinner --}}
            @if($hinweis !== null)
                <x-fa::notice tone="warn" class="w-full" data-vk-generator-hinweis>{{ $hinweis }}</x-fa::notice>
            @endif
        </div>
    @elseif($ergebnis === null)
        <x-fa::section title="Was soll es werden?" icon="heroicon-o-pencil-square">
            <x-fa::field for="vkgen-beschreibung" hint="Je genauer Hauptzutat, Garmethode und Einsatz beschrieben sind, desto besser passt der Vorschlag.">
                <x-fa::textarea id="vkgen-beschreibung" wire:model="description" rows="4" data-vk-generator-description
                    placeholder="z. B. Herbstlicher Hauptgang mit geschmortem Rind, Wurzelgemüse und Kartoffelkomponente für Bankett …" />
            </x-fa::field>
        </x-fa::section>

        {{-- VK-eigene Kontext-Achsen: wo und wie das Gericht verkauft wird --}}
        <x-fa::section title="Einsatz" icon="heroicon-o-calendar-days">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <x-fa::field label="Anlass" for="vkgen-anlass">
                    <x-fa::select id="vkgen-anlass" wire:model="parameter.occasion" placeholder="egal"
                        :options="['fruehstueck' => 'Frühstück', 'lunch' => 'Lunch', 'konferenz' => 'Konferenz', 'empfang' => 'Empfang', 'dinner' => 'Dinner', 'late_night' => 'Late Night']" />
                </x-fa::field>
                <x-fa::field label="Serviceform" for="vkgen-service">
                    <x-fa::select id="vkgen-service" wire:model="parameter.serviceform" placeholder="egal"
                        :options="['tellerservice' => 'Tellerservice', 'buffet' => 'Buffet', 'flying' => 'Flying Service', 'stehempfang' => 'Stehempfang', 'boxed' => 'Boxed']" />
                </x-fa::field>
                <x-fa::field label="Sektor" for="vkgen-sektor" :hint="$parameter['sektor'] === '' ? 'Für alle Sektoren' : null" data-richtung="sektor">
                    <x-fa::select id="vkgen-sektor" wire:model="parameter.sektor" placeholder="egal"
                        :options="['betriebsgastronomie' => 'Betriebsgastronomie', 'catering' => 'Catering / Event', 'restaurant' => 'Restaurant / à la carte', 'care' => 'Care / Klinik', 'schule_kita' => 'Schule / Kita']" />
                </x-fa::field>
                {{-- Spec 03 L8b-2: Ziel-VK als Vorgabe für den Vorschlag (kein Solver) --}}
                <x-fa::field label="Ziel-VK netto je Portion" for="vkgen-ziel" optional class="sm:col-span-2 md:col-span-1" data-richtung="ziel-vk">
                    <x-fa::input id="vkgen-ziel" wire:model="zielVk" placeholder="z. B. 8,50" numeric inputmode="decimal" data-vk-ziel-vk />
                </x-fa::field>
                <p class="{{ $hilfe }} sm:col-span-2 self-end">Der Ziel-VK lenkt Komponenten, Qualität und Grammatur. Nach der Kalkulation wird er dem gerechneten VK gegenübergestellt, der Preis wird nicht auf das Ziel gedrückt.</p>
            </div>
        </x-fa::section>

        <x-fa::section title="Richtung" icon="heroicon-o-adjustments-horizontal">
            <div class="grid md:grid-cols-2 gap-x-6 gap-y-5" data-vk-generator-parameter>
                {{-- Diät steht zuerst: sie ist die einzige harte Vorgabe. --}}
                <div class="md:col-span-2" data-richtung="diaet">
                    <p class="{{ $gruppe }}">Diät (wird streng eingehalten, mehrere möglich)</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                            @php $an = in_array($wert, $parameter['diaet_hart'], true); @endphp
                            <button type="button" wire:click="togglePill('diaet_hart', '{{ $wert }}')" wire:key="vkgen-diaet-{{ $wert }}"
                                    aria-pressed="{{ $an ? 'true' : 'false' }}" class="{{ $chip }} {{ $an ? $chipAn : $chipAus }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Richtungs-Pills — identisches Muster wie GeneratorModal::RICHTUNGEN --}}
                @foreach(\Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal::RICHTUNGEN as $g)
                    <div data-richtung="{{ $g['field'] }}" wire:key="vkgen-r-{{ $g['field'] }}">
                        <p class="{{ $gruppe }}">{{ $g['label'] }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($g['optionen'] as $wert => $lbl)
                                @php $an = $parameter[$g['field']] === $wert; @endphp
                                <button type="button" wire:click="togglePill('{{ $g['field'] }}', '{{ $wert }}')"
                                        aria-pressed="{{ $an ? 'true' : 'false' }}" class="{{ $chip }} {{ $an ? $chipAn : $chipAus }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        @if(($g['hint'][$parameter[$g['field']]] ?? '') !== '')
                            <p class="{{ $hilfe }}">{{ $g['hint'][$parameter[$g['field']]] }}</p>
                        @endif
                    </div>
                @endforeach

                <x-fa::field label="Kompositions-Stil" for="vkgen-stil">
                    <x-fa::select id="vkgen-stil" wire:model="parameter.kompositions_stil" placeholder="egal" data-vk-stil
                        :options="['klassisch' => 'klassisch', 'kreativ' => 'kreativ', 'gewagt' => 'gewagt (nur belegte Paarungen)']" />
                </x-fa::field>

                <x-fa::field label="Aroma-Richtung" for="vkgen-aroma" :hint="$parameter['aroma'] === '' ? 'Ohne Vorgabe wählt die KI passend zur Beschreibung' : null" data-richtung="aroma">
                    <x-fa::input id="vkgen-aroma" wire:model="parameter.aroma" placeholder="frei, z. B. rauchig-karamellig, mediterran …" />
                </x-fa::field>

                {{-- 06·H4: opt-in Favoriten-Modus (Default aus → keine Versteifung) --}}
                <div class="md:col-span-2 flex flex-col gap-1.5" data-richtung="favoriten">
                    <label class="flex items-start gap-2 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] cursor-pointer">
                        <input type="checkbox" wire:model.live="useFavoritesList" class="mt-0.5 accent-[var(--fa-accent)]" data-vk-favoriten />
                        <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-star', 'w-4 h-4 text-[var(--fa-ink-3)]') Auf Basis meiner Favoriten bauen</span>
                    </label>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] ml-6">Bevorzugt die gepflegten Lieblings-Grundprodukte, schließt andere aber nicht aus. Aus heißt: freie Auswahl.</p>
                    <label x-show="$wire.useFavoritesList" class="flex items-center gap-2 ml-6 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] cursor-pointer">
                        <input type="checkbox" wire:model="favoritesConvenienceOnly" class="accent-[var(--fa-accent)]" data-vk-favoriten-conv /> nur Convenience-Favoriten
                    </label>
                </div>

                {{-- Spec 03 L7b: One-Shot — Generieren und Anreichern in einem Durchlauf --}}
                <div class="md:col-span-2 pt-3 border-t border-[var(--fa-line)]">
                    <x-foodalchemist::oneshot-toggle marker="vk-generator" schritte="Beschreibung, Verkaufstext, Anrichten, Speisen-Klasse" />
                </div>
            </div>
        </x-fa::section>
    @else
        <x-fa::section title="Ergebnis" icon="heroicon-o-check-circle">
            <p class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]" data-vk-generator-ergebnis>{{ $ergebnis['name'] }}</p>
            <div class="flex flex-wrap gap-1.5">
                <x-fa::badge tone="info">{{ $ergebnis['statistik']['bestand_sub'] }} {{ (int) $ergebnis['statistik']['bestand_sub'] === 1 ? 'Basisrezept' : 'Basisrezepte' }} als Komponente</x-fa::badge>
                <x-fa::badge tone="ok">{{ $ergebnis['statistik']['bestand_gp'] }} {{ (int) $ergebnis['statistik']['bestand_gp'] === 1 ? 'Grundprodukt' : 'Grundprodukte' }} aus dem Bestand</x-fa::badge>
                <x-fa::badge tone="warn">{{ $ergebnis['statistik']['stub_neu'] }} Platzhalter neu</x-fa::badge>
                <x-fa::badge :tone="$ergebnis['statistik']['offen'] > 0 ? 'crit' : 'neutral'">{{ $ergebnis['statistik']['offen'] }} offen</x-fa::badge>
            </div>
            <x-foodalchemist::hardstop-zeilen :offene="$ergebnis['offene']" prefix="vk-"
                                             :aufgeklappt="$hardstopOffenIndex" :meldung="$hardstopMeldung" />
            <x-foodalchemist::stub-offen :stubs="$ergebnis['statistik']['stubs'] ?? []" />
            <x-foodalchemist::kontext-inspektor :kontext="$ergebnis['kontext'] ?? null" />
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Speisen-Klasse und Preisklasse sind aus dem Vorschlag übernommen, soweit sie gültig waren. Den Rest im Gericht-Editor pflegen.</p>
            @if($laeuft)
                <p wire:poll.2s="pruefeErgebnis" class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-accent)]" data-vk-generator-anreicherung-laeuft>
                    @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Gericht gespeichert. Vollanreicherung läuft separat …
                </p>
            @endif
            <x-foodalchemist::oneshot-ergebnis :anreicherung="$anreicherung" />
        </x-fa::section>
    @endif

    <x-slot:footer>
        <x-fa::button variant="ghost" wire:click="$dispatch('modal.close', { name: 'vk-generator-modal' })">{{ $ergebnis === null ? 'Abbrechen' : 'Schließen' }}</x-fa::button>
        {{-- Sichtbar machen, WAS rauskommt: volle Gericht-Ansicht (Komponenten, Wording, Plating, Kalkulation) --}}
        @if($ergebnis !== null)
            <x-fa::button icon="heroicon-o-eye" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ (int) ($ergebnis['recipe_id'] ?? 0) }} })" data-vk-generator-ansehen>Gericht ansehen</x-fa::button>
        @endif
        @if($laeuft)
            <x-fa::button variant="primary" icon="heroicon-o-arrow-path" disabled data-vk-generator-laeuft-btn>{{ $ergebnis === null ? 'Generiert' : 'Reichert an' }} …</x-fa::button>
        @elseif($ergebnis === null)
            <x-fa::button variant="primary" icon="heroicon-o-sparkles" wire:click="generieren" wire:loading.attr="disabled" data-vk-generator-start>Gericht generieren</x-fa::button>
        @elseif(!$freigegeben)
            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="generatorFreigeben" :disabled="$offene > 0"
                :title="$offene > 0 ? 'Erst die offenen Zutaten klären' : null" data-vk-generator-freigeben>Gericht freigeben</x-fa::button>
        @endif
    </x-slot:footer>
</x-foodalchemist::modal>
