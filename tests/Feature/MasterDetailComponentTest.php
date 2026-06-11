<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-07: Baustein master-detail (P-1) — kompiliert und rendert alle 3 Zonen.
 * Der Sandbox-Browser-Check (Demo auf /foodalchemist/test) bleibt zusätzlich Pflicht.
 */
it('rendert alle drei Zonen mit Slot-Inhalten', function () {
    $html = Blade::render(<<<'BLADE'
        <x-foodalchemist::master-detail>
            <x-slot:tree>BAUM-ZONE</x-slot:tree>
            TABELLEN-ZONE
            <x-slot:panel>PANEL-ZONE</x-slot:panel>
        </x-foodalchemist::master-detail>
        BLADE);

    expect($html)->toContain('BAUM-ZONE')
        ->and($html)->toContain('TABELLEN-ZONE')
        ->and($html)->toContain('PANEL-ZONE')
        ->and($html)->toContain('data-zone="tree"')
        ->and($html)->toContain('data-zone="table"')
        ->and($html)->toContain('data-zone="panel"')
        ->and($html)->toContain('panelOpen'); // Alpine-Kollaps-State verdrahtet
});

it('kommt ohne tree- und panel-Slot aus (nur Tabelle)', function () {
    $html = Blade::render('<x-foodalchemist::master-detail>NUR-TABELLE</x-foodalchemist::master-detail>');

    expect($html)->toContain('NUR-TABELLE')
        ->and($html)->toContain('data-zone="table"')
        ->and($html)->not->toContain('data-zone="tree"')
        ->and($html)->not->toContain('data-zone="panel"');
});
