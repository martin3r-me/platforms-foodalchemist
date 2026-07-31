<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 28 / E0: die geteilten Editor-Bausteine `editor-tabs` + `kpi-tiles`.
 *
 * Warum dieser Test existiert: der Rollout des Master-Editors (Basisrezepte) auf GP, LA,
 * Gericht und Concepter hängt an diesen zwei Bausteinen. Bricht einer, brechen alle Editoren
 * gleichzeitig — und zwar im Markup, wo Livewire-Tests sonst nicht hinschauen.
 *
 * Zwei Dinge werden geprüft:
 *  1. Der Baustein erzeugt die data-Marker, die vorher literal im Editor standen. Ein grep
 *     über die Editor-Datei findet sie nach dem Refactor NICHT mehr (sie kommen jetzt aus
 *     dem Baustein) — nur der gerenderte Output beweist, dass nichts verloren ging.
 *  2. Die drei Livewire/Alpine-Fallen sind im Markup verdrahtet: wire:key (Element-Ersatz
 *     bei Datensatz-Wechsel), x-effect (Tab-Reset beim Öffnen) und EIN Alpine-Scope, der
 *     Leiste und Panels umspannt. Ohne die desynct der aktive Tab unter Livewire-Morph.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('editor-tabs rendert Leiste, Marker und die drei Morph-Sicherungen', function () {
    $html = Blade::render(
        '<x-foodalchemist::editor-tabs marker="test" wire-key="t-7" :init="\'zwei\'"
            :tabs="[\'eins\' => \'Eins\', \'zwei\' => \'Zwei\', \'weg\' => null]">
            <div x-show="tab === \'eins\'">A</div>
         </x-foodalchemist::editor-tabs>'
    );

    // Marker: Root + je Tab-Knopf, unter dem Legacy-Namen UND dem generischen
    expect($html)
        ->toContain('data-fa-editor-tabs')
        ->toContain('data-test-tabs')
        ->toContain('data-test-tab="eins"')
        ->toContain('data-test-tab="zwei"');

    // Labels mit null entfallen — sonst rendert ein Editor leere Knöpfe
    expect($html)->not->toContain('data-test-tab="weg"');

    // Falle 1: EIN Alpine-Scope umspannt Leiste + Slot (Panel liegt innerhalb)
    expect(substr_count($html, 'x-data'))->toBe(1);
    expect($html)->toContain('tab === \'eins\'');

    // Falle 2: wire:key erzwingt Element-Ersatz bei Datensatz-Wechsel
    expect($html)->toContain('wire:key="t-7"');

    // Falle 3: Tab-Reset beim Öffnen, mit Guard für Einsatz außerhalb eines Modals
    expect($html)->toContain('x-effect')
        ->toContain("typeof open !== 'undefined'");

    // Start-Tab kommt aus :init, nicht aus dem ersten Schlüssel
    expect($html)->toContain('{ tab: \'zwei\' }');

    // sticky: die Leiste darf beim Scrollen nie weglaufen
    expect($html)->toContain('sticky');
});

it('kpi-tiles bildet Tones auf die Palette ab und hält die Marker', function () {
    $html = Blade::render(
        '<x-foodalchemist::kpi-tiles marker="editor-kpis" :tiles="[
            [\'kpi\' => \'ekkg\', \'label\' => \'EK / kg\', \'value\' => \'4,20 €/kg\', \'tone\' => \'accent\'],
            [\'kpi\' => \'priced\', \'label\' => \'Mit Preis\', \'value\' => \'9/9\', \'tone\' => \'good\'],
            [\'kpi\' => \'yield\', \'label\' => \'Yield\', \'value\' => \'2,400 kg\'],
            [\'label\' => \'Kaputt\', \'value\' => \'x\', \'tone\' => \'quatsch\'],
        ]" />'
    );

    expect($html)->toContain('data-fa-kpis')->toContain('data-editor-kpis');
    expect($html)->toContain('data-kpi="ekkg"')->toContain('data-kpi="priced"');

    // Tone → Klasse; unbekannter Tone fällt auf neutral zurück (nie ungestylt).
    // Gezählt wird die Kachel-Klasse, nicht die Palette — die <style>-Selektoren nennen
    // jeden Tone-Namen ebenfalls (hell + dunkel).
    expect($html)->toContain('kpi-accent')->toContain('kpi-good');
    expect(substr_count($html, 'px-3 py-2 kpi-neutral'))->toBe(2);
    expect($html)->not->toContain('kpi-quatsch');

    // Palette liegt genau einmal im Dokument (@once), nicht pro Kachel
    expect(substr_count($html, '[data-fa-kpis] .kpi-value'))->toBe(1);

    // Hell UND dunkel bedient — der Editor-Grund ist gescopet, kein `dark:`
    expect($html)->toContain('.fa-editor-panel [data-fa-kpis] .kpi-accent');
    expect($html)->not->toContain('dark:');
});

it('der Master-Editor liefert die Marker weiter, die vorher literal in ihm standen', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Brauner Fond: Kalb');

    $html = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $rezept->id)
        ->html();

    // Diese vier standen vor Spec 28 / E0 literal im recipe-modal und kommen jetzt aus den
    // Bausteinen — sie sind der Regressionsschutz für den Refactor.
    foreach (['data-editor-kpis', 'data-kpi=', 'data-rezept-tabs', 'data-rezept-tab='] as $marker) {
        expect($html)->toContain($marker);
    }

    // Und die Editor-Anatomie steht: Leitwert-Kachel + Zutaten-Tab zuerst
    expect($html)->toContain('kpi-accent')
        ->toContain('data-rezept-tab="aufbau"');
});

it('E6: der Gericht-Editor trennt Aufbau von Stammdaten', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Rinderfilet | Jus', ['is_sales_recipe' => true]);

    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)
        ->call('oeffnen', $gericht->id)
        ->html();

    // Neue Lasche existiert und steht direkt hinter «Aufbau»
    expect($html)->toContain('data-vk-tab="stammdaten"');
    expect(strpos($html, 'data-vk-tab="aufbau"'))->toBeLessThan(strpos($html, 'data-vk-tab="stammdaten"'));

    // Stammdaten + Klassifikation liegen im Stammdaten-Panel, NICHT mehr im Aufbau-Panel.
    // Geprüft über die Reihenfolge der Panel-Grenzen: der Marker der Klassifikation muss
    // hinter dem Beginn des Stammdaten-Panels liegen.
    $posStammPanel = strpos($html, "tab === 'stammdaten'");
    $posAufbauPanel = strpos($html, "tab === 'aufbau'", $posStammPanel);
    $posKlass = strpos($html, 'data-vk-klassifikation');
    expect($posStammPanel)->not->toBeFalse();
    expect($posAufbauPanel)->not->toBeFalse();
    expect($posKlass)->toBeGreaterThan($posStammPanel)->toBeLessThan($posAufbauPanel);
});

it('E6: der Concepter legt Feldleiste, Coverage und Kohäsion in eigene Tabs', function () {
    $konzept = $this->makeConcept($this->rootTeam, 'Sommerfest 2027');

    $c = Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)
        ->call('oeffnen', 'concepts', $konzept->id);

    // Neue Lasche + umbenannte Sammel-Laschen
    expect($c->html())->toContain('data-konzept-tab="stammdaten"')
        ->toContain('Konzept &amp; Planung')
        ->toContain('Sensorik &amp; Pairing');

    // Aufbau: keine Feldleiste mehr (die klebte vorher dauerhaft über den Tabs)
    expect($c->html())->not->toContain('data-konzept-schreibstil');

    // setTab muss den neuen Wert annehmen — sonst tut die Lasche stillschweigend nichts
    $c->call('setTab', 'stammdaten');
    expect($c->get('tab'))->toBe('stammdaten');
    expect($c->html())->toContain('data-konzept-schreibstil');

    // Und ein unbekannter Wert darf den Tab NICHT verstellen (Whitelist bleibt scharf)
    $c->call('setTab', 'quatsch');
    expect($c->get('tab'))->toBe('stammdaten');
});

it('der GP-Editor trägt den KPI-Kopf des GP-Cockpits und eine sticky Leiste', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet: frisch, ganz');

    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\GpModal::class)
        ->call('oeffnen', $gp->id)
        ->html();

    // Kennzahlen aus dem GP-Cockpit, jetzt fix im Editor-Kopf
    expect($html)->toContain('data-gp-editor-kpis')
        ->toContain('data-kpi="lead-preis"')
        ->toContain('data-kpi="las"')
        ->toContain('data-kpi="allergen"')
        ->toContain('kpi-accent');

    // Status-Regler liegt jetzt in der Aktionsleiste im Kopf, nicht mehr im scrollenden Body.
    // Geprüft über die Zonen-Reihenfolge — NICHT über einen Text-Slice bis
    // `data-modal-zone="body"`: dieser String kommt zuerst als CSS-Selektor im
    // Editor-Dark-Block vor, ein Slice darauf schneidet mitten im <style> ab.
    $posActions = strpos($html, 'data-modal-zone="actions"');
    $posKpi = strpos($html, 'data-modal-zone="kpi-header"');
    $posStatus = strpos($html, 'data-gp-status-kopf');
    expect($posActions)->not->toBeFalse();
    expect($posKpi)->not->toBeFalse();
    expect($posStatus)->toBeGreaterThan($posActions)->toBeLessThan($posKpi);

    // Tabs aus dem Baustein (vorher eigene Variante ohne sticky/wire:key)
    expect($html)->toContain('data-gp-tab="allgemein"')
        ->toContain('data-gp-tab="kalkulation"')
        ->toContain('wire:key="gp-tabs-' . $gp->id . '"')
        ->toContain('sticky');

    // Voll-Editor-Hülle im Bestand
    expect($html)->toContain('fa-editor-panel');
});

it('die GP-Neuanlage bleibt hell, schmal und ohne Ein-Laschen-Navigation', function () {
    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\GpModal::class)
        ->call('oeffnen', null)
        ->html();

    // Kein Voll-Editor, kein KPI-Kopf — die Neuanlage hat nichts zu zeigen
    expect($html)->not->toContain('fa-editor-panel');
    expect($html)->not->toContain('data-gp-editor-kpis');

    // Eine einzige Lasche ist keine Navigation → Baustein zeichnet keine Leiste
    expect($html)->not->toContain('data-gp-tab="allgemein"');
    expect($html)->not->toContain('data-gp-tab="kalkulation"');
});

it('der LA-Editor trägt Voll-Editor-Anatomie statt acht linearer Sektionen', function () {
    $supplier = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Delta Fleisch',
    ]);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Rinderfilet Mittelstück', 'qty' => 2.5, 'unit_code' => 'kg',
    ]);

    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\ItemModal::class)
        ->call('oeffnen', $la->id)
        ->html();

    // Vier Tabs statt acht Sektionen am Stück (Spec 28 / E3.2)
    foreach (['stammdaten', 'deklaration', 'gp', 'preise'] as $tab) {
        expect($html)->toContain('data-la-tab="' . $tab . '"');
    }

    // KPI-Kopf mit Leitwert EK; GP fehlt → Warn-Kachel statt stiller Lücke
    expect($html)->toContain('data-la-editor-kpis')
        ->toContain('data-kpi="ek"')
        ->toContain('kpi-accent')
        ->toContain('nicht gemappt')
        ->toContain('kpi-warn');

    // Voll-Editor-Hülle: dunkler Grund + Name als Akzent-Chip
    expect($html)->toContain('fa-editor-panel')
        ->toContain('data-modal-title-name');
    expect($html)->toContain('Rinderfilet Mittelstück');

    // Keine Emoji mehr in diesem Editor (E1-11). Die Tri-State-Zeichen −/≈/✓ sind
    // Zustandsträger und bleiben — hier geht es um die dekorativen.
    foreach (['🧺', '✨', '✎', '✕'] as $emoji) {
        expect($html)->not->toContain($emoji);
    }
});
