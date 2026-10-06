{{-- Hinweis VOR dem Erstellen, wenn die Hintergrund-Erstellung gerade nicht läuft
     (WorkerHealthService::status() = still/unbekannt). Ergänzt den reaktiven Hinweis im
     Fortschritt-Reiter (der erst nach ~90 s eines hängenden Laufs anschlägt).
     `workerWarnung` ist null, solange alles läuft: dann rendert dieser Block nichts.
     fa-pass: kompaktes Signal statt Kasten, damit die klebende Erstell-Leiste niedrig bleibt
     (Laptop-Höhe). --}}
@if(($workerWarnung ?? null) !== null)
    <x-fa::signal tone="warn" class="items-start" data-worker-health>{{ $workerWarnung }}</x-fa::signal>
@endif
