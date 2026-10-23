{{-- Einstellungen — Neuordnung 23.10. (Dominique, 00_INBOX/_Einstellungen_Neuordnung_Vorschlag.md): REITER statt Seitenleiste.

     Je Gruppe (Settings\Index::GRUPPEN) ein Reiter oben; im Reiter die Bereiche als Abschnitte mit Sprungmarken. Reiter und
     Sprungziel stehen in der URL (?tab=…&bereich=…); alte Links /einstellungen/{sektion} öffnen den passenden Reiter.

     Keine Seitenleiste mehr: die Reiter SIND die Navigation, eine Leiste daneben hätte sie nur verdoppelt und breiten Editoren
     (Präsentations-Designs, Wissens-Steuerung) 288 px genommen. Die Suche steht über den Reitern und findet jeden sichtbaren
     Bereich über Name, Beschreibung und Stichworte (Unterabschnitte wie MwSt, Drucker, Lagerort).

     Leistung: nur der aktive Reiter wird gerendert (Leiste im Server-Modus). Im Reiter lädt der erste Bereich (bzw. das Sprungziel)
     sofort, die übrigen verzögert (lazy, beim Hineinscrollen); erweiterte Bereiche stehen in einem aufklappbaren Block. --}}
@php
    $suchtext = fn (string $key) => \Illuminate\Support\Str::lower($sektionen[$key]['label'] . ' ' . $sektionen[$key]['hint'] . ' ' . ($stichworte[$key] ?? ''));
    $link = fn (string $key) => route('foodalchemist.einstellungen', ['tab' => \Platform\FoodAlchemist\Livewire\Settings\Index::gruppeVon($key), 'bereich' => $key]);
    $tabLabels = array_map(fn ($g) => $g['label'], $gruppen);
    $eager = $bereich ?? ($grundBereiche[0] ?? null);
    $erweitertOffen = $bereich !== null && in_array($bereich, $erweiterteBereiche, true);
    // E-3: Symbol je Reiter (in der Bereichs-Karte), nur FA-Akzent
    $reiterIcon = ['uebersicht' => 'heroicon-o-squares-2x2', 'team' => 'heroicon-o-user-group', 'kueche' => 'heroicon-o-fire',
        'einkauf' => 'heroicon-o-truck', 'kalkulation' => 'heroicon-o-calculator', 'stil' => 'heroicon-o-swatch',
        'kategorien' => 'heroicon-o-tag', 'ki' => 'heroicon-o-sparkles', 'plattform' => 'heroicon-o-server-stack'][$tab] ?? 'heroicon-o-cog-6-tooth';
    $bereichParam = fn (string $key, bool $lazy) => ['key' => $key, 'meta' => $sektionen[$key], 'lazy' => $lazy, 'ziel' => $key === $bereich,
        'icon' => $reiterIcon, 'imReiter' => (bool) ($mitSperre[$key] ?? false)];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Einstellungen" icon="heroicon-o-cog-6-tooth" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einstellungen', 'href' => route('foodalchemist.einstellungen')],
            ['label' => $gruppen[$tab]['label'] ?? ''],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">

        {{-- Suche über alle sichtbaren Bereiche (alle Reiter); Treffer öffnen Reiter + Bereich --}}
        <div class="relative max-w-xl" x-data="{ q: '' }" x-on:keydown.escape="q = ''" data-settings-suche>
            <label for="settings-filter" class="sr-only">Einstellungen durchsuchen</label>
            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-[1.15rem] -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
            <x-fa::input id="settings-filter" type="search" x-model="q" data-settings-filter autocomplete="off"
                placeholder="Einstellung suchen, z. B. MwSt, Drucker, Lagerort" aria-label="Einstellungen durchsuchen" class="pl-8" />
            <ul class="absolute z-30 mt-1 w-full max-h-80 overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] shadow-lg"
                x-show="q !== ''" x-cloak data-settings-nav aria-label="Treffer">
                @foreach($sektionen as $key => $meta)
                    <li x-show="@js($suchtext($key)).includes(q.toLowerCase())">
                        <a href="{{ $link($key) }}" wire:navigate class="block px-3 py-2 hover:bg-[var(--fa-hover)]" data-settings-link="{{ $key }}">
                            <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $meta['label'] }}
                                <span class="font-normal text-[var(--fa-ink-3)]"> · {{ $gruppen[\Platform\FoodAlchemist\Livewire\Settings\Index::gruppeVon($key)]['label'] ?? '' }}</span></span>
                            <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $meta['hint'] }}</span>
                        </a>
                    </li>
                @endforeach
                <li class="px-3 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-settings-filter-leer
                    x-show="! @js(collect(array_keys($sektionen))->map($suchtext)->values()).some(s => s.includes(q.toLowerCase()))">
                    Nichts gefunden zu „<span x-text="q"></span>“.
                </li>
            </ul>
        </div>

        @if($nurLesen)
            <x-fa::notice tone="info" data-settings-nur-lesen>
                Du siehst die Einstellungen deines Teams. Ändern kann sie der Admin deines Teams.
            </x-fa::notice>
        @endif

        @if($istKindTeam)
            <x-fa::notice tone="info" data-settings-erbe>
                Du siehst den Katalog deines Eltern-Teams. Ändern kannst du nur, was deinem Team gehört.
                Einkauf und Kalkulation legt dein Team selbst fest.
            </x-fa::notice>
        @endif

        <x-foodalchemist::editor-tabs marker="settings" action="tabWaehlen" :active="$tab" :tabs="$tabLabels" scroll stark />

        <div class="flex flex-col gap-8" data-settings-reiter="{{ $tab }}">
            {{-- Sprungmarken im Reiter + EIN Bearbeiten für den ganzen Reiter (E-3) --}}
            <div class="flex flex-wrap items-center gap-3">
            @if(count($grundBereiche) + count($erweiterteBereiche) > 1)
                <nav class="flex flex-wrap gap-1.5 flex-1 min-w-0" aria-label="Bereiche in diesem Reiter" data-settings-sprung>
                    @foreach($grundBereiche as $key)
                        <a href="#bereich-{{ $key }}" class="px-2.5 py-1 rounded-full border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft-hover)]"
                           title="{{ $sektionen[$key]['hint'] }}">{{ $sektionen[$key]['label'] }}</a>
                    @endforeach
                    @if($erweiterteBereiche !== [])
                        <a href="#settings-erweitert" class="px-2.5 py-1 rounded-full border border-dashed border-[var(--fa-line-strong)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] hover:bg-[var(--fa-hover)]"
                           x-on:click="$dispatch('settings-erweitert-oeffnen')">Erweitert ({{ count($erweiterteBereiche) }})</a>
                    @endif
                </nav>
            @else
                <div class="flex-1"></div>
            @endif
            @if($reiterSperre['modus'] === 'lesen')
                <x-fa::button variant="primary" icon="heroicon-m-pencil-square" wire:click="reiterBearbeiten" data-reiter-bearbeiten>Bearbeiten</x-fa::button>
            @elseif($reiterSperre['modus'] === 'bearbeiten')
                <span class="hidden" x-data x-on:input.window.throttle.60000ms="$wire.reiterHerzschlag()" x-on:click.window.throttle.60000ms="$wire.reiterHerzschlag()"></span>
                <span class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ok)]" data-reiter-in-bearbeitung>
                    <span class="w-2 h-2 rounded-full bg-[var(--fa-ok)]"></span>Du bearbeitest diesen Reiter
                </span>
                <x-fa::button variant="secondary" icon="heroicon-m-check" wire:click="reiterFertig"
                    wire:confirm="Bearbeitung beenden? Nicht gespeicherte Eingaben in Formularen dieses Reiters gehen verloren." data-reiter-fertig>Fertig</x-fa::button>
            @endif
            </div>
            @if($reiterSperre['fremd'] !== [])
                <x-fa::notice tone="warn" data-reiter-fremd>Gerade in Bearbeitung: {{ implode(' · ', $reiterSperre['fremd']) }}. Diese Bereiche sind für dich nur lesbar.</x-fa::notice>
            @endif

            @foreach($grundBereiche as $key)
                @include('foodalchemist::livewire.settings.partials.bereich', $bereichParam($key, $key !== $eager))
            @endforeach

            @if($erweiterteBereiche !== [])
                <div id="settings-erweitert" class="scroll-mt-24 flex flex-col gap-8" x-data="{ offen: @js($erweitertOffen) }"
                     x-on:settings-erweitert-oeffnen.window="offen = true" data-settings-erweitert>
                    <button type="button" x-on:click="offen = ! offen" x-bind:aria-expanded="offen"
                            class="flex items-center gap-2 text-left text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]">
                        <span class="transition-transform duration-150" x-bind:class="offen ? 'rotate-90' : ''">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                        Erweitert: {{ collect($erweiterteBereiche)->map(fn ($k) => $sektionen[$k]['label'])->implode(', ') }}
                    </button>
                    <div class="flex flex-col gap-8" x-show="offen" x-cloak>
                        @foreach($erweiterteBereiche as $key)
                            @include('foodalchemist::livewire.settings.partials.bereich', $bereichParam($key, $key !== $bereich))
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

    </x-ui-page-container>
</x-ui-page>
