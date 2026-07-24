{{-- Spec 18 — Produktion: Editor-Modal (Stammdaten / Ziele / Vorschau) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="produktion-editor" :title="$orderId === null ? 'Neuer Produktionsauftrag' : 'Produktionsauftrag bearbeiten'">
    <x-slot:footer>
        @if($fehler)<span class="text-[12px] text-rose-600 mr-auto">{{ $fehler }}</span>@endif
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-produktion-speichern>Speichern</button>
    </x-slot:footer>

    <x-foodalchemist::modal-section title="Stammdaten">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="{{ $label }}">Name <span class="text-rose-500">*</span></label>
                <input type="text" wire:model="name" placeholder="z. B. Sommerfest Vormittag" class="{{ $input }}" data-produktion-name />
            </div>
            <div>
                <label class="{{ $label }}">Produktionsdatum</label>
                <input type="date" wire:model="productionDate" class="{{ $input }}" data-produktion-datum />
            </div>
        </div>
        <div class="mt-3">
            <label class="{{ $label }}">Anlass</label>
            <input type="text" wire:model="reference" placeholder="z. B. Sommer-Buffet" class="{{ $input }}" data-produktion-anlass />
        </div>
        <div class="mt-3">
            <label class="{{ $label }}">Notiz</label>
            <textarea wire:model="note" rows="2" class="{{ $input }}"></textarea>
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Ziele">
        <div class="flex items-center gap-2 mb-2">
            <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs">
                <button type="button" wire:click="$set('zielTyp', 'concept')" class="px-3 py-1 rounded-md {{ $zielTyp === 'concept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Konzept</button>
                <button type="button" wire:click="$set('zielTyp', 'recipe')" class="px-3 py-1 rounded-md {{ $zielTyp === 'recipe' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Gericht</button>
                <button type="button" wire:click="$set('zielTyp', 'basisrezept')" class="px-3 py-1 rounded-md {{ $zielTyp === 'basisrezept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-basisrezept>Basisrezept</button>
                <button type="button" wire:click="$set('zielTyp', 'kapitel')" class="px-3 py-1 rounded-md {{ $zielTyp === 'kapitel' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-kapitel>Kapitel</button>
            </div>
        </div>

        @if($zielTyp === 'kapitel')
            {{-- P2: Foodbook-Kapitel als Ziel → beim Hinzufügen in eingefrorene Einzel-Ziele expandiert (V2). --}}
            <div class="space-y-2 mb-3" data-produktion-kapitel>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="{{ $label }}">Foodbook</label>
                        <select wire:model.live="auswahlFoodbookId" class="{{ $input }}" data-produktion-foodbook>
                            <option value="">— wählen —</option>
                            @foreach($foodbooks as $fb)
                                <option value="{{ $fb->id }}">{{ $fb->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Kapitel</label>
                        <select wire:model.live="auswahlChapterId" @disabled(! $auswahlFoodbookId) class="{{ $input }}" data-produktion-kapitel-select>
                            <option value="">— wählen —</option>
                            @foreach($kapitelBaum as $k)
                                <option value="{{ $k['id'] }}">{!! str_repeat('&nbsp;&nbsp;', $k['depth']) !!}{{ $k['title'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="w-28">
                    <label class="{{ $label }}">Personen</label>
                    <input type="number" min="1" wire:model="auswahlPersonen" class="{{ $input }}" data-produktion-kapitel-personen />
                </div>
                @if(! empty($variantGroups))
                    <div class="rounded-lg border border-black/5 bg-black/[0.02] p-2 space-y-2" data-produktion-varianten>
                        <p class="{{ $label }}">Varianten-Wahl (Wahl-Gruppen im Kapitel)</p>
                        @foreach($variantGroups as $g)
                            <select wire:model="variantChoices.{{ $g['group_id'] }}" class="{{ $input }}" wire:key="vg-{{ $g['group_id'] }}" data-produktion-variante="{{ $g['group_id'] }}">
                                @foreach($g['options'] as $opt)
                                    <option value="{{ $opt['block_id'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                        @endforeach
                    </div>
                @endif
                <button type="button" wire:click="zielHinzufuegen" class="{{ $btnGhost }}" data-produktion-ziel-hinzufuegen>+ Kapitel-Ziele hinzufügen</button>
            </div>
        @else
        <div class="flex items-end gap-2 mb-3">
            @if($zielTyp === 'concept')
                <div class="flex-1">
                    <label class="{{ $label }}">Konzept</label>
                    <select wire:model="auswahlConceptId" class="{{ $input }}" data-produktion-konzept>
                        <option value="">— wählen —</option>
                        @foreach($konzepte as $k)
                            <option value="{{ $k->id }}">{{ $k->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-28">
                    <label class="{{ $label }}">Personen</label>
                    <input type="number" min="1" wire:model="auswahlMenge" class="{{ $input }}" />
                </div>
            @else
                <div class="flex-1">
                    <label class="{{ $label }}">{{ $zielTyp === 'basisrezept' ? 'Basisrezept' : 'Gericht' }}</label>
                    <input type="search" wire:model.live.debounce.300ms="suche" placeholder="{{ $zielTyp === 'basisrezept' ? 'Basisrezept suchen …' : 'Gericht suchen …' }}" class="{{ $input }}" data-produktion-gericht-suche />
                    @if($treffer->isNotEmpty())
                        <div class="mt-1 rounded-lg border border-black/5 bg-white/80 max-h-40 overflow-y-auto">
                            @foreach($treffer as $t)
                                <button type="button" wire:key="tref-{{ $t->id }}" wire:click="$set('auswahlRecipeId', {{ $t->id }})"
                                    class="block w-full text-left px-2 py-1 text-[12px] {{ $auswahlRecipeId === $t->id ? 'bg-violet-500/10 text-violet-700' : 'text-gray-700 hover:bg-black/[0.03]' }}">{{ $t->name }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @if($zielTyp === 'basisrezept')
                    <div class="w-28">
                        <label class="{{ $label }}">{{ $basisEinheit === 'kg' ? 'Kilogramm' : 'Ansätze' }}</label>
                        <input type="number" min="0" step="0.1" wire:model="auswahlMenge" class="{{ $input }}" />
                    </div>
                    <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs self-end mb-0.5" data-produktion-basis-einheit>
                        <button type="button" wire:click="$set('basisEinheit', 'ansaetze')" class="px-2 py-1 rounded-md {{ $basisEinheit === 'ansaetze' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Ansätze</button>
                        <button type="button" wire:click="$set('basisEinheit', 'kg')" class="px-2 py-1 rounded-md {{ $basisEinheit === 'kg' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">kg</button>
                    </div>
                @else
                    <div class="w-28">
                        <label class="{{ $label }}">Portionen</label>
                        <input type="number" min="1" wire:model="auswahlMenge" class="{{ $input }}" />
                    </div>
                @endif
            @endif
            <button type="button" wire:click="zielHinzufuegen" class="{{ $btnGhost }}" data-produktion-ziel-hinzufuegen>+ Hinzufügen</button>
        </div>
        @endif

        <div class="space-y-1">
            @forelse($targets as $t)
                <div class="flex items-center justify-between gap-2 text-[12px] px-2 py-1 rounded-lg bg-black/[0.02]" wire:key="ziel-{{ $t['source_ref'] }}">
                    <span class="text-gray-800">{{ $t['label'] ?? '—' }}</span>
                    <div class="flex items-center gap-2">
                        @unless(str_contains($t['source_ref'], ':c'))
                            <button type="button" wire:click="zielBearbeiten('{{ $t['source_ref'] }}')" class="text-gray-400 hover:text-violet-600" title="Bearbeiten" data-produktion-ziel-bearbeiten>✎</button>
                        @endunless
                        <button type="button" wire:click="zielEntfernen('{{ $t['source_ref'] }}')" class="text-rose-500" data-produktion-ziel-entfernen>✕</button>
                    </div>
                </div>
            @empty
                <p class="text-[12px] text-gray-500">Noch keine Ziele — Konzept, Gericht, Basisrezept oder Foodbook-Kapitel wählen und hinzufügen.</p>
            @endforelse
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Vorschau">
        @if($vorschau === null)
            <p class="text-[12px] text-gray-500">Ziele hinzufügen, um die Ansätze-Vorschau zu sehen.</p>
        @else
            <table class="{{ $table }}">
                <thead><tr>
                    <th class="{{ $th }} text-left">Rezept</th>
                    <th class="{{ $th }} text-right">Ansätze</th>
                    <th class="{{ $th }} text-right">Portionen/kg</th>
                    <th class="{{ $th }} text-right">Arbeitszeit</th>
                </tr></thead>
                <tbody>
                    @foreach($vorschau['rezepte'] as $r)
                        <tr class="border-t border-black/5">
                            <td class="{{ $td }}">{{ $r['name'] }} @if($r['ist_basisrezept'])<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">Basisrezept</span>@endif</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ rtrim(rtrim(number_format($r['ansaetze'], 2, ',', '.'), '0'), ',') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">
                                @if($r['portionen'] !== null){{ $r['portionen'] }} Port.@elseif($r['produzierte_menge_kg'] !== null){{ number_format($r['produzierte_menge_kg'], 2, ',', '.') }} kg@else—@endif
                            </td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['arbeitszeit_min'] !== null ? $r['arbeitszeit_min'] . ' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @foreach($vorschau['warnungen'] as $w)
                <x-foodalchemist::alert tone="warning" class="mt-2">{{ $w }}</x-foodalchemist::alert>
            @endforeach
        @endif
    </x-foodalchemist::modal-section>
</x-foodalchemist::modal>
