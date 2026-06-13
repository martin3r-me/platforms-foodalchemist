{{-- M-K10 / Doc 16 §11: Standalone Kalkulations-Composer — Bibliothek + Positions-Editor --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($typFarbe = ['gericht' => 'primary', 'basisrezept' => 'info', 'gp' => 'success', 'frei' => 'secondary'])
@php($typLabel = ['gericht' => 'Gericht', 'basisrezept' => 'Basisrezept', 'gp' => 'Grundprodukt', 'frei' => 'Frei'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Kalkulator" icon="heroicon-o-calculator" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-4 pt-1">

            {{-- ── Bibliothek ─────────────────────────────────────────────── --}}
            <div class="relative overflow-hidden {{ $card }} self-start">
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center justify-between px-4 py-3 border-b border-black/5 dark:border-white/10">
                    <p class="{{ $label }}">Kalkulationen</p>
                    <button type="button" wire:click="neueKalkulation" class="{{ $btnGhostXs }}">+ Neu</button>
                </div>
                <div class="divide-y divide-black/5 dark:divide-white/10 max-h-[70vh] overflow-y-auto">
                    @forelse($kalkulationen as $k)
                        <button type="button" wire:key="kalk-{{ $k->id }}" wire:click="waehle({{ $k->id }})"
                            class="w-full text-left px-4 py-2.5 transition-colors {{ $selectedId === $k->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : 'hover:bg-black/[0.02] dark:hover:bg-white/5' }}">
                            <div class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate">{{ $k->titel }}</div>
                            <div class="text-[10px] text-gray-400">{{ $k->positionen_count }} {{ $k->positionen_count === 1 ? 'Position' : 'Positionen' }}</div>
                        </button>
                    @empty
                        <p class="px-4 py-8 text-center text-xs text-gray-400">Noch keine Kalkulation.<br>Mit <strong>+ Neu</strong> anlegen.</p>
                    @endforelse
                </div>
            </div>

            {{-- ── Editor ─────────────────────────────────────────────────── --}}
            @if($aktiv === null)
                <div class="relative overflow-hidden {{ $card }} flex items-center justify-center min-h-[40vh]">
                    <p class="text-sm text-gray-400">Links eine Kalkulation wählen oder <strong>+ Neu</strong> anlegen.</p>
                </div>
            @else
                <div class="space-y-4">
                    {{-- Kopf --}}
                    <div class="relative overflow-hidden {{ $card }} p-4">
                        <div class="{{ $cardAccent }}"></div>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex-1 min-w-48">
                                <label class="{{ $label }}">Titel</label>
                                <input type="text" wire:model="titel" class="{{ $input }}" placeholder="Kalkulation …" />
                            </div>
                            <div class="w-28">
                                <label class="{{ $label }}">Marge-Override (%)</label>
                                <input type="number" min="0" step="0.5" wire:model="margeOverride" class="{{ $input }} text-right tabular-nums" placeholder="Team" />
                            </div>
                            <button type="button" wire:click="speichereKopf" class="{{ $btnPrimary }}">Speichern</button>
                            <button type="button" wire:click="loeschen({{ $aktiv->id }})" wire:confirm="Diese Kalkulation löschen?" class="{{ $btnGhost }} !text-red-500">Löschen</button>
                            @if($meldung)<span class="text-[11px] text-emerald-600 dark:text-emerald-400">{{ $meldung }}</span>@endif
                        </div>
                        <div class="mt-3">
                            <label class="{{ $label }}">Notiz</label>
                            <input type="text" wire:model="note" class="{{ $input }}" placeholder="optional …" />
                        </div>
                    </div>

                    {{-- Positionen --}}
                    <div class="relative overflow-hidden {{ $card }}">
                        <div class="{{ $cardAccent }}"></div>
                        <div class="overflow-x-auto">
                            <table class="{{ $table }}">
                                <thead><tr class="text-left">
                                    @foreach([['Typ',''], ['Position','w-full'], ['Menge','text-right'], ['Einheit',''], ['Einzel-EK','text-right'], ['= Wareneinsatz','text-right'], ['min','text-right'], ['',''] ] as [$h, $a])
                                        <th class="{{ $th }} {{ $a }}">{{ $h }}</th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @forelse($berechnung['positionen'] as $p)
                                        <tr wire:key="pos-{{ $p['id'] }}" class="{{ $tr }}">
                                            <td class="{{ $td }}"><span class="{{ $pill }} {{ $variantPill[$typFarbe[$p['typ']]] }}">{{ $typLabel[$p['typ']] }}</span></td>
                                            <td class="{{ $td }} w-full">
                                                <input type="text" value="{{ $p['label'] }}" wire:change="updatePos({{ $p['id'] }}, 'label', $event.target.value)"
                                                    class="{{ $input }} !py-1" />
                                            </td>
                                            <td class="{{ $td }} text-right">
                                                <input type="number" min="0" step="0.001" value="{{ rtrim(rtrim(number_format($p['menge'], 3, '.', ''), '0'), '.') }}"
                                                    wire:change="updatePos({{ $p['id'] }}, 'menge', $event.target.value)"
                                                    class="{{ $input }} !py-1 !w-20 text-right tabular-nums" />
                                            </td>
                                            <td class="{{ $td }}">
                                                <input type="text" value="{{ $p['einheit'] }}" wire:change="updatePos({{ $p['id'] }}, 'einheit', $event.target.value)"
                                                    class="{{ $input }} !py-1 !w-20" placeholder="—" />
                                            </td>
                                            <td class="{{ $td }} text-right">
                                                <input type="number" min="0" step="0.0001" value="{{ rtrim(rtrim(number_format($p['einzel_ek'], 4, '.', ''), '0'), '.') }}"
                                                    wire:change="updatePos({{ $p['id'] }}, 'einzel_ek', $event.target.value)"
                                                    class="{{ $input }} !py-1 !w-24 text-right tabular-nums" />
                                            </td>
                                            <td class="{{ $td }} text-right tabular-nums font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ number_format($p['wareneinsatz'], 2, ',', '.') }} €</td>
                                            <td class="{{ $td }} text-right tabular-nums text-gray-400">{{ $p['arbeitszeit_min'] !== null ? $p['arbeitszeit_min'] : '—' }}</td>
                                            <td class="{{ $td }} whitespace-nowrap text-right">
                                                @if($p['typ'] !== 'frei')
                                                    <button type="button" wire:click="aktualisierePos({{ $p['id'] }})" title="Snapshot neu ziehen" class="text-gray-400 hover:text-violet-500 mr-1">↻</button>
                                                @endif
                                                <button type="button" wire:click="entfernePos({{ $p['id'] }})" title="Entfernen" class="text-gray-400 hover:text-red-500">✕</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="px-5 py-8 text-center text-gray-400">Noch keine Positionen — unten hinzufügen.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Hinzufügen --}}
                        <div class="px-4 py-3 border-t border-black/5 dark:border-white/10 bg-black/[0.015] dark:bg-white/[0.02]">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="{{ $label }}">Position hinzufügen:</span>
                                @foreach(['gericht', 'basisrezept', 'gp', 'frei'] as $t)
                                    <button type="button" wire:click="$set('addTyp', '{{ $t }}')"
                                        class="{{ $pill }} {{ $addTyp === $t ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $typLabel[$t] }}</button>
                                @endforeach

                                @if($addTyp === 'frei')
                                    <button type="button" wire:click="addPosition" class="{{ $btnGhostXs }} ml-2">+ Freie Zeile</button>
                                @else
                                    <input type="search" wire:model.live.debounce.300ms="addSuche" placeholder="{{ $typLabel[$addTyp] }} suchen …" class="{{ $input }} !w-56 ml-2" />
                                @endif
                            </div>

                            @if($addTyp !== 'frei')
                                <div class="mt-2 flex flex-wrap gap-1.5 max-h-32 overflow-y-auto">
                                    @forelse($quellen as $q)
                                        <button type="button" wire:key="q-{{ $addTyp }}-{{ $q['id'] }}" wire:click="addPosition({{ $q['id'] }})"
                                            class="{{ $btnGhostXs }}">+ {{ $q['label'] }}</button>
                                    @empty
                                        <span class="text-[11px] text-gray-400">{{ $addSuche !== '' ? 'Nichts gefunden.' : 'Tippe zum Suchen oder wähle aus der Liste.' }}</span>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Ergebnis: HK1 → Zuschläge → HK2 → VK --}}
                    <div class="relative overflow-hidden {{ $card }} p-4">
                        <div class="{{ $cardAccent }}"></div>
                        <p class="{{ $label }} mb-2">Kalkulation · Marge {{ rtrim(rtrim(number_format((float) $berechnung['marge_pct'], 2, ',', '.'), '0'), ',') }} %{{ $aktiv->marge_override_pct !== null ? ' (Override)' : '' }}</p>
                        <div class="max-w-md space-y-1">
                            <div class="flex items-center justify-between text-xs py-0.5 font-medium text-gray-900 dark:text-gray-100">
                                <span>HK1 — Wareneinsatz (Σ Positionen)</span>
                                <span class="tabular-nums">{{ number_format((float) $berechnung['hk1'], 2, ',', '.') }} €</span>
                            </div>
                            @foreach($berechnung['bloecke'] as $blk)
                                @if($blk['key'] !== 'we')
                                    <div class="flex items-center justify-between text-xs py-0.5 text-gray-600 dark:text-gray-300">
                                        <span>+ {{ $blk['label'] }}</span>
                                        <span class="tabular-nums">{{ number_format((float) $blk['betrag'], 2, ',', '.') }} €</span>
                                    </div>
                                @endif
                            @endforeach
                            <div class="flex items-center justify-between text-sm py-1.5 border-t border-black/10 dark:border-white/10 font-semibold text-gray-900 dark:text-gray-100">
                                <span>= HK2 (Selbstkosten)</span><span class="tabular-nums">{{ number_format((float) $berechnung['hk2'], 2, ',', '.') }} €</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">VK-Vorschlag (HK2 × Marge)</span>
                                <span class="tabular-nums text-violet-700 dark:text-violet-300 font-medium">{{ number_format((float) $berechnung['vk_vorschlag'], 2, ',', '.') }} €</span>
                            </div>
                            <p class="text-[10px] text-gray-400 pt-1">Arbeitszeit-Rollup: {{ number_format((float) $berechnung['arbeitszeit_min'], 0, ',', '.') }} min · nicht-lineare Skalierung folgt mit der KI. Sätze/Gemeinkosten in Einstellungen → Kalkulation.</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
