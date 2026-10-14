<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Küchenmonitor (Wandmonitor): Das Kiosk-Layout bindet im Plattform-Modus (demo/office) das FA-CSS ein — wie die page-navbar.
 * Vorher fehlten dort Farben und die Vollbild-Fenster (wall-gericht, wall-anleitung) lagen durchsichtig über dem Monitor, weil der
 * Wandmonitor keine page-navbar rendert und das FA-Design im Plattform-Modus nur über /_platform/fa-assets/foodalchemist.css kommt.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->kiosk = fn () => view('foodalchemist::layouts.kiosk', ['slot' => new \Illuminate\Support\HtmlString('<main>x</main>')])->render();
});

it('★ Plattform-Modus: das Kiosk-Layout enthält den fa-assets-Link auf das Modul-CSS', function () {
    config(['foodalchemist.shell' => 'plattform']);

    $html = ($this->kiosk)();

    expect($html)->toContain('data-fa-modul-css')
        ->toContain('/_platform/fa-assets/foodalchemist.css?v=')
        ->toContain('data-foodalchemist-kiosk-layout');
});

it('eigenständig: kein Modul-CSS-Link (der Host baut das CSS selbst über @vite)', function () {
    config(['foodalchemist.shell' => 'eigenstaendig']);

    expect(($this->kiosk)())->not->toContain('data-fa-modul-css')->not->toContain('/_platform/fa-assets/');
});

it('die fa-assets-Route liefert das CSS aus', function () {
    $this->get('/_platform/fa-assets/foodalchemist.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
});
