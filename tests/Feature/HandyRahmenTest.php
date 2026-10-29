<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht · Rahmen: linke Leiste, Detail-Leiste, Lesemodus (Ansicht + Server).
 * Das Layout selbst prüft der Browser (Screenshots im PR); hier die Verträge, auf die das Layout baut.
 */
if (! class_exists('HandyRahmenTestPanel')) {
    class HandyRahmenTestPanel extends Component
    {
        use HandyLesebereich;

        public int $geschrieben = 0;

        public int $gelesen = 0;

        public function handyLeseMethoden(): array
        {
            return ['aufklappen'];
        }

        public function aufklappen(): void
        {
            $this->gelesen++;
        }

        public function speichern(): void
        {
            $this->geschrieben++;
        }

        public function render(): string
        {
            return '<div>{{ $geschrieben }}</div>';
        }
    }
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

// Livewire-Tests schicken bei Folgeaufrufen nur X-Livewire mit (RequestBroker::addHeaders ersetzt alle Kopfzeilen) —
// die Entscheidung wird deshalb direkt geprüft; den echten Weg Browser → Kopfzeile → Abweisung belegt der PR-Browsertest.
it('Lesemodus Server: vom Handy wird eine Ändern-Methode im Lese-Bereich abgewiesen', function () {
    request()->headers->set(HandyLesemodus::KOPFZEILE, '1');

    expect(HandyLesemodus::abgewiesen(new HandyRahmenTestPanel, 'speichern'))->toBeTrue();
});

it('Lesemodus Server: Lese-Methoden und interne Aufrufe laufen auch vom Handy', function () {
    request()->headers->set(HandyLesemodus::KOPFZEILE, '1');

    expect(HandyLesemodus::abgewiesen(new HandyRahmenTestPanel, 'aufklappen'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new HandyRahmenTestPanel, '$refresh'))->toBeFalse();
});

it('Lesemodus Server: ohne Handy-Kennzeichen (Laptop, auch schmales Fenster, Tablet) läuft alles', function () {
    expect(HandyLesemodus::abgewiesen(new HandyRahmenTestPanel, 'speichern'))->toBeFalse();

    request()->headers->set(HandyLesemodus::KOPFZEILE, '0');
    expect(HandyLesemodus::abgewiesen(new HandyRahmenTestPanel, 'speichern'))->toBeFalse();

    Livewire::test(HandyRahmenTestPanel::class)
        ->call('speichern')
        ->assertSet('geschrieben', 1);
});

it('Lesemodus Server: Komponenten ohne Lese-Bereich sind nie betroffen (z. B. Planung, Editoren)', function () {
    request()->headers->set(HandyLesemodus::KOPFZEILE, '1');

    $ohneTrait = new class extends Component
    {
        public function speichern(): void {}

        public function render(): string
        {
            return '<div></div>';
        }
    };

    expect(HandyLesemodus::abgewiesen($ohneTrait, 'speichern'))->toBeFalse();
});

it('Lesemodus Server: zentral freigeschaltete Methode läuft vom Handy', function () {
    request()->headers->set(HandyLesemodus::KOPFZEILE, '1');
    config(['foodalchemist.handy.aendern_frei' => ['speichern']]);

    expect(HandyLesemodus::abgewiesen(new HandyRahmenTestPanel, 'speichern'))->toBeFalse();
});

it('Handy-Entscheidung hängt nicht an der Fensterbreite allein: Touch und schmal, oder bewusst gewählt', function () {
    expect(HandyLesemodus::JS_MODUS)->toContain('(hover: none) and (pointer: coarse) and (max-width: 47.99rem)')
        ->toContain("'desktop'")
        ->not->toContain('"');
});

it('Lesemodus Ansicht: Aktionen tragen ihren Schlüssel, Freischaltung setzt data-fa-aendern-frei', function () {
    expect((string) HandyLesemodus::attr('kopf.aktion'))->toBe('data-fa-aendern="kopf.aktion"');

    config(['foodalchemist.handy.aendern_frei' => ['kopf.aktion']]);

    expect((string) HandyLesemodus::attr('kopf.aktion'))->toBe('data-fa-aendern="kopf.aktion" data-fa-aendern-frei');
});

it('Detail-Kopf: Aktionen markiert und Hinweis „Am Computer bearbeiten“ vorhanden', function () {
    $html = Blade::render('<x-fa::detail-kopf title="Minestrone"><x-slot:aktion><button>Bearbeiten</button></x-slot:aktion><x-slot:menue><span>Löschen</span></x-slot:menue></x-fa::detail-kopf>');

    expect($html)->toContain('data-fa-aendern="kopf.aktion"')
        ->toContain('data-fa-aendern="kopf.menue"')
        ->toContain('data-fa-handy-hinweis')
        ->toContain('Am Computer bearbeiten');
});

it('KI-Knopf meldet sich als Ändern-Aktion mit seinem Methodennamen', function () {
    $html = Blade::render('<x-foodalchemist::ki-action action="kiKohaerenz(12)" label="Kohärenz prüfen" />');

    expect($html)->toContain('data-fa-aendern="ki.kiKohaerenz"');
});

it('Detail-Leiste: meldet Auswahl und ist Lese-Bereich, Planung ohne Lesemodus', function () {
    $mit = Blade::render('<x-foodalchemist::detail-sidebar title="Detail" scope="activity_test" :auswahl="42">x</x-foodalchemist::detail-sidebar>');
    $ohne = Blade::render('<x-foodalchemist::detail-sidebar title="Detail" scope="activity_test" :auswahl="\'\'">x</x-foodalchemist::detail-sidebar>');
    $unbekannt = Blade::render('<x-foodalchemist::detail-sidebar title="Detail" scope="activity_test">x</x-foodalchemist::detail-sidebar>');
    $planung = Blade::render('<x-foodalchemist::detail-sidebar title="Detail" scope="activity_test" :lesemodus="false">x</x-foodalchemist::detail-sidebar>');

    expect($mit)->toContain('data-fa-auswahl="42"')->toContain('data-fa-handy-lesen')
        ->and($ohne)->toContain('data-fa-auswahl=""')
        ->and($unbekannt)->toContain('data-fa-auswahl="-"')
        ->and($planung)->not->toContain('data-fa-handy-lesen');
});

it('Linke Leiste: FA-Hülle ersetzt x-ui-page-sidebar in allen FA-Ansichten außer Lieferanten (PR3)', function () {
    $views = dirname(__DIR__, 2).'/resources/views/livewire';
    $treffer = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views)) as $datei) {
        if ($datei->isFile() && str_ends_with($datei->getFilename(), '.blade.php') && str_contains((string) file_get_contents($datei->getPathname()), '<x-ui-page-sidebar')) {
            $treffer[] = str_replace($views.'/', '', $datei->getPathname());
        }
    }

    expect($treffer)->toBe(['suppliers/index.blade.php']);
});

it('Linke Leiste rendert mit Handy-Schublade und Öffnen-Leiste', function () {
    $html = Blade::render('<x-foodalchemist::page-sidebar title="Hauptgruppen">Baum</x-foodalchemist::page-sidebar>');

    expect($html)->toContain('data-fa-page-sidebar')
        ->toContain('data-fa-page-sidebar-oeffnen')
        ->toContain('data-fa-page-sidebar-abdunklung')
        ->toContain('Baum');
});
