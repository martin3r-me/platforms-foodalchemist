<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/*
 * Observatorium/Trend-Research-CSS als Zusatz-Datei (vorher <style>-Blöcke in den Views, UI-Regeln).
 * Die Datei wird über _platform/fa-assets ausgeliefert — ohne Host-Build, damit io (eigenständig, baut bei
 * Modul-Deploys kein CSS) und demo (Plattform) dieselbe Optik behalten.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

$basis = dirname(__DIR__, 2);

it('liefert die dist-Kopie identisch zur Quelle aus (npm run build:css nicht vergessen)', function () use ($basis) {
    $quelle = $basis.'/resources/css/foodalchemist-observatorium.css';
    $dist = $basis.'/resources/dist/foodalchemist-observatorium.css';
    expect(is_file($dist))->toBeTrue()
        ->and(file_get_contents($dist))->toBe(file_get_contents($quelle));
});

it('kommt ohne !important aus und trägt die Regeln der früheren style-Blöcke', function () use ($basis) {
    $css = file_get_contents($basis.'/resources/css/foodalchemist-observatorium.css');
    expect($css)->not->toContain('!important')
        ->and($css)->toContain('.fa-obs{')->and($css)->toContain('.fa-research-nav{')->and($css)->toContain('.fa-wett{')
        ->and($css)->toContain('.fa-obs.fa-obs-is-handy{padding:16px}')->and($css)->toContain('.fa-obs .fa-obs-link{color:var(--obs-accent)}');
});

it('bindet die Datei auf der Seite einmal per Link mit Cache-Schlüssel ein', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Observatorium CSS TEST'));
    $html = Livewire::test(Index::class)->html();
    expect(substr_count($html, 'data-fa-observatorium-css'))->toBe(1)
        ->and($html)->toContain('/_platform/fa-assets/foodalchemist-observatorium.css?v=')
        ->and($html)->not->toContain('<style')
        ->and(FaShell::assetUrl('foodalchemist-observatorium.css'))->not->toEndWith('?v=0');
});

it('lässt Badges mit umbruch ohne feste Höhe und nowrap umbrechen', function () {
    $normal = Blade::render('<x-fa::badge>Kimchi</x-fa::badge>');
    $umbruch = Blade::render('<x-fa::badge umbruch class="max-w-full">fermentiertes Gemüse</x-fa::badge>');
    expect($normal)->toContain('h-[var(--fa-badge-h,22px)]')->and($normal)->toContain('whitespace-nowrap')
        ->and($umbruch)->not->toContain('h-[var(--fa-badge-h')->and($umbruch)->not->toContain('whitespace-nowrap')
        ->and($umbruch)->not->toContain('!')->and($umbruch)->toContain('max-w-full');
});
