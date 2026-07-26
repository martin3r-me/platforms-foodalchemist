{{-- Spec 21 · Tranche P (S3a) — Signal-Panel: betroffene Objekte (volle Liste, sortierbar)
     + objekt-zentrische Sicht („was hat dieses Rezept noch?"). Design = Cockpit/section
     wie Recipes/Verkauf/Produktion (Detail-Panels v3). Read-only. --}}
{{-- Achtung Blade-Falle: die Kurzform `@php(...)` NICHT vor einem `@php … @endphp`-Block
     verwenden — Blades Raw-Block-Regex greift ab dem ersten `@php` bis zum ersten
     `@endphp` und lässt alles dazwischen unkompiliert (Symptom: „unexpected token class").
     Darum steht hier oben der Block, Kurzformen erst danach. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $sevMap = [
        'kritisch' => ['tint' => 'bg-rose-500/10 text-rose-600', 'variant' => 'danger'],
        'warnung' => ['tint' => 'bg-amber-500/10 text-amber-600', 'variant' => 'warning'],
        'info' => ['tint' => 'bg-sky-500/10 text-sky-600', 'variant' => 'info'],
    ];
    $sv = $sig !== null ? ($sevMap[$sig->severity->value] ?? $sevMap['info']) : $sevMap['info'];
@endphp

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-signal-panel>
    @if($sig === null)
        <div class="text-center text-xs text-gray-500 py-12">
            <div class="text-2xl mb-2">🔔</div>
            Signal in der Liste auf „Reinschauen" klicken —<br>betroffene Objekte erscheinen hier.
        </div>
    @else
        {{-- Kopf --}}
        <div>
            <div class="flex items-start gap-2.5">
                <span class="shrink-0 grid place-items-center w-9 h-9 rounded-xl {{ $sv['tint'] }}" title="{{ $sig->severity->label() }}">
                    @svg($sig->type->icon(), 'w-[18px] h-[18px]')
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-[15px] font-semibold tracking-tight text-gray-900 leading-snug">{{ $sig->title }}</h3>
                    <p class="text-[11px] text-gray-500 mt-1">
                        {{ $sig->type->label() }} ·
                        <span class="{{ $pill }} {{ $variantPill[$sv['variant']] }}">{{ $sig->severity->label() }}</span>
                        @if(! $sig->status->istOffen())
                            <span class="{{ $pill }} {{ $variantPill[$sig->status->badgeVariant()] }}">{{ $sig->status->label() }}</span>
                        @endif
                    </p>
                </div>
            </div>
            @if($sig->description)
                <p class="text-[12px] leading-relaxed text-gray-600 mt-2">{{ $sig->description }}</p>
            @endif
            <p class="text-[10px] text-gray-400 mt-1.5">
                {{ $sig->source }} · erkannt {{ $sig->created_at?->format('d.m.Y H:i') }}
            </p>
        </div>

        {{-- KI-Plan als Erklärtext (der Knopf bleibt in der Signal-Zeile — eine Wahrheit) --}}
        @if($plan !== null && $sig->status->istOffen())
            <div class="rounded-xl border border-violet-500/20 bg-violet-500/[0.04] px-3.5 py-2.5">
                <div class="flex items-center gap-1.5 mb-1">
                    @svg($plan['kind'] === 'deterministic' ? 'heroicon-o-bolt' : 'heroicon-o-sparkles', 'w-3.5 h-3.5 text-violet-500')
                    <span class="text-[11px] font-medium text-gray-700">{{ $plan['flavorLabel'] }}</span>
                </div>
                <p class="text-[11px] leading-relaxed text-gray-600">{{ $plan['plan'] }}</p>
            </div>
        @endif

        {{-- Rückmeldungen der S3b-Aktionen (Dry-Run / Teil-Fix) --}}
        @if($meldung)
            <div class="rounded-lg bg-emerald-500/10 text-emerald-700 text-[11px] px-3 py-2" data-signal-meldung>{{ $meldung }}</div>
        @endif
        @if($fehler)
            <div class="rounded-lg bg-rose-500/10 text-rose-700 text-[11px] px-3 py-2" data-signal-fehler>{{ $fehler }}</div>
        @endif

        {{-- ── Punkt 1: betroffene Objekte, volle Liste + Sortierung ──────────
             Meta-Text vorab in eine Variable: verschachtelte Quotes in einem
             Komponenten-Attribut lassen den Blade-ComponentTagCompiler auflaufen. --}}
        @php($metaZahl = $betroffen ? number_format($betroffen['total'], 0, ',', '.') : null)
        <x-foodalchemist::section title="Betroffene Objekte" icon="heroicon-o-queue-list" :meta="$metaZahl">
            <x-slot:actions>
                <div class="inline-flex items-center gap-0.5 p-0.5 rounded-lg bg-black/[0.04]">
                    @foreach(['name' => 'A–Z', 'name_desc' => 'Z–A', 'art' => 'Art'] as $wert => $lbl)
                        <button type="button" wire:key="sigsort-{{ $wert }}" wire:click="setSort('{{ $wert }}')"
                                class="px-2 py-0.5 rounded-md text-[10px] transition-all {{ $sort === $wert ? 'bg-white shadow-sm font-medium text-violet-700' : 'text-gray-500 hover:text-gray-800' }}"
                                data-signal-sort="{{ $wert }}">{{ $lbl }}</button>
                    @endforeach
                </div>
            </x-slot:actions>

            @if($betroffen && count($betroffen['items']))
                @php($teilBulk = $plan !== null && $plan['kind'] === 'deterministic' && $sig->status->istOffen())
                <div class="space-y-0.5">
                    @foreach($betroffen['items'] as $it)
                        @php($istGewaehlt = $objektKind === $it['kind'] && $objektId === $it['id'])
                        <div wire:key="sigobj-{{ $it['kind'] }}-{{ $it['id'] }}-{{ $loop->index }}"
                             class="rounded-lg {{ $istGewaehlt ? 'bg-violet-500/[0.06]' : 'hover:bg-black/[0.03]' }} transition-colors">
                            <div class="flex items-center gap-1.5 px-1.5 py-1">
                                @if($teilBulk && in_array($it['kind'], ['recipe', 'gp'], true))
                                    <input type="checkbox" wire:model.live="auswahl" value="{{ $it['id'] }}"
                                           class="shrink-0 w-3.5 h-3.5 rounded border-gray-300 text-violet-600 focus:ring-violet-500"
                                           title="Für Teil-Fix auswählen" data-signal-pick="{{ $it['id'] }}">
                                @endif
                                @if($it['kind'] === 'recipe')
                                    <button type="button"
                                            wire:click="$dispatch('{{ $it['is_sales_recipe'] ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $it['id'] }} })"
                                            class="min-w-0 flex-1 flex items-center gap-1.5 text-left text-[11px] text-sky-600 hover:text-sky-700 hover:underline"
                                            title="{{ $it['is_sales_recipe'] ? 'Verkaufsgericht' : 'Basisrezept' }} öffnen">
                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </button>
                                @elseif($it['kind'] === 'gp')
                                    <a href="{{ route('foodalchemist.gps.index', ['gp' => $it['id']]) }}" wire:navigate
                                       class="min-w-0 flex-1 flex items-center gap-1.5 text-[11px] text-violet-600 hover:text-violet-700 hover:underline" title="Grundprodukt öffnen">
                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @else
                                    <span class="min-w-0 flex-1 truncate text-[11px] text-gray-600">{{ $it['name'] }}</span>
                                @endif

                                @if(in_array($it['kind'], ['recipe', 'gp'], true))
                                    <button type="button" wire:click="objektWaehlen('{{ $it['kind'] }}', {{ $it['id'] }})"
                                            class="shrink-0 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] text-gray-400 hover:text-violet-700 hover:bg-violet-500/[0.08] transition-colors {{ $istGewaehlt ? 'text-violet-700 bg-violet-500/[0.08]' : '' }}"
                                            title="Alle offenen Signale an diesem Objekt"
                                            data-signal-objekt="{{ $it['kind'] }}-{{ $it['id'] }}">
                                        @svg('heroicon-o-bell-alert', 'w-3 h-3') was noch?
                                    </button>
                                @endif
                            </div>

                            {{-- ── Punkt 2: objekt-zentrische Sicht ───────────────── --}}
                            @if($istGewaehlt)
                                <div class="mx-1.5 mb-1.5 rounded-lg border border-violet-500/15 bg-white/60 px-2.5 py-2" data-signal-objekt-sicht>
                                    <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 mb-1.5">
                                        Offene Signale an diesem Objekt
                                    </p>
                                    @if(count($objektSignale) <= 1)
                                        <p class="text-[11px] text-gray-500">Nur dieses Signal — einmal fixen genügt.</p>
                                    @else
                                        <div class="space-y-0.5">
                                            @foreach($objektSignale as $os)
                                                @php($osSv = $sevMap[$os['severity']] ?? $sevMap['info'])
                                                <button type="button" wire:key="objsig-{{ $os['id'] }}" wire:click="signalOeffnen({{ $os['id'] }})"
                                                        class="w-full flex items-center gap-1.5 text-left rounded-md px-1.5 py-1 transition-colors {{ $os['id'] === $sig->id ? 'bg-black/[0.05]' : 'hover:bg-black/[0.03]' }}">
                                                    <span class="shrink-0 grid place-items-center w-5 h-5 rounded-md {{ $osSv['tint'] }}">@svg($os['icon'], 'w-3 h-3')</span>
                                                    <span class="min-w-0 flex-1 truncate text-[11px] {{ $os['id'] === $sig->id ? 'font-medium text-gray-800' : 'text-gray-600' }}">{{ $os['label'] }}</span>
                                                    @if($os['hat_ki'])<span class="shrink-0 text-[9px] text-violet-500" title="KI-Schritt vorhanden">✨</span>@endif
                                                    @if($os['id'] === $sig->id)<span class="shrink-0 text-[9px] text-gray-400">hier</span>@endif
                                                </button>
                                            @endforeach
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1.5">
                                            {{ count($objektSignale) }} Befunde am selben Objekt — in einem Durchgang beheben statt {{ count($objektSignale) }}× öffnen.
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if($betroffen['total'] > $betroffen['gezeigt'])
                    <p class="text-[10px] text-gray-400 mt-2">
                        Zeigt {{ number_format($betroffen['gezeigt'], 0, ',', '.') }} von
                        {{ number_format($betroffen['total'], 0, ',', '.') }} (Kappung bei {{ $panelLimit }}) — die übrigen
                        erscheinen, sobald diese behoben sind.
                    </p>
                @endif

                {{-- ── Punkt 7: Teil-Bulk + Punkt 3: Dry-Run davor ───────────── --}}
                @if($teilBulk)
                    <div class="mt-3 pt-2.5 border-t border-black/[0.06] flex flex-wrap items-center gap-1.5" data-signal-bulkbar>
                        <button type="button" wire:click="alleWaehlen"
                                class="px-2 py-0.5 rounded-md text-[10px] text-gray-500 hover:text-gray-800 hover:bg-black/[0.04]">alle</button>
                        <button type="button" wire:click="auswahlLeeren"
                                class="px-2 py-0.5 rounded-md text-[10px] text-gray-500 hover:text-gray-800 hover:bg-black/[0.04]">keine</button>
                        <span class="text-[10px] text-gray-400">{{ count($auswahl) }} gewählt</span>
                        <span class="flex-1"></span>
                        <button type="button" wire:click="vorschauZeigen"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-medium text-gray-700 bg-black/[0.05] hover:bg-black/[0.08]"
                                data-signal-vorschau>
                            @svg('heroicon-o-eye', 'w-3 h-3') Vorschau
                        </button>
                        <button type="button" wire:click="teilFixAusfuehren" @disabled(count($auswahl) === 0)
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-medium text-white bg-violet-600 hover:bg-violet-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                data-signal-teilfix>
                            @svg('heroicon-o-bolt', 'w-3 h-3') diese {{ count($auswahl) ?: '' }} fixen
                        </button>
                    </div>

                    @if($vorschau !== null)
                        <div class="mt-2 rounded-xl border border-violet-500/20 bg-white/70 px-3 py-2.5" data-signal-dryrun>
                            <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 mb-1">
                                Fix-Vorschau ({{ $vorschau['scope'] === 'teilmenge' ? 'Auswahl' : 'ganzer Satz' }})
                            </p>
                            <p class="text-[11px] text-gray-600 mb-2">
                                {{ number_format($vorschau['total'], 0, ',', '.') }} betroffen ·
                                geprüft {{ $vorschau['gezeigt'] }} ·
                                <span class="text-emerald-700">{{ $vorschau['wirkt'] }} würden geändert</span>
                                @if($vorschau['wirkt_nicht'] > 0)
                                    · <span class="text-gray-500">{{ $vorschau['wirkt_nicht'] }} bleiben unberührt</span>
                                @endif
                            </p>
                            <div class="space-y-1">
                                @foreach($vorschau['items'] as $vi)
                                    <div wire:key="dry-{{ $vi['kind'] }}-{{ $vi['id'] }}"
                                         class="rounded-lg px-2 py-1.5 {{ $vi['wirkt'] ? 'bg-emerald-500/[0.06]' : 'bg-black/[0.03]' }}">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-[10px] {{ $vi['wirkt'] ? 'text-emerald-600' : 'text-gray-400' }}">{{ $vi['wirkt'] ? '✓' : '–' }}</span>
                                            <span class="min-w-0 flex-1 truncate text-[11px] font-medium text-gray-700">{{ $vi['name'] }}</span>
                                        </div>
                                        @foreach($vi['felder'] as $feld => $wert)
                                            <p class="text-[10px] text-gray-600 ml-4 font-mono">{{ $feld }}: {{ $wert }}</p>
                                        @endforeach
                                        @if($vi['hinweis'])
                                            <p class="text-[10px] text-gray-500 ml-4 italic">{{ $vi['hinweis'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if($vorschau['total'] > $vorschau['gezeigt'])
                                <p class="text-[10px] text-gray-400 mt-1.5">
                                    Aufgeschlüsselt sind die ersten {{ $vorschau['gezeigt'] }} — die Zähler oben gelten für
                                    genau diese, nicht für alle {{ number_format($vorschau['total'], 0, ',', '.') }}.
                                </p>
                            @endif
                        </div>
                    @endif
                @endif
            @elseif($betroffen)
                <p class="text-[11px] text-gray-500">
                    Für diesen Signaltyp gibt es keine Einzelaufstellung — der Befund ist aggregiert
                    @if($betroffen['total'] > 0)({{ number_format($betroffen['total'], 0, ',', '.') }} Objekte laut Payload)@endif.
                </p>
            @endif
        </x-foodalchemist::section>
    @endif
</div>
