{{-- Spec 30/35 — Tagesplanung: Dashboard als Leitstand, Editor als separater Koch-Arbeitsplatz
     im FA-Dark-Editor-Duktus (.fa-editor-panel · kpi-tiles · frosted Sections, wie andere Editoren). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($istWall = $display === 'wall')

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar :title="$modus === 'dashboard' ? 'Tagesplanung' : ($istWall ? 'Küchenmonitor' : 'Tagesplan Editor')" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => $modus === 'dashboard' ? 'Tagesplanung' : 'Tagesplan Editor'],
        ]">
            <div class="flex items-center gap-2">
                @if($modus === 'dashboard')
                    <a href="{{ route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage, 'ansicht' => $ansicht]) }}"
                       wire:navigate class="{{ $btnPrimary }}" data-tagesplan-editor-link>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Editor öffnen</a>
                @else
                    <a href="{{ route('foodalchemist.produktion.tagesplan', ['von' => $von, 'bis' => $bis, 'tage' => $tage]) }}"
                       wire:navigate class="{{ $btnGhostXs }}" data-tagesplan-dashboard-link>@svg('heroicon-o-chart-bar', 'w-3.5 h-3.5') Dashboard</a>
                @endif
                <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht])) }}"
                   target="_blank" class="{{ $btnGhostXs }}" data-tagesplan-drucken>Blatt drucken</a>
                <a href="{{ route('foodalchemist.produktion.wandmonitor', ['von' => $von, 'tage' => 1]) }}"
                   wire:navigate class="{{ $btnGhostXs }}" data-tagesplan-wall-toggle>@svg('heroicon-o-tv', 'w-3.5 h-3.5') Wandmonitor</a>
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    @if($modus === 'dashboard')
        <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
            @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

            <section class="relative overflow-hidden {{ $card }} p-5" data-tagesplanung-dashboard>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="{{ $label }}">Küchenleiter-Dashboard</p>
                        <h1 class="text-2xl font-semibold tracking-tight text-gray-950">Steuerung, Auslastung & Tageshorizont</h1>
                        <p class="text-sm text-gray-500 mt-1">
                            {{ \Illuminate\Support\Carbon::parse($dashboard['von'])->format('d.m.Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($dashboard['bis'])->format('d.m.Y') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-end justify-end gap-2" data-tagesplan-steuerung>
                        <button type="button" wire:click="dashboardTagVerschieben(-1)" class="{{ $btnGhostXs }}">←</button>
                        <label>
                            <span class="{{ $label }}">von</span>
                            <input type="date" wire:model.live="von" class="{{ $input }} !py-1 !w-36" data-tagesplan-von />
                        </label>
                        <label>
                            <span class="{{ $label }}">bis</span>
                            <input type="date" wire:model.live="bis" class="{{ $input }} !py-1 !w-36" data-tagesplan-bis />
                        </label>
                        <button type="button" wire:click="dashboardTagVerschieben(1)" class="{{ $btnGhostXs }}">→</button>
                        <button type="button" wire:click="dashboardHeute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
                        <div class="flex items-center gap-1" data-tagesplanung-dashboard-fenster>
                            @foreach([3 => '3 Tage', 7 => '7 Tage', 14 => '14 Tage', 30 => 'Monat'] as $n => $lbl)
                                <button type="button" wire:click="waehleDashboardFenster({{ $n }})"
                                        class="{{ $pill }} {{ $dashboard['fenster'] === $n ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mt-4" data-tagesplanung-dashboard-kpis>
                    @foreach([
                        ['label' => 'Speisen/Zeilen', 'wert' => $dashboard['kpis']['speisen'], 'hint' => 'im Zeitraum'],
                        ['label' => 'Offen', 'wert' => $dashboard['kpis']['offen'], 'hint' => 'noch zu produzieren'],
                        ['label' => 'Arbeitszeit', 'wert' => $dashboard['kpis']['minuten'], 'hint' => 'Minuten geplant'],
                        ['label' => 'Überlast', 'wert' => $dashboard['kpis']['ueberlast'], 'hint' => 'Posten/Tag'],
                        ['label' => 'Posten', 'wert' => $dashboard['kpis']['posten'], 'hint' => 'belegt'],
                    ] as $kpi)
                        <div class="rounded-2xl border border-black/5 bg-gray-50/80 p-3">
                            <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950">{{ number_format($kpi['wert'], 0, ',', '.') }}</p>
                            <p class="text-[11px] font-medium text-gray-700">{{ $kpi['label'] }}</p>
                            <p class="text-[10px] text-gray-400">{{ $kpi['hint'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_22rem] gap-4">
                <section class="space-y-4">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <div class="{{ $card }} p-4" data-tagesplanung-dashboard-manntage>
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="font-medium tracking-tight text-gray-900">Planung in Manntagen</h3>
                                <span class="{{ $label }}">1 MT = 480 min</span>
                            </div>
                            <div class="mt-3 flex items-end gap-2 min-h-36">
                                @foreach($dashboard['manntage'] as $tag)
                                    @php($hoehe = max(8, (int) round(($tag['wert'] / $dashboard['maxManntage']) * 112)))
                                    <div class="flex-1 min-w-10 text-center">
                                        <div class="mx-auto rounded-t-xl bg-gradient-to-t from-sky-700 to-sky-300 shadow-sm" style="height: {{ $hoehe }}px"></div>
                                        <p class="mt-1 text-[10px] font-medium text-gray-700">{{ \Illuminate\Support\Carbon::parse($tag['tag'])->format('d.m.') }}</p>
                                        <p class="text-[11px] font-semibold tabular-nums text-gray-950">{{ number_format($tag['wert'], 1, ',', '.') }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="{{ $card }} p-4" data-tagesplanung-dashboard-produktion>
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="font-medium tracking-tight text-gray-900">Produktion</h3>
                                <span class="{{ $label }}">Stationen im Fenster</span>
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-3">
                                @foreach([
                                    ['label' => 'Pünktlich', 'wert' => $dashboard['produktionAmpeln']['puenktlich'], 'farbe' => 'text-emerald-600', 'ring' => 'ring-emerald-200 bg-emerald-50'],
                                    ['label' => 'Eng', 'wert' => $dashboard['produktionAmpeln']['verspaetet'], 'farbe' => 'text-amber-600', 'ring' => 'ring-amber-200 bg-amber-50'],
                                    ['label' => 'Kritisch', 'wert' => $dashboard['produktionAmpeln']['kritisch'], 'farbe' => 'text-rose-600', 'ring' => 'ring-rose-200 bg-rose-50'],
                                ] as $ampel)
                                    <div class="rounded-2xl ring-1 {{ $ampel['ring'] }} p-3 text-center">
                                        <div class="mx-auto grid place-items-center w-14 h-14 rounded-full bg-white shadow-sm">
                                            <span class="text-xl font-semibold tabular-nums {{ $ampel['farbe'] }}">{{ $ampel['wert'] }}</span>
                                        </div>
                                        <p class="mt-2 text-[11px] font-medium text-gray-700">{{ $ampel['label'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="{{ $card }} p-4" data-tagesplanung-dashboard-horizont>
                        <div class="flex items-baseline justify-between gap-3 mb-2">
                            <h3 class="font-medium tracking-tight text-gray-900">Tageshorizont</h3>
                            <span class="{{ $label }}">vom Starttag nach vorne</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-2">
                            @foreach($dashboard['tage'] as $tag)
                                @php($tagZeilen = $dashboard['zeilenNachTag']->get($tag, collect()))
                                @php($tagBuckets = collect($dashboard['auslastung'][$tag] ?? []))
                                @php($tagUeberlast = $tagBuckets->where('stufe', 'ueberlast')->count())
                                @php($tagEng = $tagBuckets->where('stufe', 'eng')->count())
                                @php($tone = $tagUeberlast > 0 ? 'border-rose-200 bg-rose-50/70' : ($tagEng > 0 ? 'border-amber-200 bg-amber-50/70' : ($tagZeilen->isNotEmpty() ? 'border-emerald-200 bg-emerald-50/60' : 'border-black/5 bg-gray-50/80')))
                                <article class="rounded-2xl border {{ $tone }} p-3 min-h-36" data-tagesplanung-dashboard-tag="{{ $tag }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-gray-500">{{ \Illuminate\Support\Carbon::parse($tag)->format('D') }}</p>
                                            <h4 class="text-lg font-semibold tracking-tight text-gray-950">{{ \Illuminate\Support\Carbon::parse($tag)->format('d.m.') }}</h4>
                                        </div>
                                        @if($tagUeberlast > 0)
                                            <span class="{{ $pill }} {{ $variantPill['danger'] }}">Überlast</span>
                                        @elseif($tagEng > 0)
                                            <span class="{{ $pill }} {{ $variantPill['warning'] }}">eng</span>
                                        @elseif($tagZeilen->isNotEmpty())
                                            <span class="{{ $pill }} {{ $variantPill['success'] }}">ok</span>
                                        @else
                                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">frei</span>
                                        @endif
                                    </div>
                                    <div class="mt-3 grid grid-cols-3 gap-1 text-center">
                                        <div class="rounded-xl bg-white/70 px-2 py-1"><p class="text-base font-semibold tabular-nums text-gray-950">{{ $tagZeilen->count() }}</p><p class="text-[9px] text-gray-500">Speisen</p></div>
                                        <div class="rounded-xl bg-white/70 px-2 py-1"><p class="text-base font-semibold tabular-nums text-gray-950">{{ (int) $tagZeilen->sum('arbeitszeit_min') }}</p><p class="text-[9px] text-gray-500">Min</p></div>
                                        <div class="rounded-xl bg-white/70 px-2 py-1"><p class="text-base font-semibold tabular-nums text-gray-950">{{ $tagBuckets->count() }}</p><p class="text-[9px] text-gray-500">Posten</p></div>
                                    </div>
                                    <div class="mt-2 space-y-1">
                                        @foreach($tagZeilen->take(3) as $z)
                                            <p class="text-[11px] text-gray-600 truncate">{{ $z->name }} <span class="text-gray-400">· {{ $z->station ?: 'ohne Posten' }}</span></p>
                                        @endforeach
                                        @if($tagZeilen->count() > 3)
                                            <p class="text-[10px] text-gray-400">+ {{ $tagZeilen->count() - 3 }} weitere im Editor</p>
                                        @elseif($tagZeilen->isEmpty())
                                            <p class="text-[11px] text-gray-400">Keine geplante Produktion.</p>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>

                    <div class="{{ $card }} p-4" data-tagesplanung-dashboard-auslastung>
                        <div class="flex items-baseline justify-between gap-3 mb-2">
                            <h3 class="font-medium tracking-tight text-gray-900">Auslastung nach Posten</h3>
                            <span class="{{ $label }}">Kapazität aus Posten/Besetzung</span>
                        </div>
                        @if(empty($dashboard['matrix']))
                            <div class="rounded-2xl border border-dashed border-black/10 bg-gray-50/80 px-4 py-8 text-center text-sm text-gray-500">
                                Noch keine geplante Tagesproduktion im gewählten Zeitraum.
                            </div>
                        @else
                            <div class="overflow-auto rounded-2xl border border-black/5">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-3 py-2 text-left sticky left-0 bg-gray-50 z-10">Posten</th>
                                            @foreach($dashboard['tage'] as $tag)
                                                <th class="px-3 py-2 text-left whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tag)->format('D d.m.') }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-black/5">
                                        @foreach($dashboard['matrix'] as $station => $zellen)
                                            <tr>
                                                <td class="px-3 py-2 font-medium text-gray-900 whitespace-nowrap sticky left-0 bg-white z-10">{{ $station }}</td>
                                                @foreach($dashboard['tage'] as $tag)
                                                    @php($bucket = $zellen[$tag] ?? null)
                                                    @php($tone = match($bucket['stufe'] ?? 'leer') {
                                                        'ueberlast' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                                        'eng' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                                        'ok' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                                        'ohne_kapazitaet' => 'bg-sky-50 text-sky-700 ring-sky-200',
                                                        default => 'bg-gray-50 text-gray-400 ring-gray-100',
                                                    })
                                                    <td class="px-3 py-2 min-w-32">
                                                        <div class="rounded-xl px-3 py-2 ring-1 {{ $tone }}">
                                                            @if($bucket)
                                                                <p class="font-semibold tabular-nums">{{ (int) $bucket['geplant_min'] }} min @if($bucket['prozent'] !== null)<span class="font-normal">/ {{ $bucket['prozent'] }}%</span>@endif</p>
                                                                <p class="text-[10px] opacity-75">{{ (int) $bucket['zeilen'] }} Zeilen @if($bucket['ohne_zeit']) · {{ (int) $bucket['ohne_zeit'] }} ohne Zeit @endif</p>
                                                            @else
                                                                <p class="font-medium">frei</p>
                                                                <p class="text-[10px] opacity-75">keine Belegung</p>
                                                            @endif
                                                        </div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>

                <aside class="space-y-3">
                    <div class="{{ $card }} p-4" data-tagesplanung-dashboard-performance>
                        <h3 class="font-medium tracking-tight text-gray-900">Performance & Engpässe</h3>
                        <div class="mt-3 space-y-2">
                            @forelse($dashboard['performance']->take(8) as $p)
                                @php($breite = $p['prozent'] !== null ? min(100, max(4, $p['prozent'])) : 12)
                                @php($bar = ($p['kritisch'] ?? 0) > 0 ? 'bg-rose-500' : (($p['eng'] ?? 0) > 0 ? 'bg-amber-500' : 'bg-sky-600'))
                                <div>
                                    <div class="flex items-center justify-between gap-2 text-[11px]">
                                        <span class="font-medium text-gray-700 truncate">{{ $p['station'] }}</span>
                                        <span class="tabular-nums text-gray-500">{{ $p['prozent'] !== null ? $p['prozent'] . '%' : (int) $p['minuten'] . ' min' }}</span>
                                    </div>
                                    <div class="mt-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $bar }}" style="width: {{ $breite }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">Noch keine Performance-Daten.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="{{ $card }} p-4" data-tagesplanung-dashboard-next>
                        <h3 class="font-medium tracking-tight text-gray-900">Als nächstes</h3>
                        <div class="mt-3 space-y-2">
                            @forelse($dashboard['naechstes'] as $z)
                                <div class="rounded-xl bg-gray-50 px-3 py-2">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $z->name }}</p>
                                    <p class="text-[11px] text-gray-500">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->station ?: 'ohne Posten' }} · {{ $z->auftrag }}</p>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">Keine offenen Jobs im Fenster.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="{{ $card }} p-4">
                        <h3 class="font-medium tracking-tight text-gray-900">Arbeitsansichten</h3>
                        <p class="text-sm text-gray-500 mt-1">Speisen, Vorlauf, Posten und Abhaken liegen bewusst im separaten Editor.</p>
                        <div class="mt-3 flex flex-col gap-2">
                            <a href="{{ route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage]) }}"
                               wire:navigate class="{{ $btnPrimary }} justify-center" data-tagesplan-editor-link-side>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Tagesplan Editor</a>
                            <button type="button" wire:click="vorschlagen" class="{{ $btnAi }} justify-center" data-tagesplan-vorschlagen>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlag rechnen</button>
                        </div>
                    </div>
                </aside>
            </div>

            @if($vorschlag !== null)
                <div class="{{ $card }} p-4 border-violet-200" data-tagesplan-vorschlag>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-medium text-violet-900">Planungs-Vorschlag</h3>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500">{{ $vorschlag['aenderungen'] }} Änderungen</span>
                            <button type="button" wire:click="vorschlagUebernehmen" class="{{ $btnPrimary }} !py-1">Übernehmen</button>
                            <button type="button" wire:click="vorschlagVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                        </div>
                    </div>
                </div>
            @endif
        </x-ui-page-container>
    @else
        <livewire:foodalchemist.produktion.editor />
        {{-- Dark-Editor-Grund (.fa-editor-panel) — kaskadiert die Hell-Utilities nach dunkel,
             identischer Duktus wie Produktionsauftrag-/Foodbook-Editor (Spec 28/29, kein dark:). --}}
        @include('foodalchemist::partials.editor-dark')

        <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
            @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

            <section class="fa-editor-panel relative overflow-hidden rounded-2xl border shadow-2xl shadow-black/20" data-tagesplan-editor>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>

                {{-- Kopf: Titel + Aktionen --}}
                <div class="border-b border-white/10 px-6 pt-4 pb-3">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Tagesordnung Editor</p>
                            <h1 class="text-xl font-semibold tracking-tight text-gray-900 flex items-center gap-2 min-w-0">
                                <span class="shrink-0">Produktions-Tagesplanung</span>
                                <span class="px-2.5 py-0.5 rounded-lg text-sm bg-violet-500/10 ring-1 ring-violet-500/20 text-violet-700">{{ $ansicht === 'gericht' ? 'Gerichtssicht' : 'Postensicht' }}</span>
                            </h1>
                            <p class="text-sm text-gray-500 mt-1">{{ \Illuminate\Support\Carbon::parse($von)->format('d.m.Y') }} – {{ \Illuminate\Support\Carbon::parse($bis)->format('d.m.Y') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <button type="button" wire:click="vorschlagen" class="{{ $btnAi }}" data-tagesplan-vorschlagen>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlag rechnen</button>
                            <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht])) }}"
                               target="_blank" class="{{ $btnGhostXs }}" data-tagesplan-drucken>Blatt drucken</a>
                        </div>
                    </div>

                    {{-- Zeitfenster-Steuerung + KPI-Streifen (kpi-tiles wie andere Editoren) --}}
                    <div class="mt-4 grid grid-cols-1 lg:grid-cols-[22rem_minmax(0,1fr)] gap-3 items-end">
                        <div data-tagesplan-steuerung>
                            <span class="{{ $label }}">Zeitraum</span>
                            <div class="mt-1 flex flex-wrap items-end gap-1">
                                <button type="button" wire:click="verschiebe(-7)" class="{{ $btnGhostXs }}">‹ Woche</button>
                                <button type="button" wire:click="heute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
                                <button type="button" wire:click="verschiebe(7)" class="{{ $btnGhostXs }}">Woche ›</button>
                                <label class="ml-1"><span class="{{ $label }}">von</span><input type="date" wire:model.live="von" class="{{ $input }} !py-1 !w-32" /></label>
                                <label><span class="{{ $label }}">bis</span><input type="date" wire:model.live="bis" class="{{ $input }} !py-1 !w-32" /></label>
                            </div>
                        </div>
                        <x-foodalchemist::kpi-tiles marker="tagesplan-editor" :tiles="[
                            ['kpi' => 'offen', 'label' => 'Offen', 'tone' => 'accent', 'value' => (string) $dashboard['kpis']['offen']],
                            ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $dashboard['kpis']['minuten'] . ' min'],
                            ['kpi' => 'manntage', 'label' => 'Manntage', 'value' => number_format($dashboard['kpis']['minuten'] / 480, 1, ',', '.')],
                            ['kpi' => 'ueberlast', 'label' => 'Überlast', 'tone' => $dashboard['kpis']['ueberlast'] > 0 ? 'warn' : 'neutral', 'value' => (string) $dashboard['kpis']['ueberlast']],
                            ['kpi' => 'posten', 'label' => 'Posten', 'value' => (string) $dashboard['kpis']['posten']],
                        ]" />
                    </div>
                </div>

                {{-- Körper: Posten-Filter · Tagesordnung · Nächste Jobs --}}
                <div class="grid min-h-[58vh] grid-cols-1 xl:grid-cols-[16rem_minmax(0,1fr)_20rem]">
                    <aside class="border-b border-white/10 p-4 xl:border-b-0 xl:border-r" data-tagesplan-postenfilter>
                        <p class="{{ $label }}">Posten</p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach($postenListe as $p)
                                <button type="button" wire:click="postenWaehlen({{ $p->id }})" wire:key="tpf-{{ $p->id }}"
                                        class="{{ $pill }} {{ $postenFilter === $p->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $p->name }}</button>
                            @endforeach
                            @if($postenFilter !== null)
                                <button type="button" wire:click="postenWaehlen(null)" class="{{ $btnGhostXs }} mt-1">alle</button>
                            @endif
                        </div>
                    </aside>

                    <main class="max-h-[65vh] overflow-auto p-4 space-y-4">
                        @forelse($zeilenNachTag as $tag => $zeilen)
                            @php($tagC = \Illuminate\Support\Carbon::parse($tag))
                            @php($nachPosten = $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id))
                            <section data-modal-zone="section" class="rounded-2xl border border-white/10 overflow-hidden" data-tagesplan-tag="{{ $tag }}">
                                <div class="flex items-baseline gap-2 border-b border-white/10 px-4 py-3">
                                    <h3 class="font-semibold text-gray-900">{{ $tagC->locale('de')->isoFormat('dd DD.MM.') }}</h3>
                                    @if($tagC->isToday())<span class="{{ $pill }} {{ $variantPill['primary'] }}">heute</span>@endif
                                    <span class="text-xs text-gray-500">{{ $zeilen->count() }} Positionen</span>
                                </div>
                                @foreach($auslastung[$tag] ?? [] as $b)
                                    @php($schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'])
                                    @php($blockZeilen = $nachPosten[$schluessel] ?? collect())
                                    @continue($blockZeilen->isEmpty())
                                    <div class="px-4 py-3" data-tagesplan-auslastung>
                                        <div class="mb-2 flex items-center gap-2">
                                            <span class="w-44 shrink-0 truncate text-xs font-semibold text-gray-800">{{ $b['station'] }}</span>
                                            <span class="w-28 shrink-0 text-xs tabular-nums text-gray-500">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) / {{ $b['kapazitaet_min'] }}@endif min</span>
                                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10">
                                                @if($b['kapazitaet_min'] !== null)
                                                    @php($bar = $b['stufe'] === 'ueberlast' ? 'bg-rose-500' : ($b['stufe'] === 'eng' ? 'bg-amber-500' : 'bg-emerald-500'))
                                                    <span class="block h-full {{ $bar }}" style="width: {{ min(100, (int) ($b['prozent'] ?? 0)) }}%"></span>
                                                @endif
                                            </span>
                                            @if($b['stufe'] === 'ueberlast')
                                                <span class="{{ $pill }} {{ $variantPill['danger'] }} shrink-0">{{ $b['prozent'] }} % Überlast</span>
                                            @elseif($b['stufe'] === 'eng')
                                                <span class="{{ $pill }} {{ $variantPill['warning'] }} shrink-0">{{ $b['prozent'] }} %</span>
                                            @endif
                                            @if($b['ohne_zeit'] > 0)<span class="text-[10px] text-amber-600 shrink-0">{{ $b['ohne_zeit'] }} ohne Zeit</span>@endif
                                        </div>
                                        <table class="{{ $table }}">
                                            <tbody>
                                                @foreach($blockZeilen as $z)
                                                    @php($erledigt = $z->line_status === 'done')
                                                    @php($laeuft = $z->auftrag_status === 'in_progress')
                                                    <tr class="{{ $tr }} {{ $erledigt ? 'opacity-60' : '' }}" wire:key="tpz-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}">
                                                        <td class="{{ $td }} w-px">
                                                            @if($laeuft)
                                                                <button type="button" wire:click="abhaken({{ $z->id }})"
                                                                        class="w-5 h-5 rounded border {{ $erledigt ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-white/20 hover:border-violet-400' }} text-[11px] leading-none"
                                                                        title="{{ $erledigt ? 'Haken zurücknehmen' : 'als erledigt abhaken' }}" data-tagesplan-abhaken>{{ $erledigt ? '✓' : '' }}</button>
                                                            @else
                                                                <span class="inline-block w-5 h-5 rounded border border-dashed border-white/15" title="Auftrag läuft noch nicht — abgehakt wird erst ab «in Arbeit»."></span>
                                                            @endif
                                                        </td>
                                                        <td class="{{ $td }} {{ $erledigt ? 'line-through' : '' }} font-medium text-gray-900">
                                                            {{ $z->name }}@if($z->assignee)<span class="text-[11px] text-gray-500 ml-1">· {{ $z->assignee }}</span>@endif
                                                        </td>
                                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">{{ rtrim(rtrim(number_format($z->ansaetze_effektiv, 2, ',', '.'), '0'), ',') }} Ans.</td>
                                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}</td>
                                                        <td class="{{ $td }} whitespace-nowrap">
                                                            <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $z->order_id }} })" class="text-[11px] text-sky-600 hover:underline" data-tagesplan-auftrag>{{ $z->auftrag }}</button>
                                                            <span class="text-[10px] text-gray-500 ml-1">für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span>
                                                        </td>
                                                        <td class="{{ $td }} text-right whitespace-nowrap w-px">
                                                            <input type="text" inputmode="numeric" value="{{ $z->vorlauf_tage }}" wire:change="vorlaufSetzen({{ $z->id }}, $event.target.value)"
                                                                   class="{{ $input }} !py-0.5 !w-14 text-right tabular-nums" title="Tage Vorlauf vor dem Liefertag" data-tagesplan-vorlauf />
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endforeach
                            </section>
                        @empty
                            <div data-modal-zone="section" class="rounded-2xl border border-white/10 px-4 py-10 text-center text-sm text-gray-500" data-tagesplan-leer>
                                In diesem Zeitraum steht nichts an.<br>
                                Der Tagesplan zeigt Zeilen aus <strong>geplanten und laufenden</strong> Aufträgen.
                            </div>
                        @endforelse
                    </main>

                    <aside class="border-t border-white/10 p-4 xl:border-l xl:border-t-0" data-tagesplan-next>
                        <p class="{{ $label }}">Nächste Jobs</p>
                        <div class="mt-3 space-y-2">
                            @forelse($dashboard['naechstes'] as $z)
                                <div data-modal-zone="section" class="rounded-xl border border-white/10 px-3 py-2">
                                    <p class="truncate text-sm font-medium text-gray-900">{{ $z->name }}</p>
                                    <p class="text-[11px] text-gray-500">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->station ?: 'ohne Posten' }}</p>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">Keine Jobs.</p>
                            @endforelse
                        </div>
                    </aside>
                </div>

                @if($vorschlag !== null)
                    <div class="border-t border-white/10 px-6 py-4" data-tagesplan-vorschlag>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-medium text-violet-700">Planungs-Vorschlag · {{ $vorschlag['aenderungen'] }} Änderungen</h3>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="vorschlagUebernehmen" class="{{ $btnPrimary }} !py-1">Übernehmen</button>
                                <button type="button" wire:click="vorschlagVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </x-ui-page-container>
    @endif
</x-ui-page>
