{{-- Spec 30/35 — Tagesplanung: Dashboard als Leitstand, Editor als separater Koch-Arbeitsplatz
     im FA-Dark-Editor-Duktus (.fa-editor-panel · kpi-tiles · frosted Sections, wie andere Editoren). --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $istWall = $display === 'wall';
@endphp

<div class="{{ $istWall ? '' : 'h-full min-h-0' }}">
@if($istWall)
    @include('foodalchemist::partials.editor-dark')
    @php
        $wallTag = \Illuminate\Support\Carbon::parse($von)->toDateString();
    @endphp
    @php
        $wallZeilen = $zeilenNachTag->get($wallTag, collect());
    @endphp
    @php
        $wallBuckets = collect($auslastung[$wallTag] ?? []);
    @endphp
    @php
        $offen = $wallZeilen->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true))->count();
    @endphp
    @php
        $fertig = $wallZeilen->filter(fn ($z) => $z->line_status === 'done')->count();
    @endphp
    @php
        $krit = $wallBuckets->where('stufe', 'ueberlast')->count();
    @endphp
    @php
        $gesamtMin = (int) $wallZeilen->sum('arbeitszeit_min');
    @endphp

    <div class="h-screen w-screen overflow-hidden bg-slate-950 text-white"
         data-tagesplan-wall data-tagesplan-wall-kiosk wire:poll.30s>
        <div class="flex h-full min-h-0 flex-col">
            <header class="shrink-0 border-b border-white/10 bg-slate-950/95 px-5 py-4 shadow-2xl shadow-black/30">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-4">
                        <a href="{{ route('foodalchemist.produktion.tagesplan', ['von' => $von, 'bis' => $bis, 'tage' => $tage]) }}"
                           onclick="try { if (document.fullscreenElement) document.exitFullscreen(); } catch (_) {}"
                           class="inline-flex h-14 shrink-0 items-center gap-2 rounded-2xl border border-white/10 bg-white/10 px-4 text-sm font-bold uppercase tracking-wide hover:bg-white/15"
                           aria-label="Zurück zur Tagesplanung" data-tagesplan-wall-zurueck>
                            <span class="text-2xl leading-none">‹</span><span>Zurück</span>
                        </a>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-violet-200">Küchenmonitor · aktualisiert alle 30 Sekunden</p>
                            <h1 class="truncate text-3xl font-bold tracking-tight md:text-5xl">
                                {{ \Illuminate\Support\Carbon::parse($wallTag)->locale('de')->isoFormat('dddd, D. MMMM') }}
                            </h1>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2" data-tagesplan-steuerung>
                        <button type="button" wire:click="verschiebe(-1)" class="h-12 rounded-xl border border-white/10 bg-white/10 px-4 text-lg font-semibold hover:bg-white/15">‹</button>
                        <button type="button" wire:click="heute" class="h-12 rounded-xl border border-white/10 bg-white/10 px-5 text-sm font-semibold uppercase tracking-wide hover:bg-white/15" data-tagesplan-heute>heute</button>
                        <button type="button" wire:click="verschiebe(1)" class="h-12 rounded-xl border border-white/10 bg-white/10 px-4 text-lg font-semibold hover:bg-white/15">›</button>
                        <button type="button"
                                x-data
                                x-on:click="(async () => { const root = document.documentElement; if (!root.requestFullscreen) return; try { document.fullscreenElement ? await document.exitFullscreen() : await root.requestFullscreen(); } catch (_) {} })()"
                                title="Browser-Vollbild umschalten"
                                class="h-12 rounded-xl bg-violet-500 px-5 text-sm font-semibold shadow-lg shadow-violet-950/40 hover:bg-violet-400"
                                data-tagesplan-wall-fullscreen>Vollbild</button>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-6">
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-3 text-center"><p class="text-3xl font-bold tabular-nums">{{ $offen }}</p><p class="text-[11px] uppercase tracking-wide text-slate-300">offen</p></div>
                    <div class="rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-3 text-center"><p class="text-3xl font-bold tabular-nums text-emerald-300">{{ $fertig }}</p><p class="text-[11px] uppercase tracking-wide text-emerald-100">erledigt</p></div>
                    <div class="rounded-2xl border {{ $krit > 0 ? 'border-rose-400/40 bg-rose-500/15' : 'border-white/10 bg-white/10' }} p-3 text-center"><p class="text-3xl font-bold tabular-nums {{ $krit > 0 ? 'text-rose-300' : '' }}">{{ $krit }}</p><p class="text-[11px] uppercase tracking-wide text-slate-300">Überlast</p></div>
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-3 text-center"><p class="text-3xl font-bold tabular-nums">{{ $gesamtMin }}</p><p class="text-[11px] uppercase tracking-wide text-slate-300">Minuten</p></div>
                    @foreach(collect($readiness)->take(2) as $f)
                        <div class="rounded-2xl border {{ $f['level'] === 'blocker' ? 'border-rose-400/40 bg-rose-500/15' : 'border-amber-400/40 bg-amber-500/15' }} p-3 text-center" data-tagesplan-readiness>
                            <p class="text-3xl font-bold tabular-nums {{ $f['level'] === 'blocker' ? 'text-rose-300' : 'text-amber-200' }}">{{ $f['count'] }}</p>
                            <p class="text-[11px] uppercase tracking-wide text-slate-200">{{ $f['label'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-2xl border border-white/10 bg-white/5 p-1" data-tagesplan-wall-ansicht>
                        <button type="button" wire:click="wallAnsichtSetzen('lanes')" class="rounded-xl px-5 py-2 text-sm font-semibold {{ $wallAnsicht === 'lanes' ? 'bg-violet-500 text-white' : 'text-slate-300' }}">Posten-Lanes</button>
                        <button type="button" wire:click="wallAnsichtSetzen('mise')" class="rounded-xl px-5 py-2 text-sm font-semibold {{ $wallAnsicht === 'mise' ? 'bg-violet-500 text-white' : 'text-slate-300' }}" data-tagesplan-wall-mise>Mise en Place</button>
                    </div>
                    @if($wallAnsicht === 'lanes')
                        @php
                            $wallFilterGruppen = $wallPostenGruppen->flatten(1);
                            $wallFilterAlle = $wallFilterGruppen->count();
                            $wallFilterGerichte = $wallFilterGruppen->filter(fn ($gruppe) => (bool) ($gruppe->hat_gericht ?? false))->count();
                            $wallFilterBasis = $wallFilterAlle - $wallFilterGerichte;
                        @endphp
                        <div class="inline-flex rounded-2xl border border-white/10 bg-white/5 p-1" data-tagesplan-wall-gruppenfilter>
                            <button type="button" wire:click="wallGruppenFilterSetzen('alle')" class="rounded-xl px-4 py-2 text-sm font-semibold {{ $wallGruppenFilter === 'alle' ? 'bg-violet-500 text-white' : 'text-slate-300' }}" data-tagesplan-wall-filter-alle>Alle <span class="ml-1 text-xs opacity-75">{{ $wallFilterAlle }}</span></button>
                            <button type="button" wire:click="wallGruppenFilterSetzen('gerichte')" class="rounded-xl px-4 py-2 text-sm font-semibold {{ $wallGruppenFilter === 'gerichte' ? 'bg-violet-500 text-white' : 'text-slate-300' }}" data-tagesplan-wall-filter-gerichte>Gerichte <span class="ml-1 text-xs opacity-75">{{ $wallFilterGerichte }}</span></button>
                            <button type="button" wire:click="wallGruppenFilterSetzen('basis')" class="rounded-xl px-4 py-2 text-sm font-semibold {{ $wallGruppenFilter === 'basis' ? 'bg-violet-500 text-white' : 'text-slate-300' }}" data-tagesplan-wall-filter-basis>Basisrezepte <span class="ml-1 text-xs opacity-75">{{ $wallFilterBasis }}</span></button>
                        </div>
                    @endif
                    @if($postenFilter !== null)
                        <button type="button" wire:click="postenWaehlen({{ $postenFilter }})"
                                class="inline-flex items-center gap-2 rounded-full border border-violet-300 bg-violet-500/20 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-violet-100"
                                data-tagesplan-wall-station-reset>Alle Stationen</button>
                    @endif
                    @foreach($wallBuckets as $b)
                        @php
                            $dot = ['ueberlast' => 'bg-rose-400', 'eng' => 'bg-amber-300', 'ok' => 'bg-emerald-300'][$b['stufe']] ?? 'bg-slate-400';
                        @endphp
                        <button type="button" wire:click="postenWaehlen({{ $b['station_id'] === null ? 'null' : (int) $b['station_id'] }})"
                                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs text-slate-200 {{ $postenFilter === $b['station_id'] ? 'border-violet-300 bg-violet-500/20' : 'border-white/10 bg-white/5' }}"
                                data-tagesplan-ampeln data-tagesplan-wall-station-filter>
                            <span class="h-2.5 w-2.5 rounded-full {{ $dot }}"></span>{{ $b['station'] }}
                            <span class="tabular-nums text-slate-400">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null)/{{ $b['kapazitaet_min'] }}@endif</span>
                        </button>
                    @endforeach
                </div>
            </header>

            <main class="min-h-0 flex-1 overflow-hidden p-4">
                @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

                @if($wallAnsicht === 'mise')
                    <section class="h-full min-h-0 overflow-y-auto rounded-3xl border border-white/10 bg-white/5 p-4" data-tagesplan-mise>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                            @forelse($miseEnPlace as $m)
                                @php
                                    $miseFertig = $m->gesamt > 0 && $m->erledigt === $m->gesamt;
                                @endphp
                                <article class="flex min-h-40 gap-3 rounded-2xl border border-white/10 bg-slate-900/80 p-4 text-left shadow-xl transition hover:border-violet-300/70 {{ $miseFertig ? 'opacity-60' : '' }}" data-tagesplan-mise-karte>
                                    <button type="button" wire:click="abhakenMise({{ $m->erste_line_id }})"
                                            class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl border text-3xl font-bold {{ $miseFertig ? 'border-emerald-400 bg-emerald-500 text-white' : 'border-dashed border-violet-300/70 bg-violet-500/10 text-violet-100 hover:bg-violet-500/20' }}"
                                            title="{{ $miseFertig ? 'Mise-en-Place zurücknehmen' : 'Mise-en-Place erledigt abhaken' }}"
                                            data-tagesplan-mise-abhaken>{{ $miseFertig ? '✓' : '' }}</button>
                                    <button type="button" wire:click="{{ $m->ist_gericht ? 'oeffneGericht(' . \Illuminate\Support\Js::from($m->gericht_key) . ')' : 'oeffneAnleitung(' . (int) $m->erste_line_id . ')' }}" class="min-w-0 flex-1 text-left" data-tagesplan-mise-anleitung @if($m->ist_gericht) data-tagesplan-mise-gericht @endif>
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-xl font-bold leading-tight {{ $miseFertig ? 'line-through' : '' }}">{{ $m->name }}</p>
                                            @if($m->ist_gericht)
                                                <span class="rounded-full bg-violet-400/15 px-2 py-1 text-xs font-semibold text-violet-100">Gericht</span>
                                            @elseif($m->ist_basisrezept)
                                                <span class="rounded-full bg-white/10 px-2 py-1 text-xs text-slate-300">Basis</span>
                                            @endif
                                        </div>
                                        <p class="mt-3 text-sm text-slate-300">{{ $m->anzahl }}× · {{ $m->erledigt }}/{{ $m->gesamt }} erledigt · {{ $m->minuten }} min</p>
                                        <p class="mt-1 truncate text-sm text-slate-400">für {{ $m->auftraege->implode(', ') }}</p>
                                        @if($m->stationen->isNotEmpty())<p class="mt-1 text-sm text-slate-400">Posten: {{ $m->stationen->implode(', ') }}</p>@endif
                                        @if(collect($m->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($m->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($m->sicherheit['diaet'] ?? [])->isNotEmpty())
                                            <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                                @foreach(collect($m->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                    <span class="rounded-full bg-amber-400/15 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-amber-100" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                                @endforeach
                                                @foreach(collect($m->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                    <span class="rounded-full bg-rose-400/15 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-rose-100" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                                @endforeach
                                                @foreach(collect($m->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                    <span class="rounded-full bg-emerald-400/15 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-emerald-100" data-tagesplan-wall-diaet>{{ $d }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </button>
                                </article>
                            @empty
                                <div class="grid min-h-[55vh] place-items-center text-center text-2xl font-semibold text-slate-300" data-tagesplan-leer>Heute steht nichts an.</div>
                            @endforelse
                        </div>
                    </section>
                @else
                    @php
                        $nachPosten = $wallPostenGruppen
                            ->map(fn ($gruppen) => $gruppen
                                ->filter(fn ($gruppe) => $wallGruppenFilter === 'alle'
                                    || ($wallGruppenFilter === 'gerichte' && (bool) ($gruppe->hat_gericht ?? false))
                                    || ($wallGruppenFilter === 'basis' && ! (bool) ($gruppe->hat_gericht ?? false)))
                                ->values())
                            ->filter(fn ($gruppen) => $gruppen->isNotEmpty());
                    @endphp
                    @php
                        $sichtbareBuckets = $wallBuckets->filter(fn ($b) => ($nachPosten[$b['station_id'] === null ? '_none' : (int) $b['station_id']] ?? collect())->isNotEmpty())->values();
                    @endphp
                    @php
                        $einzelLane = $sichtbareBuckets->count() <= 1;
                    @endphp
                    @if($wallZeilen->isEmpty() || $sichtbareBuckets->isEmpty())
                        <div class="grid h-full place-items-center rounded-3xl border border-white/10 bg-white/5 text-center" data-tagesplan-leer>
                            <div>
                                <p class="text-4xl font-bold">{{ $wallGruppenFilter === 'gerichte' ? 'Keine Gerichte im Fenster.' : ($wallGruppenFilter === 'basis' ? 'Keine Basisrezepte ohne Gericht.' : 'Heute steht nichts an.') }}</p>
                                <p class="mt-2 text-lg text-slate-400">Der Küchenmonitor aktualisiert sich automatisch.</p>
                            </div>
                        </div>
                    @else
                        <div class="{{ $einzelLane ? 'h-full min-h-0' : 'grid h-full min-h-0 grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4' }}" data-tagesplan-lanes @if($einzelLane) data-tagesplan-wall-single-lane @endif>
                            @foreach($sichtbareBuckets as $b)
                                @php
                                    $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                @endphp
                                @php
                                    $laneGruppen = $nachPosten[$schluessel] ?? collect();
                                @endphp
                                @php
                                    $laneZeilenAnzahl = $laneGruppen->sum(fn ($gruppe) => $gruppe->gesamt);
                                @endphp
                                @php
                                    $laneGruppenLabel = $wallGruppenFilter === 'gerichte'
                                        ? ($laneGruppen->count() === 1 ? 'Gericht' : 'Gerichte')
                                        : ($laneGruppen->count() === 1 ? 'Arbeitsblock' : 'Arbeitsblöcke');
                                @endphp
                                @php
                                    $tone = ['ueberlast' => 'border-rose-400/50', 'eng' => 'border-amber-300/50', 'ok' => 'border-emerald-300/40'][$b['stufe']] ?? 'border-white/10';
                                @endphp
                                <section class="flex h-full min-h-0 flex-col overflow-hidden rounded-3xl border {{ $tone }} bg-slate-900/90 shadow-2xl" data-tagesplan-lane="{{ $schluessel }}">
                                    <div class="shrink-0 border-b border-white/10 px-5 py-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h2 class="truncate text-2xl font-bold">{{ $b['station'] }}</h2>
                                                <p class="text-sm text-slate-400">{{ $laneGruppen->count() }} {{ $laneGruppenLabel }} · {{ $laneZeilenAnzahl }} Jobs · {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null)/{{ $b['kapazitaet_min'] }}@endif min</p>
                                            </div>
                                            @if($b['stufe'] === 'ueberlast')
                                                <span class="rounded-full bg-rose-500/20 px-3 py-1 text-xs font-bold uppercase tracking-wide text-rose-100">Überlast</span>
                                            @elseif($b['stufe'] === 'eng')
                                                <span class="rounded-full bg-amber-500/20 px-3 py-1 text-xs font-bold uppercase tracking-wide text-amber-100">Eng</span>
                                            @else
                                                <span class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold uppercase tracking-wide text-emerald-100">Ok</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="min-h-0 flex-1 overflow-y-auto p-4">
                                        <div class="{{ $einzelLane ? 'grid auto-rows-max grid-cols-1 gap-4 xl:grid-cols-2' : 'space-y-4' }}" data-tagesplan-wall-lane-jobs>
                                        @foreach($laneGruppen as $gruppe)
                                            @php
                                                $gruppeFertig = $gruppe->gesamt > 0 && $gruppe->erledigt === $gruppe->gesamt;
                                            @endphp
                                            <article class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950/45 {{ $gruppeFertig ? 'opacity-60' : '' }}" data-tagesplan-wall-gericht-gruppe>
                                                <div class="border-b border-white/10 bg-white/[0.04] px-4 py-3" data-tagesplan-wall-gericht>
                                                    @if($gruppe->hat_gericht)
                                                        <button type="button" wire:click="oeffneGericht(@js($gruppe->key))"
                                                                class="flex w-full items-start justify-between gap-3 text-left"
                                                                data-tagesplan-wall-gericht-open>
                                                            <div class="min-w-0">
                                                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Gericht</p>
                                                                <h3 class="mt-1 whitespace-normal break-words text-2xl font-bold leading-tight">{{ $gruppe->gericht }}</h3>
                                                                <p class="mt-2 text-sm text-slate-400">{{ $gruppe->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($gruppe->liefertag)->format('d.m.') }}</p>
                                                            </div>
                                                            <div class="shrink-0 text-right">
                                                                <p class="text-xl font-bold tabular-nums">{{ $gruppe->erledigt }}/{{ $gruppe->gesamt }}</p>
                                                                <p class="text-[11px] uppercase tracking-wide text-slate-400">erledigt</p>
                                                                <p class="mt-2 rounded-full bg-violet-500/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-violet-100">öffnen</p>
                                                            </div>
                                                        </button>
                                                    @else
                                                        <div class="flex w-full items-start justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Arbeitsblock</p>
                                                                <h3 class="mt-1 whitespace-normal break-words text-2xl font-bold leading-tight">{{ $gruppe->gericht }}</h3>
                                                                <p class="mt-2 text-sm text-slate-400">{{ $gruppe->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($gruppe->liefertag)->format('d.m.') }}</p>
                                                            </div>
                                                            <div class="shrink-0 text-right">
                                                                <p class="text-xl font-bold tabular-nums">{{ $gruppe->erledigt }}/{{ $gruppe->gesamt }}</p>
                                                                <p class="text-[11px] uppercase tracking-wide text-slate-400">erledigt</p>
                                                            </div>
                                                        </div>
                                                    @endif
                                                    @if(collect($gruppe->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($gruppe->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($gruppe->sicherheit['diaet'] ?? [])->isNotEmpty())
                                                        <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                                            @foreach(collect($gruppe->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                                <span class="rounded-full bg-amber-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-100" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                                            @endforeach
                                                            @foreach(collect($gruppe->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                                <span class="rounded-full bg-rose-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-rose-100" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                                            @endforeach
                                                            @foreach(collect($gruppe->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                                <span class="rounded-full bg-emerald-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-100" data-tagesplan-wall-diaet>{{ $d }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                                @if(! $gruppe->hat_gericht)
                                                <div class="space-y-2 p-3" data-tagesplan-wall-rezepte>
                                                    @foreach($gruppe->zeilen as $z)
                                                        @php
                                                            $erledigt = $z->line_status === 'done';
                                                        @endphp
                                                        @php
                                                            $laeuft = $z->auftrag_status === 'in_progress';
                                                        @endphp
                                                        @php
                                                            $rezeptTitel = $z->rezept_label ?: $z->name;
                                                        @endphp
                                                        <div class="flex min-h-28 gap-3 rounded-2xl border border-white/10 bg-white/[0.06] p-3 {{ $erledigt ? 'opacity-55' : '' }}" wire:key="wk-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}" data-tagesplan-wall-rezept>
                                                            <button type="button" wire:click="abhaken({{ $z->id }})"
                                                                    class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl border text-3xl font-bold {{ $erledigt ? 'border-emerald-400 bg-emerald-500 text-white' : ($laeuft ? 'border-white/25 bg-slate-950/60 text-white hover:border-violet-300' : 'border-dashed border-violet-300/70 bg-violet-500/10 text-violet-100 hover:bg-violet-500/20') }}"
                                                                    title="{{ $erledigt ? 'Haken zurücknehmen' : ($laeuft ? 'Als erledigt abhaken' : 'Auftrag starten und erledigt abhaken') }}"
                                                                    data-tagesplan-abhaken>{{ $erledigt ? '✓' : '' }}</button>
                                                            <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="min-w-0 flex-1 text-left" data-tagesplan-wall-karte>
                                                                <div class="flex items-start justify-between gap-2">
                                                                    <div class="min-w-0">
                                                                        <p class="text-[11px] font-bold uppercase tracking-wide text-violet-200">{{ $z->is_basisrezept ? 'Basisrezept' : 'Rezept' }}</p>
                                                                        <p class="mt-0.5 whitespace-normal break-words text-xl font-bold leading-tight {{ $erledigt ? 'line-through' : '' }}">{{ $rezeptTitel }}</p>
                                                                    </div>
                                                                    @if($z->is_basisrezept)<span class="shrink-0 rounded-full bg-emerald-400/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-100">Basis</span>@endif
                                                                </div>
                                                                <p class="mt-2 text-sm text-slate-400">
                                                                    @if($z->gesamt_kg !== null){{ rtrim(rtrim(number_format((float) $z->gesamt_kg, 3, ',', '.'), '0'), ',') }} kg · @endif{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'ohne Zeit' }}
                                                                </p>
                                                                @if(collect($z->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($z->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($z->sicherheit['diaet'] ?? [])->isNotEmpty())
                                                                    <div class="mt-2 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                                                        @foreach(collect($z->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                                            <span class="rounded-full bg-amber-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-100" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                                                        @endforeach
                                                                        @foreach(collect($z->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                                            <span class="rounded-full bg-rose-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-rose-100" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                                                        @endforeach
                                                                        @foreach(collect($z->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                                            <span class="rounded-full bg-emerald-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-100" data-tagesplan-wall-diaet>{{ $d }}</span>
                                                                        @endforeach
                                                                    </div>
                                                                @endif
                                                                @if(!$laeuft && !$erledigt)<p class="mt-2 text-xs font-bold uppercase tracking-wide text-violet-200">Startet beim Abhaken</p>@endif
                                                                @if($z->blocked_reason)<p class="mt-2 rounded-lg bg-rose-500/15 px-2 py-1 text-sm text-rose-100">blockiert: {{ $z->blocked_reason }}</p>@endif
                                                            </button>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @endif
                                            </article>
                                        @endforeach
                                        </div>
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @endif
                @endif
            </main>
        </div>

        <x-foodalchemist::modal name="wall-gericht" fullscreen dark-canvas title="Gericht" :title-name="$wallGericht->gericht ?? null" :close-via="'gerichtSchliessen'">
            <x-slot:actions>
                <button type="button" x-data @click="$wire.gerichtSchliessen(); close()"
                        class="inline-flex h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/10 px-4 text-xs font-bold uppercase tracking-wide text-white hover:bg-white/15"
                        data-tagesplan-wall-gericht-zurueck>
                    <span class="text-lg leading-none">‹</span>Zurück zum Monitor
                </button>
            </x-slot:actions>
            @if($wallGericht)
                <div class="space-y-4" data-tagesplan-wall-gericht-detail>
                    <header class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs font-bold uppercase tracking-wide text-violet-200">{{ $wallGericht->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($wallGericht->liefertag)->format('d.m.') }}</p>
                                <h2 class="mt-1 whitespace-normal break-words text-3xl font-bold leading-tight">{{ $wallGericht->gericht }}</h2>
                            </div>
                            <div class="grid min-w-48 grid-cols-2 gap-2 text-center">
                                <div class="rounded-xl border border-white/10 bg-slate-950/45 px-3 py-2">
                                    <p class="text-2xl font-bold tabular-nums">{{ $wallGericht->erledigt }}/{{ $wallGericht->gesamt }}</p>
                                    <p class="text-[11px] uppercase tracking-wide text-slate-400">erledigt</p>
                                </div>
                                <div class="rounded-xl border border-white/10 bg-slate-950/45 px-3 py-2">
                                    <p class="text-2xl font-bold tabular-nums">{{ $wallGericht->minuten }}</p>
                                    <p class="text-[11px] uppercase tracking-wide text-slate-400">Minuten</p>
                                </div>
                            </div>
                        </div>
                        @if(collect($wallGericht->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($wallGericht->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($wallGericht->sicherheit['diaet'] ?? [])->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                @foreach(collect($wallGericht->sicherheit['warnungen'] ?? [])->take(4) as $warnung)
                                    <span class="rounded-full bg-amber-400/15 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-amber-100" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                @endforeach
                                @foreach(collect($wallGericht->sicherheit['allergene'] ?? [])->take(6) as $a)
                                    <span class="rounded-full bg-rose-400/15 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-rose-100" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                @endforeach
                                @foreach(collect($wallGericht->sicherheit['diaet'] ?? [])->take(4) as $d)
                                    <span class="rounded-full bg-emerald-400/15 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-emerald-100" data-tagesplan-wall-diaet>{{ $d }}</span>
                                @endforeach
                            </div>
                        @endif
                    </header>

                    {{-- §3.2 Regeneration am Pass: das Programm je Komponente aus dem eingefrorenen
                         Auftrag. Bis 2026-09-04 stand hier nichts — die Kachel unten las die
                         Regenerations-Skalare der Standard-Darreichung, die kein Schreibpfad füllt. --}}
                    @if(collect($wallGericht->regeneration ?? [])->isNotEmpty())
                        <section class="rounded-2xl border border-white/10 bg-white/5 p-4" data-tagesplan-wall-gericht-regeneration>
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Regeneration</p>
                            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach($wallGericht->regeneration as $reg)
                                    <div class="rounded-xl bg-slate-950/45 px-3 py-2">
                                        <p class="text-base font-semibold text-slate-100">{{ $reg['komponente'] ?? '—' }}</p>
                                        <p class="mt-0.5 text-sm text-slate-300">
                                            {{ collect([
                                                $reg['geraet'] ?? null,
                                                ($reg['temp_c'] ?? null) !== null ? $reg['temp_c'] . ' °C' : null,
                                                ($reg['duration_min'] ?? null) !== null ? $reg['duration_min'] . ' min' : null,
                                                ($reg['core_temp_c'] ?? null) !== null ? 'KT ' . $reg['core_temp_c'] . ' °C' : null,
                                            ])->filter()->implode(' · ') }}
                                        </p>
                                        @if($reg['note'] ?? null)
                                            <p class="mt-0.5 text-xs text-slate-400">{{ $reg['note'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($wallGericht->anrichten || collect($wallGericht->darreichung ?? [])->isNotEmpty())
                        <section class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]" data-tagesplan-wall-gericht-service>
                            @if($wallGericht->anrichten)
                                <article class="rounded-2xl border border-white/10 bg-white/5 p-4" data-tagesplan-wall-gericht-anrichten>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Anrichten</p>
                                    @if(collect($wallGericht->anrichten_schritte ?? [])->isNotEmpty())
                                        <ol class="mt-2 space-y-3">
                                            @foreach($wallGericht->anrichten_schritte as $s)
                                                <li class="flex gap-3">
                                                    <span class="mt-0.5 text-base font-bold text-slate-500">{{ $s['nr'] ?? $loop->iteration }}</span>
                                                    <div class="min-w-0">
                                                        <p class="text-lg font-semibold leading-snug text-slate-100">{{ $s['text'] ?? '' }}</p>
                                                        @if(collect($s['fotos'] ?? [])->isNotEmpty())
                                                            <div class="mt-2 flex flex-wrap gap-2">
                                                                @foreach($s['fotos'] as $foto)
                                                                    @if($foto['url'] ?? null)
                                                                        <img src="{{ $foto['url'] }}" alt="{{ $foto['caption'] ?? 'Anrichten' }}"
                                                                             class="h-24 w-32 rounded-lg object-cover" />
                                                                    @endif
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ol>
                                    @else
                                        <p class="mt-2 whitespace-pre-line text-lg font-semibold leading-snug text-slate-100">{{ $wallGericht->anrichten }}</p>
                                    @endif
                                </article>
                            @endif
                            {{-- Spec 51: auch wenn es kein Geschirr gibt. Eine Basisrezept-Gruppe hat
                                 keine Servierform, aber sehr wohl einen Abfuell-Bedarf — an der
                                 alten Bedingung waere er wortlos verschwunden. --}}
                            @if(collect($wallGericht->darreichung ?? [])->isNotEmpty() || collect($wallGericht->behaelter ?? [])->isNotEmpty())
                                <article class="rounded-2xl border border-white/10 bg-white/5 p-4" data-tagesplan-wall-gericht-geschirr>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Geschirr & Ausgabe</p>
                                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-1">
                                        {{-- Spec 51: was gepackt werden muss, steht oben — es ist die
                                             Handlung. Aggregiert ueber ALLE Zeilen der Gruppe, nicht
                                             nur die erste (Abfuellen haengt an den Produktionszeilen,
                                             Regenerieren an der Gericht-Zeile). --}}
                                        @foreach($wallGericht->behaelter ?? [] as $info)
                                            <div class="rounded-xl bg-violet-500/10 border border-violet-500/25 px-3 py-2" data-wall-behaelter>
                                                <p class="text-[11px] font-bold uppercase tracking-wide text-violet-300">{{ $info['label'] }}</p>
                                                <p class="mt-0.5 text-base font-semibold text-slate-100">{{ $info['wert'] }}</p>
                                            </div>
                                        @endforeach
                                        @foreach($wallGericht->darreichung as $info)
                                            <div class="rounded-xl bg-slate-950/45 px-3 py-2">
                                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ $info['label'] }}</p>
                                                <p class="mt-0.5 text-base font-semibold text-slate-100">{{ $info['wert'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endif
                        </section>
                    @endif

                    <section class="rounded-2xl border border-white/10 bg-white/5 p-4" data-tagesplan-wall-gericht-uebersicht>
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Rezeptübersicht</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-100">Was für dieses Gericht erledigt werden muss</h3>
                            </div>
                            <p class="text-sm font-semibold text-slate-400">{{ $wallGericht->erledigt }}/{{ $wallGericht->gesamt }} erledigt</p>
                        </div>
                        <div class="mt-3 divide-y divide-white/10 rounded-2xl border border-white/10 bg-slate-950/35" data-tagesplan-wall-gericht-uebersicht-liste>
                            @foreach($wallGericht->rezept_uebersicht as $eintrag)
                                <div class="flex items-center gap-3 px-3 py-2.5 {{ $eintrag['erledigt'] ? 'opacity-60' : '' }}"
                                     data-tagesplan-wall-gericht-uebersicht-zeile>
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg border text-sm font-bold {{ $eintrag['erledigt'] ? 'border-emerald-400 bg-emerald-500 text-white' : 'border-white/10 bg-white/5 text-slate-500' }}">{{ $eintrag['erledigt'] ? '✓' : '' }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="whitespace-normal break-words text-base font-semibold leading-snug {{ $eintrag['erledigt'] ? 'line-through' : '' }}">{{ $eintrag['name'] }}</p>
                                        <p class="mt-0.5 text-xs font-bold uppercase tracking-wide {{ $eintrag['typ'] === 'Basisrezept' ? 'text-emerald-200' : 'text-violet-200' }}">{{ $eintrag['typ'] }}</p>
                                    </div>
                                    <p class="shrink-0 text-right text-sm font-semibold text-slate-400">{{ collect([$eintrag['menge'], $eintrag['zeit']])->filter()->implode(' · ') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="min-h-0" data-tagesplan-wall-gericht-arbeitslane>
                        <div class="mb-3 flex items-end justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Abarbeiten</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-100">Rezept-Kacheln</h3>
                            </div>
                            <p class="text-sm text-slate-400">Kachel öffnet Anleitung, Haken erledigt direkt.</p>
                        </div>
                    <div class="max-h-[46vh] overflow-y-auto overscroll-contain pr-2 [touch-action:pan-y] grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" data-tagesplan-wall-gericht-rezepte>
                        @foreach($wallGericht->zeilen as $z)
                            @php
                                $erledigt = $z->line_status === 'done';
                            @endphp
                            @php
                                $laeuft = $z->auftrag_status === 'in_progress';
                            @endphp
                            @php
                                $rezeptTitel = $z->rezept_label ?: $z->name;
                            @endphp
                            <article class="flex min-h-44 gap-3 rounded-2xl border border-white/10 bg-slate-900/80 p-4 text-left shadow-xl transition hover:border-violet-300/70 {{ $erledigt ? 'opacity-60' : '' }}" data-tagesplan-wall-gericht-detail-card>
                                <button type="button" wire:click="abhaken({{ $z->id }})"
                                        class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl border text-3xl font-bold {{ $erledigt ? 'border-emerald-400 bg-emerald-500 text-white' : ($laeuft ? 'border-white/25 bg-slate-950/60 text-white hover:border-violet-300' : 'border-dashed border-violet-300/70 bg-violet-500/10 text-violet-100 hover:bg-violet-500/20') }}"
                                        title="{{ $erledigt ? 'Haken zurücknehmen' : ($laeuft ? 'Als erledigt abhaken' : 'Auftrag starten und erledigt abhaken') }}"
                                        data-tagesplan-wall-gericht-abhaken>{{ $erledigt ? '✓' : '' }}</button>
                                <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="min-w-0 flex-1 text-left" data-tagesplan-wall-gericht-anleitung>
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-violet-200">{{ $z->is_basisrezept ? 'Basisrezept' : 'Produkt/Rezept' }}</p>
                                            <p class="mt-0.5 whitespace-normal break-words text-xl font-bold leading-tight {{ $erledigt ? 'line-through' : '' }}" data-tagesplan-wall-gericht-detail-card-title>{{ $rezeptTitel }}</p>
                                        </div>
                                        @if($z->is_basisrezept)<span class="shrink-0 rounded-full bg-emerald-400/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-100">Basis</span>@endif
                                    </div>
                                    <p class="mt-3 text-sm text-slate-300">
                                        @if($z->gesamt_kg !== null){{ rtrim(rtrim(number_format((float) $z->gesamt_kg, 3, ',', '.'), '0'), ',') }} kg · @endif{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'ohne Zeit' }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-400">für {{ $z->auftrag }}</p>
                                    @if(collect($z->sicherheit['allergene'] ?? [])->isNotEmpty() || collect($z->sicherheit['warnungen'] ?? [])->isNotEmpty() || collect($z->sicherheit['diaet'] ?? [])->isNotEmpty())
                                        <div class="mt-3 flex flex-wrap gap-1.5" data-tagesplan-wall-sicherheit>
                                            @foreach(collect($z->sicherheit['warnungen'] ?? [])->take(3) as $warnung)
                                                <span class="rounded-full bg-amber-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-100" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                                            @endforeach
                                            @foreach(collect($z->sicherheit['allergene'] ?? [])->take(4) as $a)
                                                <span class="rounded-full bg-rose-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-rose-100" data-tagesplan-wall-allergen>{{ $a['label'] }}</span>
                                            @endforeach
                                            @foreach(collect($z->sicherheit['diaet'] ?? [])->take(3) as $d)
                                                <span class="rounded-full bg-emerald-400/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-100" data-tagesplan-wall-diaet>{{ $d }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </button>
                            </article>
                        @endforeach
                    </div>
                    </section>
                </div>
            @else
                <div class="grid min-h-[60vh] place-items-center text-center" data-tagesplan-wall-gericht-leer>
                    <div>
                        <p class="text-3xl font-bold text-slate-100">Gericht nicht gefunden.</p>
                        <p class="mt-2 text-lg text-slate-400">Der Arbeitsblock ist nicht mehr im aktuellen Tagesfenster.</p>
                    </div>
                </div>
            @endif
        </x-foodalchemist::modal>

        <x-foodalchemist::modal name="wall-anleitung" fullscreen dark-canvas title="Anleitung" :title-name="$anleitung['name'] ?? null" :close-via="'anleitungSchliessen'">
            @if($anleitung && (collect($anleitung['sicherheit']['allergene'] ?? [])->isNotEmpty() || collect($anleitung['sicherheit']['warnungen'] ?? [])->isNotEmpty() || collect($anleitung['sicherheit']['diaet'] ?? [])->isNotEmpty()))
                <x-slot:titleExtra>
                    <span class="flex min-w-0 flex-wrap items-center gap-1.5" data-tagesplan-wall-sicherheitsblock>
                        @foreach(collect($anleitung['sicherheit']['warnungen'] ?? [])->take(2) as $warnung)
                            <span class="rounded-full bg-amber-400/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-100" data-tagesplan-wall-warnung>{{ $warnung }}</span>
                        @endforeach
                        @foreach(collect($anleitung['sicherheit']['allergene'] ?? [])->take(5) as $a)
                            <span class="rounded-full bg-rose-400/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-100" data-tagesplan-wall-allergen>{{ $a['label'] }}{{ ($a['wert'] ?? '') === 'spuren' ? ' · Spuren' : '' }}</span>
                        @endforeach
                        @foreach(collect($anleitung['sicherheit']['diaet'] ?? [])->take(3) as $d)
                            <span class="rounded-full bg-emerald-400/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-100" data-tagesplan-wall-diaet>{{ $d }}</span>
                        @endforeach
                    </span>
                </x-slot:titleExtra>
            @endif
            <x-slot:actions>
                <button type="button" x-data @click="$wire.anleitungSchliessen(); close()"
                        class="inline-flex h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/10 px-4 text-xs font-bold uppercase tracking-wide text-white hover:bg-white/15"
                        data-tagesplan-wall-anleitung-zurueck>
                    <span class="text-lg leading-none">‹</span>Zurück zum Monitor
                </button>
            </x-slot:actions>
            @if($anleitung)
                @php
                    $wallStepKeys = array_keys($anleitung['arbeitsschritte'] ?? []);
                    $wallErledigteSteps = collect($anleitung['step_erledigt'] ?? [])->map(fn ($i) => (int) $i)->intersect($wallStepKeys);
                    $wallAlleStepsErledigt = $wallStepKeys !== [] && $wallErledigteSteps->count() === count($wallStepKeys);
                    $wallLineErledigt = ($anleitung['line_status'] ?? null) === 'done';
                    $wallLineLaeuft = ($anleitung['line_status'] ?? null) === 'in_progress';
                    $wallGesamtKg = $anleitung['gesamt_kg'] ?? null;
                    $wallGesamtKgText = $wallGesamtKg !== null
                        ? rtrim(rtrim(number_format((float) $wallGesamtKg, 3, ',', '.'), '0'), ',') . ' kg'
                        : null;
                    $wallArbeitszeit = $anleitung['arbeitszeit_min'] ?? null;
                    $wallStandzeit = $anleitung['standzeit_min'] ?? null;
                    $wallDurchlaufzeit = $anleitung['durchlaufzeit_min'] ?? $wallArbeitszeit;
                    $wallStartedAt = $anleitung['started_at'] ?? null;
                @endphp
                <div class="grid gap-3 xl:grid-cols-[minmax(18rem,24rem)_1fr]" data-tagesplan-wall-anleitung>
                    <aside class="space-y-3 xl:sticky xl:top-0 xl:self-start">
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Auftrag</p>
                            <p class="mt-1 text-sm text-slate-200">für {{ $anleitung['auftrag'] }}</p>
                        </div>

                        <section class="rounded-xl border border-white/10 bg-white/5 px-3 py-2" data-tagesplan-wall-timer>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                @if($wallGesamtKgText !== null)
                                    <div data-tagesplan-wall-gesamtmenge>
                                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Gesamt</p>
                                        <p class="mt-0.5 font-semibold tabular-nums text-slate-100">{{ $wallGesamtKgText }}</p>
                                    </div>
                                @endif
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Zeit</p>
                                    @if($wallArbeitszeit !== null || $wallStandzeit !== null)
                                        <p class="mt-0.5 font-semibold tabular-nums text-slate-100">{{ $wallDurchlaufzeit }} min<span class="text-[11px] font-normal text-slate-400"> gesamt</span></p>
                                        <p class="text-[10px] tabular-nums text-slate-400">{{ (int) ($wallArbeitszeit ?? 0) }} aktiv @if($wallStandzeit)· {{ $wallStandzeit }} Garzeit @endif</p>
                                    @else
                                        <p class="mt-0.5 font-semibold tabular-nums text-slate-100">offen</p>
                                    @endif
                                </div>
                            </div>
                            @if($wallLineLaeuft && $wallStartedAt !== null)
                                <div class="mt-2 rounded-xl border border-sky-300/30 bg-sky-400/10 px-3 py-2"
                                     x-data="{ started: Date.parse(@js($wallStartedAt)), total: {{ (int) ($wallDurchlaufzeit ?? 0) }}, now: Date.now(), tick: null, init(){ this.tick = setInterval(() => this.now = Date.now(), 1000) }, elapsed(){ return Math.max(0, Math.floor((this.now - this.started) / 60000)) }, remaining(){ return this.total > 0 ? Math.max(0, this.total - this.elapsed()) : null } }"
                                     data-tagesplan-wall-laufzeit>
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-sky-100">läuft</p>
                                    <p class="mt-0.5 text-sm font-semibold tabular-nums text-slate-100">
                                        <span x-text="elapsed()"></span> min gelaufen
                                        <template x-if="remaining() !== null"><span> · <span x-text="remaining()"></span> min Rest</span></template>
                                    </p>
                                </div>
                            @elseif(! $wallLineErledigt)
                                <button type="button" wire:click="anleitungStarten"
                                        class="mt-2 flex w-full items-center justify-between rounded-xl border border-sky-300/40 bg-sky-400/15 px-3 py-2 text-left text-sm font-bold text-sky-100 hover:bg-sky-400/25"
                                        data-tagesplan-wall-start>
                                    <span>Start</span>
                                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-white/10 text-white">▶</span>
                                </button>
                            @else
                                <div class="mt-2 rounded-xl border border-emerald-400/40 bg-emerald-500/15 px-3 py-2 text-sm font-bold text-emerald-100">Erledigt</div>
                            @endif
                            @if($wallStepKeys !== [])
                                <button type="button" wire:click="anleitungAlleStepsUmschalten"
                                        class="mt-2 flex w-full items-center justify-between rounded-xl border px-3 py-2 text-left text-sm font-bold {{ $wallAlleStepsErledigt || $wallLineErledigt ? 'border-emerald-400/40 bg-emerald-500/20 text-emerald-100' : 'border-violet-300/40 bg-violet-500/15 text-violet-100 hover:bg-violet-500/25' }}"
                                        data-tagesplan-wall-anleitung-alle-steps>
                                    <span>{{ $wallAlleStepsErledigt || $wallLineErledigt ? 'Alle Schritte erledigt' : 'Alle Schritte abhaken' }}</span>
                                    <span class="grid h-8 w-8 place-items-center rounded-lg {{ $wallAlleStepsErledigt || $wallLineErledigt ? 'bg-emerald-400 text-slate-950' : 'bg-white/10 text-white' }}">{{ $wallAlleStepsErledigt || $wallLineErledigt ? '✓' : '' }}</span>
                                </button>
                                <p class="mt-1 text-xs text-slate-400" data-tagesplan-wall-step-fortschritt>{{ $wallErledigteSteps->count() }}/{{ count($wallStepKeys) }} Schritte</p>
                            @endif
                        </section>

                        @if(!empty($anleitung['sub_rezepte']))
                            <section class="rounded-xl border border-white/10 bg-white/5 px-3 py-2" data-tagesplan-wall-subrezepte>
                                <h3 class="text-base font-bold">Enthaltene Rezepte</h3>
                                <div class="mt-2 space-y-2">
                                    @foreach($anleitung['sub_rezepte'] as $sub)
                                        @if($sub['line_id'] !== null)
                                            <button type="button" wire:click="oeffneAnleitung({{ $sub['line_id'] }})"
                                                    class="flex w-full items-start gap-3 rounded-xl border border-white/10 bg-slate-950/45 px-3 py-2 text-left transition hover:border-violet-300/70"
                                                    data-tagesplan-wall-subrezept>
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border text-base font-bold {{ $sub['erledigt'] ? 'border-emerald-400 bg-emerald-500 text-slate-950' : 'border-white/10 bg-white/5 text-slate-500' }}">{{ $sub['erledigt'] ? '✓' : '›' }}</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block whitespace-normal break-words text-sm font-bold leading-snug text-slate-100">{{ $sub['name'] }}</span>
                                                    <span class="mt-0.5 block text-[11px] font-bold uppercase tracking-wide text-emerald-200">{{ $sub['typ'] }}</span>
                                                </span>
                                                <span class="shrink-0 text-right text-xs font-semibold text-slate-400">{{ collect([$sub['menge'], $sub['zeit']])->filter()->implode(' · ') }}</span>
                                            </button>
                                        @else
                                            <div class="flex items-start gap-3 rounded-xl border border-dashed border-white/10 bg-slate-950/25 px-3 py-2" data-tagesplan-wall-subrezept-fehlt>
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-amber-300/30 bg-amber-400/10 text-amber-100">!</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block whitespace-normal break-words text-sm font-bold leading-snug text-slate-100">{{ $sub['name'] }}</span>
                                                    <span class="mt-0.5 block text-xs text-amber-100">Keine Produktionszeile im Tagesfenster gefunden.</span>
                                                </span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if(!empty($anleitung['zutaten']))
                            <section class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                                <h3 class="text-base font-bold">Zutaten</h3>
                                <div class="mt-1.5 divide-y divide-white/10" data-tagesplan-wall-zutatenliste>
                                    @foreach($anleitung['zutaten'] as $zt)
                                        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 py-1.5 text-sm" data-tagesplan-wall-zutat>
                                            <span class="min-w-0 whitespace-normal leading-snug text-slate-100">{{ $zt['name'] ?? ($zt['bezeichnung'] ?? '—') }}</span>
                                            <span class="shrink-0 text-right font-semibold tabular-nums text-slate-200">{{ $zt['menge'] ?? ($zt['quantity'] ?? '') }} {{ $zt['einheit'] ?? ($zt['unit'] ?? '') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if(!empty($anleitung['behaelter']))
                            <section class="rounded-xl border border-white/10 bg-white/5 px-3 py-2" data-tagesplan-wall-behaelter>
                                <h3 class="text-base font-bold">Behälter</h3>
                                <div class="mt-1.5 divide-y divide-white/10">
                                    @foreach($anleitung['behaelter'] as $bh)
                                        <div class="py-1.5 text-sm" data-tagesplan-wall-behaelter-item>
                                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                                                <span class="min-w-0 whitespace-normal leading-snug text-slate-400">{{ $bh['zweck'] }}</span>
                                                @if($bh['wert'])
                                                    <span class="shrink-0 text-right font-semibold tabular-nums text-slate-100">{{ $bh['wert'] }}</span>
                                                @else
                                                    <span class="shrink-0 text-right text-xs text-amber-100">—</span>
                                                @endif
                                            </div>
                                            @if($bh['zusatz'])
                                                <p class="mt-0.5 text-xs text-slate-400" data-tagesplan-wall-behaelter-alt>{{ $bh['zusatz'] }}</p>
                                            @endif
                                            @if($bh['hinweis'])
                                                <p class="mt-0.5 text-xs {{ $bh['wert'] ? 'text-slate-400' : 'text-amber-100' }}">{{ $bh['hinweis'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if(!empty($anleitung['equipment']))
                            <section class="rounded-xl border border-white/10 bg-white/5 px-3 py-2" data-tagesplan-wall-equipment>
                                <h3 class="text-base font-bold">Equipment</h3>
                                <div class="mt-1.5 divide-y divide-white/10">
                                    @foreach($anleitung['equipment'] as $eq)
                                        <div class="py-1.5 text-sm" data-tagesplan-wall-equipment-item>
                                            <div class="flex items-start justify-between gap-3">
                                                <span class="min-w-0 whitespace-normal leading-snug text-slate-100">{{ $eq['name'] ?? 'Equipment' }}</span>
                                                @if($eq['gruppe'] ?? null)<span class="shrink-0 text-right text-xs text-slate-400">{{ $eq['gruppe'] }}</span>@endif
                                            </div>
                                            @if($eq['notiz'] ?? null)<p class="mt-0.5 text-xs text-slate-400">{{ $eq['notiz'] }}</p>@endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                    </aside>

                    <section class="rounded-xl border border-white/10 bg-white/5 p-3" data-tagesplan-wall-media>
                        <h3 class="text-base font-bold">Schritte & Medien</h3>
                        @if(!empty($anleitung['schritte']))
                            <div class="mt-3 space-y-2">
                                @foreach($anleitung['schritte'] as $s)
                                    @php
                                        $stepIndex = (int) $loop->index;
                                    @endphp
                                    @php
                                        $stepErledigt = in_array($stepIndex, $anleitung['step_erledigt'] ?? [], true) || $wallLineErledigt;
                                    @endphp
                                    @php
                                        $fotos = collect($s['fotos'] ?? $s['photos'] ?? [])->filter(fn ($f) => ($f['url'] ?? $f['src'] ?? null));
                                    @endphp
                                    @php
                                        $medien = collect($s['medien'] ?? $s['media'] ?? [])->filter(fn ($m) => ($m['url'] ?? $m['src'] ?? null));
                                    @endphp
                                    <article class="rounded-xl border border-white/10 bg-slate-950/45 px-3 py-2.5 {{ $stepErledigt ? 'opacity-70' : '' }}" data-tagesplan-wall-schritt>
                                        <div class="flex gap-3">
                                            <button type="button" wire:click="anleitungStepUmschalten({{ $stepIndex }})"
                                                    class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border text-xl font-bold {{ $stepErledigt ? 'border-emerald-400 bg-emerald-500 text-slate-950' : 'border-dashed border-violet-300/70 bg-violet-500/10 text-violet-100 hover:bg-violet-500/20' }}"
                                                    title="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt erledigt abhaken' }}"
                                                    data-tagesplan-wall-step-abhaken>{{ $stepErledigt ? '✓' : ($s['nr'] ?? $loop->iteration) }}</button>
                                            <div class="min-w-0 flex-1">
                                                @if($s['phase'] ?? null)<p class="text-[11px] font-bold uppercase tracking-wide text-violet-200">{{ $s['phase'] }}</p>@endif
                                                <p class="text-lg leading-snug {{ $stepErledigt ? 'line-through decoration-emerald-300/70' : '' }}">{{ $s['text'] ?? '' }}</p>
                                            </div>
                                        </div>
                                        @if($fotos->isNotEmpty())
                                            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-tagesplan-wall-bilder>
                                                @foreach($fotos as $f)
                                                    @php
                                                        $src = $f['url'] ?? $f['src'] ?? null;
                                                    @endphp
                                                    <figure class="overflow-hidden rounded-2xl border border-white/10 bg-black/30">
                                                        <img src="{{ $src }}" alt="{{ $f['caption'] ?? ('Schritt ' . ($s['nr'] ?? $loop->parent->iteration)) }}" class="h-64 w-full object-cover" loading="lazy" />
                                                        @if($f['caption'] ?? null)<figcaption class="px-3 py-2 text-sm text-slate-300">{{ $f['caption'] }}</figcaption>@endif
                                                    </figure>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if(($s['video'] ?? null) || ($s['audio'] ?? null) || $medien->isNotEmpty())
                                            <div class="mt-4 space-y-3" data-tagesplan-wall-player>
                                                @if($s['video'] ?? null)<video src="{{ $s['video'] }}" controls playsinline class="max-h-[60vh] w-full rounded-2xl bg-black"></video>@endif
                                                @if($s['audio'] ?? null)<audio src="{{ $s['audio'] }}" controls class="w-full"></audio>@endif
                                                @foreach($medien as $m)
                                                    @php
                                                        $src = $m['url'] ?? $m['src'] ?? null;
                                                    @endphp
                                                    @php
                                                        $typ = $m['type'] ?? $m['typ'] ?? '';
                                                    @endphp
                                                    @if(str_contains((string) $typ, 'video'))
                                                        <video src="{{ $src }}" controls playsinline class="max-h-[60vh] w-full rounded-2xl bg-black"></video>
                                                    @elseif(str_contains((string) $typ, 'audio'))
                                                        <audio src="{{ $src }}" controls class="w-full"></audio>
                                                    @elseif($src)
                                                        <a href="{{ $src }}" target="_blank" class="inline-flex rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white">Medium öffnen</a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @elseif(!empty($anleitung['zubereitung']))
                            @php
                                $fallbackZeilen = collect(preg_split('/\R+/', (string) $anleitung['zubereitung']))
                                    ->map(fn ($line) => trim($line))
                                    ->filter()
                                    ->values();
                            @endphp
                            <div class="mt-3 space-y-2" data-tagesplan-wall-fallback-schritte>
                                @php
                                    $fallbackStepIndex = -1;
                                @endphp
                                @foreach($fallbackZeilen as $line)
                                    @php
                                        $istHeading = str_starts_with($line, '##');
                                    @endphp
                                    @php
                                        $text = trim(preg_replace('/^#+\s*/', '', $line));
                                    @endphp
                                    @if($istHeading)
                                        <h4 class="pt-1 text-lg font-bold text-violet-100">{{ $text }}</h4>
                                    @else
                                        @php
                                            $fallbackStepIndex++;
                                        @endphp
                                        @php
                                            $stepErledigt = in_array($fallbackStepIndex, $anleitung['step_erledigt'] ?? [], true) || $wallLineErledigt;
                                        @endphp
                                        <div class="flex gap-3 rounded-xl border border-white/10 bg-slate-950/45 px-3 py-2 text-lg leading-snug text-slate-100 {{ $stepErledigt ? 'opacity-70' : '' }}" data-tagesplan-wall-fallback-zeile>
                                            <button type="button" wire:click="anleitungStepUmschalten({{ $fallbackStepIndex }})"
                                                    class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border text-xl font-bold {{ $stepErledigt ? 'border-emerald-400 bg-emerald-500 text-slate-950' : 'border-dashed border-violet-300/70 bg-violet-500/10 text-violet-100 hover:bg-violet-500/20' }}"
                                                    title="{{ $stepErledigt ? 'Schritt zurücknehmen' : 'Schritt erledigt abhaken' }}"
                                                    data-tagesplan-wall-step-abhaken>{{ $stepErledigt ? '✓' : $fallbackStepIndex + 1 }}</button>
                                            <span class="min-w-0 flex-1 {{ $stepErledigt ? 'line-through decoration-emerald-300/70' : '' }}">{{ $text }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="mt-4 rounded-2xl border border-dashed border-white/15 bg-slate-950/40 p-8 text-center" data-tagesplan-wall-anleitung-leer>
                                <p class="text-2xl font-bold text-slate-100">Keine Anleitung hinterlegt.</p>
                                <p class="mt-2 text-base text-slate-400">Zutaten, Schritte, Bilder oder Medien fehlen bei diesem Rezept noch.</p>
                            </div>
                        @endif
                    </section>
                </div>
            @else
                <div class="grid min-h-[60vh] place-items-center text-center" data-tagesplan-wall-anleitung-leer>
                    <div>
                        <p class="text-3xl font-bold text-slate-100">Keine Anleitung gefunden.</p>
                        <p class="mt-2 text-lg text-slate-400">Die Zeile ist nicht mehr im aktuellen Tagesfenster oder wurde gerade neu berechnet.</p>
                    </div>
                </div>
            @endif
        </x-foodalchemist::modal>
    </div>
@else
<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar :title="$istWall ? 'Küchenmonitor' : 'Tagesplanung'" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => $istWall ? 'Küchenmonitor' : 'Tagesplanung'],
        ]">
            <div class="flex items-center gap-2">
                @if(! $istWall)
                    <a href="{{ route('foodalchemist.produktion.tagesplan.editor', ['von' => $von, 'bis' => $bis, 'tage' => $tage, 'ansicht' => $ansicht]) }}"
                       wire:navigate x-data x-on:click="$dispatch('modal.open', { name: 'tagesplan-editor' })"
                       class="{{ $btnPrimary }}" data-tagesplan-editor-link>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Editor öffnen</a>
                @endif
                <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht])) }}"
                   target="_blank" class="{{ $btnGhostXs }}" data-tagesplan-drucken>Blatt drucken</a>
                <a href="{{ route('foodalchemist.produktion.wandmonitor', ['von' => $von, 'tage' => 1]) }}"
                   wire:navigate class="{{ $btnGhostXs }}" data-tagesplan-wall-toggle>@svg('heroicon-o-tv', 'w-3.5 h-3.5') Wandmonitor</a>
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    @if(! $istWall)
        <x-slot name="sidebar">
            <x-ui-page-sidebar title="Zeitraum & Posten" width="w-72">
                <div class="p-3 space-y-4" data-tagesplanung-sidebar>
                    <div data-tagesplan-steuerung>
                        <span class="{{ $label }}">Zeitraum</span>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <button type="button" wire:click="dashboardTagVerschieben(-7)" class="{{ $btnGhostXs }}">‹ Woche</button>
                            <button type="button" wire:click="dashboardHeute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
                            <button type="button" wire:click="dashboardTagVerschieben(7)" class="{{ $btnGhostXs }}">Woche ›</button>
                        </div>
                        <div class="grid grid-cols-2 gap-2 mt-2">
                            <label>
                                <span class="{{ $label }}">von</span>
                                <input type="date" wire:model.live="von" class="{{ $input }}" data-tagesplan-von />
                            </label>
                            <label>
                                <span class="{{ $label }}">bis</span>
                                <input type="date" wire:model.live="bis" class="{{ $input }}" data-tagesplan-bis />
                            </label>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1" data-tagesplanung-dashboard-fenster>
                            @foreach([3 => '3 Tage', 7 => '7 Tage', 14 => '14 Tage', 30 => 'Monat'] as $n => $lbl)
                                <button type="button" wire:click="waehleDashboardFenster({{ $n }})"
                                        class="{{ $pill }} {{ $dashboard['fenster'] === $n ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div data-tagesplan-postenfilter>
                        <span class="{{ $label }}">Posten</span>
                        <div class="mt-2 space-y-1">
                            <x-foodalchemist::filter-row wire:click="postenWaehlen(null)" :active="$postenFilter === null">
                                <span class="font-medium">Alle Posten</span>
                            </x-foodalchemist::filter-row>
                            <x-foodalchemist::filter-ast>
                                @foreach($postenListe as $p)
                                    <x-foodalchemist::filter-row level="child" wire:key="tps-{{ $p->id }}"
                                        wire:click="postenWaehlen({{ $p->id }})"
                                        :active="$postenFilter === $p->id">{{ $p->name }}</x-foodalchemist::filter-row>
                                @endforeach
                            </x-foodalchemist::filter-ast>
                        </div>
                    </div>

                    <button type="button" wire:click="vorschlagen" class="{{ $btnAi }} w-full justify-center" data-tagesplan-vorschlagen>
                        @svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlag rechnen
                    </button>
                </div>
            </x-ui-page-sidebar>
        </x-slot>

        <x-slot name="activity">
            <x-foodalchemist::detail-sidebar title="Tagesdetail" width="w-96" :maxWidth="760"
                                             scope="activity_tagesplan" side="right">
                <div class="p-4 space-y-3" data-tagesplanung-activity>
                    @if($selectedDay && $tagDetail)
                        <div data-tagesplanung-tagdetail>
                            <p class="{{ $label }}">Tagesdetail</p>
                            <h3 class="text-lg font-semibold tracking-tight text-gray-900">
                                {{ \Illuminate\Support\Carbon::parse($tagDetail['tag'])->locale('de')->isoFormat('dddd, DD.MM.') }}
                            </h3>
                            <p class="text-xs text-gray-500">{{ $tagDetail['zeilen']->count() }} Speisen · {{ $tagDetail['minuten'] }} min geplant</p>
                        </div>

                        @foreach($tagDetail['auslastung'] as $b)
                            @php
                                $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                            @endphp
                            @php
                                $postenZeilen = $tagDetail['posten']->get($schluessel, collect());
                            @endphp
                            @continue($postenZeilen->isEmpty())
                            @php
                                $tone = match($b['stufe'] ?? 'leer') {
                                    'ueberlast' => 'border-rose-200 bg-rose-50/80',
                                    'eng' => 'border-amber-200 bg-amber-50/80',
                                    'ok' => 'border-emerald-200 bg-emerald-50/70',
                                    default => 'border-sky-200 bg-sky-50/70',
                                };
                            @endphp
                            <section class="rounded-2xl border {{ $tone }} p-3" data-tagesplanung-tagdetail-posten>
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $b['station'] }}</p>
                                        <p class="text-[11px] text-gray-500">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null)/{{ $b['kapazitaet_min'] }}@endif min</p>
                                    </div>
                                    @if($b['stufe'] === 'ueberlast')
                                        <span class="{{ $pill }} {{ $variantPill['danger'] }}">Überlast</span>
                                    @elseif($b['stufe'] === 'eng')
                                        <span class="{{ $pill }} {{ $variantPill['warning'] }}">eng</span>
                                    @else
                                        <span class="{{ $pill }} {{ $variantPill['success'] }}">ok</span>
                                    @endif
                                </div>
                                <div class="mt-3 space-y-2">
                                    @foreach($postenZeilen as $z)
                                        <div class="rounded-xl bg-white/70 px-3 py-2">
                                            <p class="text-sm font-medium text-gray-900 truncate">{{ $z->name }}</p>
                                            <p class="text-[11px] text-gray-500 truncate">{{ $z->auftrag }} · {{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }} · für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    @else
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

                        <div class="{{ $card }} p-4" data-tagesplanung-dashboard-performance>
                            <h3 class="font-medium tracking-tight text-gray-900">Performance & Engpässe</h3>
                            <div class="mt-3 space-y-2">
                                @forelse($dashboard['performance']->take(8) as $p)
                                    @php
                                        $breite = $p['prozent'] !== null ? min(100, max(4, $p['prozent'])) : 12;
                                    @endphp
                                    @php
                                        $bar = ($p['kritisch'] ?? 0) > 0 ? 'bg-rose-500' : (($p['eng'] ?? 0) > 0 ? 'bg-amber-500' : 'bg-sky-600');
                                    @endphp
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
                    @endif
                </div>
            </x-foodalchemist::detail-sidebar>
        </x-slot>

        <livewire:foodalchemist.produktion.editor />

        <x-foodalchemist::modal name="tagesplan-editor" fullscreen dark-canvas title="Produktions-Tagesplanung"
                                :title-name="$ansicht === 'gericht' ? 'Gerichtssicht' : 'Postensicht'"
                                :close-via="'editorSchliessen'">
            <x-slot:actions>
                <button type="button" wire:click="vorschlagen" class="{{ $btnAi }}" data-tagesplan-vorschlagen>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlag rechnen</button>
                <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht])) }}"
                   target="_blank" class="{{ $btnGhostXs }}" data-tagesplan-drucken>Blatt drucken</a>
            </x-slot:actions>

            <x-slot:kpiHeader>
                <x-foodalchemist::kpi-tiles marker="tagesplan-editor" :tiles="[
                    ['kpi' => 'offen', 'label' => 'Offen', 'tone' => 'accent', 'value' => (string) $dashboard['kpis']['offen']],
                    ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $dashboard['kpis']['minuten'] . ' min'],
                    ['kpi' => 'manntage', 'label' => 'Manntage', 'value' => number_format($dashboard['kpis']['minuten'] / 480, 1, ',', '.')],
                    ['kpi' => 'ueberlast', 'label' => 'Überlast', 'tone' => $dashboard['kpis']['ueberlast'] > 0 ? 'warn' : 'neutral', 'value' => (string) $dashboard['kpis']['ueberlast']],
                    ['kpi' => 'posten', 'label' => 'Posten', 'value' => (string) $dashboard['kpis']['posten']],
                ]" />
            </x-slot:kpiHeader>

            <section data-tagesplan-editor>
                @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

                <div class="grid min-h-[68vh] grid-cols-1 xl:grid-cols-[16rem_minmax(0,1fr)_20rem] rounded-2xl border border-white/10 overflow-hidden">
                    <aside class="border-b border-white/10 p-4 xl:border-b-0 xl:border-r" data-tagesplan-postenfilter>
                        <div data-tagesplan-steuerung>
                            <p class="{{ $label }}">Zeitraum</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <button type="button" wire:click="verschiebe(-7)" class="{{ $btnGhostXs }}">‹ Woche</button>
                                <button type="button" wire:click="heute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
                                <button type="button" wire:click="verschiebe(7)" class="{{ $btnGhostXs }}">Woche ›</button>
                            </div>
                            <div class="mt-2 space-y-2">
                                <label class="block"><span class="{{ $label }}">von</span><input type="date" wire:model.live="von" class="{{ $input }}" /></label>
                                <label class="block"><span class="{{ $label }}">bis</span><input type="date" wire:model.live="bis" class="{{ $input }}" /></label>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="{{ $label }}">Posten</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach($postenListe as $p)
                                    <button type="button" wire:click="postenWaehlen({{ $p->id }})" wire:key="tpf-modal-{{ $p->id }}"
                                            class="{{ $pill }} {{ $postenFilter === $p->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $p->name }}</button>
                                @endforeach
                                @if($postenFilter !== null)
                                    <button type="button" wire:click="postenWaehlen(null)" class="{{ $btnGhostXs }} mt-1">alle</button>
                                @endif
                            </div>
                        </div>
                    </aside>

                    <main class="max-h-[70vh] overflow-auto p-4 space-y-4">
                        @forelse($zeilenNachTag as $tag => $zeilen)
                            @php
                                $tagC = \Illuminate\Support\Carbon::parse($tag);
                            @endphp
                            @php
                                $nachPosten = $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id);
                            @endphp
                            <section data-modal-zone="section" class="rounded-2xl border border-white/10 overflow-hidden" data-tagesplan-tag="{{ $tag }}">
                                <div class="flex items-baseline gap-2 border-b border-white/10 px-4 py-3">
                                    <h3 class="font-semibold text-gray-900">{{ $tagC->locale('de')->isoFormat('dd DD.MM.') }}</h3>
                                    @if($tagC->isToday())<span class="{{ $pill }} {{ $variantPill['primary'] }}">heute</span>@endif
                                    <span class="text-xs text-gray-500">{{ $zeilen->count() }} Positionen</span>
                                </div>
                                @foreach($auslastung[$tag] ?? [] as $b)
                                    @php
                                        $schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'];
                                    @endphp
                                    @php
                                        $blockZeilen = $nachPosten[$schluessel] ?? collect();
                                    @endphp
                                    @continue($blockZeilen->isEmpty())
                                    <div class="px-4 py-3" data-tagesplan-auslastung>
                                        <div class="mb-2 flex items-center gap-2">
                                            <span class="w-44 shrink-0 truncate text-xs font-semibold text-gray-800">{{ $b['station'] }}</span>
                                            <span class="w-28 shrink-0 text-xs tabular-nums text-gray-500">{{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) / {{ $b['kapazitaet_min'] }}@endif min</span>
                                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10">
                                                @if($b['kapazitaet_min'] !== null)
                                                    @php
                                                        $bar = $b['stufe'] === 'ueberlast' ? 'bg-rose-500' : ($b['stufe'] === 'eng' ? 'bg-amber-500' : 'bg-emerald-500');
                                                    @endphp
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
                                                    @php
                                                        $erledigt = $z->line_status === 'done';
                                                    @endphp
                                                    @php
                                                        $laeuft = $z->auftrag_status === 'in_progress';
                                                    @endphp
                                                    <tr class="{{ $tr }} {{ $erledigt ? 'opacity-60' : '' }}" wire:key="tpz-modal-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}">
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
                    <div class="mt-4 rounded-2xl border border-violet-400/30 bg-violet-500/10 px-4 py-3" data-tagesplan-vorschlag>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-medium text-violet-100">Planungs-Vorschlag · {{ $vorschlag['aenderungen'] }} Änderungen</h3>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="vorschlagUebernehmen" class="{{ $btnPrimary }} !py-1">Übernehmen</button>
                                <button type="button" wire:click="vorschlagVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </x-foodalchemist::modal>

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

            <section class="space-y-4">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <div class="{{ $card }} p-4" data-tagesplanung-dashboard-manntage>
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="font-medium tracking-tight text-gray-900">Planung in Manntagen</h3>
                                <span class="{{ $label }}">1 MT = 480 min</span>
                            </div>
                            <div class="mt-3 flex items-end gap-2 min-h-36">
                                @foreach($dashboard['manntage'] as $tag)
                                    @php
                                        $hoehe = max(8, (int) round(($tag['wert'] / $dashboard['maxManntage']) * 112));
                                    @endphp
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
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                            @foreach($dashboard['tage'] as $tag)
                                @php
                                    $tagZeilen = $dashboard['zeilenNachTag']->get($tag, collect());
                                @endphp
                                @php
                                    $tagBuckets = collect($dashboard['auslastung'][$tag] ?? []);
                                @endphp
                                @php
                                    $tagUeberlast = $tagBuckets->where('stufe', 'ueberlast')->count();
                                @endphp
                                @php
                                    $tagEng = $tagBuckets->where('stufe', 'eng')->count();
                                @endphp
                                @php
                                    $tone = $tagUeberlast > 0 ? 'border-rose-200 bg-rose-50/70' : ($tagEng > 0 ? 'border-amber-200 bg-amber-50/70' : ($tagZeilen->isNotEmpty() ? 'border-emerald-200 bg-emerald-50/60' : 'border-black/5 bg-gray-50/80'));
                                @endphp
                                <article wire:click="waehleTag('{{ $tag }}')" x-data x-on:click="$store.ui?.mSet('activity_tagesplan', 'open', true)"
                                         class="rounded-2xl border {{ $tone }} {{ $selectedDay === $tag ? 'ring-2 ring-violet-500 border-violet-300' : '' }} p-4 min-h-44 cursor-pointer transition hover:-translate-y-0.5 hover:shadow-md"
                                         data-tagesplanung-dashboard-tag="{{ $tag }}" data-tagesplanung-dashboard-tag-klickbar>
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-gray-500">{{ \Illuminate\Support\Carbon::parse($tag)->format('D') }}</p>
                                            <h4 class="text-2xl font-semibold tracking-tight text-gray-950">{{ \Illuminate\Support\Carbon::parse($tag)->format('d.m.') }}</h4>
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
                                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                                        <div class="rounded-xl bg-white/70 px-2 py-2"><p class="text-lg font-semibold tabular-nums text-gray-950">{{ $tagZeilen->count() }}</p><p class="text-[9px] text-gray-500">Speisen</p></div>
                                        <div class="rounded-xl bg-white/70 px-2 py-2"><p class="text-lg font-semibold tabular-nums text-gray-950">{{ (int) $tagZeilen->sum('arbeitszeit_min') }}</p><p class="text-[9px] text-gray-500">Min</p></div>
                                        <div class="rounded-xl bg-white/70 px-2 py-2"><p class="text-lg font-semibold tabular-nums text-gray-950">{{ $tagBuckets->count() }}</p><p class="text-[9px] text-gray-500">Posten</p></div>
                                    </div>
                                    <div class="mt-3 space-y-1">
                                        @foreach($tagZeilen->take(4) as $z)
                                            <p class="text-[11px] text-gray-600 truncate">{{ $z->name }} <span class="text-gray-400">· {{ $z->station ?: 'ohne Posten' }}</span></p>
                                        @endforeach
                                        @if($tagZeilen->count() > 4)
                                            <p class="text-[10px] text-gray-400">+ {{ $tagZeilen->count() - 4 }} weitere im Editor</p>
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
                                                    @php
                                                        $bucket = $zellen[$tag] ?? null;
                                                    @endphp
                                                    @php
                                                        $tone = match($bucket['stufe'] ?? 'leer') {
                                                            'ueberlast' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                                            'eng' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                                            'ok' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                                            'ohne_kapazitaet' => 'bg-sky-50 text-sky-700 ring-sky-200',
                                                            default => 'bg-gray-50 text-gray-400 ring-gray-100',
                                                        };
                                                    @endphp
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
    @endif
</x-ui-page>
@endif
</div>
