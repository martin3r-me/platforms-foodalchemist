<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * 22·H1 / V-012 — jedes Modul-Blade kompiliert, und zwar vollständig.
 *
 * Drei Fundorte, drei Mechanismen, ~25 Min verbrannt — und jedes Mal war das Fehlerbild
 * eine PHP-Syntaxmeldung an der falschen Stelle:
 *
 *  (a) Blades Raw-Block-Regex spannt von der ERSTEN `@php`-Kurzform bis zum ersten
 *      `@endphp`. Steht ein Block-`@php … @endphp` NACH einer Kurzform `@php(...)`, wird
 *      alles dazwischen unkompiliert wieder eingesetzt: `@php(` landet als `<?php(` in der
 *      Compile-Datei, `@if`/`{{ }}`/`<x-…>` dazwischen bleiben roher Text. (Dieselbe Falle
 *      schnappt, wenn `@endphp` in einem PHP-Kommentar INNERHALB des Blocks vorkommt.)
 *  (b) Blades Direktiven-Regex verlangt `\B` vor dem `@`. Klebt `@if` an ein Wortzeichen
 *      (`…übernommen@if($x)`), bleibt es Text — das zugehörige `@endif` wird kompiliert und
 *      steht als verwaistes `endif` am Dateiende.
 *  (c) Unbalancierte Direktiven-Paare fallen erst im Render auf.
 *
 * Wichtig, weil die naheliegenden Prüfungen NICHT greifen: `php -l` sieht auf der
 * Blade-Quelle nur HTML-Text, und `php artisan view:cache` meldet „cached successfully"
 * auch für ein kaputtes Blade (es schreibt PHP heraus, ohne es zu parsen). Bis zu diesem
 * Test war nur abgedeckt, was zufällig in einem Feature-Test gerendert wurde.
 *
 * Die Prüfung ist absichtlich statisch (kein Render): sie braucht keine Daten, deckt damit
 * ALLE Views ab und läuft in Sekunden.
 */

/** Alle Blades des Moduls. */
function fa_blades(): array
{
    $wurzel = \dirname(__DIR__, 2) . '/resources/views';
    $dateien = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
            $dateien[] = $f->getPathname();
        }
    }
    sort($dateien);

    return $dateien;
}

/**
 * Die vier Invarianten auf einer Blade-QUELLE (nicht Datei) — damit sie unten sowohl gegen
 * den Bestand als auch gegen die drei bekannten Fehlerbilder laufen können. Ein Riegel, von
 * dem niemand zeigt, dass er greift, ist keiner.
 *
 * @return array{php_kurzform: ?string, direktive: ?string, komponente: ?string, parse: ?string}
 */
function fa_blade_befunde(string $quelle): array
{
    // `@verbatim`-Blöcke sind der EINE Ort, an dem rohe Direktiven Absicht sind (Alpine-/JS-
    // Templates) — vorher entfernen, sonst erzeugen sie Fehlbefunde.
    $compiled = Blade::compileString((string) preg_replace('/@verbatim.*?@endverbatim/s', '', $quelle));

    // Bewusst nur echte Blade-Direktiven: `@click`/`@media`/E-Mail-Adressen sind kein Befund.
    // `@{{ … }}` (escaped echo) ist ebenfalls keins — es kompiliert zu `{{ … }}` ohne `@`.
    $direktiven = 'if|elseif|else|endif|unless|endunless|isset|endisset|empty|endempty|'
        . 'foreach|endforeach|forelse|endforelse|for|endfor|while|endwhile|'
        . 'switch|case|default|break|continue|endswitch|'
        . 'php|endphp|include|includeIf|includeWhen|includeUnless|each|'
        . 'extends|section|endsection|yield|parent|show|append|overwrite|stop|'
        . 'push|endpush|prepend|endprepend|stack|once|endonce|'
        . 'auth|endauth|guest|endguest|can|endcan|cannot|endcannot|canany|endcanany|'
        . 'error|enderror|csrf|method|json|dd|dump|props|aware|'
        . 'checked|selected|disabled|readonly|required|class|style|'
        . 'livewire|persist|endpersist|teleport|endteleport|entangle|script|endscript|vite|svg';

    $befunde = ['php_kurzform' => null, 'direktive' => null, 'komponente' => null, 'parse' => null];

    if (str_contains($compiled, '<?php(')) {
        $befunde['php_kurzform'] = '`@php(` blieb unkompiliert (Raw-Block-Falle: ein Block-`@php` darf nur '
            . 'OBERHALB aller Kurzformen stehen, und `@endphp` nicht im Kommentar vorkommen)';
    }

    if (preg_match('/@(' . $direktiven . ')\b/', $compiled, $m)) {
        $befunde['direktive'] = "`@{$m[1]}` blieb als Text stehen (klebt die Direktive an ein Wortzeichen? "
            . 'Blade verlangt ein Nicht-Wortzeichen davor)';
    }

    if (preg_match('/<\/?x-[a-zA-Z0-9:._-]/', $compiled, $m)) {
        $befunde['komponente'] = "`{$m[0]}…` blieb als Text stehen (liegt der Tag in einem unkompilierten "
            . 'Raw-Block?)';
    }

    // Der Schritt, den `view:cache` NICHT macht: das Kompilat parsen. In-Prozess über
    // `TOKEN_PARSE` (wirft bei ungültigem Code) statt 111 × `php -l` als Subprozess.
    try {
        token_get_all($compiled, TOKEN_PARSE);
    } catch (\ParseError $e) {
        $befunde['parse'] = 'Kompilat ist kein gültiges PHP: ' . $e->getMessage();
    }

    return $befunde;
}

/** @return array<int, string> „datei → befund"-Zeilen für eine Invariante über alle Blades */
function fa_blade_bestand(string $invariante): array
{
    $kaputt = [];

    foreach (fa_blades() as $datei) {
        $befund = fa_blade_befunde((string) file_get_contents($datei))[$invariante];
        if ($befund !== null) {
            $kaputt[] = basename($datei) . ' → ' . $befund;
        }
    }

    return $kaputt;
}

it('findet die Modul-Blades überhaupt', function () {
    expect(count(fa_blades()))->toBeGreaterThan(100);
});

it('kompiliert jedes Blade, ohne eine Kurzform-PHP-Direktive stehen zu lassen', function () {
    $kaputt = fa_blade_bestand('php_kurzform');
    expect($kaputt)->toBe([], "Unkompilierte `@php(`-Kurzform:\n- " . implode("\n- ", $kaputt));
});

it('kompiliert jedes Blade, ohne eine Direktive als rohen Text stehen zu lassen', function () {
    $kaputt = fa_blade_bestand('direktive');
    expect($kaputt)->toBe([], "Rohe Blade-Direktive im Kompilat:\n- " . implode("\n- ", $kaputt));
});

it('kompiliert jedes Blade, ohne einen rohen Komponenten-Tag stehen zu lassen', function () {
    $kaputt = fa_blade_bestand('komponente');
    expect($kaputt)->toBe([], "Roher Komponenten-Tag im Kompilat:\n- " . implode("\n- ", $kaputt));
});

it('erzeugt aus jedem Blade syntaktisch gültiges PHP', function () {
    $kaputt = fa_blade_bestand('parse');
    expect($kaputt)->toBe([], "Kompilat ist kein gültiges PHP:\n- " . implode("\n- ", $kaputt));
});

/**
 * Der Gegenbeweis: dieselben Invarianten gegen die drei Fehlerbilder, die diese Suite
 * gekostet haben. Fällt einer dieser Fälle künftig durch, ist der Riegel stumpf geworden —
 * das ist wichtiger zu wissen als jede grüne Bestands-Zeile.
 */
it('schlägt bei den bekannten Fehlerbildern tatsächlich an', function () {
    // (a) Block-`@php` NACH einer Kurzform: Blades Raw-Block frisst alles dazwischen.
    $a = fa_blade_befunde(<<<'BLADE'
        @php($maps = ['a' => 1])
        <x-ui-panel>
            @if($maps)<span>{{ $maps['a'] }}</span>@endif
        </x-ui-panel>
        @php
            $spaeter = 2;
        @endphp
        BLADE);
    expect($a['php_kurzform'])->not->toBeNull('Raw-Block-Falle (a) nicht erkannt');
    expect($a['komponente'])->not->toBeNull('roher Komponenten-Tag aus (a) nicht erkannt');

    // (b) Direktive klebt an einem Wortzeichen → bleibt Text, `@endif` wird kompiliert.
    $b = fa_blade_befunde('<p>noch nicht übernommen@if($x !== null) ({{ $x }})@endif</p>');
    expect($b['direktive'])->not->toBeNull('klebende Direktive (b) nicht erkannt');
    expect($b['parse'])->not->toBeNull('verwaistes `endif` aus (b) nicht erkannt');

    // (c) Unbalanciertes Paar: `@if` ohne `@endif`.
    $c = fa_blade_befunde('@if($x) <span>a</span>');
    expect($c['parse'])->not->toBeNull('unbalanciertes Direktiven-Paar (c) nicht erkannt');

    // Und die Gegenprobe: ein sauberes Blade erzeugt KEINEN Befund (sonst wäre der Riegel
    // nur laut, nicht scharf) — inklusive der Fälle, die absichtlich `@` enthalten.
    $ok = fa_blade_befunde(<<<'BLADE'
        @php($maps = \Platform\FoodAlchemist\Support\Ui::maps())
        <div @click="offen = !offen" class="{{ $maps['card'] }}">
            @if($maps) <x-ui-button>ok</x-ui-button> @endif
            @verbatim<template x-if="n">@{{ n }}<x-nix /></template>@endverbatim
            <span>mail@example.test</span>
        </div>
        BLADE);
    expect($ok)->toBe(['php_kurzform' => null, 'direktive' => null, 'komponente' => null, 'parse' => null]);
});
