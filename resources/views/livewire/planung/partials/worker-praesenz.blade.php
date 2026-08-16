{{-- Worker-Präsenz PROAKTIV (Etappe 8, Teil 2): Ampel-Warnung VOR dem Go, wenn kein lebender
     `queue:work` gestempelt hat (WorkerHealthService::status() = still/unbekannt). Ergänzt den
     reaktiven Watchdog-`hinweis` (der erst nach ~90 s eines hängenden Laufs anschlägt).
     `workerWarnung` ist null, solange der Worker `gesund` ist → dann rendert dieser Block nichts. --}}
@if(($workerWarnung ?? null) !== null)
    <div class="mb-2 flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-[11px] text-amber-300" data-worker-health>
        @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 shrink-0 mt-px')
        <span>{{ $workerWarnung }}</span>
    </div>
@endif
