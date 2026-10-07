    {{-- Reiter ZIELE: links der Katalog (wählen, Menge eingeben, einfügen), rechts der Ziel-Korb.
         Ab Laptop-Breite nebeneinander, darunter gestapelt. --}}
    @php
        $segment = 'h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] font-medium transition-colors duration-150';
        $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
        $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    @endphp
    <div x-show="tab === 'ziele'" x-cloak class="pt-4"
         x-data="produktionZiele(@js($zielTyp), @js($zielVokabular ?? null))"
         data-produktion-ziele-picker>
    <x-fa::section title="Ziele" icon="heroicon-o-flag" description="Was soll produziert werden? Konzepte, Gerichte, Basisrezepte, Foodbook-Kapitel oder Angebote mit Menge übernehmen.">
        <div class="inline-flex flex-wrap items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] self-start" role="group" aria-label="Art des Ziels">
            <template x-for="t in typen" :key="t.key">
                <button type="button" @click="typSetzen(t.key)"
                        class="{{ $segment }}"
                        :class="zielTyp === t.key ? '{{ $segmentAn }}' : '{{ $segmentAus }}'"
                        :aria-pressed="zielTyp === t.key ? 'true' : 'false'"
                        x-text="t.label"></button>
            </template>
        </div>

        <div class="flex flex-col lg:flex-row gap-3 items-start">
            <aside class="w-full lg:w-72 xl:w-80 shrink-0 flex flex-col rounded-[var(--fa-radius-surface)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-2.5 lg:sticky lg:top-14 self-start max-h-[65vh]" data-produktion-katalog>
                <div x-show="zielTyp !== 'kapitel'" class="min-h-0 flex flex-col gap-2">
                    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]"><span x-text="typLabel()"></span> <span class="font-normal text-[var(--fa-ink-3)] tabular-nums" x-text="total > 0 ? '· ' + total + ' Treffer' : ''"></span></p>

                    <div x-show="zielTyp === 'recipe' || zielTyp === 'basisrezept'" x-cloak class="flex flex-col gap-1.5">
                        <x-fa::select size="sm" x-model="filter.hg" x-on:change="filter.kat = ''; browse()" aria-label="Hauptgruppe" data-ziel-filter-hg>
                            <option value="">Alle Hauptgruppen</option>
                            <template x-for="h in (vokabular?.hauptgruppen ?? [])" :key="h.id"><option :value="h.id" x-text="h.label"></option></template>
                        </x-fa::select>
                        <x-fa::select size="sm" x-model="filter.kat" x-on:change="browse()" aria-label="Kategorie" data-ziel-filter-kat>
                            <option value="">Alle Kategorien</option>
                            <template x-for="k in kategorienFuerHg()" :key="k.id"><option :value="k.id" x-text="k.label"></option></template>
                        </x-fa::select>
                        <x-fa::select size="sm" x-model="filter.niveau" x-on:change="browse()" aria-label="Niveau" data-ziel-filter-niveau>
                            <option value="">Alle Niveaus</option>
                            <template x-for="n in (vokabular?.niveaus ?? [])" :key="n.slug"><option :value="n.slug" x-text="n.label"></option></template>
                        </x-fa::select>
                    </div>

                    <div class="sticky top-0 z-10 rounded-[var(--fa-radius-control)] bg-[var(--fa-surface)] border border-[var(--fa-line)] px-2 py-2" data-ziel-parkbar>
                        <div x-show="geparkt === null" class="relative">
                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input type="search" size="sm" x-model="browseQ" x-on:focus="browseOnce()" x-on:input.debounce.300ms="sucheGetippt()"
                                   x-bind:placeholder="typLabel() + ' suchen'" aria-label="Im Katalog suchen"
                                   class="pl-8" data-produktion-gericht-suche />
                        </div>
                        <div x-show="geparkt !== null" x-cloak class="flex flex-col gap-2" data-produktion-park-zeile>
                            <p class="min-w-0 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] break-words" x-text="geparkt?.name"></p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-fa::input size="sm" numeric x-model="neu.menge" x-on:keydown.enter.prevent="einfuegen()" placeholder="Menge" aria-label="Menge"
                                       class="w-20" data-produktion-menge />
                                <span x-show="zielTyp !== 'basisrezept'" class="{{ $leise }}" x-text="zielTyp === 'concept' ? 'Personen' : 'Portionen'"></span>
                                <div x-show="zielTyp === 'basisrezept'" x-cloak class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] shrink-0" role="group" aria-label="Einheit" data-produktion-basis-einheit>
                                    <button type="button" @click="neu.einheit = 'ansaetze'" class="{{ $segment }}" :class="neu.einheit === 'ansaetze' ? '{{ $segmentAn }}' : '{{ $segmentAus }}'">Ansätze</button>
                                    <button type="button" @click="neu.einheit = 'kg'" class="{{ $segment }}" :class="neu.einheit === 'kg' ? '{{ $segmentAn }}' : '{{ $segmentAus }}'">kg</button>
                                </div>
                                {{-- Gericht: Darreichung wählen (Menge der Produktion kommt aus deren Grammatur). --}}
                                <select x-show="zielTyp === 'recipe' && formen.length > 1" x-cloak x-model="neu.form" aria-label="Darreichung"
                                        class="fa-control fa-select pr-8 h-8 w-auto max-w-full text-[length:var(--fa-text-sm)]" data-produktion-darreichung>
                                    <template x-for="f in formen" :key="f.id">
                                        <option :value="f.standard ? '' : String(f.id)" :selected="(f.standard ? '' : String(f.id)) === neu.form" x-text="f.label + (f.gramm ? ' · ' + f.gramm + ' g' : '') + (f.standard ? ' (Standard)' : '')"></option>
                                    </template>
                                </select>
                                <span x-show="zielTyp === 'recipe' && formen.length === 1" x-cloak class="{{ $leise }}" x-text="formen[0] ? formen[0].label + (formen[0].gramm ? ' · ' + formen[0].gramm + ' g' : '') : ''"></span>
                                <span class="ml-auto inline-flex items-center gap-1">
                                    <x-fa::icon-button icon="heroicon-m-x-mark" label="Auswahl verwerfen" size="sm" x-on:click="verwerfen()" />
                                    <x-fa::button variant="secondary" size="sm" icon="heroicon-m-plus" x-on:click="einfuegen()" data-produktion-ziel-einfuegen>Einfügen</x-fa::button>
                                </span>
                            </div>
                        </div>
                        <p class="mt-1.5 {{ $leise }}" x-text="zielTyp === 'angebot' ? 'Angebote werden mit ihrer eigenen Personenzahl übernommen.' : 'Mit Plus wählen, Menge eingeben, mit Enter einfügen.'"></p>
                    </div>

                    <div class="flex flex-col gap-px flex-1 min-h-0 overflow-y-auto -mx-1 px-1" data-produktion-kandidaten>
                        <template x-for="ziel in liste" :key="ziel.type + '-' + ziel.id">
                            <div class="group flex items-center gap-1.5 px-1.5 py-1 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)] text-[length:var(--fa-text-md)]" :data-produktion-kandidat="ziel.id">
                                <span class="min-w-0 flex-1 break-words leading-snug text-[var(--fa-ink)]" x-text="ziel.name" :title="ziel.name"></span>
                                <span x-show="zielTyp === 'angebot' && ziel.meta?.personen" class="shrink-0 {{ $leise }} tabular-nums" x-text="ziel.meta.personen + ' Personen'"></span>
                                <span x-show="(ziel.meta?.niveaus ?? []).length > 0" class="shrink-0 {{ $leise }}" x-text="niveauText(ziel.meta?.niveaus)"></span>
                                <button type="button" @click="parke(ziel)" data-parke
                                        class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-[var(--fa-radius-control)] text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft)]"
                                        :aria-label="ziel.name + ' übernehmen'" title="Übernehmen, dann Menge eingeben">@svg('heroicon-m-plus', 'w-4 h-4')</button>
                            </div>
                        </template>
                        <p x-show="liste.length === 0" class="px-1.5 py-2 {{ $leise }}" x-text="browserGeladen ? 'Keine Treffer. Suche oder Filter ändern.' : 'Ins Suchfeld klicken, dann erscheint der Katalog.'"></p>
                        <p x-show="total > 200" x-cloak class="px-1.5 py-1 {{ $leise }}" x-text="'Weitere ' + (total - 200) + ' Treffer. Suche oder Filter eingrenzen.'"></p>
                    </div>
                </div>

                <div x-show="zielTyp === 'kapitel'" x-cloak class="flex flex-col gap-3" data-produktion-kapitel>
                    <x-fa::field label="Foodbook" for="produktion-foodbook">
                        <x-fa::select id="produktion-foodbook" wire:model.live="auswahlFoodbookId" placeholder="Foodbook wählen" data-produktion-foodbook>
                            @foreach($foodbooks as $fb)
                                <option value="{{ $fb->id }}">{{ $fb->label }}</option>
                            @endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Kapitel" for="produktion-kapitel" :hint="$auswahlFoodbookId ? null : 'Erst ein Foodbook wählen.'">
                        <x-fa::select id="produktion-kapitel" wire:model.live="auswahlChapterId" :disabled="! $auswahlFoodbookId" placeholder="Kapitel wählen" data-produktion-kapitel-select>
                            @foreach($kapitelBaum as $k)
                                <option value="{{ $k['id'] }}">{!! str_repeat('&nbsp;&nbsp;', $k['depth']) !!}{{ $k['title'] }}</option>
                            @endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Personen" for="produktion-kapitel-personen" class="w-32">
                        <x-fa::input id="produktion-kapitel-personen" type="number" min="1" numeric wire:model="auswahlPersonen" data-produktion-kapitel-personen />
                    </x-fa::field>
                    @if(! empty($variantGroups))
                        <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-2.5" data-produktion-varianten>
                            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Varianten wählen</p>
                            @foreach($variantGroups as $g)
                                <x-fa::select size="sm" wire:model="variantChoices.{{ $g['group_id'] }}" wire:key="vg-{{ $g['group_id'] }}" aria-label="Variante" data-produktion-variante="{{ $g['group_id'] }}">
                                    @foreach($g['options'] as $opt)
                                        <option value="{{ $opt['block_id'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </x-fa::select>
                            @endforeach
                        </div>
                    @endif
                    <x-fa::button variant="secondary" icon="heroicon-m-plus" wire:click="zielHinzufuegen" class="self-start" data-produktion-ziel-hinzufuegen>Kapitel übernehmen</x-fa::button>
                </div>
            </aside>

            <aside class="w-full flex-1 min-w-0 rounded-[var(--fa-radius-surface)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-2.5" data-produktion-zielkorb>
                <div class="flex items-center justify-between gap-2 mb-2 px-1">
                    <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Ziel-Korb</p>
                    <span class="{{ $leise }} tabular-nums">{{ count($targets) }} {{ count($targets) === 1 ? 'Ziel' : 'Ziele' }}</span>
                </div>
                <div class="flex flex-col gap-1">
                    @forelse($targets as $t)
                        <div class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-surface)] border border-[var(--fa-line)] text-[length:var(--fa-text-md)]" wire:key="ziel-{{ $t['source_ref'] }}">
                            <span class="text-[var(--fa-ink)] min-w-0 break-words">{{ $t['label'] ?? 'Ohne Bezeichnung' }}</span>
                            <div class="flex items-center gap-0.5 shrink-0">
                                @unless(str_contains($t['source_ref'], ':c'))
                                    <x-fa::icon-button icon="heroicon-o-pencil" label="Ziel bearbeiten" size="sm" wire:click="zielBearbeiten('{{ $t['source_ref'] }}')" data-produktion-ziel-bearbeiten />
                                @endunless
                                <x-fa::icon-button icon="heroicon-o-x-mark" label="Ziel entfernen" size="sm" tone="danger" wire:click="zielEntfernen('{{ $t['source_ref'] }}')" data-produktion-ziel-entfernen />
                            </div>
                        </div>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-flag" title="Noch keine Ziele">Links im Katalog wählen und mit Menge einfügen.</x-fa::empty>
                    @endforelse
                </div>
            </aside>
        </div>
    </x-fa::section>

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
            neu: { menge: '', einheit: 'ansaetze', form: '' },
            formen: [],

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
            niveauText(slugs) {
                return (slugs ?? []).map(s => (this.vokabular?.niveaus ?? []).find(n => n.slug === s)?.label ?? s).join(', ');
            },
            badgeLabel() {
                return { concept: 'Konzept', recipe: 'Gericht', basisrezept: 'Basisrezept', angebot: 'Angebot', kapitel: 'Kapitel' }[this.zielTyp] ?? 'Ziel';
            },
            parke(ziel) {
                if (this.zielTyp === 'angebot') {
                    this.$wire.zielEinfuegen(this.zielTyp, ziel.id, 1, null);
                    return;
                }
                this.geparkt = ziel;
                this.neu.menge = this.zielTyp === 'basisrezept' ? '1' : '100';
                this.neu.einheit = 'ansaetze';
                this.neu.form = '';
                this.formen = [];
                if (this.zielTyp === 'recipe') {
                    this.$wire.darreichungenFuer(ziel.id).then(f => { if (this.geparkt?.id === ziel.id) this.formen = f; });
                }
                this.$nextTick(() => this.$root.querySelector('[data-produktion-menge]')?.focus());
            },
            verwerfen() {
                this.geparkt = null;
                this.neu.menge = '';
            },
            einfuegen() {
                if (this.geparkt === null) return;
                this.$wire.zielEinfuegen(this.zielTyp, this.geparkt.id, this.neu.menge, this.neu.einheit, this.neu.form || null);
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
