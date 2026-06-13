{{-- Konzept-Taxonomie: Kategorie- + Klasse-Baum, ausklappbar (Basisrezepte-Stil), inline-CRUD --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif

    <div class="{{ $card }} p-4">
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Konzept-Taxonomie</h3>
        <p class="text-[11px] text-gray-400 mt-1">
            Zwei Klassifikations-Bäume über deinen Concepts — <strong>Kategorie</strong> und <strong>Klasse</strong>, je
            mit Eltern/Kindern. Rein organisatorisch: Filter- und Gruppier-Achse im Concept-Browser (und später im
            Foodbook-Picker), <em>ohne</em> Auswirkung auf Preise oder Kalkulation. Knoten markieren ⇒ neue Einträge
            entstehen als Unterknoten; Chevron klappt auf/zu.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

        {{-- ── Kategorie-Baum ── --}}
        <div class="{{ $card }} p-4 space-y-2" data-konzept-kategorien>
            <x-foodalchemist::tree heading="Kategorien">
                @forelse($kategorien as $kat)
                    <x-foodalchemist::tree-node
                        :node-id="$kat['id']" :depth="$kat['depth']" :ancestors="$kat['ancestors']"
                        :has-children="$kat['has_children']" :count="$katCounts[$kat['id']] ?? null"
                        :active="$katParent === $kat['id']">
                        @if($editKatId === $kat['id'])
                            <input type="text" wire:model="editKatName" wire:keydown.enter="katRename" wire:blur="katRename"
                                   class="{{ $input }} py-0.5 flex-1" autofocus />
                        @else
                            <button type="button" wire:click="katWaehlen({{ $kat['id'] }})"
                                    class="flex-1 min-w-0 text-left truncate px-1 py-0.5">{{ $kat['name'] }}</button>
                            <button type="button" wire:click="katEditStart({{ $kat['id'] }}, @js($kat['name']))"
                                    class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[11px]" title="umbenennen">✎</button>
                            <button type="button" wire:click="katLoeschen({{ $kat['id'] }})"
                                    wire:confirm="Kategorie löschen? (Untergruppen &amp; Concepts rücken zum Eltern)"
                                    class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 text-[11px]" title="löschen">✕</button>
                        @endif
                    </x-foodalchemist::tree-node>
                @empty
                    <p class="px-1 py-2 text-[11px] text-gray-400">Noch keine Kategorien.</p>
                @endforelse

                <div class="flex gap-1 pt-2 mt-1 border-t border-black/5 dark:border-white/10">
                    <input type="text" wire:model="neueKategorie" wire:keydown.enter="katNeu"
                           placeholder="{{ $katParent ? 'Neue Unterkategorie …' : 'Neue Kategorie …' }}" class="{{ $input }} py-0.5" />
                    <button type="button" wire:click="katNeu" class="{{ $btnGhostXs }}" title="Kategorie anlegen">+</button>
                </div>
            </x-foodalchemist::tree>
        </div>

        {{-- ── Klasse-Baum ── --}}
        <div class="{{ $card }} p-4 space-y-2" data-konzept-klassen>
            <x-foodalchemist::tree heading="Klassen">
                @forelse($klassen as $kl)
                    <x-foodalchemist::tree-node
                        :node-id="$kl['id']" :depth="$kl['depth']" :ancestors="$kl['ancestors']"
                        :has-children="$kl['has_children']" :count="$klasseCounts[$kl['name']] ?? null"
                        :active="$klasseParent === $kl['id']">
                        @if($editKlasseId === $kl['id'])
                            <input type="text" wire:model="editKlasseName" wire:keydown.enter="klasseRename" wire:blur="klasseRename"
                                   class="{{ $input }} py-0.5 flex-1" autofocus />
                        @else
                            <button type="button" wire:click="klasseWaehlen({{ $kl['id'] }})"
                                    class="flex-1 min-w-0 text-left truncate px-1 py-0.5">{{ $kl['name'] }}</button>
                            <button type="button" wire:click="klasseEditStart({{ $kl['id'] }}, @js($kl['name']))"
                                    class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[11px]" title="umbenennen">✎</button>
                            <button type="button" wire:click="klasseLoeschen({{ $kl['id'] }})"
                                    wire:confirm="Klasse löschen? (Unterklassen rücken zum Eltern; zugewiesene Concepts behalten ihren Text)"
                                    class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 text-[11px]" title="löschen">✕</button>
                        @endif
                    </x-foodalchemist::tree-node>
                @empty
                    <p class="px-1 py-2 text-[11px] text-gray-400">Noch keine Klassen.</p>
                @endforelse

                <div class="flex gap-1 pt-2 mt-1 border-t border-black/5 dark:border-white/10">
                    <input type="text" wire:model="neueKlasse" wire:keydown.enter="klasseNeu"
                           placeholder="{{ $klasseParent ? 'Neue Unterklasse …' : 'Neue Klasse …' }}" class="{{ $input }} py-0.5" />
                    <button type="button" wire:click="klasseNeu" class="{{ $btnGhostXs }}" title="Klasse anlegen">+</button>
                </div>
            </x-foodalchemist::tree>
        </div>

    </div>
</div>
