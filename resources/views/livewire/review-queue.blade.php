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
            <x-slot:end>
                <button type="button" wire:click="detektorLaufen" wire:target="detektorLaufen" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 bg-white/60 border border-black/5 hover:bg-white/90 hover:text-gray-900 transition-all disabled:opacity-60"
                        title="Detektor jetzt laufen lassen">
                    <span wire:loading.remove wire:target="detektorLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5') Prüfen</span>
                    <span wire:loading wire:target="detektorLaufen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin') Prüfe …</span>
                </button>
            </x-slot:end>
        </x-ui-page-actionbar>
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

        {{-- ── Tab-Leiste (Segmented, house-style) ──────────────────────────── --}}
        @php
            $pflegeGesamt = $vkOhneKlasse->count() + $imReviewZahl + $ungemapptZahl;
            $tabs = [
                ['key' => 'ueberblick', 'label' => 'Überblick', 'icon' => 'heroicon-o-squares-2x2', 'count' => null],
                ['key' => 'signale', 'label' => 'Signale', 'icon' => 'heroicon-o-bell-alert', 'count' => $signalOffen],
                ['key' => 'ki', 'label' => 'KI-Vorschläge', 'icon' => 'heroicon-o-sparkles', 'count' => $bulkZahl],
                ['key' => 'matches', 'label' => 'Matches & Terminologie', 'icon' => 'heroicon-o-link', 'count' => $matchZahl],
                ['key' => 'pflege', 'label' => 'Pflege', 'icon' => 'heroicon-o-wrench-screwdriver', 'count' => $pflegeGesamt],
            ];
        @endphp
        <div class="flex flex-wrap items-center gap-0.5 p-1 rounded-xl bg-black/[0.03] w-fit" data-rq-tabs>
            @foreach($tabs as $t)
                <button type="button" wire:key="tab-{{ $t['key'] }}" wire:click="setTab('{{ $t['key'] }}')"
                        data-rq-tab="{{ $t['key'] }}"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all {{ $tab === $t['key'] ? 'bg-white shadow-sm font-medium text-violet-700' : 'text-gray-500 hover:text-gray-900' }}">
                    @svg($t['icon'], 'w-3.5 h-3.5 '.($tab === $t['key'] ? 'text-violet-600' : 'text-gray-400'))
                    {{ $t['label'] }}
                    @if($t['count'] !== null && $t['count'] > 0)
                        <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-semibold {{ $tab === $t['key'] ? 'bg-violet-500/15 text-violet-700' : 'bg-black/[0.06] text-gray-500' }}">{{ number_format($t['count'], 0, ',', '.') }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- ════════════════════════ TAB: ÜBERBLICK ════════════════════════ --}}
        @if($tab === 'ueberblick')
            @php
                $sevK = $severitySplit['kritisch'] ?? 0;
                $sevW = $severitySplit['warnung'] ?? 0;
                $mut = 'bg-black/5 text-gray-400';
                $kacheln = [
                    ['icon' => 'heroicon-o-bell-alert', 'label' => 'Signale offen', 'wert' => $signalOffen, 'hint' => $sevK.' kritisch · '.$sevW.' Warnung', 'tab' => 'signale', 'tint' => $sevK > 0 ? 'bg-rose-500/10 text-rose-600' : ($signalOffen > 0 ? 'bg-amber-500/10 text-amber-600' : $mut), 'farbe' => $sevK > 0 ? 'text-rose-600' : ($signalOffen > 0 ? 'text-gray-900' : 'text-gray-300')],
                    ['icon' => 'heroicon-o-sparkles', 'label' => 'KI-Vorschläge', 'wert' => $bulkZahl, 'hint' => 'Bulk-Anreicherung', 'tab' => 'ki', 'tint' => $bulkZahl > 0 ? 'bg-amber-500/10 text-amber-600' : $mut, 'farbe' => $bulkZahl > 0 ? 'text-gray-900' : 'text-gray-300'],
                    ['icon' => 'heroicon-o-link', 'label' => 'LA → GP Matches', 'wert' => $matchZahl, 'hint' => 'offene Vorschläge', 'tab' => 'matches', 'tint' => $matchZahl > 0 ? 'bg-amber-500/10 text-amber-600' : $mut, 'farbe' => $matchZahl > 0 ? 'text-gray-900' : 'text-gray-300'],
                    ['icon' => 'heroicon-o-tag', 'label' => 'VK ohne Klasse', 'wert' => $vkOhneKlasse->count(), 'hint' => 'V-22-Gate', 'tab' => 'pflege', 'tint' => $vkOhneKlasse->count() > 0 ? 'bg-amber-500/10 text-amber-600' : $mut, 'farbe' => $vkOhneKlasse->count() > 0 ? 'text-gray-900' : 'text-gray-300'],
                    ['icon' => 'heroicon-o-clock', 'label' => 'Im Review', 'wert' => $imReviewZahl, 'hint' => 'freigeben / zurück', 'tab' => 'pflege', 'tint' => $imReviewZahl > 0 ? 'bg-amber-500/10 text-amber-600' : $mut, 'farbe' => $imReviewZahl > 0 ? 'text-gray-900' : 'text-gray-300'],
                    ['icon' => 'heroicon-o-question-mark-circle', 'label' => 'Ungemappte Zutaten', 'wert' => $ungemapptZahl, 'hint' => 'Allergene unbekannt', 'tab' => 'pflege', 'tint' => $ungemapptZahl > 0 ? 'bg-rose-500/10 text-rose-600' : $mut, 'farbe' => $ungemapptZahl > 0 ? 'text-rose-600' : 'text-gray-300'],
                ];
            @endphp
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3" data-rq-kpi>
                @foreach($kacheln as $k)
                    <button type="button" wire:key="kpi-{{ $loop->index }}" wire:click="setTab('{{ $k['tab'] }}')"
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
                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            @svg('heroicon-o-check-badge', 'w-8 h-8 text-emerald-400/70')
                            <p class="text-xs text-gray-500 mt-2">Keine offenen Signale — alles sauber.</p>
                        </div>
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

                <div class="space-y-0.5">
                    @forelse($signale as $sig)
                        @include('foodalchemist::livewire.partials._signal-row', ['sig' => $sig])
                    @empty
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            @svg('heroicon-o-check-badge', 'w-9 h-9 text-emerald-400/70')
                            <p class="text-xs text-gray-500 mt-2">Keine Signale ({{ $signalStatus }}).</p>
                        </div>
                    @endforelse
                </div>
                <div class="mt-3">{{ $signale->links() }}</div>
            </div>
        @endif

        {{-- ════════════════════════ TAB: KI-VORSCHLÄGE ════════════════════════ --}}
        @if($tab === 'ki')
            <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-bulks>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="grid place-items-center w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600">@svg('heroicon-o-sparkles', 'w-4 h-4')</span>
                    <div>
                        <h3 class="font-medium tracking-tight text-gray-900">KI-Vorschläge <span class="text-gray-400 font-normal">(Bulk-Anreicherung)</span></h3>
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
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            @svg('heroicon-o-sparkles', 'w-9 h-9 text-gray-300')
                            <p class="text-xs text-gray-500 mt-2">Keine offenen KI-Vorschläge.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- ════════════════════════ TAB: MATCHES & TERMINOLOGIE ════════════════════════ --}}
        @if($tab === 'matches')
            {{-- LA→GP-Match-Vorschläge (M3-11, tentative Queue) --}}
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
                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            @svg('heroicon-o-link', 'w-8 h-8 text-gray-300')
                            <p class="text-xs text-gray-500 mt-2">Keine offenen Match-Vorschläge.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Terminologie lernen (E7-c, #507) — wirkt sofort im Matching (kein Deploy) --}}
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
        @endif
    </x-ui-page-container>
</x-ui-page>
