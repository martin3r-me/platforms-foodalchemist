{{-- Signale — die Arbeitsliste für alles, was das System in den Daten findet und was eine
     Entscheidung braucht. Reine Darstellung/Steuerung: Detektor-, Policy- und Service-Logik
     bleiben unangetastet (Aktionen laufen über die bestehenden Services, eine Regel-Stelle).

     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt.
     · Auffälligkeiten nach Bereich gruppiert (ReviewQueue::BEREICHE), Kritisch über alle Seiten oben.
     · „Qualität verschlechtert sich" ist keine Einzelmeldung oben mehr, sondern Kennzahl am Bereich
       und eigener Abschnitt „Entwicklung" unten.
     · Typ-Filter als gruppierte Auswahl statt Chip-Wand; Reiter mit Namen und einem Satz Erklärung.
     · Signal-Zeile seitenlokal (statt partials/_signal-row): Schwere-Badge, Titel, Bezug, eine
       Hauptaktion; keine farbigen Seitenbalken mehr. Funktion, wire:-Bindungen und data-Marker gleich. --}}
@php
    $pflegeGesamt = $vkOhneKlasse->count() + $imReviewZahl + $ungemapptZahl;
    $vorschlaegeZahl = $bulkZahl + $matchZahl;
    $sevK = $severitySplit['kritisch'] ?? 0;
    $sevW = $severitySplit['warnung'] ?? 0;
    $sevI = $severitySplit['info'] ?? 0;
    $schwereTon = ['kritisch' => 'crit', 'warnung' => 'warn', 'info' => 'info'];

    $reiter = [
        'ueberblick' => ['label' => 'Überblick', 'satz' => 'Die Lage in Zahlen und die dringendsten Befunde auf einen Blick.', 'zahl' => null],
        'signale' => ['label' => 'Auffälligkeiten', 'satz' => 'Was die Prüfung in Preisen, Rezepten, Deklaration und Konzepten gefunden hat, nach Bereich und Schwere geordnet.', 'zahl' => $signalOffen],
        'vorschlaege' => ['label' => 'Zum Bestätigen', 'satz' => 'Werte und Zuordnungen, die die KI vorschlägt. Übernehmen oder verwerfen.', 'zahl' => $vorschlaegeZahl],
        'pflege' => ['label' => 'Nacharbeit', 'satz' => 'Rezepte und Gerichte, denen noch ein Handgriff fehlt, und Begriffe, die das Zuordnen lernen soll.', 'zahl' => $pflegeGesamt],
    ];

    // Typ-Auswahl: der gewählte Typ steht als Text neben der Auswahl, damit der Filter sichtbar bleibt.
    $typGewaehltLabel = null;
    foreach ($signalTypWerte as $tw) {
        if ($tw['value'] === $signalTyp) {
            $typGewaehltLabel = $tw['label'];
        }
    }
    $statusLabel = collect($signalStatusWerte)->firstWhere('value', $signalStatus)['label'] ?? $signalStatus;

    // Zustands-Zeilen (Spec 21 · E2): bekannte Lagen als EINE Zeile statt n Alarmen.
    // Abgelaufene Akzeptanz-Fristen brauchen eine Entscheidung → oben als Hinweis;
    // gedämpfte Lagen sind Nebensache → unten eingeklappt. Der gewählte Typ fällt raus (er ist offen).
    $zustandsZeilen = $signalStatus === 'offen'
        ? array_values(array_filter($signalZustand ?? [],
            fn ($z) => ($z['aggregiert'] || $z['state'] === 'frist_abgelaufen') && $z['type'] !== $signalTyp))
        : [];
    $fristAbgelaufen = array_values(array_filter($zustandsZeilen, fn ($z) => $z['state'] === 'frist_abgelaufen'));
    $gedaempft = array_values(array_filter($zustandsZeilen, fn ($z) => $z['state'] !== 'frist_abgelaufen'));

    $pflegeSpalten = [
        ['marker' => 'vk-ohne-klasse', 'icon' => 'heroicon-o-tag', 'titel' => 'Gerichte ohne Speisen-Klasse', 'zahl' => $vkOhneKlasse->count(),
            'ton' => 'warn', 'hint' => 'Ohne Klasse fehlt die Grundlage für den Verkaufspreis. Im Gericht die Klasse wählen oder vorschlagen lassen.', 'items' => $vkOhneKlasse, 'suffix' => false],
        ['marker' => 'im-review-status', 'icon' => 'heroicon-o-clock', 'titel' => 'Warten auf Freigabe', 'zahl' => $imReviewZahl,
            'ton' => 'warn', 'hint' => 'Prüfen und freigeben oder zurück in den Entwurf. Zeigt die ersten 50.', 'items' => $imReview, 'suffix' => false],
        ['marker' => 'ungemappte-zutaten', 'icon' => 'heroicon-o-question-mark-circle', 'titel' => 'Zutaten ohne Grundprodukt', 'zahl' => $ungemapptZahl,
            'ton' => 'crit', 'hint' => 'Solange eine Zutat keinem Grundprodukt zugeordnet ist, bleiben Allergene und Preis unbekannt. Zeigt die ersten 50.', 'items' => $ungemappt, 'suffix' => true],
    ];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Signale" icon="heroicon-o-bell-alert" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Signale'],
        ]">
            {{-- Kein `<x-slot:end>`: der Slot rendert auf demo nicht (Core-Komponente). Die
                 Lauf-Knöpfe stehen im Seitenkopf. --}}
        </x-ui-page-actionbar>
    </x-slot>

    {{-- Klick-Ziele der Rezept-Listen + Signal-Detail als Modal (`signal-selected` lädt das
         DetailPanel, das danach selbst `modal.open` feuert). Kein `activity`-Slot: der liess
         auf dieser Seite die Inhaltsfläche auf Höhe 0 kollabieren (2026-08-02). --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.recipes.pairing-netz-modal />{{-- „Netz öffnen" aus dem Rezept-/Gericht-Editor --}}
    <livewire:foodalchemist.verkauf.vk-modal />
    <livewire:foodalchemist.signale.detail-panel />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">
        <x-fa::page-header title="Signale" :subtitle="$aktiverBetrieb ? 'Betrieb: ' . $aktiverBetrieb : null">
            <x-slot:actions>
                {{-- Lauf-Knöpfe: getrennt, weil die Prüfung gratis ist und die KI-Befunde Provider-Geld
                     kosten. Das Limit steht sichtbar am Knopf (Kostenbremse, V-047). --}}
                <div class="flex flex-wrap items-center justify-end gap-2" data-rq-laeufe>
                    <div class="inline-flex items-center gap-2 h-9 pl-1 pr-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                        <x-foodalchemist::ki-action action="befundeLaufen" variant="ai" icon="heroicon-o-sparkles" label="KI-Befunde sammeln"
                                title="Rezept-Copilot über die fälligen Rezepte laufen lassen. Ruft das Modell für jedes Rezept einzeln, darum die Obergrenze rechts."
                                busy="Wird eingereiht …" flash="Lauf gestartet" data-rq-befunde />
                        <label for="rq-befunde-limit" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">höchstens</label>
                        <x-fa::input id="rq-befunde-limit" type="number" size="sm" numeric wire:model="befundeLimit" min="1"
                                     max="{{ \Platform\FoodAlchemist\Services\RecipeFindingsBatchService::MAX_LIMIT }}"
                                     class="w-16" title="Höchstens so viele Rezepte je Lauf" data-rq-befunde-limit />
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Rezepte</span>
                    </div>
                    <x-fa::button variant="primary" wire:click="detektorLaufen" wire:target="detektorLaufen" wire:loading.attr="disabled" data-rq-ampel
                                  title="Alle Prüfungen, die Ampel und den Verlauf neu rechnen. Kostet nichts und läuft im Hintergrund.">
                        <span wire:loading.remove wire:target="detektorLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-m-arrow-path', 'w-4 h-4') Daten neu prüfen</span>
                        <span wire:loading wire:target="detektorLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Wird eingereiht …</span>
                    </x-fa::button>
                </div>
            </x-slot:actions>
        </x-fa::page-header>

        @if($meldung !== null)
            <x-fa::notice tone="ok" data-rq-meldung>{{ $meldung }}</x-fa::notice>
        @endif
        @if($fehler !== null)
            <x-fa::notice tone="crit" data-rq-fehler>{{ $fehler }}</x-fa::notice>
        @endif

        {{-- ── Reiter (Server-Modus: die Komponente hält den Tab, nur das aktive Panel rendert).
             Marker wie vorher: data-rq-tabs am Root, data-rq-tab="<key>" je Knopf. --}}
        <div class="flex flex-col gap-2">
            <div role="tablist" aria-label="Ansicht" class="flex flex-wrap gap-1 border-b border-[var(--fa-line)]" data-fa-editor-tabs data-rq-tabs>
                @foreach($reiter as $tabKey => $r)
                    <button type="button" role="tab" wire:click="setTab('{{ $tabKey }}')" aria-selected="{{ $tab === $tabKey ? 'true' : 'false' }}"
                            class="inline-flex items-center gap-1.5 h-10 px-3 -mb-px border-b-2 text-[length:var(--fa-text-md)] font-medium transition-colors {{ $tab === $tabKey ? 'border-[var(--fa-accent)] text-[var(--fa-ink)]' : 'border-transparent text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                            data-fa-editor-tab="{{ $tabKey }}" data-rq-tab="{{ $tabKey }}">
                        {{ $r['label'] }}
                        @if(($r['zahl'] ?? 0) > 0)
                            <x-fa::badge :tone="$tab === $tabKey ? 'accent' : 'neutral'" class="tabular-nums">{{ number_format($r['zahl'], 0, ',', '.') }}</x-fa::badge>
                        @endif
                    </button>
                @endforeach
            </div>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-rq-tab-satz>{{ $reiter[$tab]['satz'] ?? '' }}</p>
        </div>

        {{-- ════════════════════════ ÜBERBLICK ════════════════════════ --}}
        @if($tab === 'ueberblick')
            {{-- Lagebild: die zwei echten Arbeitsvorräte als Sprung in den Reiter. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-rq-kpi>
                <button type="button" wire:key="lage-0" wire:click="setTab('signale')"
                        class="fa-surface group flex flex-col gap-1 p-4 text-left hover:bg-[var(--fa-hover)] transition-colors">
                    <span class="flex items-center justify-between text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                        Auffälligkeiten offen
                        @svg('heroicon-m-arrow-right', 'w-4 h-4 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-accent)]')
                    </span>
                    <span class="text-[length:var(--fa-text-2xl)] font-semibold tabular-nums leading-tight {{ $sevK > 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-accent)]' }}">{{ number_format($signalOffen, 0, ',', '.') }}</span>
                    <span class="flex flex-wrap gap-1.5">
                        @if($sevK > 0)<x-fa::badge tone="crit">{{ $sevK }} kritisch</x-fa::badge>@endif
                        @if($sevW > 0)<x-fa::badge tone="warn">{{ $sevW }} Warnung</x-fa::badge>@endif
                        @if($sevI > 0)<x-fa::badge tone="info">{{ $sevI }} Info</x-fa::badge>@endif
                        @if($signalOffen === 0)<x-fa::signal tone="ok">Nichts offen</x-fa::signal>@endif
                    </span>
                </button>
                <button type="button" wire:key="lage-1" wire:click="setTab('vorschlaege')"
                        class="fa-surface group flex flex-col gap-1 p-4 text-left hover:bg-[var(--fa-hover)] transition-colors">
                    <span class="flex items-center justify-between text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                        Zum Bestätigen
                        @svg('heroicon-m-arrow-right', 'w-4 h-4 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-accent)]')
                    </span>
                    <span class="text-[length:var(--fa-text-2xl)] font-semibold tabular-nums leading-tight text-[var(--fa-ink)]">{{ number_format($vorschlaegeZahl, 0, ',', '.') }}</span>
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ number_format($bulkZahl, 0, ',', '.') }} KI-Werte · {{ number_format($matchZahl, 0, ',', '.') }} Zuordnungen</span>
                </button>
            </div>

            {{-- Je Bereich: offen · davon kritisch · Verschlechterungen seit der letzten Prüfung --}}
            @if($bereichUebersicht !== [])
                @php
                    $bereichKpis = [];
                    foreach (\Platform\FoodAlchemist\Livewire\ReviewQueue::BEREICHE as $bk => $b) {
                        if (! isset($bereichUebersicht[$bk])) {
                            continue;
                        }
                        $u = $bereichUebersicht[$bk];
                        $drift = $driftJeBereich[$bk] ?? 0;
                        $bereichKpis[] = [
                            'label' => $b['label'],
                            'value' => number_format($u['offen'], 0, ',', '.') . ($u['kritisch'] > 0 ? ' · ' . $u['kritisch'] . ' kritisch' : ''),
                            'tone' => $u['kritisch'] > 0 ? 'crit' : null,
                            'hint' => $drift > 0 ? $drift . ' verschlechtert' : null,
                            'hint_title' => $drift > 0 ? 'Seit der letzten Prüfung gestiegen, siehe Abschnitt Entwicklung' : null,
                            'kpi' => 'bereich-' . $bk,
                        ];
                    }
                @endphp
                <x-fa::kpis :items="$bereichKpis" data-rq-bereiche />
            @endif

            {{-- Nacharbeit: dieselben Zahlen wie im Reiter, hier nur als Sprung. --}}
            <x-fa::section title="Nacharbeit" data-rq-pflege-shortcuts>
                <x-slot:actions>
                    <x-fa::button variant="ghost" size="sm" iconRight="heroicon-m-arrow-right" wire:click="setTab('pflege')">Alle ansehen</x-fa::button>
                </x-slot:actions>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    @foreach($pflegeSpalten as $sc)
                        <button type="button" wire:key="pfsc-{{ $loop->index }}" wire:click="setTab('pflege')"
                                class="flex items-center gap-3 rounded-[var(--fa-radius-control)] px-3 py-2 text-left hover:bg-[var(--fa-hover)] transition-colors">
                            @svg($sc['icon'], 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                            <span class="min-w-0">
                                <span class="block text-[length:var(--fa-text-lg)] font-semibold tabular-nums {{ $sc['zahl'] > 0 ? ($sc['ton'] === 'crit' ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ink)]') : 'text-[var(--fa-ink-3)]' }}">{{ number_format($sc['zahl'], 0, ',', '.') }}</span>
                                <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $sc['titel'] }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </x-fa::section>

            {{-- Dringendste offene Befunde (Schwere zuerst) --}}
            <x-fa::section title="Dringendste Befunde" description="Schwere zuerst. „Befund ansehen“ öffnet die betroffenen Rezepte, Gerichte und Artikel." data-rq-kritischste>
                <x-slot:actions>
                    <x-fa::button variant="ghost" size="sm" iconRight="heroicon-m-arrow-right" wire:click="setTab('signale')">Alle Auffälligkeiten</x-fa::button>
                </x-slot:actions>
                <div class="flex flex-col divide-y divide-[var(--fa-line)]">
                    @forelse($kritischste as $sig)
                        <div class="flex items-center gap-3 py-2.5" wire:key="krit-{{ $sig->id }}">
                            <x-fa::badge :tone="$schwereTon[$sig->severity->value] ?? 'info'" class="shrink-0 w-[76px] justify-center">{{ $sig->severity->label() }}</x-fa::badge>
                            <div class="min-w-0 flex-1">
                                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] truncate" title="{{ $sig->title }}">{{ $sig->title }}</p>
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] truncate">{{ \Platform\FoodAlchemist\Livewire\ReviewQueue::bereichLabel($sig->type) }} · {{ $sig->type->label() }}</p>
                            </div>
                            <x-fa::button size="sm" wire:click="$dispatch('signal-selected', { id: {{ $sig->id }} })" data-signal-reinschauen="{{ $sig->id }}">Befund ansehen</x-fa::button>
                        </div>
                    @empty
                        <x-fa::empty icon="heroicon-o-check-badge" title="Keine offenen Befunde">Alles sauber. Neue Befunde erscheinen nach der nächsten Prüfung, sofort mit „Daten neu prüfen“.</x-fa::empty>
                    @endforelse
                </div>
            </x-fa::section>
        @endif

        {{-- ════════════════════════ AUFFÄLLIGKEITEN ════════════════════════ --}}
        @if($tab === 'signale')
            <div class="flex flex-col gap-4" data-rq-signale>
                {{-- Werkzeugleiste: Status links, Typ-Auswahl (nach Bereich gruppiert) rechts --}}
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Status" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                        @foreach($signalStatusWerte as $sw)
                            <button type="button" wire:key="sigst-{{ $sw['value'] }}" wire:click="setSignalStatus('{{ $sw['value'] }}')" aria-pressed="{{ $signalStatus === $sw['value'] ? 'true' : 'false' }}"
                                    class="h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $signalStatus === $sw['value'] ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}">{{ $sw['label'] }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="rq-typ-wahl" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Art</label>
                        <x-fa::select id="rq-typ-wahl" size="sm" class="w-72 max-w-full" wire:key="sigtyp-wahl-{{ $signalTyp ?: 'alle' }}"
                                      wire:change="setSignalTyp($event.target.value)" data-rq-typ-wahl>
                            <option value="" @selected($signalTyp === '')>Alle Arten</option>
                            @foreach($typGruppen as $gruppe => $optionen)
                                <optgroup label="{{ $gruppe }}">
                                    @foreach($optionen as $wert => $text)
                                        <option value="{{ $wert }}" @selected($signalTyp === $wert)>{{ $text }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-fa::select>
                        @if($signalTyp !== '')
                            <x-fa::button variant="ghost" size="sm" icon="heroicon-m-x-mark" wire:click="setSignalTyp('')">Alle Arten zeigen</x-fa::button>
                        @endif
                    </div>
                </div>

                {{-- Abgelaufene Akzeptanz-Fristen: eine bewusste Entscheidung ist ausgelaufen → oben. --}}
                @foreach($fristAbgelaufen as $z)
                    <x-fa::notice tone="warn" :title="$z['label']" wire:key="sigzustand-{{ $z['type'] }}">
                        {{ $z['hinweis'] }}@if($z['note']). {{ $z['note'] }}@endif
                        <x-slot:actions>
                            <x-fa::button size="sm" wire:click="setSignalTyp('{{ $z['type'] }}')">{{ $z['count'] }} ansehen</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                @endforeach

                {{-- Abschnitte: Zuerst erledigen (kritisch) · Bereiche · Entwicklung --}}
                @forelse($signalSektionen as $sektion)
                    @php
                        $meta = number_format($sektion['items']->count(), 0, ',', '.');
                    @endphp
                    <x-fa::section :title="$sektion['label']" :icon="$sektion['icon']" :meta="$meta" :description="$sektion['satz']"
                                   id="bereich-{{ $sektion['key'] }}" wire:key="sektion-{{ $sektion['key'] }}" data-rq-sektion="{{ $sektion['key'] }}">
                        @if($sektion['drift'] > 0)
                            <x-slot:actions>
                                <x-fa::badge tone="warn" icon="heroicon-m-arrow-trending-down" title="Seit der letzten Prüfung gestiegen, siehe Abschnitt Entwicklung">{{ $sektion['drift'] }} verschlechtert</x-fa::badge>
                            </x-slot:actions>
                        @endif
                        <div class="flex flex-col divide-y divide-[var(--fa-line)] -mx-4">
                            @foreach($sektion['items'] as $sig)
                                @php
                                    // 22·H4b/V-033: der KI-Knopf hängt am AUSFÜHRBAREN Plan. `navigate` ist ein Weg-Satz
                                    // ohne Executor und wird im Detail erklärt, nicht hier als Knopf angeboten.
                                    $ki = \Platform\FoodAlchemist\Support\SignalCockpit::kiPlan($sig);
                                    $pl = is_array($sig->payload) ? $sig->payload : [];
                                    $anzahl = isset($pl['anzahl']) && is_numeric($pl['anzahl']) ? (int) $pl['anzahl'] : null;
                                @endphp
                                <div class="px-4" wire:key="sig-{{ $sig->id }}">
                                    <div class="flex flex-wrap sm:flex-nowrap items-start gap-3 py-3">
                                        <x-fa::badge :tone="$schwereTon[$sig->severity->value] ?? 'info'" class="shrink-0 w-[76px] justify-center mt-px">{{ $sig->severity->label() }}</x-fa::badge>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[length:var(--fa-text-base)] font-medium text-[var(--fa-ink)] leading-snug">{{ $sig->title }}</p>
                                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                                <span class="inline-flex items-center gap-1">@svg($sig->type->icon(), 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]'){{ $sig->type->label() }}</span>
                                                @if($anzahl !== null && $sig->type->value !== 'qualitaet_drift')
                                                    <span class="tabular-nums">{{ number_format($anzahl, 0, ',', '.') }} betroffen</span>
                                                @endif
                                                {{-- Ebene 2: Betriebs-Lane sichtbar machen. NULL = Team-Core (kein Badge), sonst der Betrieb. --}}
                                                @if($sig->outlet_id)
                                                    <x-fa::badge tone="accent" icon="heroicon-m-building-storefront" title="Signal dieses Betriebs">{{ optional($sig->outlet)->name ?? 'Betrieb' }}</x-fa::badge>
                                                @endif
                                            </p>
                                            @if($sig->description)
                                                <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] line-clamp-2 max-w-[80ch]" title="{{ $sig->description }}">{{ $sig->description }}</p>
                                            @endif

                                            @if($sig->type->value === 'preis_sprung_marge_impact' && $pl)
                                                @php
                                                    $md = (float) ($pl['marge_delta_eur'] ?? 0);
                                                    $wd = (float) ($pl['wpct_delta'] ?? 0);
                                                @endphp
                                                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">
                                                    @isset($pl['preis_alt'], $pl['preis_neu'])
                                                        <span><x-fa::money :value="$pl['preis_alt']" /> → <span class="font-medium text-[var(--fa-ink)]"><x-fa::money :value="$pl['preis_neu']" /></span></span>
                                                    @endisset
                                                    <span>{{ $pl['n_gerichte'] ?? 0 }} Gericht(e) · {{ $pl['n_concepts'] ?? 0 }} Konzept(e)</span>
                                                    @if($md != 0.0)
                                                        <span class="font-medium {{ $md < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">Marge {{ number_format($md, 2, ',', '.') }} €@if($wd != 0.0) ({{ $wd > 0 ? '+' : '' }}{{ number_format($wd, 1, ',', '.') }} Prozentpunkte Wareneinsatz)@endif</span>
                                                    @endif
                                                    @if(!empty($pl['guenstigere_alternative']['label']))
                                                        <span class="inline-flex items-center gap-1 text-[var(--fa-info)]" title="Günstigere Alternative: {{ $pl['guenstigere_alternative']['label'] }}">@svg('heroicon-m-arrow-down', 'w-3.5 h-3.5') {{ \Illuminate\Support\Str::limit($pl['guenstigere_alternative']['label'], 28) }} ({{ $pl['guenstigere_alternative']['diff_pct'] }} %)</span>
                                                    @endif
                                                </div>
                                                @if(!empty($pl['beispiele']))
                                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)]">
                                                        @foreach(array_slice($pl['beispiele'], 0, 6) as $bsp)
                                                            <a href="{{ route('foodalchemist.verkauf.index', ['rezept' => $bsp['recipe_id']]) }}" wire:navigate
                                                               class="text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:underline" title="{{ $bsp['name'] }}: Marge {{ $bsp['marge_pct_alt'] }} % → {{ $bsp['marge_pct_neu'] }} %">
                                                                {{ \Illuminate\Support\Str::limit($bsp['name'], 26) }}@if(($bsp['marge_delta_eur'] ?? 0) != 0) <span class="text-[var(--fa-ink-3)] tabular-nums">({{ number_format($bsp['marge_delta_eur'], 2, ',', '.') }} €)</span>@endif
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @endif
                                        </div>

                                        <div class="shrink-0 flex items-center gap-1.5 ml-auto">
                                            @if($sig->status->istOffen())
                                                @if($ki)
                                                    <x-fa::button variant="ai" size="sm" icon="heroicon-m-sparkles" wire:click="toggleKiPanel({{ $sig->id }})"
                                                                  aria-expanded="{{ $kiPanelId === $sig->id ? 'true' : 'false' }}" title="{{ $ki['flavorLabel'] }}">KI erledigen lassen</x-fa::button>
                                                @endif
                                                {{-- Hauptaktion je Signal: die betroffenen Objekte ansehen (öffnet das Detail-Modal). --}}
                                                <x-fa::button size="sm" icon="heroicon-m-arrow-top-right-on-square" wire:click="$dispatch('signal-selected', { id: {{ $sig->id }} })"
                                                              title="Betroffene Objekte anzeigen" data-signal-reinschauen="{{ $sig->id }}">Betroffene ansehen</x-fa::button>
                                                {{-- Erledigt/Ignorieren sind Status-Setzer, keine lauten Aktionen. --}}
                                                <x-fa::icon-button size="sm" icon="heroicon-o-check-circle" label="Als erledigt markieren" wire:click="signalErledigt({{ $sig->id }})" data-rq-sig-erledigt="{{ $sig->id }}" />
                                                <x-fa::icon-button size="sm" icon="heroicon-o-no-symbol" label="Bewusst ignorieren" wire:click="signalIgnorieren({{ $sig->id }})" data-rq-sig-ignorieren="{{ $sig->id }}" />
                                            @else
                                                <x-fa::badge :tone="$sig->status->value === 'erledigt' ? 'ok' : 'neutral'">{{ $sig->status->label() }}</x-fa::badge>
                                                <x-fa::button size="sm" wire:click="$dispatch('signal-selected', { id: {{ $sig->id }} })"
                                                              title="Betroffene Objekte anzeigen" data-signal-reinschauen="{{ $sig->id }}">Betroffene ansehen</x-fa::button>
                                                <x-fa::button variant="ghost" size="sm" wire:click="signalWiederOeffnen({{ $sig->id }})">Wieder öffnen</x-fa::button>
                                            @endif
                                        </div>
                                    </div>

                                    @if($ki && $sig->status->istOffen() && $kiPanelId === $sig->id)
                                        @php
                                            $istFix = $ki['kind'] === 'deterministic';
                                        @endphp
                                        <div class="mb-3 sm:ml-[88px] rounded-[var(--fa-radius-surface)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-4 py-3 flex flex-col gap-2" wire:key="kpanel-{{ $sig->id }}">
                                            <div class="flex items-center gap-2">
                                                <x-fa::badge :tone="$istFix ? 'ok' : 'info'" :icon="$istFix ? 'heroicon-m-bolt' : 'heroicon-m-sparkles'">{{ $ki['flavorLabel'] }}</x-fa::badge>
                                                <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">So würde die KI vorgehen</span>
                                            </div>
                                            <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)]">{{ $ki['plan'] }}</p>

                                            @if(($kiDraft['signal_id'] ?? null) === $sig->id && !empty($kiDraft['draft']))
                                                <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2" wire:key="kidraft-{{ $sig->id }}">
                                                    <div class="flex items-center justify-between mb-1">
                                                        <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-info)]">Entwurf der KI</span>
                                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">Sicherheit {{ round(((float) ($kiDraft['confidence'] ?? 0)) * 100) }} %</span>
                                                    </div>
                                                    <textarea readonly rows="6" onclick="this.select()" aria-label="Entwurf der KI"
                                                              class="w-full text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink)] bg-transparent border-0 resize-y focus:ring-0 p-0">{{ $kiDraft['draft'] }}</textarea>
                                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] mt-1">Klicken markiert den Text. Der Entwurf wird nicht automatisch verschickt.</p>
                                                </div>
                                            @endif

                                            <div class="flex items-center justify-end gap-2">
                                                <x-fa::button variant="ghost" size="sm" wire:click="toggleKiPanel({{ $sig->id }})">Schließen</x-fa::button>
                                                <x-foodalchemist::ki-action action="kiFixAusfuehren({{ $sig->id }})" target="kiFixAusfuehren" variant="primary"
                                                        :icon="$istFix ? 'heroicon-o-play' : 'heroicon-o-sparkles'" :label="$istFix ? 'Automatisch beheben' : 'Entwurf erzeugen'"
                                                        busy="Läuft …" />
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-fa::section>
                @empty
                    <div class="fa-surface">
                        @if($signalStatus === 'offen')
                            <x-fa::empty icon="heroicon-o-check-badge" title="Keine offenen Auffälligkeiten">
                                Neue Befunde erscheinen nach der nächsten Prüfung. Mit „Daten neu prüfen“ sofort messen.
                            </x-fa::empty>
                        @else
                            <x-fa::empty icon="heroicon-o-inbox" title="Keine Signale mit Status {{ $statusLabel }}">
                                Hier landen Signale, die als {{ $statusLabel }} markiert wurden.@if($typGewaehltLabel) Gefiltert auf: {{ $typGewaehltLabel }}.@endif
                            </x-fa::empty>
                        @endif
                    </div>
                @endforelse

                {{ $signale->links('foodalchemist::components.fa.pagination') }}

                {{-- Gedämpfte Lagen (Rausch-Guard): bekannt, bewusst zusammengefasst, Nebensache → eingeklappt. --}}
                @if($gedaempft !== [])
                    <details class="group fa-surface p-4" @if($signalSektionen === []) open @endif data-rq-gedaempft>
                        <summary class="flex items-center gap-2 cursor-pointer select-none text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">
                            @svg('heroicon-m-chevron-right', 'w-4 h-4 text-[var(--fa-ink-3)] transition-transform group-open:rotate-90')
                            Bekannte Lagen
                            <span class="text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)] tabular-nums">{{ count($gedaempft) }}</span>
                            <span class="text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)]">zusammengefasst, weil gedämpft oder akzeptiert</span>
                        </summary>
                        <div class="mt-3 flex flex-col divide-y divide-[var(--fa-line)]">
                            @foreach($gedaempft as $z)
                                <div wire:key="sigzustand-{{ $z['type'] }}" class="flex items-center gap-3 py-2.5">
                                    @svg($z['icon'], 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $z['label'] }}</p>
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $z['hinweis'] }}@if($z['note']). {{ $z['note'] }}@endif</p>
                                    </div>
                                    @if($z['state'] === 'stumm')
                                        <x-fa::badge>Stumm</x-fa::badge>
                                    @elseif($z['state'] === 'akzeptiert')
                                        <x-fa::badge tone="ok">Akzeptiert</x-fa::badge>
                                    @endif
                                    @if(($z['delta'] ?? 0) > 0)
                                        <x-fa::badge tone="crit" class="tabular-nums" title="Seit der letzten Prüfung dazugekommen">+{{ $z['delta'] }}</x-fa::badge>
                                    @endif
                                    <x-fa::button size="sm" wire:click="setSignalTyp('{{ $z['type'] }}')">{{ $z['count'] }} ansehen</x-fa::button>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        @endif

        {{-- ════════════════════════ ZUM BESTÄTIGEN ════════════════════════
             KI-Werte (Bulk) + Lieferantenartikel-Zuordnungen: beides Annehmen/Verwerfen-Listen. --}}
        @if($tab === 'vorschlaege')
            <x-fa::section title="Werte von der KI" icon="heroicon-o-sparkles" :meta="number_format($bulkZahl, 0, ',', '.') . ' offen'"
                           description="Übernehmen schreibt den vorgeschlagenen Wert ins Rezept." data-rq-bulks>
                <div class="flex flex-col divide-y divide-[var(--fa-line)] -mx-4">
                    @forelse($bulks as $b)
                        <div class="flex flex-wrap sm:flex-nowrap items-center gap-3 px-4 py-2.5" wire:key="rqb-{{ $b->id }}">
                            <div class="min-w-0 flex-1">
                                <button type="button" wire:click="$dispatch('{{ $b->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $b->rezept_id }} })"
                                        class="max-w-full truncate text-left text-[length:var(--fa-text-md)] font-medium text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:underline" title="{{ $b->rezept_name }} öffnen">{{ $b->rezept_name }}</button>
                                <p class="flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] min-w-0">
                                    <x-fa::badge tone="info" class="shrink-0">{{ $b->field }}</x-fa::badge>
                                    <span class="truncate" title="{{ trim((string) $b->value, '"') }}">{{ \Illuminate\Support\Str::limit(trim((string) $b->value, '"'), 90) }}</span>
                                    @if($b->confidence !== null)<span class="shrink-0 text-[var(--fa-ink-3)] tabular-nums" title="Sicherheit der KI">{{ round($b->confidence * 100) }} %</span>@endif
                                </p>
                            </div>
                            <div class="shrink-0 flex items-center gap-1.5 ml-auto">
                                <x-fa::button variant="ghost" size="sm" wire:click="bulkVerwerfen({{ $b->id }})" data-rq-bulk-nein>Verwerfen</x-fa::button>
                                <x-fa::button size="sm" icon="heroicon-m-check" wire:click="bulkUebernehmen({{ $b->id }})" data-rq-bulk-ok>Übernehmen</x-fa::button>
                            </div>
                        </div>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-sparkles" title="Keine offenen KI-Werte">Vorschläge entstehen, wenn Rezepte im Rezept-Browser per KI angereichert werden.</x-fa::empty>
                    @endforelse
                </div>
            </x-fa::section>

            <x-fa::section title="Zuordnungen Lieferantenartikel zu Grundprodukt" icon="heroicon-o-link" :meta="number_format($matchZahl, 0, ',', '.') . ' offen'"
                           description="Übernehmen verknüpft den Artikel mit dem Grundprodukt. Gezeigt werden die 50 sichersten." data-rq-matches>
                <div class="flex flex-col divide-y divide-[var(--fa-line)] -mx-4">
                    @forelse($matches as $m)
                        <div class="flex flex-wrap sm:flex-nowrap items-center gap-3 px-4 py-2.5" wire:key="rqm-{{ $m->id }}">
                            <x-fa::badge :tone="$m->score >= 0.9 ? 'ok' : 'warn'" class="shrink-0 w-[52px] justify-center tabular-nums" title="Übereinstimmung">{{ round($m->score * 100) }} %</x-fa::badge>
                            <div class="min-w-0 flex-1 flex items-center gap-2 text-[length:var(--fa-text-md)]">
                                <span class="min-w-0 truncate text-[var(--fa-ink-2)]" title="{{ $m->la_name }}">{{ $m->la_name }}</span>
                                @svg('heroicon-m-arrow-right', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                <span class="min-w-0 truncate font-medium text-[var(--fa-ink)]" title="{{ $m->gp_name }}">{{ $m->gp_name }}</span>
                                <span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" title="Wie die Zuordnung gefunden wurde">{{ $m->methode }}</span>
                            </div>
                            <div class="shrink-0 flex items-center gap-1.5 ml-auto">
                                <x-fa::button variant="ghost" size="sm" wire:click="matchVerwerfen({{ $m->id }})" data-rq-match-nein>Verwerfen</x-fa::button>
                                <x-fa::button size="sm" icon="heroicon-m-check" wire:click="matchUebernehmen({{ $m->id }})" data-rq-match-ok>Übernehmen</x-fa::button>
                            </div>
                        </div>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-link" title="Keine offenen Zuordnungen">Vorschläge entstehen beim Import neuer Lieferantenartikel.</x-fa::empty>
                    @endforelse
                </div>
            </x-fa::section>
        @endif

        {{-- ════════════════════════ NACHARBEIT ════════════════════════ --}}
        @if($tab === 'pflege')
            <div class="grid md:grid-cols-3 gap-3">
                @foreach($pflegeSpalten as $sp)
                    <x-fa::section :title="$sp['titel']" :icon="$sp['icon']" :description="$sp['hint']" data-rq-pflege="{{ $sp['marker'] }}">
                        <x-slot:actions>
                            <x-fa::badge :tone="$sp['zahl'] > 0 ? $sp['ton'] : 'neutral'" class="tabular-nums">{{ number_format($sp['zahl'], 0, ',', '.') }}</x-fa::badge>
                        </x-slot:actions>
                        <div class="flex flex-col -mx-2">
                            @forelse($sp['items'] as $r)
                                <button type="button" wire:key="rqp-{{ $sp['marker'] }}-{{ $r->id }}"
                                        wire:click="$dispatch('{{ ($r->is_sales_recipe ?? true) ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $r->id }} })"
                                        class="flex w-full items-center justify-between gap-2 text-left rounded-[var(--fa-radius-control)] px-2 py-1.5 hover:bg-[var(--fa-hover)] transition-colors">
                                    <span class="min-w-0 truncate text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $r->name }}">{{ $r->name }}</span>
                                    @if($sp['suffix'])<span class="shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-crit)] tabular-nums" title="Zutaten ohne Grundprodukt">{{ $r->n_ingredients_unmapped }}</span>@endif
                                </button>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-check-badge" title="Nichts offen" />
                            @endforelse
                        </div>
                    </x-fa::section>
                @endforeach
            </div>

            {{-- Begriffe lehren (E7-c, #507): wirkt sofort im nächsten Zuordnen. --}}
            <x-fa::section title="Begriffe lehren" icon="heroicon-o-academic-cap"
                           description="Passt eine Zuordnung nur wegen eines anderen Worts nicht, oder verwechselt sie zwei Produkte? Hier lehren. Wirkt sofort beim nächsten Zuordnen." data-rq-terminologie>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-fa::field label="Gleiche Bedeutung" for="rq-term-alias" hint="Mindestens zwei Begriffe, durch Komma getrennt.">
                        <div class="flex items-center gap-2">
                            <x-fa::input id="rq-term-alias" wire:model="termAlias" wire:keydown.enter="terminologieAlias" placeholder="Paradeiser, Tomate" class="min-w-0 flex-1" data-rq-term-alias-input />
                            <x-fa::button icon="heroicon-m-check" wire:click="terminologieAlias" wire:loading.attr="disabled" wire:target="terminologieAlias" data-rq-term-alias-save>Begriffe verknüpfen</x-fa::button>
                        </div>
                    </x-fa::field>

                    <x-fa::field label="Verwechslung sperren" hint="Wenn der Suchbegriff vorkommt, nie den gesperrten Begriff zuordnen. Ausnahme optional.">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::input wire:model="termTrigger" placeholder="Brie" class="w-24" aria-label="Suchbegriff" title="Suchbegriff" />
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">nie</span>
                            <x-fa::input wire:model="termForbid" placeholder="Bries" class="w-24" aria-label="Gesperrter Begriff" title="Gesperrter Begriff" />
                            <x-fa::input wire:model="termUnless" placeholder="außer bei …" class="min-w-0 flex-1" aria-label="Ausnahme (optional)" title="Ausnahme (optional)" />
                            <x-fa::button variant="danger" icon="heroicon-m-no-symbol" wire:click="terminologieAntiMarker" wire:loading.attr="disabled" wire:target="terminologieAntiMarker" data-rq-term-anti-save>Sperre anlegen</x-fa::button>
                        </div>
                    </x-fa::field>
                </div>
            </x-fa::section>
        @endif
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
