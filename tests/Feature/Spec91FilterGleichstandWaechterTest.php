<?php

use Livewire\Attributes\Url;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 91 · Wächter MCP-Filter-Gleichstand: jeder Filter einer erfassten Listenansicht hat ein MCP-Gegenstück (Tool + Parameter im Schema)
 * oder eine begründete Ausnahme. Ein neuer URL-Filter ohne Eintrag macht den Test rot, ebenso ein Eintrag, dessen Eigenschaft oder
 * Tool-Parameter es nicht (mehr) gibt. Die Liste wächst mit jedem Spec-91-PR um die Ansichten, deren Lücken er schließt.
 */
function spec91_gleichstand(): array
{
    return [
        \Platform\FoodAlchemist\Livewire\Suppliers\Index::class => [
            'filter' => [
                'supplierId' => ['foodalchemist.artikel.LIST', 'supplier_id'],
                'artikelSuche' => ['foodalchemist.artikel.LIST', 'q'],
                'q' => ['foodalchemist.artikel.SEARCH', 'q'],
                'art' => ['foodalchemist.artikel.LIST', 'art'],
                'onlyActive' => ['foodalchemist.artikel.LIST', 'only_active'],
                'perPage' => ['foodalchemist.artikel.LIST', 'per_page'],
                'supplierSuche' => ['foodalchemist.suppliers.SEARCH', 'q'],
                'gruppe' => ['foodalchemist.suppliers.SEARCH', 'gruppe'],
                'includeInactive' => ['foodalchemist.suppliers.SEARCH', 'include_inactive'],
                'einstufen' => ['foodalchemist.suppliers.SEARCH', 'einstufen'],
            ],
            'ausnahmen' => [],
        ],
        \Platform\FoodAlchemist\Livewire\Gps\Browser::class => [
            'filter' => [
                'commodity_group' => ['foodalchemist.gps.LIST', 'commodity_group_code'],
                'subKategorie' => ['foodalchemist.gps.LIST', 'sub_category'],
                'status' => ['foodalchemist.gps.LIST', 'status'],
                'search' => ['foodalchemist.gps.SEARCH', 'q'],
                'perPage' => ['foodalchemist.gps.LIST', 'per_page'],
            ],
            'ausnahmen' => [
                'gpId' => 'Auswahl eines GP (Detail via gps.GET), kein Listenfilter',
                'editOeffnen' => 'Dialog-Zustand',
                'ansicht' => 'Spaltenauswahl — MCP: felder=kurz|voll',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Speiseplan\Index::class => [
            'filter' => ['search' => ['foodalchemist.speiseplaene.LIST', 'search']],
            'ausnahmen' => ['selectedId' => 'Auswahl eines Plans (Detail via speiseplaene.GET), kein Listenfilter'],
        ],
        \Platform\FoodAlchemist\Livewire\Angebote\Index::class => [
            'filter' => ['search' => ['foodalchemist.angebote.SEARCH', 'q'], 'statusFilter' => ['foodalchemist.angebote.SEARCH', 'status']],
            'ausnahmen' => ['selectedId' => 'Auswahl eines Angebots (Detail via angebote.GET), kein Listenfilter'],
        ],
    ];
}

it('Spec 91 · jeder URL-Filter einer erfassten Liste ist zugeordnet oder begründet ausgenommen', function () {
    $befunde = [];
    foreach (spec91_gleichstand() as $klasse => $eintrag) {
        foreach ((new ReflectionClass($klasse))->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->getAttributes(Url::class) !== [] && ! isset($eintrag['filter'][$p->getName()]) && ! isset($eintrag['ausnahmen'][$p->getName()])) {
                $befunde[] = class_basename($klasse) . "::\${$p->getName()} ist URL-Filter ohne MCP-Gegenstück und ohne Ausnahme";
            }
        }
    }

    expect($befunde)->toBe([]);
});

it('Spec 91 · jede Zuordnung zeigt auf eine bestehende Eigenschaft und einen Parameter im Tool-Schema', function () {
    $registry = app(ToolRegistry::class);
    $befunde = [];
    foreach (spec91_gleichstand() as $klasse => $eintrag) {
        foreach (array_merge(array_keys($eintrag['filter']), array_keys($eintrag['ausnahmen'])) as $prop) {
            if (! property_exists($klasse, $prop)) {
                $befunde[] = class_basename($klasse) . "::\${$prop} gibt es nicht (mehr)";
            }
        }
        foreach ($eintrag['filter'] as $prop => [$tool, $param]) {
            $schema = $registry->get($tool)?->getSchema() ?? [];
            if (! array_key_exists($param, $schema['properties'] ?? [])) {
                $befunde[] = class_basename($klasse) . "::\${$prop} → {$tool}.{$param} fehlt im Schema";
            }
        }
    }

    expect($befunde)->toBe([]);
});
