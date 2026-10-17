<?php

use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Support\ScanCache;
use Platform\FoodAlchemist\Support\ToolListe;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;

uses(TestCase::class);

/**
 * Spec 84 E-4 · Scan-Cache für Tool-Metadaten und Livewire-Komponenten — mit Rückfall, nie ein halb leeres MCP.
 */
beforeEach(function () {
    $this->pfad = sys_get_temp_dir() . '/fa-scan-test-' . uniqid() . '.php';
    config(['foodalchemist.scan_cache_pfad' => $this->pfad]);
    ScanCache::vergessen();
    $this->alleNamen = fn () => collect(ToolListe::klassen())->map(fn ($k) => (new $k())->getName())->sort()->values()->all();
    $this->registriert = function (): array {
        $reg = new ToolRegistry();
        ToolListe::registriere($reg);

        return collect($reg->names())->filter(fn ($n) => str_starts_with($n, 'foodalchemist.'))->sort()->values()->all();
    };
});

afterEach(function () {
    @unlink($this->pfad);
    ScanCache::vergessen();
});

it('Spec 84 E-4 · kalt (keine Datei): alle Tools registriert, Cache wird geschrieben', function () {
    expect(($this->registriert)())->toBe(($this->alleNamen)())
        ->and(is_file($this->pfad))->toBeTrue()
        ->and(count((include $this->pfad)['tools']))->toBe(count(ToolListe::klassen()));
});

it('Spec 84 E-4 · Cache veraltet (anderer Schlüssel, leere Liste): trotzdem alle Tools, Datei wird erneuert', function () {
    file_put_contents($this->pfad, '<?php return ' . var_export(['schluessel' => 'alt', 'tools' => [], 'livewire' => []], true) . ';');

    expect(($this->registriert)())->toBe(($this->alleNamen)())
        ->and((include $this->pfad)['schluessel'])->toBe(ScanCache::schluessel());
});

it('Spec 84 E-4 · Lücke im gültigen Cache: fehlende Einträge werden live gebaut', function () {
    ($this->registriert)();                                         // gültigen Cache erzeugen
    $c = include $this->pfad;
    $c['tools'] = array_slice($c['tools'], 5, null, true);          // fünf Tools „vergessen“
    file_put_contents($this->pfad, '<?php return ' . var_export($c, true) . ';');
    ScanCache::vergessen();

    expect(($this->registriert)())->toBe(($this->alleNamen)());
});

it('Spec 84 E-4 · kaputte oder nicht beschreibbare Cache-Datei: kein Absturz, alle Tools', function () {
    file_put_contents($this->pfad, '<?php return [ kaputt');
    expect(($this->registriert)())->toBe(($this->alleNamen)());

    config(['foodalchemist.scan_cache_pfad' => '/gibt/es/nicht/fa-scan.php']);
    ScanCache::vergessen();
    expect(($this->registriert)())->toBe(($this->alleNamen)());
});

it('Spec 84 E-4 · verzögerte Hülle: Name/Metadaten aus dem Cache, Tool erst beim Zugriff; Beschreibung/Schema wie das echte Tool', function () {
    ($this->registriert)();
    ScanCache::vergessen();
    $reg = new ToolRegistry();
    ToolListe::registriere($reg);
    $h = $reg->get('foodalchemist.recipe_feedback.POST') ?? $reg->get('foodalchemist.gps.SEARCH');
    $innen = (new ReflectionProperty(FaRechteToolHuelle::class, 'tool'));

    expect($h)->toBeInstanceOf(FaRechteToolHuelle::class)
        ->and($innen->getValue($h))->toBeNull();                    // noch nicht gebaut
    $echt = new (get_class($h->innen()))();
    expect($h->getMetadata())->toBe($echt->getMetadata())
        ->and($h->getDescription())->toBe($echt->getDescription())
        ->and($h->getSchema())->toEqual($echt->getSchema())         // Schema bleibt live (darf Config lesen)
        ->and($innen->getValue($h))->not->toBeNull();
});

it('Spec 84 E-4 · Livewire: Cache = Live-Scan; veralteter Cache fällt auf den Live-Scan zurück', function () {
    $provider = app()->getProvider(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class);
    $live = (new ReflectionMethod($provider, 'livewireLiveScan'))
        ->invoke($provider, dirname(__DIR__, 2) . '/src/Livewire', 'Platform\\FoodAlchemist\\Livewire', 'foodalchemist');

    expect(ScanCache::livewire(fn () => $live))->toBe($live)
        ->and(count($live))->toBeGreaterThan(100);

    file_put_contents($this->pfad, '<?php return ' . var_export(['schluessel' => 'alt', 'livewire' => ['foodalchemist.x' => 'Nix']], true) . ';');
    ScanCache::vergessen();
    expect(ScanCache::livewire(fn () => $live))->toBe($live);
});
