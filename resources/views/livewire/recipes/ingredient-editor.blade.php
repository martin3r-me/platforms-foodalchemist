{{-- M4-07/08 / P-8: Zutaten-Editor — Alpine-first; Kern geteilt mit dem Voll-Editor (partials/zutaten-kern) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-zutaten-editor-root>
    @if($eingebettet)
        @include('foodalchemist::livewire.recipes.partials.zutaten-kern')
    @else
        <x-foodalchemist::modal name="zutaten-editor" :title="'Zutaten bearbeiten' . ($rezept ? ' — ' . $rezept->name : '')" size="max-w-[100rem]">
            @include('foodalchemist::livewire.recipes.partials.zutaten-kern')

            <x-slot:footer>
                <button type="button" wire:click="$dispatch('modal.close', { name: 'zutaten-editor' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" x-data @click="$dispatch('zutaten-speichern')" class="{{ $btnPrimary }}" data-zutaten-speichern>Speichern</button>
            </x-slot:footer>
        </x-foodalchemist::modal>
    @endif

    <script>
    window.zutatenEditor = function (zeilen, standalone, einheiten, vokabular) {
        return {
            rows: zeilen.map((z, i) => ({ ...z, _key: 'z' + i })),
            einheiten,
            neu: { menge: '', einheit_vocab_id: Object.keys(einheiten)[0] ? parseInt(Object.keys(einheiten)[0]) : null, is_optional: false },
            _n: zeilen.length,

            // ── R18: Drei-Spalten-Browser — Filter-States + Listen (serverseitig gefiltert) ──
            vokabular,
            gpFilter: { wg: '', sub: '', zustand: '', bio: false, regional: false, mehr: false },
            rezFilter: { hg: '', kat: '', niveau: '', mehr: false },
            browseQ: '',                                             // zentrales Suchfeld → filtert BEIDE Listen
            gpListe: [], gpTotal: 0, rezListe: [], rezTotal: 0,
            geparkt: null,                                           // [+]-Klick parkt das Ziel in der Anzeigezeile
            tauschIdx: null,                                         // ⇄: Index der Zeile, deren Produkt getauscht wird (null = normaler Add-Flow)

            async browse() {
                const r = await this.$wire.browseKatalog(
                    { wg: this.gpFilter.wg, sub: this.gpFilter.sub, zustand: this.gpFilter.zustand, bio: this.gpFilter.bio, regional: this.gpFilter.regional },
                    { hg: this.rezFilter.hg, kat: this.rezFilter.kat, niveau: this.rezFilter.niveau },
                    this.browseQ
                );
                this.gpListe = r.gps.items; this.gpTotal = r.gps.total;
                this.rezListe = r.rezepte.items; this.rezTotal = r.rezepte.total;
            },
            subKategorienFuerWg() {
                return (this.vokabular?.subKategorien ?? []).filter(s => this.gpFilter.wg === '' || s.warengruppe_code === this.gpFilter.wg);
            },
            kategorienFuerHg() {
                return (this.vokabular?.kategorien ?? []).filter(k => this.rezFilter.hg === '' || String(k.main_group_id) === String(this.rezFilter.hg));
            },
            einheitIdFuerSlug(slug) {
                for (const [id, e] of Object.entries(this.einheiten)) { if (e.slug === slug) return parseInt(id); }
                return this.neu.einheit_vocab_id;
            },
            niveauFarbe(n) {
                return { haute_cuisine: 'bg-violet-500', gehoben: 'bg-amber-500', klassisch: 'bg-sky-400' }[n] ?? 'bg-gray-300';
            },
            // Spec-Flow: [+] parkt das Ziel, Einheit kommt vom Produkt, Cursor springt in die Menge
            parke(ziel) {
                if (this.tauschIdx != null) { this.tauscheAus(ziel); return; }   // ⇄ Tausch-Modus (loose: undefined zählt als „aus")
                this.geparkt = ziel;
                this.neu.menge = '';
                this.neu.einheit_vocab_id = this.einheitIdFuerSlug(ziel.einheit_slug ?? 'g');
                this.$nextTick(() => this.$root.querySelector('[data-park-menge]')?.focus());
                // Listen tragen nur den Bulk-Ø — präzisen Lead-€/g (T3) leise nachladen
                this.$wire.ekFuerZiel(ziel.typ, ziel.id).then(ek => { if (ek !== null && this.geparkt === ziel) ziel.ek_pro_g = ek; });
            },
            verwerfen() { this.geparkt = null; this.neu.menge = ''; },
            // Menge tippen + Enter → Zutat wandert in die Liste und blinkt kurz auf
            einfuegen() {
                if (this.geparkt === null || this.zahl(this.neu.menge) === null) return;
                this.hinzufuegen(this.geparkt);
                const z = this.rows[this.rows.length - 1];
                z._flash = true;
                setTimeout(() => { z._flash = false; }, 1600);
                this.geparkt = null;
                this.$nextTick(() => this.$root.querySelector('[data-browse-suche]')?.focus());
            },
            // Spec-Annahme: tippt der Nutzer bei geparktem Produkt im Suchfeld, wird verworfen
            sucheGetippt() {
                if (this.geparkt !== null) this.geparkt = null;
                this.browse();
            },

            // ── ⇄ Tausch: Produkt einer bestehenden Zeile ersetzen (Menge/Einheit/Rolle/Note/Position bleiben) ──
            starteTausch(i) {
                this.tauschIdx = i;
                this.geparkt = null;                                 // evtl. laufenden Park-Flow abbrechen
                this.$nextTick(() => this.$root.querySelector('[data-browse-suche]')?.focus());
            },
            tauscheAus(ziel) {
                const i = this.tauschIdx;
                if (i === null || !this.rows[i]) { this.tauschIdx = null; return; }
                const z = this.rows[i];
                z.gp_id = ziel.typ === 'gp' ? ziel.id : null;        // löschen bleibt unangetastet — hier wird NUR ersetzt
                z.referenced_recipe_id = ziel.typ === 'sub' ? ziel.id : null;
                z.ziel_name = ziel.name;
                z.ziel_url = ziel.url ?? null;
                z.display_name = ziel.name;
                z.raw_text = (this.zahl(z.menge) ?? '') + ' ' + ziel.name;
                z.lineage = ziel.typ === 'sub' ? 'recipe_ref' : 'manual';
                z.ek_pro_g = ziel.ek_pro_g ?? null;
                z.ek_pro_g_min = null; z.ek_pro_g_avg = null;        // alte Min/Ø verwerfen — Save rechnet präzise nach
                z._peek = null;
                z._flash = true; setTimeout(() => { z._flash = false; }, 1600);
                this.$wire.ekFuerZiel(ziel.typ, ziel.id).then(ek => { if (ek !== null) z.ek_pro_g = ek; });
                this.tauschIdx = null;
                this.$nextTick(() => this.$root.querySelector('[data-browse-suche]')?.focus());
            },

            dragIdx: null,
            verschiebe(i, dir) {                                  // R15: Jarvis moveUpDown — DnD-unabhängig
                const ziel = i + dir;
                if (ziel < 0 || ziel >= this.rows.length) return;
                const [z] = this.rows.splice(i, 1);
                this.rows.splice(ziel, 0, z);
            },
            dropAuf(i) {
                if (this.dragIdx === null || this.dragIdx === i) { this.dragIdx = null; return; }
                const [z] = this.rows.splice(this.dragIdx, 1);
                this.rows.splice(i, 0, z);
                this.dragIdx = null;
            },
            async peek(zeile) {  // D-5 §4.2.3: LA-Tabelle hinter dem GP, lazy vom Server
                if (!zeile.gp_id) return;                            // R21: Zeile ohne GP — nichts zu peeken
                if (zeile._peek) { zeile._peek = null; return; }
                zeile._peek = await this.$wire.gpArtikel(zeile.gp_id);
            },
            payload() {
                return this.rows.map(({ _key, ziel_name, ziel_url, lineage, ek_pro_g, ek_pro_g_min, ek_pro_g_avg, _garverlust_ki, _peek, _flash, ...rest }) => ({ ...rest, garverlust_quelle: _garverlust_ki ? 'ki' : undefined }));
            },
            init() {
                // Window-Event: der Haupt-"Speichern" des Rezept-Modals (UND der Standalone-Modal-Footer)
                // stoßen damit das Zutaten-Speichern an. Auch die EINGEBETTETE Instanz lauscht jetzt —
                // sonst gehen Zutaten-Edits (Garverlust/Menge/Tausch) verloren, wenn man "Speichern"
                // statt des separaten "Zutaten speichern" klickt.
                window.addEventListener('zutaten-speichern', () => this.$wire.speichern(this.payload()));
                this.browse();                                       // R18: Seitenspalten initial füllen
            },
            zahl(v) { const n = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(n) ? null : n; },
            mengeAvg(z) {
                const m = this.zahl(z.menge); const mx = this.zahl(z.menge_max);
                return m === null ? null : (mx !== null ? (m + mx) / 2 : m);
            },
            zeilenEk(z, feld = 'ek_pro_g') {  // Live-Näherung: menge_g × €/g; R5: feld wählt Lead | min | Ø
                if (z.is_optional || z[feld] === null || z[feld] === undefined) return null;
                const avg = this.mengeAvg(z); const f = this.einheiten[z.einheit_vocab_id]?.g;
                if (avg === null || !f) return null;
                return (avg * f * z[feld]).toFixed(2).replace('.', ',') + ' €';
            },
            summe(feld = 'ek_pro_g') {
                let s = 0;
                for (const z of this.rows) {
                    const w = this.zeilenEk(z, feld);
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
            hinzufuegen(ziel) {  // Auto-Fill (M4-08) — R18: interner Schritt des Park-Flows
                this.rows.push({
                    _key: 'n' + (++this._n),
                    id: null,
                    gp_id: ziel.typ === 'gp' ? ziel.id : null,
                    referenced_recipe_id: ziel.typ === 'sub' ? ziel.id : null,
                    ziel_name: ziel.name,
                    ziel_url: ziel.url ?? null,
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
                this.neu.menge = ''; this.neu.is_optional = false;
            },
        };
    };
</script>
</div>
