<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-08: Baustein modal (P-2) — Sektions-Modal mit modal.open-Event-Vertrag.
 * Echtes Öffnen/Schließen (Alpine) prüft der Sandbox-Browser-Check auf /foodalchemist/test.
 */
it('rendert Titel, fixe Kopf-Aktionen, Sektionen und Footer-Slot', function () {
    $html = Blade::render(<<<'BLADE'
        <x-foodalchemist::modal name="gp-edit" title="GP bearbeiten">
            <x-slot:actions>AKTIONEN-OBEN</x-slot:actions>
            <x-foodalchemist::modal-section title="Stammdaten">SEKTION-INHALT</x-foodalchemist::modal-section>
            <x-slot:footer>FOOTER-AKTIONEN</x-slot:footer>
        </x-foodalchemist::modal>
        BLADE);

    expect($html)->toContain('GP bearbeiten')
        ->and($html)->toContain('AKTIONEN-OBEN')
        ->and($html)->toContain('data-modal-zone="actions"')
        ->and($html)->toContain('Stammdaten')
        ->and($html)->toContain('SEKTION-INHALT')
        ->and($html)->toContain('data-modal-zone="section"')
        ->and($html)->toContain('FOOTER-AKTIONEN')
        ->and($html)->toContain('data-modal-zone="footer"')
        ->and($html)->toContain('data-modal="gp-edit"');
});

it('verdrahtet den Event-Vertrag modal.open / modal.close / modal.closed', function () {
    $html = Blade::render('<x-foodalchemist::modal name="demo" title="T">X</x-foodalchemist::modal>');

    expect($html)->toContain('modal-open.dot.window')   // .dot: Alpine-Syntax für Event-Namen mit Punkt
        ->and($html)->toContain('modal-close.dot.window')
        ->and($html)->toContain('modal.closed')          // Schließen meldet sich (State-Reset-Vertrag)
        ->and($html)->toContain('keydown.window.escape') // ESC schließt
        ->and($html)->toContain('x-cloak');              // kein Aufblitzen vor Alpine-Boot
});

it('lässt Aktionen- und Footer-Slot weg, wenn nicht gesetzt', function () {
    $html = Blade::render('<x-foodalchemist::modal name="demo" title="T">NUR-KÖRPER</x-foodalchemist::modal>');

    expect($html)->toContain('NUR-KÖRPER')
        ->and($html)->not->toContain('data-modal-zone="actions"')
        ->and($html)->not->toContain('data-modal-zone="footer"');
});
