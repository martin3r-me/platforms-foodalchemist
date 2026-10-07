{{-- Bestellungen-Editor (Werkbank-Modal, Vollbild). Zwei Gesichter:
     · Bestellrunde (kein Beleg geladen): Quellen sammeln → Vorschau je Lieferant und Liefertag → speichern.
     · Bestellung (ein Lieferanten-Beleg): Positionen · Hinzufügen · Wareneingang · Rechnung · Bestellkopf.
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> und --fa-*-Tokens umgestellt (hell + Werkbank).
     Kopf: GENAU EINE Hauptaktion rechts (Bestellung: nächster Statusschritt, Runde: Speichern), Belege und
     Storno im Menü „Weitere Aktionen" (Storno ganz unten, rot). Der Start-Reiter folgt dem Arbeitsschritt:
     Entwurf → Positionen, versendet/bestätigt → Wareneingang, geliefert → Rechnung.
     Funktion, wire:-Bindungen, Event-Namen und data-Marker unverändert. --}}
@php
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'success' => 'ok', 'danger' => 'crit', 'warning' => 'warn', 'primary' => 'accent'];
    $menge = fn ($wert, $stellen = 2) => rtrim(rtrim(number_format((float) $wert, $stellen, ',', '.'), '0'), ',');
    $euro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
    $datum = fn ($wert) => $wert ? \Carbon\Carbon::parse($wert)->format('d.m.Y') : null;
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $mittel = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menueAus = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)] cursor-not-allowed';
    $trefferListe = 'mt-1 fa-surface divide-y divide-[var(--fa-line)] max-h-60 overflow-y-auto';
    $trefferKnopf = 'flex w-full flex-col items-start px-3 py-2 text-left hover:bg-[var(--fa-hover)]';
    $wechselKnopf = 'mt-1 inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:underline';
    // Statusknöpfe brauchen data-status-<wert> als Attributnamen; das geht an x-fa::button nicht → gleiche Klassen literal.
    $knopfBasis = 'fa-btn inline-flex items-center justify-center gap-1.5 whitespace-nowrap font-medium rounded-[var(--fa-radius-control)] transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none h-9 px-3.5 text-[length:var(--fa-text-md)]';
    $knopfPrimaer = $knopfBasis . ' bg-[var(--fa-accent)] text-[var(--fa-on-accent)] hover:bg-[var(--fa-accent-hover)]';
    $knopfSekundaer = $knopfBasis . ' bg-[var(--fa-surface)] text-[var(--fa-ink)] border border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)]';
    $quellenTyp = ['supplier_item' => 'Artikel', 'gp' => 'Grundprodukt', 'recipe' => 'Rezept', 'production' => 'Produktion'];
    $quellenTon = ['production' => 'accent', 'recipe' => 'info'];
    $herkunftTon = ['produktion' => 'accent', 'concept' => 'info'];
    $strategieLabel = fn ($wert) => $wert ? (\Platform\FoodAlchemist\Enums\LeadLaStrategie::tryFrom($wert)?->label() ?? $wert) : 'Team-Standard';

    $istRunde = $detail === null;
    // Spec 65: Beleg ohne eigene Sperre = Lesemodus (Reiter-Eingaben aus, Status/Storno/Mail erst nach „Bearbeiten").
    $sperrLesen = ! $istRunde && in_array($sperr['modus'], ['lesen', 'fremd'], true);
    $sendBlockers = $detail['send_blockers'] ?? [];

    // Statusschritte: der erste Folgeschritt ist die Hauptaktion, weitere stehen daneben, Storno ins Menü.
    $hauptStatus = null;
    $nebenStatus = [];
    $stornoStatus = null;
    foreach ($erlaubteStatus as $z) {
        if ($z === \Platform\FoodAlchemist\Enums\OrderStatus::Cancelled) {
            $stornoStatus = $z;
        } elseif ($hauptStatus === null) {
            $hauptStatus = $z;
        } else {
            $nebenStatus[] = $z;
        }
    }
    $statusKnopf = fn ($z) => match ($z) {
        \Platform\FoodAlchemist\Enums\OrderStatus::Sent => $serverVersand ? 'Absenden per E-Mail' : 'Absenden',
        \Platform\FoodAlchemist\Enums\OrderStatus::Confirmed => 'Als bestätigt markieren',
        \Platform\FoodAlchemist\Enums\OrderStatus::Delivered => 'Als geliefert markieren',
        \Platform\FoodAlchemist\Enums\OrderStatus::Cancelled => ($detail['status'] ?? 'draft') === 'draft' ? 'Stornieren' : ($serverVersand ? 'Stornieren und Lieferant per E-Mail informieren' : 'Storno bestätigt'),
        default => ucfirst($z->label()),
    };

    if (! $istRunde) {
        $statusEnum = \Platform\FoodAlchemist\Enums\OrderStatus::from($detail['status']);
        $istEntwurf = $detail['status'] === 'draft';
        $startTab = match ($detail['status']) {
            'sent', 'confirmed' => 'wareneingang',
            'delivered' => 'rechnung',
            default => 'positionen',
        };
        $reiter = $istEntwurf
            ? ['positionen' => 'Positionen', 'hinzufuegen' => $detail['editierbar'] ? 'Hinzufügen' : null, 'kopf' => 'Bestellkopf', 'wareneingang' => 'Wareneingang', 'rechnung' => 'Rechnung']
            : ['positionen' => 'Positionen', 'wareneingang' => 'Wareneingang', 'rechnung' => 'Rechnung', 'hinzufuegen' => $detail['editierbar'] ? 'Hinzufügen' : null, 'kopf' => 'Bestellkopf'];

        $moq = $detail['moq'];
        $warnings = $detail['warnings'] ?? [];
        $receiptKpi = $detail['receipt'] ?? [];
        $invoiceKpi = $detail['invoice'] ?? [];
        $lagerKpi = $detail['inventory'] ?? [];
        $kennzahlen = [
            ['kpi' => 'netto', 'label' => 'Wareneinsatz netto', 'primary' => true, 'value' => $euro($detail['total_net'])],
            ['kpi' => 'artikel', 'label' => 'Positionen', 'value' => (string) count($detail['zeilen']), 'tone' => count($detail['zeilen']) === 0 ? 'warn' : null],
            ['kpi' => 'moq', 'label' => 'Mindestbestellwert',
                'tone' => $moq['unter_mindestbestellwert'] ? 'warn' : ($moq['min_order_value'] !== null ? 'ok' : null),
                'value' => $moq['unter_mindestbestellwert'] ? 'fehlen ' . $euro($moq['fehlt_bis_min']) : ($moq['min_order_value'] !== null ? 'erreicht' : 'keiner')],
            ['kpi' => 'hinweise', 'label' => 'Hinweise',
                'tone' => ! empty($sendBlockers) ? 'crit' : (! empty($warnings) ? 'warn' : 'ok'),
                'value' => count($warnings) > 0 ? (string) count($warnings) : 'keine'],
            ['kpi' => 'wareneingang', 'label' => 'Wareneingang',
                'tone' => $istEntwurf ? null : ((($receiptKpi['differences'] ?? 0) > 0) ? 'warn' : ((($receiptKpi['missing'] ?? 0) > 0) ? null : 'ok')),
                'value' => $istEntwurf ? 'nach Versand' : (($receiptKpi['booked'] ?? 0) . ' von ' . ($receiptKpi['lines'] ?? 0))],
            ['kpi' => 'rechnung', 'label' => 'Rechnung',
                'tone' => $istEntwurf ? null : ((($invoiceKpi['differences'] ?? 0) > 0) ? 'warn' : ((($invoiceKpi['missing'] ?? 0) > 0) ? null : 'ok')),
                'value' => $istEntwurf ? 'nach Versand' : (($invoiceKpi['checked'] ?? 0) . ' von ' . ($invoiceKpi['lines'] ?? 0))],
            ['kpi' => 'lager', 'label' => 'Lager',
                'tone' => ($lagerKpi['shortage'] ?? 0) > 0 ? 'warn' : ((($lagerKpi['tracked'] ?? 0) > 0) ? 'ok' : null),
                'value' => ($lagerKpi['tracked'] ?? 0) > 0 ? (($lagerKpi['covered'] ?? 0) . ' von ' . ($lagerKpi['tracked'] ?? 0) . ' gedeckt') : 'nicht geführt'],
        ];
    } else {
        $previewTotals = $cockpitPreview['totals'] ?? ['sources' => count($cockpitSources), 'groups' => 0, 'positions' => 0, 'unresolved' => 0, 'total_net' => 0];
        $kennzahlen = [
            ['kpi' => 'netto', 'label' => 'Netto laut Vorschau', 'primary' => true, 'value' => $euro($previewTotals['total_net'] ?? 0)],
            ['kpi' => 'sources', 'label' => 'Quellen', 'value' => number_format((int) ($previewTotals['sources'] ?? 0), 0, ',', '.')],
            ['kpi' => 'tracks', 'label' => 'Bestellungen', 'value' => number_format((int) ($previewTotals['groups'] ?? 0), 0, ',', '.'), 'title' => 'Je Lieferant und Liefertag eine Bestellung'],
            ['kpi' => 'positions', 'label' => 'Positionen', 'value' => number_format((int) ($previewTotals['positions'] ?? 0), 0, ',', '.')],
            ['kpi' => 'clarifications', 'label' => 'Klärpunkte', 'tone' => ((int) ($previewTotals['unresolved'] ?? 0)) > 0 ? 'warn' : 'ok', 'value' => number_format((int) ($previewTotals['unresolved'] ?? 0), 0, ',', '.')],
        ];
    }
@endphp

<x-foodalchemist::modal name="orders-editor" fullscreen dark-canvas :title="$istRunde ? 'Bestellrunde' : 'Bestellung'"
    :title-name="$istRunde ? ($roundDetail['label'] ?? 'Neu') : ($detail['supplier'] ?? null)">
    @if(! $istRunde)
        <x-slot:titleExtra>
            <x-fa::badge :tone="$statusTon[$statusEnum->badgeVariant()] ?? 'neutral'" data-kpi="status">{{ ucfirst($detail['status_label']) }}</x-fa::badge>
            <span class="text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)] tabular-nums">ord-{{ $detail['id'] }}@if($detail['desired_delivery_date']) · Liefertag {{ $datum($detail['desired_delivery_date']) }}@endif</span>
        </x-slot:titleExtra>
    @endif

    <x-slot:actions>
        <div class="flex w-full flex-wrap items-center gap-2">
            @if($hinweis)<x-fa::signal tone="ok" data-orders-hinweis>{{ $hinweis }}</x-fa::signal>@endif
            @if($fehler)<x-fa::signal tone="crit" data-orders-fehler>{{ $fehler }}</x-fa::signal>@endif

            <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                @if($istRunde)
                    <x-fa::button icon="heroicon-m-arrow-path" wire:click="cockpitVorschau" data-orders-cockpit-preview>Vorschau berechnen</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="cockpitSpeichern" :disabled="count($cockpitSources) === 0" data-orders-cockpit-save>Bestellungen speichern</x-fa::button>
                @else
                    {{-- Spec 65: Beleg-Aktionen schreiben sofort — „Bearbeiten" sperrt die Schiene, „Fertig" gibt frei --}}
                    <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" sofort />
                    {{-- Weitere Aktionen: Belege, E-Mails, Storno (ganz unten, rot) --}}
                    <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::button variant="ghost" icon="heroicon-m-ellipsis-horizontal" iconRight="heroicon-m-chevron-down" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen">Weitere Aktionen</x-fa::button>
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 fa-surface shadow-lg py-1">
                            <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id']]) }}" target="_blank" role="menuitem" class="{{ $menuePunkt }}" title="Bestelldokument im neuen Fenster">
                                @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Bestellung drucken
                            </a>
                            <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'pdf' => 1]) }}" role="menuitem" class="{{ $menuePunkt }}">
                                @svg('heroicon-o-arrow-down-tray', 'w-4 h-4 text-[var(--fa-ink-3)]') Als PDF herunterladen
                            </a>
                            <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'csv' => 1]) }}" role="menuitem" class="{{ $menuePunkt }}">
                                @svg('heroicon-o-table-cells', 'w-4 h-4 text-[var(--fa-ink-3)]') Als CSV herunterladen
                            </a>
                            <div class="my-1 border-t border-[var(--fa-line)]"></div>
                            @if($serverVersand)
                                <span role="menuitem" aria-disabled="true" class="{{ $menueAus }}" title="Versandart „Direkt per E-Mail“ (Einstellungen → Einkauf)">
                                    @svg('heroicon-o-envelope', 'w-4 h-4') {{ $mailto ? 'Geht beim Absenden direkt per E-Mail raus' : 'E-Mail fehlt beim Lieferanten' }}
                                </span>
                            @elseif($mailto)
                                <a href="{{ $mailto }}" role="menuitem" class="{{ $menuePunkt }}" title="Bestellung als E-Mail an den Lieferanten vorbereiten">
                                    @svg('heroicon-o-envelope', 'w-4 h-4 text-[var(--fa-ink-3)]') E-Mail an Lieferant vorbereiten
                                </a>
                            @else
                                <span role="menuitem" aria-disabled="true" class="{{ $menueAus }}" title="Keine Bestell-E-Mail beim Lieferanten hinterlegt">
                                    @svg('heroicon-o-envelope', 'w-4 h-4') E-Mail fehlt beim Lieferanten
                                </span>
                            @endif
                            @if(! $serverVersand && in_array($detail['status'], ['sent', 'confirmed'], true))
                                @if($cancellationMailto)
                                    <a href="{{ $cancellationMailto }}" role="menuitem" class="{{ $menuePunkt }} text-[var(--fa-crit)]" title="Storno-Mail an den Lieferanten vorbereiten" data-order-cancellation-mail>
                                        @svg('heroicon-o-envelope', 'w-4 h-4') Storno an Lieferant
                                    </a>
                                @else
                                    <span role="menuitem" aria-disabled="true" class="{{ $menueAus }}" title="Beim Lieferanten fehlt die Bestell-E-Mail" data-order-cancellation-mail-missing>
                                        @svg('heroicon-o-envelope', 'w-4 h-4') Storno an Lieferant
                                    </span>
                                @endif
                            @endif
                            @if($stornoStatus && ! $sperrLesen)
                                <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                <button type="button" role="menuitem" x-on:click="offen = false" wire:click="setStatus('{{ $stornoStatus->value }}')" wire:confirm="Bestellung stornieren?"
                                    class="{{ $menuePunkt }} text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" data-status-{{ $stornoStatus->value }}>
                                    @svg('heroicon-o-x-circle', 'w-4 h-4') {{ $statusKnopf($stornoStatus) }}
                                </button>
                            @endif
                        </div>
                    </div>

                    @unless($sperrLesen)
                    @foreach($nebenStatus as $z)
                        <button type="button" wire:click="setStatus('{{ $z->value }}')" class="{{ $knopfSekundaer }}" data-status-{{ $z->value }}>{{ $statusKnopf($z) }}</button>
                    @endforeach
                    @if($hauptStatus)
                        @php
                            $versandGesperrt = $hauptStatus->value === 'sent' && ! empty($sendBlockers);
                        @endphp
                        @if($versandGesperrt)
                            <x-fa::signal tone="crit" title="{{ implode(', ', $sendBlockers) }}">Versand gesperrt</x-fa::signal>
                        @endif
                        <button type="button" wire:click="setStatus('{{ $hauptStatus->value }}')" class="{{ $knopfPrimaer }}"
                            @if($serverVersand && $hauptStatus->value === 'sent') wire:confirm="Bestellung jetzt per E-Mail an den Lieferanten senden?" @endif
                            @disabled($versandGesperrt)
                            @if($versandGesperrt) title="Versand gesperrt: {{ implode(', ', $sendBlockers) }}" @endif
                            data-status-{{ $hauptStatus->value }}>@svg($hauptStatus->value === 'sent' ? 'heroicon-m-paper-airplane' : 'heroicon-m-check', 'w-4 h-4 shrink-0'){{ $statusKnopf($hauptStatus) }}</button>
                    @endif
                    @endunless
                @endif
            </div>
        </div>
    </x-slot:actions>

    <x-slot:kpiHeader>
        @if($istRunde)
            <x-fa::kpis data-orders-cockpit-kpis :items="$kennzahlen" />
        @else
            <x-fa::kpis data-orders-kpis :items="$kennzahlen" />
        @endif
    </x-slot:kpiHeader>

    {{-- Spec 63: Mail-Protokoll der Bestellung (nur bei Versandart „Direkt per E-Mail") --}}
    @if(! $istRunde && $mailProtokoll->isNotEmpty())
        <div class="mb-4 flex flex-col gap-1.5" data-order-mail-protokoll>
            @foreach($mailProtokoll as $pm)
                @php
                    $ton = ['versendet' => 'ok', 'fehlgeschlagen' => 'crit'][$pm->status] ?? 'info';
                @endphp
                <x-fa::notice :tone="$ton">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium">{{ $pm->typ === 'storno' ? 'Storno-Mail' : 'Bestell-Mail' }}</span>
                        <span>an {{ $pm->an }}</span>
                        <span class="text-[var(--fa-ink-3)]">
                            @if($pm->status === 'versendet') versendet {{ $pm->versendet_am?->format('d.m.Y H:i') }}
                            @elseif($pm->status === 'fehlgeschlagen') fehlgeschlagen ({{ $pm->versuche }}×): {{ \Illuminate\Support\Str::limit($pm->fehler, 160) }}
                            @else wird gesendet …
                            @endif
                        </span>
                        @if($pm->status === 'fehlgeschlagen' && ! $sperrLesen)
                            <x-fa::button size="sm" icon="heroicon-m-arrow-path" wire:click="mailErneutSenden({{ $pm->id }})" class="ml-auto">Erneut senden</x-fa::button>
                        @endif
                    </div>
                </x-fa::notice>
            @endforeach
        </div>
    @endif

    @if($istRunde)
        {{-- ═══ BESTELLRUNDE: Rahmen · Quellen · Vorschau · Klärliste ═══ --}}
        <div class="flex flex-col gap-4">
            <x-fa::section :title="$roundDetail ? $roundDetail['label'] : 'Neue Bestellrunde'" icon="heroicon-o-calendar-days"
                description="Liefertag und Strategie gelten für alle Quellen, sofern eine Quelle nichts anderes vorgibt.">
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
                    <x-fa::field label="Standard-Liefertag" for="orders-runde-liefertag">
                        <x-fa::input id="orders-runde-liefertag" type="date" wire:model.live="formDeliveryDate" />
                    </x-fa::field>
                    <x-fa::field label="Einkaufsstrategie" for="orders-runde-strategie">
                        <x-fa::select id="orders-runde-strategie" wire:model.live="cockpitStrategy">
                            <option value="">Team-Standard</option>
                            @foreach($strategieOptionen as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Anlass" for="orders-runde-anlass" class="lg:col-span-2">
                        <x-fa::input id="orders-runde-anlass" wire:model.live="formReference" placeholder="z. B. Wochenbestellung, Bankett, Produktion" />
                    </x-fa::field>
                </div>
                @if($roundDetail)
                    <div class="pt-3 border-t border-[var(--fa-line)] flex flex-wrap items-baseline gap-x-4 gap-y-1 {{ $mittel }} tabular-nums" data-orders-round="{{ $roundDetail['id'] }}">
                        <span>{{ $roundDetail['supplier_count'] }} Lieferanten</span>
                        <span>{{ $roundDetail['order_count'] }} Bestellungen</span>
                        <span>{{ $roundDetail['position_count'] }} Positionen</span>
                        <x-fa::money :value="$roundDetail['total_net']" class="font-semibold text-[var(--fa-ink)]" />
                    </div>
                @endif
            </x-fa::section>

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(280px,0.9fr)_minmax(0,1.25fr)] 2xl:grid-cols-[minmax(280px,0.85fr)_minmax(0,1.25fr)_minmax(260px,0.7fr)] gap-4 min-w-0">
                <div class="flex flex-col gap-4 min-w-0">
                    {{-- Quellenart wählen, dann suchen. Alle Suchfelder bleiben im DOM (Livewire-Bindungen). --}}
                    <x-fa::section title="Quellen einfügen" icon="heroicon-o-plus-circle">
                        <div x-data="{ quelle: 'artikel' }" class="flex flex-col gap-3">
                            <div role="group" aria-label="Quellenart" class="grid grid-cols-2 sm:grid-cols-4 p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                                @foreach(['artikel' => 'Artikel', 'gp' => 'Grundprodukt', 'rezept' => 'Rezept', 'produktion' => 'Produktion'] as $qk => $ql)
                                    <button type="button" x-on:click="quelle = '{{ $qk }}'" x-bind:aria-pressed="quelle === '{{ $qk }}'"
                                        x-bind:class="quelle === '{{ $qk }}' ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]'"
                                        class="h-7 px-2 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors truncate">{{ $ql }}</button>
                                @endforeach
                            </div>

                            <div x-show="quelle === 'artikel'">
                                <x-fa::field label="Lieferantenartikel" for="orders-cockpit-artikel">
                                    <x-fa::input id="orders-cockpit-artikel" type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Lieferant, Grundprodukt, Artikel oder Artikelnummer" data-orders-artikel-suche />
                                </x-fa::field>
                                @if($artikelTreffer->isNotEmpty())
                                    <div class="{{ $trefferListe }}">
                                        @foreach($artikelTreffer as $a)
                                            <button type="button" wire:click="cockpitArtikelEinfuegen({{ $a['id'] }})" wire:key="cockpit-art-{{ $a['id'] }}" class="{{ $trefferKnopf }}">
                                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $a['designation'] ?: '–' }}</span>
                                                <span class="{{ $leise }}">{{ $a['supplier'] }}@if($a['gp']) · {{ $a['gp'] }}@endif @if($a['article_number']) · Art. {{ $a['article_number'] }}@endif</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div x-show="quelle === 'gp'" x-cloak>
                                <x-fa::field label="Grundprodukt" for="orders-cockpit-gp">
                                    <x-fa::input id="orders-cockpit-gp" type="search" wire:model.live.debounce.300ms="gpSuche" placeholder="Grundprodukt suchen" data-orders-gp-suche />
                                </x-fa::field>
                                @if($gpTreffer->isNotEmpty())
                                    <div class="{{ $trefferListe }}">
                                        @foreach($gpTreffer as $gp)
                                            <button type="button" wire:click="cockpitGpEinfuegen({{ $gp['id'] }})" wire:key="cockpit-gp-{{ $gp['id'] }}" class="{{ $trefferKnopf }}">
                                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $gp['name'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div x-show="quelle === 'rezept'" x-cloak>
                                <x-fa::field label="Gericht oder Basisrezept" for="orders-cockpit-rezept">
                                    <x-fa::input id="orders-cockpit-rezept" type="search" wire:model.live.debounce.300ms="bedarfSuche" placeholder="Gericht oder Basisrezept suchen" data-orders-bedarf-suche />
                                </x-fa::field>
                                @if($bedarfTreffer->isNotEmpty())
                                    <div class="{{ $trefferListe }}">
                                        @foreach($bedarfTreffer as $r)
                                            <button type="button" wire:click="cockpitRezeptEinfuegen({{ $r['id'] }})" wire:key="cockpit-recipe-{{ $r['id'] }}" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-[var(--fa-hover)]">
                                                <span class="min-w-0 truncate text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $r['name'] }}</span>
                                                <x-fa::badge :tone="$r['is_sales_recipe'] ? 'info' : 'neutral'" class="shrink-0">{{ $r['is_sales_recipe'] ? 'Gericht' : 'Basisrezept' }}</x-fa::badge>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div x-show="quelle === 'produktion'" x-cloak>
                                <x-fa::field label="Freigegebene Produktion" for="orders-cockpit-produktion">
                                    <x-fa::input id="orders-cockpit-produktion" type="search" wire:model.live.debounce.300ms="produktionSuche" placeholder="Produktionsauftrag suchen" data-orders-produktion-suche />
                                </x-fa::field>
                                @if($produktionTreffer->isNotEmpty())
                                    <div class="{{ $trefferListe }}">
                                        @foreach($produktionTreffer as $p)
                                            <button type="button" wire:click="cockpitProduktionEinfuegen({{ $p['id'] }})" wire:key="cockpit-prod-{{ $p['id'] }}" class="{{ $trefferKnopf }}">
                                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $p['name'] }}</span>
                                                @if($p['date'])<span class="{{ $leise }} tabular-nums">{{ $p['date'] }}</span>@endif
                                            </button>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="mt-1 {{ $leise }}">Keine freigegebene Produktion offen.</p>
                                @endif
                            </div>
                        </div>
                    </x-fa::section>

                    <x-fa::section title="Arbeitsstand" icon="heroicon-o-queue-list" :meta="count($cockpitSources)">
                        <div class="flex flex-col gap-2">
                            @forelse($cockpitSources as $i => $s)
                                <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-2.5 flex flex-col gap-2" wire:key="cockpit-source-{{ $s['uid'] }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex flex-col items-start gap-1">
                                            <x-fa::badge :tone="$quellenTon[$s['type']] ?? 'neutral'">{{ $quellenTyp[$s['type']] ?? ucfirst((string) $s['type']) }}</x-fa::badge>
                                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] truncate max-w-full">{{ $s['label'] ?? ($s['id'] ?? 'Quelle') }}</p>
                                        </div>
                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Quelle entfernen" wire:click="cockpitQuelleEntfernen('{{ $s['uid'] }}')" />
                                    </div>
                                    <div class="grid grid-cols-[minmax(0,80px)_minmax(0,110px)_minmax(0,1fr)] gap-1.5">
                                        <x-fa::input size="sm" numeric type="number" min="0" step="0.1" wire:model.live="cockpitSources.{{ $i }}.qty" aria-label="Menge" />
                                        <x-fa::select size="sm" wire:model.live="cockpitSources.{{ $i }}.unit" aria-label="Einheit">
                                            @if($s['type'] === 'supplier_item')
                                                <option value="gebinde">Gebinde</option>
                                            @elseif($s['type'] === 'gp')
                                                <option value="kg">kg</option>
                                                <option value="g">g</option>
                                                <option value="stk">Stk</option>
                                            @elseif($s['type'] === 'production')
                                                <option value="auftrag">Auftrag</option>
                                            @else
                                                <option value="portions">Portionen</option>
                                                <option value="ansaetze">Ansätze</option>
                                                <option value="kg">kg</option>
                                            @endif
                                        </x-fa::select>
                                        <x-fa::input size="sm" type="date" wire:model.live="cockpitSources.{{ $i }}.delivery_date" aria-label="Liefertag" />
                                    </div>
                                    <x-fa::input size="sm" wire:model.live="cockpitSources.{{ $i }}.reference" placeholder="Anlass für diese Quelle" aria-label="Anlass für diese Quelle" />
                                </div>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-inbox" title="Noch keine Quelle eingefügt">Oben Artikel, Grundprodukt, Rezept oder Produktion suchen und anklicken.</x-fa::empty>
                            @endforelse
                        </div>
                    </x-fa::section>
                </div>

                <x-fa::section title="Auflösung nach Lieferant + Liefertag" icon="heroicon-o-truck" class="min-w-0">
                    @if($cockpitPreview === null)
                        <x-fa::empty compact icon="heroicon-o-arrow-path" title="Noch keine Vorschau">Quellen einfügen und oben „Vorschau berechnen" wählen.</x-fa::empty>
                    @elseif(empty($cockpitPreview['orders_preview']))
                        <x-fa::empty compact icon="heroicon-o-truck" title="Keine bestellbare Position">Die Quellen ergeben keinen Artikel mit Lieferant. Klärliste prüfen.</x-fa::empty>
                    @else
                        <div class="flex flex-col gap-3">
                            @foreach($cockpitPreview['orders_preview'] as $g)
                                <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] overflow-hidden" wire:key="cockpit-preview-{{ $g['supplier_id'] }}-{{ $g['delivery_date'] ?? 'none' }}">
                                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-[var(--fa-ground)]">
                                        <div class="min-w-0">
                                            <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">{{ $g['supplier'] }}</p>
                                            <p class="{{ $leise }} tabular-nums">Liefertag {{ $datum($g['delivery_date']) ?? 'offen' }}</p>
                                        </div>
                                        <div class="flex flex-col items-end gap-0.5">
                                            <x-fa::money :value="$g['total_net']" class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]" />
                                            @if($g['moq']['unter_mindestbestellwert'])
                                                <x-fa::signal tone="warn">{{ $euro($g['moq']['fehlt_bis_min']) }} bis Mindestwert</x-fa::signal>
                                            @elseif($g['moq']['frei_haus'])
                                                <x-fa::signal tone="ok">frei Haus</x-fa::signal>
                                            @endif
                                        </div>
                                    </div>
                                    @if(!empty($g['warnings']))
                                        <div class="flex flex-wrap gap-1 px-3 py-1.5 border-t border-[var(--fa-line)] bg-[var(--fa-warn-soft)]">
                                            @foreach($g['warnings'] as $w)
                                                <x-fa::badge tone="warn">{{ $w }}</x-fa::badge>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="divide-y divide-[var(--fa-line)] border-t border-[var(--fa-line)]">
                                        @foreach($g['positionen'] as $p)
                                            @php
                                                $previewAltKey = (string) ($p['override_key'] ?? md5(($g['supplier_id'] ?? '') . '|' . ($g['delivery_date'] ?? '') . '|' . ($p['source_ref'] ?? '') . '|' . ($p['gp_id'] ?? '')));
                                            @endphp
                                            <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-3 py-2">
                                                <div class="min-w-0">
                                                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $p['designation'] ?: ($p['gp'] ?: 'Position') }}</p>
                                                    <p class="{{ $leise }}">
                                                        @if($p['article_number'])Art. {{ $p['article_number'] }} · @endif
                                                        Bedarf {{ $p['needed_display'] !== null ? $menge($p['needed_display'], 3) . ' ' . $p['needed_unit'] : 'direkt' }}
                                                        @if($p['source_label']) · {{ $p['source_label'] }}@endif
                                                    </p>
                                                    @if(!empty($p['reference']))
                                                        <x-fa::badge tone="accent" class="mt-1">{{ $p['reference'] }}</x-fa::badge>
                                                    @endif
                                                    @if(array_key_exists($previewAltKey, $cockpitOverrides))
                                                        <button type="button" wire:click="cockpitAlternativeZuruecksetzen('{{ $previewAltKey }}')" class="mt-1 inline-flex items-center gap-1 h-[22px] px-2 rounded-full bg-[var(--fa-warn-soft)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-warn)] hover:underline">
                                                            @svg('heroicon-m-arrow-uturn-left', 'w-3.5 h-3.5') Von Hand gewählt, Automatik wiederherstellen
                                                        </button>
                                                    @endif
                                                    @if(($p['gp_id'] ?? null) !== null)
                                                        <div>
                                                            <button type="button"
                                                                wire:click="cockpitAlternativenUmschalten('{{ $previewAltKey }}', {{ (int) $p['gp_id'] }}, {{ (int) $g['supplier_id'] }}, {{ ($p['lead_la_id'] ?? null) !== null ? (int) $p['lead_la_id'] : 'null' }})"
                                                                class="{{ $wechselKnopf }}" aria-expanded="{{ $cockpitAltKey === $previewAltKey ? 'true' : 'false' }}">
                                                                @if($cockpitAltKey === $previewAltKey)
                                                                    @svg('heroicon-m-chevron-up', 'w-3.5 h-3.5') Alternativen schließen
                                                                @else
                                                                    @svg('heroicon-m-arrows-right-left', 'w-3.5 h-3.5') Lieferant oder Artikel wechseln
                                                                @endif
                                                            </button>
                                                        </div>
                                                        @if($cockpitAltKey === $previewAltKey)
                                                            <div class="mt-1 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] p-1 flex flex-col gap-0.5">
                                                                @forelse($cockpitAlternativen as $alt)
                                                                    <button type="button"
                                                                        wire:click="cockpitAlternativeWaehlen('{{ $previewAltKey }}', {{ $alt['la_id'] }})"
                                                                        @disabled($alt['gesperrt'])
                                                                        class="flex w-full flex-col items-start px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left bg-[var(--fa-surface)] hover:bg-[var(--fa-hover)] disabled:opacity-50 disabled:cursor-not-allowed"
                                                                        wire:key="preview-alt-{{ md5($previewAltKey) }}-{{ $alt['la_id'] }}">
                                                                        <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $alt['designation'] ?: '–' }}</span>
                                                                        <span class="{{ $leise }}">
                                                                            {{ $alt['supplier'] ?? '–' }}@if($alt['schiene_wechsel']) · anderer Lieferant @endif
                                                                            @if($alt['ist_stamm']) · Stammlieferant @endif
                                                                            @if($alt['vergleichspreis'] !== null) · {{ number_format($alt['vergleichspreis'], 2, ',', '.') }} {{ $alt['vergleichspreis_einheit'] ?? '' }} @endif
                                                                        </span>
                                                                    </button>
                                                                @empty
                                                                    <p class="px-2 py-1 {{ $leise }}">Keine Alternative gefunden.</p>
                                                                @endforelse
                                                            </div>
                                                        @endif
                                                    @endif
                                                </div>
                                                <div class="flex flex-col items-end gap-0.5 whitespace-nowrap">
                                                    <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums">{{ $menge($p['qty_packs']) }} {{ $p['packaging_unit'] }}</span>
                                                    <x-fa::money :value="$p['bestellbar'] ? $p['line_total'] : null" missing="Gebindepreis fehlt" class="{{ $leise }}" />
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-fa::section>

                <x-fa::section title="Klärliste" icon="heroicon-o-exclamation-triangle" class="min-w-0">
                    @if($cockpitPreview === null)
                        <p class="{{ $mittel }}">Die Klärliste erscheint nach der Vorschau.</p>
                    @elseif(empty($cockpitPreview['unresolved']))
                        <x-fa::signal tone="ok">Keine Klärpunkte.</x-fa::signal>
                    @else
                        <div class="flex flex-col gap-2">
                            @foreach($cockpitPreview['unresolved'] as $u)
                                <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-warn-soft)] px-3 py-2" wire:key="unresolved-{{ $loop->index }}" data-orders-klaerpunkt="{{ $u['code'] }}">
                                    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $u['label'] }}</p>
                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">{{ $u['message'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($cockpitPreview && !empty($cockpitPreview['warnings']))
                        <div class="flex flex-col gap-1">
                            @foreach($cockpitPreview['warnings'] as $w)
                                <x-fa::signal tone="warn">{{ $w }}</x-fa::signal>
                            @endforeach
                        </div>
                    @endif
                </x-fa::section>
            </div>
        </div>
    @else
    <x-foodalchemist::editor-tabs marker="orders" wire-key="orders-tabs-{{ $detail['id'] }}-{{ $detail['status'] }}" :init="$startTab" :tabs="$reiter" :gesperrt="$sperrLesen">

        {{-- ═══ Reiter: POSITIONEN ═══ --}}
        <div x-show="tab === 'positionen'" x-cloak class="pt-4 flex flex-col gap-4">
            @unless($detail['editierbar'])
                <x-fa::notice tone="info">Diese Bestellung ist versendet und eingefroren. Mengen und Artikel lassen sich nicht mehr ändern.</x-fa::notice>
            @endunless
            <x-fa::section title="Positionen" icon="heroicon-o-list-bullet" :meta="count($detail['zeilen'])">
                <div class="-mx-4 overflow-x-auto">
                    <table class="fa-table">
                        <thead><tr>
                            <th class="w-full">Artikel</th>
                            <th class="num">Bedarf</th>
                            <th class="num">Bestellen</th>
                            <th class="num">Preis je Gebinde</th>
                            <th class="num">Summe</th>
                            @if($detail['editierbar'])<th><span class="sr-only">Entfernen</span></th>@endif
                        </tr></thead>
                        <tbody>
                            @forelse($detail['zeilen'] as $z)
                                <tr class="align-top" wire:key="line-{{ $z['id'] }}">
                                    <td class="min-w-[16rem]">
                                        <p class="font-medium text-[var(--fa-ink)]">{{ $z['designation'] ?: '–' }}</p>
                                        @if($z['article_number'])<p class="{{ $leise }}">Art. {{ $z['article_number'] }}@if($z['packaging_unit']) · {{ $z['packaging_unit'] }}@endif</p>@endif
                                        @unless($z['bestellbar'])<x-fa::signal tone="warn" class="mt-1">Gebindepreis fehlt, nicht in Gebinden bestellbar</x-fa::signal>@endunless
                                        @if($z['quota'])
                                            <div class="mt-1">
                                                <x-fa::signal :tone="$z['quota']['exceeded'] || ! $z['quota']['is_valid_date'] ? 'warn' : 'ok'">
                                                    Kontingent: {{ $menge($z['quota']['remaining_before_packs']) }} {{ $z['packaging_unit'] ?: 'Geb.' }} frei, nach Bestellung {{ $menge($z['quota']['remaining_after_packs']) }}@if(!$z['quota']['is_valid_date']), außerhalb der Gültigkeit @endif
                                                </x-fa::signal>
                                            </div>
                                        @endif
                                        @if(!empty($z['inventory']))
                                            <div class="mt-1"><x-fa::signal tone="info" icon="heroicon-m-archive-box">Lager: {{ $z['inventory']['display'] }} verfügbar, Restbedarf {{ $z['inventory']['shortage_display'] }}</x-fa::signal></div>
                                        @endif
                                        @if(!empty($z['herkunft']))
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach($z['herkunft'] as $h)
                                                    <x-fa::badge :tone="$herkunftTon[$h['type']] ?? 'neutral'" title="{{ $h['ref'] }}">{{ $h['label'] }}</x-fa::badge>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if($detail['editierbar'])
                                            <x-fa::input size="sm" class="mt-1.5" value="{{ $z['note'] }}" placeholder="Notiz zur Position"
                                                wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)" aria-label="Notiz zur Position" />
                                        @elseif($z['note'])
                                            <p class="mt-1 {{ $mittel }} italic">{{ $z['note'] }}</p>
                                        @endif
                                        @if($detail['editierbar'] && $z['gp_id'] !== null)
                                            <div>
                                                <button type="button" wire:click="alternativenUmschalten({{ $z['id'] }})" class="{{ $wechselKnopf }}" aria-expanded="{{ $altLineId === $z['id'] ? 'true' : 'false' }}">
                                                    @if($altLineId === $z['id'])
                                                        @svg('heroicon-m-chevron-up', 'w-3.5 h-3.5') Wechsel schließen
                                                    @else
                                                        @svg('heroicon-m-arrows-right-left', 'w-3.5 h-3.5') Lieferant oder Artikel wechseln
                                                    @endif
                                                </button>
                                            </div>
                                            @if($altLineId === $z['id'])
                                                <div class="mt-1 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] p-1 flex flex-col gap-0.5">
                                                    @forelse($alternativen as $alt)
                                                        <button type="button" wire:key="alt-{{ $z['id'] }}-{{ $alt['la_id'] }}"
                                                            wire:click="alternativeWaehlen({{ $z['id'] }}, {{ $alt['la_id'] }})"
                                                            @if($alt['schiene_wechsel']) wire:confirm="Anderer Lieferant ({{ $alt['supplier'] }}): Die Position wandert in dessen Bestellung. Fortfahren?" @endif
                                                            @disabled($alt['gesperrt'])
                                                            class="flex w-full flex-col items-start px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left bg-[var(--fa-surface)] hover:bg-[var(--fa-hover)] disabled:opacity-50 disabled:cursor-not-allowed">
                                                            <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $alt['designation'] ?: '–' }}</span>
                                                            <span class="{{ $leise }}">
                                                                {{ $alt['supplier'] ?? '–' }}@if($alt['schiene_wechsel']) · anderer Lieferant @endif
                                                                @if($alt['ist_stamm']) · Stammlieferant @endif
                                                                @if($alt['vergleichspreis'] !== null) · {{ number_format($alt['vergleichspreis'], 2, ',', '.') }} {{ $alt['vergleichspreis_einheit'] ?? '' }} @endif
                                                            </span>
                                                        </button>
                                                    @empty
                                                        <p class="px-2 py-1 {{ $leise }}">Keine Ausweichquelle für dieses Grundprodukt.</p>
                                                    @endforelse
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ $menge($z['needed_display'], 3) }} {{ $z['needed_unit'] }}</td>
                                    <td class="num">
                                        <div class="inline-flex items-center justify-end gap-1">
                                            @if($detail['editierbar'])
                                                <x-fa::input size="sm" numeric type="number" min="0" step="1" value="{{ (float) $z['qty_packs'] }}"
                                                    wire:change="updateLineQty({{ $z['id'] }}, $event.target.value)"
                                                    class="w-20 {{ $z['is_manual_qty'] ? 'border-[var(--fa-warn)]' : '' }}"
                                                    title="{{ $z['is_manual_qty'] ? 'Menge von Hand gesetzt' : 'Menge aus dem Bedarf berechnet' }}" aria-label="Bestellmenge" />
                                                @if($z['is_manual_qty'])
                                                    <x-fa::icon-button size="sm" icon="heroicon-m-arrow-uturn-left" label="Menge wieder aus dem Bedarf berechnen" wire:click="resetLineQty({{ $z['id'] }})" />
                                                @endif
                                            @else
                                                <span class="tabular-nums">{{ $menge($z['qty_packs']) }}</span>
                                            @endif
                                            @if($z['packaging_unit'])<span class="{{ $leise }}">{{ $z['packaging_unit'] }}</span>@endif
                                        </div>
                                    </td>
                                    <td class="num text-[var(--fa-ink-2)]"><x-fa::money :value="$z['pack_price']" /></td>
                                    <td class="num font-medium"><x-fa::money :value="$z['line_total']" /></td>
                                    @if($detail['editierbar'])
                                        <td class="text-right">
                                            <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Position entfernen" wire:click="removeLine({{ $z['id'] }})" />
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $detail['editierbar'] ? 6 : 5 }}">
                                        <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Position">Im Reiter „Hinzufügen" Artikel oder Bedarf aus einem Rezept übernehmen.</x-fa::empty>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-[var(--fa-line-strong)]">
                                <td class="font-medium text-[var(--fa-ink)]" colspan="4">Bestellwert gesamt (netto)</td>
                                <td class="num font-semibold text-[var(--fa-ink)]"><x-fa::money :value="$detail['total_net']" /></td>
                                @if($detail['editierbar'])<td></td>@endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-fa::section>
        </div>

        {{-- ═══ Reiter: WARENEINGANG ═══ --}}
        <div x-show="tab === 'wareneingang'" x-cloak class="pt-4 flex flex-col gap-4">
            @php
                $receipt = $detail['receipt'];
            @endphp
            @if(!$detail['wareneingang_editierbar'])
                <x-fa::notice tone="warn">Der Wareneingang lässt sich buchen, sobald die Bestellung abgesendet oder bestätigt ist.</x-fa::notice>
            @endif
            <x-fa::section title="Wareneingang" icon="heroicon-o-inbox-arrow-down">
                @if($detail['wareneingang_editierbar'])
                    <x-slot:actions>
                        @if(($receipt['backorderable'] ?? 0) > 0)
                            <x-fa::button size="sm" wire:click="createBackorder">Nachlieferung anlegen</x-fa::button>
                        @endif
                        <x-fa::button size="sm" icon="heroicon-m-check" wire:click="completeReceipt">Lieferung vollständig übernehmen</x-fa::button>
                    </x-slot:actions>
                @endif
                <div class="flex flex-wrap gap-1.5">
                    <x-fa::badge>{{ $receipt['booked'] }} von {{ $receipt['lines'] }} Zeilen gebucht</x-fa::badge>
                    @if($receipt['missing'] > 0)<x-fa::badge tone="warn">{{ $receipt['missing'] }} offen</x-fa::badge>@endif
                    @if($receipt['differences'] > 0)<x-fa::badge tone="crit">{{ $receipt['differences'] }} {{ (int) $receipt['differences'] === 1 ? 'Differenz' : 'Differenzen' }}</x-fa::badge>@endif
                    <x-fa::badge tone="accent">Wareneingang netto {{ $euro($receipt['received_net']) }}</x-fa::badge>
                </div>
                <div class="-mx-4 overflow-x-auto">
                    <table class="fa-table">
                        <thead><tr>
                            <th class="w-full">Artikel</th>
                            <th class="num">Bestellt</th>
                            <th class="num">Geliefert</th>
                            <th class="num">Differenz</th>
                            <th>Notiz</th>
                        </tr></thead>
                        <tbody>
                            @foreach($detail['zeilen'] as $z)
                                @php
                                    $diff = $z['receipt_diff_packs'];
                                @endphp
                                <tr class="align-top" wire:key="receipt-line-{{ $z['id'] }}">
                                    <td class="min-w-[14rem]">
                                        <p class="font-medium text-[var(--fa-ink)]">{{ $z['designation'] ?: '–' }}</p>
                                        @if($z['article_number'])<p class="{{ $leise }}">Art. {{ $z['article_number'] }}</p>@endif
                                        @if($z['received_at'])<p class="{{ $leise }} tabular-nums">gebucht {{ \Carbon\Carbon::parse($z['received_at'])->format('d.m.Y H:i') }}</p>@endif
                                        @if(!empty($z['inventory']))
                                            <x-fa::signal tone="info" icon="heroicon-m-archive-box" class="mt-1">Lager danach: {{ $z['inventory']['display'] }}, Rest {{ $z['inventory']['shortage_display'] }}</x-fa::signal>
                                        @endif
                                    </td>
                                    <td class="num">{{ $menge($z['qty_packs']) }} <span class="{{ $leise }}">{{ $z['packaging_unit'] }}</span></td>
                                    <td class="num">
                                        <div class="inline-flex items-center justify-end gap-1">
                                            @if($detail['wareneingang_editierbar'])
                                                <x-fa::input size="sm" numeric type="number" min="0" step="0.01" value="{{ $z['received_qty_packs'] }}"
                                                    wire:change="updateReceiptLine({{ $z['id'] }}, $event.target.value, null)" class="w-24" aria-label="Gelieferte Menge" />
                                            @else
                                                <span>{{ $z['received_qty_packs'] !== null ? $menge($z['received_qty_packs']) : '–' }}</span>
                                            @endif
                                            @if($z['packaging_unit'])<span class="{{ $leise }}">{{ $z['packaging_unit'] }}</span>@endif
                                        </div>
                                    </td>
                                    <td class="num">
                                        @if($diff === null)
                                            <span class="text-[var(--fa-ink-3)]">offen</span>
                                        @elseif(abs((float) $diff) < 0.01)
                                            <x-fa::signal tone="ok">stimmt</x-fa::signal>
                                        @else
                                            <span class="font-medium {{ (float) $diff < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-warn)]' }}">{{ (float) $diff > 0 ? '+' : '' }}{{ $menge($diff) }}</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[12rem]">
                                        @if($detail['wareneingang_editierbar'])
                                            <x-fa::input size="sm" value="{{ $z['received_note'] }}" placeholder="Differenz, Ersatz, Bruch"
                                                wire:change="updateReceiptNote({{ $z['id'] }}, $event.target.value)" aria-label="Notiz zum Wareneingang" />
                                        @else
                                            <span class="{{ $mittel }}">{{ $z['received_note'] ?: '–' }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-fa::section>
        </div>

        {{-- ═══ Reiter: RECHNUNG ═══ --}}
        <div x-show="tab === 'rechnung'" x-cloak class="pt-4 flex flex-col gap-4">
            @php
                $invoice = $detail['invoice'];
                $claims = $detail['claims'];
                $zahlungsZustand = $detail['payment']['state'] ?? '';
            @endphp
            @if(!$detail['rechnung_editierbar'])
                <x-fa::notice tone="warn">Die Rechnung lässt sich erfassen, sobald die Bestellung abgesendet ist.</x-fa::notice>
            @endif

            <x-fa::section title="Rechnungskopf" icon="heroicon-o-document-text">
                @if($detail['rechnung_editierbar'])
                    <x-slot:actions>
                        <x-fa::button size="sm" wire:click="saveInvoiceHeader">Rechnungskopf speichern</x-fa::button>
                    </x-slot:actions>
                @endif
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <x-fa::field label="Rechnungsnummer" for="orders-re-nummer">
                        @if($detail['rechnung_editierbar'])
                            <x-fa::input id="orders-re-nummer" wire:model="formInvoiceNumber" />
                        @else
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $detail['invoice_number'] ?: '–' }}</p>
                        @endif
                    </x-fa::field>
                    <x-fa::field label="Rechnungsdatum" for="orders-re-datum">
                        @if($detail['rechnung_editierbar'])
                            <x-fa::input id="orders-re-datum" type="date" wire:model="formInvoiceDate" />
                        @else
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums">{{ $datum($detail['invoice_date']) ?? '–' }}</p>
                        @endif
                    </x-fa::field>
                    <x-fa::field label="Fällig am" :hint="$detail['payment_term_days'] !== null ? $detail['payment_term_days'] . ' Tage Zahlungsziel' : null">
                        <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums">{{ $datum($detail['invoice_due_date']) ?? '–' }}</p>
                    </x-fa::field>
                    <x-fa::field label="Rechnungsnotiz" for="orders-re-notiz" class="md:col-span-3">
                        @if($detail['rechnung_editierbar'])
                            <x-fa::textarea id="orders-re-notiz" wire:model="formInvoiceNote" rows="2" />
                        @else
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $detail['invoice_note'] ?: '–' }}</p>
                        @endif
                    </x-fa::field>
                </div>
            </x-fa::section>

            @if($detail['invoice_number'] || $detail['invoice_date'])
                <x-fa::section title="Zahlung" icon="heroicon-o-banknotes">
                    @if($detail['rechnung_editierbar'])
                        <x-slot:actions>
                            <x-fa::button size="sm" wire:click="savePayment">Zahlungsstatus speichern</x-fa::button>
                        </x-slot:actions>
                    @endif
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <x-fa::field label="Zahlungsstatus" for="orders-zahlung-status">
                            @if($detail['rechnung_editierbar'])
                                <x-fa::select id="orders-zahlung-status" wire:model="formPaymentStatus">
                                    <option value="">nicht erfasst</option>
                                    <option value="open">offen</option>
                                    <option value="disputed">strittig</option>
                                    <option value="paid">bezahlt</option>
                                </x-fa::select>
                            @else
                                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $detail['payment']['label'] ?? '–' }}</p>
                            @endif
                        </x-fa::field>
                        <x-fa::field label="Bezahlt am" for="orders-zahlung-datum">
                            @if($detail['rechnung_editierbar'])
                                <x-fa::input id="orders-zahlung-datum" type="date" wire:model="formInvoicePaidAt" />
                            @else
                                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums">{{ $datum($detail['invoice_paid_at']) ?? '–' }}</p>
                            @endif
                        </x-fa::field>
                        <x-fa::field label="Stand">
                            <p class="text-[length:var(--fa-text-md)] font-medium {{ $zahlungsZustand === 'overdue' ? 'text-[var(--fa-warn)]' : ($zahlungsZustand === 'paid' ? 'text-[var(--fa-ok)]' : 'text-[var(--fa-ink)]') }}">{{ $detail['payment']['label'] ?? '–' }}</p>
                            @if(($detail['payment']['overdue_days'] ?? 0) > 0)
                                <x-fa::signal tone="warn">{{ $detail['payment']['overdue_days'] }} Tage überfällig</x-fa::signal>
                            @endif
                        </x-fa::field>
                        <x-fa::field label="Zahlungsnotiz" for="orders-zahlung-notiz" class="md:col-span-3">
                            @if($detail['rechnung_editierbar'])
                                <x-fa::textarea id="orders-zahlung-notiz" wire:model="formPaymentNote" rows="2" />
                            @else
                                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $detail['payment_note'] ?: '–' }}</p>
                            @endif
                        </x-fa::field>
                    </div>
                </x-fa::section>
            @endif

            <x-fa::section title="Rechnungsprüfung je Position" icon="heroicon-o-clipboard-document-check">
                @if($detail['rechnung_editierbar'])
                    <x-slot:actions>
                        <x-fa::button size="sm" icon="heroicon-m-arrow-down-on-square" wire:click="completeInvoiceFromReceipt">Aus Wareneingang übernehmen</x-fa::button>
                    </x-slot:actions>
                @endif
                <div class="flex flex-wrap gap-1.5">
                    <x-fa::badge>{{ $invoice['checked'] }} von {{ $invoice['lines'] }} Zeilen geprüft</x-fa::badge>
                    @if($invoice['missing'] > 0)<x-fa::badge tone="warn">{{ $invoice['missing'] }} offen</x-fa::badge>@endif
                    @if($invoice['differences'] > 0)<x-fa::badge tone="crit">{{ $invoice['differences'] }} {{ (int) $invoice['differences'] === 1 ? 'Differenz' : 'Differenzen' }}</x-fa::badge>@endif
                    <x-fa::badge tone="accent">Rechnung netto {{ $euro($invoice['invoice_net']) }}</x-fa::badge>
                    @if(abs((float) $invoice['diff_net']) >= 0.01)
                        <x-fa::badge tone="warn">Abweichung {{ $euro($invoice['diff_net']) }}</x-fa::badge>
                    @endif
                    @if(($claims['lines'] ?? 0) > 0)
                        <x-fa::badge :tone="(($claims['open'] ?? 0) + ($claims['credit_expected'] ?? 0)) > 0 ? 'warn' : 'ok'">Reklamation {{ $claims['lines'] }} · {{ $euro($claims['credit_expected_net'] ?? 0) }}</x-fa::badge>
                    @endif
                </div>
                <div class="-mx-4 overflow-x-auto">
                    <table class="fa-table">
                        <thead><tr>
                            <th>Artikel</th>
                            <th class="num">Grundlage</th>
                            <th class="num">Menge laut Rechnung</th>
                            <th class="num">Preis laut Rechnung</th>
                            <th class="num">Abweichung netto</th>
                            <th>Notiz</th>
                            <th>Reklamation</th>
                        </tr></thead>
                        <tbody>
                            @foreach($detail['zeilen'] as $z)
                                @php
                                    $diffNet = $z['invoice_diff_net'];
                                @endphp
                                <tr class="align-top" wire:key="invoice-line-{{ $z['id'] }}">
                                    <td class="min-w-[14rem]">
                                        <p class="font-medium text-[var(--fa-ink)]">{{ $z['designation'] ?: '–' }}</p>
                                        @if($z['article_number'])<p class="{{ $leise }}">Art. {{ $z['article_number'] }}</p>@endif
                                        @if($z['invoice_checked_at'])<p class="{{ $leise }} tabular-nums">geprüft {{ \Carbon\Carbon::parse($z['invoice_checked_at'])->format('d.m.Y H:i') }}</p>@endif
                                    </td>
                                    <td class="num">
                                        {{ $menge($z['received_qty_packs'] ?? $z['qty_packs']) }} <span class="{{ $leise }}">{{ $z['packaging_unit'] }}</span>
                                        <div class="{{ $leise }}"><x-fa::money :value="$z['pack_price']" /></div>
                                    </td>
                                    <td class="num">
                                        @if($detail['rechnung_editierbar'])
                                            <x-fa::input size="sm" numeric type="number" min="0" step="0.01" value="{{ $z['invoice_qty_packs'] }}"
                                                wire:change="updateInvoiceLine({{ $z['id'] }}, $event.target.value, {{ $z['invoice_pack_price'] !== null ? (float) $z['invoice_pack_price'] : 'null' }}, null)"
                                                class="w-24" aria-label="Menge laut Rechnung" />
                                        @else
                                            {{ $z['invoice_qty_packs'] !== null ? $menge($z['invoice_qty_packs']) : '–' }}
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if($detail['rechnung_editierbar'])
                                            <x-fa::input size="sm" numeric type="number" min="0" step="0.01" value="{{ $z['invoice_pack_price'] }}"
                                                wire:change="updateInvoiceLine({{ $z['id'] }}, {{ $z['invoice_qty_packs'] !== null ? (float) $z['invoice_qty_packs'] : 'null' }}, $event.target.value, null)"
                                                class="w-28" aria-label="Preis laut Rechnung" />
                                        @else
                                            {{ $z['invoice_pack_price'] !== null ? $euro($z['invoice_pack_price']) : '–' }}
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if($diffNet === null)
                                            <span class="text-[var(--fa-ink-3)]">offen</span>
                                        @elseif(abs((float) $diffNet) < 0.01)
                                            <x-fa::signal tone="ok">stimmt</x-fa::signal>
                                        @else
                                            <span class="font-medium {{ (float) $diffNet < 0 ? 'text-[var(--fa-ok)]' : 'text-[var(--fa-crit)]' }}">{{ (float) $diffNet > 0 ? '+' : '' }}{{ $euro($diffNet) }}</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[12rem]">
                                        @if($detail['rechnung_editierbar'])
                                            <x-fa::input size="sm" value="{{ $z['invoice_note'] }}" placeholder="Preisabweichung, Gutschrift"
                                                wire:change="updateInvoiceNote({{ $z['id'] }}, $event.target.value)" aria-label="Notiz zur Rechnung" />
                                        @else
                                            <span class="{{ $mittel }}">{{ $z['invoice_note'] ?: '–' }}</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[240px]">
                                        @if($detail['rechnung_editierbar'])
                                            <div class="grid grid-cols-2 gap-1">
                                                <x-fa::select size="sm" wire:change="updateClaimStatus({{ $z['id'] }}, $event.target.value)" aria-label="Status der Reklamation">
                                                    <option value="" @selected(!$z['claim_status'])>keine</option>
                                                    <option value="open" @selected($z['claim_status'] === 'open')>offen</option>
                                                    <option value="credit_expected" @selected($z['claim_status'] === 'credit_expected')>Gutschrift erwartet</option>
                                                    <option value="credited" @selected($z['claim_status'] === 'credited')>gutgeschrieben</option>
                                                    <option value="resolved" @selected($z['claim_status'] === 'resolved')>erledigt</option>
                                                </x-fa::select>
                                                <x-fa::input size="sm" numeric type="number" min="0" step="0.01" value="{{ $z['claim_qty_packs'] }}"
                                                    placeholder="Menge" aria-label="Reklamierte Menge"
                                                    wire:change="updateClaimQty({{ $z['id'] }}, $event.target.value)" />
                                                <x-fa::input size="sm" numeric type="number" min="0" step="0.01" value="{{ $z['credit_expected_net'] }}"
                                                    placeholder="Gutschrift €" aria-label="Erwartete Gutschrift"
                                                    wire:change="updateClaimCredit({{ $z['id'] }}, $event.target.value)" />
                                                <x-fa::input size="sm" value="{{ $z['claim_note'] }}" placeholder="Notiz" aria-label="Notiz zur Reklamation"
                                                    wire:change="updateClaimNote({{ $z['id'] }}, $event.target.value)" />
                                            </div>
                                        @else
                                            <span class="{{ $mittel }}">{{ $z['claim_status_label'] ?? '–' }}</span>
                                            @if($z['credit_expected_net'] !== null)<p class="{{ $leise }}">{{ $euro($z['credit_expected_net']) }}</p>@endif
                                            @if($z['claim_note'])<p class="{{ $leise }}">{{ $z['claim_note'] }}</p>@endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-fa::section>
        </div>

        {{-- ═══ Reiter: HINZUFÜGEN (Direktbestellung, nur Entwurf) ═══ --}}
        <div x-show="tab === 'hinzufuegen'" x-cloak class="pt-4 flex flex-col gap-4">
            @if($detail['editierbar'])
                <x-fa::section title="Artikel direkt bestellen" icon="heroicon-o-shopping-bag">
                    <x-fa::field label="Lieferantenartikel" for="orders-direkt-artikel">
                        <x-fa::input id="orders-direkt-artikel" type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Lieferant, Grundprodukt, Artikel oder Artikelnummer" data-orders-artikel-suche />
                    </x-fa::field>
                    @if($artikelTreffer->isNotEmpty())
                        <div class="{{ $trefferListe }}">
                            @foreach($artikelTreffer as $a)
                                <button type="button" wire:click="artikelHinzufuegen({{ $a['id'] }})" wire:key="art-{{ $a['id'] }}" class="{{ $trefferKnopf }}">
                                    <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $a['designation'] ?: '–' }}</span>
                                    <span class="{{ $leise }}">{{ $a['supplier'] }}@if($a['gp']) · {{ $a['gp'] }}@endif @if($a['article_number']) · Art. {{ $a['article_number'] }}@endif</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif(mb_strlen(trim($artikelSuche)) >= 2)
                        <p class="{{ $leise }}">Kein Artikel gefunden.</p>
                    @endif
                </x-fa::section>

                <x-fa::section title="Bedarf aus Gericht oder Basisrezept" icon="heroicon-o-book-open"
                    description="Jede Zutat landet beim Lieferanten ihres Hauptartikels. Dabei können mehrere Bestellungen entstehen oder ergänzt werden.">
                    @if($bedarfRecipeId === null)
                        <x-fa::field label="Gericht oder Basisrezept" for="orders-direkt-rezept">
                            <x-fa::input id="orders-direkt-rezept" type="search" wire:model.live.debounce.300ms="bedarfSuche" placeholder="Gericht oder Basisrezept suchen" data-orders-bedarf-suche />
                        </x-fa::field>
                        @if($bedarfTreffer->isNotEmpty())
                            <div class="{{ $trefferListe }}">
                                @foreach($bedarfTreffer as $r)
                                    <button type="button" wire:click="bedarfRezeptWaehlen({{ $r['id'] }})" wire:key="brz-{{ $r['id'] }}" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-[var(--fa-hover)]">
                                        <span class="min-w-0 truncate text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $r['name'] }}</span>
                                        <x-fa::badge :tone="$r['is_sales_recipe'] ? 'info' : 'neutral'" class="shrink-0">{{ $r['is_sales_recipe'] ? 'Gericht' : 'Basisrezept' }}</x-fa::badge>
                                    </button>
                                @endforeach
                            </div>
                        @elseif(mb_strlen(trim($bedarfSuche)) >= 2)
                            <p class="{{ $leise }}">Kein Rezept gefunden.</p>
                        @endif
                    @else
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="min-w-0 flex items-center gap-2">
                                    <span class="truncate text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $bedarfRecipeName }}</span>
                                    <x-fa::badge :tone="$bedarfRecipeVk ? 'info' : 'neutral'" class="shrink-0">{{ $bedarfRecipeVk ? 'Gericht' : 'Basisrezept' }}</x-fa::badge>
                                </span>
                                <x-fa::button size="sm" variant="ghost" wire:click="bedarfRezeptZuruecksetzen">Rezept ändern</x-fa::button>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-fa::input numeric type="number" min="0" step="0.1" wire:model="bedarfMenge" placeholder="Menge" class="w-32" aria-label="Menge" />
                                @if($bedarfRecipeVk)
                                    <span class="{{ $mittel }}">Portionen</span>
                                @else
                                    <x-fa::select wire:model="bedarfEinheit" class="w-auto" aria-label="Einheit">
                                        <option value="ansaetze">Ansätze</option>
                                        <option value="kg">kg</option>
                                    </x-fa::select>
                                @endif
                                <x-fa::button icon="heroicon-m-plus" wire:click="bedarfUebernehmen" data-orders-bedarf-uebernehmen>Bedarf übernehmen</x-fa::button>
                            </div>
                        </div>
                    @endif
                </x-fa::section>
            @endif
        </div>

        {{-- ═══ Reiter: BESTELLKOPF ═══ --}}
        <div x-show="tab === 'kopf'" x-cloak class="pt-4 flex flex-col gap-4">
            @if($moq['unter_mindestbestellwert'] || $moq['min_order_value'] !== null || $moq['frei_haus'] || $moq['free_shipping_threshold'] !== null)
                <div class="flex flex-wrap gap-1.5">
                    @if($moq['unter_mindestbestellwert'])
                        <x-fa::badge tone="warn">Unter Mindestbestellwert, es fehlen {{ $euro($moq['fehlt_bis_min']) }}</x-fa::badge>
                    @elseif($moq['min_order_value'] !== null)
                        <x-fa::badge tone="ok">Mindestbestellwert erreicht</x-fa::badge>
                    @endif
                    @if($moq['frei_haus'])
                        <x-fa::badge tone="ok">frei Haus</x-fa::badge>
                    @elseif($moq['free_shipping_threshold'] !== null)
                        <x-fa::badge>{{ $euro($moq['fehlt_bis_frei_haus']) }} bis frei Haus</x-fa::badge>
                    @endif
                </div>
            @endif

            @if(!empty($detail['warnings']))
                <x-fa::section title="Hinweise" icon="heroicon-o-exclamation-triangle">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($detail['warnings'] as $w)
                            <x-fa::badge :tone="in_array($w, $detail['send_blockers'] ?? [], true) ? 'crit' : 'warn'">{{ $w }}</x-fa::badge>
                        @endforeach
                    </div>
                    @if(!empty($detail['send_blockers']))
                        <x-fa::signal tone="crit">Absenden ist gesperrt, bis die roten Punkte geklärt sind.</x-fa::signal>
                    @endif
                    @if(!empty($detail['logistik']['deadline']))
                        <p class="{{ $leise }} tabular-nums">Bestellschluss: {{ \Carbon\Carbon::parse($detail['logistik']['deadline'])->format('d.m.Y H:i') }}</p>
                    @endif
                </x-fa::section>
            @endif

            @if($detail['editierbar'])
                <x-fa::section title="Liefertag und Anlass" icon="heroicon-o-calendar-days">
                    <x-slot:actions>
                        <x-fa::button size="sm" wire:click="saveHeader" data-orders-kopf-speichern>Bestellkopf speichern</x-fa::button>
                    </x-slot:actions>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <x-fa::field label="Liefertag" for="orders-kopf-liefertag">
                            <x-fa::input id="orders-kopf-liefertag" type="date" wire:model="formDeliveryDate" />
                        </x-fa::field>
                        <x-fa::field label="Anlass" for="orders-kopf-anlass" class="md:col-span-2">
                            <x-fa::input id="orders-kopf-anlass" wire:model="formReference" placeholder="z. B. Sommerfest" />
                        </x-fa::field>
                        <x-fa::field label="Notiz" for="orders-kopf-notiz" class="md:col-span-3">
                            <x-fa::textarea id="orders-kopf-notiz" wire:model="formNote" rows="2" placeholder="Interne Notiz" />
                        </x-fa::field>
                    </div>
                </x-fa::section>

                <x-fa::section title="Einkaufsstrategie und Lieferanten neu ermitteln" icon="heroicon-o-arrows-right-left"
                    description="Prüft, welche Positionen unter einer anderen Strategie zu einem anderen Artikel oder Lieferanten wechseln würden.">
                    <div class="flex flex-wrap items-end gap-2">
                        <x-fa::field label="Strategie" for="orders-kopf-strategie" class="max-w-sm w-full">
                            <x-fa::select id="orders-kopf-strategie" wire:model="formStrategy">
                                <option value="">Haupteinstellung des Teams</option>
                                @foreach($strategieOptionen as $s)
                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                @endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <span class="pb-2 {{ $leise }}" data-kpi="strategie">Aktuell: {{ $strategieLabel($detail['sourcing_strategy']) }}</span>
                    </div>
                    @if($resourceVorschau === null)
                        <div><x-fa::button wire:click="neuQuellenVorschau" data-neu-quellen-vorschau>Lieferantenwechsel prüfen</x-fa::button></div>
                    @else
                        <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] p-3 flex flex-col gap-2">
                            @if(empty($resourceVorschau['wechsel']))
                                <p class="{{ $mittel }}">Unter dieser Strategie wechselt keine Position.</p>
                            @else
                                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ count($resourceVorschau['wechsel']) }} {{ count($resourceVorschau['wechsel']) === 1 ? 'Position wechselt' : 'Positionen wechseln' }}:</p>
                                <ul class="flex flex-col gap-0.5">
                                    @foreach($resourceVorschau['wechsel'] as $w)
                                        <li class="flex flex-wrap items-center gap-1 {{ $mittel }}">
                                            {{ $w['gp'] }} @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]') <span class="text-[var(--fa-ink)]">{{ $w['nach_artikel'] ?: '–' }}</span>
                                            <span class="text-[var(--fa-ink-3)]">({{ $w['nach_lieferant'] ?? '–' }}@if($w['schiene_wechsel']) · anderer Lieferant @endif)</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="flex gap-2 pt-1">
                                <x-fa::button size="sm" variant="ghost" wire:click="neuQuellenAbbrechen">Abbrechen</x-fa::button>
                                <x-fa::button size="sm" wire:click="neuQuellenAnwenden" :disabled="empty($resourceVorschau['wechsel'])" data-neu-quellen-anwenden>Wechsel anwenden</x-fa::button>
                            </div>
                        </div>
                    @endif
                </x-fa::section>
            @else
                @if(!in_array($detail['status'], ['draft', 'cancelled'], true))
                    <x-fa::section title="Lieferantenbestätigung" icon="heroicon-o-check-badge">
                        <x-slot:actions>
                            <x-fa::button size="sm" wire:click="saveSupplierConfirmation">Bestätigung speichern</x-fa::button>
                        </x-slot:actions>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <x-fa::field label="Bestell- oder Auftragsnummer" for="orders-ab-nummer">
                                <x-fa::input id="orders-ab-nummer" wire:model="formSupplierOrderNumber" />
                            </x-fa::field>
                            <x-fa::field label="Bestätigter Liefertag" for="orders-ab-liefertag">
                                <x-fa::input id="orders-ab-liefertag" type="date" wire:model="formConfirmedDeliveryDate" />
                            </x-fa::field>
                            <x-fa::field label="Bestätigungsnotiz" for="orders-ab-notiz" class="md:col-span-3">
                                <x-fa::textarea id="orders-ab-notiz" wire:model="formSupplierConfirmationNote" rows="2" />
                            </x-fa::field>
                        </div>
                    </x-fa::section>
                @endif
                <x-fa::section title="Bestellkopf" icon="heroicon-o-document-text">
                    <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-[length:var(--fa-text-md)]">
                        <dt class="text-[var(--fa-ink-2)]">Anlass</dt><dd class="text-[var(--fa-ink)]">{{ $detail['reference'] ?: '–' }}</dd>
                        <dt class="text-[var(--fa-ink-2)]">Liefertermin</dt><dd class="text-[var(--fa-ink)] tabular-nums">{{ $datum($detail['desired_delivery_date']) ?? 'nicht festgelegt' }}</dd>
                        <dt class="text-[var(--fa-ink-2)]">Notiz</dt><dd class="text-[var(--fa-ink)]">{{ $detail['note'] ?: '–' }}</dd>
                        <dt class="text-[var(--fa-ink-2)]">Einkaufsstrategie</dt><dd class="text-[var(--fa-ink)]" data-kpi="strategie">{{ $strategieLabel($detail['sourcing_strategy']) }}</dd>
                    </dl>
                </x-fa::section>
            @endif

            <x-fa::section title="Freigabe" icon="heroicon-o-shield-check">
                @if($detail['is_owned'])
                    <x-slot:actions>
                        <x-fa::button size="sm" wire:click="saveApproval">Freigabe speichern</x-fa::button>
                    </x-slot:actions>
                @endif
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <x-fa::field label="Status" for="orders-freigabe-status">
                        @if($detail['is_owned'])
                            <x-fa::select id="orders-freigabe-status" wire:model="formApprovalStatus">
                                <option value="">keine Freigabe</option>
                                <option value="requested">angefragt</option>
                                <option value="approved">freigegeben</option>
                                <option value="rejected">abgelehnt</option>
                            </x-fa::select>
                        @else
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $detail['approval']['label'] ?? '–' }}</p>
                        @endif
                    </x-fa::field>
                    <x-fa::field label="Zeitpunkt">
                        <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums">{{ ($detail['approval']['approved_at'] ?? null) ?: (($detail['approval']['requested_at'] ?? null) ?: '–') }}</p>
                    </x-fa::field>
                    <x-fa::field label="Freigabenotiz" for="orders-freigabe-notiz" class="md:col-span-3">
                        @if($detail['is_owned'])
                            <x-fa::textarea id="orders-freigabe-notiz" wire:model="formApprovalNote" rows="2" />
                        @else
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $detail['approval_note'] ?: '–' }}</p>
                        @endif
                    </x-fa::field>
                </div>
            </x-fa::section>

            @if(!empty($detail['herkunft']))
                <x-fa::section title="Herkunft" icon="heroicon-o-link">
                    <div class="flex flex-wrap gap-1">
                        @foreach($detail['herkunft'] as $h)
                            @if($h['production_order_id'] !== null)
                                <a href="{{ route('foodalchemist.produktion.index', ['auftrag' => $h['production_order_id']]) }}"
                                   class="inline-flex items-center gap-1 h-[22px] px-2 rounded-full bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:underline" title="Produktion öffnen">{{ $h['label'] }}@svg('heroicon-m-arrow-top-right-on-square', 'w-3.5 h-3.5')</a>
                            @else
                                <x-fa::badge :tone="$h['type'] === 'concept' ? 'info' : 'neutral'">{{ $h['label'] }}</x-fa::badge>
                            @endif
                        @endforeach
                    </div>
                </x-fa::section>
            @endif
        </div>
    </x-foodalchemist::editor-tabs>
    @endif
</x-foodalchemist::modal>
