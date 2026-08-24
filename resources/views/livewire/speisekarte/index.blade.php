{{-- Speisekarte-Editor (Stufe A) — Browser links, Karten-Editor rechts (Rubrik-Baum,
     Gericht-Picker, Live-Preis). Dritte Ausgabeform neben Foodbook + Speiseplan. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
{{-- Spec 33 P0: Labels und Farben aus dem Enum. `veroeffentlicht` ist entfallen —
     Veröffentlichen setzt auf `aktiv`, es gibt keinen Zustand daneben. --}}
@php($statusLabel = \Platform\FoodAlchemist\Enums\AusgabeStatus::optionen())
@php($statusVariant = collect(\Platform\FoodAlchemist\Enums\AusgabeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->badgeVariant()])->all())
@php($typLabel = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte', 'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Speisekarte" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Speisekarte'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Speisekarten" width="w-72">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Karte suchen …" class="{{ $input }}" />
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center" data-sk-neu>+ Neue Karte</button>
                <div class="mt-2 space-y-1">
                    @forelse($karten as $k)
                        <button type="button" wire:key="sk-{{ $k->id }}" wire:click="waehle({{ $k->id }})" data-sk-zeile="{{ $k->id }}"
                            class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition-all {{ $karteId === $k->id ? 'bg-violet-500/10 text-violet-700' : 'hover:bg-black/[0.03] text-gray-700' }}">
                            <div class="font-medium truncate">{{ $k->name }}</div>
                            <div class="text-[10px] text-gray-500">{{ $typLabel[$k->karten_typ] ?? $k->karten_typ }} · {{ $k->sections_count }} Rubriken</div>
                        </button>
                    @empty
                        <div class="px-2 py-6 text-center text-[11px] text-gray-500">Keine Karten. Oben „+ Neue Karte".</div>
                    @endforelse
                </div>
                <div class="pt-1">{{ $karten->links() }}</div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechtes Detail-Panel (read-only Info): Logo · Status/Datum/Nummer · Eckdaten der Auswahl --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-80" scope="activity_speisekarte" side="right" icon="heroicon-o-information-circle" :default-open="true">
            @if($karte)
                @include('foodalchemist::livewire.speisekarte.partials.detail', ['karte' => $karte])
            @else
                <div class="p-4 text-[11px] text-gray-400">Wähle links eine Karte, um Details zu sehen.</div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if(! $karte)
            <div class="relative overflow-hidden {{ $card }} p-10 text-center text-sm text-gray-500">
                <div class="{{ $cardAccent }}"></div>
                Wähle links eine Speisekarte oder lege eine neue an.
            </div>
        @else
            {{-- Vorschau-Kopf: Aktionen. „Bearbeiten" öffnet den Editor als Fullscreen-Modal (Foodbook-Muster). --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold tracking-tight text-gray-900 truncate">{{ $karte->name }}</h1>
                    <p class="text-[11px] text-gray-500 flex items-center gap-1.5">{{ $typLabel[$karte->karten_typ] ?? $karte->karten_typ }} · <span class="{{ $pill }} {{ $variantPill[$karte->statusWert()->badgeVariant()] }}">{{ $karte->statusWert()->label() }}</span></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="$dispatch('modal.open', { name: 'speisekarte-editor' })" class="{{ $btnPrimary }}" data-sk-bearbeiten>@svg('heroicon-o-pencil-square', 'w-4 h-4') Bearbeiten</button>
                    <a href="{{ route('foodalchemist.speisekarte.dokument', $karte->id) }}" target="_blank" class="{{ $btnGhost }}">Dokument</a>
                    <a href="{{ route('foodalchemist.speisekarte.praesentation', $karte->id) }}" target="_blank" class="{{ $btnGhost }}">Präsentation</a>
                    <button type="button" wire:click="duplizieren" class="{{ $btnGhost }}">Duplizieren</button>
                </div>
            </div>

            {{-- Live-Ergebnis (Kundensicht, read-only) — Wording aufgelöst, Preise, Fußnoten. --}}
            @include('foodalchemist::livewire.speisekarte.partials.vorschau', ['vorschau' => $vorschau])

            {{-- ═══════════════ EDITOR = Fullscreen-Modal (Foodbook-Muster) ═══════════════ --}}
            <x-foodalchemist::modal name="speisekarte-editor" fullscreen dark-canvas title="Speisekarte bearbeiten" :title-name="$name">
                <x-slot:actions>
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-sk-speichern>Speichern</button>
                    <button type="button" wire:click="loeschen" wire:confirm="Diese Speisekarte wirklich löschen?" class="{{ $btnGhostXs }} text-red-600" data-sk-loeschen>Löschen</button>
                </x-slot:actions>

                {{-- Voll-Umbau 2026-08-03: KPI-Streifen fix im Kopf (Editor-Parität). --}}
                <x-slot:kpiHeader>
                    <x-foodalchemist::kpi-tiles marker="sk-editor-kpis" :cols="4" :tiles="[
                        ['kpi' => 'typ', 'label' => 'Kartentyp', 'value' => $typLabel[$karte->karten_typ] ?? $karte->karten_typ, 'tone' => 'accent'],
                        ['kpi' => 'status', 'label' => 'Status', 'value' => $karte->statusWert()->label(), 'tone' => (['success' => 'good', 'warning' => 'warn', 'danger' => 'bad'][$karte->statusWert()->badgeVariant()] ?? 'neutral')],
                        ['kpi' => 'rubriken', 'label' => 'Rubriken', 'value' => (string) $karte->sections->whereNull('parent_id')->count()],
                        ['kpi' => 'positionen', 'label' => 'Positionen', 'value' => (string) count($preise), 'tone' => count($preise) > 0 ? 'good' : 'warn'],
                    ]" />
                </x-slot:kpiHeader>

                {{-- Voll-Umbau 2026-08-03: sticky Tab-Leiste über den Baustein editor-tabs (Parität
                     Rezept/Gericht). „Aufbau" (Rubriken/Positionen) zuerst — wird am häufigsten
                     geändert. Panels bleiben im DOM (x-show); leitstelle-rail nicht neu mounten. --}}
                {{-- Werkstrang M Phase A (Spec 40 §6): Top-down-Fluss „vom Groben zum Kleinen" — Kontext zuerst
                     (Zielgruppe/Niveau als Leitplanken), dann Aufbau. Der Struktur/Positionen-Split bleibt
                     bewusst offen: die Positionen sind heute je Rubrik im rubrik-Partial eingebettet, ein Split
                     wäre eine Umstrukturierung (kein Surfacing) → späterer Ausbau. --}}
                <x-foodalchemist::editor-tabs marker="sk" wire-key="sk-tabs-{{ $karte->id }}" :init="'kontext'"
                    :tabs="[
                        'kontext' => 'Kontext',
                        'aufbau' => 'Aufbau',
                        'branding' => 'Branding / CI',
                        'leitstelle' => 'Leitstelle',
                    ]">

                {{-- ── Tab: KONTEXT (Werkstrang M Phase A) ─────────────────────── --}}
                <div x-show="tab === 'kontext'" x-cloak class="pt-4 space-y-4">
            {{-- Kontext / Leitplanken — Zielgruppe/Niveau/Convenience/Schreibstil. Defaults nach unten:
                 kiWordingVorschlag/kiKartenText lesen default_niveau/kundentyp als Leitplanken. --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-kontext-{{ $karte->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="font-semibold text-gray-900 text-sm">Kontext / Leitplanken</span>
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">wirkt als Default nach unten</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <div class="{{ $label }} mb-1">Kundentyp</div>
                        <input type="text" wire:model="kundentyp" placeholder="z. B. Business-Lunch, Fine-Dining-Gäste …" class="{{ $input }}" />
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Schreibstil</div>
                        <select wire:model="writingStyleId" class="{{ $input }}">
                            <option value="">— keiner —</option>
                            @foreach($schreibstile as $st)
                                <option value="{{ $st->id }}">{{ $st->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Niveau</div>
                        <select wire:model="niveau" class="{{ $input }}">
                            <option value="">— offen —</option>
                            <option value="buergerlich">bürgerlich</option>
                            <option value="gehoben">gehoben</option>
                            <option value="fine_dining">Fine Dining</option>
                        </select>
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Convenience-Tiefe</div>
                        <select wire:model="convenience" class="{{ $input }}">
                            <option value="">— offen —</option>
                            <option value="from_scratch">from scratch</option>
                            <option value="teil_convenience">teil-convenience</option>
                            <option value="voll_convenience">voll-convenience</option>
                        </select>
                    </div>
                </div>
                <p class="mt-2 text-[10px] text-gray-500">Oben „Speichern" übernimmt die Leitplanken — sie fließen als Defaults in KI-Wording &amp; Karten-Text.</p>
            </div>
            {{-- Karten-Kopf / Meta --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-head-{{ $karte->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <div class="md:col-span-2">
                        <div class="{{ $label }} mb-1">Name</div>
                        <input type="text" wire:model="name" class="{{ $input }}" />
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Kartentyp</div>
                        <select wire:model="kartenTyp" class="{{ $input }}">
                            @foreach($typLabel as $key => $lbl)
                                <option value="{{ $key }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Spec 33 P5: Status, Fenster und beide Zuordnungsachsen aus dem geteilten
                     Bauteil — dieselbe Bedienung wie in Foodbook und Speiseplan. --}}
                <div class="mt-3 pt-3 border-t border-black/5">
                    <x-foodalchemist::ausgabe-status
                        status-model="status" von-model="gueltigVon" bis-model="gueltigBis"
                        outlet-model="outletId"
                        :betriebe="$betriebe" :zustand="$karte->laufZustand()" :grund="$karte->laufGrund()"
                        :konflikt="$portfolioKonflikt" toggle="aktivUmschalten" />
                </div>
                <x-foodalchemist::crm-kunde-picker
                    :ausgabe="$karte" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="kiKartenText" class="{{ $btnAi }}">✨ KI-Einleitung</button>
                </div>
                @if($kiKartenVorschau !== null)
                    <div class="mt-3 p-3 rounded-lg bg-violet-500/[0.04] ring-1 ring-inset ring-violet-500/15">
                        <div class="{{ $label }} mb-1">KI-Vorschlag (Einleitung)</div>
                        <p class="text-sm text-gray-700 mb-2">{{ $kiKartenVorschau }}</p>
                        <button type="button" wire:click="kiKartenUebernehmen" class="{{ $btnGhostXs }}">Übernehmen &amp; speichern</button>
                        <button type="button" wire:click="kiKartenVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                    </div>
                @endif
                @error('kiKartenVorschau')<div class="mt-2 text-[11px] text-red-500">{{ $message }}</div>@enderror
            </div>
                </div>{{-- /Tab KONTEXT --}}

                {{-- ── Tab: LEITSTELLE (Cockpit, Stufe E) ──────────────────────── --}}
                <div x-show="tab === 'leitstelle'" x-cloak class="pt-4 space-y-4">
            {{-- Leitstelle-Cockpit (Stufe E) --}}
            <livewire:foodalchemist.speisekarte.leitstelle-rail :karte-id="$karte->id" wire:key="sk-ls-rail-{{ $karte->id }}" />
                </div>{{-- /Tab LEITSTELLE --}}

                {{-- ── Tab: BRANDING / CI ──────────────────────────────────────── --}}
                <div x-show="tab === 'branding'" x-cloak class="pt-4 space-y-4">
            {{-- Branding / CI (Stufe C) — im eigenen Tab, kein Akkordeon (Feinschliff 2026-08-03:
                 der Toggle war im dedizierten Tab redundant, der ▲ blieb verwaist stehen). --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-brand-{{ $karte->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 text-sm">Branding / CI</span>
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">Design</span>
                </div>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <div>
                            <div class="{{ $label }} mb-1">Markenfarbe</div>
                            <input type="color" wire:model="brandColor" class="h-8 w-16 rounded border border-black/10" />
                            <input type="text" wire:model="brandColor" class="{{ $input }} inline-block w-28 ml-2" />
                        </div>
                        <div>
                            <div class="{{ $label }} mb-1">Bandfarbe (optional)</div>
                            <input type="text" wire:model="bandColor" placeholder="leer = Markenfarbe" class="{{ $input }} w-40" />
                        </div>
                        <div>
                            <div class="{{ $label }} mb-1">Fußzeile</div>
                            <input type="text" wire:model="footerText" placeholder="z. B. Restaurant Adler · Musterstr. 1" class="{{ $input }}" />
                        </div>
                        @error('brandColor')<div class="text-[11px] text-red-500">{{ $message }}</div>@enderror
                        <button type="button" wire:click="brandingSpeichern" class="{{ $btnGhost }}">Branding speichern</button>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <div class="{{ $label }} mb-1">Logo</div>
                            @if($logoPath)
                                <div class="flex items-center gap-2">
                                    <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($karte?->logo_context_file_id, $logoPath) }}" alt="Logo" class="h-8 rounded bg-white/60 p-1" />
                                    <button type="button" wire:click="brandingLogoEntfernen" class="{{ $btnGhostXs }} text-red-600">entfernen</button>
                                </div>
                            @endif
                            <input type="file" wire:model="logoUpload" accept="image/*" class="text-xs mt-1" />
                            <div wire:loading wire:target="logoUpload" class="text-[11px] text-gray-400">lädt …</div>
                        </div>
                        <div>
                            <div class="{{ $label }} mb-1">Titelbild</div>
                            @if($coverPath)
                                <div class="flex items-center gap-2">
                                    <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($karte?->cover_context_file_id, $coverPath) }}" alt="Cover" class="h-12 rounded" />
                                    <button type="button" wire:click="brandingCoverEntfernen" class="{{ $btnGhostXs }} text-red-600">entfernen</button>
                                </div>
                            @endif
                            <input type="file" wire:model="coverUpload" accept="image/*" class="text-xs mt-1" />
                            <div wire:loading wire:target="coverUpload" class="text-[11px] text-gray-400">lädt …</div>
                        </div>
                    </div>
                </div>
            </div>
                </div>{{-- /Tab BRANDING --}}

                {{-- ── Tab: AUFBAU — Rubriken + Positionen ─────────────────────── --}}
                <div x-show="tab === 'aufbau'" x-cloak class="pt-4 space-y-4">
            {{-- Rubriken + Positionen --}}
            {{-- Werkstrang M (UX-Ausbau): gemeinsame D&D-Scope für ALLE (auch verschachtelten) Rubriken —
                 ein x-data am Container statt pro Rubrik (vermeidet die rekursive Scope-Falle). --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-body-{{ $karte->id }}"
                 x-data="{ dragPosId: null, dragRubrikId: null }">
                <div class="{{ $cardAccent }}"></div>

                <div class="flex items-center gap-2 mb-3">
                    <input type="text" wire:model="neueRubrik" wire:keydown.enter="rubrikNeu" placeholder="Neue Rubrik (z. B. Vorspeisen) …" class="{{ $input }} max-w-xs" />
                    <button type="button" wire:click="rubrikNeu" class="{{ $btnGhost }}">+ Rubrik</button>
                    {{-- Picker-Umbau: Format wandert in den permanenten Katalog rechts (Modus „Format"). --}}
                    {{-- P4: Voll-Kaskade — je Rubrik ein Konzept + Gerichte erfinden, Review im Planung-Editor. --}}
                    <button type="button" wire:click="vollKaskadeStarten" class="{{ $btnPrimary }}" wire:loading.attr="disabled" data-sk-voll-kaskade>
                        <span wire:loading.remove wire:target="vollKaskadeStarten">@svg('heroicon-o-bolt', 'w-4 h-4') Voll-Kaskade</span>
                        <span wire:loading wire:target="vollKaskadeStarten">Starte …</span>
                    </button>
                </div>

                @if($kaskadeMeldung !== null)
                    <div class="mb-3 rounded-lg bg-amber-500/10 border border-amber-500/30 px-2.5 py-1.5 text-[11px] text-amber-700">{{ $kaskadeMeldung }}</div>
                @endif

                {{-- Picker-Umbau: 2-Spalten — Rubriken links, permanenter Katalog rechts (Produktions-Muster). --}}
                <div class="flex gap-4 items-start" data-sk-2col>
                    <div class="flex-1 min-w-0" data-sk-rubriken>
                        @forelse($karte->sections->whereNull('parent_id') as $rubrik)
                            @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $rubrik, 'depth' => 0])
                        @empty
                            <div class="px-2 py-8 text-center text-[11px] text-gray-500">Noch keine Rubriken. Oben eine anlegen.</div>
                        @endforelse
                    </div>

                    {{-- Persistenter Katalog (geteilter Baustein): Gericht · Menü. Gericht/Menü fügen
                         in die Ziel-Rubrik (per „+" an einer Rubrik gewählt). --}}
                    <x-foodalchemist::katalog-picker marker="sk" switch="katalogModus" :modes="[
                        ['key' => 'gericht', 'label' => 'Gericht', 'active' => $pickerModus === 'gericht'],
                        ['key' => 'menue', 'label' => 'Menü', 'active' => $pickerModus === 'menue'],
                    ]">
                            <p class="text-[11px] mb-2 shrink-0 {{ $pickerRubrikTitel !== null ? 'text-violet-700' : 'text-amber-600' }}" data-sk-ziel>{{ $pickerRubrikTitel !== null ? 'Ziel-Rubrik: ' . $pickerRubrikTitel : 'Ziel-Rubrik: links per „+" an einer Rubrik wählen.' }}</p>
                            <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ $pickerModus === 'menue' ? 'Menü/Concept suchen …' : 'Gericht suchen …' }}" class="{{ $input }} w-full mb-2 shrink-0" data-sk-picker-suche />
                            @if($pickerModus === 'gericht')
                                {{-- Facetten als Dropdowns (Produktions-Muster): Warengruppe → Unterklasse. --}}
                                <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-sk-gericht-facetten>
                                    <select wire:model.live="pickerHauptgruppe" class="{{ $input }} !py-0.5 !text-[11px]" data-sk-facet-hg>
                                        <option value="">Alle Warengruppen</option>
                                        @foreach($pickerHauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->label }}</option>@endforeach
                                    </select>
                                    <select wire:model.live="pickerDishClass" class="{{ $input }} !py-0.5 !text-[11px]" @disabled($pickerUntergruppen->isEmpty()) data-sk-facet-klasse>
                                        <option value="">Alle Klassen</option>
                                        @foreach($pickerUntergruppen as $ug)<option value="{{ $ug->id }}">{{ $ug->label }}</option>@endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($pickerErgebnisse as $g)
                                    <x-foodalchemist::katalog-row wire:key="skpk-{{ $g->id }}" :disabled="$pickerRubrikTitel === null" wire:click="{{ $pickerModus === 'menue' ? 'positionAusMenue' : 'positionAusGericht' }}({{ (int) ($pickerRubrikId ?? 0) }}, {{ $g->id }})" :title="$g->name" :price="isset($g->sales_net) && $g->sales_net !== null ? number_format((float) $g->sales_net, 2, ',', '.') . ' €' : null">{{ $g->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="text-[11px] text-gray-400 px-2 py-2">{{ trim($pickerSuche) !== '' ? 'Nichts gefunden.' : ($pickerModus === 'menue' ? 'Keine Menüs/Concepts.' : 'Keine Gerichte.') }}</p>
                                @endforelse
                            </div>
                    </x-foodalchemist::katalog-picker>
                </div>{{-- /2col --}}
            </div>{{-- /sk-body --}}
                </div>{{-- /Tab AUFBAU --}}
                </x-foodalchemist::editor-tabs>
            </x-foodalchemist::modal>
        @endif
    </x-ui-page-container>
</x-ui-page>
