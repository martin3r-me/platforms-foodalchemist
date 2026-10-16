<?php

use Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 84 §5.8 / PR 9 · Prüfauftrag „MitBearbeitungssperre ohne sperreBeiSchliessen".
 *
 * Ergebnis der Prüfung (16.10.2026): Die zehn Komponenten ohne `sperreBeiSchliessen` sind keine Modale mit eigener
 * Sperre. Die sechs Detailspalten (GPs, Formate, Concepter, Produktion, Verkauf, Rezepte) geben eine hier geholte Sperre
 * in `zeige()`/`waehle()` frei (`sperreBeiWechselFreigeben`) und über „Fertig". Pakete und Konzepte geben sie in
 * `waehle()` und beim Löschen frei (`bearbeitenBeenden`). Zutaten- und Schritt-Editor halten keine eigene Sperre:
 * eingebettet nutzen sie das Ziel ihres Editors, frei stehend ist das Ziel null.
 *
 * Damit das so bleibt: Jede Komponente mit Bearbeitungssperre, die ein `x-foodalchemist::modal` rendert, muss beim
 * Schließen freigeben (eigener `modal.closed`-Handler ruft `sperreBeiSchliessen`, dazu `sperrModalName`). Sonst bleibt
 * die Sperre bis zu 15 Minuten liegen und blockiert Kolleginnen und Kollegen.
 */
const SPEC84_MODAL_AUSNAHMEN = [
    \Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor::class => 'eingebettet = Ziel des RecipeModal (gibt dort frei); frei stehend sperrZiel() null',
];

it('Spec 84 PR 9 · Modale mit Bearbeitungssperre geben die Sperre beim Schließen frei', function () {
    $src = dirname(__DIR__, 2) . '/src';
    $views = dirname(__DIR__, 2) . '/resources/views/';
    $ohne = [];
    $geprueft = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/Livewire'));
    foreach ($it as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php' || str_contains($datei->getPathname(), '/Concerns/')) {
            continue;
        }
        $klasse = 'Platform\\FoodAlchemist\\Livewire\\' . str_replace(['/', '.php'], ['\\', ''], substr($datei->getPathname(), strlen($src . '/Livewire/')));
        if (! class_exists($klasse) || ! in_array(MitBearbeitungssperre::class, class_uses_recursive($klasse), true)) {
            continue;
        }
        $code = file_get_contents($datei->getPathname());
        if (! preg_match("/view\\('foodalchemist::([^']+)'/", $code, $m)) {
            continue;
        }
        $blade = $views . str_replace('.', '/', $m[1]) . '.blade.php';
        if (! is_file($blade) || ! str_contains(file_get_contents($blade), 'x-foodalchemist::modal')) {
            continue;
        }
        $geprueft++;
        $gibtFrei = str_contains($code, 'function sperrModalName') && str_contains($code, 'sperreBeiSchliessen(');
        if (! $gibtFrei && ! isset(SPEC84_MODAL_AUSNAHMEN[$klasse])) {
            $ohne[] = $klasse;
        }
    }

    expect($geprueft)->toBeGreaterThan(10)   // gemessen 16.10.: 14 Modale mit Sperre
        ->and($ohne)->toBe([], 'Modal mit Bearbeitungssperre gibt beim Schließen nicht frei');
});
