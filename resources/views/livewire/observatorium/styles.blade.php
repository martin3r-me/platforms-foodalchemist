{{-- Observatorium/Trend-Research/Wettbewerber-CSS: resources/css/foodalchemist-observatorium.css, ausgeliefert über
     _platform/fa-assets (beide Hüllen, ohne Host-Build). Eingebunden aus observatorium/index, trendradar/index und
     trendradar/wettbewerber — @once lädt die Datei je Seite nur einmal. --}}
@once
<link rel="stylesheet" href="{{ \Platform\FoodAlchemist\Support\FaShell::assetUrl('foodalchemist-observatorium.css') }}" data-fa-observatorium-css>
@endonce
