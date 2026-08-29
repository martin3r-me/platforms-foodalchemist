{{-- Spec 33 P2: Pflege der Betriebe/Standorte (Outlets).
     Die Tabelle gibt es seit Spec 19, diese Oberfläche nicht — deshalb war sie leer und die
     Betriebsbrille des Controllings hätte nichts anzuzeigen gehabt. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4" data-settings-betriebe>

    <p class="text-[11px] text-gray-500 max-w-2xl">
        Standorte, Filialen oder Ausgabestellen. Sie tragen die <strong>Betriebsbrille</strong> im
        Controlling — die Antwort auf „welcher Betrieb fährt gerade welche Karte, welchen Plan,
        welches Foodbook". Ohne gepflegte Betriebe bleibt diese Sicht leer.
        Die Zuordnung an der einzelnen Ausgabe ist immer <strong>optional</strong>.
    </p>

    {{-- Anlegen --}}
    <div class="flex items-end gap-2">
        <div>
            <label class="{{ $label }} block mb-1">Neuer Betrieb</label>
            <input type="text" wire:model="neuName" wire:keydown.enter="anlegen"
                   placeholder="z. B. Kantine Nord" class="{{ $input }} !w-64" data-betrieb-neu />
        </div>
        <button type="button" wire:click="anlegen" class="{{ $btnPrimary }}" @disabled(trim($neuName) === '')>Anlegen</button>
        @if($fehler)<span class="text-[11px] text-rose-700 pb-2">{{ $fehler }}</span>@endif
    </div>

    @if($betriebe->isEmpty())
        <p class="text-xs text-gray-500">
            Noch kein Betrieb angelegt. Solange hier nichts steht, kann keine Ausgabe einem
            Standort zugeordnet werden.
        </p>
    @else
        <table class="{{ $table }}">
            <thead>
                <tr class="text-left">
                    @foreach(['Betrieb', 'Farbe', 'Reihenfolge', 'In Verwendung', ''] as $h)
                        <th class="{{ $th }}">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($betriebe as $b)
                    <tr class="{{ $tr }} {{ $b->is_inactive ? 'opacity-50' : '' }}" wire:key="outlet-{{ $b->id }}">
                        @if($editId === $b->id)
                            <td class="{{ $td }}"><input type="text" wire:model="form.name" class="{{ $input }} !py-1" /></td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.color" placeholder="#6d28d9" class="{{ $input }} !py-1 !w-24" /></td>
                            <td class="{{ $td }}"><input type="number" wire:model="form.sort_order" class="{{ $input }} !py-1 !w-20" /></td>
                            <td class="{{ $td }}"></td>
                            <td class="{{ $td }} whitespace-nowrap">
                                <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                                <button type="button" wire:click="abbrechen" class="{{ $btnGhostXs }}">Abbrechen</button>
                            </td>
                        @else
                            <td class="{{ $td }} font-medium text-gray-900">
                                {{ $b->name }}
                                @if($b->is_inactive)<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">inaktiv</span>@endif
                            </td>
                            <td class="{{ $td }}">
                                @if($b->color)
                                    <span class="inline-flex items-center gap-1.5 text-[11px] text-gray-600">
                                        <span class="inline-block w-3 h-3 rounded" style="background-color: {{ $b->color }}"></span>{{ $b->color }}
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-[11px] text-gray-500">{{ $b->sort_order }}</td>
                            {{-- Zeigt, was eine Deaktivierung berührt — deshalb wird hier auch nicht gelöscht. --}}
                            <td class="{{ $td }} text-[11px] text-gray-600">
                                @php($n = $nutzung[$b->id] ?? [])
                                {{ $n === [] ? '—' : collect($n)->map(fn ($z, $k) => $z . ' ' . $k)->implode(' · ') }}
                            </td>
                            <td class="{{ $td }} whitespace-nowrap">
                                <button type="button" wire:click="edit({{ $b->id }})" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                <button type="button" wire:click="aktivUmschalten({{ $b->id }})" class="{{ $btnGhostXs }}">{{ $b->is_inactive ? 'aktivieren' : 'deaktivieren' }}</button>
                            </td>
                        @endif
                    </tr>
                    @if($editId === $b->id)
                        <tr wire:key="outlet-ov-{{ $b->id }}">
                            <td colspan="5" class="{{ $td }}" style="background-color:#f5f3ff">
                                <div class="text-[11px] font-medium text-purple-800 mb-1">Kalkulations-Override für „{{ $b->name }}" — leer = erbt vom Team</div>
                                <div class="flex flex-wrap gap-3">
                                    @foreach(['margin_pct'=>'Marge %','target_food_cost_pct'=>'Ziel-WE %','stundensatz_eur'=>'Stundensatz €/h','hk2_surcharge_pct'=>'Material-GK %','labor_overhead_pct'=>'Lohnneben. %'] as $ovKey => $ovLab)
                                        <label class="text-[11px] text-gray-600">{{ $ovLab }}
                                            <input type="number" step="0.01" min="0" wire:model="overrides.{{ $ovKey }}" placeholder="erbt" class="{{ $input }} !py-1 !w-24 block" />
                                        </label>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <p class="text-[10px] text-gray-500">
            Betriebe werden nicht gelöscht, sondern deaktiviert — an ihnen hängen Ausgaben und
            Kapitel, und eine Löschung würde diese Zuordnungen stillschweigend kappen.
        </p>
    @endif
</div>
