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
    // S3b-2 · Punkt 8: Ton der Zustands-Zeile. Steht bewusst HIER oben im einen Block —
    // ein zweiter Roh-Block weiter unten würde ab der ersten Kurzform greifen und die
    // halbe View unkompiliert lassen (s. Warnung oben). Und: NIE die Roh-Block-Direktiven
    // in einem Kommentar hier drin nennen — der Schluss-Tag würde den Block früh beenden.
    $polTon = match ($policy['state'] ?? 'alarm') {
        'stumm' => 'bg-black/[0.03] text-gray-500',
        'akzeptiert' => 'bg-emerald-500/[0.07] text-emerald-700',
        'frist_abgelaufen' => 'bg-amber-500/[0.09] text-amber-700',
        default => 'bg-black/[0.03] text-gray-600',
    };
@endphp

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-signal-panel>
    @if($sig === null)
        <div class="text-center text-xs text-gray-500 py-12">
            <div class="text-2xl mb-2">@svg('heroicon-o-bell-alert', 'w-3.5 h-3.5 inline-block align-middle')</div>
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

        {{-- Plan als Erklärtext (der Knopf bleibt in der Signal-Zeile — eine Wahrheit).
             22·H4b/V-033: drei Lagen, drei Ausgaben — Auto-Fix/KI-Assistenz (violett, Knopf
             in der Zeile), `navigate` (sachlich, KEIN Knopf: der Mensch geht selbst hin) und
             „kein Weg" mit ausdrücklicher Begründung. Vorher war Letzteres ein leerer Bereich
             und damit von „hier hat nur niemand nachgedacht" nicht zu unterscheiden. --}}
        @php($istWeg = $plan !== null && $plan['kind'] === 'navigate')
        @if($plan !== null && $sig->status->istOffen())
            <div class="rounded-xl border px-3.5 py-2.5 {{ $istWeg ? 'border-sky-500/20 bg-sky-500/[0.04]' : 'border-violet-500/20 bg-violet-500/[0.04]' }}">
                <div class="flex items-center gap-1.5 mb-1">
                    @if($istWeg)
                        @svg('heroicon-o-map-pin', 'w-3.5 h-3.5 text-sky-500')
                    @else
                        @svg($plan['kind'] === 'deterministic' ? 'heroicon-o-bolt' : 'heroicon-o-sparkles', 'w-3.5 h-3.5 text-violet-500')
                    @endif
                    <span class="text-[11px] font-medium text-gray-700">{{ $plan['flavorLabel'] }}</span>
                </div>
                <p class="text-[11px] leading-relaxed text-gray-600">{{ $plan['plan'] }}</p>
            </div>
        @elseif($plan === null && $ohneWeg !== null && $sig->status->istOffen())
            <div class="rounded-xl border border-black/[0.06] bg-black/[0.02] px-3.5 py-2.5" data-signal-ohne-weg>
                <div class="flex items-center gap-1.5 mb-1">
                    @svg('heroicon-o-hand-raised', 'w-3.5 h-3.5 text-gray-400')
                    <span class="text-[11px] font-medium text-gray-600">Kein Weg im System</span>
                </div>
                <p class="text-[11px] leading-relaxed text-gray-500">{{ $ohneWeg }}</p>
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
                                    {{-- Tranche B (S5b): bei `rezept_plausi_ki` öffnet das Modal direkt mit den
                                         abgelegten Befunden — das Signal zählt genau die, und der Fix passiert
                                         je Befund. Kein Prüf-Call beim Sprung (kein Egress, keine zweite
                                         Befundlage neben der, auf die das Signal zeigt). --}}
                                    <button type="button"
                                            wire:click="$dispatch('{{ $it['is_sales_recipe'] ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $it['id'] }}, copilot: {{ $sig->type->istKiUrteil() ? 'true' : 'false' }} })"
                                            class="min-w-0 flex-1 flex items-center gap-1.5 text-left text-[11px] text-sky-600 hover:text-sky-700 hover:underline"
                                            title="{{ $it['is_sales_recipe'] ? 'Verkaufsgericht' : 'Basisrezept' }} öffnen{{ $sig->type->istKiUrteil() ? ' — mit den Copilot-Befunden' : '' }}">
                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </button>
                                @elseif($it['kind'] === 'gp')
                                    <a href="{{ route('foodalchemist.gps.index', ['gp' => $it['id']]) }}" wire:navigate
                                       class="min-w-0 flex-1 flex items-center gap-1.5 text-[11px] text-violet-600 hover:text-violet-700 hover:underline" title="Grundprodukt öffnen">
                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @elseif($it['kind'] === 'concept')
                                    {{-- Tranche C: der Concepter wählt über ?sel= vor (dasselbe Muster wie ?gp= bei den GPs) --}}
                                    <a href="{{ route('foodalchemist.concepter.index', ['tab' => 'concepts', 'sel' => $it['id']]) }}" wire:navigate
                                       class="min-w-0 flex-1 flex items-center gap-1.5 text-[11px] text-emerald-600 hover:text-emerald-700 hover:underline" title="Konzept im Concepter öffnen">
                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @elseif($it['kind'] === 'foodbook')
                                    {{-- Tranche D: die Leitstelle wählt über ?fb= vor (Foodbooks\Index::$selectedId) --}}
                                    <a href="{{ route('foodalchemist.foodbooks.index', ['fb' => $it['id']]) }}" wire:navigate
                                       class="min-w-0 flex-1 flex items-center gap-1.5 text-[11px] text-amber-600 hover:text-amber-700 hover:underline" title="Foodbook in der Leitstelle öffnen">
                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @else
                                    <span class="min-w-0 flex-1 truncate text-[11px] text-gray-600">{{ $it['name'] }}</span>
                                @endif

                                {{-- „was noch?" gilt für jedes auflösbare Objekt (Tranche C: auch Konzepte,
                                     Tranche D: auch Foodbooks — Liste in SignalObjectService::KINDS);
                                     die Teil-Bulk-Checkbox oben bleibt bewusst bei recipe/gp — nur dafür gibt es
                                     deterministische Fixer (SignalCockpit::DETERMINISTIC). Kommt ein Konzept-/
                                     Foodbook-Fixer, ändern sich beide Stellen zusammen (auch fixbareItems()). --}}
                                @if(in_array($it['kind'], \Platform\FoodAlchemist\Services\SignalObjectService::KINDS, true))
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
                                                    @if($os['hat_ki'])<span class="shrink-0 text-[9px] text-violet-500" title="KI-Schritt vorhanden">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle')</span>@endif
                                                    @if($os['id'] === $sig->id)<span class="shrink-0 text-[9px] text-gray-400">hier</span>@endif
                                                </button>
                                            @endforeach
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1.5">
                                            {{ count($objektSignale) }} Befunde am selben Objekt — in einem Durchgang beheben statt {{ count($objektSignale) }}× öffnen.
                                        </p>
                                    @endif

                                    {{-- ── Punkt 5 (S3b-3): Ursachen-Kette nach unten ──────
                                         Warum ist DIESES Objekt betroffen — unbepreiste Zutat → GP →
                                         Lead-LA-Lage bzw. verletztes § mit Sprung ins Wissens-Modul.
                                         Nur Kurzform-@php hier: ein Roh-Block unterhalb einer Kurzform
                                         reißt die halbe View mit (s. Warnung ganz oben). --}}
                                    @foreach($objektUrsachen as $uB)
                                        <div wire:key="urs-{{ $it['kind'] }}-{{ $it['id'] }}-{{ $uB['art'] }}"
                                             class="mt-2 pt-2 border-t border-black/[0.06]" data-signal-ursache="{{ $uB['art'] }}">
                                            <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500 mb-1">
                                                {{ $uB['titel'] }}
                                                <span class="normal-case tracking-normal text-gray-400">· {{ $uB['kopf'] }}</span>
                                            </p>

                                            @if($uB['art'] === 'regelwerk')
                                                <div class="space-y-1">
                                                    @foreach($uB['glieder'] as $g)
                                                        <div wire:key="ursreg-{{ $it['id'] }}-{{ $g['fall'] }}" class="rounded-md bg-amber-500/[0.07] px-2 py-1.5">
                                                            <div class="flex items-center gap-1.5">
                                                                <span class="text-[10px] font-medium text-amber-700">{{ $g['paragraph'] }}</span>
                                                                @if($g['url'])
                                                                    <a href="{{ $g['url'] }}" wire:navigate
                                                                       class="text-[10px] text-sky-600 hover:underline" data-signal-paragraph="{{ $g['fall'] }}">nachlesen →</a>
                                                                @endif
                                                            </div>
                                                            <p class="text-[10px] leading-relaxed text-gray-600 mt-0.5">{{ $g['regel'] }}</p>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="space-y-1">
                                                    @foreach($uB['glieder'] as $gi => $g)
                                                        <div wire:key="ursek-{{ $it['id'] }}-{{ $gi }}"
                                                             class="rounded-md px-2 py-1.5 {{ $g['fixbar'] ? 'bg-emerald-500/[0.06]' : 'bg-black/[0.03]' }}">
                                                            <div class="flex items-center gap-1.5">
                                                                <span class="min-w-0 flex-1 truncate text-[11px] font-medium text-gray-700">{{ $g['zutat'] }}</span>
                                                                @if($g['menge'])<span class="shrink-0 text-[10px] text-gray-400">{{ $g['menge'] }}</span>@endif
                                                            </div>
                                                            <p class="text-[10px] text-gray-600 mt-0.5">
                                                                <span class="{{ $g['fixbar'] ? 'text-emerald-700' : 'text-rose-600' }}">{{ $g['ursache'] }}</span>
                                                                @if($g['gp_name'])
                                                                    · <a href="{{ route('foodalchemist.gps.index', ['gp' => $g['gp_id']]) }}" wire:navigate
                                                                         class="text-violet-600 hover:underline">{{ $g['gp_name'] }}</a>
                                                                @endif
                                                            </p>
                                                            <p class="text-[10px] leading-relaxed text-gray-500 mt-0.5">{{ $g['weiter'] }}</p>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @if($uB['gekappt'] > 0)
                                                    <p class="text-[10px] text-gray-400 mt-1">… und {{ $uB['gekappt'] }} weitere unbepreiste Zutat(en).</p>
                                                @endif
                                                @if($uB['ungemappt'] > 0)
                                                    <p class="text-[10px] text-gray-500 mt-1">
                                                        Zusätzlich {{ $uB['ungemappt'] }} ungemappte Zutat(en) — die zählen gar nicht erst in
                                                        die EK-Kette und senken den Wert still.
                                                    </p>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
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

        {{-- ── Punkt 4: Trend-Sparkline (E1) ──────────────────────────────────
             Gelesen wird die Signal-Seite der Reihe (Schlüssel = Signal-Typ), darum
             stehen hier echte Labels und keine rohen Metrik-Keys (V-010). --}}
        @php($verlaufMeta = $policy !== null ? $policy['count'].' offen' : null)
        <x-foodalchemist::section title="Verlauf" icon="heroicon-o-presentation-chart-line" :meta="$verlaufMeta">
            @if($spark !== null)
                <div class="flex items-center gap-3" data-signal-spark>
                    <svg viewBox="0 0 {{ $spark['w'] }} {{ $spark['h'] }}" class="w-full h-8 overflow-visible" preserveAspectRatio="none" aria-hidden="true">
                        <polyline points="{{ $spark['points'] }}" fill="none" stroke="currentColor" stroke-width="1.5"
                                  vector-effect="non-scaling-stroke"
                                  class="{{ ($policy['delta'] ?? 0) > 0 ? 'text-rose-500' : (($policy['delta'] ?? 0) < 0 ? 'text-emerald-500' : 'text-gray-400') }}" />
                    </svg>
                    <div class="shrink-0 text-right">
                        <div class="text-[13px] font-semibold text-gray-800 leading-none">{{ $spark['letzter'] }}</div>
                        @if(($policy['delta'] ?? null) !== null && $policy['delta'] !== 0)
                            <div class="text-[10px] {{ $policy['delta'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ $policy['delta'] > 0 ? '+' : '' }}{{ $policy['delta'] }}
                            </div>
                        @endif
                    </div>
                </div>
                <p class="text-[10px] text-gray-400 mt-1.5">
                    {{ $spark['punkte'] }} Messpunkte · min {{ $spark['min'] }} / max {{ $spark['max'] }} ·
                    seit {{ \Illuminate\Support\Carbon::parse($spark['von'])->format('d.m.Y H:i') }}
                </p>
            @else
                <p class="text-[11px] text-gray-500">
                    Noch keine Reihe — der Verlauf entsteht ab dem zweiten Detektor-Lauf.
                    @if(($policy['count'] ?? 0) > 0)Aktuell {{ $policy['count'] }} offen.@endif
                </p>
            @endif
        </x-foodalchemist::section>

        {{-- ── Punkt 8: Policy-Regler (E2) ────────────────────────────────────
             WICHTIG in der Fläche selbst benannt: der Regler gilt für den TYP, nicht
             für dieses eine Signal. --}}
        <x-foodalchemist::section title="Rausch-Guard" icon="heroicon-o-adjustments-horizontal">
            <x-slot:actions>
                <button type="button" wire:click="policyFormUmschalten"
                        class="px-2 py-0.5 rounded-md text-[10px] text-gray-500 hover:text-violet-700 hover:bg-violet-500/[0.08] transition-colors"
                        data-signal-policy-toggle>{{ $policyForm ? 'schließen' : 'einstellen' }}</button>
            </x-slot:actions>

            @if($policy !== null)
                <div class="rounded-lg px-3 py-2 {{ $polTon }}" data-signal-policy-state>
                    <div class="text-[11px]">{{ $policy['hinweis'] }}</div>
                    @if($policy['note'])<div class="text-[10px] opacity-80 mt-0.5 italic">{{ $policy['note'] }}</div>@endif
                    @if($policy['geerbt'])
                        <div class="text-[10px] opacity-70 mt-0.5">geerbt vom Eltern-Team — Speichern legt eine eigene Zeile an, die sie überstimmt.</div>
                    @endif
                </div>
            @endif

            @if($policyForm)
                <div class="mt-2 rounded-xl border border-violet-500/20 bg-white/70 px-3 py-2.5 space-y-2" data-signal-policy-form>
                    <p class="text-[10px] text-gray-500 leading-relaxed">
                        Gilt für <span class="font-medium">alle</span> Signale vom Typ „{{ $sig->type->label() }}",
                        nicht nur für dieses. Schwelle und Frist dämpfen nur die Darstellung des Bestands —
                        ein Zuwachs meldet sich weiter. Nur „stumm" schaltet auch den Drift-Alarm ab.
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="block text-[10px] text-gray-500 mb-0.5">ab Bestand nur Zustands-Zeile</span>
                            <input type="number" min="0" wire:model="pThreshold" placeholder="—"
                                   class="w-full px-2 py-1 rounded-md border border-black/10 text-[11px]" data-signal-policy-threshold>
                        </label>
                        <label class="block">
                            <span class="block text-[10px] text-gray-500 mb-0.5">akzeptiert bis</span>
                            <input type="date" wire:model="pAcceptedUntil"
                                   class="w-full px-2 py-1 rounded-md border border-black/10 text-[11px]" data-signal-policy-until>
                        </label>
                    </div>
                    <label class="block">
                        <span class="block text-[10px] text-gray-500 mb-0.5">Begründung (wird angezeigt)</span>
                        <input type="text" wire:model="pNote" maxlength="255" placeholder="z. B. Sourcing läuft, Frist mit Einkauf abgestimmt"
                               class="w-full px-2 py-1 rounded-md border border-black/10 text-[11px]" data-signal-policy-note>
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input type="checkbox" wire:model="pMuted"
                               class="w-3.5 h-3.5 rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-signal-policy-muted>
                        <span class="text-[11px] text-gray-600">stumm — interessiert nicht (auch kein Drift-Alarm)</span>
                    </label>
                    <div class="flex items-center gap-1.5 pt-0.5">
                        <button type="button" wire:click="policySpeichern"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-medium text-white bg-violet-600 hover:bg-violet-700"
                                data-signal-policy-save>
                            @svg('heroicon-o-check', 'w-3 h-3') Speichern
                        </button>
                        @if($policy !== null && $policy['gesetzt'] && ! $policy['geerbt'])
                            <button type="button" wire:click="policyEntfernen"
                                    class="px-2 py-1 rounded-lg text-[10px] text-gray-500 hover:text-rose-700 hover:bg-rose-500/[0.08]"
                                    data-signal-policy-remove>Regler entfernen</button>
                        @endif
                    </div>
                </div>
            @endif
        </x-foodalchemist::section>
    @endif
</div>
