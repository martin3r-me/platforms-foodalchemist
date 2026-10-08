{{-- Trendradar (Spec 79) nach Sarah Spork, BHG Trend Radar 2026 (Click Dummy 3) — Inspiration → Hype → Trend.
     LINKS Ablegen + Filter · MITTE Radar (Ringe = Trendhierarchie, Sektoren = Kategorie), Liste oder Inspirations-Pinnwand ·
     RECHTS Detail mit Belegen, Einordnung und Status. Geschrieben wird nur über TrendService. --}}
@php
    use Platform\FoodAlchemist\Support\TrendVokabular as V;

    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $konfTon = ['hoch' => 'ok', 'mittel' => 'info', 'niedrig' => 'warn'];
    $konfWert = ['hoch' => 1, 'mittel' => 0.62, 'niedrig' => 0.3];
    $statusTon = ['gesichtet' => 'warn', 'geprueft' => 'info', 'auf_radar' => 'accent', 'in_umsetzung' => 'ok', 'archiviert' => 'neutral', 'verworfen' => 'neutral'];
    $ebenenReihe = array_keys(V::EBENEN);

    // Radar-Geometrie wie Click Dummy 3: viewBox 640, Mitte 320/320, Außenradius 268
    $cx = 320; $cy = 320; $maxR = 268;
    $ringe = \Platform\FoodAlchemist\Services\TrendService::RINGE;
    $punkt = fn ($grad, $r) => [round($cx + $r * cos(deg2rad($grad)), 1), round($cy - $r * sin(deg2rad($grad)), 1)];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Trendradar" icon="heroicon-o-sparkles" />
    </x-slot:navbar>

    {{-- LINKS: Erfassen, Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Trends" width="w-80">
            <div class="p-3 flex flex-col gap-4" data-trend-filter>
                <x-fa::button variant="primary" icon="heroicon-m-camera" wire:click="fundstueckOeffnen" data-fundstueck-ablegen>Fundstück ablegen</x-fa::button>
                @if($darfKuratieren)
                    <x-fa::button variant="secondary" icon="heroicon-m-plus" wire:click="erfassenOeffnen" data-trend-anlegen-knopf>Trend anlegen</x-fa::button>
                @endif

                <div class="relative">
                    <label for="trend-suche" class="sr-only">Trends durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="trend-suche" type="search" wire:model.live.debounce.300ms="suche" class="pl-8" placeholder="Name oder Definition" />
                </div>

                <x-fa::choice name="kategorien" label="Kategorie" idPrefix="tr" :multiple="true" :options="V::KATEGORIEN" />
                <x-fa::choice name="typen" label="Trend oder Hype" idPrefix="tr" :multiple="true" :options="V::TYPEN" />
                <x-fa::choice name="ebenen" label="Trendhierarchie" idPrefix="tr" :multiple="true" :options="V::EBENEN" />

                <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] cursor-pointer">
                    <input type="checkbox" wire:model.live="nurBefragung" class="accent-[var(--fa-accent)]" /> Nur von der Befragung bestätigt
                </label>

                @if($filterAktiv)
                    <div><x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="resetFilter">Filter zurücksetzen</x-fa::button></div>
                @endif

                <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-1.5 {{ $leise }}">
                    <p class="font-medium text-[var(--fa-ink-2)]">Inspiration → Hype → Trend</p>
                    <p>Was du siehst (Instagram, Restaurant, Messe), legst du als Fundstück in die Pinnwand. Häufen sich Fundstücke zu einem Thema, wird daraus ein Hype oder Trend und kommt mit Belegen aufs Radar.</p>
                    <p class="pt-1 font-medium text-[var(--fa-ink-2)]">So liest man das Radar</p>
                    <p>Innen kurzlebig (Mode), außen langfristig (Metatrend). Links Food, rechts Getränke, Deko und Veranstaltungen.</p>
                    <p>Gefüllter Punkt = Trend, gestrichelter Kreis = Hype, Goldring = von der Mitarbeiterbefragung bestätigt. Je blasser, desto unsicherer die Belege.</p>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- MITTE --}}
    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">
        <x-fa::page-header title="Trendradar" subtitle="Inspiration · Hype · Trend — nach Sarah Spork, BHG Trend Radar 2026" />

        @if($meldung)<x-fa::notice tone="ok" data-trend-meldung>{{ $meldung }}</x-fa::notice>@endif
        @if($fehler && ! $gewaehlt)<x-fa::notice tone="crit" data-trend-fehler>{{ $fehler }}</x-fa::notice>@endif

        <x-fa::kpis :items="[
            ['label' => 'Auf dem Radar', 'value' => (string) $zaehler['radar'], 'primary' => true, 'kpi' => 'radar'],
            ['label' => 'Davon Hypes', 'value' => (string) $zaehler['hypes'], 'kpi' => 'hypes'],
            ['label' => 'Von der Befragung bestätigt', 'value' => (string) $zaehler['befragung'], 'kpi' => 'befragung'],
            ['label' => 'Zu prüfen', 'value' => (string) $zaehler['zu_pruefen'], 'tone' => $zaehler['zu_pruefen'] > 0 ? 'warn' : null, 'kpi' => 'zu-pruefen'],
        ]" />

        <div class="flex items-center gap-1.5" role="tablist">
            <x-fa::button size="sm" :variant="$ansicht === 'radar' ? 'primary' : 'ghost'" icon="heroicon-m-signal" wire:click="ansichtSetzen('radar')" role="tab">Radar</x-fa::button>
            <x-fa::button size="sm" :variant="$ansicht === 'liste' ? 'primary' : 'ghost'" icon="heroicon-m-list-bullet" wire:click="ansichtSetzen('liste')" role="tab">
                Liste @if($zaehler['zu_pruefen'] > 0)<span class="ml-1 tabular-nums">· {{ $zaehler['zu_pruefen'] }} zu prüfen</span>@endif
            </x-fa::button>
            <x-fa::button size="sm" :variant="$ansicht === 'inspiration' ? 'primary' : 'ghost'" icon="heroicon-m-light-bulb" wire:click="ansichtSetzen('inspiration')" role="tab" data-tab-inspiration>
                Inspiration @if($offeneFundstuecke > 0)<span class="ml-1 tabular-nums">· {{ $offeneFundstuecke }} offen</span>@endif
            </x-fa::button>
        </div>

        @if($ansicht === 'radar')
            <style>
                [data-trend-radar] { --tr-food:#B4692A; --tr-nonfood:#1E6E6B; --tr-gold:#A9822D; }
                [data-trend-radar] .tr-ring { fill:none; stroke:var(--fa-line-strong); stroke-width:1; }
                [data-trend-radar] .tr-ring-band { fill:var(--fa-neutral-soft); opacity:.45; }
                [data-trend-radar] .tr-axis { stroke:var(--fa-line-strong); stroke-width:1.2; }
                [data-trend-radar] .tr-sector { stroke:var(--fa-line); stroke-width:1; stroke-dasharray:4 4; }
                [data-trend-radar] .tr-label { font-size:11px; fill:var(--fa-ink-3); paint-order:stroke; stroke:var(--fa-surface); stroke-width:3px; }
                [data-trend-radar] .tr-half { font-size:12px; font-weight:600; letter-spacing:.08em; fill:var(--fa-ink-2); }
                [data-trend-radar] .tr-punkt { cursor:pointer; }
                [data-trend-radar] .tr-punkt:hover .tr-visual, [data-trend-radar] .tr-punkt:focus .tr-visual { stroke-width:3; }
                [data-trend-radar] .tr-punkt .tr-name { font-size:11px; fill:var(--fa-ink); paint-order:stroke; stroke:var(--fa-surface); stroke-width:3px; opacity:0; pointer-events:none; }
                [data-trend-radar] .tr-punkt:hover .tr-name, [data-trend-radar] .tr-punkt:focus .tr-name, [data-trend-radar] .tr-punkt.is-active .tr-name { opacity:1; }
                [data-trend-radar] .tr-punkt.is-active .tr-visual { stroke-width:3.5; }
            </style>
            <div class="fa-surface p-4" data-trend-radar>
                @if($radar->isEmpty())
                    <x-fa::empty icon="heroicon-o-signal" title="Noch nichts auf dem Radar">
                        Trends erscheinen hier, sobald sie eingeordnet sind (Trend/Hype, Ebene, Kategorie), einen bestätigenden Beleg haben und auf „Auf dem Radar“ stehen.
                        @if($zaehler['zu_pruefen'] > 0)
                            <x-slot:action><x-fa::button size="sm" wire:click="ansichtSetzen('liste')">{{ $zaehler['zu_pruefen'] }} gesichtete Trends prüfen</x-fa::button></x-slot:action>
                        @endif
                    </x-fa::empty>
                @endif
                <svg viewBox="0 0 640 680" class="w-full max-w-[720px] mx-auto block" role="img" aria-label="Trendradar: {{ $radar->count() }} Trends">
                    {{-- Ringbänder + Ringe (innen Mode → außen Metatrend) --}}
                    @foreach(array_reverse($ebenenReihe) as $i => $eb)
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $ringe[$eb][1] * $maxR + 6 }}" class="{{ $i % 2 === 0 ? 'tr-ring-band' : '' }}" fill="{{ $i % 2 === 0 ? '' : 'var(--fa-surface)' }}" />
                    @endforeach
                    @foreach($ebenenReihe as $eb)
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $ringe[$eb][1] * $maxR + 6 }}" class="tr-ring" />
                        <text x="{{ $cx + 6 }}" y="{{ $cy - ($ringe[$eb][1] * $maxR + 6) + 13 }}" class="tr-label">{{ V::EBENEN[$eb] }} · {{ $zaehler['je_ebene'][$eb] ?? 0 }}</text>
                    @endforeach
                    {{-- Achse Food | Non-Food, Sektoren rechts (Getränke · Deko · Veranstaltungen) --}}
                    <line x1="{{ $cx }}" y1="{{ $cy - $maxR * 1.04 }}" x2="{{ $cx }}" y2="{{ $cy + $maxR * 1.04 }}" class="tr-axis" />
                    @foreach([-29, 29] as $grad)
                        @php [$x2, $y2] = $punkt($grad, $maxR * 1.02); @endphp
                        <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $x2 }}" y2="{{ $y2 }}" class="tr-sector" />
                    @endforeach
                    @foreach([[-59, 'Getränke'], [0, 'Deko'], [59, 'Veranstaltungen']] as [$grad, $txt])
                        @php [$lx, $ly] = $punkt($grad, $maxR * 1.07); @endphp
                        <text x="{{ $lx }}" y="{{ $ly }}" class="tr-label" text-anchor="{{ $grad === 0 ? 'start' : 'start' }}">{{ $txt }}</text>
                    @endforeach
                    <text x="{{ $cx - $maxR }}" y="{{ $cy + $maxR * 1.13 }}" class="tr-half" fill="var(--tr-food)">FOOD</text>
                    <text x="{{ $cx + $maxR }}" y="{{ $cy + $maxR * 1.13 }}" class="tr-half" text-anchor="end">NON-FOOD</text>
                    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="2.4" fill="var(--fa-ink-3)" />

                    {{-- Punkte --}}
                    @foreach($radar as $p)
                        @php
                            $t = $p['trend'];
                            $farbe = $t->kategorie === 'food' ? 'var(--tr-food)' : 'var(--tr-nonfood)';
                            $deck = max(0.45, $konfWert[$t->wirksameKonfidenz()] ?? 0.3);
                            $aktiv = $gewaehlt && $gewaehlt->id === $t->id;
                            $rechts = $p['x'] > $cx;
                        @endphp
                        <g class="tr-punkt {{ $aktiv ? 'is-active' : '' }}" wire:key="rp-{{ $t->id }}" wire:click="select({{ $t->id }})"
                           tabindex="0" role="button" aria-label="{{ $t->name }}, {{ V::TYPEN[$t->typ] }}, {{ V::KATEGORIEN[$t->kategorie] }}"
                           wire:keydown.enter="select({{ $t->id }})" data-trend-punkt="{{ $t->id }}"
                           x-data x-on:click="$store.ui?.mSet('activity_trendradar', 'open', true)">
                            <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="15" fill="transparent" />
                            @if($t->befragung_bestaetigt)
                                <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="11.5" fill="none" stroke="var(--tr-gold)" stroke-width="2.2" />
                            @endif
                            @if($t->typ === 'hype')
                                <circle class="tr-visual" cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="7" fill="var(--fa-surface)" stroke="{{ $farbe }}" stroke-width="2" stroke-dasharray="3,2.4" opacity="{{ $deck }}" />
                            @else
                                <circle class="tr-visual" cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="7" fill="{{ $farbe }}" stroke="{{ $farbe }}" stroke-width="1.4" opacity="{{ $deck }}" />
                            @endif
                            <text class="tr-name" x="{{ $rechts ? $p['x'] - 12 : $p['x'] + 12 }}" y="{{ $p['y'] + 4 }}" text-anchor="{{ $rechts ? 'end' : 'start' }}">{{ $t->name }}</text>
                            <title>{{ $t->name }} · {{ V::TYPEN[$t->typ] }} · {{ V::EBENEN[$t->ebene] }} · Konfidenz {{ V::KONFIDENZ[$t->wirksameKonfidenz()] }}</title>
                        </g>
                    @endforeach
                </svg>
                {{-- Legende --}}
                <div class="mt-3 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 {{ $leise }}">
                    <span class="inline-flex items-center gap-1.5"><svg width="14" height="14"><circle cx="7" cy="7" r="5.5" fill="#B4692A" /></svg> Food</span>
                    <span class="inline-flex items-center gap-1.5"><svg width="14" height="14"><circle cx="7" cy="7" r="5.5" fill="#1E6E6B" /></svg> Non-Food</span>
                    <span class="inline-flex items-center gap-1.5"><svg width="14" height="14"><circle cx="7" cy="7" r="5" fill="var(--fa-surface)" stroke="var(--fa-ink-2)" stroke-width="1.6" stroke-dasharray="2.5,2" /></svg> Hype</span>
                    <span class="inline-flex items-center gap-1.5"><svg width="16" height="16"><circle cx="8" cy="8" r="6.5" fill="none" stroke="#A9822D" stroke-width="2" /><circle cx="8" cy="8" r="3.5" fill="var(--fa-ink-3)" /></svg> Befragung bestätigt</span>
                    <span>Blass = niedrige Konfidenz</span>
                </div>
            </div>
        @elseif($ansicht === 'inspiration')
            {{-- INSPIRATION: Team-Pinnwand der Fundstücke --}}
            <div class="flex flex-wrap items-center gap-1.5" data-pinnwand-filter>
                @foreach(['offen' => 'Offen', 'zugeordnet' => 'Einem Trend zugeordnet', 'alle' => 'Alle'] as $wert => $label)
                    <button type="button" wire:click="$set('pinnAnsicht', @js($wert))" wire:key="pa-{{ $wert }}"
                            class="fa-chip {{ $pinnAnsicht === $wert ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : '' }}"><span>{{ $label }}</span></button>
                @endforeach
                @if($pinnSchlagwort !== '')
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="$set('pinnSchlagwort', '')">#{{ $pinnSchlagwort }}</x-fa::button>
                @endif
            </div>
            @if($haeufungen !== [])
                <x-fa::notice tone="info" title="Häufungen" data-pinnwand-haeufungen>
                    Zu diesen Schlagworten liegen mehrere offene Fundstücke — vielleicht entsteht hier ein Hype oder Trend:
                    <span class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach($haeufungen as $wort => $n)
                            <button type="button" class="fa-chip" wire:click="$set('pinnSchlagwort', @js($wort))" wire:key="hf-{{ md5($wort) }}"><span>#{{ $wort }} · {{ $n }}</span></button>
                        @endforeach
                    </span>
                </x-fa::notice>
            @endif
            @if($fundstuecke->isEmpty())
                <div class="fa-surface">
                    <x-fa::empty icon="heroicon-o-light-bulb" title="Die Pinnwand ist leer">
                        Etwas Spannendes gesehen? Mit „Fundstück ablegen“ den Screenshot oder Link hier sammeln.
                        <x-slot:action><x-fa::button size="sm" wire:click="fundstueckOeffnen">Fundstück ablegen</x-fa::button></x-slot:action>
                    </x-fa::empty>
                </div>
            @else
                <div class="grid gap-3 grid-cols-[repeat(auto-fill,minmax(min(100%,16rem),1fr))]" data-pinnwand>
                    @foreach($fundstuecke as $eintrag)
                        @php $f = $eintrag['b']; $bild = $eintrag['url'] && str_starts_with((string) $f->datei_mime, 'image/'); @endphp
                        <article class="fa-surface overflow-hidden flex flex-col min-w-0" wire:key="fs-{{ $f->id }}" data-fundstueck="{{ $f->id }}">
                            @if($bild)
                                <a href="{{ $eintrag['url'] }}" target="_blank" rel="noopener" class="block bg-[var(--fa-neutral-soft)]">
                                    <img src="{{ $eintrag['url'] }}" alt="{{ $f->titel ?: $f->datei_name }}" class="w-full h-44 object-cover" loading="lazy" />
                                </a>
                            @endif
                            <div class="p-3 flex flex-col gap-1.5 min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-fa::badge>{{ V::QUELLEN[$f->quelle] ?? $f->quelle }}</x-fa::badge>
                                    @if($f->beobachtet_am)<span class="{{ $leise }}">{{ $f->beobachtet_am->format('d.m.Y') }}</span>@endif
                                    @if((int) $f->team_id !== (int) auth()->user()->current_team_id)<x-fa::badge tone="info" data-fundstueck-standort>{{ $teamNamen[$f->team_id] ?? 'Anderer Standort' }}</x-fa::badge>@endif
                                </div>
                                @if($f->titel)<p class="font-medium text-[var(--fa-ink)] break-words">{{ $f->titel }}</p>@endif
                                @if($f->notiz)<p class="{{ $leise }} break-words line-clamp-3">{{ $f->notiz }}</p>@endif
                                @if($f->fundort)<p class="{{ $leise }}">Gesehen: {{ $f->fundort }}</p>@endif
                                @if($f->schlagworte)
                                    <span class="flex flex-wrap gap-1">
                                        @foreach($f->schlagworte as $w)
                                            <button type="button" class="{{ $leise }} underline" wire:click="$set('pinnSchlagwort', @js(mb_strtolower($w)))">#{{ $w }}</button>
                                        @endforeach
                                    </span>
                                @endif
                                <p class="flex flex-wrap gap-3 {{ $leise }}">
                                    @if($f->url)<a href="{{ $f->url }}" target="_blank" rel="noopener" class="text-[var(--fa-accent)] underline">Link öffnen</a>@endif
                                    @if($eintrag['url'] && ! $bild)<a href="{{ $eintrag['url'] }}" target="_blank" rel="noopener" class="text-[var(--fa-accent)] underline">{{ $f->datei_name }}</a>@endif
                                </p>
                                @if($f->trend)
                                    <p class="text-[length:var(--fa-text-sm)]">Gehört zu <button type="button" class="text-[var(--fa-accent)] underline" wire:click="select({{ $f->trend->id }})"
                                        x-data x-on:click="$store.ui?.mSet('activity_trendradar', 'open', true)">{{ $f->trend->name }}</button></p>
                                @endif
                                <div class="mt-auto pt-2 flex flex-col gap-2 border-t border-[var(--fa-line)]">
                                    @if($darfKuratieren && (int) $f->team_id === (int) auth()->user()->current_team_id)
                                        @if($f->trend_id === null)
                                            <div class="flex gap-1.5">
                                                <x-fa::select size="sm" wire:model="zuordnung.{{ $f->id }}" placeholder="An Trend hängen …" :options="$trendOptionen" class="flex-1 min-w-0" />
                                                <x-fa::button size="sm" variant="secondary" wire:click="fundstueckZuordnen({{ $f->id }})">OK</x-fa::button>
                                            </div>
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-sparkles" wire:click="trendAusFundstueck({{ $f->id }})" data-trend-aus-fundstueck>Trend daraus machen</x-fa::button>
                                        @else
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-uturn-left" wire:click="fundstueckLoesen({{ $f->id }})">Zuordnung lösen</x-fa::button>
                                        @endif
                                    @endif
                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-light-bulb" wire:click="inPlanungOeffnen(null, {{ $f->id }})" data-fundstueck-planung>In Planung öffnen</x-fa::button>
                                    @if(($darfKuratieren && (int) $f->team_id === (int) auth()->user()->current_team_id) || in_array((int) $f->team_id, $adminTeams, true))
                                        <button type="button" class="self-start {{ $leise }} underline" wire:click="belegEntfernen({{ $f->id }})" wire:confirm="Fundstück löschen?" data-fundstueck-loeschen>löschen</button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        @else
            {{-- LISTE inkl. Prüf-Queue --}}
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach(['' => 'Alle'] + V::STATUS as $wert => $label)
                    <button type="button" wire:click="$set('statusFilter', @js($wert))" wire:key="sf-{{ $wert ?: 'alle' }}"
                            class="fa-chip {{ $statusFilter === $wert ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : '' }}"><span>{{ $label }}</span></button>
                @endforeach
            </div>
            <div class="fa-surface overflow-hidden" data-trend-liste>
                <div class="max-h-[70vh] overflow-auto">
                    <table class="fa-table">
                        <thead class="sticky top-0 z-10 bg-[var(--fa-surface)]">
                            <tr><th class="w-full">Trend</th><th>Einordnung</th><th>Konfidenz</th><th>Status</th><th class="num">Belege</th></tr>
                        </thead>
                        <tbody>
                            @forelse($trends as $t)
                                <x-foodalchemist::table-row :active="$gewaehlt && $gewaehlt->id === $t->id" wire:key="tl-{{ $t->id }}" wire:click="select({{ $t->id }})"
                                    x-data x-on:click="$store.ui?.mSet('activity_trendradar', 'open', true)">
                                    <td class="min-w-[14rem]">
                                        <span class="block font-medium text-[var(--fa-ink)] break-words">{{ $t->name }}</span>
                                        @if($t->definition)<span class="block {{ $leise }} line-clamp-1">{{ $t->definition }}</span>@endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if($t->typ || $t->ebene || $t->kategorie)
                                            <span class="flex flex-wrap gap-1">
                                                @if($t->typ)<x-fa::badge :tone="$t->typ === 'hype' ? 'warn' : 'accent'">{{ V::TYPEN[$t->typ] }}</x-fa::badge>@endif
                                                @if($t->ebene)<x-fa::badge>{{ V::EBENEN[$t->ebene] }}</x-fa::badge>@endif
                                                @if($t->kategorie)<x-fa::badge>{{ V::KATEGORIEN[$t->kategorie] }}</x-fa::badge>@endif
                                            </span>
                                        @else
                                            <span class="text-[var(--fa-ink-3)]">noch nicht eingeordnet</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap"><x-fa::badge :tone="$konfTon[$t->wirksameKonfidenz()] ?? 'neutral'">{{ V::KONFIDENZ[$t->wirksameKonfidenz()] }}</x-fa::badge></td>
                                    <td class="whitespace-nowrap"><x-fa::badge :tone="$statusTon[$t->status] ?? 'neutral'">{{ V::STATUS[$t->status] ?? $t->status }}</x-fa::badge></td>
                                    <td class="num">{{ $t->belege_count }}</td>
                                </x-foodalchemist::table-row>
                            @empty
                                <tr><td colspan="5">
                                    <x-fa::empty icon="heroicon-o-sparkles" title="Keine Trends">
                                        Fundstücke in der Pinnwand sammeln und daraus Trends machen, oder mit „Trend anlegen“ direkt einen anlegen.
                                    </x-fa::empty>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-ui-page-container>

    {{-- RECHTS: Detail --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Trend" width="w-96" :maxWidth="760" scope="activity_trendradar" side="right">
            @if($gewaehlt)
                @php
                    $kf = $gewaehlt->wirksameKonfidenz();
                    $ebIdx = $gewaehlt->ebene ? array_search($gewaehlt->ebene, $ebenenReihe, true) : -1;
                @endphp
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-trend-detail="{{ $gewaehlt->id }}">
                    <x-fa::detail-kopf :title="$gewaehlt->name" :subtitle="$gewaehlt->kategorie ? V::KATEGORIEN[$gewaehlt->kategorie].($gewaehlt->food_cluster ? ' · '.V::FOOD_CLUSTER[$gewaehlt->food_cluster] : '') : 'Noch nicht eingeordnet'">
                        <x-slot:badges>
                            @if($gewaehlt->typ)<x-fa::badge :tone="$gewaehlt->typ === 'hype' ? 'warn' : 'accent'">{{ V::TYPEN[$gewaehlt->typ] }}</x-fa::badge>@endif
                            <x-fa::badge :tone="$statusTon[$gewaehlt->status] ?? 'neutral'">{{ V::STATUS[$gewaehlt->status] }}</x-fa::badge>
                            @if($gewaehlt->befragung_bestaetigt)<x-fa::badge tone="ok" icon="heroicon-m-check-badge">Befragung bestätigt</x-fa::badge>@endif
                        </x-slot:badges>
                        @if($eigen && $darfKuratieren)
                            <x-slot:aktion>
                                @if(in_array($gewaehlt->status, V::RADAR_STATUS, true))
                                    <x-fa::button size="sm" variant="secondary" icon="heroicon-m-pencil-square" wire:click="einordnenStarten">Einordnen</x-fa::button>
                                @elseif($hindernis === null)
                                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-signal" wire:click="statusSetzen('auf_radar')" data-trend-aufs-radar>Aufs Radar</x-fa::button>
                                @else
                                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-pencil-square" wire:click="einordnenStarten" data-trend-einordnen>Einordnen</x-fa::button>
                                @endif
                            </x-slot:aktion>
                            <x-slot:menue>
                                <x-fa::menu-item icon="heroicon-m-light-bulb" wire:click="inPlanungOeffnen({{ $gewaehlt->id }})" data-trend-planung>In Planung öffnen</x-fa::menu-item>
                                <x-fa::menu-item icon="heroicon-m-pencil-square" wire:click="einordnenStarten">Einordnen und bearbeiten</x-fa::menu-item>
                                @foreach(V::STATUS as $wert => $label)
                                    @if($wert !== $gewaehlt->status)
                                        <x-fa::menu-item icon="heroicon-m-arrow-right" wire:click="statusSetzen('{{ $wert }}')">Status: {{ $label }}</x-fa::menu-item>
                                    @endif
                                @endforeach
                                <x-fa::menu-item icon="heroicon-m-trash" danger wire:click="loeschen" wire:confirm="Trend mit allen Belegen löschen? Zum Aussortieren reicht „Verworfen“.">Löschen</x-fa::menu-item>
                            </x-slot:menue>
                        @else
                            <x-slot:menue>
                                <x-fa::menu-item icon="heroicon-m-light-bulb" wire:click="inPlanungOeffnen({{ $gewaehlt->id }})" data-trend-planung>In Planung öffnen</x-fa::menu-item>
                                <x-fa::menu-item icon="heroicon-m-x-mark" wire:click="deselect">Auswahl schließen</x-fa::menu-item>
                            </x-slot:menue>
                        @endif
                    </x-fa::detail-kopf>

                    @if($fehler)<x-fa::notice tone="crit" data-trend-fehler>{{ $fehler }}</x-fa::notice>@endif
                    @if($hindernis && ! in_array($gewaehlt->status, ['verworfen', 'archiviert'], true))
                        <x-fa::signal tone="warn" data-trend-hindernis>Noch nicht aufs Radar: {{ $hindernis }}</x-fa::signal>
                    @endif

                    @if($einordnenOffen)
                        <x-fa::section variant="card" title="Einordnen" icon="heroicon-o-adjustments-horizontal" data-trend-einordnung>
                            <div class="grid grid-cols-2 gap-3">
                                <x-fa::field label="Name" for="te-name" class="col-span-2"><x-fa::input id="te-name" wire:model="einordnung.name" /></x-fa::field>
                                <x-fa::field label="Definition" for="te-def" class="col-span-2"><x-fa::textarea id="te-def" rows="3" wire:model="einordnung.definition" /></x-fa::field>
                                <x-fa::field label="Trend oder Hype" for="te-typ" hint="Trend: Bedürfnis, Dauer. Hype: medial, kurzlebig."><x-fa::select id="te-typ" wire:model="einordnung.typ" placeholder="–" :options="V::TYPEN" /></x-fa::field>
                                <x-fa::field label="Ebene" for="te-ebene"><x-fa::select id="te-ebene" wire:model="einordnung.ebene" placeholder="–" :options="V::EBENEN" /></x-fa::field>
                                <x-fa::field label="Kategorie" for="te-kat"><x-fa::select id="te-kat" wire:model.live="einordnung.kategorie" placeholder="–" :options="V::KATEGORIEN" /></x-fa::field>
                                @if(($einordnung['kategorie'] ?? '') === 'food')
                                    <x-fa::field label="Food-Cluster" for="te-cluster" optional><x-fa::select id="te-cluster" wire:model="einordnung.food_cluster" placeholder="–" :options="V::FOOD_CLUSTER" /></x-fa::field>
                                @elseif(($einordnung['kategorie'] ?? '') === 'event')
                                    <x-fa::field label="Sicht" for="te-sicht" optional><x-fa::select id="te-sicht" wire:model="einordnung.sicht" placeholder="–" :options="V::SICHTEN" /></x-fa::field>
                                @else
                                    <div></div>
                                @endif
                                <x-fa::field label="Konfidenz von Hand" for="te-konf" optional hint="Leer = aus den Belegen berechnet."><x-fa::select id="te-konf" wire:model="einordnung.konfidenz_manuell" placeholder="aus Belegen" :options="V::KONFIDENZ" /></x-fa::field>
                                <x-fa::field label="Gartner-Phase" for="te-gartner" optional hint="Nur bei Technik-Trends."><x-fa::select id="te-gartner" wire:model="einordnung.gartner_phase" placeholder="–" :options="V::GARTNER_PHASEN" /></x-fa::field>
                                <x-fa::field label="Google-Trends-Suchbegriffe" for="te-such" optional hint="Kommagetrennt, höchstens 3 werden gemessen." class="col-span-2"><x-fa::input id="te-such" wire:model="einordnung.suchbegriffe" placeholder="z. B. dubai schokolade" /></x-fa::field>
                                <x-fa::field label="Hashtags" for="te-tags" optional class="col-span-2"><x-fa::input id="te-tags" wire:model="einordnung.hashtags" placeholder="z. B. dubaichocolate" /></x-fa::field>
                                <x-fa::field label="Historische Einordnung" for="te-hist" optional class="col-span-2"><x-fa::textarea id="te-hist" rows="2" wire:model="einordnung.historische_einordnung" /></x-fa::field>
                            </div>
                            <div class="flex justify-end gap-2">
                                <x-fa::button variant="ghost" wire:click="$set('einordnenOffen', false)">Abbrechen</x-fa::button>
                                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="einordnungSpeichern" data-trend-einordnung-speichern>Speichern</x-fa::button>
                            </div>
                        </x-fa::section>
                    @endif

                    <div class="flex flex-col">
                        @if($gewaehlt->definition)
                            <x-fa::section variant="plain" title="Definition" icon="heroicon-o-document-text">
                                <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink)]">{{ $gewaehlt->definition }}</p>
                            </x-fa::section>
                        @endif

                        <x-fa::section variant="plain" title="Position in der Trendhierarchie" icon="heroicon-o-signal">
                            <div class="flex items-center gap-3">
                                <span class="flex gap-1">
                                    @foreach($ebenenReihe as $i => $eb)
                                        <span class="w-3 h-3 rounded-full border border-[var(--fa-accent)] {{ $i <= $ebIdx ? 'bg-[var(--fa-accent)]' : '' }}" title="{{ V::EBENEN[$eb] }}"></span>
                                    @endforeach
                                </span>
                                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $gewaehlt->ebene ? V::EBENEN[$gewaehlt->ebene] : 'nicht eingeordnet' }}</span>
                            </div>
                        </x-fa::section>

                        <x-fa::section variant="plain" title="Konfidenz" icon="heroicon-o-scale" :meta="$gewaehlt->konfidenz_manuell ? 'von Hand gesetzt' : 'aus den Belegen'">
                            <div class="flex items-center gap-3">
                                <span class="flex-1 h-1.5 rounded-full bg-[var(--fa-neutral-soft)] overflow-hidden">
                                    <span class="block h-full rounded-full bg-[var(--fa-accent)]" style="width: {{ (int) round(($konfWert[$kf] ?? 0.3) * 100) }}%"></span>
                                </span>
                                <x-fa::badge :tone="$konfTon[$kf] ?? 'neutral'">{{ V::KONFIDENZ[$kf] }}</x-fa::badge>
                            </div>
                            <p class="{{ $leise }}">{{ $bewertung['begruendung'] ?? '' }}@if($gewaehlt->befragung_anteil !== null) Befragung: {{ number_format($gewaehlt->befragung_anteil, 0, ',', '.') }} % der Nennungen.@endif</p>
                        </x-fa::section>

                        <x-fa::section variant="plain" title="Belege" icon="heroicon-o-paper-clip" :meta="$belege->count()">
                            <ul class="flex flex-col gap-3" data-trend-belege>
                                @forelse($belege as $eintrag)
                                    @php $b = $eintrag['b']; @endphp
                                    <li class="flex gap-3 min-w-0" wire:key="tb-{{ $b->id }}">
                                        @if($eintrag['url'] && str_starts_with((string) $b->datei_mime, 'image/'))
                                            <a href="{{ $eintrag['url'] }}" target="_blank" rel="noopener" class="shrink-0">
                                                <img src="{{ $eintrag['url'] }}" alt="{{ $b->datei_name }}" class="w-16 h-16 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" loading="lazy" />
                                            </a>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <x-fa::badge :tone="in_array($b->quelle, V::HARTE_QUELLEN, true) ? 'ok' : (in_array($b->quelle, V::SOZIALE_QUELLEN, true) ? 'neutral' : 'info')">{{ V::QUELLEN[$b->quelle] ?? $b->quelle }}</x-fa::badge>
                                                @if($b->beobachtet_am)<span class="{{ $leise }}">{{ $b->beobachtet_am->format('d.m.Y') }}</span>@endif
                                                @if($b->anteil !== null)<span class="{{ $leise }}">{{ number_format($b->anteil, 0, ',', '.') }} %</span>@endif
                                            </div>
                                            @if($b->titel)<p class="mt-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">{{ $b->titel }}</p>@endif
                                            @if($b->notiz)<p class="mt-0.5 {{ $leise }} break-words">{{ $b->notiz }}</p>@endif
                                            @if($b->fundort)<p class="mt-0.5 {{ $leise }}">Gesehen: {{ $b->fundort }}</p>@endif
                                            <p class="mt-0.5 flex flex-wrap gap-3 {{ $leise }}">
                                                @if($b->url)<a href="{{ $b->url }}" target="_blank" rel="noopener" class="text-[var(--fa-accent)] underline break-all">Link öffnen</a>@endif
                                                @if($eintrag['url'] && ! str_starts_with((string) $b->datei_mime, 'image/'))<a href="{{ $eintrag['url'] }}" target="_blank" rel="noopener" class="text-[var(--fa-accent)] underline">{{ $b->datei_name }}</a>@endif
                                                @if($eigen && ($darfKuratieren || (int) $b->created_by === (int) auth()->id()))
                                                    <button type="button" class="underline" wire:click="belegEntfernen({{ $b->id }})" wire:confirm="Beleg entfernen?">entfernen</button>
                                                @endif
                                            </p>
                                        </div>
                                    </li>
                                @empty
                                    <li class="{{ $leise }}">Noch kein Beleg.</li>
                                @endforelse
                            </ul>
                        </x-fa::section>

                        @if($eigen)
                            <x-fa::section variant="plain" title="Beleg hinzufügen" icon="heroicon-o-plus-circle">
                                <div class="grid grid-cols-2 gap-3" data-trend-beleg-form>
                                    <x-fa::field label="Quelle" for="tb-quelle"><x-fa::select id="tb-quelle" wire:model.live="beleg.quelle" :options="V::QUELLEN" /></x-fa::field>
                                    <x-fa::field label="Datum" for="tb-datum" optional><x-fa::input id="tb-datum" type="date" wire:model="beleg.beobachtet_am" /></x-fa::field>
                                    <x-fa::field label="Link" for="tb-url" optional class="col-span-2"><x-fa::input id="tb-url" wire:model="beleg.url" placeholder="https://…" /></x-fa::field>
                                    <x-fa::field label="Titel" for="tb-titel" optional class="col-span-2"><x-fa::input id="tb-titel" wire:model="beleg.titel" /></x-fa::field>
                                    <x-fa::field label="Notiz" for="tb-notiz" optional class="col-span-2"><x-fa::textarea id="tb-notiz" rows="2" wire:model="beleg.notiz" /></x-fa::field>
                                    <x-fa::field label="Wo gesehen" for="tb-fundort" optional><x-fa::input id="tb-fundort" wire:model="beleg.fundort" placeholder="Account, Lokal, Messe" /></x-fa::field>
                                    @if(($beleg['quelle'] ?? '') === 'befragung')
                                        <x-fa::field label="Anteil der Befragten (%)" for="tb-anteil" optional><x-fa::input id="tb-anteil" type="number" min="0" max="100" wire:model="beleg.anteil" /></x-fa::field>
                                    @else
                                        <div></div>
                                    @endif
                                    <x-fa::field label="Screenshot, Foto oder PDF" for="tb-datei" optional hint="max. 15 MB" class="col-span-2">
                                        <input id="tb-datei" type="file" wire:model="belegDatei" accept="image/*,application/pdf" class="block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                        <div wire:loading wire:target="belegDatei" class="{{ $leise }}">lädt hoch …</div>
                                    </x-fa::field>
                                </div>
                                <div class="flex justify-end"><x-fa::button size="sm" variant="secondary" icon="heroicon-m-paper-clip" wire:click="belegHinzufuegen" wire:loading.attr="disabled" data-trend-beleg-speichern>Beleg anhängen</x-fa::button></div>
                            </x-fa::section>
                        @endif

                        <x-fa::section variant="plain" title="Google Trends" icon="heroicon-o-chart-bar" :meta="'Monat: '.number_format($verbraucht, 2, ',', '.').' $ von '.number_format($budget, 2, ',', '.').' $'">
                            @forelse($messungen as $m)
                                @php
                                    $werte = collect($m->werte ?? [])->pluck('value')->map(fn ($v) => (int) ($v ?? 0))->values();
                                    $n = max(1, $werte->count() - 1);
                                    $linie = $werte->map(fn ($v, $i) => round($i / $n * 200, 1).','.round(40 - $v / 100 * 38, 1))->implode(' ');
                                @endphp
                                <div class="flex items-center gap-3" wire:key="tm-{{ $m->id }}">
                                    <svg viewBox="0 0 200 42" class="w-40 h-9 shrink-0" aria-hidden="true"><polyline points="{{ $linie }}" fill="none" stroke="var(--fa-accent)" stroke-width="1.6" /></svg>
                                    <div class="min-w-0 {{ $leise }}">
                                        <p class="text-[var(--fa-ink)]">„{{ $m->suchbegriff }}“ · {{ ucfirst((string) $m->richtung ?: 'keine Daten') }}</p>
                                        <p>Ø {{ $m->durchschnitt ?? '–' }} · Spitze {{ $m->spitze ?? '–' }} · {{ $m->created_at?->format('d.m.Y') }}@if($m->spitze_ohne_sockel) · <span class="text-[var(--fa-warn)]">Spitze ohne Sockel</span>@endif</p>
                                    </div>
                                </div>
                            @empty
                                <p class="{{ $leise }}">Noch nicht gemessen. Gemessen werden die Suchbegriffe, sonst der Name.</p>
                            @endforelse
                            @if($eigen && $darfKuratieren)
                                <div>
                                    @if($messenMoeglich)
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-path" wire:click="messen" wire:confirm="Google Trends über DataForSEO abfragen? Kostet etwa 0,01 $ je Suchbegriff." data-trend-messen>Jetzt messen</x-fa::button>
                                    @else
                                        <p class="{{ $leise }}">Die DataForSEO-Anbindung ist auf dieser Plattform nicht installiert.</p>
                                    @endif
                                </div>
                            @endif
                        </x-fa::section>

                        @if($gewaehlt->historische_einordnung || $gewaehlt->gartner_phase || $gewaehlt->einordnung_begruendung)
                            <x-fa::section variant="plain" title="Einordnung" icon="heroicon-o-book-open">
                                @if($gewaehlt->historische_einordnung)<p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $gewaehlt->historische_einordnung }}</p>@endif
                                @if($gewaehlt->gartner_phase)<p class="{{ $leise }}">Gartner Hype Cycle: {{ V::GARTNER_PHASEN[$gewaehlt->gartner_phase] }}</p>@endif
                                @if($gewaehlt->einordnung_begruendung)<p class="{{ $leise }}">{{ $gewaehlt->einordnung_begruendung }}@if($gewaehlt->einordnung_quelle === 'ki') (KI-Vorschlag)@endif</p>@endif
                            </x-fa::section>
                        @endif
                    </div>
                </div>
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Trend gewählt">Einen Punkt im Radar oder eine Zeile in der Liste anklicken.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- Fundstück-Dialog: Inspiration in Sekunden ablegen, für alle im Team --}}
    <x-foodalchemist::modal name="fundstueck-ablegen" title="Fundstück ablegen" size="max-w-2xl">
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
        <x-foodalchemist::modal-section title="Was hast du gesehen?">
            <div class="grid grid-cols-2 gap-3" data-fundstueck-form>
                <x-fa::field label="Screenshot oder Foto" for="fs-datei" hint="Bild oder PDF, max. 15 MB" class="col-span-2">
                    <input id="fs-datei" type="file" wire:model="fundDatei" accept="image/*,application/pdf" capture="environment" class="block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                    <div wire:loading wire:target="fundDatei" class="{{ $leise }}">lädt hoch …</div>
                </x-fa::field>
                <x-fa::field label="Titel" for="fs-titel" class="col-span-2"><x-fa::input id="fs-titel" wire:model="fund.titel" placeholder="z. B. Loaded Mash Potatoes mit Pulled Pork" /></x-fa::field>
                <x-fa::field label="Quelle" for="fs-quelle"><x-fa::select id="fs-quelle" wire:model="fund.quelle" :options="V::QUELLEN" /></x-fa::field>
                <x-fa::field label="Wo gesehen" for="fs-fundort" optional><x-fa::input id="fs-fundort" wire:model="fund.fundort" placeholder="Account, Lokal, Messe" /></x-fa::field>
                <x-fa::field label="Link" for="fs-url" optional class="col-span-2"><x-fa::input id="fs-url" wire:model="fund.url" placeholder="https://www.instagram.com/p/…" /></x-fa::field>
                <x-fa::field label="Notiz" for="fs-notiz" optional class="col-span-2"><x-fa::textarea id="fs-notiz" rows="2" wire:model="fund.notiz" placeholder="Was fällt auf? Für welchen Anlass könnte es passen?" /></x-fa::field>
                <x-fa::field label="Schlagworte" for="fs-tags" optional hint="Kommagetrennt. Gleiche Schlagworte bündeln Fundstücke zu Häufungen." class="col-span-2"><x-fa::input id="fs-tags" wire:model="fund.schlagworte" placeholder="z. B. kartoffel, comfort food" /></x-fa::field>
            </div>
            <p class="{{ $leise }}">Ein Fundstück ist noch kein Trend. Es landet in der Pinnwand des Teams; wer kuratiert, macht später daraus einen Hype oder Trend.</p>
        </x-foodalchemist::modal-section>
        <x-slot:footer>
            <x-fa::button variant="ghost" @click="$dispatch('modal.close', { name: 'fundstueck-ablegen' })">Abbrechen</x-fa::button>
            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="fundstueckAblegen" wire:loading.attr="disabled" data-fundstueck-speichern>Ablegen</x-fa::button>
        </x-slot:footer>
    </x-foodalchemist::modal>

    {{-- Trend-Dialog (Kuratieren): neu oder aus einem Fundstück --}}
    <x-foodalchemist::modal name="trend-erfassen" title="Trend anlegen" size="max-w-2xl">
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
        @if($ausFundstuecken !== [])
            <x-fa::notice tone="info">Das Fundstück wird als erster Beleg an den neuen Trend gehängt.</x-fa::notice>
        @endif
        <x-foodalchemist::modal-section title="Trend">
            <div class="grid grid-cols-3 gap-3" data-trend-erfassen-form>
                <x-fa::field label="Name" for="tn-name" required class="col-span-3"><x-fa::input id="tn-name" wire:model="neu.name" placeholder="z. B. Loaded Mash Potatoes" /></x-fa::field>
                <x-fa::field label="Trend oder Hype" for="tn-typ"><x-fa::select id="tn-typ" wire:model="neu.typ" placeholder="noch offen" :options="V::TYPEN" /></x-fa::field>
                <x-fa::field label="Ebene" for="tn-ebene"><x-fa::select id="tn-ebene" wire:model="neu.ebene" placeholder="noch offen" :options="V::EBENEN" /></x-fa::field>
                <x-fa::field label="Kategorie" for="tn-kat"><x-fa::select id="tn-kat" wire:model="neu.kategorie" placeholder="noch offen" :options="V::KATEGORIEN" /></x-fa::field>
                <x-fa::field label="Kurzbeschreibung" for="tn-def" optional class="col-span-3"><x-fa::textarea id="tn-def" rows="2" wire:model="neu.definition" /></x-fa::field>
            </div>
            <p class="{{ $leise }}">Der Trend landet als „gesichtet“ in der Liste. Aufs Radar kommt er, sobald er eingeordnet ist und einen bestätigenden Beleg hat.</p>
        </x-foodalchemist::modal-section>
        @if($ausFundstuecken === [])
            <x-foodalchemist::modal-section title="Erster Beleg (optional)">
                <div class="grid grid-cols-2 gap-3">
                    <x-fa::field label="Quelle" for="tn-quelle"><x-fa::select id="tn-quelle" wire:model="neu.quelle" :options="V::QUELLEN" /></x-fa::field>
                    <x-fa::field label="Wo gesehen" for="tn-fundort" optional><x-fa::input id="tn-fundort" wire:model="neu.fundort" placeholder="Studie, Account, Lokal" /></x-fa::field>
                    <x-fa::field label="Link" for="tn-url" optional class="col-span-2"><x-fa::input id="tn-url" wire:model="neu.url" placeholder="https://…" /></x-fa::field>
                    <x-fa::field label="Datei" for="tn-datei" optional hint="Bild oder PDF, max. 15 MB" class="col-span-2">
                        <input id="tn-datei" type="file" wire:model="neuDatei" accept="image/*,application/pdf" class="block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                        <div wire:loading wire:target="neuDatei" class="{{ $leise }}">lädt hoch …</div>
                    </x-fa::field>
                    <x-fa::field label="Notiz" for="tn-notiz" optional class="col-span-2"><x-fa::textarea id="tn-notiz" rows="2" wire:model="neu.notiz" /></x-fa::field>
                </div>
            </x-foodalchemist::modal-section>
        @endif
        <x-slot:footer>
            <x-fa::button variant="ghost" @click="$dispatch('modal.close', { name: 'trend-erfassen' })">Abbrechen</x-fa::button>
            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="erfassen" wire:loading.attr="disabled" data-trend-erfassen-speichern>Trend anlegen</x-fa::button>
        </x-slot:footer>
    </x-foodalchemist::modal>
</x-ui-page>
