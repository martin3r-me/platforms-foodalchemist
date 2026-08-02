{{-- M9-03 / V-10 → Cockpit: „Signale" als Tab-Cockpit. Ein Ort, an dem alles
     zusammenläuft und von dem aus gesteuert wird. Reine Darstellung/Steuerung — die
     Detektor-/Service-Logik bleibt unangetastet (Aktionen laufen über die bestehenden
     Services, eine Regel-Stelle). --}}
@php extract(\Platform\FoodAlchemist\Support\Ui::maps()); @endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Signale" icon="heroicon-o-bell-alert" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Signale'],
        ]">
            {{-- Kein `<x-slot:end>` mehr: der Slot rendert auf demo nicht (Core-Komponente),
                 der „Prüfen"-Knopf lag hier seit a372369 (2026-06-17) unerreichbar. Die
                 Lauf-Knöpfe stehen jetzt über der Tab-Leiste im Seitenkörper. --}}
        </x-ui-page-actionbar>
    </x-slot>

    {{-- Spec 21 · S3a: die rechte Fläche war die einzige der sieben Cockpit-Seiten ohne
         Panel. Öffnen über „Reinschauen" in der Signal-Zeile (Event `signal-selected`). --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Signal-Detail" width="w-96" :maxWidth="640"
                                        scope="activity_signale" side="right" icon="heroicon-o-bell-alert" :defaultOpen="true">
            <livewire:foodalchemist.signale.detail-panel />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- Klick-Ziele der Rezept-Listen (Signale/KI/Pflege-Tabs) --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">
        @if($meldung !== null)
            <div class="flex items-center gap-2 text-xs text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-3 py-2" data-rq-meldung>
                @svg('heroicon-o-check-circle', 'w-4 h-4') {{ $meldung }}
            </div>
        @endif
        @if($fehler !== null)
            <div class="flex items-center gap-2 text-xs text-rose-700 bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2" data-rq-fehler>
                @svg('heroicon-o-exclamation-triangle', 'w-4 h-4') {{ $fehler }}
            </div>
        @endif

        {{-- ── Lauf-Knöpfe (eigene Zeile, rechtsbündig) ──────────────────────
             Über der Tab-Leiste, weil die geteilte `editor-tabs`-Leiste volle Breite
             (`-mx-6 px-6`, sticky) einnimmt und sich die Zeile nicht mehr teilt.
             Die beiden Knöpfe standen bis 2026-07-28 im `<x-slot:end>` des Core-
             `x-ui-page-actionbar` — dessen Inhalt rendert auf demo NICHT (dieselbe Ursache
             liess auf der Wissens-Seite „+ Neues Wissen" verschwinden). Ein Feature-Eingang,
             den niemand anklicken kann, ist kein Feature: darum leben sie hier, in unserem
             eigenen Markup. Der Core-Slot bleibt für Martin zu reparieren. Getrennt, weil
             links (Ampel) gratis ist und rechts (KI-Befunde) Provider-Geld kostet. --}}
        <div class="flex flex-wrap items-center justify-end gap-2" data-rq-laeufe>
                <button type="button" wire:click="detektorLaufen" wire:target="detektorLaufen" wire:loading.attr="disabled"
                        data-rq-ampel
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 bg-white/60 border border-black/5 hover:bg-white/90 hover:text-gray-900 transition-all disabled:opacity-60"
                        title="Detektoren, Datenqualitäts-Kaskade, Zeitreihen-Snapshot und Drift neu rechnen. Deterministisch, kostet nichts, läuft im Hintergrund.">
                    <span wire:loading.remove wire:target="detektorLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5') Ampel neu messen</span>
                    <span wire:loading wire:target="detektorLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin') Reihe ein …</span>
                </button>

                <div class="inline-flex items-center gap-1.5 pl-2 pr-1 py-1 rounded-lg bg-white/60 border border-black/5">
                    <button type="button" wire:click="befundeLaufen" wire:target="befundeLaufen" wire:loading.attr="disabled"
                            data-rq-befunde
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-violet-700 hover:text-violet-900 transition-all disabled:opacity-60"
                            title="Rezept-Copilot über die fälligen Rezepte laufen lassen. Ruft das Modell PRO Rezept — das Limit rechts ist die Kostenbremse.">
                        <span wire:loading.remove wire:target="befundeLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Befunde sammeln</span>
                        <span wire:loading wire:target="befundeLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 animate-spin') Reihe ein …</span>
                    </button>
                    <input type="number" wire:model="befundeLimit" min="1" max="{{ \Platform\FoodAlchemist\Services\RecipeFindingsBatchService::MAX_LIMIT }}"
                           data-rq-befunde-limit
                           class="w-14 text-xs text-right bg-transparent border-0 focus:ring-0 text-gray-600 tabular-nums"
                           title="Höchstens so viele Rezepte je Lauf (Egress-Bremse)." />
                </div>
            </div>
        </div>

        {{-- ── Tab-Leiste (geteilte editor-tabs, Server-Modus) ────────────────
             `marker="rq"` erhält die E2E-Hooks (`data-rq-tabs`, `data-rq-tab="<key>"`).
             `:counts` zeigt die offenen Zähler je Tab (nachgerüstete optionale Prop). --}}
        @php
            $pflegeGesamt = $vkOhneKlasse->count() + $imReviewZahl + $ungemapptZahl;
            $vorschlaegeZahl = $bulkZahl + $matchZahl;
        @endphp
        <x-foodalchemist::editor-tabs action="setTab" :active="$tab" marker="rq"
            :tabs="['ueberblick' => 'Überblick', 'signale' => 'Signale', 'vorschlaege' => 'Vorschläge', 'pflege' => 'Pflege']"
            :counts="['signale' => $signalOffen, 'vorschlaege' => $vorschlaegeZahl, 'pflege' => $pflegeGesamt]" />

        {{-- ════════════════════════ TAB: ÜBERBLICK ════════════════════════ --}}
        @if($tab === 'ueberblick')
            @php
                $sevK = $severitySplit['kritisch'] ?? 0;
                $sevW = $severitySplit['warnung'] ?? 0;
                $mut = 'bg-black/5 text-gray-400';
                // Lagebild = die zwei echten Arbeitsvorräte. Die Pflege-Listen (VK ohne Klasse /
                // Im Review / Ungemappt) sind unten NUR Sprung-Shortcuts — kein zweiter Zähler-Ort,
                // ihre Wahrheit steht im Pflege-Tab. Vorher standen sie hier als eigene KPI-Kacheln
                // und die Zahl las sich doppelt.
                $lage = [
                    ['icon' => 'heroicon-o-bell-alert', 'label' => 'Signale offen', 'wert' => $signalOffen, 'hint' => $sevK.' kritisch · '.$sevW.' Warnung', 'tab' => 'signale', 'tint' => $sevK > 0 ? 'bg-rose-500/10 text-rose-600' : ($signalOffen > 0 ? 'bg-amber-500/10 text-amber-600' : $mut), 'farbe' => $sevK > 0 ? 'text-rose-600' : ($signalOffen > 0 ? 'text-gray-900' : 'text-gray-300')],
                    ['icon' => 'heroicon-o-inbox', 'label' => 'Vorschläge offen', 'wert' => $vorschlaegeZahl, 'hint' => $bulkZahl.' KI-Anreicherung · '.$matchZahl.' Matches', 'tab' => 'vorschlaege', 'tint' => $vorschlaegeZahl > 0 ? 'bg-amber-500/10 text-amber-600' : $mut, 'farbe' => $vorschlaegeZahl > 0 ? 'text-gray-900' : 'text-gray-300'],
                ];
                $pflegeShortcuts = [
                    ['icon' => 'heroicon-o-tag', 'label' => 'VK ohne Klasse', 'wert' => $vkOhneKlasse->count(), 'krit' => false],
                    ['icon' => 'heroicon-o-clock', 'label' => 'Im Review', 'wert' => $imReviewZahl, 'krit' => false],
                    ['icon' => 'heroicon-o-question-mark-circle', 'label' => 'Ungemappt', 'wert' => $ungemapptZahl, 'krit' => true],
                ];
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-rq-kpi>
                @foreach($lage as $k)
                    <button type="button" wire:key="lage-{{ $loop->index }}" wire:click="setTab('{{ $k['tab'] }}')"
                            class="group relative overflow-hidden {{ $card }} px-4 py-3.5 text-left hover:-translate-y-0.5 hover:shadow-md hover:shadow-black/5 transition-all duration-150">
                        <div class="{{ $cardAccent }}"></div>
                        <div class="flex items-center justify-between">
                            <span class="grid place-items-center w-8 h-8 rounded-lg {{ $k['tint'] }}">@svg($k['icon'], 'w-4 h-4')</span>
                            @svg('heroicon-o-arrow-right', 'w-3.5 h-3.5 text-gray-300 group-hover:text-violet-500 transition-colors')
                        </div>
                        <p class="mt-2 text-2xl font-semibold tracking-tight tabular-nums {{ $k['farbe'] }}">{{ number_format($k['wert'], 0, ',', '.') }}</p>
                        <p class="text-[11px] font-medium text-gray-700">{{ $k['label'] }}</p>
                        <p class="text-[10px] text-gray-400 mt-0.5">{{ $k['hint'] }}</p>
                    </button>
                @endforeach
            </div>

            {{-- Pflege-Shortcuts: dieselben Zahlen wie im Pflege-Tab, hier NUR als Sprung. --}}
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-pflege-shortcuts>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center justify-between gap-2 mb-2">
                    <h3 class="text-[11px] font-medium text-gray-500 uppercase tracking-wider">Pflege-Listen</h3>
                    <button type="button" wire:click="setTab('pflege')" class="inline-flex items-center gap-1 text-[11px] font-medium text-gray-500 hover:text-violet-700 transition-colors">Alle @svg('heroicon-o-arrow-right', 'w-3.5 h-3.5')</button>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach($pflegeShortcuts as $sc)
                        <button type="button" wire:key="pfsc-{{ $loop->index }}" wire:click="setTab('pflege')"
                                class="group flex items-center gap-2 rounded-lg px-2.5 py-2 text-left hover:bg-violet-500/[0.05] transition-colors">
                            <span class="shrink-0 grid place-items-center w-7 h-7 rounded-lg {{ $sc['wert'] > 0 ? ($sc['krit'] ? 'bg-rose-500/10 text-rose-600' : 'bg-amber-500/10 text-amber-600') : $mut }}">@svg($sc['icon'], 'w-3.5 h-3.5')</span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold tabular-nums {{ $sc['wert'] > 0 ? ($sc['krit'] ? 'text-rose-600' : 'text-gray-900') : 'text-gray-300' }}">{{ number_format($sc['wert'], 0, ',', '.') }}</span>
                                <span class="block text-[10px] text-gray-500 truncate">{{ $sc['label'] }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Kritischste offene Signale --}}
            <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-kritischste>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div>
                        <h3 class="font-medium tracking-tight text-gray-900">Kritischste Signale</h3>
                        <p class="text-[11px] text-gray-500">Die dringendsten offenen Auffälligkeiten — von hier aus steuern.</p>
                    </div>
                    <button type="button" wire:click="setTab('signale')" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium text-gray-500 hover:text-violet-700 hover:bg-violet-500/[0.06] transition-colors">Alle Signale @svg('heroicon-o-arrow-right', 'w-3.5 h-3.5')</button>
                </div>
                <div class="space-y-0.5">
                    @forelse($kritischste as $sig)
                        @include('foodalchemist::livewire.partials._signal-row', ['sig' => $sig])
                    @empty
                        @include('foodalchemist::livewire.partials._leer-zustand', ['icon' => 'heroicon-o-check-badge', 'text' => 'Keine offenen Signale — alles sauber.', 'ton' => 'gut'])
                    @endforelse
                </div>
            </div>
        @endif

        {{-- ════════════════════════ TAB: SIGNALE ════════════════════════ --}}
        @if($tab === 'signale')
            <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-signale>
                <div class="{{ $cardAccent }}"></div>

                {{-- Toolbar: Status-Segment + getrimmte Typ-Filter --}}
                @php
                    $typenGefiltert = array_values(array_filter($signalTypWerte, fn ($tw) => ($signalNachTyp[$tw['value']] ?? 0) > 0 || $signalTyp === $tw['value']));
                @endphp
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <div class="inline-flex items-center gap-0.5 p-0.5 rounded-lg bg-black/[0.03]">
                        @foreach($signalStatusWerte as $sw)
                            <button type="button" wire:key="sigst-{{ $sw['value'] }}" wire:click="setSignalStatus('{{ $sw['value'] }}')"
                                    class="px-2.5 py-1 rounded-md text-[11px] transition-all {{ $signalStatus === $sw['value'] ? 'bg-white shadow-sm font-medium text-violet-700' : 'text-gray-500 hover:text-gray-800' }}">{{ $sw['label'] }}</button>
                        @endforeach
                    </div>
                    <span class="w-px h-4 bg-black/10"></span>
                    <button type="button" wire:click="setSignalTyp('')"
                            class="px-2.5 py-1 rounded-lg text-[11px] transition-colors {{ $signalTyp === '' ? 'bg-violet-500/10 text-violet-700 font-medium' : 'text-gray-500 hover:bg-black/[0.04]' }}">Alle Typen</button>
                    @foreach($typenGefiltert as $tw)
                        <button type="button" wire:key="sigtyp-{{ $tw['value'] }}" wire:click="setSignalTyp('{{ $tw['value'] }}')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] transition-colors {{ $signalTyp === $tw['value'] ? 'bg-violet-500/10 text-violet-700 font-medium' : 'text-gray-500 hover:bg-black/[0.04]' }}">
                            {{ $tw['label'] }}
                            @if(($signalNachTyp[$tw['value']] ?? 0) > 0)<span class="text-[10px] {{ $signalTyp === $tw['value'] ? 'text-violet-500' : 'text-gray-400' }}">{{ $signalNachTyp[$tw['value']] }}</span>@endif
                        </button>
                    @endforeach
                </div>

                {{-- Zustands-Zeilen (Spec 21 · E2): bekannte Lagen als EINE Zeile statt n Alarmen.
                     Das Kontext-Band bleibt bei jedem Typ-Wechsel stehen (nur `offen`) — vorher
                     verschwand es beim ersten Filter-Klick und die Listen-Semantik kippte still.
                     Der gerade ausgewählte Typ fällt aus dem Band (er ist unten aufgeklappt). --}}
                @php
                    $zustandsZeilen = $signalStatus === 'offen'
                        ? array_values(array_filter($signalZustand ?? [],
                            fn ($z) => ($z['aggregiert'] || $z['state'] === 'frist_abgelaufen') && $z['type'] !== $signalTyp))
                        : [];
                @endphp
                @if($zustandsZeilen !== [])
                    <div class="mb-3 space-y-1">
                        @foreach($zustandsZeilen as $z)
                            @php
                                $ton = match($z['state']) {
                                    'stumm' => 'text-gray-400 bg-black/[0.02]',
                                    'akzeptiert' => 'text-gray-600 bg-emerald-500/[0.05]',
                                    'frist_abgelaufen' => 'text-amber-700 bg-amber-500/[0.07]',
                                    default => 'text-gray-700 bg-black/[0.03]',
                                };
                            @endphp
                            <div wire:key="sigzustand-{{ $z['type'] }}" class="flex items-center gap-2 px-3 py-2 rounded-lg {{ $ton }}">
                                @svg($z['icon'], 'w-4 h-4 shrink-0 opacity-70')
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs font-medium truncate">{{ $z['label'] }}</div>
                                    <div class="text-[11px] opacity-80 truncate">{{ $z['hinweis'] }}@if($z['note']) — {{ $z['note'] }}@endif</div>
                                </div>
                                @if(($z['delta'] ?? 0) > 0)
                                    <span class="shrink-0 text-[10px] px-1.5 py-0.5 rounded-md bg-rose-500/10 text-rose-600">+{{ $z['delta'] }}</span>
                                @endif
                                <button type="button" wire:click="setSignalTyp('{{ $z['type'] }}')"
                                        class="shrink-0 text-[11px] px-2 py-1 rounded-md hover:bg-black/[0.05] transition-colors">{{ $z['count'] }} anzeigen</button>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-0.5">
                    @forelse($signale as $sig)
                        @include('foodalchemist::livewire.partials._signal-row', ['sig' => $sig])
                    @empty
                        @include('foodalchemist::livewire.partials._leer-zustand', ['icon' => 'heroicon-o-check-badge', 'text' => 'Keine Signale ('.$signalStatus.').', 'ton' => 'gut'])
                    @endforelse
                </div>
                <div class="mt-3">{{ $signale->links() }}</div>
            </div>
        @endif

        {{-- ════════════════════════ TAB: VORSCHLÄGE ════════════════════════
             KI-Anreicherung (Bulk) + LA→GP-Matches — beide sind Annehmen/Verwerfen-Queues,
             darum ein Tab mit zwei Sektionen (früher zwei getrennte Tabs). „KI" als Verb steht
             bewusst nur noch hier als Sektions-Titel und am Zeilen-Knopf „KI erledigen lassen". --}}
        @if($tab === 'vorschlaege')
            {{-- Sektion 1 · KI-Anreicherung (Bulk, M7-06) --}}
            <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-bulks>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="grid place-items-center w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600">@svg('heroicon-o-sparkles', 'w-4 h-4')</span>
                    <div>
                        <h3 class="font-medium tracking-tight text-gray-900">KI-Anreicherung <span class="text-gray-400 font-normal">(Bulk)</span></h3>
                        <p class="text-[11px] text-gray-500">{{ number_format($bulkZahl, 0, ',', '.') }} offen · übernehmen schreibt den Wert ins Rezept.</p>
                    </div>
                </div>
                <div class="space-y-0.5">
                    @forelse($bulks as $b)
                        <div class="group flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-black/[0.02] text-[11px] transition-colors" wire:key="rqb-{{ $b->id }}">
                            <button type="button" wire:click="$dispatch('{{ $b->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $b->rezept_id }} })"
                                    class="min-w-0 truncate font-medium text-gray-800 hover:text-violet-700 text-left" title="{{ $b->rezept_name }}">{{ $b->rezept_name }}</button>
                            <span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">{{ $b->field }}</span>
                            <span class="min-w-0 truncate text-gray-500" title="{{ is_string($b->value) ? trim($b->value, '"') : '' }}">{{ \Illuminate\Support\Str::limit(trim((string) $b->value, '"'), 60) }}</span>
                            @if($b->confidence !== null)<span class="shrink-0 text-[10px] font-medium text-gray-400 tabular-nums">{{ round($b->confidence * 100) }} %</span>@endif
                            <span class="ml-auto shrink-0 flex gap-1">
                                <button type="button" wire:click="bulkUebernehmen({{ $b->id }})" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium text-emerald-600 hover:bg-emerald-500/10 transition-colors" data-rq-bulk-ok>@svg('heroicon-o-check', 'w-3.5 h-3.5') Übernehmen</button>
                                <button type="button" wire:click="bulkVerwerfen({{ $b->id }})" class="px-2.5 py-1 rounded-lg text-[11px] font-medium text-gray-400 hover:text-gray-600 hover:bg-black/5 transition-colors" data-rq-bulk-nein>Verwerfen</button>
                            </span>
                        </div>
                    @empty
                        @include('foodalchemist::livewire.partials._leer-zustand', ['icon' => 'heroicon-o-sparkles', 'text' => 'Keine offenen KI-Vorschläge.'])
                    @endforelse
                </div>
            </div>

            {{-- Sektion 2 · LA→GP-Match-Vorschläge (M3-11, tentative Queue) --}}
            <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-matches>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="grid place-items-center w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600">@svg('heroicon-o-link', 'w-4 h-4')</span>
                    <div>
                        <h3 class="font-medium tracking-tight text-gray-900">LA → GP Match-Vorschläge</h3>
                        <p class="text-[11px] text-gray-500">{{ number_format($matchZahl, 0, ',', '.') }} offen · Übernehmen verknüpft das LA mit dem GP (beste 50).</p>
                    </div>
                </div>
                <div class="space-y-0.5">
                    @forelse($matches as $m)
                        <div class="group flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-black/[0.02] text-[11px] transition-colors" wire:key="rqm-{{ $m->id }}">
                            <span class="shrink-0 inline-flex items-center justify-center min-w-[38px] px-1.5 py-0.5 rounded-md text-[10px] font-semibold tabular-nums {{ $m->score >= 0.9 ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ round($m->score * 100) }} %</span>
                            <span class="min-w-0 truncate text-gray-700" title="{{ $m->la_name }}">{{ $m->la_name }}</span>
                            @svg('heroicon-o-arrow-right', 'w-3.5 h-3.5 text-gray-300 shrink-0')
                            <span class="min-w-0 truncate font-medium text-violet-600" title="{{ $m->gp_name }}">{{ $m->gp_name }}</span>
                            <span class="text-[10px] text-gray-400 shrink-0">{{ $m->methode }}</span>
                            <span class="ml-auto shrink-0 flex gap-1">
                                <button type="button" wire:click="matchUebernehmen({{ $m->id }})" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium text-emerald-600 hover:bg-emerald-500/10 transition-colors" data-rq-match-ok>@svg('heroicon-o-check', 'w-3.5 h-3.5') Übernehmen</button>
                                <button type="button" wire:click="matchVerwerfen({{ $m->id }})" class="px-2.5 py-1 rounded-lg text-[11px] font-medium text-gray-400 hover:text-gray-600 hover:bg-black/5 transition-colors" data-rq-match-nein>Verwerfen</button>
                            </span>
                        </div>
                    @empty
                        @include('foodalchemist::livewire.partials._leer-zustand', ['icon' => 'heroicon-o-link', 'text' => 'Keine offenen Match-Vorschläge.'])
                    @endforelse
                </div>
            </div>
        @endif

        {{-- ════════════════════════ TAB: PFLEGE ════════════════════════ --}}
        @if($tab === 'pflege')
            @php
                $pflegeSpalten = [
                    ['icon' => 'heroicon-o-tag', 'titel' => 'VK ohne Klasse', 'zahl' => $vkOhneKlasse->count(), 'variant' => $vkOhneKlasse->isNotEmpty() ? 'warning' : 'secondary', 'hint' => 'V-22-Gate — ✨ Klassifizieren im VK-Panel', 'items' => $vkOhneKlasse, 'suffix' => false],
                    ['icon' => 'heroicon-o-clock', 'titel' => 'Im Review-Status', 'zahl' => $imReviewZahl, 'variant' => $imReviewZahl > 0 ? 'warning' : 'secondary', 'hint' => 'Freigeben oder zurück in den Entwurf (zeigt 50)', 'items' => $imReview, 'suffix' => false],
                    ['icon' => 'heroicon-o-question-mark-circle', 'titel' => 'Ungemappte Zutaten', 'zahl' => $ungemapptZahl, 'variant' => $ungemapptZahl > 0 ? 'danger' : 'secondary', 'hint' => 'F7.1: Allergene unbekannt, bis gemappt (zeigt 50)', 'items' => $ungemappt, 'suffix' => true],
                ];
            @endphp
            <div class="grid md:grid-cols-3 gap-3">
                @foreach($pflegeSpalten as $sp)
                    <div class="relative overflow-hidden {{ $card }} px-4 py-3.5" data-rq-pflege="{{ \Illuminate\Support\Str::slug($sp['titel']) }}">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="grid place-items-center w-7 h-7 rounded-lg {{ $variantPill[$sp['variant']] }}">@svg($sp['icon'], 'w-3.5 h-3.5')</span>
                            <h3 class="text-xs font-medium text-gray-900 flex-1">{{ $sp['titel'] }}</h3>
                            <span class="{{ $pill }} {{ $variantPill[$sp['variant']] }} tabular-nums">{{ number_format($sp['zahl'], 0, ',', '.') }}</span>
                        </div>
                        <p class="text-[10px] text-gray-400 mb-2">{{ $sp['hint'] }}</p>
                        <div class="space-y-0.5">
                            @forelse($sp['items'] as $r)
                                <button type="button" wire:key="rqp-{{ \Illuminate\Support\Str::slug($sp['titel']) }}-{{ $r->id }}"
                                        wire:click="$dispatch('{{ ($r->is_sales_recipe ?? true) ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $r->id }} })"
                                        class="flex w-full items-center justify-between gap-2 text-left rounded-md px-2 py-1 hover:bg-violet-500/[0.05] transition-colors group">
                                    <span class="min-w-0 truncate text-[11px] text-gray-600 group-hover:text-violet-700">{{ $r->name }}</span>
                                    @if($sp['suffix'])<span class="shrink-0 text-[10px] font-medium text-rose-500 tabular-nums">{{ $r->n_ingredients_unmapped }}</span>@endif
                                </button>
                            @empty
                                <p class="text-[11px] text-gray-400 py-2">— keine —</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Terminologie lernen (E7-c, #507) — Config, keine Queue: von „Matches" hierher
                 verschoben. Wirkt sofort im Matching (kein Deploy). --}}
            <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-terminologie>
                <div class="flex items-center gap-2 mb-1">
                    <span class="grid place-items-center w-8 h-8 rounded-lg bg-fuchsia-500/10 text-fuchsia-600">@svg('heroicon-o-academic-cap', 'w-4 h-4')</span>
                    <h3 class="font-medium tracking-tight text-gray-900">Terminologie lernen</h3>
                </div>
                <p class="text-[11px] text-gray-500 mb-3">Passt ein Vorschlag nur wegen eines Synonyms/Dialekts nicht — oder trifft er eine Verwechslung? Hier lehren; wirkt sofort im nächsten Matching (kein Deploy).</p>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-xl bg-black/[0.02] border border-black/5 p-3">
                        <p class="text-[11px] font-medium text-gray-700 mb-1.5">Alias-Gruppe <span class="text-gray-400 font-normal">(Synonyme, kommagetrennt, ≥2)</span></p>
                        <div class="flex items-center gap-2">
                            <input type="text" wire:model="termAlias" wire:keydown.enter="terminologieAlias"
                                   placeholder="paradeiser, tomate" class="min-w-0 flex-1 bg-white/70 border border-black/10 rounded-lg px-2.5 py-1.5 text-[11px] focus:ring-2 focus:ring-violet-500/20 focus:bg-white transition-all" data-rq-term-alias-input>
                            <button type="button" wire:click="terminologieAlias" wire:loading.attr="disabled" wire:target="terminologieAlias"
                                    class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-emerald-600 bg-emerald-500/[0.08] hover:bg-emerald-500/[0.15] transition-colors" data-rq-term-alias-save>@svg('heroicon-o-check', 'w-3.5 h-3.5') Lernen</button>
                        </div>
                    </div>

                    <div class="rounded-xl bg-black/[0.02] border border-black/5 p-3">
                        <p class="text-[11px] font-medium text-gray-700 mb-1.5">Anti-Marker <span class="text-gray-400 font-normal">(Verwechslung sperren)</span></p>
                        <div class="flex items-center gap-1.5">
                            <input type="text" wire:model="termTrigger" placeholder="brie" class="min-w-0 w-16 bg-white/70 border border-black/10 rounded-lg px-2 py-1.5 text-[11px] focus:ring-2 focus:ring-violet-500/20 focus:bg-white transition-all" title="Query-Token">
                            <span class="text-gray-400 text-[11px] shrink-0">↛</span>
                            <input type="text" wire:model="termForbid" placeholder="bries" class="min-w-0 w-16 bg-white/70 border border-black/10 rounded-lg px-2 py-1.5 text-[11px] focus:ring-2 focus:ring-violet-500/20 focus:bg-white transition-all" title="zu sperrendes Kandidaten-Token">
                            <input type="text" wire:model="termUnless" placeholder="außer: bries" class="min-w-0 flex-1 bg-white/70 border border-black/10 rounded-lg px-2 py-1.5 text-[11px] focus:ring-2 focus:ring-violet-500/20 focus:bg-white transition-all" title="Guard-Token (optional)">
                            <button type="button" wire:click="terminologieAntiMarker" wire:loading.attr="disabled" wire:target="terminologieAntiMarker"
                                    class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-rose-600 bg-rose-500/[0.08] hover:bg-rose-500/[0.15] transition-colors" data-rq-term-anti-save>@svg('heroicon-o-no-symbol', 'w-3.5 h-3.5') Sperren</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
