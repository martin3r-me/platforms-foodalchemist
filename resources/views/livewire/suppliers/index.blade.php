{{-- Lieferanten — Liste links, Artikel des gewählten Lieferanten in der Mitte.
     Hauptaufgabe: Artikel eines Lieferanten finden, Preis prüfen und jedem Artikel sein Grundprodukt zuordnen.
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und data-Marker
     unverändert. Neu: Kennzahlen zum Zuordnungsstand, Fehler sichtbar auf der Seite (vorher nur im Dialog),
     fehlender Preis/fehlende Menge als Signal, Lieferanten-Aktionen und Abgleich nur bei gewähltem Lieferanten. --}}
@php
    $anzahlAuswahl = count(array_filter($auswahl));
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $bandTon = ['exact' => 'ok', 'fuzzy_high' => 'info', 'fuzzy_low' => 'warn'];
    $bandText = ['exact' => 'Exakt', 'fuzzy_high' => 'Ähnlich', 'fuzzy_low' => 'Unsicher'];
    $spaltenAnzahl = $globaleSuche ? 10 : 9;

    $kennzahlen = [];
    if (! $globaleSuche && $aktiverLieferant) {
        $nArtikel = (int) $aktiverLieferant->item_count;
        $nZugeordnet = (int) $aktiverLieferant->mapped_count;
        $quote = $nArtikel > 0 ? (int) round($nZugeordnet / $nArtikel * 100) : null;
        $kennzahlen = [
            ['label' => 'Artikel', 'value' => $fmt($nArtikel), 'kpi' => 'artikel'],
            ['label' => 'Einem Grundprodukt zugeordnet', 'value' => $quote !== null ? $quote . ' %' : '–', 'primary' => true,
                'title' => $fmt($nZugeordnet) . ' von ' . $fmt($nArtikel) . ' Artikeln', 'kpi' => 'zugeordnet'],
            ['label' => 'Ohne Grundprodukt', 'value' => $fmt(max(0, $nArtikel - $nZugeordnet)),
                'tone' => $nArtikel - $nZugeordnet > 0 ? 'warn' : 'ok', 'kpi' => 'offen'],
            ['label' => 'Zuordnungs-Vorschläge', 'value' => $fmt($offeneVorschlaege),
                'tone' => $offeneVorschlaege > 0 ? 'warn' : null, 'kpi' => 'vorschlaege'],
        ];
    }
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Lieferanten" icon="heroicon-o-truck" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lieferanten'],
        ]" />
    </x-slot>

    {{-- Zone links: Lieferanten-Liste --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Lieferanten" width="w-80" storeKey="faSuppliersOpen">
            <div class="p-3 flex flex-col gap-3" data-supplier-liste>
                <div class="relative">
                    <label for="lieferant-artikel-suche" class="sr-only">Artikel in allen Lieferanten suchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="lieferant-artikel-suche" type="search" wire:model.live.debounce.300ms="q"
                        placeholder="Artikel in allen Lieferanten" class="pl-8" data-global-suche />
                </div>

                <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]">
                    <label for="lieferant-filter" class="sr-only">Lieferanten filtern</label>
                    <x-fa::input id="lieferant-filter" type="search" size="sm" wire:model.live.debounce.300ms="supplierSuche" placeholder="Lieferant filtern" />
                    <div class="flex items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                            <input type="checkbox" wire:model.live="includeInactive" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" /> Inaktive zeigen
                        </label>
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus"
                            x-on:click="$dispatch('modal.open', { name: 'lieferant-neu' })" data-neuer-lieferant-btn>Lieferant anlegen</x-fa::button>
                    </div>
                </div>

                <div class="flex flex-col gap-0.5">
                    @forelse($lieferanten as $l)
                        <x-foodalchemist::filter-row wire:key="sup-{{ $l->id }}" wire:click="waehleLieferant({{ $l->id }})"
                            :active="! $globaleSuche && $supplierId === $l->id" :count="$l->item_count"
                            title="{{ $l->name }}: {{ $fmt($l->item_count) }} Artikel, {{ $fmt($l->mapped_count) }} einem Grundprodukt zugeordnet{{ $l->is_inactive ? ' (inaktiv)' : '' }}">
                            <span class="{{ $l->is_inactive ? 'line-through text-[var(--fa-ink-3)]' : '' }}">{{ $l->name }}</span>
                        </x-foodalchemist::filter-row>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-truck" title="Kein Lieferant gefunden">Filter leeren oder einen Lieferanten anlegen.</x-fa::empty>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        {{-- Kopf: wer ist gewählt, was ist die Hauptaktion --}}
        <x-fa::page-header :title="$globaleSuche ? 'Suche „' . $q . '“ in allen Lieferanten' : ($aktiverLieferant?->name ?? 'Lieferanten')"
            :subtitle="$artikel ? $fmt($artikel->total()) . ' Treffer' : null">
            @if(! $globaleSuche && $aktiverLieferant)
                <x-slot:actions>
                    {{-- R9.1/R9.2: Beziehungs-Stammblatt (Status, Konditionen, Absprachen, Dokumente, Bündelung) --}}
                    <x-fa::button icon="heroicon-m-identification" wire:click="$dispatch('supplier-detail.oeffnen', { id: {{ $aktiverLieferant->id }} })"
                        title="Status, Konditionen, Absprachen, Verträge und Fristen" data-beziehung-btn>Stammblatt öffnen</x-fa::button>
                    <x-fa::button icon="heroicon-m-printer" :href="route('foodalchemist.suppliers.dokument', ['id' => $aktiverLieferant->id, 'profil' => 'kalkulation'])" target="_blank"
                        title="Artikelliste mit Grundprodukt und Preisen zum Drucken oder als PDF" data-lieferant-druck>Artikelliste drucken</x-fa::button>
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                            <button type="button" role="menuitem" wire:click="anomalienAnzeigen" wire:loading.attr="disabled" x-on:click="offen = false"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]" data-anomalien-btn>
                                @svg('heroicon-o-chart-bar', 'w-4 h-4 text-[var(--fa-ink-3)]') Preisausreißer anzeigen
                            </button>
                            @if($darfLieferantEdit)
                                {{-- Stammdaten-Pflege lebt im Stammblatt; hier nur Aktivieren/Deaktivieren --}}
                                <button type="button" role="menuitem" x-on:click="offen = false"
                                        wire:click="lieferantDeaktivieren({{ $aktiverLieferant->is_inactive ? 'false' : 'true' }})"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $aktiverLieferant->is_inactive ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-crit)]' }}">
                                    @svg($aktiverLieferant->is_inactive ? 'heroicon-o-play' : 'heroicon-o-pause', 'w-4 h-4')
                                    {{ $aktiverLieferant->is_inactive ? 'Lieferant aktivieren' : 'Lieferant deaktivieren' }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <x-fa::button variant="primary" icon="heroicon-m-plus" x-on:click="$dispatch('modal.open', { name: 'artikel-neu' })" data-neuer-artikel-btn>Artikel anlegen</x-fa::button>
                </x-slot:actions>
            @else
                {{-- Preisausreißer gelten über alle Lieferanten — auch ohne Auswahl erreichbar (wie vorher) --}}
                <x-slot:actions>
                    <x-fa::button icon="heroicon-m-chart-bar" wire:click="anomalienAnzeigen" wire:loading.attr="disabled" data-anomalien-btn>Preisausreißer anzeigen</x-fa::button>
                </x-slot:actions>
            @endif
        </x-fa::page-header>

        {{-- Fehler aus Seitenaktionen (Bevorzugen, Mehrfachauswahl, Abgleich) — vorher nur im Dialog sichtbar --}}
        @if($fehler)
            <x-fa::notice tone="crit" data-seiten-fehler>{{ $fehler }}</x-fa::notice>
        @endif

        {{-- M9-06/#393: offene Zuordnungs-Vorschläge im eigenen Team → Prüfliste --}}
        @if($offeneMatches > 0)
            <x-fa::notice tone="warn">
                {{ $fmt($offeneMatches) }} Vorschläge, welcher Artikel zu welchem Grundprodukt gehört, warten auf deine Prüfung.
                <x-slot:actions>
                    <x-fa::button size="sm" icon-right="heroicon-m-arrow-right"
                        :href="\Illuminate\Support\Facades\Route::has('foodalchemist.review') ? route('foodalchemist.review') : '/foodalchemist/zu-pruefen'"
                        data-zu-pruefen-hinweis>Vorschläge prüfen</x-fa::button>
                </x-slot:actions>
            </x-fa::notice>
        @endif

        @if($kennzahlen !== [])
            <x-fa::kpis :items="$kennzahlen" data-lieferant-kpis />
        @endif

        {{-- Werkzeugleiste: Suche im Lieferanten links, Filter und Abgleich rechts --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                @if(! $globaleSuche && $aktiverLieferant)
                    <div class="relative w-72 max-w-full">
                        <label for="lieferant-lokale-suche" class="sr-only">Artikel dieses Lieferanten suchen</label>
                        @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                        <x-fa::input id="lieferant-lokale-suche" type="search" wire:model.live.debounce.300ms="artikelSuche"
                            placeholder="Artikel dieses Lieferanten" class="pl-8" data-lokale-suche />
                    </div>
                @endif
                <label class="inline-flex items-center gap-2 cursor-pointer text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                    <input type="checkbox" wire:model.live="onlyActive" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" /> Nur lieferbare Artikel
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if(! $globaleSuche && $aktiverLieferant)
                    <x-fa::button icon="heroicon-m-link" wire:click="bulkMatchStarten" wire:loading.attr="disabled"
                        title="Sucht für alle Artikel ohne Grundprodukt passende Grundprodukte (Artikelnummer, EAN, ähnlicher Name). Die Vorschläge prüfst du danach."
                        data-bulk-match-btn>Grundprodukte vorschlagen</x-fa::button>
                    @if($offeneVorschlaege > 0)
                        <x-fa::button :variant="$reviewOffen ? 'secondary' : 'ghost'" icon="heroicon-m-clipboard-document-check" wire:click="$toggle('reviewOffen')"
                            aria-pressed="{{ $reviewOffen ? 'true' : 'false' }}" data-review-btn>{{ $fmt($offeneVorschlaege) }} Vorschläge prüfen</x-fa::button>
                    @endif
                @endif
                <x-fa::select wire:model.live="perPage" size="sm" aria-label="Einträge je Seite" class="w-auto" data-per-page>
                    @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }} je Seite</option>@endforeach
                </x-fa::select>
            </div>
        </div>

        {{-- M3-11: Zuordnungs-Vorschläge des Abgleichs prüfen --}}
        @if($reviewOffen)
            <x-fa::section title="Zuordnungs-Vorschläge" icon="heroicon-o-link" :meta="$vorschlaege->count() > 0 ? $fmt($vorschlaege->count()) : null"
                :description="$bulkStats !== null ? 'Letzter Abgleich: ' . $bulkStats['geprueft'] . ' Artikel geprüft, ' . $bulkStats['exact'] . ' exakt, ' . $bulkStats['fuzzy'] . ' ähnlich, ' . $bulkStats['ohne_treffer'] . ' ohne Treffer, ' . $bulkStats['uebersprungen'] . ' übersprungen.' : 'Je Vorschlag übernehmen oder verwerfen. Exakt heißt: gleiche Artikelnummer oder EAN.'"
                data-review-liste>
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ghost" wire:click="$set('reviewOffen', false)">Schließen</x-fa::button>
                </x-slot:actions>
                @if($vorschlaege->isNotEmpty())
                    <div class="-mx-4 -mb-4 border-t border-[var(--fa-line)] overflow-x-auto">
                        <table class="fa-table fa-table--compact">
                            <thead>
                                <tr>
                                    <th>Treffer</th>
                                    <th class="w-1/2">Artikel</th>
                                    <th class="w-1/2">Grundprodukt</th>
                                    <th><span class="sr-only">Entscheidung</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($vorschlaege as $v)
                                    <tr wire:key="mp-{{ $v->id }}" data-vorschlag="{{ $v->id }}">
                                        <td class="whitespace-nowrap">
                                            <x-fa::badge :tone="$bandTon[$v->band] ?? 'neutral'" title="{{ $v->methode }}">
                                                {{ $bandText[$v->band] ?? 'Vorschlag' }} <span class="tabular-nums">{{ number_format((float) $v->score, 2, ',', '.') }}</span>
                                            </x-fa::badge>
                                        </td>
                                        <td class="text-[var(--fa-ink)]">{{ $v->item?->designation }}</td>
                                        <td>
                                            <span class="inline-flex items-center gap-1.5 text-[var(--fa-ink)]">
                                                @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 shrink-0 text-[var(--fa-ink-3)]'){{ $v->gp?->name }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-fa::button size="sm" variant="ghost" wire:click="vorschlagVerwerfen({{ $v->id }})">Verwerfen</x-fa::button>
                                                <x-fa::button size="sm" icon="heroicon-m-check" wire:click="vorschlagUebernehmen({{ $v->id }})">Übernehmen</x-fa::button>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine offenen Vorschläge">
                        Alles entschieden. Mit „Grundprodukte vorschlagen“ startest du einen neuen Abgleich.
                    </x-fa::empty>
                @endif
            </x-fa::section>
        @endif

        {{-- M3-11-Nachtrag: Mehrfachauswahl (D-2 §4) — erscheint bei Auswahl --}}
        @if($anzahlAuswahl > 0)
            <div class="flex flex-wrap items-center gap-2 px-3 py-2 rounded-[var(--fa-radius-surface)] bg-[var(--fa-accent-soft)]" data-bulk-leiste>
                <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-accent)] tabular-nums">{{ $anzahlAuswahl }} ausgewählt</span>
                <div class="relative">
                    <label for="bulk-gp-suche" class="sr-only">Grundprodukt zuweisen</label>
                    <x-fa::input id="bulk-gp-suche" type="search" size="sm" wire:model.live.debounce.300ms="bulkGpSuche"
                        placeholder="Grundprodukt zuweisen" class="w-60" data-bulk-gp-suche />
                    @if($bulkGpKandidaten->isNotEmpty())
                        <div class="absolute left-0 top-full mt-1 z-20 w-80 fa-surface shadow-xl py-1">
                            @foreach($bulkGpKandidaten as $kandidat)
                                <button type="button" wire:key="bgk-{{ $kandidat->id }}" wire:click="bulkGpZuweisen({{ $kandidat->id }})"
                                        class="block w-full text-left px-3 py-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">{{ $kandidat->name }}</button>
                            @endforeach
                            <p class="px-3 pt-1 pb-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Bereits zugeordnete Artikel bleiben unverändert.</p>
                        </div>
                    @endif
                </div>
                <x-fa::button size="sm" wire:click="bulkMappingEntfernen">Zuordnung lösen</x-fa::button>
                <x-fa::button size="sm" wire:click="bulkEinstellen(true)">Auslisten</x-fa::button>
                <x-fa::button size="sm" wire:click="bulkEinstellen(false)">Wieder lieferbar</x-fa::button>
                <span class="ml-auto"></span>
                <x-fa::button size="sm" variant="ghost" wire:click="$set('auswahl', [])">Auswahl aufheben</x-fa::button>
                <span class="w-px h-5 bg-[var(--fa-accent-line)]" aria-hidden="true"></span>
                <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="bulkLoeschen"
                    wire:confirm="{{ $anzahlAuswahl }} Artikel wirklich löschen?">Artikel löschen</x-fa::button>
            </div>
        @endif

        {{-- Artikel-Tabelle (M2-02) --}}
        <div class="fa-surface overflow-hidden" data-artikel-tabelle>
            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-10"><span class="sr-only">Auswahl</span></th>
                            @if($globaleSuche)<th>Lieferant</th>@endif
                            <th>Art.-Nr.</th>
                            <th class="w-full">Bezeichnung</th>
                            <th class="num">Gebinde</th>
                            <th>Status</th>
                            <th class="num">EK</th>
                            <th class="num" title="Preis je kg, l oder Stück">Vergleichspreis</th>
                            <th>Grundprodukt</th>
                            <th class="text-right"><span class="sr-only">Bevorzugt</span>@svg('heroicon-m-star', 'w-4 h-4 inline-block text-[var(--fa-ink-3)]')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($artikel ?? [] as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td>
                                    <input type="checkbox" wire:model.live="auswahl.{{ $item->id }}" aria-label="{{ $item->designation }} auswählen"
                                           class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-artikel-checkbox="{{ $item->id }}" />
                                </td>
                                @if($globaleSuche)
                                    <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $item->supplier?->name ?? '–' }}</td>
                                @endif
                                <td class="whitespace-nowrap font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $item->article_number ?? '–' }}</td>
                                <td class="min-w-[14rem]">
                                    <button type="button" wire:click="$dispatch('item-modal.oeffnen', { id: {{ $item->id }} })"
                                            class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline">{{ $item->designation }}</button>
                                </td>
                                <td class="num">
                                    @if($item->qty !== null)
                                        <x-fa::menge :value="$item->qty" :unit="$item->unit_code ?? $item->ordering_unit ?? ''" />
                                    @else
                                        <x-fa::badge tone="warn" title="Ohne Gebindemenge kein Vergleichspreis">Menge fehlt</x-fa::badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1">
                                        @if($item->is_discontinued)<x-fa::badge>Ausgelistet</x-fa::badge>
                                        @else<x-fa::badge tone="ok">Lieferbar</x-fa::badge>@endif
                                        @if($item->is_preorder)
                                            <x-fa::badge tone="info" icon="heroicon-m-clock" title="Vorbestell-Artikel{{ $item->preorder_days ? ', ' . $item->preorder_days . ' Tage Vorlauf' : '' }}" data-vorbestell-pill>Vorbestellung{{ $item->preorder_days ? ' ' . $item->preorder_days . ' T' : '' }}</x-fa::badge>
                                        @endif
                                    </span>
                                </td>
                                <td class="num text-[var(--fa-ink)]"><x-fa::money :value="$item->aktiver_preis" /></td>
                                <td class="num text-[var(--fa-ink-2)]" data-vergleichspreis>
                                    @if($item->vergleichspreis !== null)
                                        {{ number_format($item->vergleichspreis['value'], 2, ',', '.') }}&nbsp;<span class="text-[var(--fa-ink-3)]">{{ $item->vergleichspreis['unit'] }}</span>
                                    @else
                                        –
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if($item->structure?->gp)
                                        <a href="{{ \Platform\FoodAlchemist\Support\Sprungziel::gp($item->structure->gp_id) }}"
                                           class="text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:underline">{{ $item->structure->gp->name }}</a>
                                    @else
                                        <x-fa::badge tone="warn" title="Artikel öffnen und ein Grundprodukt zuordnen">Nicht zugeordnet</x-fa::badge>
                                    @endif
                                </td>
                                {{-- R12: Stern = diesen Artikel als bevorzugten Artikel seines Grundprodukts setzen --}}
                                <td class="text-right">
                                    @if($item->structure?->gp)
                                        @php
                                            $istLead = (int) $item->structure->gp->lead_la_supplier_item_id === (int) $item->id;
                                        @endphp
                                        <button type="button" wire:click="leadSetzen({{ $item->id }})"
                                                class="inline-flex items-center justify-center w-7 h-7 rounded-[var(--fa-radius-control)] transition-colors hover:bg-[var(--fa-hover)] {{ $istLead ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]' }}"
                                                aria-pressed="{{ $istLead ? 'true' : 'false' }}"
                                                aria-label="{{ $istLead ? 'Bevorzugter Artikel für ' . $item->structure->gp->name : 'Als bevorzugten Artikel für ' . $item->structure->gp->name . ' setzen' }}"
                                                title="{{ $istLead ? 'Bevorzugter Artikel dieses Grundprodukts, rechnet in Rezepten' : 'Als bevorzugten Artikel setzen: rechnet dann in Rezepten' }}" data-lead-stern-btn>
                                            @svg($istLead ? 'heroicon-s-star' : 'heroicon-o-star', 'w-4 h-4')
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $spaltenAnzahl }}">
                                    @if($globaleSuche)
                                        <x-fa::empty icon="heroicon-o-magnifying-glass" title="Kein Artikel gefunden">Anderen Suchbegriff versuchen oder „Nur lieferbare Artikel“ abschalten.</x-fa::empty>
                                    @elseif($aktiverLieferant)
                                        <x-fa::empty icon="heroicon-o-cube" title="Keine Artikel">
                                            Mit „Artikel anlegen“ erfasst du den ersten Artikel. Ausgelistete Artikel siehst du, wenn du „Nur lieferbare Artikel“ abschaltest.
                                        </x-fa::empty>
                                    @else
                                        <x-fa::empty icon="heroicon-o-truck" title="Noch kein Lieferant">Mit „Lieferant anlegen“ links legst du den ersten Lieferanten an.</x-fa::empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($artikel)
                <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $artikel->links('foodalchemist::components.fa.pagination') }}</div>
            @endif
        </div>

        {{-- LA-Editor-Modal (M2-06/07/08) — innerhalb x-ui-page (Template-Regel) --}}
        <livewire:foodalchemist.suppliers.item-modal key="suppliers-index--suppliers.item-modal" />

        {{-- LA-first: Ziel des Events „gp-modal.oeffnen" aus dem Artikel-Modal. Ohne diese
             Komponente hatte der sichtbare Button keinen Listener und reagierte nicht. --}}
        <livewire:foodalchemist.gps.gp-modal key="suppliers-index--gps.gp-modal" />

        {{-- R9.1/R9.2: Lieferanten-Stammblatt-Modal (Beziehungs-Ebene) --}}
        <livewire:foodalchemist.suppliers.supplier-detail key="suppliers-index--suppliers.supplier-detail" />

        {{-- Neuer Lieferant (gehört dem anlegenden Team — D1) --}}
        <x-foodalchemist::modal name="lieferant-neu" title="Lieferant anlegen" size="max-w-2xl">
            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            <x-fa::section title="Stammdaten" description="Der Lieferant gehört deinem Team. Artikel legst du danach über „Artikel anlegen“ an.">
                <div class="grid grid-cols-2 gap-3">
                    <x-fa::field label="Name" for="neu-lieferant-name" required class="col-span-2">
                        <x-fa::input id="neu-lieferant-name" wire:model="neuLieferant.name" wire:keydown.enter="lieferantAnlegen" data-neu-lieferant-name />
                    </x-fa::field>
                    <x-fa::field label="Branche" for="neu-lieferant-branche" optional>
                        <x-fa::input id="neu-lieferant-branche" wire:model="neuLieferant.branch" />
                    </x-fa::field>
                    <x-fa::field label="GLN" for="neu-lieferant-gln" optional hint="Globale Lokationsnummer, 13 Ziffern">
                        <x-fa::input id="neu-lieferant-gln" wire:model="neuLieferant.gln" />
                    </x-fa::field>
                    <x-fa::field label="PLZ" for="neu-lieferant-plz" optional>
                        <x-fa::input id="neu-lieferant-plz" wire:model="neuLieferant.postal_code" />
                    </x-fa::field>
                    <x-fa::field label="Ort" for="neu-lieferant-ort" optional>
                        <x-fa::input id="neu-lieferant-ort" wire:model="neuLieferant.city" />
                    </x-fa::field>
                    <x-fa::field label="Straße" for="neu-lieferant-strasse" optional class="col-span-2">
                        <x-fa::input id="neu-lieferant-strasse" wire:model="neuLieferant.address" />
                    </x-fa::field>
                    <x-fa::field label="Bestell-E-Mail" for="neu-lieferant-mail" optional>
                        <x-fa::input id="neu-lieferant-mail" wire:model="neuLieferant.email_order" />
                    </x-fa::field>
                    <x-fa::field label="Homepage" for="neu-lieferant-web" optional>
                        <x-fa::input id="neu-lieferant-web" wire:model="neuLieferant.homepage" />
                    </x-fa::field>
                </div>
            </x-fa::section>
            <x-slot:footer>
                <x-fa::button variant="ghost" x-on:click="$dispatch('modal.close', { name: 'lieferant-neu' })">Abbrechen</x-fa::button>
                <x-fa::button variant="primary" wire:click="lieferantAnlegen">Lieferant anlegen</x-fa::button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- M2-11: Neuer Artikel (Minimal-Pflichtfelder, gehört dem anlegenden Team — D1) --}}
        <x-foodalchemist::modal name="artikel-neu" title="Artikel anlegen" size="max-w-2xl">
            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            <x-fa::section title="Stammdaten" :description="'Wird für ' . ($aktiverLieferant?->name ?? 'den gewählten Lieferanten') . ' angelegt und gehört deinem Team. Danach öffnet sich der Artikel zum Ergänzen.'">
                <div class="grid grid-cols-2 gap-3">
                    <x-fa::field label="Bezeichnung" for="neu-artikel-bezeichnung" required class="col-span-2">
                        <x-fa::input id="neu-artikel-bezeichnung" wire:model="neuArtikel.designation" wire:keydown.enter="artikelAnlegen" data-neu-label />
                    </x-fa::field>
                    <x-fa::field label="Artikel-Nr." for="neu-artikel-nr" optional>
                        <x-fa::input id="neu-artikel-nr" wire:model="neuArtikel.article_number" />
                    </x-fa::field>
                    <div class="grid grid-cols-2 gap-2">
                        <x-fa::field label="Gebindemenge" for="neu-artikel-menge" hint="z. B. 2,5">
                            <x-fa::input id="neu-artikel-menge" numeric wire:model="neuArtikel.qty" />
                        </x-fa::field>
                        <x-fa::field label="Einheit" for="neu-artikel-einheit">
                            <x-fa::select id="neu-artikel-einheit" wire:model="neuArtikel.unit_code">
                                <option value="">–</option>
                                @foreach(['kg', 'l', 'Stk'] as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                    </div>
                </div>
            </x-fa::section>
            <x-slot:footer>
                <x-fa::button variant="ghost" x-on:click="$dispatch('modal.close', { name: 'artikel-neu' })">Abbrechen</x-fa::button>
                <x-fa::button variant="primary" wire:click="artikelAnlegen">Artikel anlegen</x-fa::button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- M2-12: Preis-Anomalien --}}
        <x-foodalchemist::modal name="preis-anomalien" title="Preisausreißer" size="max-w-5xl">
            @if($anomalien === null)
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Wird berechnet …</p>
            @else
                <x-fa::section title="Vergleichspreis weit weg vom Üblichen" icon="heroicon-o-scale"
                    description="Artikel, deren Preis je kg, l oder Stück mindestens das Vierfache oder ein Viertel des mittleren Preises (Median) ihrer Warengruppe beträgt. Häufige Ursache: falsche Gebindemenge.">
                    @if(count($anomalien['ausreisser']) > 0)
                        <div class="-mx-4 -mb-4 border-t border-[var(--fa-line)] overflow-x-auto">
                            <table class="fa-table fa-table--compact" data-ausreisser>
                                <thead>
                                    <tr>
                                        <th class="w-full">Artikel</th>
                                        <th>Lieferant</th>
                                        <th>Warengruppe</th>
                                        <th class="num">Vergleichspreis</th>
                                        <th class="num">Median der Gruppe</th>
                                        <th class="num">Faktor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($anomalien['ausreisser'] as $a)
                                        <tr>
                                            <td class="text-[var(--fa-ink)]">{{ $a['label'] }}</td>
                                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $a['lieferant'] }}</td>
                                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $a['wg'] }}</td>
                                            <td class="num">{{ number_format($a['value'], 2, ',', '.') }}&nbsp;<span class="text-[var(--fa-ink-3)]">{{ $a['unit'] }}</span></td>
                                            <td class="num text-[var(--fa-ink-2)]">{{ number_format($a['median'], 2, ',', '.') }}&nbsp;<span class="text-[var(--fa-ink-3)]">{{ $a['unit'] }}</span></td>
                                            <td class="num"><x-fa::badge tone="warn">{{ number_format((float) $a['faktor'], 1, ',', '.') }}-fach</x-fa::badge></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine Ausreißer">Alle Vergleichspreise liegen im üblichen Rahmen ihrer Warengruppe.</x-fa::empty>
                    @endif
                </x-fa::section>
                <x-fa::section title="Preissprünge über 30 %" icon="heroicon-o-arrow-trending-up" description="Vergleicht den aktuellen mit dem vorherigen Preis desselben Artikels.">
                    @forelse($anomalien['spruenge'] as $sp)
                        <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                            <span class="text-[var(--fa-ink-2)]">Artikel {{ $sp->supplier_item_id }}:</span>
                            <x-fa::money :value="$sp->von" /> @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]') <x-fa::money :value="$sp->nach" />
                            <x-fa::badge tone="warn">{{ $sp->sprung_pct }} %</x-fa::badge>
                        </p>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-clock" title="Keine Preissprünge">Bisher liegt je Artikel meist nur ein Preis vor. Mit jeder neuen Preisliste wächst der Vergleich.</x-fa::empty>
                    @endforelse
                </x-fa::section>
            @endif
        </x-foodalchemist::modal>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
