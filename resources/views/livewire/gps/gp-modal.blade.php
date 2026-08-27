{{-- M3-09/10: GP-Modal — Naming-Builder (GL-12 AUTO-SYNC) + KI-Felder (GL-07, ki-header).
     Getabt (Alpine x-show, alle Sektionen im DOM — Marker/Tests bleiben grün, kein Server-
     Roundtrip beim Umschalten; Muster = recipe-modal). Status-Regler im Kopf. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- Spec 28 / E3.1: GP-Editor auf den Master-Standard. Voll-Editor nur im Bestand
     (Neuanlage bleibt hell und schmal — sie hat nur „Allgemein"). --}}
<x-foodalchemist::modal name="gp-modal" :title="$neu ? 'Grundprodukt anlegen' : 'Grundprodukt bearbeiten'"
    :title-name="$neu ? null : $gp?->name" size="max-w-4xl"
    :fullscreen="! $neu && $gp !== null" :dark-canvas="true">

    {{-- Aktionsleiste (E1-4/5): Speichern zuerst, dann Status-Regler, dann KI-Chips.
         Status und „Alles anreichern" lagen vorher IM Body und scrollten weg. --}}
    <x-slot:actions>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-gp-speichern-kopf>{{ $neu ? 'Anlegen' : 'Speichern' }}</button>

        @if($neu)
            <span class="text-gray-300">|</span>
            <div class="flex items-center gap-1.5" data-ki-naming>
                <input type="text" wire:model="kiRohtext" placeholder="Roh-Bezeichnung, z. B. Lieferanten-Text …"
                       class="{{ $input }} !w-72" />
                <button type="button" wire:click="kiVorschlagNaming"
                        class="{{ $btnAi }}" title="gp.suggest: Builder-Felder aus Roh-Bezeichnung (§6)">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Vorschlag</button>
            </div>
        @elseif($gp !== null)
            <span class="text-gray-300">|</span>
            {{-- Status-Regler (Kurator) — sonst statisches Badge --}}
            <span class="{{ $label }}" data-gp-status-kopf>Status</span>
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

            @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $gp))
                <button type="button" wire:click="allesAnreichern" wire:loading.attr="disabled" wire:target="allesAnreichern"
                        class="{{ $btnAi }}"
                        title="Zustand + Tags + Allergene + Nährwerte in EINEM Lauf vorschlagen (Review-Liste, Übernahme bleibt manuell)" data-gp-alles-anreichern>
                    <span wire:loading.remove wire:target="allesAnreichern" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Alles anreichern</span>
                    <span wire:loading wire:target="allesAnreichern">… läuft</span>
                </button>
            @endif
        @endif
    </x-slot:actions>

    {{-- KPI-Kopf (E1-6): dieselben Größen wie das GP-Cockpit im Detail-Panel.
         Leitwert = Lead-Preis (accent). „LAs" ist die folgenreichste Lücke am GP: ohne LA
         gibt es keinen Preis, also kann kein Rezept damit rechnen — good/warn.
         Bei `requires_la = false` (Derivate/Platzhalter nach GP-Regelwerk §11.2) ist 0 LAs
         KEIN Mangel, deshalb dort neutral statt warn.
         Allergen-Konfidenz kommt aus GpAggregateService (none|low|medium|high). --}}
    @if(! $neu && $gp !== null)
        <x-slot:kpiHeader>
            @php($lasPflicht = (bool) ($gp->requires_la ?? true))
            <x-foodalchemist::kpi-tiles marker="gp-editor-kpis" :cols="5" :tiles="[
                ['kpi' => 'lead-preis', 'label' => 'Lead-Preis', 'tone' => 'accent',
                 'title' => $leadLa?->designation ?? 'Kein Lead-Lieferantenartikel gesetzt',
                 'value' => $leadPreis?->price !== null
                    ? number_format((float) $leadPreis->price, 2, ',', '.') . ' € / ' . ($leadLa->ordering_unit ?? $leadLa->unit_code ?? 'Einheit')
                    : '—'],
                ['kpi' => 'las', 'label' => 'Lieferantenartikel',
                 'tone' => ($gp->n_las_total ?? 0) > 0 ? 'good' : ($lasPflicht ? 'warn' : 'neutral'),
                 'title' => $lasPflicht
                    ? 'Ohne LA hat der GP keinen Preis — Rezepte mit ihm bleiben unbepreist.'
                    : 'Kein LA nötig (Derivat/Platzhalter, GP-Regelwerk §11.2).',
                 'value' => ($gp->n_las_total ?? 0) . ($lasPflicht ? '' : ' (kein LA nötig)')],
                ['kpi' => 'allergen', 'label' => 'Allergen-Konf.',
                 'tone' => ['high' => 'good', 'medium' => 'warn', 'low' => 'bad'][$allergenKonfidenz['confidence'] ?? ''] ?? 'neutral',
                 'title' => 'Aus ' . ($allergenKonfidenz['n_las_mit_daten'] ?? 0) . ' von ' . ($gp->n_las_total ?? 0) . ' LAs mit Allergen-Daten aggregiert (ALL-MAXIMAL).',
                 'value' => strtoupper((string) ($allergenKonfidenz['confidence'] ?? '—'))],
                ['kpi' => 'warengruppe', 'label' => 'Warengruppe',
                 'title' => $gp->sub_category ?? '',
                 'value' => $gp->commodity_group?->name ?? $gp->commodity_group_code ?? '—'],
                ['kpi' => 'zustand', 'label' => 'Zustand (§9)',
                 'value' => $gp->condition ?: '—'],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($fehler !== null)
        <p class="text-xs text-rose-600 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    {{-- Anreichern-Lauf (Bulk-Mechanik auf EIN GP; Vorschläge landen in den Feldern nach
         „Alle übernehmen"). Braucht den Tab-Scope nicht — steht deshalb davor. --}}
    @if(! $neu && ($bulkRun ?? null) !== null)
        <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 mb-2 text-xs flex items-center gap-2"
             @if($bulkRun->status === 'running') wire:poll.2s @endif data-gp-anreichern-status>
            @if($bulkRun->status === 'running')
                <span class="text-gray-900 inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Anreicherung läuft …</span>
            @else
                <span class="text-gray-900 inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Fertig — {{ $bulkOffen }} Vorschlag/Vorschläge zum Übernehmen</span>
                <button type="button" wire:click="bulkAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600 ml-auto" data-gp-anreichern-uebernehmen>Alle übernehmen</button>
                <button type="button" wire:click="bulkVerwerfen" class="{{ $btnGhostXs }}">Schließen</button>
            @endif
        </div>
    @endif

    {{-- Tabs über den Baustein: sticky (vorher scrollte die Leiste weg) + wire:key beim
         GP-Wechsel + Reset beim Öffnen. Bei Neuanlage bleibt genau „Allgemein" — der Baustein
         zeichnet dann keine Leiste. Alpine-Modus, weil die Tab-Panels eingebettete
         Detail-Panel-Kinder halten, die nicht neu mounten sollen. --}}
    <x-foodalchemist::editor-tabs marker="gp" wire-key="gp-tabs-{{ $gp?->id ?? 'neu' }}" :init="'allgemein'"
        :tabs="[
            'allgemein' => 'Allgemein',
            'eigenschaften' => $neu ? null : 'Eigenschaften',
            'allergene' => $neu ? null : 'Allergene',
            'zusatzstoffe' => $neu ? null : 'Zusatzstoffe',
            'price' => $neu ? null : 'Preis & Lieferanten',
            'ersatz' => $neu ? null : 'Ersatz',
            'sensorik' => $neu ? null : 'Sensorik & Pairing',
            'kalkulation' => $neu ? null : 'Kalkulation',
        ]">

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
                            <select wire:model.live="builder.condition" class="{{ $input }}">
                                <option value="">—</option>
                                @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Verarbeitung</label>
                            <input type="text" wire:model.live.debounce.300ms="builder.processing" placeholder="z. B. Wuerfel 5 mm" class="{{ $input }}" />
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
                            <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
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
                <div class="mt-2 rounded-lg bg-black/[0.03] px-3 py-2 space-y-0.5" data-naming-vorschau>
                    <p class="text-xs text-gray-900 font-medium" data-vorschau-name>{{ $vorschauName !== '' ? $vorschauName : '—' }}</p>
                    <p class="text-[11px] text-gray-500 font-mono">slug: {{ $vorschauSlug !== '' ? $vorschauSlug : '—' }} · gp_key: {{ $vorschauKey !== '' && $vorschauKey !== '||' ? $vorschauKey : '—' }}</p>
                </div>
                @foreach($liveFehler as $f)
                    <p class="text-[11px] text-rose-600 mt-1" data-live-fehler>{{ $f }}</p>
                @endforeach
                @foreach($warnungen as $w)
                    <p class="text-[11px] text-amber-600 mt-1" data-live-warnung>{{ $w }}</p>
                @endforeach

                {{-- Wording aus dem Lieferantenartikel ableiten (Override-First: Vorschlag → Übernehmen) --}}
                @if(! $neu)
                    <div class="mt-2" data-name-aus-la>
                        <button type="button" wire:click="nameAusLeadLa" class="{{ $btnGhostXs }} text-violet-600"
                                title="gp.suggest: §6-Namensvorschlag aus der Bezeichnung des Lead-Lieferantenartikels">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Name aus Lieferantenartikel ableiten</button>
                        @if($nameVorschlag !== null)
                            <div class="mt-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 px-2.5 py-1.5 text-[11px]" data-name-vorschlag>
                                <p class="text-gray-900">Vorschlag: <span class="font-medium">{{ $nameVorschlag }}</span></p>
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
                        <select wire:model.live="builder.commodity_group_code" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($warengruppen as $wg)<option value="{{ $wg->code }}">{{ $wg->codedLabel() }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label }} mb-1">Sub-Kategorie</label>
                        {{-- Punkt C: WG-gescopetes Dropdown gegen Drift (verwaltet + Bestand gemerged, #371) --}}
                        <select wire:model.live="builder.sub_category" class="{{ $input }}" data-sub-kategorie
                                @disabled(($builder['commodity_group_code'] ?? '') === '')>
                            <option value="">—</option>
                            @foreach($subKategorien as $sk)
                                <option value="{{ $sk->sub_category }}">{{ $sk->sub_category }}</option>
                            @endforeach
                            @if(($builder['sub_category'] ?? '') !== '' && ! $subKategorien->contains('sub_category', $builder['sub_category']))
                                <option value="{{ $builder['sub_category'] }}" selected>{{ $builder['sub_category'] }} (Bestand)</option>
                            @endif
                        </select>
                        <p class="text-[11px] text-gray-500 mt-1">
                            @if(($builder['commodity_group_code'] ?? '') === '') Erst Warengruppe wählen. @else Neue Werte in Einstellungen → Warengruppen pflegen. @endif
                        </p>
                    </div>
                </div>
            </x-foodalchemist::modal-section>

            {{-- Zustand (§9) — Klassifikations-Attribut, gehört zu Allgemein (nicht Eigenschaften). Nur Edit. --}}
            @if(! $neu && $gp !== null)
                <x-foodalchemist::modal-section title="Zustand (§9)">
                    <x-foodalchemist::ki-header label="Zustand (§9)" field="condition"
                        :source="$gp->condition_source" :confidence="$gp->condition_ai_confidence !== null ? (float) $gp->condition_ai_confidence : null"
                        :reasoning="$gp->condition_ai_reasoning" :hasProposal="isset($kiVorschlag['condition'])">
                        <div class="flex items-center gap-2">
                            <select wire:model.live="builder.condition" class="{{ $input }} !w-44">
                                <option value="">—</option>
                                @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                            </select>
                            @if(isset($kiVorschlag['condition']))
                                <span class="{{ $pill }} {{ $variantPill['primary'] }}" data-condition-vorschlag>
                                    Vorschlag: {{ $kiVorschlag['condition']['werte']['condition'] ?? '—' }} ({{ round($kiVorschlag['condition']['confidence'] * 100) }}%)
                                </span>
                            @endif
                        </div>
                    </x-foodalchemist::ki-header>
                </x-foodalchemist::modal-section>
            @endif

            {{-- Derivat (§11) --}}
            <x-foodalchemist::modal-section title="Derivat (§11)">
                <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                    <input type="checkbox" wire:model.live="builder.is_derivat" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-derivat-toggle />
                    Küchen-Nebenprodukt (Schale, Saft, Parüren, Karkasse …) — <code class="text-[11px]">requires_la=0</code>, erbt Allergene LIVE vom Mutter-GP (§16)
                </label>
                @if($builder['is_derivat'])
                    <div class="mt-2" data-derivat-mutter>
                        <label class="block {{ $label }} mb-1">Mutter-GP</label>
                        @if($builder['derivat_von_gp_id'])
                            <p class="text-xs text-gray-900">
                                {{ $derivatMutterName ?? '—' }}
                                <button type="button" wire:click="$set('builder.derivat_von_gp_id', null)" class="{{ $btnGhostXs }} ml-1">ändern</button>
                            </p>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="derivatSuche" placeholder="Mutter-GP suchen …" class="{{ $input }}" />
                            @foreach($derivatKandidaten as $kandidat)
                                <button type="button" wire:key="dk-{{ $kandidat->id }}"
                                        wire:click="$set('builder.derivat_von_gp_id', {{ $kandidat->id }})"
                                        class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 hover:bg-violet-500/10 transition-colors duration-150">
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
                            :source="$gp->tag_source" :confidence="$gp->tag_ai_confidence !== null ? (float) $gp->tag_ai_confidence : null"
                            :reasoning="$gp->tag_ai_reasoning" :hasProposal="isset($kiVorschlag['tags'])">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-3 gap-y-1.5" data-tags-grid>
                                @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag)
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="text-[11px] text-gray-600 truncate">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                                        <select wire:model.live="tags.{{ $tag }}" class="bg-transparent border-0 text-[11px] text-gray-700 cursor-pointer focus:ring-0 py-0">
                                            <option value="">unbewertet</option>
                                            <option value="1">ja</option>
                                            <option value="0">nein</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </x-foodalchemist::ki-header>

                        {{-- 06·H4b: Favorit direkt am GP pinnen (2. Andockpunkt zum Favoriten-Screen). --}}
                        <div class="flex items-center justify-between gap-3 pt-3 border-t border-gray-100">
                            <div class="min-w-0">
                                <div class="text-[11px] font-medium text-gray-700">⭐ Favorit (Lieblings-GP)</div>
                                <div class="text-[10px] text-gray-500">
                                    @if($gp->is_favorite)
                                        Gepinnt in deinen Favoriten{{ $gp->favorite_rank !== null ? ' · Rang '.$gp->favorite_rank : '' }}.
                                    @else
                                        Fließt im Generator nur mit aktivem „⭐ Auf Basis meiner Favoriten bauen"-Modus ein.
                                    @endif
                                </div>
                            </div>
                            @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $gp))
                                <button type="button" wire:click="favoriteToggle"
                                    @class([
                                        $btnGhostXs,
                                        'text-amber-600' => $gp->is_favorite,
                                    ])
                                    data-gp-favoriten-toggle>
                                    {{ $gp->is_favorite ? '★ aus Favoriten nehmen' : '☆ zu Favoriten' }}
                                </button>
                            @elseif($gp->is_favorite)
                                <span class="text-amber-500 text-sm" title="Favorit (read-only)">★</span>
                            @endif
                        </div>
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
            <div x-show="tab === 'price'" x-cloak class="pt-3">
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
                    <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 mt-5 mb-2">Pairing</h3>
                    @include('foodalchemist::livewire.concepter.partials.pairing')
                </x-foodalchemist::modal-section>
            </div>{{-- /Tab SENSORIK --}}

            {{-- ── Tab: KALKULATION (Defaults, Phase 2 — speisen die Verlust-Kaskade GL-02) ── --}}
            <div x-show="tab === 'kalkulation'" x-cloak class="pt-2">
                <x-foodalchemist::modal-section title="Kalkulations-Defaults (GL-02)">
                    <p class="text-[11px] text-gray-500 mb-2">Greifen, wenn eine Rezept-Zutat keinen eigenen Wert hat. Leer = nächste Stufe (Team-WG-Default → 0).</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3" data-gp-defaults>
                        <div>
                            <label class="{{ $label }}">Garverlust-Default %</label>
                            <input type="text" wire:model="defaults.cooking_loss_default_pct" placeholder="—" class="{{ $input }} mt-1" data-gp-garverlust />
                        </div>
                        <div>
                            <label class="{{ $label }}">Putzverlust-Default %</label>
                            <input type="text" wire:model="defaults.trimming_loss_default_pct" placeholder="—" class="{{ $input }} mt-1" data-gp-putzverlust />
                        </div>
                        <div>
                            <label class="{{ $label }}">Stück-Gewicht (g)</label>
                            <input type="text" wire:model="defaults.piece_default_g" placeholder="—" class="{{ $input }} mt-1" data-gp-stk />
                        </div>
                    </div>
                </x-foodalchemist::modal-section>

                {{-- #8 (2026-08-27): „GP in allen Rezepten tauschen" — aus dem Detail-Panel in den Editor gezogen. --}}
                <x-foodalchemist::modal-section title="Verwaltung — GP tauschen">
                    <p class="{{ $label }} mb-1 normal-case">GP in ALLEN Rezepten durch einen anderen ersetzen (Vorstufe zum Löschen). Alle Rezept-Zeilen werden umgehängt + neu berechnet.</p>
                    @if($hinweis)<div class="mb-2 rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-2.5 py-1.5 text-[11px] text-emerald-700" data-gp-tausch-hinweis>{{ $hinweis }}</div>@endif
                    <input type="search" wire:model.live.debounce.300ms="tauschSuche" placeholder="Ziel-GP suchen …" class="{{ $input }}" data-gp-tausch-suche />
                    @if($tauschKandidaten->isNotEmpty())
                        <div class="mt-1 space-y-0.5">
                            @foreach($tauschKandidaten as $k)
                                <button type="button" wire:key="tausch-{{ $k->id }}" wire:click="gpErsetzen({{ $k->id }})"
                                        wire:confirm="Diesen GP in ALLEN Rezepten durch „{{ $k->name }}“ ersetzen?"
                                        class="w-full text-left px-2 py-1 rounded hover:bg-black/[0.05] text-sm flex items-center justify-between gap-2" data-gp-tausch-kandidat>
                                    <span class="min-w-0 truncate">{{ $k->name }}</span>
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">{{ $k->status }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif(trim($tauschSuche) !== '')
                        <p class="text-[11px] text-gray-400 mt-1">Kein passender Ziel-GP.</p>
                    @endif
                    @error('tauschSuche')<div class="mt-1 text-[11px] text-red-500">{{ $message }}</div>@enderror
                </x-foodalchemist::modal-section>
            </div>{{-- /Tab KALKULATION --}}
        @endif
    </x-foodalchemist::editor-tabs>

    <x-slot:footer>
        <div class="flex items-center justify-between gap-3 w-full">
            <label class="inline-flex items-center gap-1.5 text-[11px] text-gray-500" title="GT-12-10: HARD_STOP bei vorhandenem gp_key/Jaccard ≥ 0.92 — force legt bewusst trotzdem an">
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
