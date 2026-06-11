{{-- M4-07/08 / P-8: Zutaten-Editor — Alpine-first (Tippen/Reorder/Add ohne Server-Roundtrip), Sync bei Speichern --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="zutaten-editor" :title="'Zutaten bearbeiten' . ($rezept ? ' — ' . $rezept->name : '')" size="max-w-5xl">
    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400 mb-3" data-editor-fehler>{{ $fehler }}</p>
    @endif

    {{-- wire:key: Alpine wertet x-data bei morphdom NICHT neu aus — Rezept-Wechsel muss das Element ersetzen --}}
    <div wire:key="zutaten-editor-{{ $rezept?->id ?? 0 }}"
         x-data="zutatenEditor(@js($zeilenJson), @js($einheiten->keyBy('id')->map(fn ($e) => ['slug' => $e->slug, 'g' => $e->default_in_g !== null ? (float) $e->default_in_g : ($e->default_in_ml !== null ? (float) $e->default_in_ml : null)])->all()))"
         data-zutaten-editor>
        <table class="{{ $table }}">
            <thead><tr class="text-left">
                @foreach(['#', 'Menge', 'bis', 'Einheit', 'Verknüpfung / Beschreibung', 'Hinweis', 'Garv. %', 'EK €', ''] as $head)
                    <th class="{{ $th }} !px-2">{{ $head }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                <template x-for="(zeile, i) in rows" :key="zeile._key">
                    <tr class="{{ $tr }}" :class="zeile.is_optional ? 'opacity-60' : ''" data-editor-zeile>
                        <td class="{{ $td }} !px-2 whitespace-nowrap">
                            <span class="text-gray-400 tabular-nums" x-text="i + 1"></span>
                            <button type="button" class="text-gray-300 hover:text-violet-500 px-0.5" @click="hoch(i)" :disabled="i === 0" title="nach oben">↑</button>
                            <button type="button" class="text-gray-300 hover:text-violet-500 px-0.5" @click="runter(i)" :disabled="i === rows.length - 1" title="nach unten">↓</button>
                        </td>
                        <td class="{{ $td }} !px-2"><input type="text" x-model="zeile.menge" class="{{ $input }} !w-20 !py-1 text-right" data-menge /></td>
                        <td class="{{ $td }} !px-2"><input type="text" x-model="zeile.menge_max" placeholder="–" class="{{ $input }} !w-16 !py-1 text-right" /></td>
                        <td class="{{ $td }} !px-2">
                            <select x-model.number="zeile.einheit_vocab_id" class="{{ $input }} !w-24 !py-1">
                                @foreach($einheiten as $e)<option value="{{ $e->id }}">{{ $e->slug }}</option>@endforeach
                            </select>
                        </td>
                        <td class="{{ $td }} !px-2 max-w-[16rem]">
                            <span class="text-xs" :class="zeile.gp_id || zeile.referenced_recipe_id ? 'text-violet-600 dark:text-violet-400' : 'text-gray-400'"
                                  x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"></span>
                            <span class="block text-[10px] text-gray-400 italic truncate" x-text="zeile.lineage ? 'via ' + zeile.lineage : ''"></span>
                        </td>
                        <td class="{{ $td }} !px-2"><input type="text" x-model="zeile.note" placeholder="Hinweis" class="{{ $input }} !w-28 !py-1" /></td>
                        <td class="{{ $td }} !px-2"><input type="text" x-model="zeile.garverlust_pct" placeholder="0" class="{{ $input }} !w-14 !py-1 text-right" /></td>
                        <td class="{{ $td }} !px-2 text-right tabular-nums whitespace-nowrap" data-zeilen-ek-live>
                            <span x-text="zeilenEk(zeile) ?? '—'" :class="zeilenEk(zeile) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400'"></span>
                        </td>
                        <td class="{{ $td }} !px-2 whitespace-nowrap">
                            <label class="inline-flex items-center gap-1 text-[10px] text-gray-400 mr-1" title="optional: zählt nicht in Yield/Kosten">
                                <input type="checkbox" x-model="zeile.is_optional" class="rounded border-gray-300 !w-3 !h-3" />opt
                            </label>
                            <button type="button" class="text-rose-400 hover:text-rose-600" @click="rows.splice(i, 1)" title="Zeile entfernen" data-zeile-entfernen>✕</button>
                        </td>
                    </tr>
                </template>
            </tbody>
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
    </div>

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'zutaten-editor' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" x-data @click="$dispatch('zutaten-speichern')" class="{{ $btnPrimary }}" data-zutaten-speichern>Speichern</button>
    </x-slot:footer>
</x-foodalchemist::modal>

<script>
    window.zutatenEditor = function (zeilen, einheiten) {
        return {
            rows: zeilen.map((z, i) => ({ ...z, _key: 'z' + i })),
            einheiten,
            neu: { menge: '', einheit_vocab_id: Object.keys(einheiten)[0] ? parseInt(Object.keys(einheiten)[0]) : null, is_optional: false },
            pickerSuche: '',
            pickerErgebnisse: [],
            _n: zeilen.length,

            init() {
                // Speichern-Button (Footer liegt außerhalb des x-data-Scopes) → Window-Event
                window.addEventListener('zutaten-speichern', () => {
                    this.$wire.speichern(this.rows.map(({ _key, ziel_name, lineage, ek_pro_g, _garverlust_ki, ...rest }) => ({ ...rest, garverlust_quelle: _garverlust_ki ? 'ki' : undefined })));
                });
            },
            zahl(v) { const n = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(n) ? null : n; },
            mengeAvg(z) {
                const m = this.zahl(z.menge); const mx = this.zahl(z.menge_max);
                return m === null ? null : (mx !== null ? (m + mx) / 2 : m);
            },
            zeilenEk(z) {  // Live-Näherung: menge_g × ek_pro_g (T3-Quelle vom Server)
                if (z.is_optional || z.ek_pro_g === null || z.ek_pro_g === undefined) return null;
                const avg = this.mengeAvg(z); const f = this.einheiten[z.einheit_vocab_id]?.g;
                if (avg === null || !f) return null;
                return (avg * f * z.ek_pro_g).toFixed(2).replace('.', ',') + ' €';
            },
            summe() {
                let s = 0;
                for (const z of this.rows) {
                    const w = this.zeilenEk(z);
                    if (w) s += parseFloat(w.replace(',', '.'));
                }
                return s.toFixed(2).replace('.', ',') + ' €';
            },
            hoch(i) { if (i > 0) this.rows.splice(i - 1, 0, this.rows.splice(i, 1)[0]); },
            runter(i) { if (i < this.rows.length - 1) this.rows.splice(i + 1, 0, this.rows.splice(i, 1)[0]); },
            async garverluste() {  // M4-11: Vorschläge in die Client-rows mergen (Save schreibt quelle=ki)
                const zutaten = {};
                this.rows.forEach((z, i) => { zutaten[i] = z.raw_text; });
                const v = await this.$wire.garverlustVorschlag(zutaten);
                for (const [i, pct] of Object.entries(v.verluste ?? {})) {
                    if (this.rows[i] !== undefined) { this.rows[i].garverlust_pct = pct; this.rows[i]._garverlust_ki = true; }
                }
            },
            async suchen() {
                this.pickerErgebnisse = this.pickerSuche.trim() === '' ? [] : await this.$wire.sucheZiel(this.pickerSuche);
            },
            hinzufuegen(ziel) {  // Auto-Fill aus Picker (M4-08)
                this.rows.push({
                    _key: 'n' + (++this._n),
                    id: null,
                    gp_id: ziel.typ === 'gp' ? ziel.id : null,
                    referenced_recipe_id: ziel.typ === 'sub' ? ziel.id : null,
                    ziel_name: ziel.name,
                    raw_text: (this.neu.menge || '') + ' ' + ziel.name,
                    display_name: ziel.name,
                    menge: this.zahl(this.neu.menge) ?? 1,
                    menge_max: null,
                    einheit_vocab_id: this.neu.einheit_vocab_id,
                    garverlust_pct: null, putzverlust_pct: null,
                    is_optional: this.neu.is_optional,
                    note: '', rolle: null, ist_wertgebend: false,
                    lineage: ziel.typ === 'sub' ? 'recipe_ref' : 'manual',
                    ek_pro_g: ziel.ek_pro_g,
                });
                this.pickerSuche = ''; this.pickerErgebnisse = []; this.neu.menge = ''; this.neu.is_optional = false;
            },
        };
    };
</script>
