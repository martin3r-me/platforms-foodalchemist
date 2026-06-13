{{-- M1-04: Rezept-Taxonomie — ausklappbarer HG→Kategorie-Baum (Basisrezepte-Look), rechts Edit-Panel --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif

    <div class="flex gap-4 items-start">
        {{-- Baum: Hauptgruppen → Kategorien (ausklappbar) --}}
        <div class="w-96 shrink-0 {{ $card }} p-3" data-taxonomie-hg>
            <x-foodalchemist::tree heading="Hauptgruppen ({{ $hauptgruppen->count() }})" :initial-collapsed="$initialCollapsed">
                @foreach($baum as $node)
                    @if($node['kind'] === 'hg')
                        <x-foodalchemist::tree-node
                            :node-id="$node['id']" :depth="0" :has-children="$node['has_children']"
                            :count="$node['count']" :active="$hauptgruppeId === $node['hg_id']">
                            <button type="button" wire:click="waehleHg({{ $node['hg_id'] }})"
                                    x-on:click="toggle(@js($node['id']))"
                                    class="flex-1 min-w-0 text-left truncate px-1 py-0.5 font-medium">{{ $node['name'] }}</button>
                        </x-foodalchemist::tree-node>
                    @else
                        <x-foodalchemist::tree-node
                            :node-id="$node['id']" :depth="1" :ancestors="$node['ancestors']" :has-children="false"
                            :count="$node['count']" :active="$editId === $node['kat_id']">
                            <button type="button" wire:click="edit({{ $node['kat_id'] }})"
                                    class="flex-1 min-w-0 text-left truncate px-1 py-0.5">
                                {{ $node['name'] }}
                                @if($node['technik'])<span class="text-[10px] text-gray-400 ml-1">· {{ $node['technik'] }}</span>@endif
                            </button>
                            @if($node['darf_edit'])
                                <button type="button" wire:click="delete({{ $node['kat_id'] }})"
                                        @if($node['count'] > 0) disabled title="Hat {{ $node['count'] }} Rezepte — erst mergen/umhängen (AT-D1-02)" @endif
                                        wire:confirm="Kategorie „{{ $node['name'] }}" löschen?"
                                        class="shrink-0 opacity-0 group-hover:opacity-100 text-[11px] {{ $node['count'] > 0 ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-red-500' }}"
                                        title="löschen">✕</button>
                            @endif
                        </x-foodalchemist::tree-node>
                    @endif
                @endforeach

                <div class="flex gap-1 pt-2 mt-1 border-t border-black/5 dark:border-white/10" data-taxonomie-hg-neu>
                    <input type="text" wire:model="neueHauptgruppe" wire:keydown.enter="hgNeu"
                           placeholder="Neue Hauptgruppe …" class="{{ $input }} py-0.5" />
                    <button type="button" wire:click="hgNeu" class="{{ $btnGhostXs }}" title="Hauptgruppe anlegen">+</button>
                </div>
            </x-foodalchemist::tree>
        </div>

        {{-- Detail rechts: Kategorie bearbeiten ODER neue Kategorie in der gewählten HG --}}
        <div class="flex-1 min-w-0 space-y-4">
            @if($editId !== null)
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" data-taxonomie-edit>
                    <div class="{{ $cardAccent }}"></div>
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kategorie bearbeiten</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Bezeichnung</label>
                            <input type="text" wire:model="form.bezeichnung" wire:keydown.enter="save" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Technik</label>
                            <input type="text" wire:model="form.technik" wire:keydown.enter="save" class="{{ $input }}" placeholder="optional" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Sort</label>
                            <input type="number" wire:model="form.sort_order" class="{{ $input }}" />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="save" class="{{ $btnPrimary }}">Speichern</button>
                        <button type="button" wire:click="$set('editId', null)" class="{{ $btnGhost }}">Abbrechen</button>
                    </div>
                </div>
            @else
                <div class="{{ $card }} p-5" data-taxonomie-neu>
                    <h4 class="{{ $label }} mb-3">Neue Kategorie
                        @if($hauptgruppeId){{ '· in ' . optional($hauptgruppen->firstWhere('id', $hauptgruppeId))->bezeichnung }}@endif
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <input type="text" wire:model="neu.bezeichnung" placeholder="Bezeichnung" class="{{ $input }}" />
                        <input type="text" wire:model="neu.technik" placeholder="Technik (optional)" class="{{ $input }}" />
                        <input type="number" wire:model="neu.sort_order" placeholder="Sort" class="{{ $input }}" />
                        <button type="button" wire:click="create" class="{{ $btnPrimary }} justify-center">Anlegen</button>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-2">Hauptgruppe links wählen (= Ziel), Knoten aufklappen mit dem Pfeil. Kategorie anklicken zum Bearbeiten.</p>
                </div>
            @endif
        </div>
    </div>
</div>
