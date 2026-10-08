{{-- Grundprodukt-Editor. fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt, Werkbank-tauglich
     (nur --fa-*-Tokens, keine festen Farben).

     Anatomie: Kopf = Titel + Name · Status · ein KI-Knopf · „Weitere Aktionen" · Speichern (rechts, einzige
     Hauptaktion). Kennzahlen fest im Kopf. Reiter in der Reihenfolge des GP-Detail-Panels:
     Lieferantenartikel (Preis) · Allergene und Zusatzstoffe · Stammdaten · Eigenschaften · Kalkulation ·
     Aroma · Ersatz · Verwaltung. Die Neuanlage bleibt schmal und ohne Reiter (nur „Allgemein").

     Alle Reiter-Panels bleiben im DOM (x-show), weil sie eingebettete Detail-Panel-Kinder halten. --}}
@php
    $kannKuratieren = $gp !== null && \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $gp);
    $tagLabels = [
        'is_vegan' => 'Vegan', 'is_vegetarian' => 'Vegetarisch', 'is_halal' => 'Halal',
        'contains_pork' => 'Enthält Schwein', 'contains_beef' => 'Enthält Rind',
        'is_organic' => 'Bio', 'is_regional' => 'Regional', 'is_staple_food' => 'Grundnahrungsmittel',
        'is_convenience' => 'Convenience', 'is_lactose_free' => 'Laktosefrei', 'is_gluten_free' => 'Glutenfrei',
    ];
    $quelleLabel = ['ki' => 'KI', 'manual' => 'von Hand', 'auto' => 'automatisch'];
    $formLabel = fn (?string $slug) => ['stk' => 'Stück'][$slug] ?? ucfirst((string) $slug);
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $titelKlein = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $listenKnopf = 'flex w-full items-center justify-between gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $haken = 'w-4 h-4 shrink-0 rounded accent-[var(--fa-accent)]';
@endphp

<x-foodalchemist::modal name="gp-modal" :title="$neu ? 'Grundprodukt anlegen' : 'Grundprodukt bearbeiten'"
    :title-name="$neu ? null : $gp?->name" :size="$neu ? 'max-w-3xl' : 'max-w-4xl'"
    :fullscreen="! $neu && $gp !== null" :dark-canvas="true">

    {{-- Kopf: Status · KI · Weitere Aktionen · Speichern (rechts) --}}
    <x-slot:actions>
        @if(! $neu && $gp !== null)
            <span class="{{ $titelKlein }}" data-gp-status-kopf>Status</span>
            @if($kannKuratieren && $gp->status !== \Platform\FoodAlchemist\Enums\GpStatus::Merged)
                <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="gp-status-{{ $gp->id }}-{{ $gp->status->value }}">
                    <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Status ändern" data-gp-status-select>
                        <x-fa::status :value="$gp->status" />@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                    </button>
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-44 fa-surface shadow-lg py-1">
                        @foreach($statusFaelle as $fall)
                            <button type="button" role="menuitem" x-on:click="offen = false" wire:click="statusSetzen('{{ $fall->value }}')"
                                    class="flex w-full items-center justify-between px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $gp->status === $fall ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                                {{ $fall->label() }}@if($gp->status === $fall)@svg('heroicon-m-check', 'w-4 h-4')@endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                <x-fa::status :value="$gp->status" />
            @endif

            @if($kannKuratieren)
                <x-foodalchemist::ki-action action="allesAnreichern" variant="ai" icon="heroicon-o-sparkles" label="Alles anreichern"
                    title="Zustand, Eigenschaften, Allergene und Nährwerte in einem Lauf vorschlagen. Übernehmen bleibt deine Entscheidung."
                    data-gp-alles-anreichern busy="Läuft …" flash="Vorschläge da" />
            @endif

            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" data-gp-weitere-aktionen />
                <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                    <a role="menuitem" href="{{ route('foodalchemist.gps.dokument', ['id' => $gp->id, 'profil' => 'kalkulation']) }}" target="_blank" x-on:click="offen = false" class="{{ $menuePunkt }}" data-gp-druck>
                        @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Blatt drucken
                    </a>
                    <a role="menuitem" href="{{ route('foodalchemist.gps.dokument', ['id' => $gp->id, 'profil' => 'kalkulation', 'pdf' => 1]) }}" x-on:click="offen = false" class="{{ $menuePunkt }}" data-gp-pdf>
                        @svg('heroicon-o-arrow-down-tray', 'w-4 h-4 text-[var(--fa-ink-3)]') Blatt als PDF laden
                    </a>
                    <a role="menuitem" href="{{ route('foodalchemist.etiketten.index', ['quelle' => 'gp', 'id' => $gp->id]) }}" target="_blank" x-on:click="offen = false" class="{{ $menuePunkt }}" data-gp-etikett>
                        @svg('heroicon-o-tag', 'w-4 h-4 text-[var(--fa-ink-3)]') Anbruch-Etikett drucken
                    </a>
                </div>
            </div>
        @endif

        {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung,
             der Editor bleibt im Lesemodus offen. Neuanlage: nur der Knopf (keine Sperre nötig). --}}
        <div class="ml-auto">
            <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern"
                    :disabled="$autoSuggestPending" wire:loading.attr="disabled" wire:target="autoSuggestFromSupplierItem"
                    data-gp-speichern data-gp-speichern-kopf>{{ $neu ? ($autoSuggestPending ? 'KI analysiert …' : 'Grundprodukt anlegen') : 'Speichern' }}</x-fa::button>
            </x-foodalchemist::bearbeiten-leiste>
        </div>
    </x-slot:actions>

    {{-- Kennzahlen: Preis des Lead-Artikels (Hauptzahl) · Artikel · Allergenangaben · Warengruppe · Zustand.
         Fehlender Preis wird gezeigt, nicht versteckt. Ohne Artikelpflicht (Derivat, Platzhalter) ist 0 kein Mangel. --}}
    @if(! $neu && $gp !== null)
        <x-slot:kpiHeader>
            @php
                $lasPflicht = (bool) ($gp->requires_la ?? true);
                $nLas = (int) ($gp->n_las_total ?? 0);
                $hatPreis = $leadPreis?->price !== null;
                $konfidenz = $allergenKonfidenz['confidence'] ?? null;
                $kennzahlen = [
                    [
                        'kpi' => 'lead-preis', 'label' => 'Preis (Lead)',
                        'value' => $hatPreis
                            ? number_format((float) $leadPreis->price, 2, ',', '.') . ' € / ' . ($leadLa->ordering_unit ?? $leadLa->unit_code ?? 'Einheit')
                            : ($lasPflicht ? 'Preis fehlt' : 'entfällt'),
                        'primary' => $hatPreis,
                        'tone' => ! $hatPreis && $lasPflicht ? 'crit' : null,
                        'title' => $leadLa?->designation ?? 'Kein Lead-Artikel gesetzt',
                    ],
                    [
                        'kpi' => 'las', 'label' => 'Lieferantenartikel',
                        'value' => (string) $nLas,
                        'tone' => $nLas === 0 && $lasPflicht ? 'warn' : null,
                        'title' => $lasPflicht
                            ? 'Ohne Lieferantenartikel kein Preis, Rezepte mit diesem Grundprodukt bleiben ohne Preis.'
                            : 'Braucht keinen Lieferantenartikel (Nebenprodukt oder Platzhalter).',
                    ],
                    [
                        'kpi' => 'allergen', 'label' => 'Allergenangaben',
                        'value' => \Platform\FoodAlchemist\Support\Labels::konfidenz($konfidenz),
                        'tone' => ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit'][$konfidenz ?? ''] ?? null,
                        'title' => 'Aus ' . ($allergenKonfidenz['n_las_mit_daten'] ?? 0) . ' von ' . $nLas . ' Artikeln mit Allergenangaben zusammengeführt',
                    ],
                    [
                        'kpi' => 'warengruppe', 'label' => 'Warengruppe',
                        'value' => $gp->commodity_group?->name ?? $gp->commodity_group_code ?? '–',
                        'title' => $gp->sub_category ?? '',
                    ],
                    [
                        'kpi' => 'zustand', 'label' => 'Zustand',
                        'value' => $gp->condition ?: '–',
                    ],
                ];
            @endphp
            <x-fa::kpis :items="$kennzahlen" data-gp-editor-kpis />
        </x-slot:kpiHeader>
    @endif

    @if($fehler !== null)
        <x-fa::notice tone="crit" data-modal-fehler>{{ $fehler }}</x-fa::notice>
    @endif
    @if($autoSuggestPending)
        <x-fa::notice tone="info" data-gp-auto-suggest-laeuft>Lieferantenartikel wird gelesen, die KI bereitet einen Vorschlag für das Grundprodukt vor …</x-fa::notice>
    @endif

    {{-- Anreichern-Lauf: Vorschläge landen nach „Alle übernehmen" in den Feldern --}}
    @if(! $neu && ($bulkRun ?? null) !== null)
        <div @if($bulkRun->status === 'running') wire:poll.2s @endif data-gp-anreichern-status>
            @if($bulkRun->status === 'running')
                <x-fa::notice tone="info">Anreicherung läuft …</x-fa::notice>
            @else
                <x-fa::notice tone="ok">
                    Anreicherung fertig: {{ $bulkOffen }} {{ $bulkOffen === 1 ? 'Vorschlag' : 'Vorschläge' }} zum Übernehmen.
                    <x-slot:actions>
                        <x-fa::button size="sm" variant="secondary" wire:click="bulkAlleUebernehmen" data-gp-anreichern-uebernehmen>Alle übernehmen</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="bulkVerwerfen">Schließen</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            @endif
        </div>
    @endif

    <x-foodalchemist::editor-tabs marker="gp" wire-key="gp-tabs-{{ $gp?->id ?? 'neu' }}" :init="$neu ? 'allgemein' : 'price'" :gesperrt="in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true)"
        :tabs="[
            'price' => $neu ? null : 'Lieferantenartikel',
            'allergene' => $neu ? null : 'Allergene und Zusatzstoffe',
            'allgemein' => 'Stammdaten',
            'eigenschaften' => $neu ? null : 'Eigenschaften',
            'kalkulation' => $neu ? null : 'Kalkulation',
            'sensorik' => $neu ? null : 'Aroma',
            'ersatz' => $neu ? null : 'Ersatz',
            'einkauf' => $neu ? null : 'Einkauf',
            'lager' => $neu ? null : 'Lager',
            'verwaltung' => $neu ? null : 'Verwaltung',
        ]">

        {{-- ── Reiter: STAMMDATEN (bei der Neuanlage der einzige Inhalt) ────────── --}}
        <div x-show="tab === 'allgemein'" class="pt-4 flex flex-col gap-4">

            {{-- Neuanlage: Ausgangspunkt ist meist ein Lieferantenartikel — deshalb zuerst --}}
            @if($neu)
                <x-fa::section title="Lieferantenartikel" icon="heroicon-o-building-storefront" description="Wird beim Anlegen direkt verknüpft. Abweichende Allergene oder Zusatzstoffe werden gemeldet.">
                    @if($supplierItem !== null)
                        <div class="flex items-center justify-between gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2" data-gp-la-selected>
                            <div class="min-w-0">
                                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $supplierItem->designation }}</p>
                                <p class="{{ $leise }}">{{ $supplierItem->supplier?->name ?? 'Lieferant unbekannt' }} · {{ $supplierItem->article_number ? 'Art.-Nr. ' . $supplierItem->article_number : 'ohne Artikelnummer' }}</p>
                            </div>
                            <x-fa::button size="sm" variant="ghost" wire:click="supplierItemLoesen">Verknüpfung lösen</x-fa::button>
                        </div>
                    @else
                        <x-fa::input type="search" wire:model.live.debounce.300ms="laSuche" placeholder="Bezeichnung oder Artikelnummer suchen …" aria-label="Lieferantenartikel suchen" data-gp-la-search />
                        @if($supplierItemKandidaten->isNotEmpty())
                            <div class="flex flex-col" data-gp-la-results>
                                @foreach($supplierItemKandidaten as $la)
                                    <button type="button" wire:key="la-k-{{ $la->id }}" wire:click="supplierItemWaehlen({{ $la->id }})" class="{{ $listenKnopf }}">
                                        <span class="min-w-0">
                                            <span class="block font-medium">{{ $la->designation }}</span>
                                            <span class="block {{ $leise }}">{{ $la->supplier_name ?? 'Lieferant unbekannt' }} · {{ $la->article_number ? 'Art.-Nr. ' . $la->article_number : 'ohne Artikelnummer' }}</span>
                                        </span>
                                        @svg('heroicon-m-plus', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                    </button>
                                @endforeach
                            </div>
                        @elseif(trim($laSuche) !== '')
                            <p class="{{ $leise }}">Kein freier Lieferantenartikel gefunden.</p>
                        @endif
                    @endif
                </x-fa::section>

                <x-fa::section title="Vorschlag aus der Bezeichnung" icon="heroicon-o-sparkles" description="Lieferanten-Text einfügen, die KI füllt die Felder darunter. Prüfen und anpassen bleibt bei dir.">
                    <div class="flex flex-wrap items-center gap-2" data-ki-naming>
                        <x-fa::input wire:model="kiRohtext" placeholder="z. B. Zanderfilet TK 400 g" aria-label="Bezeichnung für den KI-Vorschlag" class="flex-1 min-w-[16rem]" />
                        <x-foodalchemist::ki-action action="kiVorschlagNaming" variant="ai" icon="heroicon-o-sparkles" label="Felder vorschlagen"
                            title="Hauptzutat, Zustand, Verarbeitung und Form aus der Bezeichnung vorschlagen" busy="Wird vorgeschlagen …" flash="Vorschlag da" />
                    </div>
                </x-fa::section>
            @endif

            {{-- Name --}}
            <x-fa::section title="Name" icon="heroicon-o-pencil-square">
                @if($neu)
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <x-fa::field label="Hauptzutat" for="gp-hauptzutat" required>
                            <x-fa::input id="gp-hauptzutat" wire:model.live.debounce.300ms="builder.hauptzutat" placeholder="z. B. Zander" data-builder-hauptzutat />
                        </x-fa::field>
                        <x-fa::field label="Zustand" for="gp-zustand-neu">
                            <x-fa::select id="gp-zustand-neu" wire:model.live="builder.condition" placeholder="Bitte wählen">
                                @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Verarbeitung" for="gp-verarbeitung" optional>
                            <x-fa::input id="gp-verarbeitung" wire:model.live.debounce.300ms="builder.processing" placeholder="z. B. Würfel 5 mm" />
                        </x-fa::field>
                        <x-fa::field label="Form" for="gp-form" optional>
                            <x-fa::input id="gp-form" wire:model.live.debounce.300ms="builder.form" placeholder="Ganz, Filet, Püree …" />
                        </x-fa::field>
                        <x-fa::field label="Gewicht oder Portion" for="gp-portion" optional>
                            <x-fa::input id="gp-portion" wire:model.live.debounce.300ms="builder.portion" placeholder="z. B. 180 g" />
                        </x-fa::field>
                        <x-fa::field label="Pflichtangabe" for="gp-pflicht" optional>
                            <x-fa::input id="gp-pflicht" wire:model.live.debounce.300ms="builder.pflichtangabe" placeholder="z. B. 3,5 %, Type 405, 16/20" />
                        </x-fa::field>
                    </div>
                    <fieldset class="min-w-0" data-zusatz-klammern>
                        <legend class="mb-1.5 {{ $titelKlein }}">Zusatz im Namen</legend>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach(['bio' => 'Bio', 'vegan' => 'Vegan', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei'] as $flag => $text)
                                <label for="gp-zusatz-{{ $flag }}" class="fa-chip" wire:key="gp-zusatz-{{ $flag }}">
                                    <input id="gp-zusatz-{{ $flag }}" type="checkbox" wire:model.live="builder.{{ $flag }}" class="sr-only peer" />
                                    <span>{{ $text }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <x-fa::field label="Name" for="gp-name" :hint="$neu ? 'Wird aus den Feldern gebildet. Nur bei Bedarf von Hand überschreiben.' : null">
                    <x-fa::input id="gp-name" wire:model.live.debounce.300ms="manuellerName" placeholder="{{ $vorschauName }}" data-name-feld />
                </x-fa::field>

                {{-- Vorschau: so heißt das Grundprodukt nach dem Speichern --}}
                <div class="flex flex-col gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-3 py-2" data-naming-vorschau>
                    <p class="{{ $leise }}">So heißt es nach dem Speichern</p>
                    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]" data-vorschau-name>{{ $vorschauName !== '' ? $vorschauName : '–' }}</p>
                    <p class="{{ $leise }}" title="Hauptzutat und Kennung, über die doppelte Grundprodukte erkannt werden">
                        Hauptzutat <span class="font-mono">{{ $vorschauSlug !== '' ? $vorschauSlug : '–' }}</span> · Kennung <span class="font-mono">{{ $vorschauKey !== '' && $vorschauKey !== '||' ? $vorschauKey : '–' }}</span>
                    </p>
                </div>
                @if($liveFehler !== [] || $warnungen !== [])
                    <div class="flex flex-col gap-1">
                        @foreach($liveFehler as $f)
                            <x-fa::signal tone="crit" data-live-fehler>{{ $f }}</x-fa::signal>
                        @endforeach
                        @foreach($warnungen as $w)
                            <x-fa::signal tone="warn" data-live-warnung>{{ $w }}</x-fa::signal>
                        @endforeach
                    </div>
                @endif

                {{-- Namensvorschlag aus dem Lead-Artikel (Vorschlag → Übernehmen) --}}
                @if(! $neu)
                    <div class="flex flex-col gap-2" data-name-aus-la>
                        <div>
                            <x-foodalchemist::ki-action action="nameAusLeadLa" variant="ai" icon="heroicon-o-sparkles" label="Name aus Lieferantenartikel ableiten"
                                title="Namensvorschlag aus der Bezeichnung des Lead-Artikels" busy="Wird abgeleitet …" flash="Vorschlag da" />
                        </div>
                        @if($nameVorschlag !== null)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2" data-name-vorschlag>
                                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">Vorschlag: <span class="font-medium">{{ $nameVorschlag }}</span></p>
                                <div class="flex items-center gap-1.5">
                                    <x-fa::button size="sm" variant="secondary" wire:click="nameVorschlagUebernehmen" data-name-vorschlag-uebernehmen>Vorschlag übernehmen</x-fa::button>
                                    <x-fa::button size="sm" variant="ghost" wire:click="nameVorschlagVerwerfen">Verwerfen</x-fa::button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if($neu)
                    <label class="inline-flex items-center gap-2 {{ $leise }}" title="Legt das Grundprodukt auch an, wenn es schon ein sehr ähnliches gibt">
                        <input type="checkbox" wire:model.live="force" class="{{ $haken }}" data-force-flag />
                        Auch anlegen, wenn es schon ein sehr ähnliches Grundprodukt gibt
                    </label>
                @endif
            </x-fa::section>

            {{-- Einordnung: Warengruppe · Unterkategorie · Zustand --}}
            <x-fa::section title="Einordnung" icon="heroicon-o-squares-2x2">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-fa::field label="Warengruppe" for="gp-wg">
                        <x-fa::select id="gp-wg" wire:model.live="builder.commodity_group_code" placeholder="Bitte wählen">
                            @foreach($warengruppen as $wg)<option value="{{ $wg->code }}">{{ $wg->codedLabel() }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Unterkategorie" for="gp-sub"
                        :hint="($builder['commodity_group_code'] ?? '') === '' ? 'Erst Warengruppe wählen.' : 'Neue Unterkategorien legst du in den Einstellungen unter Warengruppen an.'">
                        <x-fa::select id="gp-sub" wire:model.live="builder.sub_category" placeholder="Bitte wählen" data-sub-kategorie
                            :disabled="($builder['commodity_group_code'] ?? '') === ''">
                            @foreach($subKategorien as $sk)
                                <option value="{{ $sk->sub_category }}">{{ $sk->sub_category }}</option>
                            @endforeach
                            @if(($builder['sub_category'] ?? '') !== '' && ! $subKategorien->contains('sub_category', $builder['sub_category']))
                                <option value="{{ $builder['sub_category'] }}" selected>{{ $builder['sub_category'] }} (bisheriger Wert)</option>
                            @endif
                        </x-fa::select>
                    </x-fa::field>
                </div>

                @if(! $neu && $gp !== null)
                    <div class="pt-3 border-t border-[var(--fa-line)]">
                        <x-foodalchemist::ki-header label="Zustand" field="zustand"
                            :source="$gp->condition_source" :confidence="$gp->condition_ai_confidence !== null ? (float) $gp->condition_ai_confidence : null"
                            :reasoning="$gp->condition_ai_reasoning" :hasProposal="isset($kiVorschlag['condition'])">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-fa::select wire:model.live="builder.condition" placeholder="Bitte wählen" aria-label="Zustand" class="w-44">
                                    @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                                </x-fa::select>
                                @if(isset($kiVorschlag['condition']))
                                    <x-fa::badge tone="accent" data-condition-vorschlag>
                                        Vorschlag: {{ $kiVorschlag['condition']['werte']['condition'] ?? '–' }} ({{ round($kiVorschlag['condition']['confidence'] * 100) }} %)
                                    </x-fa::badge>
                                @endif
                            </div>
                        </x-foodalchemist::ki-header>
                    </div>
                @endif
            </x-fa::section>

            {{-- Nebenprodukt (Derivat) --}}
            <x-fa::section title="Nebenprodukt" icon="heroicon-o-arrow-turn-down-right">
                <label class="inline-flex items-start gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                    <input type="checkbox" wire:model.live="builder.is_derivat" class="{{ $haken }} mt-0.5" data-derivat-toggle />
                    <span>
                        Küchen-Nebenprodukt (Schale, Saft, Parüren, Karkasse …)
                        <span class="block {{ $leise }}">Braucht keinen Lieferantenartikel und übernimmt die Allergene laufend vom Ausgangsprodukt.</span>
                    </span>
                </label>
                @if($builder['is_derivat'])
                    <div class="flex flex-col gap-1.5" data-derivat-mutter>
                        <p class="{{ $titelKlein }}">Ausgangsprodukt</p>
                        @if($builder['derivat_von_gp_id'])
                            <div class="flex items-center gap-2">
                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $derivatMutterName ?? '–' }}</span>
                                <x-fa::button size="sm" variant="ghost" wire:click="$set('builder.derivat_von_gp_id', null)">Ausgangsprodukt ändern</x-fa::button>
                            </div>
                        @else
                            <x-fa::input type="search" wire:model.live.debounce.300ms="derivatSuche" placeholder="Ausgangsprodukt suchen …" aria-label="Ausgangsprodukt suchen" />
                            @if($derivatKandidaten->isNotEmpty())
                                <div class="flex flex-col">
                                    @foreach($derivatKandidaten as $kandidat)
                                        <button type="button" wire:key="dk-{{ $kandidat->id }}"
                                                wire:click="$set('builder.derivat_von_gp_id', {{ $kandidat->id }})" class="{{ $listenKnopf }}">
                                            <span class="min-w-0 truncate">{{ $kandidat->name }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </x-fa::section>
        </div>{{-- /Reiter STAMMDATEN --}}

        {{-- Alle weiteren Reiter brauchen ein gespeichertes Grundprodukt --}}
        @if(! $neu && $gp !== null)
            {{-- ── Reiter: LIEFERANTENARTIKEL (Preis, Lead, Verwendungen) ───────── --}}
            <div x-show="tab === 'price'" x-cloak class="pt-4">
                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="las" :key="'gpd-las-'.$gpId" />
                </x-fa::section>
            </div>

            {{-- ── Reiter: ALLERGENE UND ZUSATZSTOFFE ───────────────────────────── --}}
            <div x-show="tab === 'allergene'" x-cloak class="pt-4 flex flex-col gap-4">
                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="allergene" :key="'gpd-allerg-'.$gpId" />
                </x-fa::section>
                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="zusatzstoffe" :key="'gpd-zusatz-'.$gpId" />
                </x-fa::section>
            </div>

            {{-- ── Reiter: EIGENSCHAFTEN (Merkmale, Favorit, Nährwerte) ───────────── --}}
            <div x-show="tab === 'eigenschaften'" x-cloak class="pt-4 flex flex-col gap-4">
                <x-fa::section title="Merkmale" icon="heroicon-o-tag">
                    <x-foodalchemist::ki-header label="Merkmale" field="tags"
                        :source="$gp->tag_source" :confidence="$gp->tag_ai_confidence !== null ? (float) $gp->tag_ai_confidence : null"
                        :reasoning="$gp->tag_ai_reasoning" :hasProposal="isset($kiVorschlag['tags'])">
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2" data-tags-grid>
                            @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag)
                                <div class="flex items-center justify-between gap-2 min-w-0" wire:key="gp-tag-{{ $tag }}">
                                    <label for="gp-tag-{{ $tag }}" class="min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $tagLabels[$tag] ?? $tag }}</label>
                                    <x-fa::select id="gp-tag-{{ $tag }}" size="sm" wire:model.live="tags.{{ $tag }}" class="w-32 shrink-0">
                                        <option value="">offen</option>
                                        <option value="1">ja</option>
                                        <option value="0">nein</option>
                                    </x-fa::select>
                                </div>
                            @endforeach
                        </div>
                    </x-foodalchemist::ki-header>
                </x-fa::section>

                <x-fa::section title="Favorit" icon="heroicon-o-star">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="{{ $leise }} min-w-0">
                            @if($gp->is_favorite)
                                In deinen Favoriten{{ $gp->favorite_rank !== null ? ', Rang ' . $gp->favorite_rank : '' }}.
                            @else
                                Favoriten nutzt der Generator, wenn „Auf Basis meiner Favoriten bauen" eingeschaltet ist.
                            @endif
                        </p>
                        @if($kannKuratieren)
                            <x-fa::button size="sm" :variant="$gp->is_favorite ? 'ghost' : 'secondary'" :icon="$gp->is_favorite ? 'heroicon-s-star' : 'heroicon-o-star'"
                                wire:click="favoriteToggle" data-gp-favoriten-toggle>{{ $gp->is_favorite ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen' }}</x-fa::button>
                        @elseif($gp->is_favorite)
                            <x-fa::badge tone="accent" icon="heroicon-s-star" title="Favorit (nur lesen)">Favorit</x-fa::badge>
                        @endif
                    </div>
                </x-fa::section>

                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="naehrwerte" :key="'gpd-naehr-'.$gpId" />
                </x-fa::section>
            </div>

            {{-- ── Reiter: KALKULATION (Verluste, Gewichte je Form) ─────────────── --}}
            <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 flex flex-col gap-4">
                <x-fa::section title="Verluste und Stückgewicht" icon="heroicon-o-calculator"
                    description="Gilt, wenn eine Rezept-Zutat keinen eigenen Wert hat. Leer lassen übernimmt die Vorgabe der Warengruppe.">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" data-gp-defaults>
                        <x-fa::field label="Garverlust in %" for="gp-garverlust">
                            <x-fa::input id="gp-garverlust" numeric inputmode="decimal" wire:model="defaults.cooking_loss_default_pct" placeholder="–" data-gp-garverlust />
                        </x-fa::field>
                        <x-fa::field label="Putzverlust in %" for="gp-putzverlust">
                            <x-fa::input id="gp-putzverlust" numeric inputmode="decimal" wire:model="defaults.trimming_loss_default_pct" placeholder="–" data-gp-putzverlust />
                        </x-fa::field>
                        <x-fa::field label="Stückgewicht in g" for="gp-stk">
                            <x-fa::input id="gp-stk" numeric inputmode="decimal" wire:model="defaults.piece_default_g" placeholder="–" data-gp-stk />
                        </x-fa::field>
                    </div>
                </x-fa::section>

                <x-fa::section title="Gewicht je Form" icon="heroicon-o-scale" :meta="$formen->count() ?: null"
                    description="Legt fest, welche Einheiten im Rezept wählbar sind (Stück, Scheibe, Würfel …), und rechnet den Einkaufspreis um.">
                    <x-slot:actions>
                        <x-foodalchemist::ki-action action="formenKiSchaetzen" variant="ai" icon="heroicon-o-sparkles" label="Gewichte schätzen"
                            data-gp-formen-ki busy="Wird geschätzt …" flash="Geschätzt" />
                    </x-slot:actions>
                    @if($hinweis)<x-fa::notice tone="ok" data-gp-formen-hinweis>{{ $hinweis }}</x-fa::notice>@endif
                    @if($formen->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="fa-table fa-table--compact">
                                <thead><tr><th>Form</th><th class="num">Gewicht</th><th>Herkunft</th><th><span class="sr-only">Aktion</span></th></tr></thead>
                                <tbody>
                                    @foreach($formen as $f)
                                        <tr wire:key="gpform-{{ $f->form_slug }}">
                                            <td class="font-medium">{{ $formLabel($f->form_slug) }}</td>
                                            <td class="num"><x-fa::menge :value="(float) $f->gramm" unit="g" :decimals="0" /></td>
                                            <td><x-fa::badge :tone="$f->source === 'ki' ? 'accent' : 'neutral'">{{ $quelleLabel[$f->source] ?? $f->source }}</x-fa::badge></td>
                                            <td class="text-right">
                                                <x-fa::icon-button size="sm" tone="danger" icon="heroicon-m-x-mark" label="Form {{ $formLabel($f->form_slug) }} entfernen"
                                                    wire:click="formEntfernen('{{ $f->form_slug }}')" data-gp-form-remove />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="{{ $leise }}" data-gp-formen-leer>Noch keine Gewichte je Form. Schätzen lassen oder unten eintragen.</p>
                    @endif
                    <div class="flex flex-wrap items-end gap-2">
                        <x-fa::field label="Form" for="gp-form-neu">
                            <x-fa::select id="gp-form-neu" wire:model="formNeuSlug" class="w-36" data-gp-form-slug>
                                @foreach($formSlugs as $slug)<option value="{{ $slug }}">{{ $formLabel($slug) }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Gewicht in g" for="gp-form-gramm" error="formNeuGramm">
                            <x-fa::input id="gp-form-gramm" numeric inputmode="decimal" wire:model="formNeuGramm" placeholder="z. B. 150" class="w-28" data-gp-form-gramm />
                        </x-fa::field>
                        <x-fa::button icon="heroicon-m-plus" wire:click="formSetzen" data-gp-form-add>Form hinzufügen</x-fa::button>
                    </div>
                </x-fa::section>
            </div>

            {{-- ── Reiter: AROMA (Sensorik, Aroma-Anker, Pairing) ───────────────── --}}
            <div x-show="tab === 'sensorik'" x-cloak class="pt-4 flex flex-col gap-4">
                <x-fa::section title="Geschmack" icon="heroicon-o-beaker">
                    @include('foodalchemist::livewire.concepter.partials.sensorik')
                </x-fa::section>

                {{-- Aroma-Anker editierbar (mehrere je Grundprodukt, Haupt- oder Nebenanker). Das Pairing darunter
                     ist nur lesend und aktualisiert sich, sobald hier ein Anker steht. --}}
                @if($gpId !== null)
                    <x-fa::section title="Aroma-Anker" icon="heroicon-o-sparkles" :meta="$gpAnker->count() ?: null">
                        <div class="flex flex-wrap gap-1.5" data-gp-anker-liste>
                            @forelse($gpAnker as $a)
                                <x-fa::badge wire:key="ga-{{ $a->id }}" :tone="$a->role === 'kern' ? 'accent' : 'neutral'" :icon="$a->role === 'kern' ? 'heroicon-s-star' : null"
                                    title="{{ $a->role === 'kern' ? 'Hauptanker' : 'Nebenanker' }} · {{ $quelleLabel[$a->source] ?? $a->source }}{{ $a->ai_confidence !== null ? ' ' . round($a->ai_confidence * 100) . ' %' : '' }}">
                                    {{ $a->display_de }}
                                    <button type="button" wire:click="gpAnkerLoesen({{ $a->id }})" class="-mr-1 inline-flex items-center rounded-full hover:text-[var(--fa-crit)]" aria-label="Anker {{ $a->display_de }} lösen" title="Anker lösen" data-gp-anker-loesen>
                                        @svg('heroicon-m-x-mark', 'w-3.5 h-3.5')
                                    </button>
                                </x-fa::badge>
                            @empty
                                <p class="{{ $leise }}">Noch kein Aroma-Anker gesetzt.</p>
                            @endforelse
                        </div>
                        @if($gpAnkerFehler !== null)<x-fa::signal tone="crit" data-gp-anker-fehler>{{ $gpAnkerFehler }}</x-fa::signal>@endif
                        <div class="flex flex-wrap items-start gap-2">
                            <x-fa::select size="md" wire:model="gpAnkerRolle" class="w-40" aria-label="Rolle des nächsten Ankers" title="Rolle des nächsten verknüpften Ankers" data-gp-anker-rolle>
                                <option value="kern">Hauptanker</option>
                                <option value="neben">Nebenanker</option>
                            </x-fa::select>
                            <div class="flex-1 min-w-[14rem] flex flex-col gap-1">
                                <x-fa::input type="search" wire:model.live.debounce.300ms="gpAnkerSuche" placeholder="Anker suchen und verknüpfen …" aria-label="Aroma-Anker suchen" data-gp-anker-suche />
                                @foreach($gpAnkerKandidaten as $kandidat)
                                    <button type="button" wire:key="gak-{{ $kandidat->id }}" wire:click="gpAnkerVerknuepfen({{ $kandidat->id }})" class="{{ $listenKnopf }}" data-gp-anker-kandidat>
                                        <span class="min-w-0 truncate">{{ $kandidat->display_de }}</span>
                                        <span class="shrink-0 {{ $leise }}">{{ $kandidat->category ?: $kandidat->slug }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </x-fa::section>
                @endif

                <x-fa::section title="Pairing" icon="heroicon-o-link">
                    @include('foodalchemist::livewire.concepter.partials.pairing')
                </x-fa::section>
            </div>

            {{-- ── Reiter: ERSATZ (selbst machen oder kaufen, Artikel-Ersatz) ───── --}}
            <div x-show="tab === 'ersatz'" x-cloak class="pt-4">
                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="ersatz" :key="'gpd-ersatz-'.$gpId" />
                </x-fa::section>
            </div>

            {{-- ── Reiter: EINKAUF (Spec 66 §4) ─────────────────────────────────── --}}
            <div x-show="tab === 'einkauf'" x-cloak class="pt-4">
                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="einkauf" :key="'gpd-einkauf-'.$gpId" />
                </x-fa::section>
            </div>

            {{-- ── Reiter: VERWALTUNG (in allen Rezepten ersetzen) ──────────────── --}}
            <div x-show="tab === 'verwaltung'" x-cloak class="pt-4">
                <x-fa::section title="In allen Rezepten ersetzen" icon="heroicon-o-arrows-right-left"
                    description="Hängt jede Rezept-Zeile mit diesem Grundprodukt auf ein anderes um und rechnet die Rezepte neu. Danach lässt es sich gefahrlos aussortieren.">
                    @if($hinweis)<x-fa::notice tone="ok" data-gp-tausch-hinweis>{{ $hinweis }}</x-fa::notice>@endif
                    <x-fa::field label="Ersetzen durch" for="gp-tausch" error="tauschSuche">
                        <x-fa::input id="gp-tausch" type="search" wire:model.live.debounce.300ms="tauschSuche" placeholder="Grundprodukt suchen …" data-gp-tausch-suche />
                    </x-fa::field>
                    @if($tauschKandidaten->isNotEmpty())
                        <div class="flex flex-col">
                            @foreach($tauschKandidaten as $k)
                                <button type="button" wire:key="tausch-{{ $k->id }}" wire:click="gpErsetzen({{ $k->id }})"
                                        wire:confirm="Dieses Grundprodukt in ALLEN Rezepten durch „{{ $k->name }}“ ersetzen?"
                                        class="{{ $listenKnopf }}" data-gp-tausch-kandidat>
                                    <span class="min-w-0 truncate">{{ $k->name }}</span>
                                    <x-fa::status :value="$k->status" class="shrink-0" />
                                </button>
                            @endforeach
                        </div>
                    @elseif(trim($tauschSuche) !== '')
                        <p class="{{ $leise }}">Kein passendes Grundprodukt gefunden.</p>
                    @endif
                </x-fa::section>
            </div>
        @endif
        {{-- Spec 67: Stellplatz ist Betriebsdatum — auch ohne „Bearbeiten“ pflegbar --}}
        <x-slot:frei>
            @if($gpId)
            <div x-show="tab === 'lager'" x-cloak class="pt-4">
                <x-fa::section>
                    <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" :embedded="true" section="lager" :key="'gpd-lager-'.$gpId" />
                </x-fa::section>
            </div>
            @endif
        </x-slot:frei>
    </x-foodalchemist::editor-tabs>
</x-foodalchemist::modal>
