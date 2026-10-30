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
                'search' => ['foodalchemist.gps.LIST', 'q'],
                'perPage' => ['foodalchemist.gps.LIST', 'per_page'],
            ],
            'ausnahmen' => [
                'gpId' => 'Auswahl eines GP (Detail via gps.GET), kein Listenfilter',
                'editOeffnen' => 'Dialog-Zustand',
                'ansicht' => 'Spaltenauswahl — MCP: felder=kurz|voll',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Recipes\Browser::class => [
            'filter' => [
                'search' => ['foodalchemist.recipes.LIST', 'q'],
                'hauptgruppe' => ['foodalchemist.recipes.LIST', 'hauptgruppe'],
                'kategorie' => ['foodalchemist.recipes.LIST', 'kategorie'],
                'ohneKategorie' => ['foodalchemist.recipes.LIST', 'ohne_kategorie'],
                'status' => ['foodalchemist.recipes.LIST', 'status'],
                'geschmack' => ['foodalchemist.recipes.LIST', 'geschmack'],
                'fertigung' => ['foodalchemist.recipes.LIST', 'fertigung'],
                'nurTemplates' => ['foodalchemist.recipes.LIST', 'nur_templates'],
                'perPage' => ['foodalchemist.recipes.LIST', 'per_page'],
            ],
            'ausnahmen' => [
                'recipeId' => 'Auswahl eines Rezepts (Detail via recipes.GET), kein Listenfilter',
                'ansicht' => 'Spaltenauswahl — MCP liefert alle Spalten',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Verkauf\Browser::class => [
            'filter' => [
                'search' => ['foodalchemist.verkaufsrezepte.LIST', 'q'],
                'hauptgruppe' => ['foodalchemist.verkaufsrezepte.LIST', 'hauptgruppe'],
                'klasse' => ['foodalchemist.verkaufsrezepte.LIST', 'klasse'],
                'status' => ['foodalchemist.verkaufsrezepte.LIST', 'status'],
                'geschmack' => ['foodalchemist.verkaufsrezepte.LIST', 'geschmack'],
                'perPage' => ['foodalchemist.verkaufsrezepte.LIST', 'per_page'],
            ],
            'ausnahmen' => [
                'recipeId' => 'Auswahl eines Gerichts (Detail via verkaufsrezepte.GET), kein Listenfilter',
                'ansicht' => 'Spaltenauswahl — MCP liefert alle Spalten',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Orders\Index::class => [
            'filter' => [
                'statusFilter' => ['foodalchemist.orders.GET', 'status'],
                'supplierFilter' => ['foodalchemist.orders.GET', 'supplier_id'],
                'productionFilter' => ['foodalchemist.orders.GET', 'production_order_id'],
                'suche' => ['foodalchemist.orders.GET', 'q'],
                'datumsbasis' => ['foodalchemist.orders.GET', 'datumsbasis'],
                'von' => ['foodalchemist.orders.GET', 'von'],
                'bis' => ['foodalchemist.orders.GET', 'bis'],
                'zeitraum' => ['foodalchemist.orders.GET', 'zeitraum'],
                'nurMitPositionen' => ['foodalchemist.orders.GET', 'nur_mit_positionen'],
                'nurMitKlaerung' => ['foodalchemist.orders.GET', 'nur_mit_klaerung'],
            ],
            'ausnahmen' => [
                'openId' => 'Deep-Link auf eine Bestellung (Detail via orders.GET order_id), kein Listenfilter',
                'sicht' => 'Reiter Bestellungen/Bedarfe — Bedarfe über die Produktions-Tools',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Produktion\Browser::class => [
            'filter' => [
                'statusFilter' => ['foodalchemist.production_orders.GET', 'status'],
                'von' => ['foodalchemist.production_orders.GET', 'von'],
                'bis' => ['foodalchemist.production_orders.GET', 'bis'],
                'suche' => ['foodalchemist.production_orders.GET', 'q'],
                'zeitraum' => ['foodalchemist.production_orders.GET', 'zeitraum'],
                'perPage' => ['foodalchemist.production_orders.GET', 'per_page'],
            ],
            'ausnahmen' => [
                'orderId' => 'Auswahl eines Auftrags (Detail via production_orders.GET order_id), kein Listenfilter',
                'ansicht' => 'Spaltenauswahl — MCP liefert alle Spalten',
            ],
        ],
        // Grenze wie Speiseplan/Angebote: Leistenfilter, Grund und Quelle sind dort keine #[Url]-Eigenschaften, nur der Lagerort.
        \Platform\FoodAlchemist\Livewire\Lager\Index::class => [
            'filter' => ['lagerortId' => ['foodalchemist.inventory.GET', 'location_id']],
            'ausnahmen' => [
                'reiter' => 'Reiter-Auswahl (Bestand, Bewegungen, Inventur …) — je Reiter ein Tool bzw. bewegungen=true',
                'inventurId' => 'Auswahl einer Inventur (Detail via inventory_counts.GET count_id), kein Listenfilter',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Concepter\Browser::class => [
            'filter' => [
                'tab' => ['foodalchemist.concepts.LIST', 'kind'],
                'search' => ['foodalchemist.concepts.LIST', 'q'],
                'klasse' => ['foodalchemist.concepts.LIST', 'klasse'],
                'categoryFilter' => ['foodalchemist.concepts.LIST', 'kategorie'],
                'showVorlagen' => ['foodalchemist.concepts.LIST', 'vorlagen'],
                'statusFilter' => ['foodalchemist.concepts.LIST', 'status'],
                'servierformFilter' => ['foodalchemist.concepts.LIST', 'servierform'],
                'eventtypFilter' => ['foodalchemist.concepts.LIST', 'eventtyp'],
                'momentFilter' => ['foodalchemist.concepts.LIST', 'einsatzmoment'],
                'saisonFilter' => ['foodalchemist.concepts.LIST', 'saison'],
            ],
            'ausnahmen' => [
                'rolleFilter' => 'ohne Wirkung seit der Kaskade (Pakete-Reiter hat keine Rolle mehr), nur noch zurückgesetzt',
                'selectedId' => 'Auswahl eines Konzepts (Detail via concepts.GET), kein Listenfilter',
                'editId' => 'Editor-Zustand',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Concepts\Index::class => [
            'filter' => [
                'search' => ['foodalchemist.concepts.LIST', 'q'],
                'showVorlagen' => ['foodalchemist.concepts.LIST', 'vorlagen'],
                'categoryFilter' => ['foodalchemist.concepts.LIST', 'kategorie'],
                'phaseFilter' => ['foodalchemist.concepts.LIST', 'phase'],
            ],
            'ausnahmen' => ['selectedId' => 'Auswahl eines Konzepts (Detail via concepts.GET), kein Listenfilter'],
        ],
        \Platform\FoodAlchemist\Livewire\Formate\Browser::class => [
            'filter' => [
                'search' => ['foodalchemist.formats.LIST', 'q'],
                'statusFilter' => ['foodalchemist.formats.LIST', 'status'],
                'originFilter' => ['foodalchemist.formats.LIST', 'origin'],
                'servierformFilter' => ['foodalchemist.formats.LIST', 'servierform'],
                'eventtypFilter' => ['foodalchemist.formats.LIST', 'eventtyp'],
                'momentFilter' => ['foodalchemist.formats.LIST', 'einsatzmoment'],
                'saisonFilter' => ['foodalchemist.formats.LIST', 'saison'],
            ],
            'ausnahmen' => ['selectedId' => 'Auswahl eines Formats (Detail via formats.GET), kein Listenfilter'],
        ],
        \Platform\FoodAlchemist\Livewire\Foodbooks\Index::class => [
            'filter' => ['search' => ['foodalchemist.foodbooks.LIST', 'q']],
            'ausnahmen' => [
                'selectedId' => 'Auswahl eines Foodbooks (Detail via foodbooks.GET), kein Listenfilter',
                'selectedKapitelId' => 'Auswahl eines Kapitels im Detail, kein Listenfilter',
            ],
        ],
        \Platform\FoodAlchemist\Livewire\Speisekarte\Index::class => [
            'filter' => ['search' => ['foodalchemist.speisekarten.LIST', 'q']],
            'ausnahmen' => ['karteId' => 'Auswahl einer Karte (Detail via speisekarten.GET), kein Listenfilter'],
        ],
        \Platform\FoodAlchemist\Livewire\Pakete\Index::class => [
            'filter' => ['search' => ['foodalchemist.pakete.LIST', 'q'], 'rolleFilter' => ['foodalchemist.pakete.LIST', 'role']],
            'ausnahmen' => ['selectedId' => 'Auswahl eines Pakets (Detail via pakete.GET), kein Listenfilter'],
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
