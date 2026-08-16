{{-- Worker-Cockpit: Stufen-Abschnitte (Concept · Gerichte · Basisrezepte) mit Fortschritts-Headern +
     Stufen-Freigabe. Nur erreichte Stufen erscheinen (progressive Enthüllung). Erwartet $lauf gesetzt. --}}
@php
    $stepLabel = ['geplant' => 'geplant', 'queued' => 'wartet', 'running' => 'läuft', 'done' => 'Vorschau', 'freigegeben' => 'freigegeben', 'verworfen' => 'verworfen', 'failed' => 'Fehler', 'skipped' => 'übernommen'];
    $stepColor = ['geplant' => 'text-violet-300', 'queued' => 'text-amber-300', 'running' => 'text-amber-300', 'done' => 'text-emerald-300', 'freigegeben' => 'text-emerald-400', 'verworfen' => 'text-gray-500', 'failed' => 'text-rose-300', 'skipped' => 'text-gray-400'];
    $refRoute = ['gericht' => 'foodalchemist.verkauf.index', 'rezept' => 'foodalchemist.recipes.index', 'concept' => 'foodalchemist.concepts.index'];
    $laufRunning = $lauf->steps->whereIn('status', ['queued', 'running'])->count();
    $laufDone = $lauf->steps->whereIn('status', ['done', 'freigegeben'])->count();
    $laufFailed = $lauf->steps->where('status', 'failed')->count();
    $offeneEntwuerfe = $lauf->steps->where('status', 'done')->count();
    $stufen = $this->stufenAusSteps($lauf->steps);
    $zustandPill = ['läuft' => 'bg-amber-500/15 text-amber-300', 'prüfen' => 'bg-emerald-500/15 text-emerald-300', 'geplant' => 'bg-violet-500/15 text-violet-300', 'erledigt' => 'bg-white/10 text-gray-400'];
    $stepArgs = fn ($s, $indent) => ['st' => $s, 'stepLabel' => $stepLabel, 'stepColor' => $stepColor, 'refRoute' => $refRoute, 'indent' => $indent, 'kalkulation' => $kalkulation ?? []];
    // Anreicherungs-Bilanz über die freigegebenen Rezept-/Gericht-Steps (deferred.enrich) — damit der
    // Abschluss ehrlich meldet: freigegeben + angereichert, oder Anreicherung läuft/fehlgeschlagen.
    $freigegebenGesamt = $lauf->steps->where('status', 'freigegeben')->count();
    $anrDone = 0; $anrFehler = 0; $anrOffen = 0;
    foreach ($lauf->steps as $s) {
        if ($s->status !== 'freigegeben' || ! in_array($s->kind, ['rezept', 'gericht'], true)) { continue; }
        $enr = (is_array($s->deferred) && is_array($s->deferred['enrich'] ?? null)) ? $s->deferred['enrich'] : [];
        $es = $enr['status'] ?? null;
        if ($es === 'done') { $anrDone++; }
        elseif ($es === 'failed') { $anrFehler++; }
        elseif (in_array($es, ['queued', 'running'], true)) { $anrOffen++; }
    }
    // Terminal-Endzustand: nichts läuft mehr, keine offenen Entwürfe, aber freigegebene Artefakte da.
    $terminal = $laufRunning === 0 && $offeneEntwuerfe === 0 && $freigegebenGesamt > 0;
@endphp
<x-foodalchemist::modal-section title="Kaskade — Stufen &amp; Freigabe">
    @if($lauf->brief)
        <p class="text-[11px] text-gray-400 mb-3 line-clamp-2"><span class="text-gray-500">Brief:</span> {{ \Illuminate\Support\Str::limit($lauf->brief, 200) }}</p>
    @endif

    {{-- Gesamt-Worker-Status (aus den Steps abgeleitet, kein globaler Ping). --}}
    <div class="flex items-center gap-2 mb-3 text-xs font-medium flex-wrap">
        @if($laufRunning > 0 && ($hinweis ?? null) !== null)
            <span class="inline-flex items-center gap-1.5 text-amber-400">@svg('heroicon-o-exclamation-triangle', 'w-4 h-4') Worker hängt? — {{ $laufRunning }} Schritt(e) warten (vermutlich kein Queue-Worker)</span>
        @elseif($laufRunning > 0)
            <span class="inline-flex items-center gap-1.5 text-amber-300">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Worker arbeitet — {{ $laufRunning }} Schritt(e) laufen{{ $laufDone > 0 ? ', ' . $laufDone . ' fertig' : '' }}</span>
        @elseif($offeneEntwuerfe > 0)
            <span class="inline-flex items-center gap-1.5 text-emerald-300">@svg('heroicon-o-check-circle', 'w-4 h-4') Vorschau fertig — {{ $offeneEntwuerfe }} Entwurf/Entwürfe (ansehen / stufenweise freigeben)</span>
        @elseif($terminal)
            <span class="inline-flex items-center gap-1.5 text-emerald-400">@svg('heroicon-o-check-badge', 'w-4 h-4') Kaskade abgeschlossen — {{ $freigegebenGesamt }} freigegeben{{ $anrOffen > 0 ? ', Anreicherung läuft …' : ($anrDone > 0 ? ' & angereichert' : '') }}</span>
            @if($anrFehler > 0)
                <span class="inline-flex items-center gap-1.5 text-rose-300">@svg('heroicon-o-exclamation-triangle', 'w-4 h-4') {{ $anrFehler }} Anreicherung(en) fehlgeschlagen — unten „neu anreichern"</span>
            @endif
        @elseif($laufFailed > 0)
            <span class="inline-flex items-center gap-1.5 text-rose-300">@svg('heroicon-o-x-circle', 'w-4 h-4') Fehlgeschlagen — Details unten</span>
        @endif
    </div>

    @if($laufRunning > 0 && $freigegebenGesamt > 0)
        <p class="text-[11px] text-gray-500 mb-3">Eine Stufe ist freigegeben — die nächste wird gerade erzeugt. Jede Stufe wird separat freigegeben.</p>
    @endif

    {{-- Stufen-Abschnitte: je Ebene ein Fortschritts-Header + Steps + Stufen-Freigabe (bei „prüfen"). --}}
    <div class="space-y-3">
        @forelse($stufen as $stufe)
            @php $stufeSteps = $lauf->steps->where('kind', $stufe['kind'])->sortBy([['depth', 'asc'], ['sort', 'asc']]); @endphp
            <div wire:key="stufe-{{ $stufe['kind'] }}" class="rounded-lg border border-white/10 p-2.5">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <span class="text-xs font-semibold text-gray-200">{{ $stufe['label'] }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-[11px] text-gray-400">{{ $stufe['fertig'] }}/{{ $stufe['total'] }} fertig{{ $stufe['freigegeben'] > 0 ? ' · ' . $stufe['freigegeben'] . ' freigegeben' : '' }}{{ ($stufe['geplant'] ?? 0) > 0 ? ' · ' . $stufe['geplant'] . ' geplant' : '' }}{{ ($stufe['uebernommen'] ?? 0) > 0 ? ' · ' . $stufe['uebernommen'] . ' übernommen' : '' }}</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] {{ $zustandPill[$stufe['zustand']] ?? 'bg-white/10 text-gray-400' }}">{{ $stufe['zustand'] }}</span>
                    </span>
                </div>
                @if(($stufe['geplant'] ?? 0) > 0)
                    {{-- Gericht = Basisrezepte: die Sub-Rezepte stehen schon als eigene Stufe, erzeugt
                         werden sie mit der Freigabe der Stufe darüber (gestufte Kaskade). --}}
                    <p class="text-[10px] text-violet-300/70 mb-1.5">{{ $stufe['geplant'] }} Sub-Rezept(e) geplant — sie werden erzeugt, sobald die Stufe darüber freigegeben ist.</p>
                @endif
                <div class="space-y-1.5">
                    @foreach($stufeSteps as $st)
                        @include('foodalchemist::livewire.planung.partials.step-zeile', $stepArgs($st, (int) $st->depth > 0))
                    @endforeach
                </div>
                @if($stufe['zustand'] === 'prüfen')
                    <div class="mt-2 pt-2 border-t border-white/10 flex justify-end">
                        <button wire:click="gibStufeFrei('{{ $stufe['kind'] }}')" class="text-[11px] text-emerald-300 hover:text-emerald-200 inline-flex items-center gap-1">
                            @svg('heroicon-o-check-badge', 'w-3.5 h-3.5') Ganze Stufe freigeben → nächste erzeugen
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-xs text-gray-500">Noch keine Schritte.</p>
        @endforelse
    </div>

    {{-- Globale Bulk-Freigabe als Fallback über alle Stufen. --}}
    @if($offeneEntwuerfe > 0)
        <div class="flex items-center justify-between gap-2 mt-3 pt-2 border-t border-white/10">
            <span class="text-[11px] text-gray-400">{{ $offeneEntwuerfe }} Entwurf/Entwürfe offen</span>
            <span class="flex gap-2">
                <button wire:click="alleFrei" class="text-[11px] text-emerald-300 hover:text-emerald-200">Alle freigeben</button>
                <button wire:click="alleVerwerfen" class="text-[11px] text-rose-300 hover:text-rose-200">Alle verwerfen</button>
            </span>
        </div>
    @endif
</x-foodalchemist::modal-section>
