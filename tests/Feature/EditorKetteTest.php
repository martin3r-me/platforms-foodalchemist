<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RecipesBrowser;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel as GpDetailPanel;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Editor-Kette (Dominique 2026-10-13): Rezept → Grundprodukt → Lieferantenartikel.
 * Aus einem Rezept ins Grundprodukt gesprungen, Artikel angeklickt — nichts passierte:
 * das Artikel-Fenster war nur in Lieferanten-Liste und GP-Browser eingebunden, das
 * GP-Fenster fehlte auf Planung/Angebote/Concepter/Speisekarte/Review-Liste. Ein
 * Livewire-Event ohne Empfänger verpufft still — kein Fehler, kein Log.
 * Regel: Jede Seite mit Rezept- oder Gericht-Editor bindet alle Glieder der Kette ein.
 */
function editorKetteSeiten(): array
{
    $basis = dirname(__DIR__, 2).'/resources/views/livewire';
    $seiten = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis)) as $datei) {
        if (! str_ends_with($datei->getFilename(), '.blade.php')) {
            continue;
        }
        $inhalt = file_get_contents($datei->getPathname());
        if (preg_match('/<livewire:foodalchemist\.(recipes\.recipe-modal|verkauf\.vk-modal)\b/', $inhalt)) {
            $seiten[substr($datei->getPathname(), strlen($basis) + 1)] = $inhalt;
        }
    }

    return $seiten;
}

it('jede Seite mit Rezept- oder Gericht-Editor bindet GP- und Artikel-Fenster ein', function () {
    $seiten = editorKetteSeiten();
    expect(count($seiten))->toBeGreaterThanOrEqual(9);   // Stand 13.10.: 9 Seiten

    $fehlt = [];
    foreach ($seiten as $pfad => $inhalt) {
        foreach (['gps.gp-modal', 'suppliers.item-modal', 'recipes.recipe-modal'] as $glied) {
            if (! str_contains($inhalt, '<livewire:foodalchemist.'.$glied)) {
                $fehlt[] = "$pfad ohne $glied";
            }
        }
    }
    expect($fehlt)->toBe([]);
});

it('Rezept-Browser und Review-Liste rendern das Artikel-Fenster wirklich', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    foreach ([RecipesBrowser::class, ReviewQueue::class] as $seite) {
        $html = Livewire::test($seite)->html();
        expect($html)->toContain('data-modal="item-modal"')
            ->and($html)->toContain('data-modal="gp-modal"');
    }
});

it('GP-Artikelliste zeigt die Artikelnummer (Feld article_number, nicht das nie existierende order_number)', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $gp = $this->makeGp($this->rootTeam, 'Romana Salat');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Kluth']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'article_number' => '1608',
        'designation' => 'Romana Latuga geschnitten 35mm', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    $gp->update(['n_las_total' => 1, 'lead_la_supplier_item_id' => $la->id]);

    $html = Livewire::test(GpDetailPanel::class, ['gpId' => $gp->id, 'embedded' => true, 'section' => 'las'])->html();
    expect($html)->toContain('data-la-oeffnen')
        ->and($html)->toContain('Art.-Nr. 1608');
});
