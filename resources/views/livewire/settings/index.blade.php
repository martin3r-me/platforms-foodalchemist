{{-- M1-01: Settings-Gerüst — Sektion = eigene URL (V-17).
     Spec 28 / E16: die Sektions-Navigation lag als w-72-Karte IM Inhaltsbereich und nahm der
     Sektion 288px weg. Sie gehört in die Plattform-Sidebar, wie in allen anderen Schirmen.
     Der Sektions-Kopf (Titel + Zweck) steht jetzt EINMAL hier statt in jeder der 17 Sektionen. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Einstellungen" icon="heroicon-o-cog-6-tooth" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einstellungen'],
            ['label' => $sektionen[$sektion]['label'] ?? ''],
        ]" />
    </x-slot>

    {{-- LINKS: Sektions-Navigation --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Bereiche" width="w-72">
            {{-- Die Reihenfolge folgt der Arbeits-Kaskade (siehe Settings\Index::SEKTIONEN). Bewusst
                 KEINE Gruppen; wer direkt weiß was er sucht, tippt in den Filter — matcht Label UND
                 Hint, rein clientseitig (alle Links bleiben im DOM, nur x-show blendet aus). --}}
            @php($suchtexte = collect($sektionen)->map(fn ($m) => \Illuminate\Support\Str::lower($m['label'].' '.$m['hint']))->values())
            <div class="p-3" x-data="{ q: '' }">
                <div class="relative mb-2">
                    <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-gray-400">
                        @svg('heroicon-o-magnifying-glass', 'w-3.5 h-3.5')
                    </span>
                    <input type="search" x-model="q" data-settings-filter
                           placeholder="Bereich filtern…" aria-label="Bereiche filtern"
                           class="{{ $input }} !pl-8" />
                </div>

                <nav class="space-y-0.5" data-settings-nav>
                    @foreach($sektionen as $key => $meta)
                        {{-- Auswahl wie in den Filter-Bäumen: Balken + Füllung. Der transparente Balken
                             auf den ruhenden Einträgen hält die Breite, damit beim Wechsel nichts springt. --}}
                        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => $key]) }}" wire:navigate.hover
                           x-show="q === '' || @js(\Illuminate\Support\Str::lower($meta['label'].' '.$meta['hint'])).includes(q.toLowerCase())"
                           class="block px-3 py-2 rounded-lg border-l-2 transition-all duration-150 {{ $sektion === $key
                                ? 'border-violet-500 bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700'
                                : 'border-transparent text-gray-700 hover:bg-black/[0.03]' }}"
                           data-settings-link="{{ $key }}">
                            <span class="block text-xs font-medium">{{ $meta['label'] }}</span>
                            <span class="block text-[11px] text-gray-500 mt-0.5 leading-snug">{{ $meta['hint'] }}</span>
                        </a>
                    @endforeach
                    <p class="px-3 py-2 text-[11px] text-gray-400" data-settings-filter-leer
                       x-show="q !== '' && ! @js($suchtexte).some(s => s.includes(q.toLowerCase()))" x-cloak>
                        Kein Bereich passt zu „<span x-text="q"></span>".
                    </p>
                </nav>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($istKindTeam)
            <x-foodalchemist::alert tone="warning" data-settings-erbe>
                Du siehst den geerbten Katalog deines Eltern-Teams — editierbar ist nur, was deinem Team gehört (D1).
                Einkaufs- und Kalkulations-Einstellungen entscheidet dein Team selbst.
            </x-foodalchemist::alert>
        @endif

        {{-- Sektions-Kopf: EINMAL hier, aus derselben Quelle wie die Navigation --}}
        <div data-settings-kopf>
            <h2 class="text-base font-semibold tracking-tight text-gray-900">{{ $sektionen[$sektion]['label'] ?? '' }}</h2>
            <p class="text-[11px] text-gray-500 mt-0.5">{{ $sektionen[$sektion]['hint'] ?? '' }}</p>
        </div>

        {{-- aktive Sektion (eigene Livewire-Komponente, isolierter State) --}}
        <div class="min-w-0" data-settings-sektion="{{ $sektion }}">
            @livewire('foodalchemist.settings.' . $sektion, key('sektion-' . $sektion))
        </div>

    </x-ui-page-container>
</x-ui-page>
