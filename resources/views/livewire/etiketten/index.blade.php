{{-- Spec 70 · Etikett drucken: Formular + Live-Vorschau. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $segment = 'h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium transition-colors duration-150';
    $auto = fn ($c) => $c?->format('d.m.Y');
    $hatFeld = fn (string $k) => in_array($k, $felder, true);
    $quelleText = ['recipe' => 'Rezept', 'gp' => 'Grundprodukt', 'stellplatz' => 'Stellplatz'];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Etiketten" icon="heroicon-o-tag" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Etikett drucken" subtitle="Für Eigenproduktion, Anbruch und Stellplätze. Allergene und Zutaten kommen aus dem Rezept; leere Datumsfelder rechnet das System." />

        @if($fehler)<x-fa::notice tone="crit" data-etikett-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

        <div class="grid gap-4 lg:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                {{-- Was --}}
                <x-fa::section title="Was" icon="heroicon-o-magnifying-glass" data-etikett-quelle>
                    @if($daten)
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <div class="font-medium text-[var(--fa-ink)]">{{ $daten['bezeichnung'] }}</div>
                                <div class="{{ $leise }}">{{ $quelleText[$quelle] ?? $quelle }}</div>
                            </div>
                            <x-fa::button size="sm" variant="ghost" wire:click="$set('bezugId', null)">ändern</x-fa::button>
                        </div>
                    @else
                        <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] self-start">
                            @foreach(['recipe' => 'Rezept / Gericht', 'gp' => 'Grundprodukt'] as $k => $t)
                                <button type="button" wire:click="$set('suchArt', '{{ $k }}')" class="{{ $segment }} {{ $suchArt === $k ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}">{{ $t }}</button>
                            @endforeach
                        </div>
                        <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="suche" placeholder="Suchen …" aria-label="Rezept oder Grundprodukt suchen" data-etikett-suche />
                        @foreach($treffer as $t)
                            <button type="button" wire:key="et-{{ $suchArt }}-{{ $t->id }}" wire:click="waehlen('{{ $suchArt }}', {{ $t->id }})" class="self-start text-left text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">{{ $t->name }}</button>
                        @endforeach
                    @endif
                </x-fa::section>

                @if($daten)
                    {{-- Vorlage + Anzahl --}}
                    <x-fa::section title="Vorlage" icon="heroicon-o-swatch" data-etikett-vorlage>
                        <x-fa::select wire:model.live="vorlageId" size="sm" :options="$vorlagen->pluck('name', 'id')" aria-label="Etikett-Vorlage" />
                        <p class="{{ $leise }}">{{ $format['label'] }} · {{ $vorlage->typ === 'verkauf' ? 'Verkauf (LMIV-Pflichtangaben)' : 'intern' }}</p>
                        <div class="flex flex-wrap items-end gap-2">
                            <x-fa::field label="Anzahl" for="et-anzahl"><x-fa::input id="et-anzahl" type="number" min="1" max="500" wire:model.live.debounce.400ms="anzahl" class="w-20" /></x-fa::field>
                            @if($format['bogen'])
                                <x-fa::field label="Start bei Platz" for="et-start" hint="für angebrochene Bögen"><x-fa::input id="et-start" type="number" min="1" wire:model.live.debounce.400ms="startplatz" class="w-20" /></x-fa::field>
                            @endif
                        </div>
                        @if(\Illuminate\Support\Facades\Route::has('foodalchemist.einstellungen'))
                            <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'etiketten']) }}" class="{{ $leise }} hover:underline">Vorlagen gestalten →</a>
                        @endif
                    </x-fa::section>

                    {{-- Angaben --}}
                    @if($quelle !== 'stellplatz')
                        <x-fa::section title="Angaben" icon="heroicon-o-pencil-square" description="Leer lassen = automatisch. Felder, die die Vorlage leer druckt, füllt man von Hand aus." data-etikett-angaben>
                            <x-fa::field label="Bezeichnung" for="et-bez"><x-fa::input id="et-bez" wire:model.live.debounce.500ms="e.bezeichnung" :placeholder="$daten['bezeichnung']" /></x-fa::field>
                            @if($hatFeld('zusatz'))<x-fa::field label="Zusatzzeile" for="et-zus"><x-fa::input id="et-zus" wire:model.live.debounce.500ms="e.zusatz" /></x-fa::field>@endif
                            @if($quelle === 'recipe' || $hatFeld('lagerung'))
                                <x-fa::field label="Lagerung" for="et-lag">
                                    <x-fa::select id="et-lag" wire:model.live="e.lagerung" size="sm" :options="\Platform\FoodAlchemist\Services\EtikettService::LAGERUNG" :placeholder="'automatisch (' . (\Platform\FoodAlchemist\Services\EtikettService::LAGERUNG[$daten['lagerung']] ?? '–') . ')'" />
                                </x-fa::field>
                            @endif
                            <div class="grid grid-cols-2 gap-2">
                                @foreach(['hergestellt_am' => 'Hergestellt', 'eingefroren_am' => 'Eingefroren', 'geoeffnet_am' => 'Geöffnet', 'verbrauchen_bis' => 'Verbrauchen bis'] as $k => $t)
                                    @if($hatFeld($k))
                                        <x-fa::field :label="$t" :for="'et-' . $k" :hint="$daten['datum'][$k] ? 'automatisch: ' . $auto($daten['datum'][$k]) : null">
                                            <x-fa::input :id="'et-' . $k" type="date" wire:model.live="e.{{ $k }}" />
                                        </x-fa::field>
                                    @endif
                                @endforeach
                            </div>
                            @foreach(['menge' => 'Menge (z. B. 2 l, 10 Portionen)', 'kuerzel' => 'Kürzel', 'charge' => 'Charge'] as $k => $t)
                                @if($hatFeld($k))<x-fa::field :label="$t" :for="'et-' . $k"><x-fa::input :id="'et-' . $k" wire:model.live.debounce.500ms="e.{{ $k }}" placeholder="leer = Schreiblinie" /></x-fa::field>@endif
                            @endforeach
                        </x-fa::section>

                        @if($quelle === 'recipe')
                            <x-fa::section title="Lagerung & Haltbarkeit am Rezept" icon="heroicon-o-clock" description="Übliche Lagerart und Vorschlag für „verbrauchen bis“ — gilt für alle künftigen Etiketten dieses Rezepts." data-etikett-haltbarkeit>
                                <div class="flex flex-wrap items-end gap-2">
                                    <x-fa::field label="Übliche Lagerart" for="et-la"><x-fa::select id="et-la" wire:model="lagerart" size="sm" :options="\Platform\FoodAlchemist\Services\EtikettService::LAGERUNG" placeholder="offen" /></x-fa::field>
                                    <x-fa::field label="Gekühlt (Tage)" for="et-hg"><x-fa::input id="et-hg" wire:model="haltbarGekuehlt" inputmode="numeric" class="w-20" /></x-fa::field>
                                    <x-fa::field label="Tiefgekühlt (Tage)" for="et-ht"><x-fa::input id="et-ht" wire:model="haltbarTk" inputmode="numeric" class="w-20" /></x-fa::field>
                                    <x-fa::button size="sm" wire:click="haltbarkeitSpeichern">Speichern</x-fa::button>
                                </div>
                            </x-fa::section>
                        @endif
                    @endif
                @endif
            </div>

            {{-- Vorschau --}}
            <x-fa::section title="Vorschau" icon="heroicon-o-eye" data-etikett-vorschau>
                @if($url)
                    <x-slot:actions>
                        <div class="flex flex-wrap gap-2">
                            <x-fa::button size="sm" variant="primary" icon="heroicon-m-printer" :href="$url" target="_blank" data-etikett-drucken>Drucken</x-fa::button>
                            <x-fa::button size="sm" icon="heroicon-m-arrow-down-tray" :href="$pdfUrl" data-etikett-pdf>PDF</x-fa::button>
                        </div>
                    </x-slot:actions>
                    @if($daten['allergene_unbekannt'])
                        <x-fa::notice tone="warn">Die Allergene dieses {{ $quelle === 'gp' ? 'Grundprodukts' : 'Rezepts' }} sind nicht vollständig erfasst — das Etikett weist darauf hin.</x-fa::notice>
                    @endif
                    <iframe src="{{ $vorschauUrl }}" wire:key="vorschau-{{ md5($vorschauUrl) }}" class="w-full h-[640px] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)]" title="Etikett-Vorschau" data-etikett-iframe></iframe>
                @else
                    <x-fa::empty compact icon="heroicon-o-tag" title="Erst links wählen, was etikettiert wird" />
                @endif
            </x-fa::section>
        </div>
    </x-ui-page-container>
</x-ui-page>
