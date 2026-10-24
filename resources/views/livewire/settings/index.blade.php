{{-- Einstellungen — Neuordnung 23.10. (Dominique, 00_INBOX/_Einstellungen_Neuordnung_Vorschlag.md): REITER statt Seitenleiste.

     Je Gruppe (Settings\Index::GRUPPEN) ein Reiter oben; darunter je Unter-Bereich ein Unter-Reiter (E-4, Dominique 23.10.: „alle
     Tab-Inhalte untereinander … eher schlimmer“). Es ist immer genau EIN Unter-Reiter gerendert, die anderen stehen nicht im DOM.
     Reiter und Unter-Reiter stehen in der URL (?tab=…&bereich=…); alte Links /einstellungen/{sektion} öffnen den passenden Unter-Reiter.

     Keine Seitenleiste mehr: die Reiter SIND die Navigation, eine Leiste daneben hätte sie nur verdoppelt und breiten Editoren
     (Präsentations-Designs, Wissens-Steuerung) 288 px genommen. Die Suche steht über den Reitern und findet jeden sichtbaren
     Bereich über Name, Beschreibung und Stichworte (Unterabschnitte wie MwSt, Drucker, Lagerort).

     Erweiterte Bereiche stehen als Unter-Reiter hinter einem Trenner (kein Menü, kein Klappblock). Große Blöcke einer Sektion
     (Ausstattung, Verluste, Fixkosten, Modellstufen …) sind eigene Unter-Reiter: die Komponente bekommt `abschnitt` und zeigt nur ihn. --}}
@php
    $suchtext = fn (array $t) => \Illuminate\Support\Str::lower($t['label'] . ' ' . $t['hint'] . ' ' . $t['stichworte'] . ' ' . ($sektionen[$t['sektion']]['label'] ?? ''));
    $link = fn (string $key, array $t) => route('foodalchemist.einstellungen', ['tab' => $t['tab'], 'bereich' => $key]);
    $tabLabels = array_map(fn ($g) => $g['label'], $gruppen);
    $unterKnopf = 'shrink-0 h-8 px-3 rounded-full border text-[length:var(--fa-text-sm)] font-medium whitespace-nowrap transition-colors';
    $unterAn = 'border-[var(--fa-accent)] bg-[var(--fa-accent)] text-[var(--fa-on-accent)]';
    $unterAus = 'border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft-hover)]';
    $unterAusErweitert = 'border-[var(--fa-line)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)]';
    // E-3: Symbol je Reiter (in der Bereichs-Karte), nur FA-Akzent
    $reiterIcon = ['uebersicht' => 'heroicon-o-squares-2x2', 'team' => 'heroicon-o-user-group', 'kueche' => 'heroicon-o-fire',
        'einkauf' => 'heroicon-o-truck', 'kalkulation' => 'heroicon-o-calculator', 'stil' => 'heroicon-o-swatch',
        'kategorien' => 'heroicon-o-tag', 'ki' => 'heroicon-o-sparkles', 'plattform' => 'heroicon-o-server-stack'][$tab] ?? 'heroicon-o-cog-6-tooth';
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
                @foreach($treffer as $key => $t)
                    <li x-show="@js($suchtext($t)).includes(q.toLowerCase())">
                        <a href="{{ $link($key, $t) }}" wire:navigate class="block px-3 py-2 hover:bg-[var(--fa-hover)]" data-settings-link="{{ $key }}">
                            <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $t['label'] }}
                                <span class="font-normal text-[var(--fa-ink-3)]"> · {{ $gruppen[$t['tab']]['label'] ?? '' }}@if($t['abschnitt'] !== null) · {{ $sektionen[$t['sektion']]['label'] }}@endif</span></span>
                            <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $t['hint'] }}</span>
                        </a>
                    </li>
                @endforeach
                <li class="px-3 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-settings-filter-leer
                    x-show="! @js(collect($treffer)->map($suchtext)->values()).some(s => s.includes(q.toLowerCase()))">
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
            {{-- E-4: Unter-Reiter (je Unter-Bereich EINE Ansicht, Erweitert hinter dem Trenner) + EIN Bearbeiten für den ganzen Reiter (E-3).
                 Der Wechsel des Unter-Reiters gibt die Sperre des Reiters nicht frei. --}}
            <div class="flex flex-wrap items-center gap-3">
            @if(count($grundReiter) + count($erweiterteReiter) > 1)
                <nav class="flex flex-wrap items-center gap-1.5 flex-1 min-w-0" aria-label="Unterbereiche in diesem Reiter" data-settings-unter-reiter>
                    @foreach($grundReiter as $key => $u)
                        <button type="button" wire:click="bereichWaehlen('{{ $key }}')" title="{{ $u['label'] }}: {{ $u['hint'] }}" @if($key === $aktiv) aria-current="page" @endif
                                class="{{ $unterKnopf }} {{ $key === $aktiv ? $unterAn : $unterAus }}" data-settings-unter-reiter-knopf="{{ $key }}">{{ $u['kurz'] }}</button>
                    @endforeach
                    @if($erweiterteReiter !== [])
                        <span class="mx-1.5 h-5 w-px bg-[var(--fa-line-strong)]" aria-hidden="true"></span>
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-settings-erweitert-trenner>Erweitert</span>
                        @foreach($erweiterteReiter as $key => $u)
                            <button type="button" wire:click="bereichWaehlen('{{ $key }}')" title="{{ $u['label'] }}: {{ $u['hint'] }}" @if($key === $aktiv) aria-current="page" @endif
                                    class="{{ $unterKnopf }} {{ $key === $aktiv ? $unterAn : $unterAusErweitert }}" data-settings-unter-reiter-knopf="{{ $key }}" data-settings-erweitert="{{ $key }}">{{ $u['kurz'] }}</button>
                        @endforeach
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

            @if($aktivEintrag !== null)
                @include('foodalchemist::livewire.settings.partials.bereich', ['key' => $aktiv, 'eintrag' => $aktivEintrag, 'icon' => $reiterIcon,
                    'imReiter' => (bool) ($mitSperre[$aktivEintrag['sektion']] ?? false)])
            @endif
        </div>

    </x-ui-page-container>
</x-ui-page>
