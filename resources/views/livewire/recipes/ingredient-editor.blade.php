{{-- M4-07/08 / P-8: Zutaten-Editor — Alpine-first; Kern geteilt mit dem Voll-Editor (partials/zutaten-kern) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-zutaten-editor-root>
    @if($eingebettet)
        @include('foodalchemist::livewire.recipes.partials.zutaten-kern')
    @else
        <x-foodalchemist::modal name="zutaten-editor" :title="'Zutaten bearbeiten' . ($rezept ? ' — ' . $rezept->name : '')" size="max-w-5xl">
            @include('foodalchemist::livewire.recipes.partials.zutaten-kern')

            <x-slot:footer>
                <button type="button" wire:click="$dispatch('modal.close', { name: 'zutaten-editor' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" x-data @click="$dispatch('zutaten-speichern')" class="{{ $btnPrimary }}" data-zutaten-speichern>Speichern</button>
            </x-slot:footer>
        </x-foodalchemist::modal>
    @endif

    <script>
    window.zutatenEditor = function (zeilen, standalone, einheiten) {
        return {
            rows: zeilen.map((z, i) => ({ ...z, _key: 'z' + i })),
            einheiten,
            neu: { menge: '', einheit_vocab_id: Object.keys(einheiten)[0] ? parseInt(Object.keys(einheiten)[0]) : null, is_optional: false },
            pickerSuche: '',
            pickerErgebnisse: [],
            _n: zeilen.length,

            dragIdx: null,
            dropAuf(i) {
                if (this.dragIdx === null || this.dragIdx === i) { this.dragIdx = null; return; }
                const [z] = this.rows.splice(this.dragIdx, 1);
                this.rows.splice(i, 0, z);
                this.dragIdx = null;
            },
            async peek(zeile) {  // D-5 §4.2.3: LA-Tabelle hinter dem GP, lazy vom Server
                if (zeile._peek) { zeile._peek = null; return; }
                zeile._peek = await this.$wire.gpArtikel(zeile.gp_id);
            },
            payload() {
                return this.rows.map(({ _key, ziel_name, lineage, ek_pro_g, _garverlust_ki, _peek, ...rest }) => ({ ...rest, garverlust_quelle: _garverlust_ki ? 'ki' : undefined }));
            },
            init() {
                // Modal-Footer liegt außerhalb des x-data-Scopes → Window-Event;
                // NUR die Standalone-(Modal-)Instanz lauscht (eingebettete hat eigenen Button)
                if (standalone) {
                    window.addEventListener('zutaten-speichern', () => this.$wire.speichern(this.payload()));
                }
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
</div>
