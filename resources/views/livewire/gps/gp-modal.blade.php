{{-- M3-09/10: GP-Modal — Naming-Builder (GL-12 AUTO-SYNC) + KI-Felder (GL-07, ki-header).
     Getabt (Alpine x-show, alle Sektionen im DOM — Marker/Tests bleiben grün, kein Server-
     Roundtrip beim Umschalten; Muster = recipe-modal). Status-Regler im Kopf. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="gp-modal" :title="$neu ? 'Grundprodukt anlegen' : 'Grundprodukt bearbeiten'" size="max-w-4xl">
    @if($neu)
        <x-slot:actions>
            <div class="flex items-center gap-1.5 w-full" data-ki-naming>
                <input type="text" wire:model="kiRohtext" placeholder="Roh-Bezeichnung, z. B. Lieferanten-Text …"
                       class="{{ $input }} !w-72" />
                <button type="button" wire:click="kiVorschlagNaming"
                        class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="gp.suggest: Builder-Felder aus Roh-Bezeichnung (§6)">✨ KI-Vorschlag</button>
            </div>
        </x-slot:actions>
    @endif

    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    <div x-data="{ tab: 'allgemein' }" data-gp-tabs>
        {{-- Kopf: Status-Regler (Edit, Kurator) — sonst statisches Badge --}}
        @if(! $neu && $gp !== null)
            <div class="flex items-center gap-2 mb-3" data-gp-status-kopf>
                <span class="{{ $label }}">Status</span>
                @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $gp) && $gp->status !== \Platform\FoodAlchemist\Enums\GpStatus::Merged)
                    <select wire:change="statusSetzen($event.target.value)"
                            class="{{ $pill }} font-medium {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }} border-0 cursor-pointer focus:ring-1 focus:ring-violet-400 pr-6"
                            data-gp-status-select>
                        @foreach($statusFaelle as $fall)
                            <option value="{{ $fall->value }}" @selected($gp->status === $fall)>{{ $fall->label() }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="{{ $pill }} font-medium {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">{{ $gp->status->label() }}</span>
                @endif

                {{-- ✨ Alles anreichern — globaler Autopilot über alle KI-Felder (Review, nie Auto-Persistenz) --}}
                @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $gp))
                    <button type="button" wire:click="allesAnreichern" wire:loading.attr="disabled" wire:target="allesAnreichern"
                            class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 ml-auto"
                            title="Zustand + Tags + Allergene + Nährwerte in EINEM Lauf vorschlagen (Review-Liste, Übernahme bleibt manuell)" data-gp-alles-anreichern>
                        <span wire:loading.remove wire:target="allesAnreichern">✨ Alles anreichern</span>
                        <span wire:loading wire:target="allesAnreichern">… läuft</span>
                    </button>
                @endif
            </div>
        @endif

        {{-- ✨-Anreichern-Lauf (Bulk-Mechanik auf EIN GP; Vorschläge landen in den Feldern nach „Alle übernehmen") --}}
        @if(! $neu && ($bulkRun ?? null) !== null)
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 mb-2 text-xs flex items-center gap-2"
                 @if($bulkRun->status === 'running') wire:poll.2s @endif data-gp-anreichern-status>
                @if($bulkRun->status === 'running')
                    <span class="text-gray-900 dark:text-gray-100">✨ Anreicherung läuft …</span>
                @else
                    <span class="text-gray-900 dark:text-gray-100">✨ Fertig — {{ $bulkOffen }} Vorschlag/Vorschläge zum Übernehmen</span>
                    <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600 ml-auto" data-gp-anreichern-uebernehmen>Alle übernehmen</button>
                    <button type="button" wire:click="bulkVerwerfen" class="{{ $btnGhostXs }}">Schließen</button>
                @endif
            </div>
        @endif

        {{-- Tab-Leiste (nur Edit — Neuanlage hat nur „Allgemein") --}}
        @if(! $neu && $gp !== null)
            @php($gpTabs = ['allgemein' => 'Allgemein', 'eigenschaften' => 'Eigenschaften', 'allergene' => 'Allergene', 'zusatzstoffe' => 'Zusatzstoffe', 'preis' => 'Preis & Lieferanten', 'ersatz' => 'Ersatz', 'sensorik' => 'Sensorik & Pairing', 'kalkulation' => 'Kalkulation'])
            <div class="flex items-center gap-1 border-b border-black/5 dark:border-white/10 mb-1 overflow-x-auto" data-gp-tabbar>
                @foreach($gpTabs as $tabKey => $tabLabel)
                    <button type="button" @click="tab = '{{ $tabKey }}'"
                            :class="tab === '{{ $tabKey }}' ? 'border-violet-500 text-violet-700 dark:text-violet-300' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                            class="px-2 py-2 text-xs font-medium border-b-2 -mb-px whitespace-nowrap transition-colors" data-gp-tab="{{ $tabKey }}">{{ $tabLabel }}</button>
                @endforeach
            </div>
        @endif

        {{-- ── Tab: ALLGEMEIN (Benennung · Klassifikation · Derivat) ──────── --}}
        <div x-show="tab === 'allgemein'" class="pt-2">
            {{-- Naming-Builder (Neuanlage) / Name (Edit) --}}
            <x-foodalchemist::modal-section title="Benennung (§6)">
                @if($neu)
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <div class="md:col-span-1">
                            <label class="block {{ $label }} mb-1">Hauptzutat *</label>
                            <input type="text" wire:model.live.debounce.300ms="builder.hauptzutat" placeholder="z. B. Zander" class="{{ $input }}" data-builder-hauptzutat />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Zustand (§9)</label>
                            <select wire:model.live="builder.zustand" class="{{ $input }}">
                                <option value="">—</option>
                                @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Verarbeitung</label>
                            <input type="text" wire:model.live.debounce.300ms="builder.verarbeitung" placeholder="z. B. Wuerfel 5 mm" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Form</label>
                            <input type="text" wire:model.live.debounce.300ms="builder.form" placeholder="Ganz / Filet / Pueree …" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Portion (§7)</label>
                            <input type="text" wire:model.live.debounce.300ms="builder.portion" placeholder="180 g" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Pflichtangabe (§8)</label>
                            <input type="text" wire:model.live.debounce.300ms="builder.pflichtangabe" placeholder="3,5 % / Type 405 / 16/20" class="{{ $input }}" />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4 mt-2" data-zusatz-klammern>
                        @foreach(['bio' => '(Bio)', 'vegan' => '(Vegan)', 'glutenfrei' => '(Glutenfrei)', 'laktosefrei' => '(Laktosefrei)'] as $flag => $klammer)
                            <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox" wire:model.live="builder.{{ $flag }}" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                                {{ $klammer }}
                            </label>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3">
                    <label class="block {{ $label }} mb-1">Name {{ $neu ? '(AUTO-SYNC — Überschreiben erzeugt Drift-Warnung)' : '' }}</label>
                    <input type="text" wire:model.live.debounce.300ms="manuellerName" placeholder="{{ $vorschauName }}" class="{{ $input }}" data-name-feld />
                </div>

                {{-- AUTO-SYNC-Vorschau: Name + Slug + gp_key --}}
                <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 space-y-0.5" data-naming-vorschau>
                    <p class="text-xs text-gray-900 dark:text-gray-100 font-medium" data-vorschau-name>{{ $vorschauName !== '' ? $vorschauName : '—' }}</p>
                    <p class="text-[11px] text-gray-400 font-mono">slug: {{ $vorschauSlug !== '' ? $vorschauSlug : '—' }} · gp_key: {{ $vorschauKey !== '' && $vorschauKey !== '||' ? $vorschauKey : '—' }}</p>
                </div>
                @foreach($liveFehler as $f)
                    <p class="text-[11px] text-rose-600 dark:text-rose-400 mt-1" data-live-fehler>{{ $f }}</p>
                @endforeach
                @foreach($warnungen as $w)
                    <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1" data-live-warnung>{{ $w }}</p>
                @endforeach

                {{-- Wording aus dem Lieferantenartikel ableiten (Override-First: Vorschlag → Übernehmen) --}}
                @if(! $neu)
                    <div class="mt-2" data-name-aus-la>
                        <button type="button" wire:click="nameAusLeadLa" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400"
                                title="gp.suggest: §6-Namensvorschlag aus der Bezeichnung des Lead-Lieferantenartikels">✨ Name aus Lieferantenartikel ableiten</button>
                        @if($nameVorschlag !== null)
                            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-2.5 py-1.5 text-[11px]" data-name-vorschlag>
                                <p class="text-gray-900 dark:text-gray-100">Vorschlag: <span class="font-medium">{{ $nameVorschlag }}</span></p>
                                <div class="flex gap-1.5 mt-1">
                                    <button type="button" wire:click="nameVorschlagUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-name-vorschlag-uebernehmen>Übernehmen</button>
                                    <button type="button" wire:click="nameVorschlagVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </x-foodalchemist::modal-section>

            {{-- Klassifikation --}}
            <x-foodalchemist::modal-section title="Klassifikation">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block {{ $label }} mb-1">Warengruppe</label>
                        <select wire:model.live="builder.warengruppe_code" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($warengruppen as $wg)<option value="{{ $wg->code }}">{{ $wg->codedLabel() }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label }} mb-1">Sub-Kategorie</label>
                        {{-- Punkt C: WG-gescopetes Dropdown gegen Drift (verwaltet + Bestand gemerged, #371) --}}
                        <select wire:model.live="builder.sub_kategorie" class="{{ $input }}" data-sub-kategorie
                                @disabled(($builder['warengruppe_code'] ?? '') === '')>
                            <option value="">—</option>
                            @foreach($subKategorien as $sk)
                                <option value="{{ $sk->sub_kategorie }}">{{ $sk->sub_kategorie }}</option>
                            @endforeach
                            @if(($builder['sub_kategorie'] ?? '') !== '' && ! $subKategorien->contains('sub_kategorie', $builder['sub_kategorie']))
                                <option value="{{ $builder['sub_kategorie'] }}" selected>{{ $builder['sub_kategorie'] }} (Bestand)</option>
                            @endif
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">
                            @if(($builder['warengruppe_code'] ?? '') === '') Erst Warengruppe wählen. @else Neue Werte in Einstellungen → Warengruppen pflegen. @endif
                        </p>
                    </div>
                </div>
            </x-foodalchemist::modal-section>

            {{-- Zustand (§9) — Klassifikations-Attribut, gehört zu Allgemein (nicht Eigenschaften). Nur Edit. --}}
            @if(! $neu && $gp !== null)
                <x-foodalchemist::modal-section title="Zustand (§9)">
                    <x-foodalchemist::ki-header label="Zustand (§9)" field="zustand"
                        :quelle="$gp->zustand_quelle" :confidence="$gp->zustand_ai_confidence !== null ? (float) $gp->zustand_ai_confidence : null"
                        :begruendung="$gp->zustand_ai_begruendung" :hasProposal="isset($kiVorschlag['zustand'])">
                        <div class="flex items-center gap-2">
                            <select wire:model.live="builder.zustand" class="{{ $input }} !w-44">
                                <option value="">—</option>
                                @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                            </select>
                            @if(isset($kiVorschlag['zustand']))
                                <span class="{{ $pill }} {{ $variantPill['primary'] }}" data-zustand-vorschlag>
                                    Vorschlag: {{ $kiVorschlag['zustand']['werte']['zustand'] ?? '—' }} ({{ round($kiVorschlag['zustand']['confidence'] * 100) }}%)
                                </span>
                            @endif
                        </div>
                    </x-foodalchemist::ki-header>
                </x-foodalchemist::modal-section>
            @endif

            {{-- Derivat (§11) --}}
            <x-foodalchemist::modal-section title="Derivat (§11)">
                <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="builder.is_derivat" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-derivat-toggle />
                    Küchen-Nebenprodukt (Schale, Saft, Parüren, Karkasse …) — <code class="text-[11px]">requires_la=0</code>, erbt Allergene LIVE vom Mutter-GP (§16)
                </label>
                @if($builder['is_derivat'])
                    <div class="mt-2" data-derivat-mutter>
                        <label class="block {{ $label }} mb-1">Mutter-GP</label>
                        @if($builder['derivat_von_gp_id'])
                            <p class="text-xs text-gray-900 dark:text-gray-100">
                                {{ \Platform\FoodAlchemist\Models\FoodAlchemistGp::find($builder['derivat_von_gp_id'])?->name ?? '—' }}
                                <button type="button" wire:click="$set('builder.derivat_von_gp_id', null)" class="{{ $btnGhostXs }} ml-1">ändern</button>
                            </p>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="derivatSuche" placeholder="Mutter-GP suchen …" class="{{ $input }}" />
                            @foreach($derivatKandidaten as $kandidat)
                                <button type="button" wire:key="dk-{{ $kandidat->id }}"
                                        wire:click="$set('builder.derivat_von_gp_id', {{ $kandidat->id }})"
                                        class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors duration-150">
                                    {{ $kandidat->name }}
                                </button>
                            @endforeach
                        @endif
                    </div>
                @endif
            </x-foodalchemist::modal-section>
        </div>{{-- /Tab ALLGEMEIN --}}

        {{-- KI-Felder + Sensorik + Kalkulation brauchen ein persistiertes GP (nur Edit) --}}
        @if(! $neu && $gp !== null)
            {{-- ── Tab: EIGENSCHAFTEN (KI-Felder GL-07) ──────────────────── --}}
            <div x-show="tab === 'eigenschaften'" x-cloak class="pt-2">
                <x-foodalchemist::modal-section title="Eigenschafts-Tags (GL-07)">
                    <div class="space-y-4">
                        <x-foodalchemist::ki-header label="Eigenschafts-Tags" field="tags"
                            :quelle="$gp->tag_quelle" :confidence="$gp->tag_ai_confidence !== null ? (float) $gp->tag_ai_confidence : null"
                            :begruendung="$gp->tag_ai_begruendung" :hasProposal="isset($kiVorschlag['tags'])">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-3 gap-y-1.5" data-tags-grid>
                                @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag)
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="text-[11px] text-gray-500 dark:text-gray-400 truncate">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                                        <select wire:model.live="tags.{{ $tag }}" class="bg-transparent border-0 text-[11px] text-gray-700 dark:text-gray-200 cursor-pointer focus:ring-0 py-0">
                                            <option value="">unbewertet</option>
                                            <option value="1">ja</option>
                                            <option value="0">nein</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </x-foodalchemist::ki-header>
                    </div>
                </x-foodalchemist::modal-section>
                {{-- Natürliche Einheit + Nährwerte (eingebettetes DetailPanel, geteilte Render-Quelle) --}}
                <x-foodalchemist::modal-section title="Einheit & Nährwerte">
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="naehrwerte" :key="'gpd-naehr-'.$gpId" />
                </x-foodalchemist::modal-section>
            </div>{{-- /Tab EIGENSCHAFTEN --}}

            {{-- ── Tab: ALLERGENE (eingebettet, GL-01) — Panel bringt eigenen Header ── --}}
            <div x-show="tab === 'allergene'" x-cloak class="pt-3">
                <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="allergene" :key="'gpd-allerg-'.$gpId" />
            </div>{{-- /Tab ALLERGENE --}}

            {{-- ── Tab: ZUSATZSTOFFE (eingebettet, LMIV GL-09) ────────────── --}}
            <div x-show="tab === 'zusatzstoffe'" x-cloak class="pt-3">
                <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="zusatzstoffe" :key="'gpd-zusatz-'.$gpId" />
            </div>{{-- /Tab ZUSATZSTOFFE --}}

            {{-- ── Tab: PREIS & LIEFERANTEN (eingebettet — LA-Kette + Verwendungen) ── --}}
            <div x-show="tab === 'preis'" x-cloak class="pt-3">
                <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="las" :key="'gpd-las-'.$gpId" />
            </div>{{-- /Tab PREIS & LIEFERANTEN --}}

            {{-- ── Tab: ERSATZ (make-or-buy / Artikel-Ersatz — Äquivalenz-Katalog) ── --}}
            <div x-show="tab === 'ersatz'" x-cloak class="pt-3">
                <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="ersatz" :key="'gpd-ersatz-'.$gpId" />
            </div>{{-- /Tab ERSATZ --}}

            {{-- ── Tab: SENSORIK & PAIRING ────────────────────────────────── --}}
            <div x-show="tab === 'sensorik'" x-cloak class="pt-2">
                <x-foodalchemist::modal-section title="Sensorik & Pairing">
                    @include('foodalchemist::livewire.concepter.partials.sensorik')
                    <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-5 mb-2">Pairing</h3>
                    @include('foodalchemist::livewire.concepter.partials.pairing')
                </x-foodalchemist::modal-section>
            </div>{{-- /Tab SENSORIK --}}

            {{-- ── Tab: KALKULATION (Defaults, Phase 2 — speisen die Verlust-Kaskade GL-02) ── --}}
            <div x-show="tab === 'kalkulation'" x-cloak class="pt-2">
                <x-foodalchemist::modal-section title="Kalkulations-Defaults (GL-02)">
                    <p class="text-[11px] text-gray-400 mb-2">Greifen, wenn eine Rezept-Zutat keinen eigenen Wert hat. Leer = nächste Stufe (Team-WG-Default → 0).</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3" data-gp-defaults>
                        <div>
                            <label class="{{ $label }}">Garverlust-Default %</label>
                            <input type="text" wire:model="defaults.garverlust_default_pct" placeholder="—" class="{{ $input }} mt-1" data-gp-garverlust />
                        </div>
                        <div>
                            <label class="{{ $label }}">Putzverlust-Default %</label>
                            <input type="text" wire:model="defaults.putzverlust_default_pct" placeholder="—" class="{{ $input }} mt-1" data-gp-putzverlust />
                        </div>
                        <div>
                            <label class="{{ $label }}">Stück-Gewicht (g)</label>
                            <input type="text" wire:model="defaults.stk_default_g" placeholder="—" class="{{ $input }} mt-1" data-gp-stk />
                        </div>
                    </div>
                </x-foodalchemist::modal-section>
            </div>{{-- /Tab KALKULATION --}}
        @endif
    </div>{{-- /gp-tabs --}}

    <x-slot:footer>
        <div class="flex items-center justify-between gap-3 w-full">
            <label class="inline-flex items-center gap-1.5 text-[11px] text-gray-400" title="GT-12-10: HARD_STOP bei vorhandenem gp_key/Jaccard ≥ 0.92 — force legt bewusst trotzdem an">
                @if($neu)<input type="checkbox" wire:model.live="force" class="rounded border-gray-300 text-rose-500 focus:ring-rose-400" data-force-flag /> bewusst trotzdem anlegen (force)@endif
            </label>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('modal.close', { name: 'gp-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-gp-speichern>
                    {{ $neu ? 'Anlegen' : 'Speichern' }}
                </button>
            </div>
        </div>
    </x-slot:footer>
</x-foodalchemist::modal>
