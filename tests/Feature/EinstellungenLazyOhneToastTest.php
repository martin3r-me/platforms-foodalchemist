<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Kalkulation;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einstellungen (23.10.): Lazy-Bereiche lädt Livewire über `__lazyLoad` nach. Der globale Sperr-Haken hielt das für eine
 * Schreibaktion und zeigte beim bloßen Hineinscrollen „Zum Ändern zuerst ‚Bearbeiten' klicken." (Küche, Plattform).
 * Nachladen ist mount = Lesen: kein Toast, ohne Sperre und ohne Admin-Recht. Schreiben bleibt abgewiesen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->nachladen = function () {
        $c = Livewire::test(Kalkulation::class, ['lazy' => true]);
        preg_match('/__lazyLoad\((?:\'|&#039;)([^\'&]+)/', $c->html(), $m);
        expect($m[1] ?? null)->not->toBeNull();   // Platzhalter da = der Test geht wirklich über den Lazy-Weg

        return $c->call('__lazyLoad', $m[1]);
    };
});

it('Admin ohne Sperre: Nachladen zeigt keinen Toast, Speichern bleibt abgewiesen', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Admin', 'owner'));

    ($this->nachladen)()->assertNotDispatched('fa-saved')->assertSeeHtml('Garverlust')
        ->call('speichern')->assertDispatched('fa-saved');
});

it('Mitglied ohne Admin-Recht: Nachladen zeigt keinen Toast', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Mitglied', 'member'));

    ($this->nachladen)()->assertNotDispatched('fa-saved');
});
