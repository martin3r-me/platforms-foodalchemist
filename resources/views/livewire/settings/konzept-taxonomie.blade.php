{{-- Konzept-Taxonomie: Master-Detail wie Rezept-Taxonomie — Achse links (Kategorie/Klasse), Tabelle rechts --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($katAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($katHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif

    <div class="flex gap-4 items-start">
        {{-- Achse links --}}
        <div class="w-64 shrink-0 {{ $card }} p-3 space-y-0.5" data-konzept-achsen>
            <div class="{{ $label }} px-2 pb-2">Achsen</div>
            <button type="button" wire:click="setAchse('kategorie')"
                    class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $achse === 'kategorie' ? $katAktiv : $katHover }}">
                <span>Kategorien</span><span class="text-[11px] text-gray-400">{{ count($kategorien) }}</span>
            </button>
            <button type="button" wire:click="setAchse('klasse')"
                    class="w-full flex items-center justify-between px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $achse === 'klasse' ? $katAktiv : $katHover }}">
                <span>Klassen</span><span class="text-[11px] text-gray-400">{{ count($klassen) }}</span>
            </button>
            <p class="text-[10px] text-gray-400 px-2 pt-2 leading-snug">Rein organisatorisch — Filter-/Gruppier-Achse im Concept-Browser (und Foodbook-Picker), ohne Auswirkung auf Preise.</p>
        </div>

        {{-- Detail rechts --}}
        <div class="flex-1 min-w-0 space-y-4">
            @if($achse === 'kategorie')
                {{-- Kategorien-Tabelle --}}
                <div class="relative overflow-hidden {{ $card }}" data-konzept-kategorien>
                    <div class="{{ $cardAccent }}"></div>
                    <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kategorien</h3>
                        <span class="{{ $label }}">{{ count($kategorien) }} gesamt</span>
                    </div>
                    <table class="{{ $table }}">
                        <thead><tr class="text-left">@foreach(['Bezeichnung', 'Concepts', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse($kategorien as $kat)
                                <tr wire:key="kkat-{{ $kat['id'] }}" class="{{ $tr }}">
                                    @if($editKatId === $kat['id'])
                                        <td class="{{ $td }}"><input type="text" wire:model="editKatName" wire:keydown.enter="katRename" wire:keydown.escape="$set('editKatId', null)" class="{{ $input }} !py-1" autofocus /></td>
                                        <td class="{{ $td }}"></td>
                                        <td class="{{ $td }} text-right whitespace-nowrap">
                                            <button type="button" wire:click="katRename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                            <button type="button" wire:click="$set('editKatId', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                        </td>
                                    @else
                                        <td class="{{ $td }} text-gray-900 dark:text-gray-100"><span style="padding-left: {{ $kat['depth'] * 14 }}px">{{ $kat['depth'] > 0 ? '└ ' : '' }}{{ $kat['name'] }}</span></td>
                                        <td class="{{ $td }} text-gray-500">{{ $katCounts[$kat['id']] ?? 0 }}</td>
                                        <td class="{{ $td }} text-right whitespace-nowrap">
                                            <button type="button" wire:click="katEditStart({{ $kat['id'] }}, @js($kat['name']))" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                            <button type="button" wire:click="katLoeschen({{ $kat['id'] }})" wire:confirm="Kategorie „{{ $kat['name'] }}" löschen? (Untergruppen & Concepts rücken zum Eltern)" class="{{ $btnGhostXs }} text-red-500">Löschen</button>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="3" class="{{ $td }} text-gray-400 py-4 text-center">Noch keine Kategorien.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="{{ $card }} p-5" data-konzept-kategorie-neu>
                    <h4 class="{{ $label }} mb-3">Neue Kategorie</h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <input type="text" wire:model="neueKategorie" wire:keydown.enter="katNeu" placeholder="Bezeichnung" class="{{ $input }}" />
                        <select wire:model="katParent" class="{{ $input }}">
                            <option value="">— oberste Ebene —</option>
                            @foreach($kategorien as $kat)<option value="{{ $kat['id'] }}">{{ $kat['label'] }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="katNeu" class="{{ $btnPrimary }} justify-center">Anlegen</button>
                    </div>
                </div>
            @else
                {{-- Klassen-Tabelle --}}
                <div class="relative overflow-hidden {{ $card }}" data-konzept-klassen>
                    <div class="{{ $cardAccent }}"></div>
                    <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Klassen</h3>
                        <span class="{{ $label }}">{{ count($klassen) }} gesamt</span>
                    </div>
                    <table class="{{ $table }}">
                        <thead><tr class="text-left">@foreach(['Bezeichnung', 'Concepts', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse($klassen as $kl)
                                <tr wire:key="kkl-{{ $kl['id'] }}" class="{{ $tr }}">
                                    @if($editKlasseId === $kl['id'])
                                        <td class="{{ $td }}"><input type="text" wire:model="editKlasseName" wire:keydown.enter="klasseRename" wire:keydown.escape="$set('editKlasseId', null)" class="{{ $input }} !py-1" autofocus /></td>
                                        <td class="{{ $td }}"></td>
                                        <td class="{{ $td }} text-right whitespace-nowrap">
                                            <button type="button" wire:click="klasseRename" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">Speichern</button>
                                            <button type="button" wire:click="$set('editKlasseId', null)" class="{{ $btnGhostXs }}">Abbrechen</button>
                                        </td>
                                    @else
                                        <td class="{{ $td }} text-gray-900 dark:text-gray-100"><span style="padding-left: {{ $kl['depth'] * 14 }}px">{{ $kl['depth'] > 0 ? '└ ' : '' }}{{ $kl['name'] }}</span></td>
                                        <td class="{{ $td }} text-gray-500">{{ $klasseCounts[$kl['name']] ?? 0 }}</td>
                                        <td class="{{ $td }} text-right whitespace-nowrap">
                                            <button type="button" wire:click="klasseEditStart({{ $kl['id'] }}, @js($kl['name']))" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                            <button type="button" wire:click="klasseLoeschen({{ $kl['id'] }})" wire:confirm="Klasse „{{ $kl['name'] }}" löschen? (Unterklassen rücken zum Eltern)" class="{{ $btnGhostXs }} text-red-500">Löschen</button>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="3" class="{{ $td }} text-gray-400 py-4 text-center">Noch keine Klassen.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="{{ $card }} p-5" data-konzept-klasse-neu>
                    <h4 class="{{ $label }} mb-3">Neue Klasse</h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <input type="text" wire:model="neueKlasse" wire:keydown.enter="klasseNeu" placeholder="Bezeichnung" class="{{ $input }}" />
                        <select wire:model="klasseParent" class="{{ $input }}">
                            <option value="">— oberste Ebene —</option>
                            @foreach($klassen as $kl)<option value="{{ $kl['id'] }}">{{ $kl['label'] }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="klasseNeu" class="{{ $btnPrimary }} justify-center">Anlegen</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
