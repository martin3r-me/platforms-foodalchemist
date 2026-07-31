<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RecipeBrowser;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 28 / E14: Spalten-Ansichten der Browser-Tabellen.
 *
 * Der wichtigste Test hier ist der ERSTE: Katalog-Reihenfolge == Zellen-Reihenfolge im Blade.
 * Beim Bau lief genau das auseinander — der Kopf folgte der Ansichts-Reihenfolge, die Zellen der
 * Datei-Reihenfolge, und „€/kg" stand über dem Status-Select. Eine Zählprüfung (gleich viele
 * Köpfe wie Zellen) hat das NICHT gefunden, weil die Anzahl stimmte und nur die Ordnung falsch war.
 * Deshalb wird hier die Ordnung verglichen, nicht die Menge.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

/** Alle Browser mit Spalten-Ansichten: Klasse => Blade-Pfad. */
dataset('browser', [
    'Basisrezepte' => [RecipeBrowser::class, 'recipes/browser'],
    'Gerichte' => [\Platform\FoodAlchemist\Livewire\Verkauf\Browser::class, 'verkauf/browser'],
    'Grundprodukte' => [\Platform\FoodAlchemist\Livewire\Gps\Browser::class, 'gps/browser'],
]);

it('Katalog-Reihenfolge und Zellen-Reihenfolge im Blade sind identisch', function (string $klasse, string $blade) {
    $html = file_get_contents(__DIR__ . '/../../resources/views/livewire/' . $blade . '.blade.php');

    preg_match_all("/@if\(in_array\('(\w+)', \\\$spalten, true\)\)/", $html, $treffer);

    // Ordnung vergleichen, nicht Menge: beim Bau stimmte die ANZAHL und nur die Reihenfolge war
    // falsch — „€/kg" stand über dem Status-Select.
    expect($treffer[1])->toBe(array_keys($klasse::SPALTEN));
})->with('browser');

it('jeder Browser hat eine Standard-Ansicht und nennt nur bekannte Spalten', function (string $klasse) {
    expect($klasse::ANSICHTEN)->toHaveKey('standard');
    $katalog = array_keys($klasse::SPALTEN);

    foreach ($klasse::ANSICHTEN as $key => [$label, $spalten]) {
        expect($label)->not->toBeEmpty();
        expect(array_diff($spalten, $katalog))->toBe([], "Ansicht [{$key}] nennt unbekannte Spalten");
    }
})->with('browser');

it('spalten() sortiert jede Ansicht in Katalog-Ordnung', function (string $klasse) {
    $katalog = array_keys($klasse::SPALTEN);

    foreach (array_keys($klasse::ANSICHTEN) as $ansicht) {
        $c = Livewire::test($klasse)->set('ansicht', $ansicht);
        $spalten = $c->instance()->spalten();
        $erwartet = array_values(array_filter($katalog, fn ($k) => in_array($k, $spalten, true)));
        expect($spalten)->toBe($erwartet);
    }
})->with('browser');

it('jede Ansicht nennt nur Spalten, die es im Katalog gibt', function () {
    $katalog = array_keys(RecipeBrowser::SPALTEN);

    foreach (RecipeBrowser::ANSICHTEN as $key => [$label, $spalten]) {
        expect($label)->not->toBeEmpty();
        // Hinweis: toContain nimmt KEINE Meldung als zweites Argument — das wäre eine zweite
        // Nadel. Der Kontext steht deshalb im array_diff.
        expect(array_diff($spalten, $katalog))->toBe([], "Ansicht [{$key}] nennt unbekannte Spalten");
    }
});

it('spalten() liefert Katalog-Ordnung, nicht die Reihenfolge der Ansichts-Definition', function () {
    $c = Livewire::test(RecipeBrowser::class);

    // 'kalkulation' ist als [kategorie, ekkg, yield, zutaten, status] definiert — die Ausgabe muss
    // trotzdem der Katalog-Ordnung folgen, sonst versetzt sie Kopf und Zellen.
    $c->set('ansicht', 'kalkulation');
    expect($c->instance()->spalten())->toBe(['kategorie', 'ekkg', 'yield', 'zutaten', 'status']);

    $c->set('ansicht', 'pflege');
    expect($c->instance()->spalten())->toBe(['kategorie', 'fertigung', 'zutaten', 'allergen', 'status']);
});

it('eine unbekannte Ansicht fällt auf Standard zurück statt zu leeren Spalten zu führen', function () {
    $c = Livewire::test(RecipeBrowser::class)->set('ansicht', 'gibt-es-nicht');

    expect($c->instance()->spalten())->toBe(['kategorie', 'geschmack', 'ekkg', 'status']);
});

it('die Standard-Ansicht ist knapp — die Mitte ist eng, wenn das Panel offen ist', function () {
    // Bewusst als Zusicherung: wer eine sechste Spalte in den Standard legt, soll hier stolpern
    // und die Entscheidung treffen, nicht sie nebenbei mitnehmen.
    expect(RecipeBrowser::ANSICHTEN['standard'][1])->toHaveCount(4);
});

it('der Ansichts-Schalter rendert und die aktive Ansicht bestimmt den Tabellenkopf', function () {
    $this->makeRecipe($this->rootTeam, 'Brauner Fond: Kalb');

    // NUR den Tabellenkopf DIESER Tabelle prüfen. Zwei Fallen dabei:
    //  1. „Geschmack" steht auch im Filter-Select der Sidebar — Suche übers Dokument ist falsch-positiv.
    //  2. Die Seite mountet Editor-Modals mit EIGENEN Tabellen; der erste <thead> im Dokument
    //     gehört dem Zutaten-Editor, nicht dem Browser. Deshalb ab dem Ansichts-Schalter suchen.
    $kopf = function (string $html): string {
        $anker = strpos($html, 'data-ansicht-schalter');
        $i = $anker === false ? false : strpos($html, '<thead', $anker);
        $j = $i === false ? false : strpos($html, '</thead>', $i);

        return $i === false || $j === false ? '' : substr($html, $i, $j - $i);
    };

    $standard = Livewire::test(RecipeBrowser::class)->html();
    expect($standard)->toContain('data-ansicht-schalter')->toContain('data-ansicht="kalkulation"');
    expect($kopf($standard))->toContain('€/kg')->toContain('Geschmack')->not->toContain('Yield');

    $kalk = Livewire::test(RecipeBrowser::class)->set('ansicht', 'kalkulation')->html();
    expect($kopf($kalk))->toContain('Yield')->toContain('Zutaten')->not->toContain('Geschmack');
});
