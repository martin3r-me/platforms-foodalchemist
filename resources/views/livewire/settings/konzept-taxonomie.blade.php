{{-- Konzept-Taxonomie: Master-Detail wie Rezept-/VK-Taxonomie (2026-06-17) — Ober-Knoten links
     wählbar → Unter-Knoten rechts. 2 Ebenen, keine flache Gesamttabelle mehr. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($katAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($katHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif

    {{-- Achsen-Tabs --}}
    <div class="flex items-center gap-1.5">
        <button type="button" wire:click="setAchse('kategorie')"
                class="{{ $pill }} {{ $achse === 'kategorie' ? $variantPill['primary'] : $variantPill['secondary'] }}">Kategorien</button>
        <button type="button" wire:click="setAchse('klasse')"
                class="{{ $pill }} {{ $achse === 'klasse' ? $variantPill['primary'] : $variantPill['secondary'] }}">Klassen</button>
        <span class="text-[10px] text-gray-400 ml-2 leading-snug">Rein organisatorisch — Filter-/Gruppier-Achse im Concept-Browser &amp; Foodbook/Angebots-Picker, ohne Preis-Einfluss.</span>
    </div>

    @if($achse === 'kategorie')
        @php($topKats = collect($kategorien)->where('depth', 0)->values())
        @php($selKat = collect($kategorien)->firstWhere('id', $katSelectedId))
        @php($subKats = $katSelectedId !== null ? collect($kategorien)->where('parent_id', $katSelectedId)->values() : collect())
        <div class="flex gap-4 items-start">
            {{-- Ober-Kategorien links --}}
            <div class="w-80 shrink-0 {{ $card }} p-3 space-y-0.5" data-konzept-kat-top>
                <div class="{{ $label }} px-2 pb-2">Kategorien ({{ $topKats->count() }})</div>
                @forelse($topKats as $kat)
                    @php($kinder = collect($kategorien)->where('parent_id', $kat['id'])->count())
                    <div wire:key="ktop-{{ $kat['id'] }}" class="group flex items-center gap-1 rounded-lg {{ $katSelectedId === $kat['id'] ? $katAktiv : $katHover }}">
                        @if($editKatId === $kat['id'])
                            <input type="text" wire:model="editKatName" wire:keydown.enter="katRename" wire:keydown.escape="$set('editKatId', null)" class="{{ $input }} !py-0.5 flex-1" autofocus />
                            <button type="button" wire:click="katRename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0">OK</button>
                        @else
                            <button type="button" wire:click="katWaehlen({{ $kat['id'] }})" class="flex-1 min-w-0 truncate text-left px-2 py-1.5 text-xs">{{ $kat['name'] }}</button>
                            <span class="text-[11px] text-gray-400 shrink-0" title="Unterkategorien">{{ $kinder }}</span>
                            <button type="button" wire:click="katEditStart({{ $kat['id'] }}, @js($kat['name']))" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[11px] px-1" title="Umbenennen">✎</button>
                            <button type="button" wire:click="katLoeschen({{ $kat['id'] }})" wire:confirm="Kategorie „{{ $kat['name'] }}" löschen? (Unterkategorien & Concepts rücken zum Eltern)" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 text-[11px] px-1" title="Löschen">✕</button>
                        @endif
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400 px-2 py-2">Noch keine Kategorien.</p>
                @endforelse
                <div class="flex gap-1 pt-2 mt-1 border-t border-black/5 dark:border-white/10">
                    <input type="text" wire:model="neuTopKat" wire:keydown.enter="katNeuTop" placeholder="Neue Kategorie …" class="{{ $input }} !py-0.5" />
                    <button type="button" wire:click="katNeuTop" class="{{ $btnGhostXs }}" title="Ober-Kategorie anlegen">+</button>
                </div>
            </div>

            {{-- Unterkategorien rechts --}}
            <div class="flex-1 min-w-0">
                <div class="relative overflow-hidden {{ $card }}" data-konzept-kat-sub>
                    <div class="{{ $cardAccent }}"></div>
                    <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Unterkategorien</h3>
                        <span class="{{ $label }}">{{ $selKat['name'] ?? 'Kategorie wählen' }}</span>
                    </div>
                    @if($katSelectedId !== null)
                        <table class="{{ $table }}">
                            <thead><tr class="text-left">@foreach(['Bezeichnung', 'Concepts', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody>
                                @forelse($subKats as $sub)
                                    <tr wire:key="ksub-{{ $sub['id'] }}" class="{{ $tr }}">
                                        @if($editKatId === $sub['id'])
                                            <td class="{{ $td }}"><input type="text" wire:model="editKatName" wire:keydown.enter="katRename" wire:keydown.escape="$set('editKatId', null)" class="{{ $input }} !py-1" autofocus /></td>
                                            <td class="{{ $td }}"></td>
                                            <td class="{{ $td }} text-right whitespace-nowrap">
                                                <button type="button" wire:click="katRename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                                <button type="button" wire:click="$set('editKatId', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                            </td>
                                        @else
                                            <td class="{{ $td }} text-gray-900 dark:text-gray-100">{{ $sub['name'] }}</td>
                                            <td class="{{ $td }} text-gray-500">{{ $katCounts[$sub['id']] ?? 0 }}</td>
                                            <td class="{{ $td }} text-right whitespace-nowrap">
                                                <button type="button" wire:click="katEditStart({{ $sub['id'] }}, @js($sub['name']))" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                                <button type="button" wire:click="katLoeschen({{ $sub['id'] }})" wire:confirm="Unterkategorie „{{ $sub['name'] }}" löschen?" class="{{ $btnGhostXs }} text-red-500">Löschen</button>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="{{ $td }} text-gray-400 py-4 text-center">Noch keine Unterkategorien — unten anlegen.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/10 flex items-center gap-1.5">
                            <input type="text" wire:model="neuSubKat" wire:keydown.enter="katNeuSub" placeholder="Neue Unterkategorie in „{{ $selKat['name'] ?? '' }}" …" class="{{ $input }} flex-1" />
                            <button type="button" wire:click="katNeuSub" class="{{ $btnGhostXs }}">+ Unterkategorie</button>
                        </div>
                    @else
                        <div class="px-5 pb-5 text-xs text-gray-400">Links eine Kategorie wählen, um ihre Unterkategorien zu sehen und zu pflegen.</div>
                    @endif
                </div>
            </div>
        </div>
    @else
        @php($topKl = collect($klassen)->where('depth', 0)->values())
        @php($selKl = collect($klassen)->firstWhere('id', $klasseSelectedId))
        @php($subKl = $klasseSelectedId !== null ? collect($klassen)->where('parent_id', $klasseSelectedId)->values() : collect())
        <div class="flex gap-4 items-start">
            {{-- Ober-Klassen links --}}
            <div class="w-80 shrink-0 {{ $card }} p-3 space-y-0.5" data-konzept-kl-top>
                <div class="{{ $label }} px-2 pb-2">Klassen ({{ $topKl->count() }})</div>
                @forelse($topKl as $kl)
                    @php($kinder = collect($klassen)->where('parent_id', $kl['id'])->count())
                    <div wire:key="kltop-{{ $kl['id'] }}" class="group flex items-center gap-1 rounded-lg {{ $klasseSelectedId === $kl['id'] ? $katAktiv : $katHover }}">
                        @if($editKlasseId === $kl['id'])
                            <input type="text" wire:model="editKlasseName" wire:keydown.enter="klasseRename" wire:keydown.escape="$set('editKlasseId', null)" class="{{ $input }} !py-0.5 flex-1" autofocus />
                            <button type="button" wire:click="klasseRename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0">OK</button>
                        @else
                            <button type="button" wire:click="klasseWaehlen({{ $kl['id'] }})" class="flex-1 min-w-0 truncate text-left px-2 py-1.5 text-xs">{{ $kl['name'] }}</button>
                            <span class="text-[11px] text-gray-400 shrink-0" title="Unterklassen">{{ $kinder }}</span>
                            <button type="button" wire:click="klasseEditStart({{ $kl['id'] }}, @js($kl['name']))" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[11px] px-1" title="Umbenennen">✎</button>
                            <button type="button" wire:click="klasseLoeschen({{ $kl['id'] }})" wire:confirm="Klasse „{{ $kl['name'] }}" löschen? (Unterklassen rücken zum Eltern)" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 text-[11px] px-1" title="Löschen">✕</button>
                        @endif
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400 px-2 py-2">Noch keine Klassen.</p>
                @endforelse
                <div class="flex gap-1 pt-2 mt-1 border-t border-black/5 dark:border-white/10">
                    <input type="text" wire:model="neuTopKlasse" wire:keydown.enter="klasseNeuTop" placeholder="Neue Klasse …" class="{{ $input }} !py-0.5" />
                    <button type="button" wire:click="klasseNeuTop" class="{{ $btnGhostXs }}" title="Ober-Klasse anlegen">+</button>
                </div>
            </div>

            {{-- Unterklassen rechts --}}
            <div class="flex-1 min-w-0">
                <div class="relative overflow-hidden {{ $card }}" data-konzept-kl-sub>
                    <div class="{{ $cardAccent }}"></div>
                    <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Unterklassen</h3>
                        <span class="{{ $label }}">{{ $selKl['name'] ?? 'Klasse wählen' }}</span>
                    </div>
                    @if($klasseSelectedId !== null)
                        <table class="{{ $table }}">
                            <thead><tr class="text-left">@foreach(['Bezeichnung', 'Concepts', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody>
                                @forelse($subKl as $sub)
                                    <tr wire:key="klsub-{{ $sub['id'] }}" class="{{ $tr }}">
                                        @if($editKlasseId === $sub['id'])
                                            <td class="{{ $td }}"><input type="text" wire:model="editKlasseName" wire:keydown.enter="klasseRename" wire:keydown.escape="$set('editKlasseId', null)" class="{{ $input }} !py-1" autofocus /></td>
                                            <td class="{{ $td }}"></td>
                                            <td class="{{ $td }} text-right whitespace-nowrap">
                                                <button type="button" wire:click="klasseRename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                                <button type="button" wire:click="$set('editKlasseId', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                            </td>
                                        @else
                                            <td class="{{ $td }} text-gray-900 dark:text-gray-100">{{ $sub['name'] }}</td>
                                            <td class="{{ $td }} text-gray-500">{{ $klasseCounts[$sub['name']] ?? 0 }}</td>
                                            <td class="{{ $td }} text-right whitespace-nowrap">
                                                <button type="button" wire:click="klasseEditStart({{ $sub['id'] }}, @js($sub['name']))" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                                <button type="button" wire:click="klasseLoeschen({{ $sub['id'] }})" wire:confirm="Unterklasse „{{ $sub['name'] }}" löschen?" class="{{ $btnGhostXs }} text-red-500">Löschen</button>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="{{ $td }} text-gray-400 py-4 text-center">Noch keine Unterklassen — unten anlegen.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/10 flex items-center gap-1.5">
                            <input type="text" wire:model="neuSubKlasse" wire:keydown.enter="klasseNeuSub" placeholder="Neue Unterklasse …" class="{{ $input }} flex-1" />
                            <button type="button" wire:click="klasseNeuSub" class="{{ $btnGhostXs }}">+ Unterklasse</button>
                        </div>
                    @else
                        <div class="px-5 pb-5 text-xs text-gray-400">Links eine Klasse wählen, um ihre Unterklassen zu sehen und zu pflegen.</div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
