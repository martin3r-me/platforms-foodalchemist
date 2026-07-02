{{-- P-8-Zutaten-Kern — EINE Quelle für Modal (M4-07) und Voll-Editor (Editor-Parität) --}}
    {{-- Phase 5: Typ-Farben (Settings) als Inline-Style — Text = Hex, Hintergrund = Hex+1a (10%). --}}
    @php($typFarben = $typFarben ?? \Platform\FoodAlchemist\Services\TeamSettingsService::TYP_FARBEN_DEFAULTS)
    @php($typStyle = fn (string $t) => isset($typFarben[$t]) ? 'color:' . $typFarben[$t] . ';background-color:' . $typFarben[$t] . '1a' : '')
    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400 mb-3" data-editor-fehler>{{ $fehler }}</p>
    @endif

    {{-- wire:key: Alpine wertet x-data bei morphdom NICHT neu aus — Rezept-Wechsel muss das Element ersetzen --}}
    <div wire:key="zutaten-editor-{{ $rezept?->id ?? 0 }}"
         x-data="zutatenEditor(@js($zeilenJson), @js(! $eingebettet), @js($einheiten->keyBy('id')->map(fn ($e) => ['slug' => $e->slug, 'g' => $e->default_in_g !== null ? (float) $e->default_in_g : ($e->default_in_ml !== null ? (float) $e->default_in_ml : null)])->all()), @js($browserVokabular ?? null))"
         data-zutaten-editor>
        {{-- R18: Drei-Spalten-Layout — Browsen (links GPs, rechts Basisrezepte) und Editieren
             (Mitte) konkurrieren nicht mehr um denselben Platz; Spalten scrollen intern. --}}
        <div class="flex gap-3 items-start">
        {{-- R19 (Dominique): Seitenspalten als ECHTE Panels — farblich abgehoben, stehen fest
             (sticky), nur die Mitte scrollt; die Trefferlisten scrollen intern. --}}
        <aside class="w-72 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] dark:bg-white/[0.05] border border-black/5 dark:border-white/10 p-2.5 sticky top-0 self-start max-h-[70vh]" data-browser-gps>
            <p class="{{ $dt }} mb-1">Produkte (<span x-text="gpTotal"></span>)</p>
            <div class="space-y-1 mb-1.5">
                <select x-model="gpFilter.wg" @change="gpFilter.sub = ''; browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-gp-filter-wg>
                    <option value="">Alle Warengruppen</option>
                    <template x-for="w in (vokabular?.warengruppen ?? [])" :key="w.code">
                        <option :value="w.code" x-text="w.name"></option>
                    </template>
                </select>
                <select x-model="gpFilter.sub" @change="browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-gp-filter-sub>
                    <option value="">Alle Kategorien</option>
                    <template x-for="su in subKategorienFuerWg()" :key="su.warengruppe_code + su.sub_kategorie">
                        <option :value="su.sub_kategorie" x-text="su.sub_kategorie"></option>
                    </template>
                </select>
                <button type="button" @click="gpFilter.mehr = !gpFilter.mehr"
                        class="text-[10px] text-gray-400 hover:text-violet-500" data-gp-mehr-filter
                        x-text="gpFilter.mehr ? '− Weniger Filter' : '+ Mehr Filter'"></button>
                <div x-show="gpFilter.mehr" x-cloak class="space-y-1">
                    <select x-model="gpFilter.zustand" @change="browse()" class="{{ $input }} !py-0.5 !text-[11px]">
                        <option value="">Jeder Zustand</option>
                        <template x-for="z in (vokabular?.zustande ?? [])" :key="z"><option :value="z" x-text="z"></option></template>
                    </select>
                    <label class="flex items-center gap-1.5 text-[11px] text-gray-500">
                        <input type="checkbox" x-model="gpFilter.bio" @change="browse()" class="rounded border-gray-300 !w-3 !h-3" /> Bio
                    </label>
                    <label class="flex items-center gap-1.5 text-[11px] text-gray-500">
                        <input type="checkbox" x-model="gpFilter.regional" @change="browse()" class="rounded border-gray-300 !w-3 !h-3" /> Regional
                    </label>
                </div>
            </div>
            <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1" data-gp-liste>
                <template x-for="ziel in gpListe" :key="'bg' + ziel.id">
                    <div class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px]">
                        <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('gp') }}">GP</span>
                        <span class="min-w-0 flex-1 break-words leading-snug text-gray-700 dark:text-gray-200" x-text="ziel.name" :title="ziel.name"></span>
                        <span class="shrink-0 text-[10px] text-gray-400 tabular-nums" x-text="ziel.preis_label ?? ''"></span>
                        <button type="button" x-show="ziel.id" @click="Livewire.dispatch('gp-modal.oeffnen', { id: ziel.id })"
                                class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Produkt einsehen">📦</button>
                        <button type="button" @click="parke(ziel)" data-parke
                                class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none"
                                title="übernehmen → Menge eingeben">+</button>
                    </div>
                </template>
                <p x-show="gpListe.length === 0" class="text-[10px] text-gray-400 px-1">— keine Treffer —</p>
                <p x-show="gpTotal > 200" x-cloak class="text-[10px] text-gray-400 px-1" x-text="'… ' + (gpTotal - 200) + ' weitere — Filter verengen'"></p>
            </div>
        </aside>
        <div class="flex-1 min-w-0">
        {{-- Such-/Park-Zeile FIX oben (sticky) — filtert beide Seitenspalten; die Tabelle scrollt darunter --}}
        <div class="sticky top-0 z-10 mb-3 rounded-lg bg-white/90 dark:bg-gray-900/90 backdrop-blur border border-black/5 dark:border-white/10 px-3 py-2" data-add-zeile>
            <div x-show="tauschIdx !== null" x-cloak class="flex items-center gap-2 mb-2 rounded-md bg-amber-500/10 border border-amber-500/30 px-2 py-1 text-[11px] text-amber-700 dark:text-amber-300" data-tausch-banner>
                <span>⇄ Tausch-Modus — Ersatz für Zeile <span class="font-semibold" x-text="(tauschIdx ?? 0) + 1"></span> per <span class="font-semibold">+</span> in den Spalten wählen. Menge &amp; Einheit bleiben.</span>
                <button type="button" @click="tauschIdx = null" class="{{ $btnGhostXs }} shrink-0 ml-auto" data-tausch-abbrechen>Abbrechen</button>
            </div>
            <div x-show="geparkt === null" class="flex items-center gap-2">
                <input type="search" x-model="browseQ" @input.debounce.300ms="sucheGetippt()"
                       placeholder="Suchen — filtert Produkte UND Rezepte … (Übernehmen per [+] in den Spalten)"
                       class="{{ $input }} !py-1 flex-1" data-browse-suche />
            </div>
            <div x-show="geparkt !== null" x-cloak class="flex items-center gap-2" data-park-zeile>
                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider"
                      :style="geparkt?.typ === 'gp' ? '{{ $typStyle('gp') }}' : '{{ $typStyle('basisrezept') }}'"
                      x-text="geparkt?.typ === 'gp' ? 'GP' : 'Rezept'"></span>
                <span class="min-w-0 flex-1 truncate text-[11px] font-medium text-gray-900 dark:text-gray-100" x-text="geparkt?.name" data-park-name></span>
                <input type="text" x-model="neu.menge" @keydown.enter.prevent="einfuegen()" placeholder="Menge"
                       class="{{ $input }} !w-20 !py-1 text-right" data-park-menge />
                <select x-model.number="neu.einheit_vocab_id" class="{{ $input }} !w-24 !py-0.5 !text-[11px]" data-park-einheit>
                    @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                </select>
                <label class="inline-flex items-center gap-1 text-[11px] text-gray-400 shrink-0">
                    <input type="checkbox" x-model="neu.is_optional" class="rounded border-gray-300" /> optional
                </label>
                <button type="button" @click="einfuegen()" class="{{ $btnGhostXs }} text-emerald-600 shrink-0" data-park-einfuegen>Einfügen ⏎</button>
                <button type="button" @click="verwerfen()" class="{{ $btnGhostXs }} shrink-0" title="Verwerfen" data-park-verwerfen>✕</button>
            </div>
            <p class="text-[10px] text-gray-400 mt-1">Erst Produkt/Rezept per [+] wählen — Einheit kommt automatisch mit, dann Menge + Enter (§1.2)</p>
            <button type="button" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 mt-1" @click="garverluste()" data-garverlust-ki
                    title="M4-11: KI-Schätzung je Zutat (GL-07 — geschrieben erst beim Speichern, quelle=ki)">✨ Garverluste vorschlagen</button>
        </div>
        <div class="overflow-x-auto">{{-- R18: Mitte scrollt intern statt unter die Seitenspalten zu laufen --}}
        <table class="{{ $table }} border-collapse">
            {{-- R5: BIS-Spalte raus (Dominique) — menge_max bleibt in den Daten erhalten; 3 EK-Sichten statt einer --}}
            <thead><tr class="text-left">
                @php($koepfe = ['#' => null, 'Menge' => null, 'Einheit' => null, 'Verknüpfung / Beschreibung' => 'Klick auf den Namen öffnet GP/Rezept als Fenster über dem Editor']
                    + ($vkKontext ? ['Rolle' => 'V-21: aroma_treiber · komponente · beilage · garnitur (🎭 verteilt per KI)'] : [])
                    + ['Garv. %' => null, 'EK €' => 'EK nach Lead-LA-Strategie (V-27 — damit rechnet das Rezept)', 'EK ↓' => 'günstigster Lieferantenartikel hinter dem GP', 'EK Ø' => 'Durchschnitt über alle Lieferantenartikel hinter dem GP', '' => null])
                {{-- R22: schmale Spalten auf Inhaltsbreite (w-px) — der Restplatz gehört der Zutat --}}
                @foreach($koepfe as $head => $tip)
                    <th class="{{ $th }} !px-2 {{ str_starts_with($head, 'Verknüpfung') ? 'w-full' : 'w-px' }} {{ $tip ? 'cursor-help underline decoration-dotted decoration-gray-300 underline-offset-2' : '' }}" @if($tip) title="{{ $tip }}" @endif>{{ $head }}</th>
                @endforeach
            </tr></thead>
            {{-- tbody je Zutat: Haupt-Zeile + aufklappbare LA-Peek-Zeile (HTML erlaubt mehrere tbody) --}}
                <template x-for="(zeile, i) in rows" :key="zeile._key">
                    <tbody @dragover.prevent @dragenter.prevent @drop.prevent="dropAuf(i)"
                           :class="dragIdx === i ? 'opacity-40' : ''" data-editor-zeile>
                    <tr class="{{ $tr }} !border-b-0 transition-colors duration-500" :class="(zeile.is_optional ? 'opacity-60 ' : '') + (zeile._flash ? 'bg-emerald-500/15' : '')">
                        <td class="{{ $td }} !px-1.5 !py-0.5 whitespace-nowrap">
                            {{-- R4: setData ist PFLICHT, sonst startet Safari den Drag gar nicht --}}
                            <span class="inline-block cursor-grab active:cursor-grabbing text-gray-500 dark:text-gray-400 hover:text-violet-500 select-none" draggable="true"
                                  @dragstart="dragIdx = i; $event.dataTransfer.setData('text/plain', String(i)); $event.dataTransfer.effectAllowed = 'move'"
                                  @dragend="dragIdx = null" title="ziehen zum Sortieren" data-drag-handle>⠿</span>
                            {{-- R15 (Jarvis moveUpDown): ▲▼ als zuverlässige Sortier-Alternative zu DnD --}}
                            <span class="inline-flex flex-col align-middle leading-none">
                                <button type="button" class="text-[9px] text-gray-500 dark:text-gray-400 hover:text-violet-500 leading-none disabled:opacity-20"
                                        :disabled="i === 0" @click="verschiebe(i, -1)" title="nach oben" data-zeile-hoch>▲</button>
                                <button type="button" class="text-[9px] text-gray-500 dark:text-gray-400 hover:text-violet-500 leading-none disabled:opacity-20"
                                        :disabled="i === rows.length - 1" @click="verschiebe(i, 1)" title="nach unten" data-zeile-runter>▼</button>
                            </span>
                            <span class="text-gray-400 tabular-nums text-[11px] ml-0.5" x-text="i + 1"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-0.5"><input type="text" x-model="zeile.menge" class="{{ $input }} !w-20 !py-0.5 !text-[11px] text-right" data-menge /></td>
                        <td class="{{ $td }} !px-2 !py-0.5">
                            <select x-model.number="zeile.einheit_vocab_id" class="{{ $input }} !w-24 !py-0.5 !text-[11px]">
                                @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                            </select>
                        </td>
                        {{-- R19: Hinweis-Spalte raus → Platz für die EINZEILIGE Zutat (note bleibt im Datensatz) --}}
                        <td class="{{ $td }} !px-2 !py-0.5 whitespace-nowrap">
                            {{-- R4 (Dichte): Lineage als Tooltip; R7-Fix: neuer Tab ist bei Dominique
                                 blockiert → Klick öffnet das Ziel als MODAL über dem Editor (Stand bleibt) --}}
                            <template x-if="zeile.gp_id || zeile.referenced_recipe_id">
                                <button type="button"
                                        class="text-[11px] text-violet-600 dark:text-violet-400 hover:underline text-left"
                                        x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"
                                        :title="(zeile.lineage ? 'via ' + zeile.lineage + ' — ' : '') + (zeile.gp_id ? 'GP öffnen' : 'Rezept öffnen')"
                                        @click="zeile.gp_id
                                            ? Livewire.dispatch('gp-modal.oeffnen', { id: zeile.gp_id })
                                            : Livewire.dispatch('recipe-modal.oeffnen', { id: zeile.referenced_recipe_id })"
                                        data-ziel-link></button>
                            </template>
                            <template x-if="!zeile.gp_id && !zeile.referenced_recipe_id">
                                <span class="text-[11px] text-gray-400" x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"
                                      :title="zeile.lineage ? 'Verknüpfung via ' + zeile.lineage : ''"></span>
                            </template>
                            <button type="button" x-show="zeile.gp_id" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="Lieferantenartikel hinter dem GP (Peek)"
                                    @click="peek(zeile)" data-gp-peek>📦</button>
                            {{-- Concepter-Logik überall: Basisrezept als Fenster öffnen (kein Sprung), eigenes Symbol --}}
                            <button type="button" x-show="zeile.referenced_recipe_id" class="text-gray-300 hover:text-violet-500 ml-1 align-middle" title="Rezept einsehen"
                                    @click="Livewire.dispatch('recipe-modal.oeffnen', { id: zeile.referenced_recipe_id })" data-rez-oeffnen>📖</button>
                        </td>
                        @if($vkKontext)
                            <td class="{{ $td }} !px-2 !py-0.5">
                                <select x-model="zeile.rolle" class="{{ $input }} !w-32 !py-0.5 !text-[11px]" data-rolle-select>
                                    <option value="">—</option>
                                    @foreach(\Platform\FoodAlchemist\Services\SpeisenKlassenService::ROLLEN as $rolle)
                                        <option value="{{ $rolle }}">{{ $rolle }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endif
                        <td class="{{ $td }} !px-2 !py-0.5"><input type="text" x-model="zeile.garverlust_pct" placeholder="0" class="{{ $input }} !w-14 !py-0.5 !text-[11px] text-right" /></td>
                        <td class="{{ $td }} !px-2 !py-0.5 text-right tabular-nums whitespace-nowrap" data-zeilen-ek-live>
                            <span x-text="zeilenEk(zeile) ?? '—'" :class="zeilenEk(zeile) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-0.5 text-right tabular-nums whitespace-nowrap text-gray-500" data-zeilen-ek-min>
                            <span x-text="zeilenEk(zeile, 'ek_pro_g_min') ?? '—'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-0.5 text-right tabular-nums whitespace-nowrap text-gray-500" data-zeilen-ek-avg>
                            <span x-text="zeilenEk(zeile, 'ek_pro_g_avg') ?? '—'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 !py-0.5 whitespace-nowrap">
                            <label class="inline-flex items-center gap-1 text-[10px] text-gray-400 mr-1" title="optional: zählt nicht in Yield/Kosten">
                                <input type="checkbox" x-model="zeile.is_optional" class="rounded border-gray-300 !w-3 !h-3" />opt
                            </label>
                            {{-- ♻ Ersatz (Äquivalenz-Katalog): nur sichtbar wenn hinterlegt — 1 Klick tauscht um, Menge × Faktor --}}
                            <button type="button" x-show="zeile.ersatz" x-cloak class="text-emerald-500/60 hover:text-emerald-600 mr-1"
                                    :title="ersatzTitel(zeile)" @click="ersatzTausch(i)" data-zeile-ersatz>♻</button>
                            <button type="button" class="hover:text-violet-600 mr-1" :class="tauschIdx === i ? 'text-violet-600' : 'text-gray-300'" @click="starteTausch(i)" title="Zutat tauschen — Menge & Einheit bleiben" data-zeile-tausch>⇄</button>
                            <button type="button" class="text-rose-400 hover:text-rose-600" @click="rows.splice(i, 1)" title="Zeile entfernen" data-zeile-entfernen>✕</button>
                        </td>
                    </tr>
                    {{-- GP-Peek (D-5 §4.2.3, Ist-App): LA-Tabelle hinter dem GP, ★ = Lead --}}
                    <tr x-show="zeile._peek" x-cloak>
                        <td colspan="{{ $vkKontext ? 10 : 9 }}" class="!px-3 !py-1.5 bg-black/[0.02] dark:bg-white/[0.03]">
                            <div class="rounded-lg border-l-2 border-orange-400 bg-white dark:bg-gray-900 px-3 py-1.5" data-gp-peek-tabelle>
                                <p class="text-[11px] font-medium text-gray-900 dark:text-gray-100 mb-1">
                                    📦 <span x-text="(zeile._peek?.length ?? 0) + ' Lieferantenartikel · GP '"></span><span class="font-semibold" x-text="zeile.ziel_name"></span>
                                </p>
                                <table class="w-full text-[11px]">
                                    <thead><tr class="text-left text-[10px] uppercase tracking-wider text-gray-400">
                                        <th class="px-1.5 py-0.5"></th><th class="px-1.5 py-0.5">Lieferant</th><th class="px-1.5 py-0.5">Art.-Nr</th>
                                        <th class="px-1.5 py-0.5">Bezeichnung</th><th class="px-1.5 py-0.5">Marke</th><th class="px-1.5 py-0.5">VPE</th>
                                        <th class="px-1.5 py-0.5 text-right">Preis</th><th class="px-1.5 py-0.5 text-right">Vergleichspreis</th><th class="px-1.5 py-0.5 text-right">Match</th>
                                    </tr></thead>
                                    <tbody>
                                        <template x-for="(la, j) in (zeile._peek ?? [])" :key="j">
                                            <tr class="border-t border-black/5 dark:border-white/5" :class="la.lead ? 'bg-orange-500/10' : ''">
                                                <td class="px-1.5 py-0.5"><span x-show="la.lead" class="text-orange-500" title="Lead-LA (GL-03)">★</span></td>
                                                <td class="px-1.5 py-0.5 text-gray-600 dark:text-gray-300" x-text="la.lieferant"></td>
                                                <td class="px-1.5 py-0.5 font-mono text-gray-500" x-text="la.artikelnr"></td>
                                                <td class="px-1.5 py-0.5 text-gray-900 dark:text-gray-100" x-text="la.bezeichnung"></td>
                                                <td class="px-1.5 py-0.5 text-gray-500" x-text="la.marke ?? '—'"></td>
                                                <td class="px-1.5 py-0.5 text-gray-500 italic" x-text="la.vpe ?? '—'"></td>
                                                <td class="px-1.5 py-0.5 text-right tabular-nums" x-text="la.preis ?? '—'"></td>
                                                <td class="px-1.5 py-0.5 text-right tabular-nums text-gray-500" x-text="la.vergleichspreis ?? '—'"></td>
                                                <td class="px-1.5 py-0.5 text-right text-gray-500" x-text="la.match ?? '—'"></td>
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
                    <td colspan="{{ $vkKontext ? 6 : 5 }}" class="{{ $td }} !px-2 text-right text-[11px] text-gray-400">
                        <span data-yield-live>Yield ≈ <span class="font-medium text-gray-700 dark:text-gray-200" x-text="yieldLive()"></span></span>
                        · Σ live (Näherung — Putzverlust-Defaults & Brücken rechnet der Save-Recompute)
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
        </div>

        {{-- Such-/Park-Zeile ist jetzt sticky am Anfang der Mittelspalte (oben). --}}

        {{-- R4-Fix: dieser Block saß IM Picker-Dropdown (x-show) — der Button war praktisch nie sichtbar --}}
        @if($eingebettet)
            <div class="mt-3 flex items-center justify-end gap-2" data-zutaten-eingebettet-aktionen>
                <span class="text-[10px] text-gray-400">Zutaten speichern synct + rechnet GL-02 neu (eigener Schritt, P-8)</span>
                <button type="button" @click="$wire.speichern(payload())" class="{{ $btnPrimary }}" data-zutaten-speichern-inline>Zutaten speichern</button>
            </div>
        @endif
        </div>{{-- /Mitte --}}
        <aside class="w-72 shrink-0 hidden xl:flex flex-col rounded-xl bg-gray-500/[0.07] dark:bg-white/[0.05] border border-black/5 dark:border-white/10 p-2.5 sticky top-0 self-start max-h-[70vh]" data-browser-rezepte>
            <p class="{{ $dt }} mb-1">Basisrezepte (<span x-text="rezTotal"></span>)</p>
            <div class="space-y-1 mb-1.5">
                <select x-model="rezFilter.hg" @change="rezFilter.kat = ''; browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-rez-filter-hg>
                    <option value="">Alle Hauptgruppen</option>
                    <template x-for="h in (vokabular?.hauptgruppen ?? [])" :key="h.id">
                        <option :value="h.id" x-text="h.bezeichnung"></option>
                    </template>
                </select>
                <select x-model="rezFilter.kat" @change="browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-rez-filter-kat>
                    <option value="">Alle Kategorien</option>
                    <template x-for="k in kategorienFuerHg()" :key="k.id">
                        <option :value="k.id" x-text="k.bezeichnung"></option>
                    </template>
                </select>
                <select x-model="rezFilter.niveau" @change="browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-rez-filter-niveau>
                    <option value="">Jedes Niveau</option>
                    <template x-for="n in (vokabular?.niveaus ?? [])" :key="n.slug">
                        <option :value="n.slug" x-text="n.label"></option>
                    </template>
                </select>
            </div>
            <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1" data-rez-liste>
                <template x-for="ziel in rezListe" :key="'br' + ziel.id">
                    <div class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-emerald-500/5 text-[11px]">
                        <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider" style="{{ $typStyle('basisrezept') }}">REZ</span>
                        {{-- Niveau-Farbpunkt (haute=violett · gehoben=amber · klassisch=blau) --}}
                        <span class="shrink-0 w-1.5 h-1.5 rounded-full" x-show="(ziel.niveaus ?? []).length > 0"
                              :class="niveauFarbe(ziel.niveaus?.[0])" :title="(ziel.niveaus ?? []).join(' · ')"></span>
                        <span class="min-w-0 flex-1 break-words leading-snug text-gray-700 dark:text-gray-200" x-text="ziel.name.replace('↳ ', '')" :title="ziel.name"></span>
                        <span class="shrink-0 text-[10px] text-gray-400 tabular-nums" x-text="ziel.preis_label ?? ''"></span>
                        <button type="button" x-show="ziel.id" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: ziel.id })"
                                class="shrink-0 text-gray-300 hover:text-violet-500 leading-none" title="Rezept einsehen">📖</button>
                        <button type="button" @click="parke(ziel)" data-parke
                                class="shrink-0 px-1 rounded font-medium text-emerald-500 hover:bg-emerald-500/15 leading-none"
                                title="übernehmen → Menge eingeben">+</button>
                    </div>
                </template>
                <p x-show="rezListe.length === 0" class="text-[10px] text-gray-400 px-1">— keine Treffer —</p>
                <p x-show="rezTotal > 200" x-cloak class="text-[10px] text-gray-400 px-1" x-text="'… ' + (rezTotal - 200) + ' weitere — Filter verengen'"></p>
            </div>
        </aside>
        </div>{{-- /Drei-Spalten-Flex --}}
    </div>
