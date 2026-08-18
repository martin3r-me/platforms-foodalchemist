    <div x-show="tab === 'ziele'" x-cloak class="pt-4"
         x-data="produktionZiele(@js($zielTyp), @js($zielVokabular ?? null))"
         data-produktion-ziele-picker>
    <x-foodalchemist::modal-section title="Ziele">
        <div class="flex items-center gap-2 mb-3">
            <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs">
                <template x-for="t in typen" :key="t.key">
                    <button type="button" @click="typSetzen(t.key)"
                            class="px-3 py-1 rounded-md"
                            :class="zielTyp === t.key ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600'"
                            x-text="t.label"></button>
                </template>
            </div>
        </div>

        <div class="flex flex-col xl:flex-row gap-3 items-start">
            <aside class="w-full xl:w-80 shrink-0 flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-0 self-start max-h-[70vh]" data-produktion-katalog>
                <div x-show="zielTyp !== 'kapitel'" class="min-h-0 flex flex-col">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <p class="{{ $dt }}"><span x-text="typLabel()"></span> (<span x-text="total"></span>)</p>
                        <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider bg-violet-500/10 text-violet-700" x-text="badgeLabel()"></span>
                    </div>

                    <div x-show="zielTyp === 'recipe' || zielTyp === 'basisrezept'" x-cloak class="space-y-1 mb-1.5">
                        <select x-model="filter.hg" @change="filter.kat = ''; browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-ziel-filter-hg>
                            <option value="">Alle Hauptgruppen</option>
                            <template x-for="h in (vokabular?.hauptgruppen ?? [])" :key="h.id"><option :value="h.id" x-text="h.label"></option></template>
                        </select>
                        <select x-model="filter.kat" @change="browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-ziel-filter-kat>
                            <option value="">Alle Kategorien</option>
                            <template x-for="k in kategorienFuerHg()" :key="k.id"><option :value="k.id" x-text="k.label"></option></template>
                        </select>
                        <select x-model="filter.niveau" @change="browse()" class="{{ $input }} !py-0.5 !text-[11px]" data-ziel-filter-niveau>
                            <option value="">Alle Niveaus</option>
                            <template x-for="n in (vokabular?.niveaus ?? [])" :key="n.slug"><option :value="n.slug" x-text="n.label"></option></template>
                        </select>
                    </div>

                    <div class="sticky top-0 z-10 mb-2 rounded-lg bg-white/90 backdrop-blur border border-black/5 px-2 py-2" data-ziel-parkbar>
                        <div x-show="geparkt === null" class="flex items-center gap-2">
                            <input type="search" x-model="browseQ" @focus="browseOnce()" @input.debounce.300ms="sucheGetippt()"
                                   :placeholder="'Suchen - ' + typLabel() + ' per [+] parken'"
                                   class="{{ $input }} !py-1 flex-1" data-produktion-gericht-suche />
                        </div>
                        <div x-show="geparkt !== null" x-cloak class="flex items-center gap-2" data-produktion-park-zeile>
                            <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider bg-violet-500/10 text-violet-700" x-text="badgeLabel()"></span>
                            <span class="min-w-0 flex-1 truncate text-[11px] font-medium text-gray-900" x-text="geparkt?.name"></span>
                            <input type="text" x-model="neu.menge" @keydown.enter.prevent="einfuegen()" placeholder="Menge"
                                   class="{{ $input }} !w-20 !py-1 text-right" data-produktion-menge />
                            <div x-show="zielTyp === 'basisrezept'" x-cloak class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs shrink-0" data-produktion-basis-einheit>
                                <button type="button" @click="neu.einheit = 'ansaetze'" class="px-2 py-1 rounded-md" :class="neu.einheit === 'ansaetze' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600'">Ans.</button>
                                <button type="button" @click="neu.einheit = 'kg'" class="px-2 py-1 rounded-md" :class="neu.einheit === 'kg' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600'">kg</button>
                            </div>
                            <button type="button" @click="einfuegen()" class="{{ $btnGhostXs }} text-emerald-600 shrink-0" data-produktion-ziel-einfuegen>Einfügen</button>
                            <button type="button" @click="verwerfen()" class="{{ $btnGhostXs }} shrink-0" title="Verwerfen">@svg('heroicon-o-x-mark', 'w-3.5 h-3.5')</button>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1" x-text="zielTyp === 'angebot' ? 'Angebote werden mit ihrer eigenen Personenzahl übernommen.' : 'Erst per [+] wählen, dann Menge eingeben und Enter.'"></p>
                    </div>

                    <div class="space-y-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1" data-produktion-kandidaten>
                        <template x-for="ziel in liste" :key="ziel.type + '-' + ziel.id">
                            <div class="group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-violet-500/5 text-[11px]" :data-produktion-kandidat="ziel.id">
                                <span class="shrink-0 px-1 rounded text-[9px] font-medium uppercase tracking-wider bg-violet-500/10 text-violet-700" x-text="badgeLabel()"></span>
                                <span class="min-w-0 flex-1 break-words leading-snug text-gray-700" x-text="ziel.name" :title="ziel.name"></span>
                                <span x-show="zielTyp === 'angebot' && ziel.meta?.personen" class="shrink-0 text-[10px] text-gray-500 tabular-nums" x-text="ziel.meta.personen + ' P.'"></span>
                                <span x-show="(ziel.meta?.niveaus ?? []).length > 0" class="shrink-0 flex gap-0.5">
                                    <template x-for="n in ziel.meta.niveaus" :key="n"><span class="w-1.5 h-1.5 rounded-full bg-violet-400" :title="n"></span></template>
                                </span>
                                <button type="button" @click="parke(ziel)" data-parke
                                        class="shrink-0 px-1 rounded font-medium text-violet-500 hover:bg-violet-500/15 leading-none"
                                        title="übernehmen -> Menge eingeben">+</button>
                            </div>
                        </template>
                        <p x-show="liste.length === 0" class="text-[10px] text-gray-500 px-1">- keine Treffer -</p>
                        <p x-show="total > 200" x-cloak class="text-[10px] text-gray-500 px-1" x-text="'... ' + (total - 200) + ' weitere - Filter verengen'"></p>
                    </div>
                </div>

                <div x-show="zielTyp === 'kapitel'" x-cloak class="space-y-2" data-produktion-kapitel>
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
                    <div class="w-28">
                        <label class="{{ $label }}">Personen</label>
                        <input type="number" min="1" wire:model="auswahlPersonen" class="{{ $input }}" data-produktion-kapitel-personen />
                    </div>
                    @if(! empty($variantGroups))
                        <div class="rounded-lg border border-black/5 bg-black/[0.02] p-2 space-y-2" data-produktion-varianten>
                            <p class="{{ $label }}">Varianten-Wahl</p>
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
            </aside>

            <aside class="w-full flex-1 min-w-0 rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5" data-produktion-zielkorb>
                <div class="flex items-center justify-between gap-2 mb-2">
                    <p class="{{ $dt }}">Ziel-Korb</p>
                    <span class="text-[11px] text-gray-500">{{ count($targets) }} Ziele</span>
                </div>
                <div class="space-y-1">
                    @forelse($targets as $t)
                        <div class="flex items-center justify-between gap-2 text-[12px] px-2 py-1.5 rounded-lg bg-white/70 border border-black/5" wire:key="ziel-{{ $t['source_ref'] }}">
                            <span class="text-gray-800 min-w-0 truncate">{{ $t['label'] ?? '—' }}</span>
                            <div class="flex items-center gap-2 shrink-0">
                                @unless(str_contains($t['source_ref'], ':c'))
                                    <button type="button" wire:click="zielBearbeiten('{{ $t['source_ref'] }}')" class="text-gray-400 hover:text-violet-600" title="Bearbeiten" data-produktion-ziel-bearbeiten>@svg('heroicon-o-pencil', 'w-3.5 h-3.5')</button>
                                @endunless
                                <button type="button" wire:click="zielEntfernen('{{ $t['source_ref'] }}')" class="text-rose-500" title="Entfernen" data-produktion-ziel-entfernen>@svg('heroicon-o-x-mark', 'w-3.5 h-3.5')</button>
                            </div>
                        </div>
                    @empty
                        <p class="text-[12px] text-gray-500 px-2 py-3">Noch keine Ziele.</p>
                    @endforelse
                </div>
            </aside>
        </div>
    </x-foodalchemist::modal-section>

    @assets
    <script>
    window.produktionZiele = function (initialTyp, vokabular) {
        return {
            typen: [
                { key: 'concept', label: 'Konzept' },
                { key: 'recipe', label: 'Gericht' },
                { key: 'basisrezept', label: 'Basisrezept' },
                { key: 'kapitel', label: 'Kapitel' },
                { key: 'angebot', label: 'Angebot' },
            ],
            zielTyp: initialTyp || 'concept',
            vokabular,
            filter: { hg: '', kat: '', niveau: '' },
            browseQ: '',
            liste: [],
            total: 0,
            browserGeladen: false,
            geparkt: null,
            neu: { menge: '', einheit: 'ansaetze' },

            async typSetzen(typ) {
                this.zielTyp = typ;
                this.geparkt = null;
                this.liste = [];
                this.total = 0;
                this.browserGeladen = false;
                this.filter = { hg: '', kat: '', niveau: '' };
                this.neu.einheit = 'ansaetze';
                if (typ === 'kapitel') {
                    await this.$wire.set('zielTyp', 'kapitel');
                    return;
                }
                this.browse();
            },
            async browse() {
                if (this.zielTyp === 'kapitel') return;
                this.browserGeladen = true;
                const r = await this.$wire.browseZiele(this.zielTyp, this.filter, this.browseQ);
                this.liste = r.items;
                this.total = r.total;
            },
            browseOnce() {
                if (!this.browserGeladen) this.browse();
            },
            kategorienFuerHg() {
                return (this.vokabular?.kategorien ?? []).filter(k => this.filter.hg === '' || String(k.main_group_id) === String(this.filter.hg));
            },
            typLabel() {
                return (this.typen.find(t => t.key === this.zielTyp)?.label ?? 'Ziele');
            },
            badgeLabel() {
                return { concept: 'K', recipe: 'G', basisrezept: 'BR', angebot: 'A', kapitel: 'Kap' }[this.zielTyp] ?? 'Z';
            },
            parke(ziel) {
                if (this.zielTyp === 'angebot') {
                    this.$wire.zielEinfuegen(this.zielTyp, ziel.id, 1, null);
                    return;
                }
                this.geparkt = ziel;
                this.neu.menge = this.zielTyp === 'basisrezept' ? '1' : '100';
                this.neu.einheit = 'ansaetze';
                this.$nextTick(() => this.$root.querySelector('[data-produktion-menge]')?.focus());
            },
            verwerfen() {
                this.geparkt = null;
                this.neu.menge = '';
            },
            einfuegen() {
                if (this.geparkt === null) return;
                this.$wire.zielEinfuegen(this.zielTyp, this.geparkt.id, this.neu.menge, this.neu.einheit);
                this.geparkt = null;
                this.neu.menge = '';
                this.$nextTick(() => this.$root.querySelector('[data-produktion-gericht-suche]')?.focus());
            },
            sucheGetippt() {
                if (this.geparkt !== null) this.geparkt = null;
                this.browse();
            },
        };
    };
    </script>
    @endassets
    </div>{{-- /Ziele-Panel --}}
