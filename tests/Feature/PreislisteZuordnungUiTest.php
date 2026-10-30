<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Preislisten\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistImportZuordnung;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** 1c: Preislisten-Lauf im Status „Zuordnung nötig“ in der Oberfläche bearbeiten (UI-/MCP-Gleichstand, Kurator 29.10.2026). */
beforeEach(function () {
    Storage::fake('local');
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->actingAs($this->makeUser($this->team, 'Chef', 'owner'));
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001']);
    $this->eigene = "Artikel Code;Warenbezeichnung;Preis EUR\n1001;Mehl;12,50\n";
    $this->wartend = function () {
        $pfad = tempnam(sys_get_temp_dir(), 'pz') . '.csv';
        file_put_contents($pfad, $this->eigene);

        return app(PreislistenImportService::class)->stage($this->team, $pfad, [], $this->hanos->id);
    };
});

it('der wartende Lauf steht mit Hinweis in der Läufe-Liste; Anklicken zeigt das Zuordnungs-Panel mit Kopf und Vorschau', function () {
    $lauf = ($this->wartend)();
    Livewire::test(Index::class)
        ->assertSeeHtml('data-preisliste-lauf-zuordnung')
        ->call('laufWaehlen', $lauf->id)
        ->assertSeeHtml('data-preisliste-zuordnung-noetig')->assertSee('Warenbezeichnung')->assertSee('12,50')
        ->assertSeeHtml('data-preisliste-zuordnen')->assertDontSeeHtml('data-preisliste-pruefliste');
});

it('Zuordnen und abgleichen: aus dem wartenden Lauf wird der Abgleich, die Zuordnung wird je Lieferant gemerkt', function () {
    $lauf = ($this->wartend)();
    Livewire::test(Index::class)
        ->call('laufWaehlen', $lauf->id)
        ->set('zuordnung', ['article_number' => '0', 'price' => '2', 'designation' => '1'])
        ->call('zuordnen')
        ->assertSet('fehler', null)->assertSeeHtml('data-preisliste-pruefliste');

    expect(Lauf::find($lauf->id))->toBeNull()
        ->and(Lauf::where('status', 'staged')->where('art', 'preisliste')->count())->toBe(1)
        ->and(FoodAlchemistImportZuordnung::where('supplier_id', $this->hanos->id)->where('art', 'preisliste')->count())->toBe(1);
});

it('merken=false speichert nichts; unvollständige Zuordnung bleibt mit Meldung stehen, die Datei wartet weiter', function () {
    $lauf = ($this->wartend)();
    $c = Livewire::test(Index::class)->call('laufWaehlen', $lauf->id)->set('zuordnung', ['designation' => '1'])->call('zuordnen');
    expect(Lauf::find($lauf->id)->status)->toBe('zuordnung_noetig');
    $c->assertSet('fehler', fn ($f) => str_contains((string) $f, 'reicht noch nicht'));

    $c->set('zuordnung', ['article_number' => '0', 'price' => '2'])->set('merken', false)->call('zuordnen')->assertSet('fehler', null);
    expect(FoodAlchemistImportZuordnung::count())->toBe(0);
});

it('Upload mit noch fehlenden Pflichtspalten in der Oberfläche lässt den Lauf warten statt Fehler (Panel erscheint)', function () {
    Livewire::test(Index::class)
        ->set('lieferantId', $this->hanos->id)
        ->set('datei', UploadedFile::fake()->createWithContent('eigen.csv', $this->eigene))
        ->call('abgleichen')
        ->assertSeeHtml('data-preisliste-zuordnung-noetig');
    expect(Lauf::where('status', 'zuordnung_noetig')->count())->toBe(1);
});

it('Rechte: Viewer sieht das Panel, aber ohne Zuordnen-Knopf, und kann nicht zuordnen', function () {
    $lauf = ($this->wartend)();
    $this->actingAs($this->makeUser($this->team, 'Gast', 'viewer'));
    Livewire::test(Index::class)->call('laufWaehlen', $lauf->id)->assertSeeHtml('data-preisliste-zuordnung-noetig')->assertDontSeeHtml('data-preisliste-zuordnen')
        ->set('zuordnung', ['article_number' => '0', 'price' => '2'])->call('zuordnen')
        ->assertSet('fehler', 'Preislisten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).');
    expect(Lauf::find($lauf->id)->status)->toBe('zuordnung_noetig');
});
