{{-- M1-01: Settings-Gerüst — Sektion = eigene URL (V-17).
     Spec 28 / E16: die Sektions-Navigation gehört in die Seitenleiste links, wie in allen anderen Schirmen.
     Der Sektions-Kopf (Titel + Zweck) steht EINMAL hier statt in jeder Sektion.

     fa-pass 2026-10-05: auf Bausteine/Tokens umgestellt. Navigation in fünf Gruppen
     (Settings\Index::GRUPPEN), je Eintrag Name + ein Satz, wofür er da ist. Der Filter sucht
     weiter über Name UND Beschreibung (rein clientseitig, alle Links bleiben im DOM).
     Sektionen ohne Gruppe landen unter „Weitere" — nie verschluckt. --}}
@php
    $einsortiert = collect($gruppen)->pluck('sektionen')->flatten()->all();
    $rest = array_values(array_diff(array_keys($sektionen), $einsortiert));
    $navGruppen = collect($gruppen)
        ->map(fn ($g) => ['label' => $g['label'], 'sektionen' => array_values(array_filter($g['sektionen'], fn ($k) => isset($sektionen[$k])))])
        ->when($rest !== [], fn ($c) => $c->put('weitere', ['label' => 'Weitere', 'sektionen' => $rest]))
        ->filter(fn ($g) => $g['sektionen'] !== []);
    $suchtext = fn (string $key) => \Illuminate\Support\Str::lower($sektionen[$key]['label'] . ' ' . $sektionen[$key]['hint']);
    $suchtexte = collect(array_keys($sektionen))->map($suchtext)->values();
    $aktiveGruppe = $navGruppen->first(fn ($g) => in_array($sektion, $g['sektionen'], true))['label'] ?? null;
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Einstellungen" icon="heroicon-o-cog-6-tooth" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einstellungen'],
            ['label' => $sektionen[$sektion]['label'] ?? ''],
        ]" />
    </x-slot>

    {{-- LINKS: Bereiche, nach Gruppen --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Bereiche" width="w-72">
            @php
                $gruppenIcons = [
                    'katalog' => 'heroicon-o-squares-2x2',
                    'betrieb' => 'heroicon-o-building-storefront',
                    'kalkulation' => 'heroicon-o-calculator',
                    'ki' => 'heroicon-o-sparkles',
                    'ausgabe' => 'heroicon-o-document-text',
                    'weitere' => 'heroicon-o-ellipsis-horizontal-circle',
                ];
                $aktiverGruppenKey = $navGruppen->search(fn ($g) => in_array($sektion, $g['sektionen'], true));
            @endphp
            {{-- Gruppen klappen auf/zu (Kachel-Kopf). Offen: die Gruppe des aktiven Bereichs + was der Nutzer
                 zuletzt offen hatte (localStorage, best effort). Beim Suchen sind alle Treffer-Gruppen offen. --}}
            <div class="p-3 flex flex-col gap-3"
                 x-data="{
                    q: '',
                    offen: {},
                    init() {
                        try { this.offen = JSON.parse(localStorage.getItem('fa.einstellungen.offen') || '{}') || {}; } catch (e) { this.offen = {}; }
                        @if($aktiverGruppenKey !== false && $aktiverGruppenKey !== null) this.offen[@js($aktiverGruppenKey)] = true; @endif
                    },
                    umschalten(k) {
                        this.offen[k] = ! this.offen[k];
                        try { localStorage.setItem('fa.einstellungen.offen', JSON.stringify(this.offen)); } catch (e) {}
                    },
                    istOffen(k) { return this.q !== '' || !! this.offen[k]; },
                 }">
                <div class="relative">
                    <label for="settings-filter" class="sr-only">Bereiche filtern</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="settings-filter" type="search" x-model="q" data-settings-filter
                        placeholder="Bereich suchen" aria-label="Bereiche filtern" class="pl-8" />
                </div>

                <nav class="flex flex-col gap-2" aria-label="Einstellungen" data-settings-nav>
                    @foreach($navGruppen as $gKey => $gruppe)
                        @php
                            $gruppenTexte = collect($gruppe['sektionen'])->map($suchtext)->values();
                            $enthaeltAktiv = $gKey === $aktiverGruppenKey;
                        @endphp
                        <div class="flex flex-col" wire:key="settings-gruppe-{{ $gKey }}" data-settings-gruppe="{{ $gKey }}"
                             x-show="q === '' || @js($gruppenTexte).some(s => s.includes(q.toLowerCase()))">
                            <button type="button" x-on:click="umschalten(@js($gKey))" x-bind:aria-expanded="istOffen(@js($gKey))"
                                    aria-controls="settings-gruppe-liste-{{ $gKey }}"
                                    class="fa-settings-gruppe {{ $enthaeltAktiv ? 'is-aktiv' : '' }}">
                                <span class="fa-settings-gruppe-icon">@svg($gruppenIcons[$gKey] ?? 'heroicon-o-folder', 'w-4 h-4')</span>
                                <span class="flex-1 min-w-0 truncate text-left">{{ $gruppe['label'] }}</span>
                                <span class="fa-settings-gruppe-zahl">{{ count($gruppe['sektionen']) }}</span>
                                <span class="shrink-0 transition-transform duration-150" x-bind:class="istOffen(@js($gKey)) ? 'rotate-90' : ''">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                            </button>
                            <div id="settings-gruppe-liste-{{ $gKey }}" class="flex flex-col gap-0.5 pt-1 pl-2" x-show="istOffen(@js($gKey))" x-cloak>
                                @foreach($gruppe['sektionen'] as $key)
                                    @php
                                        $meta = $sektionen[$key];
                                        $aktiv = $sektion === $key;
                                    @endphp
                                    <a href="{{ route('foodalchemist.einstellungen', ['sektion' => $key]) }}" wire:navigate.hover
                                       x-show="q === '' || @js($suchtext($key)).includes(q.toLowerCase())"
                                       @if($aktiv) aria-current="page" @endif
                                       class="block px-2.5 py-1.5 rounded-[var(--fa-radius-control)] border-l-2 transition-colors duration-150 {{ $aktiv
                                            ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]'
                                            : 'border-transparent text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                                       data-settings-link="{{ $key }}">
                                        <span class="block text-[length:var(--fa-text-md)] font-medium">{{ $meta['label'] }}</span>
                                        <span class="block mt-0.5 text-[length:var(--fa-text-sm)] leading-snug {{ $aktiv ? 'text-[var(--fa-ink-2)]' : 'text-[var(--fa-ink-3)]' }}">{{ $meta['hint'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <p class="px-2.5 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-settings-filter-leer
                       x-show="q !== '' && ! @js($suchtexte).some(s => s.includes(q.toLowerCase()))" x-cloak>
                        Kein Bereich passt zu „<span x-text="q"></span>“.
                    </p>
                </nav>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">

        {{-- Sektions-Kopf: EINMAL hier, aus derselben Quelle wie die Navigation --}}
        <div class="min-w-0" data-settings-kopf>
            <h2 class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)]">{{ $sektionen[$sektion]['label'] ?? '' }}</h2>
            <p class="mt-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[75ch]">{{ $sektionen[$sektion]['hint'] ?? '' }}@if($aktiveGruppe)<span class="text-[var(--fa-ink-3)]"> · {{ $aktiveGruppe }}</span>@endif</p>
        </div>

        @if($istKindTeam)
            <x-fa::notice tone="info" data-settings-erbe>
                Du siehst den Katalog deines Eltern-Teams. Ändern kannst du nur, was deinem Team gehört.
                Einkauf und Kalkulation legt dein Team selbst fest.
            </x-fa::notice>
        @endif

        {{-- aktive Sektion (eigene Livewire-Komponente, isolierter State) --}}
        <div class="min-w-0" data-settings-sektion="{{ $sektion }}">
            @livewire('foodalchemist.settings.' . $sektion, key('sektion-' . $sektion))
        </div>

    </x-ui-page-container>
</x-ui-page>
