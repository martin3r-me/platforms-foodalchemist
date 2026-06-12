{{-- P-8-Zutaten-Kern — EINE Quelle für Modal (M4-07) und Voll-Editor (Editor-Parität) --}}
    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400 mb-3" data-editor-fehler>{{ $fehler }}</p>
    @endif

    {{-- wire:key: Alpine wertet x-data bei morphdom NICHT neu aus — Rezept-Wechsel muss das Element ersetzen --}}
    <div wire:key="zutaten-editor-{{ $rezept?->id ?? 0 }}"
         x-data="zutatenEditor(@js($zeilenJson), @js(! $eingebettet), @js($einheiten->keyBy('id')->map(fn ($e) => ['slug' => $e->slug, 'g' => $e->default_in_g !== null ? (float) $e->default_in_g : ($e->default_in_ml !== null ? (float) $e->default_in_ml : null)])->all()))"
         data-zutaten-editor>
        <table class="{{ $table }}">
            {{-- R5: BIS-Spalte raus (Dominique) — menge_max bleibt in den Daten erhalten; 3 EK-Sichten statt einer --}}
            <thead><tr class="text-left">
                @php($koepfe = ['#' => null, 'Menge' => null, 'Einheit' => null, 'Verknüpfung / Beschreibung' => 'Klick auf den Namen öffnet GP/Rezept als Fenster über dem Editor']
                    + ($vkKontext ? ['Rolle' => 'V-21: aroma_treiber · komponente · beilage · garnitur (🎭 verteilt per KI)'] : [])
                    + ['Hinweis' => null, 'Garv. %' => null, 'EK €' => 'EK nach Lead-LA-Strategie (V-27 — damit rechnet das Rezept)', 'EK ↓' => 'günstigster Lieferantenartikel hinter dem GP', 'EK Ø' => 'Durchschnitt über alle Lieferantenartikel hinter dem GP', '' => null])
                @foreach($koepfe as $head => $tip)
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
                        <td class="{{ $td }} !px-2 !py-1">
                            <select x-model.number="zeile.einheit_vocab_id" class="{{ $input }} !w-24 !py-1">
                                @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                            </select>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1 max-w-[18rem]">
                            {{-- R4 (Dichte): Lineage als Tooltip; R7-Fix: neuer Tab ist bei Dominique
                                 blockiert → Klick öffnet das Ziel als MODAL über dem Editor (Stand bleibt) --}}
                            <template x-if="zeile.gp_id || zeile.referenced_recipe_id">
                                <button type="button"
                                        class="text-xs text-violet-600 dark:text-violet-400 hover:underline text-left"
                                        x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"
                                        :title="(zeile.lineage ? 'via ' + zeile.lineage + ' — ' : '') + (zeile.gp_id ? 'GP öffnen' : 'Rezept öffnen')"
                                        @click="zeile.gp_id
                                            ? Livewire.dispatch('gp-modal.oeffnen', { id: zeile.gp_id })
                                            : Livewire.dispatch('recipe-modal.oeffnen', { id: zeile.referenced_recipe_id })"
                                        data-ziel-link></button>
                            </template>
                            <template x-if="!zeile.gp_id && !zeile.referenced_recipe_id">
                                <span class="text-xs text-gray-400" x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"
                                      :title="zeile.lineage ? 'Verknüpfung via ' + zeile.lineage : ''"></span>
                            </template>
                            <button type="button" x-show="zeile.gp_id" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="Lieferantenartikel hinter dem GP (Peek)"
                                    @click="peek(zeile)" data-gp-peek>📦</button>
                        </td>
                        @if($vkKontext)
                            <td class="{{ $td }} !px-2 !py-1">
                                <select x-model="zeile.rolle" class="{{ $input }} !w-32 !py-1" data-rolle-select>
                                    <option value="">—</option>
                                    @foreach(\Platform\FoodAlchemist\Services\SpeisenKlassenService::ROLLEN as $rolle)
                                        <option value="{{ $rolle }}">{{ $rolle }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endif
                        <td class="{{ $td }} !px-2 !py-1"><input type="text" x-model="zeile.note" placeholder="Hinweis" class="{{ $input }} !w-28 !py-1" /></td>
                        <td class="{{ $td }} !px-2 !py-1"><input type="text" x-model="zeile.garverlust_pct" placeholder="0" class="{{ $input }} !w-14 !py-1 text-right" /></td>
                        <td class="{{ $td }} !px-2 !py-1 text-right tabular-nums whitespace-nowrap" data-zeilen-ek-live>
                            <span x-text="zeilenEk(zeile) ?? '—'" :class="zeilenEk(zeile) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1 text-right tabular-nums whitespace-nowrap text-gray-500" data-zeilen-ek-min>
                            <span x-text="zeilenEk(zeile, 'ek_pro_g_min') ?? '—'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-1 text-right tabular-nums whitespace-nowrap text-gray-500" data-zeilen-ek-avg>
                            <span x-text="zeilenEk(zeile, 'ek_pro_g_avg') ?? '—'"></span>
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
                        <td colspan="{{ $vkKontext ? 11 : 10 }}" class="!px-3 !py-2 bg-black/[0.02] dark:bg-white/[0.03]">
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
                    <td colspan="{{ $vkKontext ? 7 : 6 }}" class="{{ $td }} !px-2 text-right text-xs text-gray-400">
                        Σ live (Näherung — count-Einheiten & Brücken rechnet der Save-Recompute)
                    </td>
                    <td class="{{ $td }} !px-2 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100" data-summe-live>
                        <span x-text="summe()"></span>
                    </td>
                    <td class="{{ $td }} !px-2 text-right tabular-nums text-gray-500" data-summe-min>
                        <span x-text="summe('ek_pro_g_min')"></span>
                    </td>
                    <td class="{{ $td }} !px-2 text-right tabular-nums text-gray-500" data-summe-avg>
                        <span x-text="summe('ek_pro_g_avg')"></span>
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
                {{-- R5: Typ-Filter (Alle/GP/Rezept) — smarte Suche bleibt, Treffer tragen Typ-Badges --}}
                <div class="flex items-center gap-1" data-picker-typ-filter>
                    <template x-for="t in [['alle', 'Alle'], ['gp', 'GPs'], ['sub', 'Rezepte']]" :key="t[0]">
                        <button type="button" @click="pickerTyp = t[0]"
                                class="px-2 py-0.5 rounded-full text-[11px] border transition-colors"
                                :class="pickerTyp === t[0] ? 'border-violet-500 text-violet-700 dark:text-violet-300 font-medium' : 'border-black/10 dark:border-white/15 text-gray-400'"
                                x-text="t[1]"></button>
                    </template>
                </div>
                <div class="relative flex-1">
                    <input type="search" x-model="pickerSuche" @input.debounce.300ms="suchen()"
                           placeholder="GP oder Basisrezept suchen … (Auto-Fill)" class="{{ $input }} !py-1" data-picker-suche />
                    <div x-show="gefilterte().length > 0" x-cloak
                         class="absolute left-0 top-full mt-1 z-20 w-full rounded-lg bg-white dark:bg-gray-900 border border-black/10 dark:border-white/10 shadow-xl overflow-hidden">
                        <template x-for="ziel in gefilterte()" :key="ziel.typ + '-' + ziel.id">
                            <button type="button" class="flex items-center gap-2 w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-violet-500/10"
                                    @click="hinzufuegen(ziel)" data-picker-treffer>
                                <span class="shrink-0 px-1.5 rounded text-[9px] font-medium uppercase tracking-wider"
                                      :class="ziel.typ === 'gp' ? 'bg-violet-500/10 text-violet-600 dark:text-violet-300' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'"
                                      x-text="ziel.typ === 'gp' ? 'GP' : 'Rezept'"></span>
                                <span x-text="ziel.name"></span>
                            </button>
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
