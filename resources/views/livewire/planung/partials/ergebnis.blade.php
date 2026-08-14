{{-- Worker-Ergebnis: Status (arbeitet/hängt/fertig) + Fan-out-Baum + Freigabe. Erwartet $lauf gesetzt (Parent prüft). --}}
@php
    $stepLabel = ['queued' => 'wartet', 'running' => 'läuft', 'done' => 'Entwurf', 'freigegeben' => 'freigegeben', 'verworfen' => 'verworfen', 'failed' => 'Fehler', 'skipped' => 'übernommen'];
    $stepColor = ['queued' => 'text-amber-300', 'running' => 'text-amber-300', 'done' => 'text-emerald-300', 'freigegeben' => 'text-emerald-400', 'verworfen' => 'text-gray-500', 'failed' => 'text-rose-300', 'skipped' => 'text-gray-400'];
    $refRoute = ['gericht' => 'foodalchemist.verkauf.index', 'rezept' => 'foodalchemist.recipes.index', 'concept' => 'foodalchemist.concepts.index'];
    $offeneEntwuerfe = $lauf->steps->where('status', 'done')->count();
    $laufRunning = $lauf->steps->whereIn('status', ['queued', 'running'])->count();
    $laufDone = $lauf->steps->whereIn('status', ['done', 'freigegeben'])->count();
    $laufFailed = $lauf->steps->where('status', 'failed')->count();
    $rootSteps = $lauf->steps->whereNull('parent_step_id')->values();
    $childrenBy = $lauf->steps->whereNotNull('parent_step_id')->groupBy('parent_step_id');
    $stepArgs = fn ($s, $indent) => ['st' => $s, 'stepLabel' => $stepLabel, 'stepColor' => $stepColor, 'refRoute' => $refRoute, 'indent' => $indent];
@endphp
<x-foodalchemist::modal-section title="Ergebnis (Entwürfe) — Freigabe">
    {{-- #2/#3 Worker-/Fortschritts-Status aus den Steps abgeleitet (kein globaler Worker-Ping). --}}
    <div class="flex items-center gap-2 mb-3 text-xs font-medium">
        @if($laufRunning > 0 && ($hinweis ?? null) !== null)
            <span class="inline-flex items-center gap-1.5 text-amber-400">@svg('heroicon-o-exclamation-triangle', 'w-4 h-4') Worker hängt? — {{ $laufRunning }} Schritt(e) warten (vermutlich kein Queue-Worker)</span>
        @elseif($laufRunning > 0)
            <span class="inline-flex items-center gap-1.5 text-amber-300">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Worker arbeitet — {{ $laufRunning }} Schritt(e) laufen{{ $laufDone > 0 ? ', ' . $laufDone . ' fertig' : '' }}</span>
        @elseif($laufDone > 0)
            <span class="inline-flex items-center gap-1.5 text-emerald-300">@svg('heroicon-o-check-circle', 'w-4 h-4') Fertig — {{ $laufDone }} Entwurf/Entwürfe erzeugt (rechts „ansehen" / freigeben)</span>
        @elseif($laufFailed > 0)
            <span class="inline-flex items-center gap-1.5 text-rose-300">@svg('heroicon-o-x-circle', 'w-4 h-4') Fehlgeschlagen — Details unten</span>
        @endif
    </div>
    @if($offeneEntwuerfe > 0)
        <div class="flex items-center justify-between gap-2 mb-2 pb-2 border-b border-white/10">
            <span class="text-[11px] text-gray-400">{{ $offeneEntwuerfe }} Entwurf/Entwürfe warten auf Freigabe</span>
            <span class="flex gap-2">
                <button wire:click="alleFrei" class="text-[11px] text-emerald-300 hover:text-emerald-200">Alle freigeben</button>
                <button wire:click="alleVerwerfen" class="text-[11px] text-rose-300 hover:text-rose-200">Alle verwerfen</button>
            </span>
        </div>
    @endif
    {{-- #4 Fan-out-Baum: root-Steps + Kinder (parent_step_id) eingerückt. Step-Zeile inkl. In-Context-Ansicht
         + „Verwendetes Wissen" (#1a) im geteilten Partial. --}}
    <div class="space-y-1.5">
        @forelse($rootSteps as $st)
            @include('foodalchemist::livewire.planung.partials.step-zeile', $stepArgs($st, false))
            @foreach($childrenBy[$st->id] ?? [] as $child)
                @include('foodalchemist::livewire.planung.partials.step-zeile', $stepArgs($child, true))
            @endforeach
        @empty
            <p class="text-xs text-gray-500">Noch keine Schritte.</p>
        @endforelse
    </div>
</x-foodalchemist::modal-section>
