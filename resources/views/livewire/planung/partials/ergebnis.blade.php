{{-- Worker-Cockpit: Stufen-Abschnitte (Concept · Gerichte · Basisrezepte) mit Fortschritts-Headern +
     Stufen-Freigabe. Nur erreichte Stufen erscheinen (progressive Enthüllung). Erwartet $lauf gesetzt. --}}
@php
    $stepLabel = ['queued' => 'wartet', 'running' => 'läuft', 'done' => 'Vorschau', 'freigegeben' => 'freigegeben', 'verworfen' => 'verworfen', 'failed' => 'Fehler', 'skipped' => 'übernommen'];
    $stepColor = ['queued' => 'text-amber-300', 'running' => 'text-amber-300', 'done' => 'text-emerald-300', 'freigegeben' => 'text-emerald-400', 'verworfen' => 'text-gray-500', 'failed' => 'text-rose-300', 'skipped' => 'text-gray-400'];
    $refRoute = ['gericht' => 'foodalchemist.verkauf.index', 'rezept' => 'foodalchemist.recipes.index', 'concept' => 'foodalchemist.concepts.index'];
    $laufRunning = $lauf->steps->whereIn('status', ['queued', 'running'])->count();
    $laufDone = $lauf->steps->whereIn('status', ['done', 'freigegeben'])->count();
    $laufFailed = $lauf->steps->where('status', 'failed')->count();
    $offeneEntwuerfe = $lauf->steps->where('status', 'done')->count();
    $stufen = $this->stufenAusSteps($lauf->steps);
    $zustandPill = ['läuft' => 'bg-amber-500/15 text-amber-300', 'prüfen' => 'bg-emerald-500/15 text-emerald-300', 'erledigt' => 'bg-white/10 text-gray-400'];
    $stepArgs = fn ($s, $indent) => ['st' => $s, 'stepLabel' => $stepLabel, 'stepColor' => $stepColor, 'refRoute' => $refRoute, 'indent' => $indent];
@endphp
<x-foodalchemist::modal-section title="Kaskade — Stufen &amp; Freigabe">
    @if($lauf->brief)
        <p class="text-[11px] text-gray-400 mb-3 line-clamp-2"><span class="text-gray-500">Brief:</span> {{ \Illuminate\Support\Str::limit($lauf->brief, 200) }}</p>
    @endif

    {{-- Gesamt-Worker-Status (aus den Steps abgeleitet, kein globaler Ping). --}}
    <div class="flex items-center gap-2 mb-3 text-xs font-medium">
        @if($laufRunning > 0 && ($hinweis ?? null) !== null)
            <span class="inline-flex items-center gap-1.5 text-amber-400">@svg('heroicon-o-exclamation-triangle', 'w-4 h-4') Worker hängt? — {{ $laufRunning }} Schritt(e) warten (vermutlich kein Queue-Worker)</span>
        @elseif($laufRunning > 0)
            <span class="inline-flex items-center gap-1.5 text-amber-300">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Worker arbeitet — {{ $laufRunning }} Schritt(e) laufen{{ $laufDone > 0 ? ', ' . $laufDone . ' fertig' : '' }}</span>
        @elseif($laufDone > 0)
            <span class="inline-flex items-center gap-1.5 text-emerald-300">@svg('heroicon-o-check-circle', 'w-4 h-4') Vorschau fertig — {{ $laufDone }} Entwurf/Entwürfe (ansehen / stufenweise freigeben)</span>
        @elseif($laufFailed > 0)
            <span class="inline-flex items-center gap-1.5 text-rose-300">@svg('heroicon-o-x-circle', 'w-4 h-4') Fehlgeschlagen — Details unten</span>
        @endif
    </div>

    {{-- Stufen-Abschnitte: je Ebene ein Fortschritts-Header + Steps + Stufen-Freigabe (bei „prüfen"). --}}
    <div class="space-y-3">
        @forelse($stufen as $stufe)
            @php $stufeSteps = $lauf->steps->where('kind', $stufe['kind'])->sortBy([['depth', 'asc'], ['sort', 'asc']]); @endphp
            <div wire:key="stufe-{{ $stufe['kind'] }}" class="rounded-lg border border-white/10 p-2.5">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <span class="text-xs font-semibold text-gray-200">{{ $stufe['label'] }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-[11px] text-gray-400">{{ $stufe['fertig'] }}/{{ $stufe['total'] }} fertig{{ $stufe['freigegeben'] > 0 ? ' · ' . $stufe['freigegeben'] . ' freigegeben' : '' }}</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] {{ $zustandPill[$stufe['zustand']] ?? 'bg-white/10 text-gray-400' }}">{{ $stufe['zustand'] }}</span>
                    </span>
                </div>
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
