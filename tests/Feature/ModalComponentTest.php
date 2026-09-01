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

    // Der Punkt steckt im dynamischen Argument, nicht als Alpine-Modifier im Attributnamen.
    // Dadurch verwaltet Alpine den Listener lifecycle-sicher über Livewire-Morphs hinweg.
    expect($html)->toContain('x-on:[modalOpenEvent].window')
        ->and($html)->toContain('x-on:[modalCloseEvent].window')
        ->and($html)->toContain('modal.closed')          // Schließen meldet sich (State-Reset-Vertrag)
        ->and($html)->toContain('keydown.window.escape') // ESC schließt
        ->and($html)->toContain('x-cloak');              // kein Aufblitzen vor Alpine-Boot
});

it('beendet ein serverseitiges modal.close ohne die Close-Methode erneut aufzurufen', function () {
    $html = Blade::render(<<<'BLADE'
        <x-foodalchemist::modal name="demo" title="T" :close-via="'schliessenOderZurueck'">X</x-foodalchemist::modal>
        BLADE);

    expect($html)->toContain("requestClose() {  this.\$wire.schliessenOderZurueck();  }")
        ->and($html)->toContain("x-on:[modalCloseEvent].window=\"if (!\$event.detail?.name || \$event.detail.name === 'demo') close()\"")
        ->and($html)->not->toContain("x-on:[modalCloseEvent].window=\"if (!\$event.detail?.name || \$event.detail.name === 'demo') requestClose()\"");
});

it('lässt Aktionen- und Footer-Slot weg, wenn nicht gesetzt', function () {
    $html = Blade::render('<x-foodalchemist::modal name="demo" title="T">NUR-KÖRPER</x-foodalchemist::modal>');

    expect($html)->toContain('NUR-KÖRPER')
        ->and($html)->not->toContain('data-modal-zone="actions"')
        ->and($html)->not->toContain('data-modal-zone="footer"');
});
