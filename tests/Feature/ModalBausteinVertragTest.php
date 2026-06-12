<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * UI-Audit 2026-06-12 (Regressionsschutz): Der `.dot`-Event-Modifier wird vom
 * gebündelten Alpine 3.15 IGNORIERT — Listener hörten auf `modal-open` statt
 * `modal.open`, KEIN Modal konnte je per Livewire-Event öffnen (live im
 * Browser bewiesen). Der Baustein muss die addEventListener-Brücke nutzen;
 * dieser Vertrag verhindert die Rückkehr der @-Syntax mit Punkt-Events.
 */
it('Modal-Baustein: addEventListener-Brücke statt .dot-Listener (Alpine-3.15-Falle)', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/components/modal.blade.php');

    expect($blade)->not->toContain('@modal-open.dot')
        ->and($blade)->not->toContain('@modal-close.dot')
        ->and($blade)->toContain("addEventListener('modal.open'")
        ->and($blade)->toContain("addEventListener('modal.close'");
});

it('keine View nutzt .dot-Event-Listener (gleiches Muster, gleiche Falle)', function () {
    $treffer = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../resources/views'));
    foreach ($it as $datei) {
        if ($datei->isFile() && str_ends_with($datei->getFilename(), '.blade.php')
            && preg_match('/@[\w-]+\.dot/', file_get_contents($datei->getPathname()))) {
            $treffer[] = $datei->getFilename();
        }
    }

    expect($treffer)->toBe([]);
});
