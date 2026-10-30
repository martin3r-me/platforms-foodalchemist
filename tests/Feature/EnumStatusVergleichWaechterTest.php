<?php

/**
 * Wächter (29.10.2026): `status` ist bei BulkRun, BulkGpProposal, BulkProposal, Recipe, Angebot, Gp, ProductionOrder, Signal und Order
 * ein Enum-Cast. Ein String-Vergleich (`->status === 'running'`) ist dort immer falsch — so meldete der GP-Dialog „0 Vorschläge“ und
 * es kam zum Doppellauf (GpAnreichernAnzeigeTest). Statisch lässt sich das Modell hinter `$x->status` nicht sicher bestimmen; darum
 * führt dieser Wächter die Dateien, in denen String-Vergleiche auf `->status` GEPRÜFT sind (Modelle ohne Enum-Cast oder Rohzeilen aus
 * DB::table). Kommt eine neue Datei dazu: prüfen, welches Modell es ist — Enum-Modell → Enum vergleichen bzw. Modell-Methode
 * (`FoodAlchemistBulkRun::laeuft()`); sonst die Datei hier mit Grund eintragen.
 */
const ENUM_STATUS_GEPRUEFT = [
    // Planung (PlanningRun/-Step: String-Spalten, kein Cast)
    'src/Livewire/Planung/Index.php', 'src/Livewire/Concerns/FortschrittAnsicht.php', 'src/Support/Planung/KaskadenAnsicht.php',
    'src/Services/PlanningCascadeService.php', 'src/Services/RecipeDependencyWorkflowService.php', 'src/Services/PlanningSessionService.php',
    'resources/views/livewire/planung/partials/step-zeile.blade.php', 'resources/views/livewire/planung/partials/fortschritt.blade.php',
    'resources/views/livewire/planung/index.blade.php',
    // Trends, Produktpass, Necta, Mails, Befunde, Inventur, Projekte, Preislisten-Lauf, KI-Aufträge: eigene Modelle ohne Enum-Cast
    'src/Livewire/Trendradar/Index.php', 'src/Services/TrendService.php', 'resources/views/livewire/trendradar/index.blade.php',
    'resources/views/livewire/trendradar/eingang.blade.php',   // Spec 93: FoodAlchemistTrendEingang.status ist eine String-Spalte
    'src/Livewire/Suppliers/ProduktpassPanel.php', 'src/Jobs/ProduktpassLesenJob.php', 'src/Services/Produktpass/ProduktpassService.php',
    'src/Services/Produktpass/ProduktpassAbweichungService.php', 'src/Livewire/Suppliers/NectaPruefaelle.php',
    'src/Services/OrderMailService.php', 'resources/views/livewire/orders/editor.blade.php', 'src/Services/RecipeFindingService.php',
    'src/Services/ConformanceService.php', 'src/Models/FoodAlchemistInventoryCount.php', 'resources/views/livewire/projekte/index.blade.php',
    'resources/views/livewire/preislisten/index.blade.php', 'src/Services/Ai/KiAuftragService.php', 'src/Services/PreislistenImportService.php',
    // Concept: (string)-Cast auf den Status, kein Enum-Modell aus der Liste
    'src/Tools/ConceptSlotsPostTool.php', 'src/Services/ConceptGeneratorService.php',
    // GP als Rohzeile (DB::table → status ist ein String)
    'src/Services/GpBereinigenService.php', 'src/Services/GpUmhaengenService.php', 'src/Services/GpPaket/GpPaketVerknuepfer.php',
];

function enumStatusTreffer(): array
{
    $basis = dirname(__DIR__, 2);
    $muster = '/(->status\s*(===|!==|==|!=)\s*[\'"])|([\'"]\s*(===|!==|==|!=)\s*\$[\w>-]*->status\b)|(match\s*\(\s*\$[\w>-]*->status\s*\)\s*\{[^}]*[\'"]\w+[\'"]\s*=>)/';
    $treffer = [];
    foreach (['src', 'resources/views'] as $ordner) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis.'/'.$ordner, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! str_ends_with($f->getFilename(), '.php')) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($basis) + 1);
            foreach (file($f->getPathname()) as $i => $zeile) {
                if (preg_match($muster, $zeile) === 1 && ! preg_match('/^\s*(\/\/|\*|#)/', $zeile)) {
                    $treffer[$rel][] = $i + 1;
                }
            }
        }
    }

    return $treffer;
}

it('String-Vergleiche auf ->status nur in geprüften Dateien (Enum-Modelle vergleichen Enums)', function () {
    $neu = array_diff_key(enumStatusTreffer(), array_flip(ENUM_STATUS_GEPRUEFT));

    expect(array_map(fn ($z) => implode(',', $z), $neu))->toBe([], 'Neue String-Vergleiche auf ->status — Modell prüfen (Enum-Cast?), siehe Docblock');
});

it('Gegenprobe: der Wächter findet den alten Fehler im GP-Dialog', function () {
    $zeile = "<div @if(\$bulkRun->status === 'running') wire:poll.2s @endif>";
    expect(preg_match('/->status\s*(===|!==|==|!=)\s*[\'"]/', $zeile))->toBe(1);
});

it('die Lauf-Ansichten nutzen laeuft() statt eines String-Vergleichs', function () {
    $basis = dirname(__DIR__, 2);
    foreach (['gps/gp-modal', 'recipes/recipe-modal', 'recipes/browser', 'verkauf/vk-modal'] as $v) {
        $t = file_get_contents("{$basis}/resources/views/livewire/{$v}.blade.php");
        expect($t)->not->toMatch('/(bulkRun|\$run)->status\s*(===|!==)\s*\'running\'/', $v);
    }
});
