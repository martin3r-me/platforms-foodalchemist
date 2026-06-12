{{-- P-8-Zutaten-Kern — EINE Quelle für Modal (M4-07) und Voll-Editor (Editor-Parität) --}}
    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400 mb-3" data-editor-fehler>{{ $fehler }}</p>
    @endif

    {{-- wire:key: Alpine wertet x-data bei morphdom NICHT neu aus — Rezept-Wechsel muss das Element ersetzen --}}
    <div wire:key="zutaten-editor-{{ $rezept?->id ?? 0 }}"
         x-data="zutatenEditor(@js($zeilenJson), @js(! $eingebettet), @js($einheiten->keyBy('id')->map(fn ($e) => ['slug' => $e->slug, 'g' => $e->default_in_g !== null ? (float) $e->default_in_g : ($e->default_in_ml !== null ? (float) $e->default_in_ml : null)])->all()))"
         data-zutaten-editor>
        <table class="{{ $table }}">
            <thead><tr class="text-left">
                @foreach(['#' => null, 'Menge' => null, 'bis' => 'Mengenbereich (optional): „Menge BIS", z. B. 2–3 Stk — gerechnet wird mit dem Mittelwert', 'Einheit' => null, 'Verknüpfung / Beschreibung' => null, 'Hinweis' => null, 'Garv. %' => null, 'EK €' => null, '' => null] as $head => $tip)
                    <th class="{{ $th }} !px-2 {{ $tip ? 'cursor-help underline decoration-dotted decoration-gray-300 underline-offset-2' : '' }}" @if($tip) title="{{ $tip }}" @endif>{{ $head }}</th>
                @endforeach
            </tr></thead>
            {{-- tbody je Zutat: Haupt-Zeile + aufklappbare LA-Peek-Zeile (HTML erlaubt mehrere tbody) --}}
                <template x-for="(zeile, i) in rows" :key="zeile._key">
                    <tbody @dragover.prevent @drop.prevent="dropAuf(i)"
                           :class="dragIdx === i ? 'opacity-40' : ''" data-editor-zeile>
                    <tr class="{{ $tr }} !border-b-0" :class="zeile.is_optional ? 'opacity-60' : ''">
                        <td class="{{ $td }} !px-1.5 !py-1 whitespace-nowrap">
                            {{-- R4: setData ist PFLICHT, sonst startet Safari den Drag gar nicht --}}
                            <span class="cursor-grab active:cursor-grabbing text-gray-300 hover:text-violet-500 select-none" draggable="true"
                                  @dragstart="dragIdx = i; $event.dataTransfer.setData('text/plain', String(i)); $event.dataTransfer.effectAllowed = 'move'"
                                  @dragend="dragIdx = null" title="ziehen zum Sortieren" data-drag-handle>⠿</span>
                            <span class="text-gray-400 tabular-nums text-xs ml-0.5" x-text="i + 1"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1"><input type="text" x-model="zeile.menge" class="{{ $input }} !w-20 !py-1 text-right" data-menge /></td>
                        <td class="{{ $td }} !px-2 !py-1"><input type="text" x-model="zeile.menge_max" placeholder="–" title="Mengenbereich: bis (optional)" class="{{ $input }} !w-14 !py-1 text-right" /></td>
                        <td class="{{ $td }} !px-2 !py-1">
                            <select x-model.number="zeile.einheit_vocab_id" class="{{ $input }} !w-24 !py-1">
                                @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                            </select>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1 max-w-[18rem]">
                            {{-- R4 (Dichte): Lineage als Tooltip statt eigener Zeile — 19-Zutaten-Rezepte blieben sonst unlesbar --}}
                            <span class="text-xs" :class="zeile.gp_id || zeile.referenced_recipe_id ? 'text-violet-600 dark:text-violet-400' : 'text-gray-400'"
                                  x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"
                                  :title="zeile.lineage ? 'Verknüpfung via ' + zeile.lineage : ''"></span>
                            <button type="button" x-show="zeile.gp_id" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="Lieferantenartikel hinter dem GP (Peek)"
                                    @click="peek(zeile)" data-gp-peek>📦</button>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1"><input type="text" x-model="zeile.note" placeholder="Hinweis" class="{{ $input }} !w-28 !py-1" /></td>
                        <td class="{{ $td }} !px-2 !py-1"><input type="text" x-model="zeile.garverlust_pct" placeholder="0" class="{{ $input }} !w-14 !py-1 text-right" /></td>
                        <td class="{{ $td }} !px-2 !py-1 text-right tabular-nums whitespace-nowrap" data-zeilen-ek-live>
                            <span x-text="zeilenEk(zeile) ?? '—'" :class="zeilenEk(zeile) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1 whitespace-nowrap">
                            <label class="inline-flex items-center gap-1 text-[10px] text-gray-400 mr-1" title="optional: zählt nicht in Yield/Kosten">
                                <input type="checkbox" x-model="zeile.is_optional" class="rounded border-gray-300 !w-3 !h-3" />opt
                            </label>
                            <button type="button" class="text-rose-400 hover:text-rose-600" @click="rows.splice(i, 1)" title="Zeile entfernen" data-zeile-entfernen>✕</button>
                        </td>
                    </tr>
                    {{-- GP-Peek (D-5 §4.2.3, Ist-App): LA-Tabelle hinter dem GP, ★ = Lead --}}
                    <tr x-show="zeile._peek" x-cloak>
                        <td colspan="9" class="!px-3 !py-2 bg-black/[0.02] dark:bg-white/[0.03]">
                            <div class="rounded-lg border-l-2 border-orange-400 bg-white dark:bg-gray-900 px-3 py-2" data-gp-peek-tabelle>
                                <p class="text-xs font-medium text-gray-900 dark:text-gray-100 mb-1">
                                    📦 <span x-text="(zeile._peek?.length ?? 0) + ' Lieferantenartikel · GP '"></span><span class="font-semibold" x-text="zeile.ziel_name"></span>
                                </p>
                                <table class="w-full text-xs">
                                    <thead><tr class="text-left text-[10px] uppercase tracking-wider text-gray-400">
                                        <th class="px-1.5 py-0.5"></th><th class="px-1.5 py-0.5">Lieferant</th><th class="px-1.5 py-0.5">Art.-Nr</th>
                                        <th class="px-1.5 py-0.5">Bezeichnung</th><th class="px-1.5 py-0.5">Marke</th><th class="px-1.5 py-0.5">VPE</th>
                                        <th class="px-1.5 py-0.5 text-right">Preis</th><th class="px-1.5 py-0.5 text-right">Vergleichspreis</th><th class="px-1.5 py-0.5 text-right">Match</th>
                                    </tr></thead>
                                    <tbody>
                                        <template x-for="(la, j) in (zeile._peek ?? [])" :key="j">
                                            <tr class="border-t border-black/5 dark:border-white/5" :class="la.lead ? 'bg-orange-500/10' : ''">
                                                <td class="px-1.5 py-1"><span x-show="la.lead" class="text-orange-500" title="Lead-LA (GL-03)">★</span></td>
                                                <td class="px-1.5 py-1 text-gray-600 dark:text-gray-300" x-text="la.lieferant"></td>
                                                <td class="px-1.5 py-1 font-mono text-gray-500" x-text="la.artikelnr"></td>
                                                <td class="px-1.5 py-1 text-gray-900 dark:text-gray-100" x-text="la.bezeichnung"></td>
                                                <td class="px-1.5 py-1 text-gray-500" x-text="la.marke ?? '—'"></td>
                                                <td class="px-1.5 py-1 text-gray-500 italic" x-text="la.vpe ?? '—'"></td>
                                                <td class="px-1.5 py-1 text-right tabular-nums" x-text="la.preis ?? '—'"></td>
                                                <td class="px-1.5 py-1 text-right tabular-nums text-gray-500" x-text="la.vergleichspreis ?? '—'"></td>
                                                <td class="px-1.5 py-1 text-right text-gray-500" x-text="la.match ?? '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </template>
            <tfoot>
                <tr class="border-t border-black/10 dark:border-white/10">
                    <td colspan="7" class="{{ $td }} !px-2 text-right text-xs text-gray-400">
                        Σ live (Näherung — count-Einheiten & Brücken rechnet der Save-Recompute)
                    </td>
                    <td class="{{ $td }} !px-2 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100" data-summe-live>
                        <span x-text="summe()"></span>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        {{-- Add-Zeile (M4-08): GP-/Sub-Picker mit Auto-Fill --}}
        <div class="mt-3 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" data-add-zeile>
            <div class="flex items-center gap-2">
                <input type="text" x-model="neu.menge" placeholder="Menge" class="{{ $input }} !w-20 !py-1 text-right" data-neu-menge />
                <select x-model.number="neu.einheit_vocab_id" class="{{ $input }} !w-24 !py-1">
                    @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                </select>
                <div class="relative flex-1">
                    <input type="search" x-model="pickerSuche" @input.debounce.300ms="suchen()"
                           placeholder="GP oder Basisrezept suchen … (Auto-Fill)" class="{{ $input }} !py-1" data-picker-suche />
                    <div x-show="pickerErgebnisse.length > 0" x-cloak
                         class="absolute left-0 top-full mt-1 z-20 w-full rounded-lg bg-white dark:bg-gray-900 border border-black/10 dark:border-white/10 shadow-xl overflow-hidden">
                        <template x-for="ziel in pickerErgebnisse" :key="ziel.typ + '-' + ziel.id">
                            <button type="button" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-violet-500/10"
                                    @click="hinzufuegen(ziel)" x-text="ziel.name" data-picker-treffer></button>
                        </template>
                    </div>
                </div>
                <label class="inline-flex items-center gap-1 text-xs text-gray-400">
                    <input type="checkbox" x-model="neu.is_optional" class="rounded border-gray-300" /> optional
                </label>
            </div>
            <p class="text-[10px] text-gray-400 mt-1">Syntax §1.2: Menge · Einheit · Verknüpfung — Hinweis/Verarbeitung in die Hinweis-Spalte (Regelwerk §2)</p>
            <button type="button" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 mt-1" @click="garverluste()" data-garverlust-ki
                    title="M4-11: KI-Schätzung je Zutat (GL-07 — geschrieben erst beim Speichern, quelle=ki)">✨ Garverluste vorschlagen</button>
        </div>

        {{-- R4-Fix: dieser Block saß IM Picker-Dropdown (x-show) — der Button war praktisch nie sichtbar --}}
        @if($eingebettet)
            <div class="mt-3 flex items-center justify-end gap-2" data-zutaten-eingebettet-aktionen>
                <span class="text-[10px] text-gray-400">Zutaten speichern synct + rechnet GL-02 neu (eigener Schritt, P-8)</span>
                <button type="button" @click="$wire.speichern(payload())" class="{{ $btnPrimary }}" data-zutaten-speichern-inline>Zutaten speichern</button>
            </div>
        @endif
    </div>
