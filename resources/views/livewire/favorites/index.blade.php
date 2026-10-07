{{-- 06·H2: Kuratierungs-Screen Favoriten-GPs (Auto-Score + Pin/Exclude).
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion und wire:-Bindungen unverändert.
     Neu: zwei Flächen statt einer Mischliste — oben „Deine Favoriten" in ihrer Reihenfolge, darunter
     „Vorschläge" nach Eignung; Heroicons statt Stern-/Haken-Zeichen, fehlender Preis als Signal. --}}
@php
    $gruppen = [
        'favoriten' => [
            'titel' => 'Deine Favoriten',
            'icon' => 'heroicon-o-star',
            'zeilen' => $favoriten,
            'beschreibung' => 'Die Reihenfolge legst du mit der Zahl in der Spalte „Reihenfolge“ fest. Kleinere Zahl steht weiter oben.',
        ],
        'vorschlaege' => [
            'titel' => 'Vorschläge',
            'icon' => 'heroicon-o-light-bulb',
            'zeilen' => $vorschlaege,
            'beschreibung' => 'Sortiert nach Eignung: wie oft das Grundprodukt in Rezepten steckt, ob ein Lieferantenartikel mit Preis hinterlegt ist und wie wichtig der Lieferant ist.',
        ],
    ];
    if ($nurGepinnt) {
        unset($gruppen['vorschlaege']);
    }
    $anzahlVorschlaege = $vorschlaege->count();
    $kopfZeile = $anzahlGepinnt . ' angepinnt'
        . ($nurGepinnt ? '' : ' · ' . $anzahlVorschlaege . ($anzahlVorschlaege === 1 ? ' Vorschlag' : ' Vorschläge'));
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Favoriten" icon="heroicon-o-star" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Favoriten'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <div class="max-w-5xl flex flex-col gap-4">
            <x-fa::page-header title="Favoriten" :subtitle="$kopfZeile">
                <x-slot:actions>
                    <x-fa::button icon="heroicon-o-printer" target="_blank"
                        :href="route('foodalchemist.favorites.dokument', ['profil' => 'kalkulation', 'limit' => $limit])"
                        title="Druck- und PDF-Liste der Favoriten mit Eignung, Lieferantenartikel und Preisabdeckung" data-favoriten-druck>Liste drucken</x-fa::button>
                </x-slot:actions>
            </x-fa::page-header>

            <x-fa::notice tone="info">
                Pinne die Grundprodukte an, mit denen deine Küche am liebsten arbeitet. Jedes Grundprodukt lässt sich anpinnen, nicht nur Convenience.
                Der Rezept-Generator nutzt deine Favoriten nur, wenn dort „Auf Basis meiner Favoriten bauen“ eingeschaltet ist.
            </x-fa::notice>

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-72">
                    <label for="favoriten-suche" class="sr-only">Grundprodukt suchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="favoriten-suche" type="search" wire:model.live.debounce.300ms="q" placeholder="Grundprodukt suchen" class="pl-8" />
                </div>
                <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer">
                    <input type="checkbox" wire:model.live="nurGepinnt"
                           class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" /> Nur angepinnte zeigen
                </label>
            </div>

            @foreach($gruppen as $schluessel => $gruppe)
                <x-fa::section :title="$gruppe['titel']" :icon="$gruppe['icon']" :meta="(string) $gruppe['zeilen']->count()"
                    :description="$gruppe['zeilen']->isNotEmpty() ? $gruppe['beschreibung'] : null" wire:key="favgruppe-{{ $schluessel }}">
                    @if($gruppe['zeilen']->isEmpty())
                        @if($schluessel === 'favoriten')
                            <x-fa::empty compact icon="heroicon-o-star" title="{{ trim($q) !== '' ? 'Kein angepinnter Favorit passt zur Suche' : 'Noch keine Favoriten angepinnt' }}">
                                {{ $nurGepinnt ? 'Schalte „Nur angepinnte zeigen“ aus und pinne einen Vorschlag an.' : 'Pinne unten bei den Vorschlägen die Grundprodukte an, die du am häufigsten einsetzt.' }}
                            </x-fa::empty>
                        @else
                            <x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Keine Grundprodukte gefunden">Anderen Suchbegriff versuchen.</x-fa::empty>
                        @endif
                    @else
                        <div class="-mx-4 -mb-4 overflow-x-auto border-t border-[var(--fa-line)]">
                            <table class="fa-table">
                                <thead>
                                    <tr>
                                        <th class="w-full">Grundprodukt</th>
                                        <th class="num" title="Wie oft das Grundprodukt als Zutat in Rezepten steckt">In Rezepten</th>
                                        <th>Lieferantenartikel</th>
                                        <th>Preis</th>
                                        <th class="num" title="Nutzung, Lieferantenartikel, Preis und Lieferanten-Priorität zusammengerechnet">Eignung</th>
                                        @if($schluessel === 'favoriten')<th class="num">Reihenfolge</th>@endif
                                        <th><span class="sr-only">Aktion</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($gruppe['zeilen'] as $r)
                                        <tr wire:key="fav-{{ $r['gp_id'] }}" class="border-t border-[var(--fa-line)]">
                                            <td class="min-w-[14rem]">
                                                <span class="flex items-center gap-2">
                                                    @if($r['is_favorite'])
                                                        <span class="text-[var(--fa-warn)]" title="Angepinnt">@svg('heroicon-s-star', 'w-4 h-4 shrink-0')</span>
                                                    @endif
                                                    <a href="{{ route('foodalchemist.gps.index', ['gp' => $r['gp_id'], 'edit' => 1]) }}" wire:navigate
                                                       class="font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline" title="Grundprodukt Nr. {{ $r['gp_id'] }} öffnen">{{ $r['name'] }}</a>
                                                    @if($r['is_convenience'])<x-fa::badge tone="info" title="Als Convenience gekennzeichnet">Convenience</x-fa::badge>@endif
                                                </span>
                                            </td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $r['usage'] }}</td>
                                            <td class="whitespace-nowrap">
                                                @if($r['has_lead_la'])
                                                    <x-fa::signal tone="ok">hinterlegt</x-fa::signal>
                                                @else
                                                    <x-fa::signal tone="warn">fehlt</x-fa::signal>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap">
                                                @if($r['has_price'])
                                                    <x-fa::signal tone="ok">vorhanden</x-fa::signal>
                                                @else
                                                    <x-fa::badge tone="crit">Preis fehlt</x-fa::badge>
                                                @endif
                                            </td>
                                            <td class="num font-medium text-[var(--fa-ink)]">{{ number_format($r['score'], 2, ',', '.') }}</td>
                                            @if($schluessel === 'favoriten')
                                                <td class="num">
                                                    <x-fa::input type="number" min="0" size="sm" numeric
                                                        value="{{ $r['favorite_rank'] }}"
                                                        wire:change="setRank({{ $r['gp_id'] }}, $event.target.value)"
                                                        aria-label="Reihenfolge von {{ $r['name'] }}" class="w-20" />
                                                </td>
                                            @endif
                                            <td class="text-right whitespace-nowrap">
                                                @if($r['is_favorite'])
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-x-mark" wire:click="exclude({{ $r['gp_id'] }})">Entfernen</x-fa::button>
                                                @else
                                                    <x-fa::button size="sm" icon="heroicon-o-star" wire:click="pin({{ $r['gp_id'] }})">Anpinnen</x-fa::button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-fa::section>
            @endforeach
        </div>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
