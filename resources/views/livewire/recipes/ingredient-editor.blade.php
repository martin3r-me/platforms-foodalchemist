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

    {{-- @assets: global registrieren, damit window.zutatenEditor VOR Alpine existiert —
         auch wenn die Komponente per Livewire-Morph kommt (VK-Modal). Inline-Script wurde
         bei Re-Renders nicht ausgeführt → Alpine-x-data schlug fehl (rezFilter/vokabular undefined). --}}
    @assets
    <script>
    window.zutatenEditor = function (zeilen, standalone, einheiten, vokabular) {
        return {
            rows: zeilen.map((z, i) => ({ ...z, _key: 'z' + i })),
            einheiten,
            neu: { quantity: '', unit_vocab_id: Object.keys(einheiten)[0] ? parseInt(Object.keys(einheiten)[0]) : null, is_optional: false },
            _n: zeilen.length,

            // ── R18: Drei-Spalten-Browser — Filter-States + Listen (serverseitig gefiltert) ──
            vokabular,
            gpFilter: { wg: '', sub: '', condition: '', bio: false, regional: false, mehr: false },
            rezFilter: { hg: '', kat: '', niveau: '', mehr: false },
            browseQ: '',                                             // zentrales Suchfeld → filtert BEIDE Listen
            gpListe: [], gpTotal: 0, rezListe: [], rezTotal: 0,
            geparkt: null,                                           // [+]-Klick parkt das Ziel in der Anzeigezeile
            tauschIdx: null,                                         // ⇄: Index der Zeile, deren Produkt getauscht wird (null = normaler Add-Flow)

            async browse() {
                const r = await this.$wire.browseKatalog(
                    { wg: this.gpFilter.wg, sub: this.gpFilter.sub, condition: this.gpFilter.condition, bio: this.gpFilter.bio, regional: this.gpFilter.regional },
                    { hg: this.rezFilter.hg, kat: this.rezFilter.kat, niveau: this.rezFilter.level },
                    this.browseQ
                );
                this.gpListe = r.gps.items; this.gpTotal = r.gps.total;
                this.rezListe = r.rezepte.items; this.rezTotal = r.rezepte.total;
            },
            subKategorienFuerWg() {
                return (this.vokabular?.subKategorien ?? []).filter(s => this.gpFilter.wg === '' || s.commodity_group_code === this.gpFilter.wg);
            },
            kategorienFuerHg() {
                return (this.vokabular?.kategorien ?? []).filter(k => this.rezFilter.hg === '' || String(k.main_group_id) === String(this.rezFilter.hg));
            },
            einheitIdFuerSlug(slug) {
                for (const [id, e] of Object.entries(this.einheiten)) { if (e.slug === slug) return parseInt(id); }
                return this.neu.unit_vocab_id;
            },
            niveauFarbe(n) {
                return { haute_cuisine: 'bg-violet-500', gehoben: 'bg-amber-500', klassisch: 'bg-sky-400' }[n] ?? 'bg-gray-300';
            },
            // Spec-Flow: [+] parkt das Ziel, Einheit kommt vom Produkt, Cursor springt in die Menge
            parke(ziel) {
                if (this.tauschIdx != null) { this.tauscheAus(ziel); return; }   // ⇄ Tausch-Modus (loose: undefined zählt als „aus")
                this.geparkt = ziel;
                this.neu.quantity = '';
                this.neu.unit_vocab_id = this.einheitIdFuerSlug(ziel.einheit_slug ?? 'g');
                this.$nextTick(() => this.$root.querySelector('[data-park-quantity]')?.focus());
                // Listen tragen nur den Bulk-Ø — präzisen Lead-€/g (T3) leise nachladen
                this.$wire.ekFuerZiel(ziel.type, ziel.id).then(ek => { if (ek !== null && this.geparkt === ziel) ziel.ek_pro_g = ek; });
            },
            verwerfen() { this.geparkt = null; this.neu.quantity = ''; },
            // Menge tippen + Enter → Zutat wandert in die Liste und blinkt kurz auf
            einfuegen() {
                if (this.geparkt === null || this.zahl(this.neu.quantity) === null) return;
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

            // ── ♻ Ersatz-Tausch (Äquivalenz-Katalog): 1 Klick tauscht auf die hinterlegte
            //    Gegenseite (make-or-buy / Artikel-Ersatz), Menge × faktor. Der inverse
            //    Hinweis wird direkt gesetzt — nochmal klicken tauscht zurück. ──
            ersatzTausch(i) {
                const z = this.rows[i];
                const e = z?.ersatz;
                if (!e) return;
                const zurueck = {                                    // Rückweg aus dem alten Stand bauen
                    kind: z.gp_id ? 'gp' : 'recipe',
                    id: z.gp_id ?? z.referenced_recipe_id,
                    name: z.ziel_name, url: z.ziel_url,
                    faktor: e.faktor ? Math.round(10000 / e.faktor) / 10000 : 1,
                };
                const m = this.zahl(z.quantity);
                if (m !== null) z.quantity = Math.round(m * (e.faktor || 1) * 100) / 100;
                const mx = this.zahl(z.quantity_max);
                if (mx !== null) z.quantity_max = Math.round(mx * (e.faktor || 1) * 100) / 100;
                z.gp_id = e.kind === 'gp' ? e.id : null;
                z.referenced_recipe_id = e.kind === 'recipe' ? e.id : null;
                z.ziel_name = e.name; z.display_name = e.name.replace('↳ ', '');
                z.ziel_url = e.url ?? null;
                z.raw_text = (this.zahl(z.quantity) ?? '') + ' ' + e.name.replace('↳ ', '');
                z.lineage = e.kind === 'recipe' ? 'recipe_ref' : 'manual';
                z.ek_pro_g = null; z.ek_pro_g_min = null; z.ek_pro_g_avg = null;
                z.g_pro_stueck = null;                               // Stück-Faktor kennt nur der Save-Recompute präzise
                z._peek = null;
                z.ersatz = zurueck;
                z._flash = true; setTimeout(() => { z._flash = false; }, 1600);
                this.$wire.ekFuerZiel(e.kind === 'gp' ? 'gp' : 'sub', e.id).then(ek => { if (ek !== null) z.ek_pro_g = ek; });
            },
            ladeErsatz(z) {                                          // neue/gebrowste Zeile: Hinweis leise nachladen
                this.$wire.ersatzFuer(z.gp_id, z.referenced_recipe_id).then(e => { z.ersatz = e; });
            },
            ersatzTitel(z) {
                if (!z.ersatz) return '';
                const f = z.ersatz.faktor;
                return 'Ersatz: ' + z.ersatz.name + (f && f !== 1 ? ' (Menge ×' + String(f).replace('.', ',') + ')' : '') + ' — Klick tauscht um';
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
                z.gp_id = ziel.type === 'gp' ? ziel.id : null;        // löschen bleibt unangetastet — hier wird NUR ersetzt
                z.referenced_recipe_id = ziel.type === 'sub' ? ziel.id : null;
                z.ziel_name = ziel.name;
                z.ziel_url = ziel.url ?? null;
                z.display_name = ziel.name;
                z.raw_text = (this.zahl(z.quantity) ?? '') + ' ' + ziel.name;
                z.lineage = ziel.type === 'sub' ? 'recipe_ref' : 'manual';
                z.ek_pro_g = ziel.ek_pro_g ?? null;
                z.g_pro_stueck = ziel.g_pro_stueck ?? null;          // Stück-Sub: g/Stück fürs Live-Rechnen
                z.ek_pro_g_min = null; z.ek_pro_g_avg = null;        // alte Min/Ø verwerfen — Save rechnet präzise nach
                z._peek = null;
                z.ersatz = null; this.ladeErsatz(z);                 // ♻-Hinweis fürs neue Produkt nachladen
                z._flash = true; setTimeout(() => { z._flash = false; }, 1600);
                this.$wire.ekFuerZiel(ziel.type, ziel.id).then(ek => { if (ek !== null) z.ek_pro_g = ek; });
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
                return this.rows.map(({ _key, ziel_name, ziel_url, lineage, ek_pro_g, ek_pro_g_min, ek_pro_g_avg, ersatz, _garverlust_ki, _peek, _flash, ...rest }) => ({ ...rest, cooking_loss_source: _garverlust_ki ? 'ki' : undefined }));
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
                const m = this.zahl(z.quantity); const mx = this.zahl(z.quantity_max);
                return m === null ? null : (mx !== null ? (m + mx) / 2 : m);
            },
            // g-Faktor je Zeile: g/ml-Einheit ODER (Stück-Sub) g/Stück = Yield÷yield_pieces (spiegelt Server-grammFaktor)
            gFaktor(z) { return this.einheiten[z.unit_vocab_id]?.g ?? z.g_pro_stueck ?? null; },
            zeilenEk(z, feld = 'ek_pro_g') {  // Live-Näherung: menge_g × €/g; R5: feld wählt Lead | min | Ø
                if (z.is_optional || z[feld] === null || z[feld] === undefined) return null;
                const avg = this.mengeAvg(z); const f = this.gFaktor(z);
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
            // Live-Yield (Näherung): Σ menge_g × (1−putz) × (1−garv) — reagiert sofort auf Garverlust/Stück,
            // ohne Save. Putzverlust-DEFAULTS (GP/Team) kennt der Client nicht → präzise erst beim Save-Recompute.
            yieldLive() {
                let g = 0;
                for (const z of this.rows) {
                    if (z.is_optional) continue;
                    const f = this.gFaktor(z); const avg = this.mengeAvg(z);
                    if (avg === null || !f) continue;
                    const garv = (this.zahl(z.cooking_loss_pct) ?? 0) / 100;
                    const putz = (this.zahl(z.trimming_loss_pct) ?? 0) / 100;
                    g += avg * f * (1 - putz) * (1 - garv);
                }
                return g > 0 ? (g / 1000).toFixed(3).replace('.', ',') + ' kg' : '—';
            },
            hoch(i) { if (i > 0) this.rows.splice(i - 1, 0, this.rows.splice(i, 1)[0]); },
            runter(i) { if (i < this.rows.length - 1) this.rows.splice(i + 1, 0, this.rows.splice(i, 1)[0]); },
            async garverluste() {  // M4-11: Vorschläge in die Client-rows mergen (Save schreibt source=ki)
                const zutaten = {};
                this.rows.forEach((z, i) => { zutaten[i] = z.raw_text; });
                const v = await this.$wire.garverlustVorschlag(zutaten);
                for (const [i, pct] of Object.entries(v.verluste ?? {})) {
                    if (this.rows[i] !== undefined) { this.rows[i].cooking_loss_pct = pct; this.rows[i]._garverlust_ki = true; }
                }
            },
            hinzufuegen(ziel) {  // Auto-Fill (M4-08) — R18: interner Schritt des Park-Flows
                this.rows.push({
                    _key: 'n' + (++this._n),
                    id: null,
                    gp_id: ziel.type === 'gp' ? ziel.id : null,
                    referenced_recipe_id: ziel.type === 'sub' ? ziel.id : null,
                    ziel_name: ziel.name,
                    ziel_url: ziel.url ?? null,
                    raw_text: (this.neu.quantity || '') + ' ' + ziel.name,
                    display_name: ziel.name,
                    quantity: this.zahl(this.neu.quantity) ?? 1,
                    quantity_max: null,
                    unit_vocab_id: this.neu.unit_vocab_id,
                    cooking_loss_pct: null, trimming_loss_pct: null,
                    is_optional: this.neu.is_optional,
                    note: '', role: null, is_value_relevant: false,
                    lineage: ziel.type === 'sub' ? 'recipe_ref' : 'manual',
                    ek_pro_g: ziel.ek_pro_g,
                    g_pro_stueck: ziel.g_pro_stueck ?? null,          // Stück-Sub: g/Stück fürs Live-Rechnen
                    ersatz: null,
                });
                this.ladeErsatz(this.rows[this.rows.length - 1]);     // ♻-Hinweis leise nachladen
                this.neu.quantity = ''; this.neu.is_optional = false;
            },
        };
    };
</script>
    @endassets
</div>
