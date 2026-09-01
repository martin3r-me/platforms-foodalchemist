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

    // Native Listener erhalten den Punkt im Eventnamen; Alpine würde ihn in einer statischen
    // x-on-Direktive als Modifier interpretieren.
    expect($html)->toContain("addEventListener('modal.open'")
        ->and($html)->toContain("addEventListener('modal.close'")
        ->and($html)->toContain('modal.closed')          // Schließen meldet sich (State-Reset-Vertrag)
        ->and($html)->toContain('keydown.window.escape') // ESC schließt
        ->and($html)->toContain('x-cloak');              // kein Aufblitzen vor Alpine-Boot
});

it('holt jedes neu geöffnete Modal vor bereits offene Editoren', function () {
    $html = Blade::render('<x-foodalchemist::modal name="demo" title="T">X</x-foodalchemist::modal>');

    expect($html)->toContain('bringToFront($el)')
        ->and($html)->toContain('window.__foodAlchemistModalZ')
        ->and($html)->toContain('el.style.zIndex');
});

it('führt beim Schließen den optionalen Livewire-State-Reset aus', function () {
    $html = Blade::render(<<<'BLADE'
        <x-foodalchemist::modal name="demo" title="T" :close-via="'schliessenOderZurueck'">X</x-foodalchemist::modal>
        BLADE);

    expect($html)->toContain("closeWithState() {  this.\$wire.schliessenOderZurueck();  this.close(); }")
        ->and($html)->toContain("e.detail.name === 'demo') closeWithState()");
});

it('lässt Aktionen- und Footer-Slot weg, wenn nicht gesetzt', function () {
    $html = Blade::render('<x-foodalchemist::modal name="demo" title="T">NUR-KÖRPER</x-foodalchemist::modal>');

    expect($html)->toContain('NUR-KÖRPER')
        ->and($html)->not->toContain('data-modal-zone="actions"')
        ->and($html)->not->toContain('data-modal-zone="footer"');
});
