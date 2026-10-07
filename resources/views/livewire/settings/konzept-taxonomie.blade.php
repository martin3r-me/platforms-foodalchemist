{{-- Konzept-Taxonomie: Master-Detail wie Rezept-/VK-Taxonomie (2026-06-17) — Ober-Knoten links
     wählbar → Unter-Knoten rechts. 2 Ebenen, keine flache Gesamttabelle mehr.
     Seit 2026-07-25 nicht mehr in der Navigation (Konzept-Merkmale ersetzen sie); die Komponente bleibt.
     fa-pass 2026-10-05: Bausteine/Tokens, gleiche Anordnung; Achsen-Wahl als Umschalter, Löschen im Menü. --}}
@php
    $zeileBasis = 'group flex items-center gap-1 min-h-9 pr-1 rounded-[var(--fa-radius-control)] transition-colors';
    $menueKnopf = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
@endphp

<div class="flex flex-col gap-4">
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif

    {{-- Achsen-Umschalter --}}
    <div class="flex flex-wrap items-center gap-3">
        <div role="group" aria-label="Achse" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
            @foreach(['category' => 'Kategorien', 'class' => 'Klassen'] as $ak => $al)
                <button type="button" wire:click="setAchse('{{ $ak }}')" aria-pressed="{{ $achse === $ak ? 'true' : 'false' }}"
                        class="h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $achse === $ak ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}">{{ $al }}</button>
            @endforeach
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Rein zum Ordnen und Filtern von Konzepten in Foodbook und Angebot, ohne Einfluss auf den Preis.</p>
    </div>

    @if($achse === 'category')
        @php($topKats = collect($kategorien)->where('depth', 0)->values())
        @php($selKat = collect($kategorien)->firstWhere('id', $katSelectedId))
        @php($subKats = $katSelectedId !== null ? collect($kategorien)->where('parent_id', $katSelectedId)->values() : collect())
        <div class="flex flex-wrap gap-4 items-start">
            {{-- Ober-Kategorien links --}}
            <x-fa::section title="Kategorien" :meta="$topKats->count()" class="w-72 max-w-full shrink-0" data-konzept-kat-top>
                <div class="flex flex-col gap-0.5 -mx-1">
                    @forelse($topKats as $kat)
                        @php($kinder = collect($kategorien)->where('parent_id', $kat['id'])->count())
                        @php($aktiv = $katSelectedId === $kat['id'])
                        <div wire:key="ktop-{{ $kat['id'] }}" class="{{ $zeileBasis }} pl-1 {{ $aktiv ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}">
                            @if($editKatId === $kat['id'])
                                <x-fa::input size="sm" wire:model="editKatName" wire:keydown.enter="katRename" wire:keydown.escape="$set('editKatId', null)" aria-label="Name der Kategorie" class="flex-1 min-w-0" autofocus />
                                <x-fa::button size="sm" variant="primary" wire:click="katRename">Speichern</x-fa::button>
                            @else
                                <button type="button" wire:click="katWaehlen({{ $kat['id'] }})" @if($aktiv) aria-current="true" @endif
                                        class="flex-1 min-w-0 truncate text-left px-1.5 py-1.5 text-[length:var(--fa-text-md)] {{ $aktiv ? 'font-medium' : '' }}">{{ $kat['name'] }}</button>
                                <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]" title="Unterkategorien">{{ $kinder }}</span>
                                <span class="shrink-0 flex opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                                    <x-fa::icon-button size="sm" icon="heroicon-o-pencil" label="Kategorie umbenennen" wire:click="katEditStart({{ $kat['id'] }}, @js($kat['name']))" />
                                    <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Kategorie löschen" wire:click="katLoeschen({{ $kat['id'] }})"
                                        wire:confirm="Kategorie „{{ $kat['name'] }}“ löschen? Unterkategorien und Konzepte rücken eine Ebene nach oben." />
                                </span>
                            @endif
                        </div>
                    @empty
                        <x-fa::empty compact title="Noch keine Kategorien">Unten die erste Kategorie anlegen.</x-fa::empty>
                    @endforelse
                </div>
                <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                    <label for="konzept-kat-neu" class="sr-only">Neue Kategorie</label>
                    <x-fa::input id="konzept-kat-neu" size="sm" wire:model="neuTopKat" wire:keydown.enter="katNeuTop" placeholder="Neue Kategorie" class="flex-1 min-w-0" />
                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="katNeuTop" title="Kategorie anlegen">Anlegen</x-fa::button>
                </div>
            </x-fa::section>

            {{-- Unterkategorien rechts --}}
            <x-fa::section title="Unterkategorien" :meta="$selKat['name'] ?? null" class="flex-1 basis-[24rem] min-w-0" data-konzept-kat-sub>
                @if($katSelectedId !== null)
                    <div class="overflow-x-auto -mx-4">
                        <table class="fa-table">
                            <thead><tr><th class="w-full">Bezeichnung</th><th class="num">Konzepte</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                            <tbody>
                                @forelse($subKats as $sub)
                                    <tr wire:key="ksub-{{ $sub['id'] }}">
                                        @if($editKatId === $sub['id'])
                                            <td><x-fa::input size="sm" wire:model="editKatName" wire:keydown.enter="katRename" wire:keydown.escape="$set('editKatId', null)" aria-label="Name der Unterkategorie" class="w-full min-w-40" autofocus /></td>
                                            <td></td>
                                            <td class="whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <x-fa::button size="sm" variant="ghost" wire:click="$set('editKatId', null)">Abbrechen</x-fa::button>
                                                    <x-fa::button size="sm" variant="primary" wire:click="katRename">Speichern</x-fa::button>
                                                </div>
                                            </td>
                                        @else
                                            <td>{{ $sub['name'] }}</td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $katCounts[$sub['id']] ?? 0 }}</td>
                                            <td class="whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-1">
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil" wire:click="katEditStart({{ $sub['id'] }}, @js($sub['name']))">Umbenennen</x-fa::button>
                                                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                        <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $sub['name'] }}"
                                                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                        <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="katLoeschen({{ $sub['id'] }})" wire:confirm="Unterkategorie „{{ $sub['name'] }}“ löschen?" class="{{ $menueKnopf }}">
                                                                @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Unterkategorie löschen
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="3"><x-fa::empty compact title="Noch keine Unterkategorien">Unten die erste anlegen.</x-fa::empty></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                        <label for="konzept-subkat-neu" class="sr-only">Neue Unterkategorie</label>
                        <x-fa::input id="konzept-subkat-neu" wire:model="neuSubKat" wire:keydown.enter="katNeuSub" placeholder="Neue Unterkategorie in {{ $selKat['name'] ?? '' }}" class="flex-1 min-w-0" />
                        <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="katNeuSub">Unterkategorie anlegen</x-fa::button>
                    </div>
                @else
                    <x-fa::empty compact icon="heroicon-o-cursor-arrow-rays" title="Keine Kategorie gewählt">Links eine Kategorie wählen, um ihre Unterkategorien zu sehen und zu pflegen.</x-fa::empty>
                @endif
            </x-fa::section>
        </div>
    @else
        @php($topKl = collect($klassen)->where('depth', 0)->values())
        @php($selKl = collect($klassen)->firstWhere('id', $klasseSelectedId))
        @php($subKl = $klasseSelectedId !== null ? collect($klassen)->where('parent_id', $klasseSelectedId)->values() : collect())
        <div class="flex flex-wrap gap-4 items-start">
            {{-- Ober-Klassen links --}}
            <x-fa::section title="Klassen" :meta="$topKl->count()" class="w-72 max-w-full shrink-0" data-konzept-kl-top>
                <div class="flex flex-col gap-0.5 -mx-1">
                    @forelse($topKl as $kl)
                        @php($kinder = collect($klassen)->where('parent_id', $kl['id'])->count())
                        @php($aktiv = $klasseSelectedId === $kl['id'])
                        <div wire:key="kltop-{{ $kl['id'] }}" class="{{ $zeileBasis }} pl-1 {{ $aktiv ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}">
                            @if($editKlasseId === $kl['id'])
                                <x-fa::input size="sm" wire:model="editKlasseName" wire:keydown.enter="klasseRename" wire:keydown.escape="$set('editKlasseId', null)" aria-label="Name der Klasse" class="flex-1 min-w-0" autofocus />
                                <x-fa::button size="sm" variant="primary" wire:click="klasseRename">Speichern</x-fa::button>
                            @else
                                <button type="button" wire:click="klasseWaehlen({{ $kl['id'] }})" @if($aktiv) aria-current="true" @endif
                                        class="flex-1 min-w-0 truncate text-left px-1.5 py-1.5 text-[length:var(--fa-text-md)] {{ $aktiv ? 'font-medium' : '' }}">{{ $kl['name'] }}</button>
                                <span class="shrink-0 text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]" title="Unterklassen">{{ $kinder }}</span>
                                <span class="shrink-0 flex opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                                    <x-fa::icon-button size="sm" icon="heroicon-o-pencil" label="Klasse umbenennen" wire:click="klasseEditStart({{ $kl['id'] }}, @js($kl['name']))" />
                                    <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Klasse löschen" wire:click="klasseLoeschen({{ $kl['id'] }})"
                                        wire:confirm="Klasse „{{ $kl['name'] }}“ löschen? Unterklassen rücken eine Ebene nach oben." />
                                </span>
                            @endif
                        </div>
                    @empty
                        <x-fa::empty compact title="Noch keine Klassen">Unten die erste Klasse anlegen.</x-fa::empty>
                    @endforelse
                </div>
                <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                    <label for="konzept-kl-neu" class="sr-only">Neue Klasse</label>
                    <x-fa::input id="konzept-kl-neu" size="sm" wire:model="neuTopKlasse" wire:keydown.enter="klasseNeuTop" placeholder="Neue Klasse" class="flex-1 min-w-0" />
                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="klasseNeuTop" title="Klasse anlegen">Anlegen</x-fa::button>
                </div>
            </x-fa::section>

            {{-- Unterklassen rechts --}}
            <x-fa::section title="Unterklassen" :meta="$selKl['name'] ?? null" class="flex-1 basis-[24rem] min-w-0" data-konzept-kl-sub>
                @if($klasseSelectedId !== null)
                    <div class="overflow-x-auto -mx-4">
                        <table class="fa-table">
                            <thead><tr><th class="w-full">Bezeichnung</th><th class="num">Konzepte</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                            <tbody>
                                @forelse($subKl as $sub)
                                    <tr wire:key="klsub-{{ $sub['id'] }}">
                                        @if($editKlasseId === $sub['id'])
                                            <td><x-fa::input size="sm" wire:model="editKlasseName" wire:keydown.enter="klasseRename" wire:keydown.escape="$set('editKlasseId', null)" aria-label="Name der Unterklasse" class="w-full min-w-40" autofocus /></td>
                                            <td></td>
                                            <td class="whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <x-fa::button size="sm" variant="ghost" wire:click="$set('editKlasseId', null)">Abbrechen</x-fa::button>
                                                    <x-fa::button size="sm" variant="primary" wire:click="klasseRename">Speichern</x-fa::button>
                                                </div>
                                            </td>
                                        @else
                                            <td>{{ $sub['name'] }}</td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ $klasseCounts[$sub['name']] ?? 0 }}</td>
                                            <td class="whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-1">
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil" wire:click="klasseEditStart({{ $sub['id'] }}, @js($sub['name']))">Umbenennen</x-fa::button>
                                                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                        <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $sub['name'] }}"
                                                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                        <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="klasseLoeschen({{ $sub['id'] }})" wire:confirm="Unterklasse „{{ $sub['name'] }}“ löschen?" class="{{ $menueKnopf }}">
                                                                @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Unterklasse löschen
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="3"><x-fa::empty compact title="Noch keine Unterklassen">Unten die erste anlegen.</x-fa::empty></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                        <label for="konzept-subkl-neu" class="sr-only">Neue Unterklasse</label>
                        <x-fa::input id="konzept-subkl-neu" wire:model="neuSubKlasse" wire:keydown.enter="klasseNeuSub" placeholder="Neue Unterklasse" class="flex-1 min-w-0" />
                        <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="klasseNeuSub">Unterklasse anlegen</x-fa::button>
                    </div>
                @else
                    <x-fa::empty compact icon="heroicon-o-cursor-arrow-rays" title="Keine Klasse gewählt">Links eine Klasse wählen, um ihre Unterklassen zu sehen und zu pflegen.</x-fa::empty>
                @endif
            </x-fa::section>
        </div>
    @endif
</div>
