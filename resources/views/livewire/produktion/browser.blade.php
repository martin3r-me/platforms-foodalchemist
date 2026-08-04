{{-- Spec 18/30 — Produktion: Browser-Liste der Produktionsaufträge.
     E4: serverseitig gefiltert + paginiert (schließt MVP-033), Filterbaum mit Zählern,
     Zeitraum-Presets, Spalten-Ansichten, KPI-Zeile.
     E5: das Detail-Panel ist wieder verdrahtet — Zeilen-Klick wählt, der NAME öffnet den
     Editor (Muster: Gerichte-Browser). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Produktion" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-72">
            <div class="p-3 space-y-3">
                <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Name/Anlass suchen …" class="{{ $input }}" data-produktion-suche />

                {{-- Zähler kennen die übrigen aktiven Filter — sonst zeigen sie Treffer an,
                     die die Liste gar nicht liefert (dieselbe Falle wie MVP-048 im VK-Browser). --}}
                <div data-produktion-statusfilter>
                    <x-foodalchemist::filter-row wire:click="waehleStatus('')" :active="$statusFilter === ''" :count="$gesamtCount">
                        <span class="font-medium">Alle Status</span>
                    </x-foodalchemist::filter-row>
                    <x-foodalchemist::filter-ast>
                        @foreach($statusFaelle as $fall)
                            <x-foodalchemist::filter-row level="child" wire:key="pstat-{{ $fall->value }}"
                                wire:click="waehleStatus('{{ $fall->value }}')"
                                :active="$statusFilter === $fall->value"
                                :count="$statusCounts[$fall->value] ?? 0">{{ $fall->label() }}</x-foodalchemist::filter-row>
                        @endforeach
                    </x-foodalchemist::filter-ast>
                </div>

                <div data-produktion-zeitraum>
                    <span class="{{ $label }}">Zeitraum</span>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($zeitraeume as $key => $lbl)
                            <button type="button" wire:click="waehleZeitraum('{{ $key }}')" wire:key="pz-{{ $key }}"
                                class="{{ $pill }} {{ $zeitraum === $key ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <div>
                            <label class="{{ $label }}">von</label>
                            <input type="date" wire:model.live="von" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="{{ $label }}">bis</label>
                            <input type="date" wire:model.live="bis" class="{{ $input }}" />
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        {{-- Eigener Store-Scope: sonst teilen sich alle Detail-Panels des Moduls EIN Toggle-Feld. --}}
        <x-foodalchemist::detail-sidebar title="Auftrag" width="w-96" :maxWidth="760"
                                         scope="activity_produktion" side="right">
            <livewire:foodalchemist.produktion.detail-panel :order-id="$orderId" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <livewire:foodalchemist.produktion.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- Küchenchef-Dashboard: Steuerung direkt im Modul, kein Editor.
             Die Koch-Detailplanung mit Speisen/Anleitungen/Equipment bleibt die separate
             Tagesplan-Seite. --}}
        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_22rem] gap-4" data-produktion-dashboard>
            <section class="relative overflow-hidden {{ $card }} p-5" data-produktion-dashboard-hauptpanel>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex flex-wrap items-start gap-3 justify-between">
                    <div>
                        <p class="{{ $label }}">Küchenchef-Dashboard</p>
                        <h2 class="text-xl font-semibold tracking-tight text-gray-950">Steuerung & Auslastung</h2>
                        <p class="text-sm text-gray-500 mt-1">
                            {{ \Illuminate\Support\Carbon::parse($dashboard['von'])->format('d.m.Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($dashboard['bis'])->format('d.m.Y') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <div class="flex flex-wrap items-end gap-1" data-produktion-dashboard-zeitraum>
                            <button type="button" wire:click="dashboardTagVerschieben(-1)" class="{{ $btnGhostXs }}" title="einen Tag zurück">←</button>
                            <label class="block">
                                <span class="{{ $label }}">von</span>
                                <input type="date" wire:model.live="dashboardVon" class="{{ $input }} !py-1 !w-36" aria-label="Dashboard-Starttag">
                            </label>
                            <label class="block">
                                <span class="{{ $label }}">bis</span>
                                <input type="date" wire:model.live="dashboardBis" class="{{ $input }} !py-1 !w-36" aria-label="Dashboard-Endtag">
                            </label>
                            <button type="button" wire:click="dashboardTagVerschieben(1)" class="{{ $btnGhostXs }}" title="einen Tag vor">→</button>
                            <button type="button" wire:click="dashboardHeute" class="{{ $btnGhostXs }}">heute</button>
                        </div>
                        <div class="flex items-center gap-1" data-produktion-dashboard-fenster>
                            @foreach([3 => '3 Tage', 7 => '7 Tage', 14 => '14 Tage', 30 => 'Monat'] as $tage => $label)
                                <button type="button" wire:click="waehleDashboardFenster({{ $tage }})"
                                        class="{{ $pill }} {{ $dashboard['fenster'] === $tage ? $variantPill['primary'] : $variantPill['secondary'] }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mt-4" data-produktion-dashboard-kpis>
                    @foreach([
                        ['label' => 'Speisen/Zeilen', 'wert' => $dashboard['kpis']['speisen'], 'hint' => 'im Fenster'],
                        ['label' => 'Offen', 'wert' => $dashboard['kpis']['offen'], 'hint' => 'noch zu produzieren'],
                        ['label' => 'Arbeitszeit', 'wert' => $dashboard['kpis']['minuten'], 'hint' => 'Minuten geplant'],
                        ['label' => 'Überlast', 'wert' => $dashboard['kpis']['ueberlast'], 'hint' => 'Posten/Tag'],
                        ['label' => 'Klärfälle', 'wert' => $dashboard['kpis']['klaerfaelle'], 'hint' => 'vor Start'],
                    ] as $kpi)
                        <div class="rounded-2xl border border-black/5 bg-gray-50/80 p-3">
                            <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-950">{{ number_format($kpi['wert'], 0, ',', '.') }}</p>
                            <p class="text-[11px] font-medium text-gray-700">{{ $kpi['label'] }}</p>
                            <p class="text-[10px] text-gray-400">{{ $kpi['hint'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-5" data-produktion-dashboard-lekkarai>
                    <div class="rounded-2xl border border-black/5 bg-white/80 p-4" data-produktion-dashboard-manntage>
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

                    <div class="rounded-2xl border border-black/5 bg-white/80 p-4" data-produktion-dashboard-changelog>
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="font-medium tracking-tight text-gray-900">Change Log</h3>
                            <span class="{{ $label }}">kurzfristige Änderungen</span>
                        </div>
                        <div class="mt-3 space-y-2 max-h-40 overflow-auto">
                            @forelse($dashboard['events'] as $e)
                                <div class="rounded-xl bg-gray-50 px-3 py-2">
                                    <p class="text-[11px] font-medium text-gray-900 truncate">{{ $e->auftrag }}</p>
                                    <p class="text-[10px] text-gray-500">
                                        {{ \Illuminate\Support\Carbon::parse($e->created_at)->format('d.m. H:i') }}
                                        · {{ $e->event_type }}
                                        @if($e->reason_code) · {{ $e->reason_code }} @endif
                                    </p>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">Noch keine protokollierten Änderungen im gewählten Fenster.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-2xl border border-black/5 bg-white/80 p-4" data-produktion-dashboard-produktion>
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="font-medium tracking-tight text-gray-900">Produktion</h3>
                            <span class="{{ $label }}">Stationen im Fenster</span>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-3">
                            @foreach([
                                ['label' => 'Pünktlich', 'wert' => $dashboard['produktionAmpeln']['puenktlich'], 'farbe' => 'text-emerald-600', 'ring' => 'ring-emerald-200 bg-emerald-50'],
                                ['label' => 'Verspätet/eng', 'wert' => $dashboard['produktionAmpeln']['verspaetet'], 'farbe' => 'text-amber-600', 'ring' => 'ring-amber-200 bg-amber-50'],
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

                    <div class="rounded-2xl border border-black/5 bg-white/80 p-4" data-produktion-dashboard-performance>
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="font-medium tracking-tight text-gray-900">Performance & Engpässe</h3>
                            <span class="{{ $label }}">{{ $dashboard['fenster'] }} Tage</span>
                        </div>
                        <div class="mt-3 space-y-2">
                            @forelse($dashboard['performance']->take(6) as $p)
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
                                <p class="text-sm text-gray-500">Noch keine Performance-Daten im gewählten Fenster.</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="mt-5" data-produktion-dashboard-horizont>
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
                            <article class="rounded-2xl border {{ $tone }} p-3 min-h-36" data-produktion-dashboard-tag="{{ $tag }}">
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
                                    <div class="rounded-xl bg-white/70 px-2 py-1">
                                        <p class="text-base font-semibold tabular-nums text-gray-950">{{ $tagZeilen->count() }}</p>
                                        <p class="text-[9px] text-gray-500">Speisen</p>
                                    </div>
                                    <div class="rounded-xl bg-white/70 px-2 py-1">
                                        <p class="text-base font-semibold tabular-nums text-gray-950">{{ (int) $tagZeilen->sum('arbeitszeit_min') }}</p>
                                        <p class="text-[9px] text-gray-500">Min</p>
                                    </div>
                                    <div class="rounded-xl bg-white/70 px-2 py-1">
                                        <p class="text-base font-semibold tabular-nums text-gray-950">{{ $tagBuckets->count() }}</p>
                                        <p class="text-[9px] text-gray-500">Posten</p>
                                    </div>
                                </div>
                                <div class="mt-2 space-y-1">
                                    @foreach($tagZeilen->take(3) as $z)
                                        <p class="text-[11px] text-gray-600 truncate">{{ $z->name }} <span class="text-gray-400">· {{ $z->station ?: 'ohne Posten' }}</span></p>
                                    @endforeach
                                    @if($tagZeilen->count() > 3)
                                        <p class="text-[10px] text-gray-400">+ {{ $tagZeilen->count() - 3 }} weitere in Tagesplanung Details</p>
                                    @elseif($tagZeilen->isEmpty())
                                        <p class="text-[11px] text-gray-400">Keine geplante Produktion.</p>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                <div class="mt-5" data-produktion-dashboard-auslastung>
                    <div class="flex items-baseline justify-between gap-3 mb-2">
                        <h3 class="font-medium tracking-tight text-gray-900">Auslastung nach Posten</h3>
                        <span class="{{ $label }}">Kapazität aus Posten/Besetzung</span>
                    </div>

                    @if(empty($dashboard['matrix']))
                        <div class="rounded-2xl border border-dashed border-black/10 bg-gray-50/80 px-4 py-8 text-center text-sm text-gray-500">
                            Noch keine geplante Produktion im Dashboard-Fenster.
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
                                                @php($stufe = $bucket['stufe'] ?? 'leer')
                                                @php($tone = match($stufe) {
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

                <div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-3" data-produktion-dashboard-tagesordnung>
                    @foreach($dashboard['tage'] as $tag)
                        @php($tagZeilen = $dashboard['zeilenNachTag']->get($tag, collect()))
                        <article class="rounded-2xl border border-black/5 bg-white/80 p-3">
                            <div class="flex items-baseline justify-between gap-2">
                                <h4 class="font-medium text-gray-900">{{ \Illuminate\Support\Carbon::parse($tag)->format('d.m.') }}</h4>
                                <span class="{{ $label }}">{{ $tagZeilen->count() }} Speisen</span>
                            </div>
                            <div class="mt-2 space-y-2">
                                @forelse($tagZeilen->groupBy('order_id') as $orderZeilen)
                                    <div class="rounded-xl bg-gray-50/80 px-3 py-2">
                                        <p class="text-[11px] font-semibold text-gray-800 truncate">{{ $orderZeilen->first()->auftrag }}</p>
                                        <div class="mt-1 space-y-1">
                                            @foreach($orderZeilen->take(4) as $z)
                                                <p class="text-[11px] text-gray-600 truncate">
                                                    {{ $z->name }}
                                                    <span class="text-gray-400">· {{ $z->station ?: 'ohne Posten' }} · {{ (int) $z->arbeitszeit_min }} min</span>
                                                </p>
                                            @endforeach
                                            @if($orderZeilen->count() > 4)
                                                <p class="text-[10px] text-gray-400">+ {{ $orderZeilen->count() - 4 }} weitere</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-400">Keine Tagesordnung.</p>
                                @endforelse
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <aside class="space-y-3" data-produktion-dashboard-steuerung>
                <div class="relative overflow-hidden {{ $card }} p-4">
                    <div class="{{ $cardAccent }}"></div>
                    <h3 class="font-medium tracking-tight text-gray-900">Klärfälle vor Start</h3>
                    <div class="mt-3 space-y-2">
                        @forelse(array_merge($dashboard['readiness']['blockers'], $dashboard['readiness']['warnings']) as $fall)
                            <div class="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-3 py-2">
                                <span class="text-sm text-gray-700">{{ $fall['label'] }}</span>
                                <span class="{{ $pill }} {{ ($fall['level'] ?? '') === 'blocker' ? $variantPill['danger'] : $variantPill['warning'] }}">{{ $fall['count'] }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Alles startklar im gewählten Fenster.</p>
                        @endforelse
                    </div>
                </div>

                <div class="relative overflow-hidden {{ $card }} p-4">
                    <div class="{{ $cardAccent }}"></div>
                    <h3 class="font-medium tracking-tight text-gray-900">Als nächstes</h3>
                    <div class="mt-3 space-y-2">
                        @forelse($dashboard['naechstes'] as $z)
                            <div class="rounded-xl bg-gray-50 px-3 py-2">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $z->name }}</p>
                                <p class="text-[11px] text-gray-500">
                                    {{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }}
                                    · {{ $z->station ?: 'ohne Posten' }}
                                    · {{ $z->auftrag }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Keine offenen Jobs im Fenster.</p>
                        @endforelse
                    </div>
                </div>

                <div class="relative overflow-hidden {{ $card }} p-4">
                    <div class="{{ $cardAccent }}"></div>
                    <h3 class="font-medium tracking-tight text-gray-900">Koch-Ansichten</h3>
                    <p class="text-sm text-gray-500 mt-1">Details, Schritt-für-Schritt-Anleitung und Equipment liegen im Tagesplan.</p>
                    <div class="mt-3 flex flex-col gap-2">
                        <a href="{{ route('foodalchemist.produktion.tagesplan', ['von' => $dashboard['von'], 'tage' => $dashboard['fenster'], 'ansicht' => 'posten']) }}"
                           class="{{ $btnGhostXs }} justify-center" data-produktion-tagesplanung-details>@svg('heroicon-o-calendar-days', 'w-3.5 h-3.5') Tagesplanung Details</a>
                        <a href="{{ route('foodalchemist.produktion.wandmonitor') }}"
                           class="{{ $btnGhostXs }} justify-center" data-produktion-wandmonitor-dashboard>@svg('heroicon-o-tv', 'w-3.5 h-3.5') Wandmonitor</a>
                    </div>
                </div>
            </aside>
        </div>

        {{-- KPI-Zeile: beantwortet „wie ist die Lage", NICHT „was habe ich gefiltert" —
             deshalb bewusst unabhängig von den Filtern. Klick springt in den Ausschnitt. --}}
        @php($mut = 'bg-black/5 text-gray-400')
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-produktion-kpi>
            @foreach([
                ['icon' => 'heroicon-o-clipboard-document-list', 'label' => 'Offene Aufträge', 'wert' => $kpiOffen,
                 'hint' => 'Status geplant', 'aktion' => "waehleStatus('planned')",
                 'tint' => $kpiOffen > 0 ? 'bg-violet-500/10 text-violet-600' : $mut],
                ['icon' => 'heroicon-o-calendar-days', 'label' => 'Heute fällig', 'wert' => $kpiHeuteAuftraege,
                 'hint' => 'Liefertag ist heute', 'aktion' => "waehleZeitraum('heute')",
                 'tint' => $kpiHeuteAuftraege > 0 ? 'bg-amber-500/10 text-amber-600' : $mut],
                ['icon' => 'heroicon-o-clock', 'label' => 'Minuten heute', 'wert' => $kpiHeuteMinuten,
                 'hint' => 'geplante Arbeitszeit aller Posten', 'aktion' => null,
                 'tint' => $kpiHeuteMinuten > 0 ? 'bg-sky-500/10 text-sky-600' : $mut],
                ['icon' => 'heroicon-o-funnel', 'label' => 'Treffer', 'wert' => $gesamtCount,
                 'hint' => 'in der aktuellen Filterung', 'aktion' => null, 'tint' => $mut],
            ] as $k)
                <button type="button" @if($k['aktion']) wire:click="{{ $k['aktion'] }}" @endif
                        class="group relative overflow-hidden {{ $card }} px-4 py-3 text-left {{ $k['aktion'] ? 'hover:-translate-y-0.5 hover:shadow-md transition-all duration-150' : 'cursor-default' }}"
                        wire:key="pkpi-{{ $loop->index }}">
                    <div class="{{ $cardAccent }}"></div>
                    <span class="grid place-items-center w-8 h-8 rounded-lg {{ $k['tint'] }}">@svg($k['icon'], 'w-4 h-4')</span>
                    <p class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900">{{ number_format($k['wert'], 0, ',', '.') }}</p>
                    <p class="text-[11px] font-medium text-gray-700">{{ $k['label'] }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ $k['hint'] }}</p>
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="neuerAuftrag" class="{{ $btnPrimary }}" data-produktion-anlegen>+ Neuer Produktionsauftrag</button>
            <a href="{{ route('foodalchemist.produktion.tagesplan') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-calendar-days', 'w-3.5 h-3.5') Tagesplanung Details</a>
            <a href="{{ route('foodalchemist.produktion.wandmonitor') }}" class="{{ $btnGhostXs }}" data-produktion-wandmonitor>@svg('heroicon-o-tv', 'w-3.5 h-3.5') Wandmonitor</a>

            <span class="ml-auto flex items-center gap-1" data-produktion-ansichten>
                @foreach($ansichten as $key => $def)
                    <button type="button" wire:click="waehleAnsicht('{{ $key }}')" wire:key="pa-{{ $key }}"
                        class="{{ $pill }} {{ $ansicht === $key ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $def[0] }}</button>
                @endforeach
            </span>
            <select wire:model.live="perPage" class="{{ $input }} !py-1 !w-24" data-produktion-perpage>
                @foreach([25, 50, 100, 250] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
            </select>
        </div>

        <div class="relative overflow-hidden {{ $card }}" data-produktion-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900">Produktionsaufträge</h3>
                <span class="{{ $label }}">{{ number_format($auftraege->total(), 0, ',', '.') }} Treffer</span>
            </div>
            <div class="max-h-[70vh] overflow-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr class="text-left">
                            <th class="{{ $th }} w-full sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                            {{-- Kopf folgt dem KATALOG, nicht der Ansicht — sonst versetzt sich die Tabelle. --}}
                            @foreach($spaltenKatalog as $sk => $def)
                                @if(in_array($sk, $spalten, true))
                                    <th class="{{ $th }} {{ $def[1] }} whitespace-nowrap w-px sticky top-0 z-20 bg-white/95 backdrop-blur-xl">{{ $def[0] }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php($einkaufPill = ['keine' => ['—', $variantPill['secondary'] ?? ''], 'offen' => ['offen', $variantPill['warning'] ?? ''], 'versendet' => ['versendet', $variantPill['success'] ?? '']])
                        @forelse($auftraege as $a)
                            @php($aktiv = $a->lines->reject(fn ($l) => (bool) $l->is_struck))
                            @php($ziele = collect($a->targets ?? [])->pluck('label')->filter()->values())
                            <x-foodalchemist::table-row :active="$orderId === $a->id" wire:key="po-{{ $a->id }}"
                                wire:click="waehle({{ $a->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_produktion', 'open', true)"
                                data-produktion-zeile="{{ $a->id }}">
                                <td class="{{ $td }} font-medium text-gray-900 whitespace-nowrap">
                                    <button type="button" wire:click.stop="$dispatch('produktion-editor.bearbeiten', { id: {{ $a->id }} })"
                                            class="text-left hover:text-violet-600" data-produktion-bearbeiten>{{ $a->name ?: $a->reference ?: '—' }}</button>
                                    @if($a->reference && $a->name && $a->reference !== $a->name)<span class="block text-[11px] font-normal text-gray-500">{{ $a->reference }}</span>@endif
                                </td>

                                @foreach($spalten as $sp)
                                    @if($sp === 'ziele')
                                        <td class="{{ $td }} text-gray-600 whitespace-nowrap">
                                            @if($ziele->isEmpty())<span class="text-gray-400">—</span>
                                            @else<span class="text-[12px]">{{ $ziele->take(2)->implode(' · ') }}</span>@if($ziele->count() > 2)<span class="text-[11px] text-gray-400"> +{{ $ziele->count() - 2 }}</span>@endif
                                            @endif
                                        </td>
                                    @elseif($sp === 'ansaetze')
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap text-gray-600">{{ rtrim(rtrim(number_format((float) $aktiv->sum('ansaetze_effektiv'), 2, ',', '.'), '0'), ',') ?: '0' }}</td>
                                    @elseif($sp === 'portionen')
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap text-gray-600">{{ (int) $aktiv->sum('portionen') ?: '—' }}</td>
                                    @elseif($sp === 'zeit')
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap text-gray-600">{{ (int) $aktiv->sum('arbeitszeit_min') ?: '—' }} min</td>
                                    @elseif($sp === 'posten')
                                        @php($postenNamen = $aktiv->pluck('station.name')->filter()->unique())
                                        @php($ohnePosten = $aktiv->whereNull('station_id')->count())
                                        <td class="{{ $td }} text-[11px] text-gray-500 whitespace-nowrap">
                                            {{ $postenNamen->take(2)->implode(' · ') ?: '—' }}@if($ohnePosten > 0)<span class="text-amber-600" title="Zeilen ohne Posten"> · {{ $ohnePosten }} offen</span>@endif
                                        </td>
                                    @elseif($sp === 'datum')
                                        <td class="{{ $td }} whitespace-nowrap tabular-nums">{{ $a->production_date?->format('d.m.Y') }}</td>
                                    @elseif($sp === 'status')
                                        <td class="{{ $td }} whitespace-nowrap"><span class="{{ $pill }} font-medium {{ $variantPill[$a->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $a->status->label() }}</span></td>
                                    @elseif($sp === 'einkauf')
                                        @php($ind = $einkaufPill[$indikatoren[$a->id] ?? 'keine'] ?? $einkaufPill['keine'])
                                        <td class="{{ $td }} whitespace-nowrap"><span class="{{ $pill }} font-medium {{ $ind[1] }}" data-einkauf-indikator="{{ $indikatoren[$a->id] ?? 'keine' }}">{{ $ind[0] }}</span></td>
                                    @endif
                                @endforeach
                            </x-foodalchemist::table-row>
                        @empty
                            <tr><td colspan="{{ count($spalten) + 1 }}" class="px-5 py-10 text-center text-gray-500" data-produktion-leer>
                                Kein Auftrag im gewählten Ausschnitt — Filter zurücksetzen oder oben einen neuen anlegen.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($auftraege->hasPages())
                <div class="px-5 py-3 border-t border-black/5" data-produktion-pagination>{{ $auftraege->links() }}</div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
