{{-- Gäste-Drucke des Speiseplans: Überschriften-Schrift passend zum Präsentations-Design.
     Serif-Designs → PT Serif (liegt im Modul, OFL). Im PDF als data-URI eingebettet, weil DomPDF
     Dateien außerhalb des chroot (Modul unter vendor/) nicht liest; im Browser über Google Fonts.
     Pfad über ReflectionClass, nicht __DIR__ (kompilierte Views liegen im Cache). --}}
@php
    // Die Allergenliste ist ein Arbeitsdokument — sie bleibt bei DejaVu und bekommt keine Schrift eingebettet.
    $serif = (bool) ($optik['serif'] ?? true) && ($format ?? 'buffet') !== 'liste';
    $fontQuellen = [];
    if ($serif && ($istPdf ?? false)) {
        $fontDir = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/fonts/';
        foreach (['normal' => 'PTSerif-Regular.ttf', 'bold' => 'PTSerif-Bold.ttf'] as $gewicht => $datei) {
            if (is_file($fontDir . $datei)) {
                $fontQuellen[$gewicht] = 'data:font/truetype;base64,' . base64_encode((string) file_get_contents($fontDir . $datei));
            }
        }
        // DomPDF legt seinen Font-Cache (storage/fonts) nicht selbst an — fehlt er, bricht das PDF ab.
        if ($fontQuellen !== []) {
            \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('fonts'));
        }
    }
@endphp
@if($serif && ! ($istPdf ?? false))
    <link href="https://fonts.googleapis.com/css2?family=PT+Serif:wght@400;700&display=swap" rel="stylesheet">
@endif
@if($fontQuellen !== [])
    <style>
        @foreach($fontQuellen as $gewicht => $src)
        @font-face { font-family: "PT Serif"; font-style: normal; font-weight: {{ $gewicht }}; src: url("{{ $src }}") format("truetype"); }
        @endforeach
    </style>
@endif
